<?php
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/recaptcha.php';
require_once __DIR__ . '/../Db/db_config.php';

mysqli_report(MYSQLI_REPORT_OFF);

const BREWSKI_AUTH_ERROR = 'error';
const BREWSKI_AUTH_INVALID = 'invalid';
const BREWSKI_AUTH_LOCKED = 'locked';
const BREWSKI_AUTH_INACTIVE = 'inactive';
const BREWSKI_AUTH_WRONG_PORTAL = 'wrong_portal';
const BREWSKI_AUTH_TOTP = 'totp';
const BREWSKI_AUTH_ENROLL = 'enroll';

function brewski_ensure_account_lockouts_table(mysqli $connection): bool
{
    return $connection->query(
        "CREATE TABLE IF NOT EXISTS account_lockouts (
            account_lockout_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(255) NOT NULL UNIQUE,
            failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
            locked_at DATETIME NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_account_lockouts_locked_at (locked_at)
        )"
    ) === true;
}

function brewski_record_login_attempt(
    mysqli $connection,
    string $username,
    int $attempts,
    bool $locked
): void {
    $statement = $connection->prepare(
        'INSERT INTO account_lockouts (username, failed_attempts, locked_at)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE
            failed_attempts = VALUES(failed_attempts),
            locked_at = VALUES(locked_at)'
    );
    if (!$statement) {
        error_log('Could not prepare account lockout history statement: ' . $connection->error);
        return;
    }
    $lockedAt = $locked ? date('Y-m-d H:i:s') : null;
    $statement->bind_param('sis', $username, $attempts, $lockedAt);
    if (!$statement->execute()) {
        error_log('Could not save account lockout history: ' . $statement->error);
    }
    $statement->close();
}

function brewski_auth_result(
    string $status,
    string $message = '',
    array $user = [],
    string $redirect = ''
): array {
    return [
        'status'   => $status,
        'message'  => $message,
        'user'     => $user,
        'redirect' => $redirect,
    ];
}
function brewski_auth_submission_errors(array $post, bool $requireRecaptcha = true): array
{
    $submittedToken = $post['csrf_token'] ?? '';

    if (
        empty($_SESSION['csrf_token'])
        || !is_string($submittedToken)
        || !hash_equals((string) $_SESSION['csrf_token'], $submittedToken)
    ) {
        return ['This request could not be verified. Refresh the page and try again.'];
    }

    $errors = [];

    if ($requireRecaptcha && !recaptcha_verify((string) ($post['g-recaptcha-response'] ?? ''))) {
        $errors[] = 'Please complete the reCAPTCHA verification.';
    }

    $email = trim((string) ($post['email'] ?? ''));
    $password = (string) ($post['password'] ?? '');

    if ($email === '' || $password === '') {
        $errors[] = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    return $errors;
}

function brewski_authenticate($conn, string $email, string $password, string $portalKey): array
{
    if (!brewski_ensure_account_lockouts_table($conn)) {
        error_log('Account lockout history table is unavailable: ' . $conn->error);
    }
    $portal = brewski_portal($portalKey);
    $allowedRoles = $portal['roles'];

    $statement = $conn->prepare(
        "SELECT
            user_id,
            first_name,
            last_name,
            email,
            password,
            role,
            is_active,
            is_locked,
            totp_secret_encrypted,
            totp_enabled_at,
            failed_login_attempts
         FROM users
         WHERE email = ?
         LIMIT 1"
    );

    if (!$statement) {
        return brewski_auth_result(
            BREWSKI_AUTH_ERROR,
            'Something went wrong while accessing the database.'
        );
    }

    $statement->bind_param('s', $email);

    if (!$statement->execute()) {
        $statement->close();

        return brewski_auth_result(
            BREWSKI_AUTH_ERROR,
            'Something went wrong. Please try again.'
        );
    }

    $statement->store_result();

    if ($statement->num_rows !== 1) {
        $statement->close();

        return brewski_auth_result(BREWSKI_AUTH_INVALID, 'Incorrect email or password.');
    }

    $statement->bind_result(
        $userId,
        $firstName,
        $lastName,
        $userEmail,
        $storedPassword,
        $role,
        $isActive,
        $isLocked,
        $totpSecretEncrypted,
        $totpEnabledAt,
        $failedLoginAttempts
    );

    $statement->fetch();
    $statement->close();

    $role = strtoupper(trim((string) $role));

    $maxAttempts = brewski_max_login_attempts();
    $accountLocked = (int) $isLocked === 1;
    $genericLoginMessage = 'Incorrect email or password.';

    $passwordOk = password_verify($password, $storedPassword);

    if (!$passwordOk && hash_equals((string) $storedPassword, $password)) {
        $passwordOk = true;

        $upgrade = $conn->prepare('UPDATE users SET password = ? WHERE user_id = ?');

        if ($upgrade) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $upgrade->bind_param('si', $newHash, $userId);
            $upgrade->execute();
            $upgrade->close();
        }
    }

    if ($passwordOk && !$accountLocked) {
        $resetAttempts = $conn->prepare(
            'UPDATE users SET failed_login_attempts = 0 WHERE user_id = ?'
        );

        if ($resetAttempts) {
            $resetAttempts->bind_param('i', $userId);
            $resetAttempts->execute();
            $resetAttempts->close();
        }
        brewski_record_login_attempt($conn, $email, 0, false);
    }

    if ($accountLocked) {
        return brewski_auth_result(BREWSKI_AUTH_INVALID, $genericLoginMessage);
    }

    if (!$passwordOk) {
        $attempts = (int) $failedLoginAttempts + 1;

        if ($attempts >= $maxAttempts) {
            $lockUpdate = $conn->prepare(
                'UPDATE users SET is_locked = 1, failed_login_attempts = 0 WHERE user_id = ?'
            );

            if ($lockUpdate) {
                $lockUpdate->bind_param('i', $userId);
                $lockUpdate->execute();
                $lockUpdate->close();
            }
            brewski_record_login_attempt($conn, $email, $attempts, true);

            return brewski_auth_result(BREWSKI_AUTH_INVALID, $genericLoginMessage);
        }

        $attemptUpdate = $conn->prepare(
            'UPDATE users SET failed_login_attempts = ? WHERE user_id = ?'
        );

        if ($attemptUpdate) {
            $attemptUpdate->bind_param('ii', $attempts, $userId);
            $attemptUpdate->execute();
            $attemptUpdate->close();
        }
        brewski_record_login_attempt($conn, $email, $attempts, false);

        return brewski_auth_result(BREWSKI_AUTH_INVALID, $genericLoginMessage);
    }

    if ($allowedRoles && !in_array($role, $allowedRoles, true)) {
        $targetPortal = brewski_portal(brewski_portal_key_for_role($role));

        return brewski_auth_result(
            BREWSKI_AUTH_WRONG_PORTAL,
            'This account signs in through the ' . $targetPortal['label'] . '.',
            [],
            brewski_portal_login_url($targetPortal['key'])
        );
    }

    if ((int) $isActive !== 1) {
        return brewski_auth_result(
            BREWSKI_AUTH_INACTIVE,
            'Your account is not verified yet. Please complete the email verification first.'
        );
    }

    try {
        brewski_totp_encryption_key();
    } catch (Throwable $exception) {
        error_log('Authenticator login is unavailable: ' . $exception->getMessage());

        return brewski_auth_result(
            BREWSKI_AUTH_ERROR,
            'Authenticator sign-in is unavailable. Please contact support.'
        );
    }

    $hasSecret = !empty($totpSecretEncrypted);
    $isEnabled = !empty($totpEnabledAt);

    if ($hasSecret !== $isEnabled) {
        error_log('Incomplete authenticator configuration for user ' . (int) $userId);

        return brewski_auth_result(
            BREWSKI_AUTH_ERROR,
            'Authenticator sign-in is unavailable. Please contact support.'
        );
    }

    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);

    if ($hasSecret) {
        $_SESSION['totp_login_pending'] = [
            'user_id'    => (int) $userId,
            'expires_at' => time() + 600,
            'attempts'   => 0,
            'portal'     => $portal['key'],
        ];

        return brewski_auth_result(
            BREWSKI_AUTH_TOTP,
            '',
            [],
            brewski_auth_step_url($portal['key'], 'verify-authenticator.php')
        );
    }

    $_SESSION['totp_enrollment_pending'] = [
        'user_id'    => (int) $userId,
        'expires_at' => time() + 900,
        'portal'     => $portal['key'],
    ];

    return brewski_auth_result(
        BREWSKI_AUTH_ENROLL,
        '',
        [],
        brewski_auth_step_url($portal['key'], 'setup-authenticator.php')
    );
}

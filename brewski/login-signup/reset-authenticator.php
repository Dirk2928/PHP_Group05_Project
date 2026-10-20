<?php

require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/email-service-client.php';
require_once __DIR__ . '/../Db/connection.php';

$portalKey = brewski_auth_active_portal();
$loginUrl = brewski_portal_login_url($portalKey);
$setupUrl = brewski_auth_step_url($portalKey, 'setup-authenticator.php');
$resetUrl = brewski_auth_step_url($portalKey, 'reset-authenticator.php');
$authStylesheet = $portalKey === 'portal'
    ? '../login-signup/styles.css'
    : 'styles.css';
$portalStylesheet = $portalKey === 'portal' ? '../portal/portal.css' : '';

if (empty($_SESSION['user_id'])) {
    header('Location: ' . $loginUrl);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$error = '';
$success = '';
$user = null;
$canReset = false;
$otpLifetime = 600;
$otpResendDelay = 60;
$otpMaxAttempts = 5;

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_token'];

try {
    $userStatement = $pdo->prepare(
        'SELECT first_name, email, password, totp_secret_encrypted, totp_enabled_at
         FROM users
         WHERE user_id = ?
         LIMIT 1'
    );
    $userStatement->execute([$userId]);
    $user = $userStatement->fetch() ?: null;

    if (
        !$user
        || empty($user['totp_secret_encrypted'])
        || empty($user['totp_enabled_at'])
    ) {
        $error = 'This account does not have an active authenticator to reset.';
        unset(
            $_SESSION['totp_reset_otp'],
            $_SESSION['totp_reset_otp_last_sent_at'],
            $_SESSION['totp_reset_attempts']
        );
    } else {
        $canReset = true;
    }
} catch (Throwable $exception) {
    error_log('Authenticator reset account lookup failed: ' . $exception->getMessage());
    $error = 'Authenticator reset is temporarily unavailable. Please try again later.';
}

$maskedEmail = 'your registered email address';
if ($user && is_string($user['email'] ?? null) && strpos($user['email'], '@') !== false) {
    $emailParts = explode('@', $user['email'], 2);
    $maskedEmail = substr($emailParts[0], 0, 1) . '***@' . $emailParts[1];
}

$sendOtp = static function () use (
    &$error,
    &$success,
    $user,
    $canReset,
    $maskedEmail,
    $userId,
    $otpLifetime,
    $otpResendDelay
): void {
    if (!$user || !$canReset) {
        return;
    }

    $lastSentAt = (int) ($_SESSION['totp_reset_otp_last_sent_at'] ?? 0);
    $waitSeconds = $otpResendDelay - (time() - $lastSentAt);
    if ($lastSentAt > 0 && $waitSeconds > 0) {
        $error = 'Please wait ' . $waitSeconds . ' seconds before requesting another code.';
        return;
    }

    $_SESSION['totp_reset_otp_last_sent_at'] = time();
    $otp = (string) random_int(100000, 999999);

    if (!email_service_send('send-authenticator-reset-otp', [
        'email' => (string) $user['email'],
        'firstName' => (string) $user['first_name'],
        'otp' => $otp,
    ])) {
        unset($_SESSION['totp_reset_otp']);
        $error = 'We could not send the verification code. Please try again shortly.';
        return;
    }

    $_SESSION['totp_reset_otp'] = [
        'user_id' => $userId,
        'code_hash' => password_hash($otp, PASSWORD_DEFAULT),
        'expires_at' => time() + $otpLifetime,
        'attempts' => 0,
    ];
    $success = 'A six-digit verification code was sent to ' . $maskedEmail . '.';
};

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$otpState = $_SESSION['totp_reset_otp'] ?? null;
$otpIsActive = is_array($otpState)
    && (int) ($otpState['user_id'] ?? 0) === $userId
    && (int) ($otpState['expires_at'] ?? 0) > time()
    && (int) ($otpState['attempts'] ?? $otpMaxAttempts) < $otpMaxAttempts;

if ($requestMethod === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        $error = 'This request could not be verified. Refresh the page and try again.';
    } elseif (!$canReset) {
        // Keep the account lookup error visible.
    } elseif (($_POST['action'] ?? 'verify') === 'send_code') {
        $sendOtp();
    } else {
        $password = $_POST['password'] ?? '';
        $submittedOtp = $_POST['otp'] ?? '';
        $otpState = $_SESSION['totp_reset_otp'] ?? null;
        $otpIsActive = is_array($otpState)
            && (int) ($otpState['user_id'] ?? 0) === $userId
            && (int) ($otpState['expires_at'] ?? 0) > time()
            && (int) ($otpState['attempts'] ?? $otpMaxAttempts) < $otpMaxAttempts;

        if (!$otpIsActive) {
            unset($_SESSION['totp_reset_otp']);
            $error = 'Your code has expired or is no longer valid. Request a new code to continue.';
        } else {
            $passwordValid = is_string($password)
                && password_verify($password, (string) $user['password']);
            $otpValid = is_string($submittedOtp)
                && preg_match('/^\d{6}$/', $submittedOtp) === 1
                && password_verify($submittedOtp, (string) $otpState['code_hash']);

            if (!$passwordValid || !$otpValid) {
                $otpState['attempts'] = (int) ($otpState['attempts'] ?? 0) + 1;
                $_SESSION['totp_reset_otp'] = $otpState;
                if ($otpState['attempts'] >= $otpMaxAttempts) {
                    unset($_SESSION['totp_reset_otp']);
                    $error = 'Too many verification attempts. Request a new code to continue.';
                } else {
                    $error = 'The password or verification code is invalid.';
                }
            } else {
                try {
                    $pdo->beginTransaction();

                    $resetStatement = $pdo->prepare(
                        'UPDATE users
                         SET totp_secret_encrypted = NULL, totp_enabled_at = NULL
                         WHERE user_id = ?
                           AND totp_secret_encrypted IS NOT NULL
                           AND totp_enabled_at IS NOT NULL'
                    );
                    $resetStatement->execute([$userId]);
                    if ($resetStatement->rowCount() !== 1) {
                        throw new RuntimeException('The authenticator could not be reset.');
                    }

                    $deleteStatement = $pdo->prepare(
                        'DELETE FROM totp_recovery_codes WHERE user_id = ?'
                    );
                    $deleteStatement->execute([$userId]);
                    $pdo->commit();

                    unset(
                        $_SESSION['totp_reset_otp'],
                        $_SESSION['totp_reset_otp_last_sent_at'],
                        $_SESSION['totp_reset_attempts'],
                        $_SESSION['totp_setup_secret']
                    );
                    session_regenerate_id(true);
                    header('Location: ' . $setupUrl);
                    exit;
                } catch (PDOException $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Authenticator reset database failure: ' . $exception->getMessage());
                    $error = 'Authenticator reset is temporarily unavailable. Please try again later.';
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Authenticator reset failed: ' . $exception->getMessage());
                    $error = 'Authenticator reset is temporarily unavailable. Please try again later.';
                }
            }
        }
    }
} elseif ($canReset && !$otpIsActive) {
    $lastSentAt = (int) ($_SESSION['totp_reset_otp_last_sent_at'] ?? 0);
    if ($lastSentAt === 0) {
        $sendOtp();
    } elseif ((time() - $lastSentAt) >= $otpResendDelay) {
        $error = 'Your verification code has expired. Request a new code to continue.';
    } else {
        $error = 'A verification code could not be sent yet. Please try again shortly.';
    }
}

$otpState = $_SESSION['totp_reset_otp'] ?? null;
$otpIsActive = is_array($otpState)
    && (int) ($otpState['user_id'] ?? 0) === $userId
    && (int) ($otpState['expires_at'] ?? 0) > time()
    && (int) ($otpState['attempts'] ?? $otpMaxAttempts) < $otpMaxAttempts;

$escape = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset authenticator | Brewski</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $escape($authStylesheet) ?>">
    <?php if ($portalStylesheet !== ''): ?>
        <link rel="stylesheet" href="<?= $escape($portalStylesheet) ?>">
    <?php endif; ?>
</head>
<body>
<main class="auth auth--centered auth--compact<?= $portalKey === 'portal' ? ' auth--portal' : '' ?>">
    <section class="auth__panel">
        <div class="auth__content">
            <img class="brand__logo" src="../images/brewskilogo.png" alt="Brewski Logo">
            <p class="brand__name">brew<span>ski</span></p>

            <h1 class="auth__title">Reset authenticator</h1>
            <p class="auth__intro">
                Enter your current password and the verification code sent to your email.
                The code expires in 10 minutes. Resetting will invalidate your existing recovery codes and require
                authenticator setup again.
            </p>

            <?php if ($error !== ''): ?>
                <p class="field__error" role="alert"><?= $escape($error) ?></p>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <p class="auth__intro" role="status"><?= $escape($success) ?></p>
            <?php endif; ?>

            <?php if ($canReset && !$otpIsActive): ?>
                <form class="form" method="post" action="<?= $escape($resetUrl) ?>">
                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="action" value="send_code">
                    <button type="submit" class="btn">Send verification code</button>
                </form>
            <?php elseif ($canReset): ?>
                <form class="form" method="post" action="<?= $escape($resetUrl) ?>">
                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">

                    <div class="field">
                        <label for="current-password">Current password</label>
                        <input
                            type="password"
                            id="current-password"
                            name="password"
                            autocomplete="current-password"
                            required
                        >
                    </div>

                    <div class="field">
                        <label for="verification-code">Email verification code</label>
                        <input
                            type="text"
                            id="verification-code"
                            name="otp"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            pattern="[0-9]{6}"
                            maxlength="6"
                            required
                        >
                    </div>

                    <button type="submit" class="btn">Verify and reset</button>
                </form>

                <form class="form" method="post" action="<?= $escape($resetUrl) ?>">
                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="action" value="send_code">
                    <button type="submit" class="auth__switch-button">Send a new code</button>
                </form>
            <?php endif; ?>

            <p class="auth__switch">
                <a href="<?= $escape($setupUrl) ?>">Cancel</a>
            </p>
        </div>
    </section>
</main>
</body>
</html>

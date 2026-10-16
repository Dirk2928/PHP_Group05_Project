<?php

require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/../Db/connection.php';
require_once __DIR__ . '/../Db/activity_log.php';

$portalKey = brewski_auth_active_portal();
$loginUrl = brewski_portal_login_url($portalKey);
$setupUrl = brewski_auth_step_url($portalKey, 'setup-authenticator.php');
$resetUrl = brewski_auth_step_url($portalKey, 'reset-authenticator.php');

$enrollmentPending = $_SESSION['totp_enrollment_pending'] ?? null;
$isPendingEnrollment = is_array($enrollmentPending)
    && (int) ($enrollmentPending['user_id'] ?? 0) > 0
    && (int) ($enrollmentPending['expires_at'] ?? 0) > time();

if (isset($_SESSION['totp_enrollment_pending']) && !$isPendingEnrollment) {
    unset($_SESSION['totp_enrollment_pending'], $_SESSION['totp_setup_secret']);
}

if (empty($_SESSION['user_id']) && !$isPendingEnrollment) {
    header('Location: ' . $loginUrl);
    exit;
}

$userId = $isPendingEnrollment
    ? (int) $enrollmentPending['user_id']
    : (int) $_SESSION['user_id'];
$error = '';
$success = '';
$recoveryCodes = [];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_token'];

try {
    brewski_totp_encryption_key();
    $userStatement = $pdo->prepare(
        'SELECT email, first_name, last_name, role, is_active,
                totp_secret_encrypted, totp_enabled_at
         FROM users
         WHERE user_id = ?
         LIMIT 1'
    );
    $userStatement->execute([$userId]);
    $user = $userStatement->fetch();

    if (!$user || (int) $user['is_active'] !== 1) {
        brewski_end_session();
    }
} catch (Throwable $exception) {
    error_log('Could not load authenticator setup: ' . $exception->getMessage());
    http_response_code(503);
    $error = 'Authenticator setup is temporarily unavailable. Please contact support.';
    $user = null;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $user !== null) {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        $error = 'This request could not be verified. Refresh the page and try again.';
    } elseif (!empty($user['totp_enabled_at']) || !empty($user['totp_secret_encrypted'])) {
        unset($_SESSION['totp_setup_secret']);
        $success = 'An authenticator is already configured for this account.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'cancel_setup') {
            unset($_SESSION['totp_setup_secret']);
            if ($isPendingEnrollment) {
                unset($_SESSION['totp_enrollment_pending']);
                header('Location: ' . $loginUrl);
            } else {
                header('Location: ' . brewski_home_for_role(brewski_current_role()));
            }
            exit;
        }

        $secret = $_SESSION['totp_setup_secret'] ?? '';
        if (!is_string($secret) || $secret === '') {
            $error = 'Your setup session expired. Refresh this page to start again.';
        } elseif ($action !== 'confirm_setup') {
            $error = 'Choose whether to confirm or cancel authenticator setup.';
        } else {
            $code = $_POST['code'] ?? '';
            if (!is_string($code) || !brewski_totp_verify_code($secret, $code)) {
                $error = 'That code is invalid or expired. Check your device time and try again.';
            } else {
                try {
                    $encryptedSecret = brewski_totp_encrypt_secret($secret);
                    $newRecoveryCodes = brewski_totp_generate_recovery_codes();
                    $pdo->beginTransaction();
                    $saveStatement = $pdo->prepare(
                        'UPDATE users
                         SET totp_secret_encrypted = ?, totp_enabled_at = NOW()
                         WHERE user_id = ?
                           AND totp_secret_encrypted IS NULL
                           AND totp_enabled_at IS NULL'
                    );
                    $saveStatement->execute([$encryptedSecret, $userId]);

                    if ($saveStatement->rowCount() !== 1) {
                        throw new RuntimeException('The authenticator setup could not be saved.');
                    }

                    $recoveryStatement = $pdo->prepare(
                        'INSERT INTO totp_recovery_codes (user_id, code_hash) VALUES (?, ?)'
                    );
                    foreach ($newRecoveryCodes as $recoveryCode) {
                        $normalizedCode = brewski_totp_normalize_recovery_code($recoveryCode);
                        if ($normalizedCode === null) {
                            throw new RuntimeException('Could not prepare an authenticator recovery code.');
                        }
                        $recoveryStatement->execute([
                            $userId,
                            password_hash($normalizedCode, PASSWORD_DEFAULT),
                        ]);
                    }

                    $pdo->commit();
                    unset($_SESSION['totp_setup_secret']);
                    $_SESSION['totp_recovery_codes_once'] = $newRecoveryCodes;
                    if ($isPendingEnrollment) {
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $userId;
                        $_SESSION['first_name'] = (string) $user['first_name'];
                        $_SESSION['last_name'] = (string) $user['last_name'];
                        $_SESSION['email'] = (string) $user['email'];
                        $_SESSION['role'] = strtoupper(trim((string) $user['role']));
                        $_SESSION['created_at'] = time();
                        $_SESSION['last_activity'] = time();
                        unset($_SESSION['totp_enrollment_pending']);
                        brewski_record_activity($pdo, 'authentication', 'Signed in', 'Signed in after authenticator enrollment.');
                    } elseif (in_array(brewski_current_role(), ['ADMIN', 'STAFF'], true)) {
                        brewski_record_activity($pdo, 'security', 'Enabled authenticator', 'Enabled authenticator-based sign-in.');
                    }
                    header('Location: ' . $setupUrl . '?enrolled=1');
                    exit;
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Could not save authenticator setup: ' . $exception->getMessage());
                    $error = 'Authenticator setup could not be saved. Please try again.';
                }
            }
        }
    }
}

$recoveryCodes = $_SESSION['totp_recovery_codes_once'] ?? [];
unset($_SESSION['totp_recovery_codes_once']);
if (isset($_GET['enrolled']) && $recoveryCodes) {
    $success = 'Authenticator setup is complete. Save these recovery codes now; they will only be shown once.';
}

$secret = '';
$provisioningUri = '';
if (
    $user !== null
    && empty($user['totp_enabled_at'])
    && empty($user['totp_secret_encrypted'])
    && $success === ''
) {
    try {
        if (!isset($_SESSION['totp_setup_secret'])) {
            $_SESSION['totp_setup_secret'] = brewski_totp_generate_secret();
        }

        $secret = (string) $_SESSION['totp_setup_secret'];
        $provisioningUri = brewski_totp_provisioning_uri(
            $secret,
            (string) $user['email']
        );
    } catch (Throwable $exception) {
        error_log('Could not prepare authenticator setup: ' . $exception->getMessage());
        http_response_code(503);
        $error = 'Authenticator setup is temporarily unavailable. Please contact support.';
    }
}

$homeUrl = brewski_home_for_role(brewski_current_role());
$escape = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Authenticator setup | Brewski</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600&family=Questrial&display=swap" rel="stylesheet">

    <style>
        :root {
            color-scheme: light;
            --font-brand: "Fredoka", "Century Gothic", sans-serif;
            --font-body: "Century Gothic", CenturyGothic, "AppleGothic", "Questrial", "Segoe UI", sans-serif;
            font-family: var(--font-body);
            color: #1a0f0a;
            background: #faf5ee;
        }
        h1, h2 { font-family: var(--font-brand); font-weight: 600; }
        body { margin: 0; padding: 2rem 1rem; }
        main { width: min(100%, 38rem); margin: 2rem auto; padding: 2rem; box-sizing: border-box; background: #fff; border: 1px solid #e8dac4; border-radius: 1rem; }
        h1 { margin-top: 0; }
        li { margin: 0.65rem 0; line-height: 1.5; }
        .notice { padding: 0.9rem 1rem; border-radius: 0.65rem; line-height: 1.5; }
        .notice--error { color: #7f1d1d; background: #fef2f2; border: 1px solid #fecaca; }
        .notice--success { color: #14532d; background: #f0fdf4; border: 1px solid #bbf7d0; }
        .secret { display: block; margin: 1rem 0; padding: 1rem; border: 1px solid #e8dac4; border-radius: 0.65rem; background: #faf5ee; font: 700 1.1rem/1.5 monospace; overflow-wrap: anywhere; user-select: all; }
        .recovery-codes { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.5rem; padding: 1rem; border: 1px solid #e8dac4; border-radius: 0.65rem; background: #faf5ee; list-style: none; }
        .recovery-codes code { font-weight: 700; }
        .uri { overflow-wrap: anywhere; font: 0.85rem/1.5 monospace; }
        label { display: block; margin: 1.5rem 0 0.4rem; font-weight: 700; }
        input { width: 100%; box-sizing: border-box; padding: 0.8rem; border: 1px solid #b8a58e; border-radius: 0.6rem; font: inherit; font-size: 1.2rem; letter-spacing: 0.25rem; }
        .actions { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 1rem; }
        button, .button { display: inline-block; padding: 0.75rem 1rem; border: 1px solid #1a0f0a; border-radius: 0.6rem; background: #1a0f0a; color: #fff; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        button.secondary, .button.secondary { background: #fff; color: #1a0f0a; }
        details { margin-top: 1rem; }
        @media (max-width: 480px) { main { padding: 1.25rem; } }
    </style>
</head>
<body>
<main>
    <h1>Set up an authenticator</h1>
    <p>Use Google Authenticator or another TOTP-compatible app to add Brewski.</p>

    <?php if ($error !== ''): ?>
        <p class="notice notice--error" role="alert"><?= $escape($error) ?></p>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <p class="notice notice--success" role="status"><?= $escape($success) ?></p>
    <?php endif; ?>

    <?php if ($user === null): ?>
        <a class="button secondary" href="<?= $escape($homeUrl) ?>">Return to Brewski</a>
    <?php elseif (!empty($user['totp_enabled_at']) || !empty($user['totp_secret_encrypted'])): ?>
        <?php if ($recoveryCodes): ?>
            <h2>Save your recovery codes</h2>
            <p>Each code works once if you cannot access your authenticator. Store them somewhere private. They will not be shown again.</p>
            <ul class="recovery-codes">
                <?php foreach ($recoveryCodes as $recoveryCode): ?>
                    <li><code><?= $escape($recoveryCode) ?></code></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p>This account already has an authenticator configured.</p>
        <?php endif; ?>
        <p><a class="button secondary" href="<?= $escape($resetUrl) ?>">Reset authenticator</a></p>
        <a class="button secondary" href="<?= $escape($homeUrl) ?>">Return to Brewski</a>
    <?php elseif ($secret !== ''): ?>
        <?php if ($isPendingEnrollment): ?>
            <p>Set up an authenticator to complete your first sign-in.</p>
        <?php endif; ?>
        <ol>
            <li>In your authenticator app, choose to add an account using a setup key.</li>
            <li>Enter the account name and this key. Keep the key private.</li>
        </ol>
        <span class="secret" aria-label="Authenticator setup key"><?= $escape($secret) ?></span>
        <details>
            <summary>Advanced: provisioning URI</summary>
            <p class="uri"><?= $escape($provisioningUri) ?></p>
        </details>
        <form method="post" action="<?= $escape($setupUrl) ?>">
            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="action" value="confirm_setup">
            <label for="totp-code">Enter the current six-digit code to confirm</label>
            <input
                type="text"
                id="totp-code"
                name="code"
                inputmode="numeric"
                autocomplete="one-time-code"
                pattern="[0-9]{6}"
                maxlength="6"
                required
            >
            <div class="actions">
                <button type="submit">Confirm setup</button>
            </div>
        </form>
        <form method="post" action="<?= $escape($setupUrl) ?>" class="actions">
            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="action" value="cancel_setup">
            <button class="secondary" type="submit">Cancel</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>

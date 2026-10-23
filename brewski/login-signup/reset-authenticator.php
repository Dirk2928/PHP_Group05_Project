<?php

require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/email-service-client.php';
require_once __DIR__ . '/../Db/connection.php';

$portalKey = brewski_auth_active_portal();
$loginUrl = brewski_portal_login_url($portalKey);
$setupUrl = brewski_auth_step_url($portalKey, 'setup-authenticator.php');
$resetUrl = brewski_auth_step_url($portalKey, 'reset-authenticator.php');
$otpScript = $portalKey === 'portal' ? '../login-signup/otp.js' : 'otp.js';
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

    $_SESSION['totp_reset_otp_last_sent_at'] = time();
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
} elseif ($canReset && !$otpIsActive && isset($_SESSION['totp_reset_otp'])) {
    $lastSentAt = (int) ($_SESSION['totp_reset_otp_last_sent_at'] ?? 0);
    unset($_SESSION['totp_reset_otp']);
    if ($lastSentAt > 0 && (time() - $lastSentAt) < $otpResendDelay) {
        $error = 'Please wait before requesting another code.';
    } else {
        $error = 'Your verification code has expired. Request a new code to continue.';
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
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600&display=swap" rel="stylesheet">
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
        p { line-height: 1.5; }
        .notice { padding: 0.9rem 1rem; border-radius: 0.65rem; line-height: 1.5; }
        .notice--error { color: #7f1d1d; background: #fef2f2; border: 1px solid #fecaca; }
        .notice--success { color: #14532d; background: #f0fdf4; border: 1px solid #bbf7d0; }
        label { display: block; margin: 1.2rem 0 0.4rem; font-weight: 700; }
        input { width: 100%; box-sizing: border-box; padding: 0.8rem; border: 1px solid #b8a58e; border-radius: 0.6rem; font: inherit; }
        .otp-inputs { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); width: min(100%, 19.5rem); margin-top: 0.55rem; }
        .otp-inputs .otp-input { min-width: 0; height: 3rem; padding: 0; border-radius: 0; font-size: 1.25rem; font-weight: 700; text-align: center; }
        .otp-inputs .otp-input + .otp-input { margin-left: -1px; }
        .otp-inputs .otp-input:first-child { border-radius: 0.6rem 0 0 0.6rem; }
        .otp-inputs .otp-input:last-child { border-radius: 0 0.6rem 0.6rem 0; }
        .otp-inputs .otp-input:focus { position: relative; z-index: 1; outline: 2px solid rgb(113 73 42 / 20%); border-color: #71492a; }
        .actions { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 1rem; }
        button, .button { display: inline-block; padding: 0.75rem 1rem; border: 1px solid #1a0f0a; border-radius: 0.6rem; background: #1a0f0a; color: #fff; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        button.secondary, .button.secondary { background: #fff; color: #1a0f0a; }
        @media (max-width: 480px) { main { padding: 1.25rem; } }
    </style>
</head>
<body>
<main>
    <h1>Reset authenticator</h1>
    <p>
        Enter your current password and the verification code sent to your email.
        The code expires in 10 minutes. Resetting will invalidate your existing recovery codes
        and require authenticator setup again.
    </p>

    <?php if ($error !== ''): ?>
        <p class="notice notice--error" role="alert"><?= $escape($error) ?></p>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <p class="notice notice--success" role="status"><?= $escape($success) ?></p>
    <?php endif; ?>

    <?php if ($canReset && !$otpIsActive): ?>
        <form method="post" action="<?= $escape($resetUrl) ?>" class="actions">
            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="action" value="send_code">
            <button type="submit">Send verification code</button>
            <a class="button secondary" href="<?= $escape($setupUrl) ?>">Cancel</a>
        </form>
    <?php elseif ($canReset): ?>
        <form id="otp-form" method="post" action="<?= $escape($resetUrl) ?>">
            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">

            <label for="current-password">Current password</label>
            <input
                type="password"
                id="current-password"
                name="password"
                autocomplete="current-password"
                required
            >

            <label for="verification-code">Email verification code</label>
            <div class="otp-inputs" role="group" aria-label="Six-digit email verification code">
                <?php for ($digit = 0; $digit < 6; $digit++): ?>
                    <input
                        class="otp-input"
                        type="text"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        pattern="[0-9]"
                        maxlength="1"
                        aria-label="Digit <?= $digit + 1 ?>"
                        <?= $digit === 0 ? 'id="verification-code" autofocus' : '' ?>
                        required
                    >
                <?php endfor; ?>
            </div>
            <input id="otp" name="otp" type="hidden" value="">

            <div class="actions">
                <button type="submit">Verify and reset</button>
                <button class="secondary" type="submit" name="action" value="send_code" formnovalidate>Send a new code</button>
                <a class="button secondary" href="<?= $escape($setupUrl) ?>">Cancel</a>
            </div>
        </form>
    <?php else: ?>
        <a class="button secondary" href="<?= $escape($setupUrl) ?>">Return to Brewski</a>
    <?php endif; ?>
</main>
<script src="<?= $escape($otpScript) ?>"></script>
</body>
</html>

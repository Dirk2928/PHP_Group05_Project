<?php

require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/../Db/connection.php';

$pending = $_SESSION['totp_login_pending'] ?? null;
if (
    !is_array($pending)
    || (int) ($pending['user_id'] ?? 0) <= 0
    || (int) ($pending['expires_at'] ?? 0) <= time()
) {
    unset($_SESSION['totp_login_pending']);
    header('Location: login.php?msg=otp_cancelled');
    exit;
}

$error = '';
$userId = (int) $pending['user_id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (
        empty($_SESSION['csrf_token'])
        || !is_string($submittedToken)
        || !hash_equals((string) $_SESSION['csrf_token'], $submittedToken)
    ) {
        $error = 'This request could not be verified. Refresh the page and try again.';
    } elseif ((int) ($pending['attempts'] ?? 0) >= 5) {
        unset($_SESSION['totp_login_pending']);
        header('Location: login.php?msg=otp_cancelled');
        exit;
    } else {
        $code = $_POST['code'] ?? '';
        if (!is_string($code)) {
            $error = 'Enter an authenticator code or recovery code.';
        } else {
            try {
                $userStatement = $pdo->prepare(
                    'SELECT first_name, last_name, email, role, is_active,
                            totp_secret_encrypted, totp_enabled_at
                     FROM users
                     WHERE user_id = ?
                     LIMIT 1'
                );
                $userStatement->execute([$userId]);
                $user = $userStatement->fetch();

                if (
                    !$user
                    || (int) $user['is_active'] !== 1
                    || empty($user['totp_secret_encrypted'])
                    || empty($user['totp_enabled_at'])
                ) {
                    unset($_SESSION['totp_login_pending']);
                    header('Location: login.php?msg=otp_cancelled');
                    exit;
                }

                $recoveryCodeId = null;
                $validCode = false;
                if (preg_match('/^\d{6}$/', $code)) {
                    $secret = brewski_totp_decrypt_secret((string) $user['totp_secret_encrypted']);
                    $validCode = brewski_totp_verify_code($secret, $code);
                } else {
                    $recoveryCodeId = brewski_totp_find_recovery_code($pdo, $userId, $code);
                    $validCode = $recoveryCodeId !== null;
                }

                if (!$validCode) {
                    $_SESSION['totp_login_pending']['attempts'] =
                        (int) $pending['attempts'] + 1;
                    $attemptsLeft = max(0, 5 - (int) $_SESSION['totp_login_pending']['attempts']);
                    $error = $attemptsLeft > 0
                        ? "Incorrect code. {$attemptsLeft} attempt(s) left."
                        : 'Too many incorrect attempts. Please log in again.';

                    if ($attemptsLeft === 0) {
                        unset($_SESSION['totp_login_pending']);
                    }
                } else {
                    if ($recoveryCodeId !== null) {
                        $consumeStatement = $pdo->prepare(
                            'UPDATE totp_recovery_codes
                             SET used_at = NOW()
                             WHERE recovery_code_id = ?
                               AND user_id = ?
                               AND used_at IS NULL'
                        );
                        $consumeStatement->execute([$recoveryCodeId, $userId]);
                        if ($consumeStatement->rowCount() !== 1) {
                            throw new RuntimeException('The recovery code was already used.');
                        }
                    }

                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['first_name'] = (string) $user['first_name'];
                    $_SESSION['last_name'] = (string) $user['last_name'];
                    $_SESSION['email'] = (string) $user['email'];
                    $_SESSION['role'] = strtoupper(trim((string) $user['role']));
                    $_SESSION['created_at'] = time();
                    $_SESSION['last_activity'] = time();
                    unset($_SESSION['totp_login_pending']);
                    session_write_close();

                    header('Location: ' . brewski_home_for_role((string) $user['role']));
                    exit;
                }
            } catch (Throwable $exception) {
                error_log('Authenticator login verification failed: ' . $exception->getMessage());
                http_response_code(503);
                $error = 'Authenticator verification is temporarily unavailable. Please try again later.';
            }
        }
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify sign-in | Brewski</title>
    <style>
        :root { color-scheme: light; font-family: Arial, sans-serif; color: #1a0f0a; background: #faf5ee; }
        body { margin: 0; padding: 2rem 1rem; }
        main { width: min(100%, 26rem); margin: 3rem auto; padding: 2rem; box-sizing: border-box; background: #fff; border: 1px solid #e8dac4; border-radius: 1rem; }
        h1 { margin-top: 0; }
        p { line-height: 1.5; }
        .error { padding: 0.9rem 1rem; border: 1px solid #fecaca; border-radius: 0.65rem; color: #7f1d1d; background: #fef2f2; }
        label { display: block; margin: 1.5rem 0 0.4rem; font-weight: 700; }
        input { width: 100%; box-sizing: border-box; padding: 0.8rem; border: 1px solid #b8a58e; border-radius: 0.6rem; font: inherit; font-size: 1.2rem; letter-spacing: 0.25rem; text-align: center; }
        button, a { display: inline-block; margin-top: 1rem; padding: 0.75rem 1rem; border: 1px solid #1a0f0a; border-radius: 0.6rem; background: #1a0f0a; color: #fff; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        a { margin-left: 0.5rem; background: #fff; color: #1a0f0a; }
    </style>
</head>
<body>
<main>
    <h1>Verify your sign-in</h1>
    <p>Enter the current six-digit code from your authenticator app, or use one of your recovery codes. This sign-in attempt expires in 10 minutes.</p>

    <?php if ($error !== ''): ?>
        <p class="error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <form method="post" action="verify-authenticator.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <label for="totp-code">Authenticator or recovery code</label>
        <input
            type="text"
            id="totp-code"
            name="code"
            inputmode="numeric"
            autocomplete="one-time-code"
            maxlength="30"
            required
            autofocus
            aria-describedby="totp-code-help"
        >
        <small id="totp-code-help">Authenticator codes have 6 digits. Recovery codes have four groups of 6 letters or numbers.</small>
        <button type="submit">Verify and sign in</button>
        <a href="login.php?msg=otp_cancelled">Cancel</a>
    </form>
</main>
</body>
</html>

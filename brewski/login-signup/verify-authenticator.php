<?php

require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/../Db/connection.php';
require_once __DIR__ . '/../Db/activity_log.php';

$portalKey = brewski_auth_active_portal();
$loginUrl = brewski_portal_login_url($portalKey, '?msg=otp_cancelled');
$verifyUrl = brewski_auth_step_url($portalKey, 'verify-authenticator.php');

$pending = $_SESSION['totp_login_pending'] ?? null;
if (
    !is_array($pending)
    || (int) ($pending['user_id'] ?? 0) <= 0
    || (int) ($pending['expires_at'] ?? 0) <= time()
) {
    unset($_SESSION['totp_login_pending']);
    header('Location: ' . $loginUrl);
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
        header('Location: ' . $loginUrl);
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
                    header('Location: ' . $loginUrl);
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
                    $error = 'Incorrect code. Please try again.';

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
                    brewski_record_activity(
                        $pdo,
                        'authentication',
                        'Signed in',
                        'Signed in successfully.'
                    );

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
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(BREWSKI_BASE_URL . '/images/brewskilogo.png', ENT_QUOTES, 'UTF-8') ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../login-signup/styles.css">
</head>
<body>
<header class="auth-topbar">
    <a class="auth-topbar__brand" href="../Start-screen/start.php"><img class="auth-topbar__logo" src="../images/brewskilogo.png" alt="">brewski</a>
    <nav class="auth-topbar__nav" aria-label="Main navigation">
        <a class="auth-topbar__link" href="../customer/customer_menu/customermenu.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 2v2"/><path d="M14 2v2"/><path d="M4 7h16l-1 13H5L4 7Z"/><path d="M4 7c0-2 2-3 4-3h8c2 0 4 1 4 3"/></svg>Menu</a>
        <a class="auth-topbar__link" href="../login-signup/signup.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>Order Now</a>
    </nav>
</header>
<main class="auth auth--centered auth--compact">
    <section class="auth__panel">
      <div class="auth__content">
        <img class="brand__logo" src="../images/brewskilogo.png" alt="Brewski Logo">
        <p class="brand__name">brew<span>ski</span></p>
        <h1 class="auth__title">Verify your sign-in</h1>
        <p class="auth__intro">Enter a current authenticator code or one of your saved recovery codes. This sign-in expires in 10 minutes.</p>

    <?php if ($error !== ''): ?>
        <p class="field__error" role="alert" style="text-align:center;margin-bottom:1rem;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

        <form class="form" method="post" action="<?= htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <div class="field">
                <label for="totp-code">
                    <svg class="field__icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                    Authenticator or recovery code
                </label>
                <input class="auth__code-input" type="text" id="totp-code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="30" required autofocus aria-describedby="totp-code-help">
                <small class="auth__code-help" id="totp-code-help">Authenticator codes have 6 digits. Recovery codes use four groups of 6 letters or numbers.</small>
            </div>
            <button class="btn" type="submit">Verify and sign in</button>
        </form>
        <p class="auth__switch"><a href="<?= htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') ?>">Cancel and return to sign in</a></p>
      </div>
    </section>
</main>
</body>
</html>

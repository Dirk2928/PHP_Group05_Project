<?php

require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/../Db/db_config.php';
require_once __DIR__ . '/email-service-client.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pending = $_SESSION['signup_otp_pending'] ?? null;
$error = '';
$email = is_array($pending) ? (string) ($pending['email'] ?? '') : '';

if (
    !is_array($pending)
    || (int) ($pending['user_id'] ?? 0) <= 0
    || (int) ($pending['expires_at'] ?? 0) < time()
) {
    unset($_SESSION['signup_otp_pending']);
    $error = 'This verification request has expired. Please sign up again.';
    $pending = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pending !== null) {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $otp = trim((string) ($_POST['otp'] ?? ''));

    if (
        empty($_SESSION['csrf_token'])
        || !is_string($submittedToken)
        || !hash_equals((string) $_SESSION['csrf_token'], $submittedToken)
    ) {
        $error = 'This request could not be verified. Refresh the page and try again.';
    } elseif (!preg_match('/^\d{6}$/', $otp)) {
        $error = 'Enter the six-digit code from your email.';
    } else {
        $connection = brewski_db_connect();

        if (!$connection) {
            $error = 'Unable to connect to the database. Please try again later.';
        } else {
            try {
            $statement = $connection->prepare(
                'SELECT otp_id, code_hash, expires_at, attempts
                 FROM otp_codes
                 WHERE user_id = ?
                 ORDER BY created_at DESC
                 LIMIT 1'
            );

            if (!$statement) {
                $error = 'Unable to verify the code. Please try again later.';
            } else {
                $userId = (int) $pending['user_id'];
                $statement->bind_param('i', $userId);
                $statement->execute();
                $statement->bind_result($otpId, $codeHash, $expiresAt, $attempts);
                $codeFound = $statement->fetch();
                $otpId = (int) $otpId;
                $codeHash = (string) $codeHash;
                $attempts = (int) $attempts;
                $statement->close();

                if (!$codeFound) {
                    $error = 'This verification code is no longer available. Please request a new one.';
                } else {
                    $expired = !$expiresAt || strtotime($expiresAt) < time();
                    $tooManyAttempts = (int) $attempts >= 5;

                    if ($expired || $tooManyAttempts) {
                        $error = 'This verification code has expired. Please request a new one.';
                    } elseif (!password_verify($otp, $codeHash)) {
                        $attempts++;
                        $update = $connection->prepare(
                            'UPDATE otp_codes SET attempts = ? WHERE otp_id = ?'
                        );
                        if ($update) {
                            $update->bind_param('ii', $attempts, $otpId);
                            $update->execute();
                            $update->close();
                        }
                        $error = 'The verification code is incorrect.';
                    } else {
                        $userStatement = $connection->prepare(
                            'SELECT first_name, email
                             FROM users WHERE user_id = ? AND is_active = 0 LIMIT 1'
                        );

                        if (!$userStatement) {
                            $error = 'Unable to complete account verification.';
                        } else {
                            $userStatement->bind_param('i', $userId);
                            $userStatement->execute();
                            $userStatement->bind_result($firstName, $userEmail);
                            $userFound = $userStatement->fetch();
                            $firstName = (string) brewski_personal_decrypt($firstName, 'first_name');
                            $userEmail = (string) brewski_personal_decrypt($userEmail, 'email');
                            $userStatement->close();

                            if (!$userFound) {
                                $error = 'Unable to complete account verification.';
                            } else {
                                $activationToken = (string) ($pending['activation_token'] ?? '');
                                $activationUrl = brewski_app_public_url(
                                    '/login-signup/activate.php?token=' . urlencode($activationToken)
                                );
                                $activationSent = $activationUrl !== null && email_service_send(
                                    'send-activation',
                                    [
                                        'email' => $userEmail,
                                        'firstName' => $firstName,
                                        'activationUrl' => $activationUrl,
                                    ]
                                );

                                if ($activationSent) {
                                    $delete = $connection->prepare(
                                        'DELETE FROM otp_codes WHERE otp_id = ?'
                                    );
                                    $delete->bind_param('i', $otpId);
                                    $delete->execute();
                                    $delete->close();
                                    unset($_SESSION['signup_otp_pending']);
                                    $_SESSION['signup_success'] =
                                        'Email verified. An activation link has been sent to your email. '
                                        . 'Open it to activate your account.';
                                    header('Location: login.php');
                                    exit;
                                }

                                $error =
                                    'Your code is valid, but the activation email could not be sent. '
                                    . 'Please try again.';
                            }
                        }
                    }
                }
            }
            } catch (Throwable $exception) {
                error_log('Signup OTP verification error: ' . $exception->getMessage());
                $error = 'Verification is temporarily unavailable. Please try again.';
            }
            $connection->close();
        }
    }
}

$csrfToken = (string) $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify your email | brewski</title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(BREWSKI_BASE_URL . '/images/brewskilogo.png', ENT_QUOTES, 'UTF-8') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
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
            <h1 class="auth__title">Verify your email</h1>
            <?php if ($pending !== null): ?>
                <p class="auth__hint">
                    A verification code has been sent to
                    <strong><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?></strong>.
                    Enter it below to continue.
                </p>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <p class="field__error" style="text-align:center;margin-bottom:1rem;">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </p>
            <?php endif; ?>
            <?php if ($pending !== null): ?>
                <form class="form" id="otp-form" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="field">
                        <label for="otp-digit-1">Verification code</label>
                        <div class="otp-inputs">
                            <?php for ($digit = 0; $digit < 6; $digit++): ?>
                                <input
                                    class="otp-input"
                                    type="text"
                                    inputmode="numeric"
                                    autocomplete="one-time-code"
                                    pattern="[0-9]"
                                    maxlength="1"
                                    aria-label="Digit <?= $digit + 1 ?>"
                                    <?= $digit === 0 ? 'id="otp-digit-1" autofocus' : '' ?>
                                    required
                                >
                            <?php endfor; ?>
                        </div>
                        <input id="otp" name="otp" type="hidden" value="">
                    </div>
                    <button class="btn" type="submit">Verify email</button>
                </form>
            <?php endif; ?>
            <p class="auth__switch"><a href="signup.php">Back to sign up</a></p>
        </div>
    </section>
</main>
<script src="otp.js"></script>
</body>
</html>

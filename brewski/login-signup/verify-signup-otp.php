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

                if (!$statement->fetch()) {
                    $error = 'This verification code is no longer available. Please request a new one.';
                } else {
                    $statement->close();
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
                        }

                        if (!$userStatement || !$userStatement->fetch()) {
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
                                    'Email verified. Check your email for the activation link.';
                                header('Location: login.php');
                                exit;
                            }

                            $error =
                                'Your code is valid, but the activation email could not be sent. '
                                . 'Please try again.';
                        }
                        if ($userStatement) {
                            $userStatement->close();
                        }
                    }
                }

                if ($statement instanceof mysqli_stmt) {
                    $statement->close();
                }
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
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<main class="auth">
    <aside class="side">
        <p class="side__word">brew<span>ski</span></p>
        <p class="side__line">Your favorite cup, ready when you are.</p>
    </aside>
    <section class="auth__panel">
        <div class="auth__content">
            <p class="brand__name">brew<span>ski</span></p>
            <h1 class="auth__title">Verify your email</h1>
            <p>Enter the six-digit code sent to <?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>.</p>
            <?php if ($error !== ''): ?>
                <p class="field__error" style="text-align:center;margin-bottom:1rem;">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </p>
            <?php endif; ?>
            <?php if ($pending !== null): ?>
                <form class="form" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="field">
                        <label for="otp">Verification code</label>
                        <input id="otp" name="otp" inputmode="numeric" autocomplete="one-time-code"
                               pattern="\d{6}" maxlength="6" required>
                    </div>
                    <button class="auth__button" type="submit">Verify email</button>
                </form>
            <?php endif; ?>
            <p class="auth__switch"><a href="signup.php">Back to sign up</a></p>
        </div>
    </section>
</main>
</body>
</html>

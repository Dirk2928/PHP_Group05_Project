<?php

require_once __DIR__ . '/auth_core.php';
require_once __DIR__ . '/email-service-client.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (brewski_is_logged_in()) {
    header('Location: ' . brewski_home_for_role(brewski_current_role()));
    exit;
}

$conn = brewski_db_connect();

if ($conn) {
    try {
        $conn->query('SELECT password_reset_token FROM users LIMIT 1');
    } catch (PDOException $exception) {
        $conn->exec('ALTER TABLE users ADD COLUMN password_reset_token CHAR(64) NULL AFTER email_activation_expires');
        $conn->exec('ALTER TABLE users ADD COLUMN password_reset_expires DATETIME NULL AFTER password_reset_token');
    }
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken)
        || !hash_equals((string) $_SESSION['csrf_token'], $submittedToken)
    ) {
        $error = 'This request could not be verified. Refresh the page and try again.';
    } elseif (!$conn) {
        $error = 'Unable to connect to the database. Please make sure MySQL is running.';
    } else {
        $email = trim((string) ($_POST['email'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $statement = $conn->prepare(
                'SELECT user_id, first_name, email FROM users WHERE ' . brewski_email_lookup_column() . ' = ? LIMIT 1'
            );

            if (!$statement) {
                $error = 'Something went wrong while looking up that account.';
            } else {
                $emailLookup = brewski_email_lookup($email);
                $statement->bind_param('s', $emailLookup);
                $statement->execute();
                $user = $statement->get_result()->fetch_assoc();
                if ($user) {
                    $user = brewski_decrypt_user_fields($user);
                }
                $statement->close();

                if ($user) {
                    $token = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $token);
                    $expiresAt = date('Y-m-d H:i:s', time() + 3600);

                    $update = $conn->prepare(
                        'UPDATE users
                         SET password_reset_token = ?, password_reset_expires = ?
                         WHERE user_id = ?'
                    );

                    if ($update) {
                        $update->bind_param('ssi', $tokenHash, $expiresAt, $user['user_id']);
                        $update->execute();
                        $update->close();
                    }

                    $baseUrl = defined('BREWSKI_BASE_URL') ? BREWSKI_BASE_URL : '';
                    $resetUrl = $baseUrl === ''
                        ? 'http://localhost/login-signup/reset-password.php?token=' . urlencode($token)
                        : $baseUrl . '/login-signup/reset-password.php?token=' . urlencode($token);

                    email_service_send('send-password-reset', [
                        'email' => $user['email'],
                        'firstName' => $user['first_name'],
                        'resetUrl' => $resetUrl,
                    ]);
                }

                $success = 'If an account exists for that email, a password reset link has been sent.';
            }
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
    <title>Forgot password | brewski</title>
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

            <h1 class="auth__title">Forgot password</h1>
            <p class="auth__intro">Enter your email and we’ll send you a secure reset link.</p>

            <?php if ($error !== ''): ?>
                <p id="reset-error" class="field__error" style="text-align: center; margin-bottom: 1rem;">
                    <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                </p>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <p id="reset-success" style="text-align: center; margin-bottom: 1rem; color: var(--mocha);">
                    <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                </p>
            <?php endif; ?>

            <?php if ($success === ''): ?>
                <form class="form" method="POST" action="forgot-password.php">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="field">
                        <label for="email"><svg class="field__icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>Email</label>
                        <input type="email" id="email" name="email" autocomplete="email" required>
                    </div>

                    <button type="submit" class="btn">Send reset link</button>
                </form>
            <?php endif; ?>

            <p class="auth__switch">
                <a href="login.php">Back to login</a>
            </p>
        </div>
    </section>
</main>

<script src="script.js"></script>
</body>
</html>

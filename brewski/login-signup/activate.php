<?php
require_once __DIR__ . '/session_init.php';

require_once __DIR__ . '/../Db/db_config.php';

mysqli_report(MYSQLI_REPORT_OFF);

$error = '';
$token = $_GET['token'] ?? '';

if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/i', $token)) {
    $error = 'This activation link is invalid or has already been used.';
} else {
    $conn = brewski_db_connect();

    if (!$conn) {
        $error = 'Unable to connect to the database. Please try again later.';
    } else {
        $token_hash = hash('sha256', $token);
        $stmt = $conn->prepare(
            "SELECT user_id, is_active, email_activation_expires
             FROM users
             WHERE email_activation_token = ?
             LIMIT 1"
        );

        if (!$stmt) {
            $error = 'Unable to verify this activation link. Please try again.';
        } else {
            $stmt->bind_param('s', $token_hash);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows !== 1) {
                $error = 'This activation link is invalid or has already been used.';
            } else {
                $stmt->bind_result($user_id, $is_active, $expires_at);
                $stmt->fetch();

                if ((int) $is_active === 1) {
                    $_SESSION['signup_success'] = 'Your account is activated. Log in to receive a one-time code.';
                    header('Location: login.php');
                    exit;
                }

                if (!$expires_at || strtotime($expires_at) < time()) {
                    $error = 'This activation link has expired. Please sign up again to receive a new one.';
                } else {
                    $update = $conn->prepare(
                        "UPDATE users
                         SET is_active = 1,
                             activation_token = NULL,
                             activation_expires = NULL,
                             email_activation_token = NULL,
                             email_activation_expires = NULL,
                             updated_at = CURRENT_TIMESTAMP
                         WHERE user_id = ? AND is_active = 0"
                    );

                    if (!$update) {
                        $error = 'Unable to activate your account. Please try again.';
                    } else {
                        $update->bind_param('i', $user_id);

                        if ($update->execute() && $update->affected_rows === 1) {
                            $_SESSION['signup_success'] = 'Your account is activated. Log in to receive a one-time code.';
                            header('Location: login.php');
                            exit;
                        }

                        $error = 'Unable to activate your account. Please try again.';
                        $update->close();
                    }
                }
            }

            $stmt->close();
        }

        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Activate your account | brewski</title>
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
<main class="auth">
    <aside class="side">
        <p class="side__word">brew<span>ski</span></p>
        <p class="side__line">Your favorite cup, ready when you are.</p>
    </aside>
    <section class="auth__panel">
        <div class="auth__content">
            <img class="brand__logo" src="../images/brewskilogo.png" alt="Brewski Logo">
            <p class="brand__name">brew<span>ski</span></p>
            <h1 class="auth__title">Activation link unavailable</h1>
            <p class="field__error" style="text-align:center;margin-bottom:1rem;">
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </p>
            <p class="auth__switch"><a href="login.php">Go to login</a></p>
        </div>
    </section>
</main>
</body>
</html>

<?php
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/../Db/db_config.php';

mysqli_report(MYSQLI_REPORT_OFF);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$customerHome = '../customer/customer_home/customerhome.php';
$adminHome    = '../admin/admin%20home/admin_dashboard.php';
$staffHome    = '../staff/orders/orders.php';

$conn = brewski_db_connect();

function normalizeRole($role)
{
    return strtoupper(trim((string) $role));
}

function redirectByRole($role, $customerHome, $adminHome, $staffHome)
{
    $role = normalizeRole($role);

    if ($role === 'ADMIN') {
        header('Location: ' . $adminHome);
    } elseif ($role === 'STAFF') {
        header('Location: ' . $staffHome);
    } else {
        header('Location: ' . $customerHome);
    }

    exit;
}

$error = '';
$email_value = '';

if (isset($_SESSION['signup_success'])) {
    $success = $_SESSION['signup_success'];
    unset($_SESSION['signup_success']);
} else {
    $success = '';
}

if (isset($_GET['logout'])) {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }

    session_destroy();
    header('Location: login.php');
    exit;
}

if (isset($_GET['msg']) && $_GET['msg'] === 'otp_cancelled') {
    $error = 'Login cancelled. Please log in again.';
}

if (isset($_GET['msg']) && $_GET['msg'] === 'login_method_changed') {
    $error = 'Sign-in now uses an authenticator. Enter your password to continue.';
}

if (isset($_GET['timeout'])) {
    $error = 'Your session expired. Please log in again.';
}

if (isset($_SESSION['user_id'])) {
    redirectByRole(
        $_SESSION['role'] ?? '',
        $customerHome,
        $adminHome,
        $staffHome
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    $tokenValid =
        !empty($_SESSION['csrf_token'])
        && is_string($submittedToken)
        && hash_equals((string) $_SESSION['csrf_token'], $submittedToken);

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $email_value = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

    if (!$tokenValid) {

        $error = 'This request could not be verified. Refresh the page and try again.';

    } elseif ($email === '' || $password === '') {

        $error = 'Please fill in all fields.';

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = 'Please enter a valid email address.';

    } elseif (!$conn) {

        $error = 'Unable to connect to the database. Please make sure MySQL is running.';

    } else {

        $stmt = $conn->prepare(
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

        if (!$stmt) {

            $error = 'Something went wrong while accessing the database.';

        } else {

            $stmt->bind_param('s', $email);

            if (!$stmt->execute()) {

                $error = 'Something went wrong. Please try again.';

            } else {

                $stmt->store_result();

                if ($stmt->num_rows === 1) {

                    $stmt->bind_result(
                        $user_id,
                        $first_name,
                        $last_name,
                        $user_email,
                        $stored_password,
                        $role,
                        $is_active,
                        $is_locked,
                        $totp_secret_encrypted,
                        $totp_enabled_at,
                        $failed_login_attempts
                    );

                    $stmt->fetch();

                    $role = normalizeRole($role);

                    $max_attempts = brewski_max_login_attempts();
                    $isLocked = (int) $is_locked === 1;
                    $lockedMessage = 'This account is locked after too many failed sign-in attempts. '
                        . 'Contact an administrator to unlock it.';

                    $passwordOk = password_verify($password, $stored_password);

                    if (
                        !$passwordOk &&
                        hash_equals((string) $stored_password, $password)
                    ) {
                        $passwordOk = true;

                        $newHash = password_hash($password, PASSWORD_DEFAULT);

                        $upd = $conn->prepare(
                            "UPDATE users
                             SET password = ?
                             WHERE user_id = ?"
                        );

                        if ($upd) {
                            $upd->bind_param('si', $newHash, $user_id);
                            $upd->execute();
                            $upd->close();
                        }
                    }

                    if ($passwordOk && !$isLocked) {

                        $resetAttempts = $conn->prepare(
                            "UPDATE users
                             SET failed_login_attempts = 0
                             WHERE user_id = ?"
                        );

                        if ($resetAttempts) {
                            $resetAttempts->bind_param('i', $user_id);
                            $resetAttempts->execute();
                            $resetAttempts->close();
                        }
                    }

                    if ($isLocked) {

                        $error = $lockedMessage;

                    } elseif (!$passwordOk) {

                        $attempts = (int) $failed_login_attempts + 1;

                        if ($attempts >= $max_attempts) {

                            $lockUpdate = $conn->prepare(
                                "UPDATE users
                                 SET is_locked = 1,
                                     failed_login_attempts = 0
                                 WHERE user_id = ?"
                            );

                            if ($lockUpdate) {
                                $lockUpdate->bind_param('i', $user_id);
                                $lockUpdate->execute();
                                $lockUpdate->close();
                            }

                            $error = $lockedMessage;

                        } else {

                            $attemptUpdate = $conn->prepare(
                                "UPDATE users
                                 SET failed_login_attempts = ?
                                 WHERE user_id = ?"
                            );

                            if ($attemptUpdate) {
                                $attemptUpdate->bind_param('ii', $attempts, $user_id);
                                $attemptUpdate->execute();
                                $attemptUpdate->close();
                            }

                            $remaining = $max_attempts - $attempts;

                            $error = 'Incorrect email or password. '
                                . $remaining
                                . ($remaining === 1 ? ' attempt' : ' attempts')
                                . ' left before this account is locked.';
                        }

                    } elseif ((int) $is_active !== 1) {

                        $error = 'Your account is not verified yet. Please complete the email verification first.';

                    } else {
                        try {
                            brewski_totp_encryption_key();
                        } catch (Throwable $exception) {
                            error_log('Authenticator login is unavailable: ' . $exception->getMessage());
                            $error = 'Authenticator sign-in is unavailable. Please contact support.';
                        }

                        if ($error === '') {
                            $hasSecret = !empty($totp_secret_encrypted);
                            $isEnabled = !empty($totp_enabled_at);

                            if ($hasSecret !== $isEnabled) {
                                error_log('Incomplete authenticator configuration for user ' . (int) $user_id);
                                $error = 'Authenticator sign-in is unavailable. Please contact support.';
                            } else {
                                session_regenerate_id(true);
                                unset($_SESSION['csrf_token']);
                                if ($hasSecret) {
                                    $_SESSION['totp_login_pending'] = [
                                        'user_id' => (int) $user_id,
                                        'expires_at' => time() + 600,
                                        'attempts' => 0,
                                    ];
                                    header('Location: verify-authenticator.php');
                                } else {
                                    $_SESSION['totp_enrollment_pending'] = [
                                        'user_id' => (int) $user_id,
                                        'expires_at' => time() + 900,
                                    ];
                                    header('Location: setup-authenticator.php');
                                }
                                exit;
                            }
                        }
                    }

                } else {
                    $error = 'Incorrect email or password.';
                }
            }
            $stmt->close();
        }
    }
}

$csrfToken = (string) $_SESSION['csrf_token'];
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Login | brewski</title>

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="styles.css"
    >

</head>

<body>

<main class="auth">

    <aside class="side">

        <p class="side__word">
            brew<span>ski</span>
        </p>

        <p class="side__line">
            Your favorite cup, ready when you are.
        </p>

    </aside>

    <section class="auth__panel">

        <div class="auth__content">

            <img
                class="brand__logo"
                src="../images/brewskilogo.png"
                alt="Brewski Logo"
            >

            <p class="brand__name">
                brew<span>ski</span>
            </p>

            <h1 class="auth__title">
                Welcome back
            </h1>

            <?php if ($success !== ''): ?>

                <p
                    id="login-success"
                    style="
                        text-align: center;
                        margin-bottom: 1rem;
                        color: green;
                    "
                >

                    <?php
                    echo htmlspecialchars(
                        $success,
                        ENT_QUOTES,
                        'UTF-8'
                    );
                    ?>

                </p>

            <?php endif; ?>

            <?php if ($error !== ''): ?>

                <p
                    id="login-error"
                    class="field__error"
                    style="
                        text-align: center;
                        margin-bottom: 1rem;
                    "
                >

                    <?php
                    echo htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        'UTF-8'
                    );
                    ?>

                </p>

            <?php else: ?>

                <p
                    id="login-error"
                    class="field__error"
                    style="
                        display: none;
                        text-align: center;
                        margin-bottom: 1rem;
                    "
                ></p>

            <?php endif; ?>

            <form
                class="form"
                id="login-form"
                method="POST"
                action="login.php"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"
                >

                <div class="field">

                    <label for="email">
                        Email
                    </label>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="<?php echo $email_value; ?>"
                        autocomplete="email"
                        required
                    >

                    <p
                        class="field__error"
                        id="email-error"
                        style="display: none;"
                    ></p>

                </div>

                <div class="field">

                    <label for="password">
                        Password
                    </label>

                    <div class="password-wrap">

                        <input
                            type="password"
                            id="password"
                            name="password"
                            autocomplete="current-password"
                            required
                        >

                        <button
                            type="button"
                            class="password-toggle"
                            id="toggle-password"
                            aria-label="Show password"
                            aria-pressed="false"
                        >
                            👁
                        </button>

                    </div>

                    <p
                        class="field__error"
                        id="password-error"
                        style="display: none;"
                    ></p>

                </div>

                <button
                    type="submit"
                    class="btn"
                >
                    Login
                </button>

            </form>

            <p class="auth__switch">

                Don't have an account?

                <a href="signup.php">
                    Click here to sign up.
                </a>

            </p>

            <p class="auth__switch">

                How we handle your data:

                <a
                    href="<?php echo htmlspecialchars(BREWSKI_BASE_URL . '/cookie-consent/privacy.php', ENT_QUOTES, 'UTF-8'); ?>"
                >
                    Privacy Policy
                </a>

            </p>

        </div>

    </section>

</main>

<?php require_once __DIR__ . '/../cookie-consent/cookie-consent.php'; ?>

<script src="script.js"></script>

</body>

</html>
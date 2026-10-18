<?php
require_once __DIR__ . '/auth_core.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$portal = brewski_portal('customer');

$conn = brewski_db_connect();

$error = '';
$email_value = '';
$portalRedirect = '';

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

// Staff and administrators belong on the portal; send them straight to their
// own landing page rather than bouncing them through the customer flow.
if (brewski_is_logged_in()) {
    header('Location: ' . brewski_home_for_role(brewski_current_role()));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    $email_value = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

    $submissionErrors = brewski_auth_submission_errors($_POST, false);

    if ($submissionErrors) {

        $error = $submissionErrors[0];

    } elseif (!$conn) {

        $error = 'Unable to connect to the database. Please make sure MySQL is running.';

    } else {

        $result = brewski_authenticate($conn, $email, $password, $portal['key']);

        if ($result['status'] === BREWSKI_AUTH_TOTP || $result['status'] === BREWSKI_AUTH_ENROLL) {
            header('Location: ' . $result['redirect']);
            exit;
        }

        if ($result['status'] === BREWSKI_AUTH_WRONG_PORTAL) {
            $portalRedirect = $result['redirect'];
        }

        $error = $result['message'];
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

                <?php if ($portalRedirect !== ''): ?>

                    <p
                        class="auth__switch"
                        style="margin-top: -0.5rem;"
                    >

                        <a href="<?php echo htmlspecialchars($portalRedirect, ENT_QUOTES, 'UTF-8'); ?>">
                            Go to the staff &amp; admin portal
                        </a>

                    </p>

                <?php endif; ?>

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

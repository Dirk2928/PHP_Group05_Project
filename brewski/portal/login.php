<?php










require_once __DIR__ . '/../login-signup/auth_core.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$portal = brewski_portal('portal');

$conn = brewski_db_connect();

$error = '';
$email_value = '';
$loginRedirect = '';
$foreignSession = false;

if (isset($_GET['msg']) && $_GET['msg'] === 'otp_cancelled') {
    $error = 'Sign-in cancelled. Please start again.';
}

if (isset($_GET['msg']) && $_GET['msg'] === 'login_method_changed') {
    $error = 'Sign-in now uses an authenticator. Enter your password to continue.';
}

if (isset($_GET['timeout'])) {
    $error = 'Your session expired. Please sign in again.';
}

if (brewski_is_logged_in()) {

    $currentRole = brewski_current_role();

    if (in_array($currentRole, $portal['roles'], true)) {
        header('Location: ' . brewski_home_for_role($currentRole));
        exit;
    }


    $foreignSession = true;
}

if (!$foreignSession && $_SERVER['REQUEST_METHOD'] === 'POST') {

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

        if ($result['status'] === BREWSKI_AUTH_LOCKED) {
            $loginRedirect = '';
        }

        if ($result['status'] === BREWSKI_AUTH_WRONG_PORTAL) {
            $loginRedirect = $result['redirect'];
        }

        $error = $result['message'];
    }
}

$csrfToken = (string) $_SESSION['csrf_token'];

$escape = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <meta
        name="robots"
        content="noindex, nofollow"
    >

    <title>Staff &amp; admin portal | brewski</title>

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
        href="../login-signup/styles.css"
    >

    <link
        rel="stylesheet"
        href="portal.css"
    >

</head>

<body>

<main class="auth auth--portal auth--centered auth--content-sized">

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

            <?php if ($foreignSession): ?>

                <h1 class="auth__title">
                    Wrong account type
                </h1>

                <p class="portal__notice">

                    You are signed in as a customer. This portal is only for
                    staff and administrator accounts.

                </p>

                <p class="auth__switch">

                    <a href="<?php echo $escape(BREWSKI_BASE_URL . '/customer/customer_home/customerhome.php'); ?>">
                        Go to my customer account
                    </a>

                </p>

                <p class="auth__switch">

                    <a href="<?php echo $escape(BREWSKI_BASE_URL . '/login-signup/logout.php'); ?>">
                        Sign out
                    </a>

                </p>

            <?php else: ?>

                <h1 class="auth__title">
                    Staff &amp; admin sign-in
                </h1>

                <p class="auth__intro">Authorized staff and administrators only.</p>

                <?php if ($error !== ''): ?>

                    <p
                        id="login-error"
                        class="field__error"
                        style="
                            text-align: center;
                            margin-bottom: 1rem;
                        "
                    >

                        <?php echo $escape($error); ?>

                    </p>

                    <?php if ($loginRedirect !== ''): ?>
                        <p class="auth__switch" style="margin-top: -0.5rem;">
                            <a href="<?php echo $escape($loginRedirect); ?>">Go to the customer sign-in</a>
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
                        value="<?php echo $escape($csrfToken); ?>"
                    >

                    <div class="field">

                        <label for="email">
                            <svg class="field__icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>
                            Work email
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
                            <svg class="field__icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
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

                        <p class="auth__switch" style="margin: 0.4rem 0 0; text-align: right; font-size: 0.72rem;">
                            <a href="../login-signup/forgot-password.php" style="color: var(--espresso); text-decoration: underline;">
                                Forgot your password?
                            </a>
                        </p>

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
                        Sign in
                    </button>

                </form>

                <p class="auth__switch" style="margin-top: 0.75rem;">
                    <a href="<?php echo $escape(BREWSKI_BASE_URL . '/login-signup/login.php'); ?>">
                        Back to customer
                    </a>
                </p>

            <?php endif; ?>

            <div class="auth__legal">
                <a href="<?php echo $escape(BREWSKI_BASE_URL . '/cookie-consent/privacy.php'); ?>">Privacy &amp; cookie policy</a>
            </div>

        </div>

    </section>

</main>

<?php require_once __DIR__ . '/../cookie-consent/cookie-consent.php'; ?>

<script src="../login-signup/script.js"></script>

</body>

</html>

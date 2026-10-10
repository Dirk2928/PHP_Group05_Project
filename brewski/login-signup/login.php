<?php
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/email-service-client.php';

mysqli_report(MYSQLI_REPORT_OFF);

$customerHome = '../customer/customer_home/customerhome.php';
$adminHome    = '../admin/admin%20home/admin_dashboard.php';
$staffHome    = '../staff/orders/orders.php';

$conn = new mysqli(
    'localhost',
    'root',
    '',
    'brewski_db'
);

if ($conn->connect_error) {
    $conn = null;
} else {
    $conn->set_charset('utf8mb4');
}

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

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $email_value = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

    if ($email === '' || $password === '') {

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
                is_active
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
                        $is_active
                    );

                    $stmt->fetch();

                    $role = normalizeRole($role);

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

                    if (!$passwordOk) {

                        $error = 'Incorrect email or password.';

                    } elseif ((int) $is_active !== 1) {

                        $error = 'Your account is not verified yet. Please complete the email verification first.';

                    } else {

                        try {
                            $otp = (string) random_int(100000, 999999);
                        } catch (Exception $e) {
                            $otp = null;
                            $error = 'Unable to generate a login code. Please try again.';
                        }

                        if ($otp !== null) {
                            $otp_hash = password_hash($otp, PASSWORD_DEFAULT);

                            $delete = $conn->prepare('DELETE FROM otp_codes WHERE user_id = ?');
                            if (!$delete) {
                                $error = 'Unable to prepare your login code. Please try again.';
                            } else {
                                $delete->bind_param('i', $user_id);
                                if (!$delete->execute()) {
                                    $error = 'Unable to prepare your login code. Please try again.';
                                }
                                $delete->close();
                            }

                            if ($error === '') {
                                $insert = $conn->prepare(
                                    'INSERT INTO otp_codes (user_id, code_hash, expires_at, attempts) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), 0)'
                                );

                                if (!$insert) {
                                    $error = 'Unable to save your login code. Please try again.';
                                } else {
                                    $insert->bind_param('is', $user_id, $otp_hash);
                                    if (!$insert->execute()) {
                                        $error = 'Unable to save your login code. Please try again.';
                                    }
                                    $insert->close();
                                }
                            }

                            if ($error === '') {
                                $email_sent = email_service_send(
                                    'send-login-otp',
                                    [
                                        'email' => $user_email,
                                        'firstName' => $first_name,
                                        'otp' => $otp
                                    ]
                                );

                                if ($email_sent) {
                                    session_regenerate_id(true);
                                    $_SESSION['otp_pending'] = [
                                        'user_id' => (int) $user_id,
                                        'first_name' => $first_name,
                                        'last_name' => $last_name,
                                        'email' => $user_email,
                                        'role' => $role,
                                        'last_sent' => time()
                                    ];
                                    header('Location: verify-otp.php');
                                    exit;
                                }

                                $error = 'We could not send your login code. Please try again.';
                            }

                            if ($error !== '') {
                                $clear = $conn->prepare('DELETE FROM otp_codes WHERE user_id = ?');
                                if ($clear) {
                                    $clear->bind_param('i', $user_id);
                                    $clear->execute();
                                    $clear->close();
                                }
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

        <nav class="side__nav">

            <a
                href="about-us.html"
                class="side__btn"
            >
                About us
            </a>

            <a
                href="about-brewski.html"
                class="side__btn"
            >
                About brewski
            </a>

        </nav>

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

        </div>

    </section>

</main>

<script src="script.js"></script>

</body>

</html>
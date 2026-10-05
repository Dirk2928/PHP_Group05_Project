<?php
require_once __DIR__ . '/session_init.php';

mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli(
    'localhost',
    'root',
    '',
    'brewski_db'
);

if ($conn->connect_error) {
    die('Unable to connect to the database.');
}

$conn->set_charset('utf8mb4');

if (empty($_SESSION['otp_pending'])) {
    header('Location: login.php');
    exit;
}

$pending = $_SESSION['otp_pending'];
$user_id = (int) $pending['user_id'];
$email = $pending['email'];

$error = $_SESSION['otp_resend_error'] ?? '';
$success = $_SESSION['otp_resend_success'] ?? '';
unset($_SESSION['otp_resend_error'], $_SESSION['otp_resend_success']);

if (isset($_GET['cancel'])) {
    $delete = $conn->prepare('DELETE FROM otp_codes WHERE user_id = ?');
    if ($delete) {
        $delete->bind_param('i', $user_id);
        $delete->execute();
        $delete->close();
    }

    unset($_SESSION['otp_pending']);
    header('Location: login.php?msg=otp_cancelled');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp = preg_replace('/\D/', '', $_POST['otp'] ?? '');

    if (strlen($otp) !== 6) {
        $error = 'Please enter the 6-digit login code.';
    } else {
        $stmt = $conn->prepare(
            "SELECT otp_id, code_hash, attempts, (expires_at > NOW()) AS still_valid
             FROM otp_codes
             WHERE user_id = ?
             ORDER BY otp_id DESC
             LIMIT 1"
        );

        if (!$stmt) {
            $error = 'Unable to verify your login code. Please try again.';
        } else {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows !== 1) {
                $error = 'No active login code. Please request a new one.';
            } else {
                $stmt->bind_result($otp_id, $code_hash, $attempts, $still_valid);
                $stmt->fetch();

                if (!(int) $still_valid) {
                    $error = 'Your login code has expired. Please request a new code.';
                } elseif ((int) $attempts >= 5) {
                    $error = 'Too many incorrect attempts. Please request a new code.';
                } elseif (password_verify($otp, $code_hash)) {
                    $delete = $conn->prepare('DELETE FROM otp_codes WHERE user_id = ?');
                    if ($delete) {
                        $delete->bind_param('i', $user_id);
                        $delete->execute();
                        $delete->close();
                    }

                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $pending['user_id'];
                    $_SESSION['first_name'] = $pending['first_name'];
                    $_SESSION['last_name'] = $pending['last_name'];
                    $_SESSION['email'] = $pending['email'];
                    $_SESSION['role'] = $pending['role'];

                    // Start the timeout clocks (read by session_init.php)
                    $_SESSION['created_at'] = time();    // absolute timeout
                    $_SESSION['last_activity'] = time(); // idle timeout

                    unset($_SESSION['otp_pending']);
                    session_write_close();

                    if ($pending['role'] === 'ADMIN') {
                        header('Location: ../admin/admin%20home/admin_dashboard.php');
                    } elseif ($pending['role'] === 'STAFF') {
                        header('Location: ../staff/orders/orders.php');
                    } else {
                        header('Location: ../customer/customer_home/customerhome.php');
                    }
                    exit;
                } else {
                    $update = $conn->prepare(
                        'UPDATE otp_codes SET attempts = attempts + 1 WHERE otp_id = ?'
                    );
                    if ($update) {
                        $update->bind_param('i', $otp_id);
                        $update->execute();
                        $update->close();
                    }

                    $left = max(0, 5 - ((int) $attempts + 1));
                    $error = $left > 0
                        ? "Incorrect code. {$left} attempt(s) left."
                        : 'Too many incorrect attempts. Please request a new code.';
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

    <title>Verify your email | brewski</title>

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
                Verify your login
            </h1>


            <?php if ($success !== ''): ?>
                <p class="field__success" style="text-align:center;margin-bottom:1rem;color:green;">
                    <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                </p>
            <?php endif; ?>


            <p style="
                text-align:center;
                margin-bottom:1.5rem;
                line-height:1.5;
            ">
                We sent a 6-digit login code to
                <strong>
                    <?php
                    echo htmlspecialchars(
                        $email,
                        ENT_QUOTES,
                        'UTF-8'
                    );
                    ?>
                </strong>. The code expires in 10 minutes.
            </p>


            <?php if ($error !== ''): ?>

                <p
                    class="field__error"
                    style="
                        text-align:center;
                        margin-bottom:1rem;
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

            <?php endif; ?>


            <form
                class="form"
                method="POST"
                action="verify-otp.php"
            >

                <div class="field">

                    <label for="otp">
                        Verification code
                    </label>

                    <input
                        type="text"
                        id="otp"
                        name="otp"
                        inputmode="numeric"
                        pattern="[0-9]{6}"
                        maxlength="6"
                        autocomplete="one-time-code"
                        placeholder="Enter 6-digit code"
                        required
                    >

                </div>


                <button
                    type="submit"
                    class="btn"
                >
                    Verify and log in
                </button>

            </form>


            <p
                style="
                    text-align:center;
                    margin-top:1.25rem;
                "
            >
                Didn't receive your code?
            </p>


            <form
                method="POST"
                action="resend-login-otp.php"
                style="text-align:center;"
            >

                <button
                    type="submit"
                    style="
                        background:none;
                        border:none;
                        padding:0;
                        font:inherit;
                        cursor:pointer;
                        text-decoration:underline;
                    "
                >
                    Resend code
                </button>

            </form>


            <p
                class="auth__switch"
                style="margin-top:1.5rem;"
            >

                <a href="verify-otp.php?cancel=1">
                    Cancel and go back to login
                </a>

            </p>

        </div>

    </section>

</main>

</body>

</html>
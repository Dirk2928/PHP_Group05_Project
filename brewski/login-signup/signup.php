<?php
session_start();
require_once __DIR__ . '/email-service-client.php';
require_once __DIR__ . '/settings.php';

mysqli_report(MYSQLI_REPORT_OFF);

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

if (isset($_SESSION['user_id'])) {
    header('Location: ../customer/customer_home/customerhome.php');
    exit;
}

$error   = '';
$success = '';

$first_name_value = '';
$last_name_value  = '';
$email_value      = '';

$password_policy = brewski_password_policy();

$min_password_length = (int) $password_policy['min_length'];

$password_hint = brewski_password_policy_hint($password_policy);

$errors = [
    'first_name' => [],
    'last_name'  => [],
    'email'      => [],
    'password'   => []
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $password   = $_POST['password'] ?? '';

    $first_name_value = htmlspecialchars(
        $first_name,
        ENT_QUOTES,
        'UTF-8'
    );

    $last_name_value = htmlspecialchars(
        $last_name,
        ENT_QUOTES,
        'UTF-8'
    );

    $email_value = htmlspecialchars(
        $email,
        ENT_QUOTES,
        'UTF-8'
    );

    if ($first_name === '') {

        $errors['first_name'][] =
            'Enter your first name.';

    } elseif (
        !preg_match(
            "/^[A-Za-zÀ-ÿ\s'\-]+$/u",
            $first_name
        )
    ) {

        $errors['first_name'][] =
            'First name may only contain letters, spaces, hyphens, and apostrophes.';

    }

    if ($last_name === '') {

        $errors['last_name'][] =
            'Enter your last name.';

    } elseif (
        !preg_match(
            "/^[A-Za-zÀ-ÿ\s'\-]+$/u",
            $last_name
        )
    ) {

        $errors['last_name'][] =
            'Last name may only contain letters, spaces, hyphens, and apostrophes.';

    }

    if ($email === '') {

        $errors['email'][] =
            'Enter your email address.';

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $errors['email'][] =
            'Please enter a valid email address.';

    }

    if ($password === '') {

        $errors['password'][] =
            'Enter a password.';

    } else {

        $errors['password'] = brewski_password_failures(
            $password,
            $password_policy
        );
    }

    $has_field_errors = false;

    foreach ($errors as $field_messages) {

        if ($field_messages) {

            $has_field_errors = true;

            break;
        }
    }

    if (!$has_field_errors && !$conn) {

        $error =
            'Unable to connect to the database. Please make sure MySQL is running.';

    } elseif (!$has_field_errors) {

        $check = $conn->prepare(
            "SELECT user_id, is_active
             FROM users
             WHERE email = ?
             LIMIT 1"
        );

        if (!$check) {

            $error = 'Unable to check your account. Please try again.';

        } else {

            $check->bind_param(
                's',
                $email
            );

            $check->execute();

            $check->store_result();

            $existing = null;

            if ($check->num_rows > 0) {

                $check->bind_result(
                    $existing_user_id,
                    $existing_is_active
                );

                $check->fetch();

                $existing = [
                    'user_id'   => $existing_user_id,
                    'is_active' => $existing_is_active
                ];
            }

            $check->close();

            if (
                $existing &&
                (int)$existing['is_active'] === 1
            ) {

                $errors['email'][] =
                    'An account with this email already exists.';

            } else {

                $hashed_password = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                try {
                    $email_activation_token = bin2hex(random_bytes(32));
                    $email_activation_hash = hash('sha256', $email_activation_token);
                    $email_activation_expires = date(
                        'Y-m-d H:i:s',
                        time() + (24 * 60 * 60)
                    );

                } catch (Exception $e) {

                    $error =
                        'Unable to generate the activation link. Please try again.';
                    $email_activation_token = null;
                }

                if ($error === '' && $email_activation_token !== null) {
                    $is_new_row = false;
                    $ok = false;
                    $stmt = null;

                    if ($existing) {

                        $user_id = (int)$existing['user_id'];

                        $stmt = $conn->prepare(
                            "UPDATE users
                             SET
                                first_name = ?,
                                last_name = ?,
                                password = ?,
                                role = 'CUSTOMER',
                                is_active = 0,
                                activation_token = NULL,
                                activation_expires = NULL,
                                email_activation_token = ?,
                                email_activation_expires = ?,
                                updated_at = CURRENT_TIMESTAMP
                             WHERE user_id = ?"
                        );

                        if ($stmt) {

                            $stmt->bind_param(
                                'sssssi',
                                $first_name,
                                $last_name,
                                $hashed_password,
                                $email_activation_hash,
                                $email_activation_expires,
                                $user_id
                            );

                            $ok = $stmt->execute();
                        }








                    } else {

                        $stmt = $conn->prepare(
                            "INSERT INTO users
                            (
                                first_name,
                                last_name,
                                email,
                                password,
                                role,
                                is_active,
                                email_activation_token,
                                email_activation_expires
                            )
                            VALUES
                            (
                                ?,
                                ?,
                                ?,
                                ?,
                                'CUSTOMER',
                                0,
                                ?,
                                ?
                            )"
                        );

                        if ($stmt) {

                            $stmt->bind_param(
                                'ssssss',
                                $first_name,
                                $last_name,
                                $email,
                                $hashed_password,
                                $email_activation_hash,
                                $email_activation_expires
                            );

                            $ok = $stmt->execute();

                            if ($ok) {
                                $user_id = $conn->insert_id;
                                $is_new_row = true;
                            }
                        }
                    }








                    if (!$ok) {

                        $error =
                            'Unable to create account. Please try again.';

                        if ($stmt) {
                            error_log(
                                'Signup DB error: ' .
                                $stmt->error
                            );
                        }








                    } else {

                        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                        $directory = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
                        $activation_url = $scheme . '://' . $host . $directory
                            . '/activate.php?token=' . urlencode($email_activation_token);

                        $email_sent = email_service_send(
                            'send-activation',
                            [
                                'email' => $email,
                                'firstName' => $first_name,
                                'activationUrl' => $activation_url
                            ]
                        );








                        if ($email_sent) {

                            $success =
                                'Account created. We sent an activation link to ' .
                                $email .
                                '. Open it to activate your account before logging in.';








                        } else {






                            if ($is_new_row) {

                                $delete = $conn->prepare(
                                    "DELETE FROM users
                                     WHERE user_id = ?"
                                );

                                if ($delete) {

                                    $delete->bind_param(
                                        'i',
                                        $user_id
                                    );

                                    $delete->execute();
                                    $delete->close();
                                }

                            } else {






                                $clear = $conn->prepare(
                                    "UPDATE users
                                     SET
                                        activation_token = NULL,
                                        activation_expires = NULL,
                                        email_activation_token = NULL,
                                        email_activation_expires = NULL
                                     WHERE user_id = ?"
                                );

                                if ($clear) {

                                    $clear->bind_param(
                                        'i',
                                        $user_id
                                    );

                                    $clear->execute();
                                    $clear->close();
                                }
                            }


                            $error =
                                'We could not send the activation email. Please make sure the email service is running and try again.';
                        }
                    }


                    if ($stmt) {
                        $stmt->close();
                    }
                }
            }
        }
    }
}

$render_field_errors = function (array $messages) {

    $html = '';

    foreach ($messages as $message) {

        $html .=
            '<p class="field__error">' .
            htmlspecialchars($message, ENT_QUOTES, 'UTF-8') .
            '</p>';
    }

    return $html;
};

$field_error_style = function (array $messages) {

    return $messages ? '' : ' style="display:none;"';
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

    <title>Create your account | brewski</title>

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
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
                Create your account
            </h1>


            <?php if ($error !== ''): ?>

                <p
                    id="signup-error"
                    class="field__error"
                    style="text-align:center; margin-bottom:1rem;"
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


            <?php if ($success !== ''): ?>

                <p
                    id="signup-success"
                    class="field__success"
                    style="text-align:center; margin-bottom:1rem; color:green;"
                >
                    <?php
                    echo htmlspecialchars(
                        $success,
                        ENT_QUOTES,
                        'UTF-8'
                    );
                    ?>
                </p>

                <p class="auth__switch">
                    <a href="login.php">
                        Go back to login
                    </a>
                </p>

            <?php else: ?>

            <form
                class="form"
                id="signup-form"
                method="POST"
                action="signup.php"
                novalidate
            >

                <div class="field">

                    <label for="first_name">
                        First name
                    </label>

                    <input
                        type="text"
                        id="first_name"
                        name="first_name"
                        value="<?php echo $first_name_value; ?>"
                        autocomplete="given-name"
                        required
                    >

                    <div
                        class="field__messages"
                        id="first_name-messages"<?php echo $field_error_style($errors['first_name']); ?>
                    ><?php echo $render_field_errors($errors['first_name']); ?></div>

                </div>


                <div class="field">

                    <label for="last_name">
                        Last name
                    </label>

                    <input
                        type="text"
                        id="last_name"
                        name="last_name"
                        value="<?php echo $last_name_value; ?>"
                        autocomplete="family-name"
                        required
                    >

                    <div
                        class="field__messages"
                        id="last_name-messages"<?php echo $field_error_style($errors['last_name']); ?>
                    ><?php echo $render_field_errors($errors['last_name']); ?></div>

                </div>


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

                    <div
                        class="field__messages"
                        id="email-messages"<?php echo $field_error_style($errors['email']); ?>
                    ><?php echo $render_field_errors($errors['email']); ?></div>

                </div>


                <div class="field">

                    <label for="password">
                        Create password
                    </label>

                    <div class="password-wrap">

                        <input
                            type="password"
                            id="password"
                            name="password"
                            autocomplete="new-password"
                            required
                            minlength="<?php echo (int) $min_password_length; ?>"
                            data-min-length="<?php echo (int) $min_password_length; ?>"
                            data-min-lowercase="<?php echo (int) $password_policy['lowercase']; ?>"
                            data-min-uppercase="<?php echo (int) $password_policy['uppercase']; ?>"
                            data-min-digits="<?php echo (int) $password_policy['digits']; ?>"
                            data-min-special="<?php echo (int) $password_policy['special']; ?>"
                            data-special-characters="<?php echo htmlspecialchars(brewski_password_special_characters(), ENT_QUOTES, 'UTF-8'); ?>"
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

                    <div
                        class="field__messages"
                        id="password-messages"<?php echo $field_error_style($errors['password']); ?>
                    ><?php echo $render_field_errors($errors['password']); ?></div>

                    <p class="password-hint">
                        <?php echo htmlspecialchars($password_hint, ENT_QUOTES, 'UTF-8'); ?>
                    </p>

                </div>


                <button
                    type="submit"
                    class="btn"
                >
                    Create account
                </button>

            </form>


            <p class="auth__switch">

                Already have an account?

                <a href="login.php">
                    Click here to login.
                </a>

            </p>

            <?php endif; ?>

        </div>

    </section>

</main>


<script src="script.js"></script>

</body>

</html>
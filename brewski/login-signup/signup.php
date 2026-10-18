<?php

require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/recaptcha.php';
require_once __DIR__ . '/../Db/db_config.php';
require_once __DIR__ . '/../Db/staff_columns.php';
require_once __DIR__ . '/email-service-client.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';
$first_name_value = '';
$last_name_value = '';
$email_value = '';
$first_timer_value = '';

$password_policy = brewski_password_policy();
$password_requirements = [
    'length' => [
        'label' => 'At least ' . (int) $password_policy['min_length'] . ' characters',
        'required' => (int) $password_policy['min_length'],
    ],
];

foreach (brewski_password_rule_definitions() as $rule_key => $rule) {
    $required = (int) $password_policy[$rule_key];

    if ($required > 0) {
        $password_requirements[$rule_key] = [
            'label' => 'At least '
                . $required
                . ' '
                . $rule['label']
                . ($required === 1 ? '' : 's'),
            'required' => $required,
        ];
    }
}

$conn = brewski_db_connect();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    $tokenValid =
        !empty($_SESSION['csrf_token'])
        && is_string($submittedToken)
        && hash_equals((string) $_SESSION['csrf_token'], $submittedToken);

    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $password   = $_POST['password'] ?? '';
    $confirm    = $_POST['confirm_password'] ?? '';
    $first_timer = $_POST['first_timer'] ?? '';
    $firstTimerValue = $first_timer === 'yes' ? 1 : 0;
    $first_timer_value = htmlspecialchars((string) $first_timer, ENT_QUOTES, 'UTF-8');

    $first_name_value = htmlspecialchars($first_name, ENT_QUOTES, 'UTF-8');
    $last_name_value  = htmlspecialchars($last_name, ENT_QUOTES, 'UTF-8');
    $email_value      = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

    // --- Validation ---
    if (!$tokenValid) {

        $error = 'This request could not be verified. Refresh the page and try again.';

    } elseif (!recaptcha_verify($_POST['g-recaptcha-response'] ?? '')) {

        $error = 'Please complete the reCAPTCHA verification.';

    } elseif ($first_name === '' || mb_strlen($first_name) > 100) {

        $error = 'Please enter a valid first name.';

    } elseif ($last_name === '' || mb_strlen($last_name) > 100) {

        $error = 'Please enter a valid last name.';

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = 'Please enter a valid email address.';

    } elseif (!in_array($first_timer, ['yes', 'no'], true)) {
        $error = 'Please tell us whether this is your first time ordering from a café.';
    } elseif ($password !== $confirm) {

        $error = 'The passwords do not match.';

    } elseif ($passwordFailures = brewski_password_failures($password, $password_policy)) {

        $error = implode(' ', $passwordFailures);

    } elseif (!$conn) {

        $error = 'Unable to connect to the database. Please make sure MySQL is running.';

    } else {
        brewski_ensure_preference_columns($conn);

        // --- Check for existing email ---
        $check = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");

        if (!$check) {

            $error = 'Something went wrong while accessing the database.';

        } else {

            $check->bind_param('s', $email);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {

                $error = 'That email is already registered. Please log in instead.';

            }
            $check->close();

            // --- Insert the new user ---
            if ($error === '') {

                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $activationToken = bin2hex(random_bytes(32));
                $activationExpires = date('Y-m-d H:i:s', time() + 86400);

                $insert = $conn->prepare(
                    "INSERT INTO users
                        (first_name, last_name, email, password, role, is_active, is_first_timer,
                         email_activation_token, email_activation_expires)
                     VALUES (?, ?, ?, ?, 'CUSTOMER', 0, ?, ?, ?)"
                );

                if (!$insert) {

                    $error = 'Something went wrong while creating your account.';

                } else {

                    $insert->bind_param(
                        'ssssiss',
                        $first_name,
                        $last_name,
                        $email,
                        $passwordHash,
                        $firstTimerValue,
                        $activationToken,
                        $activationExpires
                    );

                    if (!$insert->execute()) {

                        $error = 'Could not create your account. Please try again.';

                    } else {

                        $activationUrl = brewski_app_public_url(
                            '/login-signup/activate.php?token=' . urlencode($activationToken)
                        );

                        $activationEmailSent = $activationUrl !== null && email_service_send(
                            'send-activation',
                            [
                                'email'         => $email,
                                'firstName'     => $first_name,
                                'activationUrl' => $activationUrl,
                            ]
                        );

                        if ($activationEmailSent) {
                            $_SESSION['signup_success'] = 'Account created! Check your email to activate it.';
                            header('Location: login.php');
                            exit;
                        }

                        $error = 'Your account was created, but we could not send the activation email. Please contact support.';
                    }
                    $insert->close();
                }
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

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Sign up | brewski</title>

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

    <script src="https://www.google.com/recaptcha/api.js" async defer></script>

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
                Create your account
            </h1>

            <?php if ($error !== ''): ?>

                <p
                    class="field__error"
                    style="
                        text-align: center;
                        margin-bottom: 1rem;
                    "
                >
                    <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                </p>

            <?php endif; ?>

            <form
                class="form"
                id="signup-form"
                method="POST"
                action="signup.php"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"
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
                        maxlength="100"
                        autocomplete="given-name"
                        required
                    >

                </div>

                <fieldset class="field">
                    <legend>Is this your first time ordering beverages from a café?</legend>
                    <label>
                        <input type="radio" name="first_timer" value="yes"<?= $first_timer_value === 'yes' ? ' checked' : '' ?> required>
                        Yes
                    </label>
                    <label>
                        <input type="radio" name="first_timer" value="no"<?= $first_timer_value === 'no' ? ' checked' : '' ?>>
                        No
                    </label>
                </fieldset>

                <div class="field">

                    <label for="last_name">
                        Last name
                    </label>

                    <input
                        type="text"
                        id="last_name"
                        name="last_name"
                        value="<?php echo $last_name_value; ?>"
                        maxlength="100"
                        autocomplete="family-name"
                        required
                    >

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
                            autocomplete="new-password"
                            minlength="<?php echo (int) $password_policy['min_length']; ?>"
                            data-min-length="<?php echo (int) $password_policy['min_length']; ?>"
                            data-min-lowercase="<?php echo (int) $password_policy['lowercase']; ?>"
                            data-min-uppercase="<?php echo (int) $password_policy['uppercase']; ?>"
                            data-min-digits="<?php echo (int) $password_policy['digits']; ?>"
                            data-min-special="<?php echo (int) $password_policy['special']; ?>"
                            data-special-characters="<?php echo htmlspecialchars(brewski_password_special_characters(), ENT_QUOTES, 'UTF-8'); ?>"
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

                </div>

                <div
                    class="field__messages"
                    id="password-messages"
                    aria-live="polite"
                ></div>

                <ul
                    class="password-requirements"
                    id="password-requirements"
                    aria-label="Password requirements"
                >
                    <?php foreach ($password_requirements as $requirement_key => $requirement): ?>
                        <li
                            data-requirement="<?php echo htmlspecialchars($requirement_key, ENT_QUOTES, 'UTF-8'); ?>"
                            data-required="<?php echo (int) $requirement['required']; ?>"
                        ><?php echo htmlspecialchars($requirement['label'], ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                </ul>

                <div class="field">

                    <label for="confirm_password">
                        Confirm password
                    </label>

                    <div class="password-wrap">

                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            autocomplete="new-password"
                            required
                        >

                    </div>

                    <div
                        class="field__messages"
                        id="confirm_password-messages"
                        aria-live="polite"
                    ></div>

                </div>

                <div
                    class="g-recaptcha"
                    data-sitekey="<?php echo RECAPTCHA_SITE_KEY; ?>"
                ></div>

                <button
                    type="submit"
                    class="btn"
                >
                    Sign up
                </button>

            </form>

            <p class="auth__switch">

                Already have an account?

                <a href="login.php">
                    Click here to log in.
                </a>

            </p>

        </div>

    </section>

</main>

<script src="script.js"></script>

</body>

</html>
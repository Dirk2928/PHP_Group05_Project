<?php

require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/recaptcha.php';
require_once __DIR__ . '/../Db/db_config.php';
require_once __DIR__ . '/email-service-client.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';
$first_name_value = '';
$last_name_value = '';
$email_value = '';

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

    $first_name_value = htmlspecialchars($first_name, ENT_QUOTES, 'UTF-8');
    $last_name_value  = htmlspecialchars($last_name, ENT_QUOTES, 'UTF-8');
    $email_value      = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

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

    } elseif ($password !== $confirm) {

        $error = 'The passwords do not match.';

    } elseif ($passwordFailures = brewski_password_failures($password, $password_policy)) {

        $error = implode(' ', $passwordFailures);

    } elseif (!$conn) {

        $error = 'Unable to connect to the database. Please make sure MySQL is running.';

    } else {
        $otpTableReady = $conn->query(
            "CREATE TABLE IF NOT EXISTS otp_codes (
                otp_id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                code_hash VARCHAR(255) NOT NULL,
                expires_at DATETIME NOT NULL,
                attempts TINYINT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_otp_codes_user_id (user_id),
                CONSTRAINT fk_otp_codes_user
                    FOREIGN KEY (user_id)
                    REFERENCES users(user_id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE
            )"
        );

        if (!$otpTableReady) {
            $error = 'Email verification is temporarily unavailable. Please try again later.';
        }

        $check = $error === ''
            ? $conn->prepare("SELECT user_id FROM users WHERE email_lookup = ? LIMIT 1")
            : false;

        if (!$check) {

            $error = 'Something went wrong while accessing the database.';

        } else {

            $emailLookup = brewski_email_lookup($email);
            $check->bind_param('s', $emailLookup);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {

                $error = 'That email is already registered. Please log in instead.';

            }
            $check->close();

            if ($error === '') {

                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $activationToken = bin2hex(random_bytes(32));
                $activationTokenHash = hash('sha256', $activationToken);
                $activationExpires = date('Y-m-d H:i:s', time() + 86400);
                $encryptedFirstName = brewski_personal_encrypt($first_name, 'first_name');
                $encryptedLastName = brewski_personal_encrypt($last_name, 'last_name');
                $encryptedEmail = brewski_personal_encrypt($email, 'email');

                $insert = $conn->prepare(
                    "INSERT INTO users
                        (first_name, last_name, email, email_lookup, password, role, is_active,
                         is_first_timer, email_activation_token, email_activation_expires)
                     VALUES (?, ?, ?, ?, ?, 'CUSTOMER', 0, 1, ?, ?)"
                );

                if (!$insert) {

                    $error = 'Something went wrong while creating your account.';

                } else {

                    $insert->bind_param(
                        'sssssss',
                        $encryptedFirstName,
                        $encryptedLastName,
                        $encryptedEmail,
                        $emailLookup,
                        $passwordHash,
                        $activationTokenHash,
                        $activationExpires
                    );

                    if (!$insert->execute()) {

                        $error = 'Could not create your account. Please try again.';

                    } else {

                        $otp = (string) random_int(100000, 999999);
                        $otpHash = password_hash($otp, PASSWORD_DEFAULT);
                        $otpExpires = date('Y-m-d H:i:s', time() + 600);
                        $otpInsert = $conn->prepare(
                            'INSERT INTO otp_codes (user_id, code_hash, expires_at, attempts)
                             VALUES (?, ?, ?, 0)'
                        );

                        if (!$otpInsert) {
                            $error = 'Your account could not be prepared for email verification.';
                        } else {
                            $newUserId = (int) $conn->insert_id;
                            $otpInsert->bind_param('iss', $newUserId, $otpHash, $otpExpires);
                            $otpSaved = $otpInsert->execute();
                            $otpInsert->close();
                        }

                        $otpSent = $otpSaved ?? false;
                        if ($otpSent) {
                            $otpSent = email_service_send(
                            'send-signup-otp',
                            [
                                'email'         => $email,
                                'firstName'     => $first_name,
                                'otp'            => $otp,
                            ]
                            );
                        }

                        if ($otpSent) {
                            $_SESSION['signup_otp_pending'] = [
                                'user_id' => $newUserId,
                                'email' => $email,
                                'first_name' => $first_name,
                                'activation_token' => $activationToken,
                                'expires_at' => time() + 600,
                            ];
                            header('Location: verify-signup-otp.php');
                            exit;
                        }

                        $error = 'Your account was created, but we could not send the verification code. Please contact support.';
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
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(BREWSKI_BASE_URL . '/images/brewskilogo.png', ENT_QUOTES, 'UTF-8') ?>">

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

<main class="auth auth--centered">

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

            <p class="auth__intro">Join Brewski to make your next coffee run easier.</p>

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
                        <svg class="field__icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
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

                <div class="field">

                    <label for="last_name">
                        <svg class="field__icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
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
                        <svg class="field__icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>
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
                        <svg class="field__icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
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
                        <svg class="field__icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
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

            <p class="auth__switch">
                Staff or administrator?
                <a href="<?php echo htmlspecialchars(BREWSKI_BASE_URL . '/portal/login.php', ENT_QUOTES, 'UTF-8'); ?>">
                    Open the staff &amp; admin sign-in.
                </a>
            </p>

            <div class="auth__legal">
                <a href="<?php echo htmlspecialchars(BREWSKI_BASE_URL . '/cookie-consent/privacy.php', ENT_QUOTES, 'UTF-8'); ?>">Privacy &amp; cookie policy</a>
            </div>

        </div>

    </section>

</main>

<script src="script.js"></script>

</body>

</html>

<?php

require_once __DIR__ . '/auth_core.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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
$token = trim((string) ($_GET['token'] ?? ''));
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

if ($token === '') {
    $error = 'This reset link is invalid or has expired.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken)
        || !hash_equals((string) $_SESSION['csrf_token'], $submittedToken)
    ) {
        $error = 'This request could not be verified. Refresh the page and try again.';
    } elseif ($token === '') {
        $error = 'This reset link is invalid or has expired.';
    } elseif (!$conn) {
        $error = 'Unable to connect to the database. Please make sure MySQL is running.';
    } else {
        $password = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if ($password === '' || $confirmPassword === '') {
            $error = 'Please enter and confirm your new password.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } else {
            $failures = brewski_password_failures($password, brewski_password_policy());

            if ($failures) {
                $error = $failures[0];
            } else {
                $tokenHash = hash('sha256', $token);
                $statement = $conn->prepare(
                    'SELECT user_id
                     FROM users
                     WHERE password_reset_token = ?
                       AND password_reset_expires > NOW()
                     LIMIT 1'
                );

                if (!$statement) {
                    $error = 'Something went wrong while validating the reset link.';
                } else {
                    $statement->bind_param('s', $tokenHash);
                    $statement->execute();
                    $user = $statement->get_result()->fetch_assoc();
                    $statement->close();

                    if (!$user) {
                        $error = 'This reset link is invalid or has expired.';
                    } else {
                        $newHash = password_hash($password, PASSWORD_DEFAULT);
                        $update = $conn->prepare(
                            'UPDATE users
                             SET password = ?,
                                 password_reset_token = NULL,
                                 password_reset_expires = NULL,
                                 failed_login_attempts = 0,
                                 is_locked = 0
                             WHERE user_id = ?'
                        );

                        if (!$update) {
                            $error = 'Something went wrong while updating your password.';
                        } else {
                            $update->bind_param('si', $newHash, $user['user_id']);
                            $update->execute();
                            $update->close();
                            $success = 'Your password has been updated. You can now sign in.';
                        }
                    }
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset password | brewski</title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(BREWSKI_BASE_URL . '/images/brewskilogo.png', ENT_QUOTES, 'UTF-8') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<main class="auth">
    <aside class="side">
        <p class="side__word">brew<span>ski</span></p>
        <p class="side__line">Set a strong password and get back to your cup.</p>
    </aside>

    <section class="auth__panel">
        <div class="auth__content">
            <img class="brand__logo" src="../images/brewskilogo.png" alt="Brewski Logo">
            <p class="brand__name">brew<span>ski</span></p>

            <h1 class="auth__title">Reset password</h1>

            <?php if ($error !== ''): ?>
                <p class="field__error" style="text-align: center; margin-bottom: 1rem;">
                    <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                </p>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <p style="text-align: center; margin-bottom: 1rem; color: green;">
                    <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <p class="auth__switch">
                    <a href="login.php">Return to login</a>
                </p>
            <?php else: ?>
                <form class="form" id="reset-password-form" method="POST" action="reset-password.php?token=<?php echo urlencode($token); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="field">
                        <label for="password">New password</label>
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
                            <button type="button" class="password-toggle" id="toggle-password" aria-label="Show password" aria-pressed="false">👁</button>
                        </div>
                    </div>

                    <div class="field__messages" id="password-messages" aria-live="polite"></div>

                    <ul class="password-requirements" id="password-requirements" aria-label="Password requirements">
                        <?php foreach ($password_requirements as $requirement_key => $requirement): ?>
                            <li data-requirement="<?php echo htmlspecialchars($requirement_key, ENT_QUOTES, 'UTF-8'); ?>" data-required="<?php echo (int) $requirement['required']; ?>"><?php echo htmlspecialchars($requirement['label'], ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                    </ul>

                    <div class="field">
                        <label for="confirm_password">Confirm password</label>
                        <div class="password-wrap">
                            <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
                        </div>
                        <div class="field__messages" id="confirm_password-messages" aria-live="polite"></div>
                    </div>

                    <button type="submit" class="btn">Update password</button>
                </form>

                <p class="auth__switch">
                    <a href="login.php">Back to login</a>
                </p>
            <?php endif; ?>
        </div>
    </section>
</main>

<script src="script.js"></script>
</body>
</html>

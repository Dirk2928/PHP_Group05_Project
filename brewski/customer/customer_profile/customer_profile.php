<?php

require __DIR__ . '/../partials/bootstrap.php';
require_once __DIR__ . '/../partials/preferences.php';

$pageTitle = 'Profile | brewski';
$active = 'profile';
$extraStyles = ['customer_profile.css', 'choice.css'];

/*
 * ---------------------------------------------------------------------------
 * BACKEND NOT WIRED UP YET.
 *
 * The session, PDO connection, CSRF check and both POST handlers are still
 * commented out below, exactly as they were before this file moved. The page
 * renders and the markup is intact, but the two forms currently post to a
 * handler that does nothing, and the CSRF field renders empty.
 *
 * Restore this block to make the page functional. Note that the auth guard it
 * used to carry now lives in partials/bootstrap.php, so it does not need to
 * come back here.
 * ---------------------------------------------------------------------------
 *
 * if (!function_exists('e')) {
 *     function e($value)
 *     {
 *         return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
 *     }
 * }
 *
 * $dbHost = 'localhost';
 * $dbName = 'brewski_db';
 * $dbUser = 'root';
 * $dbPass = '';
 *
 * try {
 *     $pdo = new PDO(
 *         "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
 *         $dbUser,
 *         $dbPass,
 *         [
 *             PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
 *             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
 *         ]
 *     );
 * } catch (PDOException $e) {
 *     http_response_code(500);
 *     exit('Database connection failed.');
 * }
 *
 * if (empty($_SESSION['csrf_token'])) {
 *     $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
 * }
 *
 * $errors = [];
 * $success = '';
 *
 * if (!empty($_SESSION['user_id'])) {
 *     $userId = (int) $_SESSION['user_id'];
 * } else {
 *     $userId = (int) $pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
 * }
 *
 * if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 *     $token = $_POST['csrf_token'] ?? '';
 *
 *     if (!hash_equals($_SESSION['csrf_token'], $token)) {
 *         $errors[] = 'Invalid request. Please refresh the page and try again.';
 *     } else {
 *         $action = $_POST['action'] ?? '';
 *
 *         if ($action === 'update_profile') {
 *             $firstName = trim($_POST['first_name'] ?? '');
 *             $lastName = trim($_POST['last_name'] ?? '');
 *             $email = trim($_POST['email'] ?? '');
 *
 *             if ($firstName === '' || mb_strlen($firstName) > 50) {
 *                 $errors[] = 'Please enter a valid first name.';
 *             }
 *
 *             if ($lastName === '' || mb_strlen($lastName) > 50) {
 *                 $errors[] = 'Please enter a valid last name.';
 *             }
 *
 *             if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
 *                 $errors[] = 'Please enter a valid email address.';
 *             }
 *
 *             if (!$errors) {
 *                 $check = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
 *                 $check->execute([$email, $userId]);
 *
 *                 if ($check->fetch()) {
 *                     $errors[] = 'That email is already used by another account.';
 *                 }
 *             }
 *
 *             if (!$errors) {
 *                 $update = $pdo->prepare('UPDATE users SET first_name = ?, last_name = ?, email = ? WHERE id = ?');
 *                 $update->execute([$firstName, $lastName, $email, $userId]);
 *                 $success = 'Your profile has been updated.';
 *             }
 *         }
 *
 *         if ($action === 'change_password') {
 *             $current = $_POST['current_password'] ?? '';
 *             $new = $_POST['new_password'] ?? '';
 *             $confirm = $_POST['confirm_password'] ?? '';
 *
 *             $row = $pdo->prepare('SELECT password FROM users WHERE id = ?');
 *             $row->execute([$userId]);
 *             $hash = $row->fetchColumn();
 *
 *             if (!$hash || !password_verify($current, $hash)) {
 *                 $errors[] = 'Your current password is incorrect.';
 *             }
 *
 *             if (strlen($new) < 8) {
 *                 $errors[] = 'The new password must be at least 8 characters.';
 *             }
 *
 *             if ($new !== $confirm) {
 *                 $errors[] = 'The new passwords do not match.';
 *             }
 *
 *             if (!$errors) {
 *                 $update = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
 *                 $update->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
 *                 $success = 'Your password has been changed.';
 *             }
 *         }
 *     }
 * }
 *
 * $stmt = $pdo->prepare('SELECT id, first_name, last_name, email FROM users WHERE id = ?');
 * $stmt->execute([$userId]);
 * $user = $stmt->fetch();
 *
 * if (!$user) {
 *     $user = [
 *         'id' => 0,
 *         'first_name' => 'Guest',
 *         'last_name' => '',
 *         'email' => '',
 *     ];
 * }
 *
 * $display_first_name = e($user['first_name']);
 * $display_last_name = e($user['last_name']);
 * $display_email = e($user['email']);
 */

// Placeholders standing in for what the commented block above produces.
$success = $success ?? '';
$errors = $errors ?? [];
$csrf_token = $_SESSION['csrf_token'] ?? '';

$initials = strtoupper(
    mb_substr($first_name, 0, 1) .
    mb_substr($last_name, 0, 1)
);

$choiceUserId = (int) ($_SESSION['user_id'] ?? 0);
$choicePreferences = $choiceUserId > 0 ? brewski_user_preferences($choiceUserId) : [];
$choiceAnswers = is_array($choicePreferences)
    ? brewski_answers_from_preferences($choicePreferences)
    : [];
$choiceValues = $choiceAnswers;
$choiceAutoOpen = false;

require __DIR__ . '/../partials/header.php';

?>

    <!--
        All profile styles are inside this file on purpose, so the page does
        not depend on customer_profile.css being found. Once the stylesheet
        loads correctly through $extraStyles you can delete this block.
    -->
    <style>
        :root {
            --espresso: #1a0f0a;
            --mocha: #3b2218;
            --dark-mocha: #5c4033;
            --latte: #e8dac4;
            --cream: #faf5ee;
            --foam: #f2e4cc;
            --danger: #a32a1f;
            --success: #2f6b3a;
        }

        .page *,
        .page *::before,
        .page *::after {
            box-sizing: border-box;
        }

        .page a {
            color: inherit;
            text-decoration: none;
        }

        .page i[data-lucide],
        .page svg.lucide {
            width: 20px;
            height: 20px;
            stroke-width: 1.8;
        }

        .page {
            max-width: calc(1420px + 3rem);
            margin: 0 auto;
            padding: 2.5rem 1.5rem 4rem;
            color: var(--espresso);
        }

        .page h1,
        .page h2,
        .page h3,
        .page p {
            margin: 0;
        }

        .page__header {
            padding-bottom: 1.5rem;
            margin-bottom: 2rem;
            border-bottom: 1px solid var(--latte);
        }

        .page__header h1 {
            font-size: 2.25rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .page__header p {
            color: var(--dark-mocha);
            font-size: 1rem;
            line-height: 1.5;
        }

        .alert {
            padding: 1rem 1.25rem;
            border-radius: 0.75rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            line-height: 1.5;
        }

        .alert--success {
            background-color: #e3f0e5;
            border: 1px solid #b9d8bf;
            color: var(--success);
        }

        .alert--error {
            background-color: #f8e3e0;
            border: 1px solid #e6b8b2;
            color: var(--danger);
        }

        .profile-grid {
            display: grid;
            grid-template-columns: 340px 1fr;
            gap: 1.5rem;
            align-items: start;
        }

        .profile-forms {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .card {
            background-color: var(--foam);
            border-radius: 1.25rem;
            padding: 1.75rem;
        }

        .card__title {
            font-size: 1.15rem;
            font-weight: 700;
            margin-bottom: 1.25rem;
        }

        .profile-summary {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        .avatar {
            width: 96px;
            height: 96px;
            border-radius: 50%;
            background-color: var(--espresso);
            color: var(--cream);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            margin-bottom: 1.1rem;
        }

        .profile-summary h2 {
            font-size: 1.3rem;
            margin-bottom: 0.4rem;
        }

        .profile-summary__email {
            color: var(--dark-mocha);
            font-size: 0.9rem;
            word-break: break-word;
        }

        .profile-summary__actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            width: 100%;
            margin-top: 1.75rem;
        }

        .form {
            display: flex;
            flex-direction: column;
            gap: 1.1rem;
        }

        .form__row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.1rem;
        }

        .form__group {
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
        }

        .form__group label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--mocha);
        }

        .form__group input {
            font-family: inherit;
            font-size: 0.95rem;
            padding: 0.85rem 1rem;
            border: 1px solid var(--latte);
            border-radius: 0.75rem;
            background-color: var(--cream);
            color: var(--espresso);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .form__group input:focus {
            outline: none;
            border-color: var(--mocha);
            box-shadow: 0 0 0 3px rgba(59, 34, 24, 0.12);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-family: inherit;
            font-size: 0.95rem;
            font-weight: 600;
            padding: 0.95rem 1.25rem;
            border: none;
            border-radius: 0.75rem;
            cursor: pointer;
            transition: background-color 0.2s ease, transform 0.1s ease;
        }

        .btn:active {
            transform: scale(0.98);
        }

        .btn--block {
            width: 100%;
        }

        .btn--dark {
            background-color: var(--espresso);
            color: var(--cream);
        }

        .btn--dark:hover {
            background-color: var(--mocha);
        }

        .btn--light {
            background-color: var(--cream);
            color: var(--espresso);
            border: 1px solid var(--latte);
        }

        .btn--light:hover {
            background-color: var(--latte);
        }

        /*
         * Dark buttons: the rule ".page a { color: inherit }" above is more
         * specific than ".btn--dark", which made the Logout text dark on a
         * dark background. These rules win over it and over shared styles.
         */
        .page .btn--dark,
        .page a.btn--dark,
        .page button.btn--dark {
            background-color: var(--espresso);
            color: var(--cream) !important;
        }

        .page .btn--dark:hover,
        .page a.btn--dark:hover,
        .page button.btn--dark:hover {
            background-color: var(--mocha);
        }

        .page .btn--dark span,
        .page .btn--dark i,
        .page .btn--dark svg {
            color: var(--cream) !important;
            stroke: var(--cream) !important;
        }

        .page .btn--light,
        .page a.btn--light {
            background-color: var(--cream);
            color: var(--espresso) !important;
            border: 1px solid var(--latte);
        }

        .page .btn--light:hover,
        .page a.btn--light:hover {
            background-color: var(--latte);
        }

        .page .btn--light span,
        .page .btn--light i,
        .page .btn--light svg {
            color: var(--espresso) !important;
            stroke: var(--espresso) !important;
        }

        .choice-summary__empty {
            color: var(--dark-mocha);
            font-size: 0.95rem;
            line-height: 1.5;
            margin-bottom: 1.25rem;
        }

        @media (max-width: 900px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 720px) {
            .form__row {
                grid-template-columns: 1fr;
            }

            .page__header h1 {
                font-size: 1.75rem;
            }
        }
    </style>

    <main class="page">

        <section class="page__header">

            <h1>My Profile</h1>

            <p>Manage your account details and keep your Brewski orders up to date.</p>

        </section>

        <?php if ($success): ?>

            <div class="alert alert--success"><?= e($success) ?></div>

        <?php endif; ?>

        <?php if ($errors): ?>

            <div class="alert alert--error">

                <?php foreach ($errors as $error): ?>

                    <p><?= e($error) ?></p>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

        <div class="profile-grid">

            <aside class="card profile-summary">

                <div class="avatar"><?= e($initials) ?></div>

                <h2><?= e($display_first_name . ' ' . $display_last_name) ?></h2>

                <p class="profile-summary__email"><?= e($display_email) ?></p>

                <div class="profile-summary__actions">

                    <!-- TODO: Orders page does not exist yet. -->
                    <a href="../customer_orders/orders.php" class="btn btn--light">

                        <i data-lucide="receipt"></i>

                        <span>View orders</span>

                    </a>

                    <a href="../../login-signup/logout.php" class="btn btn--dark">

                        <i data-lucide="log-out"></i>

                        <span>Logout</span>

                    </a>

                </div>

            </aside>

            <div class="profile-forms">

                <section class="card">

                    <h3 class="card__title">Personal information</h3>

                    <form
                        method="post"
                        action="customer_profile.php"
                        class="form"
                        novalidate
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e($csrf_token) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="update_profile"
                        >

                        <div class="form__row">

                            <div class="form__group">

                                <label for="first_name">First name</label>

                                <input
                                    type="text"
                                    id="first_name"
                                    name="first_name"
                                    value="<?= $display_first_name ?>"
                                    required
                                    maxlength="50"
                                >

                            </div>

                            <div class="form__group">

                                <label for="last_name">Last name</label>

                                <input
                                    type="text"
                                    id="last_name"
                                    name="last_name"
                                    value="<?= $display_last_name ?>"
                                    required
                                    maxlength="50"
                                >

                            </div>

                        </div>

                        <div class="form__group">

                            <label for="email">Email address</label>

                            <input
                                type="email"
                                id="email"
                                name="email"
                                value="<?= $display_email ?>"
                                required
                            >

                        </div>

                        <button
                            type="submit"
                            class="btn btn--dark btn--block"
                        >

                            <i data-lucide="check"></i>

                            <span>Save changes</span>

                        </button>

                    </form>

                </section>

                <section class="card">

                    <h3 class="card__title">Your choices</h3>

                    <?php if ($choiceAnswers): ?>

                        <ul class="choice-summary">

                            <?php foreach (brewski_choice_questions() as $questionKey => $question): ?>

                                <li>

                                    <span class="choice-summary__question">
                                        <?= e($question['question']) ?>
                                    </span>

                                    <span class="choice-summary__answer">
                                        <?= e($choiceAnswers[$questionKey] ?? 'Not answered') ?>
                                    </span>

                                </li>

                            <?php endforeach; ?>

                        </ul>

                    <?php else: ?>

                        <p class="choice-summary__empty">
                            You have not made your choices yet.
                            Answer a few quick questions and we will sort the
                            menu around what you like.
                        </p>

                    <?php endif; ?>

                    <button
                        type="button"
                        class="btn btn--dark btn--block"
                        id="choice-open"
                    >

                        <i data-lucide="sparkles"></i>

                        <span><?= $choiceAnswers ? 'Change choices' : 'Take the assessment' ?></span>

                    </button>

                </section>

                <section class="card">

                    <h3 class="card__title">Change password</h3>

                    <form
                        method="post"
                        action="customer_profile.php"
                        class="form"
                        novalidate
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e($csrf_token) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="change_password"
                        >

                        <div class="form__group">

                            <label for="current_password">Current password</label>

                            <input
                                type="password"
                                id="current_password"
                                name="current_password"
                                required
                                autocomplete="current-password"
                            >

                        </div>

                        <div class="form__row">

                            <div class="form__group">

                                <label for="new_password">New password</label>

                                <input
                                    type="password"
                                    id="new_password"
                                    name="new_password"
                                    required
                                    minlength="8"
                                    autocomplete="new-password"
                                >

                            </div>

                            <div class="form__group">

                                <label for="confirm_password">Confirm new password</label>

                                <input
                                    type="password"
                                    id="confirm_password"
                                    name="confirm_password"
                                    required
                                    minlength="8"
                                    autocomplete="new-password"
                                >

                            </div>

                        </div>

                        <button
                            type="submit"
                            class="btn btn--dark btn--block"
                        >

                            <i data-lucide="lock"></i>

                            <span>Update password</span>

                        </button>

                    </form>

                </section>

            </div>

        </div>

    </main>

<?php require __DIR__ . '/../partials/choice-modal.php'; ?>

<?php require __DIR__ . '/../partials/footer.php'; ?>
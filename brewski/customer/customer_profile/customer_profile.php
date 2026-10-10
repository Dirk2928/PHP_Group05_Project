<?php

require __DIR__ . '/../partials/bootstrap.php';
require_once __DIR__ . '/../partials/preferences.php';

$pageTitle = 'Profile | brewski';
$active = 'profile';
$extraStyles = ['customer_profile.css', 'choice.css'];

require_once __DIR__ . '/../../Db/connection.php';

if (!brewski_is_logged_in()) {
    brewski_end_session();
}

$errors = [];
$success = '';
$userId = (int) ($_SESSION['user_id'] ?? 0);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

if (!empty($_SESSION['profile_flash'])) {
    $success = (string) $_SESSION['profile_flash'];
    unset($_SESSION['profile_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $userId > 0) {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($submittedToken) || !hash_equals($csrf_token, $submittedToken)) {

        $errors[] = 'Invalid request. Please refresh the page and try again.';

    } else {

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'update_profile') {

            $firstNameInput = trim((string) ($_POST['first_name'] ?? ''));
            $lastNameInput  = trim((string) ($_POST['last_name'] ?? ''));
            $emailInput     = trim((string) ($_POST['email'] ?? ''));

            if ($firstNameInput === '' || mb_strlen($firstNameInput) > 50) {
                $errors[] = 'Please enter a valid first name.';
            } elseif (!preg_match("/^[A-Za-zÀ-ÿ\s'\-]+$/u", $firstNameInput)) {
                $errors[] = 'First name may only contain letters, spaces, hyphens, and apostrophes.';
            }

            if ($lastNameInput === '' || mb_strlen($lastNameInput) > 50) {
                $errors[] = 'Please enter a valid last name.';
            } elseif (!preg_match("/^[A-Za-zÀ-ÿ\s'\-]+$/u", $lastNameInput)) {
                $errors[] = 'Last name may only contain letters, spaces, hyphens, and apostrophes.';
            }

            if ($emailInput === '' || mb_strlen($emailInput) > 255) {
                $errors[] = 'Please enter a valid email address.';
            } elseif (!filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            }

            if (!$errors) {

                $check = $pdo->prepare(
                    'SELECT user_id FROM users WHERE email = ? AND user_id <> ? LIMIT 1'
                );
                $check->execute([$emailInput, $userId]);

                if ($check->fetch()) {
                    $errors[] = 'That email is already used by another account.';
                }
            }

            if (!$errors) {

                $update = $pdo->prepare(
                    'UPDATE users SET first_name = ?, last_name = ?, email = ? WHERE user_id = ?'
                );
                $update->execute([$firstNameInput, $lastNameInput, $emailInput, $userId]);

                $_SESSION['first_name'] = $firstNameInput;
                $_SESSION['last_name']  = $lastNameInput;
                $_SESSION['email']      = $emailInput;
                $_SESSION['profile_flash'] = 'Your profile has been updated.';

                header('Location: customer_profile.php');
                exit;
            }
        }

        if ($action === 'change_password') {

            $currentInput = (string) ($_POST['current_password'] ?? '');
            $newInput     = (string) ($_POST['new_password'] ?? '');
            $confirmInput = (string) ($_POST['confirm_password'] ?? '');

            $passwordPolicy = brewski_password_policy();

            $row = $pdo->prepare('SELECT password FROM users WHERE user_id = ?');
            $row->execute([$userId]);
            $storedHash = $row->fetchColumn();

            if (!$storedHash || !password_verify($currentInput, $storedHash)) {
                $errors[] = 'Your current password is incorrect.';
            }

            if ($newInput === '') {

                $errors[] = 'Enter a new password.';

            } else {

                foreach (brewski_password_failures($newInput, $passwordPolicy) as $failure) {
                    $errors[] = $failure;
                }
            }

            if ($newInput !== $confirmInput) {
                $errors[] = 'The new passwords do not match.';
            }

            if (!$errors && $newInput === $currentInput) {
                $errors[] = 'The new password must be different from your current password.';
            }

            if (!$errors) {

                $update = $pdo->prepare('UPDATE users SET password = ? WHERE user_id = ?');
                $update->execute([password_hash($newInput, PASSWORD_DEFAULT), $userId]);

                session_regenerate_id(true);
                $_SESSION['profile_flash'] = 'Your password has been changed.';

                header('Location: customer_profile.php');
                exit;
            }
        }
    }
}

$stmt = $pdo->prepare(
    'SELECT first_name, last_name, email FROM users WHERE user_id = ? LIMIT 1'
);
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    $user = [
        'first_name' => $first_name,
        'last_name'  => $last_name,
        'email'      => $email,
    ];
}

$display_first_name = e($user['first_name']);
$display_last_name  = e($user['last_name']);
$display_email      = e($user['email']);

$initials = strtoupper(
    mb_substr((string) $user['first_name'], 0, 1) .
    mb_substr((string) $user['last_name'], 0, 1)
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
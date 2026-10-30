<?php

require __DIR__ . '/../partials/bootstrap.php';
require_once __DIR__ . '/../partials/preferences.php';

brewski_require_role(['CUSTOMER']);

$pageTitle = 'Profile | brewski';
$active = 'profile';
$extraStyles = ['customer_profile/customer_profile.css', 'partials/choice.css'];

require_once __DIR__ . '/../../Db/connection.php';
require_once __DIR__ . '/../../Db/activity_log.php';
require_once __DIR__ . '/../../Db/user_avatars.php';

if (!brewski_is_logged_in()) {
    brewski_end_session();
}

$errors = [];
$success = '';
$failedAction = '';
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId > 0) {
    $avatarConn = brewski_db_connection();
    brewski_ensure_user_avatar_columns($avatarConn);
    $avatarConn->close();
}

$avatarUrl = null;
if ($userId > 0) {
    try {
        $avatarConn = brewski_db_connection();
        $avatarStmt = $avatarConn->prepare(
            'SELECT avatar_mime_type, updated_at FROM users WHERE user_id = ? LIMIT 1'
        );
        $avatarStmt->bind_param('i', $userId);
        $avatarStmt->execute();
        $avatarStmt->bind_result($avatarMime, $avatarUpdatedAt);
        if ($avatarStmt->fetch() && $avatarMime) {
            $cacheBuster = $avatarUpdatedAt ? strtotime($avatarUpdatedAt) : time();
            $avatarUrl = BREWSKI_BASE_URL . '/customer/user_avatar.php?id=' . $userId . '&v=' . $cacheBuster;
        }
        $avatarStmt->close();
        $avatarConn->close();
    } catch (Throwable $e) {
        $avatarUrl = null;
    }
}

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

        if ($action === 'update_avatar') {

            if (!isset($_FILES['avatar'])) {
                $errors[] = 'Choose an image to upload.';
            } else {
                try {
                    $conn = brewski_db_connection();
                    $avatar = brewski_read_avatar_upload($_FILES['avatar']);

                    $stmt = $conn->prepare(
                        'UPDATE users SET avatar_data = ?, avatar_mime_type = ?, updated_at = NOW() WHERE user_id = ?'
                    );
                    $encryptedAvatar = brewski_personal_encrypt($avatar['data'], 'avatar_data');
                    $stmt->bind_param('ssi', $encryptedAvatar, $avatar['mime'], $userId);
                    $stmt->execute();
                    $stmt->close();
                    $conn->close();

                    brewski_record_activity(
                        $pdo,
                        'profile',
                        'Updated profile picture',
                        'Updated the account profile picture.'
                    );
                    $_SESSION['profile_flash'] = 'Your profile picture has been updated.';
                    header('Location: customer_profile.php');
                    exit;

                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }

        if ($action === 'remove_avatar') {
            $conn = brewski_db_connection();
            $stmt = $conn->prepare(
                'UPDATE users SET avatar_data = NULL, avatar_mime_type = NULL, updated_at = NOW() WHERE user_id = ?'
            );
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
            $conn->close();

            brewski_record_activity(
                $pdo,
                'profile',
                'Removed profile picture',
                'Removed the account profile picture.'
            );
            $_SESSION['profile_flash'] = 'Your profile picture has been removed.';
            header('Location: customer_profile.php');
            exit;
        }

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
                    'SELECT user_id FROM users WHERE email_lookup = ? AND user_id <> ? LIMIT 1'
                );
                $check->execute([brewski_email_lookup($emailInput), $userId]);

                if ($check->fetch()) {
                    $errors[] = 'That email is already used by another account.';
                }
            }

            if (!$errors) {
                $update = $pdo->prepare(
                    'UPDATE users SET first_name = ?, last_name = ?, email = ?, email_lookup = ? WHERE user_id = ?'
                );
                $update->execute([...brewski_encrypted_user_values($firstNameInput, $lastNameInput, $emailInput), $userId]);

                $_SESSION['first_name'] = $firstNameInput;
                $_SESSION['last_name']  = $lastNameInput;
                $_SESSION['email']      = $emailInput;
                brewski_record_activity(
                    $pdo,
                    'profile',
                    'Updated profile',
                    'Updated account profile details.'
                );
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
                brewski_record_activity(
                    $pdo,
                    'security',
                    'Changed password',
                    'Changed the account password.'
                );
                $_SESSION['profile_flash'] = 'Your password has been changed.';

                header('Location: customer_profile.php');
                exit;
            }
        }

        if ($errors) {
            $failedAction = $action;
        }
    }
}

$stmt = $pdo->prepare(
    'SELECT first_name, last_name, email FROM users WHERE user_id = ? LIMIT 1'
);
$stmt->execute([$userId]);
$user = $stmt->fetch();
if ($user) {
    $user = brewski_decrypt_user_fields($user);
}

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
$choiceMode = 'recommendation';

require __DIR__ . '/../partials/header.php';

?>

    <style>
        :root {
            --espresso: #1e110a;
            --mocha: #71492a;
            --dark-mocha: #3c3027;
            --latte: #f1e2ca;
            --cream: #faf6ee;
            --foam: #f5ebdf;
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
            max-width: none;
            margin: 0 auto;
            padding: 2rem 1.5rem 4rem;
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
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }

        .page__header p {
            color: var(--dark-mocha);
            font-size: 14px;
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
            grid-template-columns: minmax(0, 340px) minmax(0, 1fr);
            gap: 1.5rem;
            align-items: start;
        }

        .profile-forms {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .card {
            background-color: #fffdf9;
            border: 1px solid rgba(30, 17, 10, .12);
            border-radius: 1.25rem;
            padding: 1.75rem;
            box-shadow: 0 8px 24px rgba(30, 17, 10, .06);
        }

        .profile-summary {
            background: linear-gradient(160deg, #f5ebdf, #fffdf9 78%);
        }

        .card__title {
            font-size: 1.15rem;
            font-weight: 700;
            margin-bottom: 1.25rem;
        }

        .card__header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.25rem;
        }

        .card__header .card__title {
            margin-bottom: 0;
        }

        .page [hidden] {
            display: none !important;
        }

        .profile-summary {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        .avatar-form {
            display: inline-block;
            margin: 0 0 1.1rem;
        }

        .avatar-label {
            position: relative;
            display: inline-block;
            cursor: pointer;
            border-radius: 50%;
        }

        .avatar {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 96px;
            height: 96px;
            border-radius: 50%;
            background-color: var(--espresso);
            color: var(--cream);
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            overflow: hidden;
            user-select: none;
        }

        .avatar__image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: transform 0.2s ease;
        }

        .avatar__edit {
            position: absolute;
            right: 0;
            bottom: 0;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background-color: var(--mocha);
            color: var(--cream);
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid var(--foam);
            transition: transform 0.15s ease, background-color 0.2s ease;
            pointer-events: none;
        }

        .avatar__edit svg,
        .avatar__edit i {
            width: 15px;
            height: 15px;
            stroke: var(--cream);
            stroke-width: 2;
        }

        .avatar-label:hover .avatar__edit {
            transform: scale(1.1);
            background-color: var(--espresso);
        }

        .avatar-label:hover .avatar__image {
            transform: scale(1.05);
        }

        .avatar-remove {
            display: inline-block;
            font-size: 0.75rem;
            color: var(--dark-mocha);
            background: none;
            border: none;
            cursor: pointer;
            text-decoration: underline;
            padding: 0;
            margin: 0 0 0.75rem;
        }

        .avatar-remove:hover {
            color: var(--danger);
        }

        .avatar-upload-hint {
            max-width: 220px;
            margin: 0.65rem auto 0;
            color: var(--dark-mocha);
            font-size: 0.75rem;
            line-height: 1.4;
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

        .profile-role {
            display: inline-flex;
            align-items: center;
            margin-top: 0.75rem;
            padding: 0.25rem 0.65rem;
            border-radius: 999px;
            background: #ede9fe;
            color: #5b21b6;
            font-size: 0.75rem;
            font-weight: 700;
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

        .form__actions {
            display: flex;
            gap: 0.75rem;
        }

        .form__actions .btn {
            flex: 1;
        }

        .form__group {
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
        }

        .form__group label {
            font-size: 13px;
            font-weight: 500;
            color: var(--dark-mocha);
        }

        .form__group input {
            font-family: inherit;
            font-size: 14px;
            height: 38px;
            padding: 0 12px;
            border: 1px solid #e5dccf;
            border-radius: 6px;
            background-color: #fffdf9;
            color: var(--espresso);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .form__group input:focus {
            outline: none;
            border-color: var(--mocha);
            box-shadow: 0 0 0 3px rgba(59, 34, 24, 0.12);
        }

        .form__group input[readonly] {
            background-color: #f8f2e9;
            color: var(--dark-mocha);
            cursor: default;
        }

        .form__group input[readonly]:focus {
            border-color: #e5dccf;
            box-shadow: none;
        }

        .address-section {
            border-top: 1px solid var(--latte);
            margin-top: 1.5rem;
            padding-top: 1.5rem;
        }

        .address-section__title {
            font-size: 1rem;
            font-weight: 700;
            margin: 0 0 1rem;
        }

        .address-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            list-style: none;
            margin-bottom: 1.25rem;
            padding: 0;
        }

        .address-list__item {
            background: var(--cream);
            border: 1px solid var(--latte);
            border-radius: 0.75rem;
            padding: 0.9rem 1rem;
            line-height: 1.5;
        }

        .address-list__item strong {
            display: block;
        }

        .address-list__empty {
            color: var(--dark-mocha);
            font-size: 0.9rem;
            margin-bottom: 1rem;
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

        .btn--sm {
            padding: 0.55rem 0.95rem;
            font-size: 0.85rem;
            gap: 0.4rem;
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
                font-size: 1.5rem;
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

                <form
                    method="post"
                    action="customer_profile.php"
                    enctype="multipart/form-data"
                    class="avatar-form"
                    id="avatar-form"
                >
                    <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                    <input type="hidden" name="action" value="update_avatar">

                    <label for="avatar-input" class="avatar-label" title="Change profile picture">
                        <span class="avatar">
                            <?php if ($avatarUrl): ?>
                                <img src="<?= e($avatarUrl) ?>" alt="Profile picture" class="avatar__image">
                            <?php else: ?>
                                <?= e($initials) ?>
                            <?php endif; ?>
                        </span>
                        <span class="avatar__edit" aria-hidden="true">
                            <i data-lucide="camera"></i>
                        </span>
                    </label>

                    <input
                        type="file"
                        id="avatar-input"
                        name="avatar"
                        accept="image/jpeg,image/png,image/webp"
                        hidden
                        onchange="document.getElementById('avatar-form').submit();"
                    >
                    <p class="avatar-upload-hint">JPG, PNG, or WebP only. Maximum file size: 5 MB.</p>
                </form>

                <?php if ($avatarUrl): ?>
                    <form method="post" action="customer_profile.php" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                        <input type="hidden" name="action" value="remove_avatar">
                        <button type="submit" class="avatar-remove">Remove photo</button>
                    </form>
                <?php endif; ?>

                <h2><?= e($display_first_name . ' ' . $display_last_name) ?></h2>

                <p class="profile-summary__email"><?= e($display_email) ?></p>
                <span class="profile-role">Customer</span>

                <div class="profile-summary__actions">
                    <a href="../../login-signup/setup-authenticator.php" class="btn btn--light">
                        <i data-lucide="shield-check"></i>
                        <span>Set up authenticator</span>
                    </a>
                    <a href="../customer_orders/orders.php" class="btn btn--light">

                        <i data-lucide="receipt"></i>

                        <span>View orders</span>

                    </a>

                    <a href="../../login-signup/logout.php" class="btn btn--dark" data-cart-logout>

                        <i data-lucide="log-out"></i>

                        <span>Logout</span>

                    </a>

                </div>

            </aside>

            <div class="profile-forms">

                <section class="card">

                    <div class="card__header">

                        <h3 class="card__title">Personal information</h3>

                        <button
                            type="button"
                            class="btn btn--light btn--sm"
                            data-profile-edit
                        >

                            <i data-lucide="pencil"></i>

                            <span>Edit</span>

                        </button>

                    </div>

                    <form
                        method="post"
                        action="customer_profile.php"
                        class="form"
                        data-profile-form<?= $failedAction === 'update_profile' ? ' data-profile-editing="true"' : '' ?>
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
                                    readonly
                                    data-profile-field
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
                                    readonly
                                    data-profile-field
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
                                readonly
                                data-profile-field
                            >

                        </div>

                        <div class="form__actions" data-profile-actions hidden>

                            <button
                                type="button"
                                class="btn btn--light"
                                data-profile-cancel
                            >

                                <span>Cancel</span>

                            </button>

                            <button
                                type="submit"
                                class="btn btn--dark"
                            >

                                <i data-lucide="check"></i>

                                <span>Save changes</span>

                            </button>

                        </div>

                    </form>


                </section>

                <section class="card">

                    <h3 class="card__title">Your choices</h3>

                    <?php if ($choiceAnswers): ?>

                        <ul class="choice-summary">

                            <?php foreach (brewski_menu_preference_questions() as $questionKey => $question): ?>

                                <li>

                                    <span class="choice-summary__question">
                                        <?= e($question['question']) ?>
                                    </span>

                                    <span class="choice-summary__answer">
                                        <?php $answerLabel = $choiceAnswers[$questionKey] ?? 'Not answered'; ?>
                                        <?= e(is_array($answerLabel) ? implode(', ', $answerLabel) : $answerLabel) ?>
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
                        data-choice-mode="recommendation"
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

    <script>
        (function () {
            var form = document.querySelector('[data-profile-form]');

            if (!form) {
                return;
            }

            var editButton = document.querySelector('[data-profile-edit]');
            var actions = form.querySelector('[data-profile-actions]');
            var cancelButton = form.querySelector('[data-profile-cancel]');
            var fields = Array.prototype.slice.call(
                form.querySelectorAll('[data-profile-field]')
            );
            var initialValues = fields.map(function (field) {
                return field.value;
            });
            var editing = false;

            function setEditing(next) {
                editing = next;

                fields.forEach(function (field, index) {
                    if (next) {
                        field.removeAttribute('readonly');
                    } else {
                        field.setAttribute('readonly', 'readonly');
                        field.value = initialValues[index];
                    }
                });

                if (editButton) {
                    editButton.hidden = next;
                }

                if (actions) {
                    actions.hidden = !next;
                }

                if (next && fields.length) {
                    fields[0].focus();
                }
            }

            if (editButton) {
                editButton.addEventListener('click', function () {
                    setEditing(true);
                });
            }

            if (cancelButton) {
                cancelButton.addEventListener('click', function () {
                    setEditing(false);

                    if (editButton) {
                        editButton.focus();
                    }
                });
            }

            form.addEventListener('submit', function (event) {
                if (!editing) {
                    event.preventDefault();
                }
            });

            setEditing(form.getAttribute('data-profile-editing') === 'true');
        })();
    </script>

<?php require __DIR__ . '/../partials/choice-modal.php'; ?>

<?php require __DIR__ . '/../partials/footer.php'; ?>

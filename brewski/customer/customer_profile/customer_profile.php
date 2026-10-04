<?php

require __DIR__ . '/../partials/bootstrap.php';

$pageTitle = 'Profile | brewski';
$active = 'profile';
$extraStyles = ['customer_profile.css'];

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

require __DIR__ . '/../partials/header.php';

?>

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

<?php require __DIR__ . '/../partials/footer.php'; ?>

<?php
require_once __DIR__ . '/../../login-signup/session_init.php';

require_once __DIR__ . '/../../Db/db_config.php';
require_once __DIR__ . '/../../Db/activity_log.php';
require_once __DIR__ . '/../../Db/user_avatars.php';

if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$pdo = brewski_db_pdo();

if (!$pdo) {
    http_response_code(500);
    exit('Database connection failed.');
}

if (empty($_SESSION['user_id'])) {
    header('Location: ../customer-home/login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$avatarConnection = brewski_db_connection();
brewski_ensure_user_avatar_columns($avatarConnection);
$avatarConnection->close();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$success = '';

if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjaxRequest = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    if ($isAjaxRequest) {
        header('Content-Type: text/plain; charset=utf-8');
    }

    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        if ($isAjaxRequest) {
            echo 'Invalid request. Please refresh the page and try again.';
            exit;
        }
        $errors[] = 'Invalid request. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_avatar') {
            try {
                if (!isset($_FILES['avatar'])) {
                    throw new RuntimeException('Choose an image to upload.');
                }
                $avatar = brewski_read_avatar_upload($_FILES['avatar']);
                $update = $pdo->prepare('UPDATE users SET avatar_data = ?, avatar_mime_type = ?, updated_at = NOW() WHERE user_id = ?');
                $update->execute([$avatar['data'], $avatar['mime'], $userId]);
                echo 'ok';
            } catch (Throwable $exception) {
                echo $exception->getMessage();
            }
            exit;
        }

        if ($action === 'update_profile') {
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $lastName = trim((string) ($_POST['last_name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));

            if ($firstName === '' || mb_strlen($firstName) > 50) {
                $errors[] = 'Please enter a valid first name.';
            }

            if ($lastName === '' || mb_strlen($lastName) > 50) {
                $errors[] = 'Please enter a valid last name.';
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
                $errors[] = 'Please enter a valid email address.';
            }

            if (!$errors) {
                $check = $pdo->prepare('SELECT user_id FROM users WHERE email = ? AND user_id <> ?');
                $check->execute([$email, $userId]);

                if ($check->fetch()) {
                    $errors[] = 'That email is already used by another account.';
                }
            }

            if (!$errors) {
                $update = $pdo->prepare('UPDATE users SET first_name = ?, last_name = ?, email = ? WHERE user_id = ?');
                $update->execute([$firstName, $lastName, $email, $userId]);
                $_SESSION['first_name'] = $firstName;
                $_SESSION['last_name'] = $lastName;
                $_SESSION['email'] = $email;
                brewski_record_activity(
                    $pdo,
                    'profile',
                    'Updated profile',
                    'Updated account profile details.'
                );
                if ($isAjaxRequest) {
                    echo 'ok';
                    exit;
                }
                $_SESSION['flash_success'] = 'Your profile has been updated.';
                header('Location: profile.php');
                exit;
            }

            if ($isAjaxRequest) {
                echo $errors[0];
                exit;
            }
        }

        if ($action === 'change_password') {
            $current = (string) ($_POST['current_password'] ?? '');
            $new = (string) ($_POST['new_password'] ?? '');
            $confirm = (string) ($_POST['confirm_password'] ?? '');

            $row = $pdo->prepare('SELECT password FROM users WHERE user_id = ?');
            $row->execute([$userId]);
            $hash = $row->fetchColumn();

            if (!$hash || !password_verify($current, $hash)) {
                $errors[] = 'Your current password is incorrect.';
            }

            if (strlen($new) < 8) {
                $errors[] = 'The new password must be at least 8 characters.';
            }

            if (strlen($new) > 72) {
                $errors[] = 'The new password must not be longer than 72 characters.';
            }

            if ($new !== $confirm) {
                $errors[] = 'The new passwords do not match.';
            }

            if (!$errors && $new === $current) {
                $errors[] = 'The new password must be different from your current password.';
            }

            if (!$errors) {
                $update = $pdo->prepare('UPDATE users SET password = ? WHERE user_id = ?');
                $update->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
                session_regenerate_id(true);
                brewski_record_activity(
                    $pdo,
                    'security',
                    'Changed password',
                    'Changed the account password.'
                );
                if ($isAjaxRequest) {
                    echo 'ok';
                    exit;
                }
                $_SESSION['flash_success'] = 'Your password has been changed.';
                header('Location: profile.php');
                exit;
            }

            if ($isAjaxRequest) {
                echo $errors[0];
                exit;
            }
        }
    }
}

$stmt = $pdo->prepare('SELECT user_id, first_name, last_name, email, role, totp_enabled_at, avatar_mime_type FROM users WHERE user_id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    session_unset();
    session_destroy();
    header('Location: ../customer-home/login.php');
    exit;
}

$display_first_name = e($user['first_name']);
$display_last_name = e($user['last_name']);
$display_email = e($user['email']);
$hasAuthenticator = !empty($user['totp_enabled_at']);
$avatarUrl = !empty($user['avatar_mime_type']) ? '../user_avatar.php?id=' . $userId : '';

$initials = strtoupper(
    mb_substr($user['first_name'], 0, 1) .
    mb_substr($user['last_name'], 0, 1)
);

$cartCount = 0;
if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cartCount += is_array($item) ? (int) ($item['quantity'] ?? 1) : (int) $item;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Brewski</title>
    <link rel="icon" type="image/png" href="<?= e(BREWSKI_BASE_URL . '/images/brewskilogo.png') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Questrial&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../admin/profile/profile.css">
    <link rel="stylesheet" href="profile.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="customer-profile">
    <header class="navbar">
        <a href="../customer-home/customerhome.php" class="navbar__brand">brewski</a>

        <nav class="navbar__links">
            <a href="../customer-home/customerhome.php" class="nav-link">
                <i data-lucide="house"></i>
                <span>Home</span>
            </a>
            <a href="../customer-home/customermenu.php" class="nav-link">
                <i data-lucide="coffee"></i>
                <span>Menu</span>
            </a>
            <a href="../customer-home/cart.php" class="nav-link nav-link--cart">
                <i data-lucide="shopping-cart"></i>
                <span>Cart</span>
                <span class="cart-badge"><?= e($cartCount) ?></span>
            </a>
            <a href="../customer-home/orders.php" class="nav-link">
                <i data-lucide="receipt"></i>
                <span>Orders</span>
            </a>

            <div class="profile-menu" id="profileMenu">
                <button type="button" class="nav-link profile-button active" id="profileButton" aria-haspopup="true" aria-expanded="false">
                    <i data-lucide="user"></i>
                    <span>Profile</span>
                    <i data-lucide="chevron-down" class="profile-chevron"></i>
                </button>

                <div class="profile-dropdown" id="profile-dropdown">
                    <div class="profile-dropdown__user">
                        <strong><?= $display_first_name . ' ' . $display_last_name ?></strong>
                        <span><?= $display_email ?></span>
                    </div>

                    <div class="profile-dropdown__divider"></div>

                    <a href="profile.php" class="logout-button">
                        <i data-lucide="user-round"></i>
                        My Profile
                    </a>

                    <a href="../../login-signup/logout.php" class="logout-button" data-cart-logout>
                        <i data-lucide="log-out"></i>
                        Logout
                    </a>
                </div>
            </div>
        </nav>
    </header>

    <main class="profile-page">
        <section class="page-header">
            <div>
            <h1 class="page-title">My Profile</h1>
            <p class="subtitle">Manage your account details and keep your Brewski account secure.</p>
            </div>
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

        <div class="profile-layout">
            <aside class="profile-card profile-summary">
                <form class="profile-avatar-form" method="post" action="profile.php" enctype="multipart/form-data" data-avatar-form data-avatar-url="../user_avatar.php?id=<?= e($userId) ?>">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="update_avatar">
                    <label class="profile-avatar-label" for="customer-avatar-input">
                        <span class="profile-avatar">
                            <img class="profile-avatar__image" data-profile-avatar-image src="<?= e($avatarUrl) ?>" alt="Profile picture"<?= $avatarUrl === '' ? ' hidden' : '' ?>>
                            <span data-profile-initials<?= $avatarUrl !== '' ? ' hidden' : '' ?>><?= e($initials) ?></span>
                        </span>
                        <span class="profile-avatar-label__text" aria-hidden="true">Change photo</span>
                    </label>
                    <input id="customer-avatar-input" name="avatar" type="file" accept="image/jpeg,image/png,image/webp" hidden>
                    <p class="profile-avatar-hint">JPG, PNG, or WebP only. Maximum file size: 5 MB.</p>
                    <p class="profile-status" data-avatar-status role="status" aria-live="polite"></p>
                </form>
                <h2 class="profile-summary__name" data-profile-name><?= $display_first_name . ' ' . $display_last_name ?></h2>
                <p class="profile-summary__email" data-profile-email><?= $display_email ?></p>
                <span class="badge badge-role-customer">Customer</span>

                <div class="profile-summary__actions">
                    <a href="../../login-signup/setup-authenticator.php" class="btn btn-secondary">
                        <span><?= $hasAuthenticator ? 'Reset authenticator' : 'Set up authenticator' ?></span>
                    </a>
                    <a href="../../login-signup/logout.php" class="btn btn-secondary" data-cart-logout>
                        <i data-lucide="log-out"></i>
                        <span>Log out</span>
                    </a>
                </div>
            </aside>

            <div class="profile-forms">
                <section class="profile-card">
                    <div class="profile-card__header">
                        <h3 class="profile-card__title">Personal information</h3>
                        <button type="button" class="btn btn-secondary" data-profile-edit>Edit</button>
                    </div>

                    <form method="post" action="profile.php" class="form-grid" data-profile-form novalidate>
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="update_profile">

                        <div class="form-field">
                            <label for="first_name">First name</label>
                            <input type="text" id="first_name" name="first_name" value="<?= $display_first_name ?>" required maxlength="50" autocomplete="given-name" readonly data-profile-field>
                        </div>

                        <div class="form-field">
                            <label for="last_name">Last name</label>
                            <input type="text" id="last_name" name="last_name" value="<?= $display_last_name ?>" required maxlength="50" autocomplete="family-name" readonly data-profile-field>
                        </div>

                        <div class="form-field form-field-full">
                            <label for="email">Email address</label>
                            <input type="email" id="email" name="email" value="<?= $display_email ?>" required autocomplete="email" readonly data-profile-field>
                        </div>

                        <div class="form-field-full profile-actions" data-profile-actions hidden>
                            <button type="button" class="btn btn-secondary" data-profile-cancel>Cancel</button>
                            <button type="submit" class="btn btn-primary">Save changes</button>
                        </div>
                    </form>
                    <p class="profile-status" data-profile-status role="status" aria-live="polite"></p>
                </section>

                <section class="profile-card">
                    <h3 class="profile-card__title">Change password</h3>

                    <form method="post" action="profile.php" class="form-grid" data-password-form novalidate>
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="change_password">

                        <div class="form-field form-field-full">
                            <label for="current_password">Current password</label>
                            <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                        </div>

                        <div class="form-field">
                            <label for="new_password">New password</label>
                            <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
                        </div>

                        <div class="form-field">
                            <label for="confirm_password">Confirm new password</label>
                            <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
                        </div>

                        <div class="form-field-full">
                            <p class="profile-hint">Use 8 to 72 characters and choose a password you have not used before.</p>
                        </div>

                        <div class="form-field-full profile-actions">
                            <button type="submit" class="btn btn-primary">
                                <i data-lucide="lock"></i>
                                <span>Update password</span>
                            </button>
                        </div>
                    </form>
                    <p class="profile-status" data-password-status role="status" aria-live="polite"></p>
                </section>
            </div>
        </div>
    </main>

    <script>
        lucide.createIcons();

        (function () {
            function setStatus(element, text, modifier) {
                if (!element) return;
                element.textContent = text;
                element.classList.remove('is-saved', 'is-error');
                if (modifier) element.classList.add(modifier);
            }

            function postForm(form, status, onSuccess) {
                const submit = form.querySelector('button[type="submit"]');
                if (submit) submit.disabled = true;
                fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(response => response.text())
                    .then(text => text.trim() === 'ok'
                        ? onSuccess()
                        : setStatus(status, text.trim() || 'Could not save. Please try again.', 'is-error'))
                    .catch(() => setStatus(status, 'Could not save. Please try again.', 'is-error'))
                    .finally(() => { if (submit) submit.disabled = false; });
            }

            const avatarForm = document.querySelector('[data-avatar-form]');
            if (avatarForm) {
                const input = avatarForm.querySelector('input[type="file"]');
                const status = avatarForm.querySelector('[data-avatar-status]');
                input.addEventListener('change', function () {
                    if (!input.files.length) return;
                    setStatus(status, 'Uploading photo...', '');
                    postForm(avatarForm, status, function () {
                        const image = document.querySelector('[data-profile-avatar-image]');
                        const initials = document.querySelector('[data-profile-initials]');
                        if (image) {
                            image.src = avatarForm.dataset.avatarUrl + '&v=' + Date.now();
                            image.hidden = false;
                        }
                        if (initials) initials.hidden = true;
                        input.value = '';
                        setStatus(status, 'Your profile picture has been updated.', 'is-saved');
                    });
                });
            }

            const profileForm = document.querySelector('[data-profile-form]');
            if (profileForm) {
                const status = document.querySelector('[data-profile-status]');
                const actions = document.querySelector('[data-profile-actions]');
                const edit = document.querySelector('[data-profile-edit]');
                const cancel = document.querySelector('[data-profile-cancel]');
                const fields = Array.from(profileForm.querySelectorAll('[data-profile-field]'));
                let originals = fields.map(field => field.value);

                function setEditing(editing) {
                    fields.forEach((field, index) => {
                        if (editing) field.removeAttribute('readonly');
                        else {
                            field.setAttribute('readonly', 'readonly');
                            field.value = originals[index];
                        }
                    });
                    edit.hidden = editing;
                    actions.hidden = !editing;
                    if (editing && fields.length) fields[0].focus();
                }

                edit.addEventListener('click', function () {
                    setStatus(status, '', '');
                    setEditing(true);
                });
                cancel.addEventListener('click', function () {
                    setEditing(false);
                    setStatus(status, '', '');
                    edit.focus();
                });
                profileForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                    setStatus(status, 'Saving...', '');
                    postForm(profileForm, status, function () {
                        originals = fields.map(field => field.value);
                        document.querySelector('[data-profile-name]').textContent =
                            (fields[0].value.trim() + ' ' + fields[1].value.trim()).trim();
                        document.querySelector('[data-profile-email]').textContent = fields[2].value.trim();
                        const initials = document.querySelector('[data-profile-initials]');
                        if (initials) initials.textContent =
                            (fields[0].value.trim().charAt(0) + fields[1].value.trim().charAt(0)).toUpperCase();
                        setEditing(false);
                        setStatus(status, 'Your profile has been updated.', 'is-saved');
                    });
                });
            }

            const passwordForm = document.querySelector('[data-password-form]');
            if (passwordForm) {
                const status = document.querySelector('[data-password-status]');
                passwordForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                    setStatus(status, 'Updating...', '');
                    postForm(passwordForm, status, function () {
                        passwordForm.reset();
                        setStatus(status, 'Your password has been updated.', 'is-saved');
                    });
                });
            }
        })();

        const profileMenu = document.getElementById('profileMenu');
        const profileButton = document.getElementById('profileButton');

        profileButton.addEventListener('click', function (event) {
            event.stopPropagation();
            const isOpen = profileMenu.classList.toggle('open');
            profileButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        document.addEventListener('click', function (event) {
            if (!profileMenu.contains(event.target)) {
                profileMenu.classList.remove('open');
                profileButton.setAttribute('aria-expanded', 'false');
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                profileMenu.classList.remove('open');
                profileButton.setAttribute('aria-expanded', 'false');
            }
        });
    </script>
<script>
document.querySelectorAll('[data-cart-logout]').forEach(function (link) {
    link.addEventListener('click', function () {
        try {
            sessionStorage.removeItem('brewski_cart');
            sessionStorage.removeItem('brewski_checkout_sync_complete');
        } catch (error) {
            console.warn('Could not clear the cart during logout:', error);
        }
    });
});
</script>
</body>
</html>

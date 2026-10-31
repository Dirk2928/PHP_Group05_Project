<?php

require_once __DIR__ . '/../../login-signup/session_init.php';
require_once __DIR__ . '/../../Db/connection.php';
require_once __DIR__ . '/../../Db/activity_log.php';
require_once __DIR__ . '/../../Db/user_avatars.php';

brewski_require_role(['ADMIN']);

$userId = (int) ($_SESSION['user_id'] ?? 0);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = (string) $_SESSION['csrf_token'];
$endpoint = '../profile/profile.php';

$avatarConnection = brewski_db_connection();
brewski_ensure_user_avatar_columns($avatarConnection);
$avatarConnection->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    header('Content-Type: text/plain; charset=utf-8');

    if (!brewski_is_logged_in() || brewski_current_role() !== 'ADMIN') {
        echo 'Your session expired. Reload the page and sign in again.';
        exit;
    }

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        empty($_SESSION['csrf_token'])
        || !is_string($submittedToken)
        || !hash_equals((string) $_SESSION['csrf_token'], $submittedToken)
    ) {
        echo 'Your session expired. Reload the page and sign in again.';
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    $errors = [];

    if ($action === 'update_avatar') {
        if (!isset($_FILES['avatar'])) {
            echo 'Choose an image to upload.';
            exit;
        }

        try {
            $avatar = brewski_read_avatar_upload($_FILES['avatar']);
            $update = $pdo->prepare(
                'UPDATE users SET avatar_data = ?, avatar_mime_type = ?, updated_at = NOW() WHERE user_id = ?'
            );
            $update->execute([brewski_personal_encrypt($avatar['data'], 'avatar_data'), $avatar['mime'], $userId]);
            echo 'ok';
        } catch (Throwable $exception) {
            echo $exception->getMessage();
        }
        exit;
    }

    if ($action === 'update_profile') {

        $firstNameInput = trim((string) ($_POST['first_name'] ?? ''));
        $lastNameInput  = trim((string) ($_POST['last_name'] ?? ''));
        $emailInput     = trim((string) ($_POST['email'] ?? ''));

        if ($firstNameInput === '' || mb_strlen($firstNameInput) > 50) {
            $errors[] = 'Enter a first name no longer than 50 characters.';
        } elseif (!preg_match("/^[A-Za-zÀ-ÿ\s'\-]+$/u", $firstNameInput)) {
            $errors[] = 'A first name may only contain letters, spaces, hyphens, and apostrophes.';
        }

        if ($lastNameInput === '' || mb_strlen($lastNameInput) > 50) {
            $errors[] = 'Enter a last name no longer than 50 characters.';
        } elseif (!preg_match("/^[A-Za-zÀ-ÿ\s'\-]+$/u", $lastNameInput)) {
            $errors[] = 'A last name may only contain letters, spaces, hyphens, and apostrophes.';
        }

        if (
            $emailInput === ''
            || mb_strlen($emailInput) > 255
            || !filter_var($emailInput, FILTER_VALIDATE_EMAIL)
        ) {
            $errors[] = 'Enter a valid email address.';
        }

        if (!$errors) {

            $check = $pdo->prepare(
                'SELECT user_id FROM users WHERE ' . brewski_email_lookup_column() . ' = ? AND user_id <> ? LIMIT 1'
            );
            $check->execute([brewski_email_lookup($emailInput), $userId]);

            if ($check->fetch()) {
                $errors[] = 'That email is already used by another account.';
            }
        }

        if ($errors) {
            echo $errors[0];
            exit;
        }

        try {
            brewski_update_user_identity($pdo, $userId, $firstNameInput, $lastNameInput, $emailInput);
        } catch (Throwable $exception) {
            error_log('Admin profile update failed: ' . $exception->getMessage());
            http_response_code(500);
            echo 'Your profile could not be saved. Please try again.';
            exit;
        }

        $_SESSION['first_name'] = $firstNameInput;
        $_SESSION['last_name']  = $lastNameInput;
        $_SESSION['email']      = $emailInput;
        brewski_record_activity($pdo, 'profile', 'Updated profile', 'Updated account profile details.');

        echo 'ok';
        exit;
    }

    if ($action === 'change_password') {

        $currentInput = (string) ($_POST['current_password'] ?? '');
        $newInput     = (string) ($_POST['new_password'] ?? '');
        $confirmInput = (string) ($_POST['confirm_password'] ?? '');

        $row = $pdo->prepare('SELECT password FROM users WHERE user_id = ?');
        $row->execute([$userId]);
        $storedHash = $row->fetchColumn();

        if (!$storedHash || !password_verify($currentInput, $storedHash)) {
            $errors[] = 'Your current password is incorrect.';
        }

        if ($newInput === '') {
            $errors[] = 'Enter a new password.';
        } elseif (strlen($newInput) > 72) {
            $errors[] = 'New password must be 72 characters or fewer.';
        } else {
            foreach (brewski_password_failures($newInput) as $failure) {
                $errors[] = $failure;
            }
        }

        if ($newInput !== $confirmInput) {
            $errors[] = 'The new passwords do not match.';
        }

        if (!$errors && $newInput === $currentInput) {
            $errors[] = 'The new password must be different from your current password.';
        }

        if ($errors) {
            echo $errors[0];
            exit;
        }

        try {
            $update = $pdo->prepare('UPDATE users SET password = ? WHERE user_id = ?');
            $update->execute([password_hash($newInput, PASSWORD_DEFAULT), $userId]);
        } catch (Throwable $exception) {
            error_log('Admin password update failed: ' . $exception->getMessage());
            http_response_code(500);
            echo 'Your password could not be updated. Please try again.';
            exit;
        }
        brewski_record_activity($pdo, 'security', 'Changed password', 'Changed account password.');

        session_regenerate_id(true);

        echo 'ok';
        exit;
    }

    echo 'That request could not be processed. Reload the page and try again.';
    exit;
}

$statement = $pdo->prepare(
    'SELECT first_name, last_name, email, role, job_title, totp_enabled_at,
            avatar_mime_type, updated_at
     FROM users
     WHERE user_id = ?
     LIMIT 1'
);
$statement->execute([$userId]);
$user = $statement->fetch();

if (!$user) {
    brewski_end_session();
}
$user = brewski_decrypt_user_fields($user);

$firstName = (string) $user['first_name'];
$lastName  = (string) $user['last_name'];
$email     = (string) $user['email'];
$role      = strtoupper(trim((string) $user['role']));
$jobTitle  = trim((string) ($user['job_title'] ?? ''));
$hasAuthenticator = !empty($user['totp_enabled_at']);
$avatarUrl = !empty($user['avatar_mime_type'])
    ? BREWSKI_BASE_URL . '/customer/user_avatar.php?id=' . $userId . '&as=admin'
    : '';

$initials = strtoupper(
    mb_substr($firstName, 0, 1) . mb_substr($lastName, 0, 1)
);

if ($role === 'ADMIN') {
    $roleLabel = 'Administrator';
} elseif ($role === 'STAFF') {
    $roleLabel = 'Staff';
} else {
    $roleLabel = 'Customer';
}

$roleBadge = strtolower($role);
$passwordHint = brewski_password_policy_hint();

$escape = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};

?>

<link
    rel="stylesheet"
    href="<?= $escape(BREWSKI_BASE_URL . '/admin/profile/profile.css') ?>"
>

<section class="page-container profile-page">

    <div class="page-header">
        <div>
            <h1 class="page-title">My Profile</h1>
            <p class="subtitle">Manage your account details and keep your Brewski account secure.</p>
        </div>
    </div>

    <div class="profile-layout">

        <aside class="profile-card profile-summary">

            <form
                class="profile-avatar-form"
                method="post"
                action="<?= $escape($endpoint) ?>"
                enctype="multipart/form-data"
                data-avatar-form
                data-avatar-url="<?= $escape(BREWSKI_BASE_URL . '/customer/user_avatar.php?id=' . $userId . '&as=admin') ?>"
            >
                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                <input type="hidden" name="action" value="update_avatar">
                <label class="profile-avatar-label" for="admin-avatar-input">
                    <span class="profile-avatar">
                        <img class="profile-avatar__image" data-profile-avatar-image src="<?= $escape($avatarUrl) ?>" alt="Profile picture"<?= $avatarUrl === '' ? ' hidden' : '' ?>>
                        <span data-profile-initials<?= $avatarUrl !== '' ? ' hidden' : '' ?>><?= $escape($initials) ?></span>
                    </span>
                    <span class="profile-avatar-label__text" aria-hidden="true">Change photo</span>
                </label>
                <input id="admin-avatar-input" name="avatar" type="file" accept="image/jpeg,image/png,image/webp" hidden>
                <p class="profile-avatar-hint">JPG, PNG, or WebP only. Maximum file size: 5 MB.</p>
                <p class="profile-status" data-avatar-status role="status" aria-live="polite"></p>
            </form>

            <h2 class="profile-summary__name" data-profile-name>
                <?= $escape(trim($firstName . ' ' . $lastName)) ?>
            </h2>

            <p class="profile-summary__email" data-profile-email><?= $escape($email) ?></p>

            <span class="badge badge-role-<?= $escape($roleBadge) ?>"><?= $escape($roleLabel) ?></span>

            <?php if ($jobTitle !== ''): ?>

                <p class="profile-summary__role"><?= $escape($jobTitle) ?></p>

            <?php endif; ?>

            <div class="profile-summary__actions">

                <a
                    class="btn btn-secondary"
                    href="<?= $escape(brewski_auth_step_url('portal', $hasAuthenticator ? 'reset-authenticator.php' : 'setup-authenticator.php')) ?>"
                ><?= $hasAuthenticator ? 'Reset authenticator' : 'Set up authenticator' ?></a>

                <a
                    class="btn btn-secondary"
                    href="<?= $escape(brewski_logout_url()) ?>"
                >Log out</a>

            </div>

        </aside>

        <div class="profile-forms">

            <section class="profile-card">

                <div class="profile-card__header">

                    <h3 class="profile-card__title">Personal information</h3>

                    <button
                        type="button"
                        class="btn btn-secondary"
                        data-profile-edit
                    >Edit</button>

                </div>

                <form
                    class="form-grid"
                    method="POST"
                    action="<?= $escape($endpoint) ?>"
                    data-profile-form
                    novalidate
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= $escape($csrfToken) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="update_profile"
                    >

                    <div class="form-field">
                        <label for="profileFirstName">First name</label>

                        <input
                            type="text"
                            id="profileFirstName"
                            name="first_name"
                            value="<?= $escape($firstName) ?>"
                            maxlength="50"
                            autocomplete="given-name"
                            required
                            readonly
                            data-profile-field
                        >
                    </div>

                    <div class="form-field">
                        <label for="profileLastName">Last name</label>

                        <input
                            type="text"
                            id="profileLastName"
                            name="last_name"
                            value="<?= $escape($lastName) ?>"
                            maxlength="50"
                            autocomplete="family-name"
                            required
                            readonly
                            data-profile-field
                        >
                    </div>

                    <div class="form-field form-field-full">
                        <label for="profileEmail">Email address</label>

                        <input
                            type="email"
                            id="profileEmail"
                            name="email"
                            value="<?= $escape($email) ?>"
                            maxlength="255"
                            autocomplete="email"
                            required
                            readonly
                            data-profile-field
                        >
                    </div>

                    <div class="form-field-full profile-actions" data-profile-actions hidden>

                        <button
                            type="button"
                            class="btn btn-secondary"
                            data-profile-cancel
                        >Cancel</button>

                        <button type="submit" class="btn btn-primary">Save changes</button>

                    </div>

                </form>

                <p
                    class="profile-status"
                    data-profile-status
                    role="status"
                    aria-live="polite"
                ></p>

            </section>

            <section class="profile-card">

                <h3 class="profile-card__title">Change password</h3>

                <form
                    class="form-grid"
                    method="POST"
                    action="<?= $escape($endpoint) ?>"
                    data-password-form
                    novalidate
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= $escape($csrfToken) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="change_password"
                    >

                    <div class="form-field form-field-full">
                        <label for="profileCurrentPassword">Current password</label>

                        <input
                            type="password"
                            id="profileCurrentPassword"
                            name="current_password"
                            autocomplete="current-password"
                            required
                        >
                    </div>

                    <div class="form-field">
                        <label for="profileNewPassword">New password</label>

                        <input
                            type="password"
                            id="profileNewPassword"
                            name="new_password"
                            maxlength="72"
                            autocomplete="new-password"
                            required
                        >
                    </div>

                    <div class="form-field">
                        <label for="profileConfirmPassword">Confirm new password</label>

                        <input
                            type="password"
                            id="profileConfirmPassword"
                            name="confirm_password"
                            maxlength="72"
                            autocomplete="new-password"
                            required
                        >
                    </div>

                    <div class="form-field form-field-full">
                        <p class="profile-hint"><?= $escape($passwordHint) ?></p>
                    </div>

                    <div class="form-field-full profile-actions">
                        <button type="submit" class="btn btn-primary">Update password</button>
                    </div>

                </form>

                <p
                    class="profile-status"
                    data-password-status
                    role="status"
                    aria-live="polite"
                ></p>

            </section>

        </div>

    </div>

</section>

<script>
(function () {
    function setStatus(element, text, modifier) {
        if (!element) {
            return;
        }

        element.textContent = text;
        element.classList.remove('is-saved');
        element.classList.remove('is-error');

        if (modifier) {
            element.classList.add(modifier);
        }
    }

    function postForm(form, status, onSuccess) {
        var submit = form.querySelector('button[type="submit"]');

        if (submit) {
            submit.disabled = true;
        }

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin'
        })
            .then(function (response) {
                if (response.redirected) {
                    window.location.assign(response.url);
                    throw new Error('redirected');
                }
                return response.text().then(function (text) {
                    if (!response.ok) {
                        var message = text.trim();
                        throw new Error(message && message.charAt(0) !== '<'
                            ? message.slice(0, 200) : 'Could not save. Please try again.');
                    }
                    return text;
                });
            })
            .then(function (text) {
                var message = text.trim();

                if (message === 'ok') {
                    onSuccess();
                } else {
                    setStatus(status, message || 'Could not save. Please try again.', 'is-error');
                }
            })
            .catch(function (error) {
                if (error.message === 'redirected') {
                    return;
                }
                setStatus(status, error.message || 'Could not save. Please try again.', 'is-error');
            })
            .finally(function () {
                if (submit) {
                    submit.disabled = false;
                }
            });
    }

    function refreshHeaderIdentity(first, last, email) {
        var summary = document.querySelector('#profileMenu .profile-user-summary');
        var name = summary && summary.querySelector('strong');
        var address = summary && summary.querySelector('.profile-summary-copy span');
        var initials = (first.charAt(0) + last.charAt(0)).toUpperCase();
        if (name) name.textContent = (first + ' ' + last).trim();
        if (address) address.textContent = email;
        document.querySelectorAll('.profile-btn-initials, .profile-summary-initials').forEach(function (node) {
            node.textContent = initials;
        });
    }

    function refreshHeaderAvatar(url) {
        [['#profileBtn', 'profile-btn-avatar', '.profile-btn-initials'],
            ['#profileMenu .profile-user-summary', 'profile-summary-avatar', '.profile-summary-initials']]
            .forEach(function (item) {
                var parent = document.querySelector(item[0]);
                if (!parent) return;
                var image = parent.querySelector('img');
                if (!image) {
                    image = document.createElement('img');
                    image.className = item[1];
                    image.alt = '';
                    var initials = parent.querySelector(item[2]);
                    if (initials) initials.replaceWith(image);
                    else parent.prepend(image);
                }
                image.src = url;
            });
    }

    var profileForm = document.querySelector('[data-profile-form]');

    var avatarForm = document.querySelector('[data-avatar-form]');

    if (avatarForm) {
        var avatarInput = avatarForm.querySelector('input[type="file"]');
        var avatarStatus = avatarForm.querySelector('[data-avatar-status]');

        avatarInput.addEventListener('change', function () {
            if (!avatarInput.files.length) {
                return;
            }

            if (avatarInput.files[0].size > 5 * 1024 * 1024) {
                setStatus(avatarStatus, 'Choose an image no larger than 5 MB.', 'is-error');
                avatarInput.value = '';
                return;
            }

            setStatus(avatarStatus, 'Uploading photo...', '');
            postForm(avatarForm, avatarStatus, function () {
                var image = document.querySelector('[data-profile-avatar-image]');
                var initials = document.querySelector('[data-profile-initials]');

                if (image) {
                    image.src = avatarForm.dataset.avatarUrl + '&v=' + Date.now();
                    refreshHeaderAvatar(image.src);
                    image.hidden = false;
                }

                if (initials) {
                    initials.hidden = true;
                }

                avatarInput.value = '';
                setStatus(avatarStatus, 'Your profile picture has been updated.', 'is-saved');
            });
        });
    }

    if (profileForm) {
        var profileStatus = document.querySelector('[data-profile-status]');
        var profileActions = document.querySelector('[data-profile-actions]');
        var editButton = document.querySelector('[data-profile-edit]');
        var cancelButton = document.querySelector('[data-profile-cancel]');
        var fields = Array.prototype.slice.call(
            profileForm.querySelectorAll('[data-profile-field]')
        );
        var originals = fields.map(function (field) {
            return field.value;
        });
        var editing = false;

        var setEditing = function (next) {
            editing = next;

            fields.forEach(function (field, index) {
                if (next) {
                    field.removeAttribute('readonly');
                } else {
                    field.setAttribute('readonly', 'readonly');
                    field.value = originals[index];
                }
            });

            if (editButton) {
                editButton.hidden = next;
            }

            if (profileActions) {
                profileActions.hidden = !next;
            }

            if (next && fields.length) {
                fields[0].focus();
            }
        };

        var refreshSummary = function () {
            var nameElement = document.querySelector('[data-profile-name]');
            var emailElement = document.querySelector('[data-profile-email]');
            var initialsElement = document.querySelector('[data-profile-initials]');
            var first = fields[0] ? fields[0].value.trim() : '';
            var last = fields[1] ? fields[1].value.trim() : '';

            if (nameElement) {
                nameElement.textContent = (first + ' ' + last).trim();
            }

            if (emailElement && fields[2]) {
                emailElement.textContent = fields[2].value.trim();
            }

            if (initialsElement) {
                initialsElement.textContent = (
                    first.charAt(0) + last.charAt(0)
                ).toUpperCase();
            }
            refreshHeaderIdentity(first, last, fields[2] ? fields[2].value.trim() : '');
        };

        if (editButton) {
            editButton.addEventListener('click', function () {
                setStatus(profileStatus, '', '');
                setEditing(true);
            });
        }

        if (cancelButton) {
            cancelButton.addEventListener('click', function () {
                setEditing(false);
                setStatus(profileStatus, '', '');

                if (editButton) {
                    editButton.focus();
                }
            });
        }

        profileForm.addEventListener('submit', function (event) {
            event.preventDefault();

            if (!editing || !profileForm.reportValidity()) {
                return;
            }

            setStatus(profileStatus, 'Saving...', '');

            postForm(profileForm, profileStatus, function () {
                originals = fields.map(function (field) {
                    return field.value;
                });

                refreshSummary();
                setEditing(false);
                setStatus(profileStatus, 'Your profile has been updated.', 'is-saved');
            });
        });
    }

    var passwordForm = document.querySelector('[data-password-form]');

    if (passwordForm) {
        var passwordStatus = document.querySelector('[data-password-status]');

        passwordForm.addEventListener('submit', function (event) {
            event.preventDefault();

            if (!passwordForm.reportValidity()) return;

            setStatus(passwordStatus, 'Updating...', '');

            postForm(passwordForm, passwordStatus, function () {
                passwordForm.reset();
                setStatus(passwordStatus, 'Your password has been updated.', 'is-saved');
            });
        });
    }
})();
</script>
<?php echo brewski_session_timeout_markup(); ?>

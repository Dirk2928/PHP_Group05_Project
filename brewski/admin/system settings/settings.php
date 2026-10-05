<?php

require_once __DIR__ . '/../../login-signup/session_init.php';
require_once __DIR__ . '/../../login-signup/settings.php';

$settings_endpoint = BREWSKI_BASE_URL . '/admin/system%20settings/settings.php';

$password_bounds = brewski_password_length_bounds();
$timeout_bounds = brewski_session_timeout_bounds();

$idle_min_minutes = (int) ($timeout_bounds['idle_min'] / 60);
$idle_max_minutes = (int) ($timeout_bounds['idle_max'] / 60);
$absolute_min_hours = (int) ($timeout_bounds['absolute_min'] / 3600);
$absolute_max_hours = (int) ($timeout_bounds['absolute_max'] / 3600);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    header('Content-Type: text/plain; charset=utf-8');

    if (!brewski_is_logged_in() || brewski_current_role() !== 'ADMIN') {
        echo 'Your session expired. Reload the page and sign in again.';
        exit;
    }

    $min_password_length = filter_var(
        $_POST['min_password_length'] ?? '',
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => $password_bounds['min'],
                'max_range' => $password_bounds['max']
            ]
        ]
    );

    $idle_minutes = filter_var(
        $_POST['session_idle_timeout_minutes'] ?? '',
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => $idle_min_minutes,
                'max_range' => $idle_max_minutes
            ]
        ]
    );

    $absolute_hours = filter_var(
        $_POST['session_absolute_timeout_hours'] ?? '',
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => $absolute_min_hours,
                'max_range' => $absolute_max_hours
            ]
        ]
    );

    if ($min_password_length === false) {
        echo 'Password length must be a whole number between '
            . $password_bounds['min']
            . ' and '
            . $password_bounds['max']
            . '.';
        exit;
    }

    if ($idle_minutes === false) {
        echo 'Idle timeout must be a whole number of minutes between '
            . $idle_min_minutes
            . ' and '
            . $idle_max_minutes
            . '.';
        exit;
    }

    if ($absolute_hours === false) {
        echo 'Session lifetime must be a whole number of hours between '
            . $absolute_min_hours
            . ' and '
            . $absolute_max_hours
            . '.';
        exit;
    }

    $idle_seconds = $idle_minutes * 60;
    $absolute_seconds = $absolute_hours * 3600;

    if ($absolute_seconds <= $idle_seconds) {
        echo 'Session lifetime must be longer than the idle timeout.';
        exit;
    }

    $pending = [
        'min_password_length' => (string) $min_password_length,
        'session_idle_timeout' => (string) $idle_seconds,
        'session_absolute_timeout' => (string) $absolute_seconds
    ];

    $saved = true;

    foreach ($pending as $setting_key => $setting_value) {
        if (!brewski_setting_set($setting_key, $setting_value)) {
            $saved = false;
        }
    }

    if (!$saved) {
        echo 'Could not save the settings. Please try again.';
        exit;
    }

    echo 'ok';
    exit;
}

brewski_require_role(['ADMIN']);

$min_password_length = brewski_min_password_length();

$idle_minutes_value = (int) round(brewski_session_idle_timeout() / 60);
$absolute_hours_value = (int) round(brewski_session_absolute_timeout() / 3600);

$record = brewski_setting_record('min_password_length');

$updated_at = '';

if ($record['updated_at'] !== '') {
    $updated_stamp = strtotime($record['updated_at']);

    if ($updated_stamp !== false) {
        $updated_at = date('M j, Y g:i A', $updated_stamp);
    }

    unset($updated_stamp);
}

unset($record);

?>

<link
    rel="stylesheet"
    href="<?php echo htmlspecialchars(BREWSKI_BASE_URL . '/admin/system%20settings/settings.css', ENT_QUOTES, 'UTF-8'); ?>"
>

<section class="page-container settings-page">
    <div class="page-header">
        <div>
            <h1 class="page-title">System Settings</h1>
            <p class="subtitle">Rules Brewski applies across the whole system.</p>
        </div>
    </div>

    <form
        class="form-grid settings-card"
        id="settingsForm"
        method="POST"
        action="<?php echo htmlspecialchars($settings_endpoint, ENT_QUOTES, 'UTF-8'); ?>"
    >
        <div class="form-field form-field-full">
            <label for="settingsMinPasswordLength">Minimum password length</label>

            <input
                type="number"
                id="settingsMinPasswordLength"
                name="min_password_length"
                value="<?php echo (int) $min_password_length; ?>"
                min="<?php echo (int) $password_bounds['min']; ?>"
                max="<?php echo (int) $password_bounds['max']; ?>"
                step="1"
                inputmode="numeric"
                autocomplete="off"
                required
            >

            <p class="form-hint">
                Customers creating an account must use at least this many characters.
                The sign-up page hint, its minimum length and its validation all follow
                this value. Allowed range:
                <?php echo (int) $password_bounds['min']; ?> to
                <?php echo (int) $password_bounds['max']; ?>.
            </p>
        </div>

        <div class="form-field">
            <label for="settingsIdleTimeout">Idle timeout (minutes)</label>

            <input
                type="number"
                id="settingsIdleTimeout"
                name="session_idle_timeout_minutes"
                value="<?php echo (int) $idle_minutes_value; ?>"
                min="<?php echo (int) $idle_min_minutes; ?>"
                max="<?php echo (int) $idle_max_minutes; ?>"
                step="1"
                inputmode="numeric"
                autocomplete="off"
                required
            >

            <p class="form-hint">
                Signs a signed-in user out after this long with no activity. Range:
                <?php echo (int) $idle_min_minutes; ?> to
                <?php echo (int) $idle_max_minutes; ?> minutes.
            </p>
        </div>

        <div class="form-field">
            <label for="settingsAbsoluteTimeout">Session lifetime (hours)</label>

            <input
                type="number"
                id="settingsAbsoluteTimeout"
                name="session_absolute_timeout_hours"
                value="<?php echo (int) $absolute_hours_value; ?>"
                min="<?php echo (int) $absolute_min_hours; ?>"
                max="<?php echo (int) $absolute_max_hours; ?>"
                step="1"
                inputmode="numeric"
                autocomplete="off"
                required
            >

            <p class="form-hint">
                Signs a signed-in user out this long after signing in, active or not.
                Must be longer than the idle timeout. Range:
                <?php echo (int) $absolute_min_hours; ?> to
                <?php echo (int) $absolute_max_hours; ?> hours.
            </p>
        </div>

        <div class="form-field form-field-full">
            <div class="settings-actions">
                <button type="submit" class="btn btn-primary" id="settingsSubmit">
                    Save settings
                </button>

                <p
                    class="settings-status"
                    id="settingsStatus"
                    role="status"
                    aria-live="polite"
                ><?php if ($updated_at !== ''): ?>Last saved <?php echo htmlspecialchars($updated_at, ENT_QUOTES, 'UTF-8'); ?>.<?php endif; ?></p>
            </div>
        </div>
    </form>
</section>

<script>
(function () {
    var form = document.getElementById('settingsForm');
    var password = document.getElementById('settingsMinPasswordLength');
    var idle = document.getElementById('settingsIdleTimeout');
    var status = document.getElementById('settingsStatus');
    var submit = document.getElementById('settingsSubmit');

    if (!form || !password || !idle || !status) {
        return;
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        status.textContent = 'Saving...';
        status.classList.remove('is-saved');
        status.classList.remove('is-error');

        if (submit) {
            submit.disabled = true;
        }

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.text();
            })
            .then(function (text) {
                var message = text.trim();

                if (message === 'ok') {
                    status.textContent =
                        'Saved. New accounts must use at least ' +
                        password.value +
                        ' characters, and idle sessions end after ' +
                        idle.value +
                        ' minutes.';
                    status.classList.add('is-saved');
                } else {
                    status.textContent = message;
                    status.classList.add('is-error');
                }
            })
            .catch(function () {
                status.textContent = 'Could not save. Please try again.';
                status.classList.add('is-error');
            })
            .finally(function () {
                if (submit) {
                    submit.disabled = false;
                }
            });
    });
})();
</script>

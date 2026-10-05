<?php

require_once __DIR__ . '/settings.php';

if (!defined('SESSION_IDLE_TIMEOUT')) {
    define('SESSION_IDLE_TIMEOUT', brewski_session_idle_timeout());
}

if (!defined('SESSION_ABSOLUTE_TIMEOUT')) {
    define('SESSION_ABSOLUTE_TIMEOUT', brewski_session_absolute_timeout());
}

if (!defined('BREWSKI_BASE_URL')) {
    $brewskiAppRoot = str_replace('\\', '/', dirname(__DIR__));
    $brewskiDocRoot = str_replace(
        '\\',
        '/',
        rtrim((string) realpath($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\')
    );

    if ($brewskiDocRoot !== '' && strpos($brewskiAppRoot, $brewskiDocRoot) === 0) {
        define('BREWSKI_BASE_URL', substr($brewskiAppRoot, strlen($brewskiDocRoot)));
    } else {
        define('BREWSKI_BASE_URL', '');
    }

    unset($brewskiAppRoot, $brewskiDocRoot);
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function brewski_login_url(string $query = ''): string
{
    return BREWSKI_BASE_URL . '/login-signup/login.php' . $query;
}

function brewski_is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function brewski_current_role(): string
{
    return strtoupper(trim((string) ($_SESSION['role'] ?? '')));
}

function brewski_home_for_role(string $role): string
{
    switch (strtoupper(trim($role))) {
        case 'ADMIN':
            return BREWSKI_BASE_URL . '/admin/admin%20home/admin_dashboard.php';

        case 'STAFF':
            return BREWSKI_BASE_URL . '/staff/orders/orders.php';

        case 'CUSTOMER':
            return BREWSKI_BASE_URL . '/customer/customer_home/customerhome.php';
    }

    return brewski_login_url();
}

function brewski_end_session(string $query = ''): void
{
    $_SESSION = [];

    if (session_status() === PHP_SESSION_ACTIVE && ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    header('Location: ' . brewski_login_url($query));
    exit;
}

function brewski_require_role(array $allowed = []): void
{
    if (!brewski_is_logged_in()) {
        brewski_end_session();
    }

    if ($allowed && !in_array(brewski_current_role(), $allowed, true)) {
        $home = brewski_home_for_role(brewski_current_role());

        if ($home === brewski_login_url()) {
            brewski_end_session();
        }

        header('Location: ' . $home);
        exit;
    }
}

if (brewski_is_logged_in()) {
    $brewskiNow = time();

    if (!isset($_SESSION['created_at'])) {
        $_SESSION['created_at'] = $brewskiNow;
    }

    if (!isset($_SESSION['last_activity'])) {
        $_SESSION['last_activity'] = $brewskiNow;
    }

    $brewskiIdleExpired =
        ($brewskiNow - (int) $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT;

    $brewskiAbsoluteExpired =
        ($brewskiNow - (int) $_SESSION['created_at']) > SESSION_ABSOLUTE_TIMEOUT;

    if ($brewskiIdleExpired || $brewskiAbsoluteExpired) {
        brewski_end_session('?timeout=1');
    }

    $_SESSION['last_activity'] = $brewskiNow;

    unset($brewskiNow, $brewskiIdleExpired, $brewskiAbsoluteExpired);
}

<?php

require_once __DIR__ . '/settings.php';

function brewski_is_https(): bool
{
    $flag = strtolower(trim((string) ($_SERVER['HTTPS'] ?? '')));

    if ($flag !== '' && $flag !== 'off' && $flag !== '0') {
        return true;
    }

    $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');

    if ($forwarded === '') {
        return false;
    }

    $first = explode(',', $forwarded);
    $proto = strtolower(trim($first[0]));

    return $proto === 'https';
}

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
        'secure'   => brewski_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/**
 * Registry of the separate sign-in entry points.
 *
 * Each portal only declares routing facts: where its login lives, where its
 * authenticator steps live, and which roles it will accept. The authentication
 * logic itself is shared (see auth_core.php), so adding a portal is a data
 * change rather than a second copy of the login flow.
 */
function brewski_portals(): array
{
    static $portals = null;

    if ($portals === null) {
        $portals = [
            'customer' => [
                'key'        => 'customer',
                'label'      => 'Customer sign-in',
                'login_path' => '/login-signup/login.php',
                'auth_path'  => '/login-signup/',
                'roles'      => ['CUSTOMER'],
                'signup'     => true,
                'recaptcha'  => true,
            ],
            'portal' => [
                'key'        => 'portal',
                'label'      => 'Staff & admin portal',
                'login_path' => '/portal/login.php',
                'auth_path'  => '/portal/',
                'roles'      => ['ADMIN', 'STAFF'],
                'signup'     => false,
                'recaptcha'  => true,
            ],
        ];
    }

    return $portals;
}

function brewski_portal(string $key): array
{
    $portals = brewski_portals();
    $key = strtolower(trim($key));

    return isset($portals[$key]) ? $portals[$key] : $portals['customer'];
}

function brewski_portal_key_for_role(string $role): string
{
    $role = strtoupper(trim($role));

    foreach (brewski_portals() as $key => $portal) {
        if (in_array($role, $portal['roles'], true)) {
            return $key;
        }
    }

    return 'customer';
}

function brewski_portal_login_url(string $portalKey, string $query = ''): string
{
    return BREWSKI_BASE_URL . brewski_portal($portalKey)['login_path'] . $query;
}

/**
 * URL for one of a portal's authenticator steps, so the shared 2FA pages can
 * send the browser back into the portal it started from.
 */
function brewski_auth_step_url(string $portalKey, string $step): string
{
    return BREWSKI_BASE_URL . brewski_portal($portalKey)['auth_path'] . $step;
}

function brewski_login_url_for_role(string $role, string $query = ''): string
{
    return brewski_portal_login_url(brewski_portal_key_for_role($role), $query);
}

function brewski_login_url(string $query = ''): string
{
    return brewski_portal_login_url('customer', $query);
}

/**
 * Which portal the browser is currently inside.
 *
 * The shared authenticator pages call this so they can render their own form
 * actions and cancel links, and send the user back to the sign-in page they
 * actually started from.
 */
function brewski_auth_active_portal(): string
{
    $loginPending = $_SESSION['totp_login_pending'] ?? null;

    if (is_array($loginPending) && !empty($loginPending['portal'])) {
        return (string) $loginPending['portal'];
    }

    $enrollmentPending = $_SESSION['totp_enrollment_pending'] ?? null;

    if (is_array($enrollmentPending) && !empty($enrollmentPending['portal'])) {
        return (string) $enrollmentPending['portal'];
    }

    if (brewski_is_logged_in()) {
        return brewski_portal_key_for_role(brewski_current_role());
    }

    return 'customer';
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

    return brewski_login_url_for_role($role);
}

function brewski_end_session(string $query = '', ?string $role = null): void
{
    if ($role === null) {
        $role = (string) ($_SESSION['role'] ?? '');
    }

    // Resolve the destination before the session is wiped, so an admin or
    // staff session lands on the portal instead of the customer login.
    $loginUrl = brewski_login_url_for_role($role, $query);

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

    header('Location: ' . $loginUrl);
    exit;
}

function brewski_require_role(array $allowed = []): void
{
    if (!brewski_is_logged_in()) {
        brewski_end_session();
    }

    if ($allowed && !in_array(brewski_current_role(), $allowed, true)) {
        $role = brewski_current_role();
        $home = brewski_home_for_role($role);

        if ($home === brewski_login_url_for_role($role)) {
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

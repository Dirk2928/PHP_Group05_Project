<?php

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

function brewski_session_scope(): string
{
    static $scope = null;
    if ($scope !== null) {
        return $scope;
    }

    $path = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (preg_match('~/admin/~i', $path)) {
        return $scope = 'admin';
    }
    if (preg_match('~/staff/~i', $path)) {
        return $scope = empty($_COOKIE['BREWSKI_STAFF']) && !empty($_COOKIE['BREWSKI_ADMIN'])
            ? 'admin' : 'staff';
    }
    if (preg_match('~/Start-screen/~i', $path)) {
        $requested = strtolower((string) ($_GET['as'] ?? ''));
        if (in_array($requested, ['admin', 'staff'], true)) {
            return $scope = $requested;
        }
        if (!empty($_COOKIE['BREWSKI_CUSTOMER'])) {
            return $scope = 'customer';
        }
        if (!empty($_COOKIE['BREWSKI_ADMIN'])) {
            return $scope = 'admin';
        }
        if (!empty($_COOKIE['BREWSKI_STAFF'])) {
            return $scope = 'staff';
        }
    }
    if (preg_match('~/portal/~i', $path)
        || preg_match('~/login-signup/logout\.php$~i', $path)) {
        $requested = strtolower((string) ($_GET['as'] ?? ''));
        if (in_array($requested, ['admin', 'staff'], true)) {
            return $scope = $requested;
        }
        if (preg_match('~/portal/~i', $path)) {
            return $scope = 'admin';
        }
    }

    return $scope = 'customer';
}

if (session_status() === PHP_SESSION_NONE) {
    session_name('BREWSKI_' . strtoupper(brewski_session_scope()));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => brewski_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

require_once __DIR__ . '/settings.php';

if (!defined('SESSION_IDLE_TIMEOUT')) {
    define('SESSION_IDLE_TIMEOUT', brewski_session_idle_timeout());
}

if (!defined('SESSION_ABSOLUTE_TIMEOUT')) {
    define('SESSION_ABSOLUTE_TIMEOUT', brewski_session_absolute_timeout());
}









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
    $url = BREWSKI_BASE_URL . brewski_portal($portalKey)['login_path'];
    if ($portalKey === 'portal') {
        $url .= '?as=' . (brewski_session_scope() === 'staff' ? 'staff' : 'admin');
    }
    if ($query !== '') {
        $url .= (strpos($url, '?') === false ? '?' : '&') . ltrim($query, '?&');
    }
    return $url;
}





function brewski_auth_step_url(string $portalKey, string $step): string
{
    $url = BREWSKI_BASE_URL . brewski_portal($portalKey)['auth_path'] . $step;
    if ($portalKey === 'portal') {
        $url .= '?as=' . (brewski_session_scope() === 'staff' ? 'staff' : 'admin');
    }
    return $url;
}

function brewski_login_url_for_role(string $role, string $query = ''): string
{
    $role = strtoupper(trim($role));
    if ($role === 'ADMIN' || $role === 'STAFF') {
        $url = BREWSKI_BASE_URL . '/portal/login.php?as=' . strtolower($role);
        return $url . ($query !== '' ? '&' . ltrim($query, '?&') : '');
    }
    return brewski_portal_login_url('customer', $query);
}

function brewski_logout_url(string $query = ''): string
{
    $url = BREWSKI_BASE_URL . '/login-signup/logout.php';
    $scope = brewski_session_scope();
    if ($scope === 'admin' || $scope === 'staff') {
        $url .= '?as=' . $scope;
    }
    if ($query !== '') {
        $url .= (strpos($url, '?') === false ? '?' : '&') . ltrim($query, '?&');
    }
    return $url;
}

function brewski_login_url(string $query = ''): string
{
    return brewski_portal_login_url('customer', $query);
}

function brewski_session_timeout_markup(): string
{
    if (!brewski_is_logged_in()) {
        return '';
    }

    $escape = static function (string $value): string {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    };

    $absoluteRemaining = max(
        0,
        SESSION_ABSOLUTE_TIMEOUT - (time() - (int) ($_SESSION['created_at'] ?? time()))
    );

    return '<meta name="session-timeout-seconds" content="' . (int) SESSION_IDLE_TIMEOUT . '">' . "\n"
        . '<meta name="session-absolute-remaining-seconds" content="' . $absoluteRemaining . '">' . "\n"
        . '<meta name="logout-url" content="' . $escape(brewski_logout_url('timeout=1')) . '">' . "\n"
        . '<script src="' . $escape(BREWSKI_BASE_URL . '/login-signup/session-timeout.js') . '" defer></script>' . "\n";
}








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

/*
 * "Proceed to checkout" while signed out sends the customer to
 * login.php?next=checkout. We remember that choice in the session, and once
 * the login finishes (password / OTP / authenticator) the customer lands on
 * the checkout page instead of the home page.
 *
 * Only the word "checkout" is stored, never a URL, so this cannot be used to
 * redirect people to other sites. The choice expires after 30 minutes.
 */
function brewski_remember_post_login_target(string $target): void
{
    if ($target !== 'checkout') {
        return;
    }

    $_SESSION['post_login_target'] = [
        'target' => 'checkout',
        'at'     => time(),
    ];

    // Backup copy in a short-lived cookie, in case the login steps reset the session
    if (!headers_sent()) {
        setcookie('brewski_next', 'checkout', [
            'expires'  => time() + 1800,
            'path'     => '/',
            'secure'   => brewski_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

function brewski_take_post_login_url(): ?string
{
    $pending = $_SESSION['post_login_target'] ?? null;
    unset($_SESSION['post_login_target']);

    $wantsCheckout = false;

    if (is_array($pending) && ($pending['target'] ?? '') === 'checkout') {
        $wantsCheckout = (time() - (int) ($pending['at'] ?? 0)) <= 1800;
    }

    if (!$wantsCheckout && (($_COOKIE['brewski_next'] ?? '') === 'checkout')) {
        $wantsCheckout = true;
    }

    if (isset($_COOKIE['brewski_next']) && !headers_sent()) {
        setcookie('brewski_next', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => brewski_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    return $wantsCheckout
        ? BREWSKI_BASE_URL . '/customer/checkout/checkout.php'
        : null;
}

function brewski_home_for_role(string $role): string
{
    switch (strtoupper(trim($role))) {
        case 'ADMIN':
            return BREWSKI_BASE_URL . '/admin/admin%20home/admin_dashboard.php';

        case 'STAFF':
            return BREWSKI_BASE_URL . '/staff/orders/orders.php';

        case 'CUSTOMER':
            $checkoutUrl = brewski_take_post_login_url();

            if ($checkoutUrl !== null) {
                return $checkoutUrl;
            }

            return BREWSKI_BASE_URL . '/customer/customer_home/customerhome.php';
    }

    return brewski_login_url_for_role($role);
}

function brewski_end_session(string $query = '', ?string $role = null): void
{
    if ($role === null) {
        $role = (string) ($_SESSION['role'] ?? '');
    }

    if ($role === '') {
        $scope = brewski_session_scope();
        $role = $scope === 'admin' || $scope === 'staff' ? strtoupper($scope) : 'CUSTOMER';
    }



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
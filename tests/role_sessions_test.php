<?php

$case = $argv[1] ?? '';
$scenarios = [
    'customer' => ['/brewski/customer/customer_menu/customermenu.php', [], [], 'customer'],
    'admin' => ['/brewski/admin/admin home/admin_dashboard.php', [], [], 'admin'],
    'staff' => ['/brewski/staff/orders/orders.php', [], [], 'staff'],
    'staff-fallback' => ['/brewski/staff/orders/orders.php', [], ['BREWSKI_ADMIN' => 'existing'], 'admin'],
    'portal-admin' => ['/brewski/portal/login.php', ['as' => 'admin'], [], 'admin'],
    'portal-staff' => ['/brewski/portal/login.php', ['as' => 'staff'], [], 'staff'],
    'start-admin' => ['/brewski/Start-screen/start.php', ['as' => 'admin'], [], 'admin'],
    'logout-staff' => ['/brewski/login-signup/logout.php', ['as' => 'staff'], [], 'staff'],
];

if (!isset($scenarios[$case])) {
    fwrite(STDERR, "Unknown role session test case.\n");
    exit(2);
}

[$script, $query, $cookies, $expected] = $scenarios[$case];
$_SERVER['SCRIPT_NAME'] = $script;
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_GET = $query;
$_COOKIE = $cookies;

define('SESSION_IDLE_TIMEOUT', 1800);
define('SESSION_ABSOLUTE_TIMEOUT', 28800);
require __DIR__ . '/../brewski/login-signup/session_init.php';

$actual = brewski_session_scope();
if ($actual !== $expected || session_name() !== 'BREWSKI_' . strtoupper($expected)) {
    fwrite(STDERR, "Wrong session scope for {$case}: {$actual} / " . session_name() . "\n");
    exit(1);
}

$logoutUrl = brewski_logout_url();
if ($expected === 'customer' ? strpos($logoutUrl, '?as=') !== false
    : strpos($logoutUrl, '?as=' . $expected) === false) {
    fwrite(STDERR, "Wrong logout URL for {$case}: {$logoutUrl}\n");
    exit(1);
}

if (strpos(brewski_login_url_for_role('STAFF'), '?as=staff') === false
    || strpos(brewski_login_url_for_role('ADMIN'), '?as=admin') === false) {
    fwrite(STDERR, "Role login URLs are incorrect.\n");
    exit(1);
}

if ($expected !== 'customer'
    && strpos(brewski_auth_step_url('portal', 'verify-authenticator.php'), '?as=' . $expected) === false) {
    fwrite(STDERR, "Authenticator URL has the wrong session scope.\n");
    exit(1);
}

echo "{$case}: ok\n";

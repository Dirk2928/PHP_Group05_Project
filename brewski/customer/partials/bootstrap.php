<?php


require_once __DIR__ . '/../../login-signup/session_init.php';

brewski_require_role(['CUSTOMER']);

if (!function_exists('e')) {

    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$first_name = $first_name ?? ($_SESSION['first_name'] ?? 'Customer');
$last_name  = $last_name  ?? ($_SESSION['last_name']  ?? '');
$email      = $email      ?? ($_SESSION['email']      ?? '');

$display_first_name = e($first_name);
$display_last_name  = e($last_name);
$display_email      = e($email);

$cart_count = $cart_count ?? 0;

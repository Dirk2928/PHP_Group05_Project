<?php

// "Proceed to checkout" from the cart always comes through here.
// Signed in  -> checkout page.
// Signed out -> login page, and after login the customer lands on checkout.

require_once __DIR__ . '/../../login-signup/session_init.php';

if (!brewski_is_logged_in()) {
    brewski_remember_post_login_target('checkout');
    header('Location: ' . brewski_login_url('?next=checkout'), true, 302);
    exit;
}

$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ../checkout/checkout.php' . ($query !== '' ? '?' . $query : ''), true, 302);
exit;<?php

// "Proceed to checkout" from the cart always comes through here.
// Signed in  -> checkout page.
// Signed out -> login page, and after login the customer lands on checkout.

require_once __DIR__ . '/../../login-signup/session_init.php';

if (!brewski_is_logged_in()) {
    brewski_remember_post_login_target('checkout');
    header('Location: ' . brewski_login_url('?next=checkout'), true, 302);
    exit;
}

$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ../checkout/checkout.php' . ($query !== '' ? '?' . $query : ''), true, 302);
exit;
<?php

require_once __DIR__ . '/session_init.php';

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

$now = time();

if (isset($_SESSION['user_id'])) {
    $idleExpired = isset($_SESSION['last_activity'])
        && ($now - $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT;

    $absoluteExpired = isset($_SESSION['created_at'])
        && ($now - $_SESSION['created_at']) > SESSION_ABSOLUTE_TIMEOUT;

    if ($idleExpired || $absoluteExpired) {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }

        session_destroy();

        header('Location: ' . brewski_login_url('?timeout=1'));
        exit;
    }

    $_SESSION['last_activity'] = $now;
}
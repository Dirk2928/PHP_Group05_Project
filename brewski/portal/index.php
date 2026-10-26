<?php


require_once __DIR__ . '/../login-signup/session_init.php';

header('Location: ' . brewski_portal_login_url('portal'));
exit;

<?php

// The portal lives at its own URL; keep the bare directory working too.
require_once __DIR__ . '/../login-signup/session_init.php';

header('Location: ' . brewski_portal_login_url('portal'));
exit;

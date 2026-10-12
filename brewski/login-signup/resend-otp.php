<?php
session_start();

require_once __DIR__ . '/session_init.php';

unset($_SESSION['otp_pending'], $_SESSION['otp_resend_error'], $_SESSION['otp_resend_success']);
session_regenerate_id(true);

header('Location: login.php?msg=login_method_changed');
exit;

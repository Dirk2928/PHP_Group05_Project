<?php
session_start();

header('Location: resend-login-otp.php', true, 307);
exit;

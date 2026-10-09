<?php
session_start();

if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$dbHost = 'localhost';
$dbName = 'brewski_db';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database connection failed.');
}

if (empty($_SESSION['user_id'])) {
    header('Location: ../customer-home/login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$success = '';

if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = 'Invalid request. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_profile') {
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $lastName = trim((string) ($_POST['last_name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));

            if ($firstName === '' || mb_strlen($firstName) > 50) {
                $errors[] = 'Please enter a valid first name.';
            }

            if ($lastName === '' || mb_strlen($lastName) > 50) {
                $errors[] = 'Please enter a valid last name.';
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
                $errors[] = 'Please enter a valid email address.';
            }

            if (!$errors) {
                $check = $pdo->prepare('SELECT user_id FROM users WHERE email = ? AND user_id <> ?');
                $check->execute([$email, $userId]);

                if ($check->fetch()) {
                    $errors[] = 'That email is already used by another account.';
                }
            }

            if (!$errors) {
                $update = $pdo->prepare('UPDATE users SET first_name = ?, last_name = ?, email = ? WHERE user_id = ?');
                $update->execute([$firstName, $lastName, $email, $userId]);
                $_SESSION['flash_success'] = 'Your profile has been updated.';
                header('Location: profile.php');
                exit;
            }
        }

        if ($action === 'change_password') {
            $current = (string) ($_POST['current_password'] ?? '');
            $new = (string) ($_POST['new_password'] ?? '');
            $confirm = (string) ($_POST['confirm_password'] ?? '');

            $row = $pdo->prepare('SELECT password FROM users WHERE user_id = ?');
            $row->execute([$userId]);
            $hash = $row->fetchColumn();

            if (!$hash || !password_verify($current, $hash)) {
                $errors[] = 'Your current password is incorrect.';
            }

            if (strlen($new) < 8) {
                $errors[] = 'The new password must be at least 8 characters.';
            }

            if (strlen($new) > 72) {
                $errors[] = 'The new password must not be longer than 72 characters.';
            }

            if ($new !== $confirm) {
                $errors[] = 'The new passwords do not match.';
            }

            if (!$errors && $new === $current) {
                $errors[] = 'The new password must be different from your current password.';
            }

            if (!$errors) {
                $update = $pdo->prepare('UPDATE users SET password = ? WHERE user_id = ?');
                $update->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
                session_regenerate_id(true);
                $_SESSION['flash_success'] = 'Your password has been changed.';
                header('Location: profile.php');
                exit;
            }
        }
    }
}

$stmt = $pdo->prepare('SELECT user_id, first_name, last_name, email FROM users WHERE user_id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    session_unset();
    session_destroy();
    header('Location: ../customer-home/login.php');
    exit;
}

$display_first_name = e($user['first_name']);
$display_last_name = e($user['last_name']);
$display_email = e($user['email']);

$initials = strtoupper(
    mb_substr($user['first_name'], 0, 1) .
    mb_substr($user['last_name'], 0, 1)
);

$cartCount = 0;
if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cartCount += is_array($item) ? (int) ($item['quantity'] ?? 1) : (int) $item;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Brewski</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Questrial&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="profile.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
    <header class="navbar">
        <a href="../customer-home/customerhome.php" class="navbar__brand">brewski</a>

        <nav class="navbar__links">
            <a href="../customer-home/customerhome.php" class="nav-link">
                <i data-lucide="house"></i>
                <span>Home</span>
            </a>
            <a href="../customer-home/customermenu.php" class="nav-link">
                <i data-lucide="coffee"></i>
                <span>Menu</span>
            </a>
            <a href="../customer-home/cart.php" class="nav-link nav-link--cart">
                <i data-lucide="shopping-cart"></i>
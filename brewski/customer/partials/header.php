<?php

$pageTitle   = $pageTitle   ?? 'brewski';
$active      = $active      ?? '';
$extraStyles = $extraStyles ?? [];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$cartSessionUrl = BREWSKI_BASE_URL . '/customer/customer_cart/cart_session.php';
$btnBrewskiLogo = BREWSKI_BASE_URL . '/Start-screen/start.php';

$navItems = [
    'home'    => ['label' => 'Home',    'icon' => 'home',          'href' => '../customer_home/customerhome.php'],
    'menu'    => ['label' => 'Menu',    'icon' => 'coffee',        'href' => '../customer_menu/customermenu.php'],
    'orders'  => ['label' => 'Orders',  'icon' => 'receipt',       'href' => '../customer_orders/orders.php'],
    'profile' => ['label' => 'Profile', 'icon' => 'user',          'href' => '../customer_profile/customer_profile.php'],
];

$checkoutHref = BREWSKI_BASE_URL . '/customer/checkout/checkout.php';

$headerAvatarUrl = null;
if (!empty($_SESSION['user_id'])) {
    try {
        require_once __DIR__ . '/../../Db/db_config.php';
        $avatarConn = brewski_db_connection();
        $avatarStmt = $avatarConn->prepare(
            'SELECT avatar_mime_type FROM users WHERE user_id = ? LIMIT 1'
        );
        $avatarStmt->bind_param('i', $_SESSION['user_id']);
        $avatarStmt->execute();
        $avatarStmt->bind_result($avatarMime);
        if ($avatarStmt->fetch() && $avatarMime) {
            $headerAvatarUrl = BREWSKI_BASE_URL . '/customer/user_avatar.php?id=' . (int) $_SESSION['user_id'];
        }
        $avatarStmt->close();
        $avatarConn->close();
    } catch (Throwable $e) {
        $headerAvatarUrl = null;
    }
}

$headerInitials = strtoupper(
    mb_substr((string) ($display_first_name ?? ''), 0, 1) .
    mb_substr((string) ($display_last_name ?? ''), 0, 1)
);
if ($headerInitials === '') {
    $headerInitials = '?';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <meta name="cart-session-url" content="<?= e($cartSessionUrl) ?>">
    <meta name="checkout-url" content="<?= e($checkoutHref) ?>">
    <meta name="csrf-token" content="<?= e($_SESSION['csrf_token']) ?>">
    <meta name="cart-sync-enabled" content="<?= !empty($_SESSION['user_id']) ? '1' : '0' ?>">
    <meta name="session-timeout-seconds" content="<?= (int) SESSION_IDLE_TIMEOUT ?>">
    <meta name="logout-url" content="<?= e(BREWSKI_BASE_URL . '/login-signup/logout.php?timeout=1') ?>">

    <title><?= e($pageTitle) ?></title>

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600&family=Questrial&display=swap"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="../styles.css">
    <link rel="stylesheet" href="../header.css">

    <?php foreach ($extraStyles as $style): ?>

        <link
            rel="stylesheet"
            href="../<?= e($style) ?>"
        >

    <?php endforeach; ?>

    <script src="https://unpkg.com/lucide@latest" defer></script>

    <style>
        .cart-dropdown__footer {
            padding: 0.75rem 1rem 1rem;
            border-top: 1px solid #e8dac4;
            margin-top: 0.5rem;
        }

        .cart-checkout-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
            padding: 0.85rem 1rem;
            background: #1a0f0a;
            color: #faf5ee !important;
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 0.95rem;
            text-decoration: none;
            transition: background 0.2s ease;
        }

        .cart-checkout-btn:hover {
            background: #3b2218;
        }

        .cart-checkout-btn i,
        .cart-checkout-btn svg {
            width: 18px;
            height: 18px;
            stroke: #faf5ee !important;
        }

        .profile-dropdown__user {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .profile-dropdown__avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
            border: 2px solid #e8dac4;
            display: block;
        }

        .profile-dropdown__avatar--initials {
            display: flex;
            align-items: center;
            justify-content: center;
            background: #1a0f0a;
            color: #faf5ee;
            font-weight: 700;
            font-size: 0.85rem;
        }

        .profile-dropdown__user-text {
            display: flex;
            flex-direction: column;
            overflow: hidden;
            min-width: 0;
        }

        .profile-dropdown__user-text strong {
            font-size: 0.9rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .profile-dropdown__user-text span {
            font-size: 0.8rem;
            color: #5c4033;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
    </style>

</head>

<body>

    <header class="topbar">

        <a class="topbar__brand" href="<?= e($btnBrewskiLogo) ?>" aria-label="Brewski home">
            brewski
        </a>

        <nav class="topbar__nav">

            <a
                href="<?= e($navItems['home']['href']) ?>"
                class="topbar__link<?= $active === 'home' ? ' active' : '' ?>"
            >
                <i data-lucide="<?= e($navItems['home']['icon']) ?>"></i>
                <?= e($navItems['home']['label']) ?>
            </a>

            <a
                href="<?= e($navItems['menu']['href']) ?>"
                class="topbar__link<?= $active === 'menu' ? ' active' : '' ?>"
            >
                <i data-lucide="<?= e($navItems['menu']['icon']) ?>"></i>
                <?= e($navItems['menu']['label']) ?>
            </a>

            <div class="cart-menu" id="cart-menu">

                <button
                    type="button"
                    class="topbar__link cart-button"
                    id="cart-button"
                    aria-haspopup="true"
                    aria-expanded="false"
                >

                    <i data-lucide="shopping-cart"></i>
                    Cart

                    <span
                        class="cart-badge"
                        data-cart-count
                    ><?= (int) $cart_count ?></span>

                </button>

                <div
                    class="cart-dropdown"
                    id="cart-dropdown"
                >

                    <h3 class="cart-dropdown__title">
                        Your cart
                    </h3>

                    <div id="cart-body"></div>

                    <div class="cart-dropdown__footer" id="cart-footer">
                        <a
                            href="<?= e($checkoutHref) ?>"
                            class="cart-checkout-btn"
                        >
                            <i data-lucide="shopping-bag"></i>
                            Proceed to Checkout
                        </a>
                    </div>

                </div>

            </div>

            <a
                href="<?= e($navItems['orders']['href']) ?>"
                class="topbar__link<?= $active === 'orders' ? ' active' : '' ?>"
            >
                <i data-lucide="<?= e($navItems['orders']['icon']) ?>"></i>
                <?= e($navItems['orders']['label']) ?>
            </a>

            <div class="profile-menu">

                <button
                    type="button"
                    class="profile-button"
                    id="profile-button"
                    aria-label="Open profile menu"
                    aria-haspopup="true"
                    aria-expanded="false"
                    aria-controls="profile-dropdown"
                >

                    <?php if ($headerAvatarUrl): ?>

                        <img
                            src="<?= e($headerAvatarUrl) ?>"
                            alt=""
                            class="profile-button__avatar"
                        >

                    <?php else: ?>

                        <span class="profile-button__avatar profile-button__avatar--initials" aria-hidden="true">
                            <?= e($headerInitials) ?>
                        </span>

                    <?php endif; ?>

                </button>

                <div
                    class="profile-dropdown"
                    id="profile-dropdown"
                >

                    <div class="profile-dropdown__user">

                        <?php if ($headerAvatarUrl): ?>

                            <img
                                src="<?= e($headerAvatarUrl) ?>"
                                alt="Profile picture"
                                class="profile-dropdown__avatar"
                            >

                        <?php else: ?>

                            <span class="profile-dropdown__avatar profile-dropdown__avatar--initials">
                                <?= e($headerInitials) ?>
                            </span>

                        <?php endif; ?>

                        <div class="profile-dropdown__user-text">

                            <strong><?= e($display_first_name . ' ' . $display_last_name) ?></strong>

                            <span><?= e($display_email) ?></span>

                        </div>

                    </div>

                    <div class="profile-dropdown__divider"></div>

                    <a
                        href="<?= e($navItems['profile']['href']) ?>"
                        class="logout-button"
                    >
                        <i data-lucide="user-round"></i>
                        My Profile
                    </a>

                    <a
                        href="../../login-signup/logout.php"
                        class="logout-button"
                    >
                        <i data-lucide="log-out"></i>
                        Logout
                    </a>

                </div>

            </div>

        </nav>

    </header>

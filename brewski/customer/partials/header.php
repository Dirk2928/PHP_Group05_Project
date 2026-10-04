<?php

$pageTitle   = $pageTitle   ?? 'brewski';
$active      = $active      ?? '';
$extraStyles = $extraStyles ?? [];

$navItems = [
    'home'    => ['label' => 'Home',    'icon' => 'home',          'href' => '../customer_home/customerhome.php'],
    'menu'    => ['label' => 'Menu',    'icon' => 'coffee',        'href' => '../customer_menu/customermenu.php'],
    'orders'  => ['label' => 'Orders',  'icon' => 'receipt',       'href' => '../customer_orders/orders.php'],
    'profile' => ['label' => 'Profile', 'icon' => 'user',          'href' => '../customer_profile/customer_profile.php'],
];

// TODO: the Orders page does not exist yet. Point this at the real file once
// it is built; for now the nav item is here to match the agreed layout.
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

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

    <script src="https://unpkg.com/lucide@latest"></script>

</head>

<body>

    <header class="topbar">

        <div class="topbar__brand">
            brewski
        </div>

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

                <a
                    href="<?= e($navItems['profile']['href']) ?>"
                    class="topbar__link profile-link<?= $active === 'profile' ? ' active' : '' ?>"
                >
                    <i data-lucide="<?= e($navItems['profile']['icon']) ?>"></i>
                    <?= e($navItems['profile']['label']) ?>
                </a>

                <button
                    type="button"
                    class="profile-button"
                    id="profile-button"
                    aria-label="Open profile menu"
                    aria-haspopup="true"
                    aria-expanded="false"
                >

                    <i
                        data-lucide="chevron-down"
                        class="profile-chevron"
                    ></i>

                </button>

                <div
                    class="profile-dropdown"
                    id="profile-dropdown"
                >

                    <div class="profile-dropdown__user">

                        <strong><?= e($display_first_name . ' ' . $display_last_name) ?></strong>

                        <span><?= e($display_email) ?></span>

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

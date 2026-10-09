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

$checkoutHref = '/Brewski/brewski/customer/checkout/checkout.php';
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

    <style>
        /* Checkout button inside the cart dropdown */
        .cart-dropdown__footer {
            display: none;               /* toggled by cart.js when cart has items */
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
    </style>

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
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600&family=Questrial&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="../styles.css">
    <link rel="stylesheet" href="../header.css">

    <?php foreach ($extraStyles as $style): ?>
        <link rel="stylesheet" href="../<?= e($style) ?>">
    <?php endforeach; ?>

    <script src="https://unpkg.com/lucide@latest"></script>

    <style>
        /* Cart Actions (Edit + Remove) */
        .cart-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            justify-content: flex-end;
        }
        .cart-edit__btn, .cart-remove__btn {
            background: transparent;
            border: none;
            cursor: pointer;
            padding: 0.25rem;
            color: #5c4033;
            transition: color 0.2s ease;
        }
        .cart-edit__btn:hover { color: #1a0f0a; }
        .cart-remove__btn:hover { color: #a32a1f; }

        /* Cart Edit Modal */
        .cart-edit-modal {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(26, 15, 10, 0.6);
        }
        .cart-edit-modal[hidden] { display: none; }
        .cart-edit-modal__panel {
            background: #faf5ee;
            padding: 2rem;
            border-radius: 1rem;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            color: #1a0f0a;
        }
        .cart-edit-modal__panel h3 {
            margin-top: 0;
            margin-bottom: 1rem;
            font-size: 1.25rem;
        }
        .cart-edit-modal__panel label {
            display: block;
            margin-bottom: 1rem;
            font-weight: 600;
            font-size: 0.9rem;
        }
        .cart-edit-modal__panel input, .cart-edit-modal__panel textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #e8dac4;
            border-radius: 0.5rem;
            margin-top: 0.5rem;
            font-family: inherit;
            font-size: 1rem;
        }
        .cart-edit-modal__actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
        }
        .cart-edit-modal__actions button {
            padding: 0.75rem 1.25rem;
            border-radius: 0.5rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
        }
        #cart-edit-cancel {
            background: #e8dac4;
            color: #3b2218;
        }
        .cart-edit-modal__actions button[type="submit"] {
            background: #1a0f0a;
            color: #faf5ee;
        }

        /* Avatar inside the profile dropdown */
        .profile-dropdown__user { display: flex; align-items: center; gap: 0.75rem; }
        .profile-dropdown__avatar {
            width: 40px; height: 40px; border-radius: 50%;
            object-fit: cover; flex-shrink: 0; border: 2px solid #e8dac4; display: block;
        }
        .profile-dropdown__avatar--initials {
            display: flex; align-items: center; justify-content: center;
            background: #1a0f0a; color: #faf5ee; font-weight: 700; font-size: 0.85rem;
        }
        .profile-dropdown__user-text { display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        .profile-dropdown__user-text strong { font-size: 0.9rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .profile-dropdown__user-text span { font-size: 0.8rem; color: #5c4033; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    </style>

</head>

<body>

    <header class="topbar">
        <div class="topbar__brand">brewski</div>

        <nav class="topbar__nav">
            <a href="<?= e($navItems['home']['href']) ?>" class="topbar__link<?= $active === 'home' ? ' active' : '' ?>">
                <i data-lucide="<?= e($navItems['home']['icon']) ?>"></i> <?= e($navItems['home']['label']) ?>
            </a>

            <a href="<?= e($navItems['menu']['href']) ?>" class="topbar__link<?= $active === 'menu' ? ' active' : '' ?>">
                <i data-lucide="<?= e($navItems['menu']['icon']) ?>"></i> <?= e($navItems['menu']['label']) ?>
            </a>

            <div class="cart-menu" id="cart-menu">
                <button type="button" class="topbar__link cart-button" id="cart-button" aria-haspopup="true" aria-expanded="false">
                    <i data-lucide="shopping-cart"></i> Cart
                    <span class="cart-badge" data-cart-count><?= (int) $cart_count ?></span>
                </button>

                <div class="cart-dropdown" id="cart-dropdown">
                    <h3 class="cart-dropdown__title">Your cart</h3>
                    <div id="cart-body"></div>

                    <div class="cart-dropdown__footer" id="cart-footer">
                        <a href="<?= e(BREWSKI_BASE_URL . '/customer/checkout/checkout.php') ?>" class="cart-checkout">
                            <i data-lucide="shopping-bag"></i> Proceed to Checkout
                        </a>
                    </div>
                </div>
            </div>

            <a href="<?= e($navItems['orders']['href']) ?>" class="topbar__link<?= $active === 'orders' ? ' active' : '' ?>">
                <i data-lucide="<?= e($navItems['orders']['icon']) ?>"></i> <?= e($navItems['orders']['label']) ?>
            </a>

            <div class="profile-menu">
                <a href="<?= e($navItems['profile']['href']) ?>" class="topbar__link profile-link<?= $active === 'profile' ? ' active' : '' ?>">
                    <i data-lucide="<?= e($navItems['profile']['icon']) ?>"></i> <?= e($navItems['profile']['label']) ?>
                </a>

                <button type="button" class="profile-button" id="profile-button" aria-label="Open profile menu" aria-haspopup="true" aria-expanded="false">
                    <i data-lucide="chevron-down" class="profile-chevron"></i>
                </button>

                <div class="profile-dropdown" id="profile-dropdown">
                    <div class="profile-dropdown__user">
                        <?php if ($headerAvatarUrl): ?>
                            <img src="<?= e($headerAvatarUrl) ?>" alt="Profile picture" class="profile-dropdown__avatar">
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

                    <a href="<?= e($navItems['profile']['href']) ?>" class="logout-button">
                        <i data-lucide="user-round"></i> My Profile
                    </a>
                    <a href="<?= e(BREWSKI_BASE_URL . '/login-signup/logout.php') ?>" class="logout-button">
                        <i data-lucide="log-out"></i> Logout
                    </a>
                </div>
            </div>
        </nav>
    </header>

    <!-- Cart Edit Modal -->
    <div id="cart-edit-modal" class="cart-edit-modal" hidden>
        <div class="cart-edit-modal__panel">
            <h3>Edit Drink</h3>
            <form id="cart-edit-form">
                <input type="hidden" id="cart-edit-key" value="">
                
                <div style="margin-bottom: 1rem; font-weight: bold; color: #3b2218;" id="cart-edit-name"></div>

                <label>
                    Quantity (Max 99)
                    <input type="number" id="cart-edit-qty" min="1" max="99" required>
                </label>

                <label>
                    Customization Notes (Sugar, Ice, etc.)
                    <textarea id="cart-edit-custom" rows="2" placeholder="e.g. 50% sugar, less ice"></textarea>
                </label>

                <div class="cart-edit-modal__actions">
                    <button type="button" id="cart-edit-cancel">Cancel</button>
                    <button type="submit">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
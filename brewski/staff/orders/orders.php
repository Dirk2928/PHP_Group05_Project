<?php

require_once __DIR__ . '/../../login-signup/session_init.php';

brewski_require_role(['STAFF', 'ADMIN']);

$is_signed_in = brewski_is_logged_in();

$first_name = $_SESSION['first_name'] ?? '';
$last_name  = $_SESSION['last_name'] ?? '';

$full_name = trim($first_name . ' ' . $last_name);

if ($full_name === '') {
    $full_name = 'Staff';
}

$display_name = htmlspecialchars(
    $full_name,
    ENT_QUOTES,
    'UTF-8'
);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>brewski Staff</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600&display=swap" rel="stylesheet">

    <link
        rel="stylesheet"
        href="../staff.css?v=<?= filemtime(__DIR__ . '/../staff.css') ?>"
    >

</head>

<body>

    <header class="topbar">
        <img class="topbar-logo" src="../../images/brewskilogo.png" alt="Brewski logo">
        <div class="profile-wrapper">
            <span class="profile-label">Staff</span>

            <button
                type="button"
                id="profileBtn"
                class="icon-btn profile-btn"
                aria-label="Open profile menu"
                aria-expanded="false"
            ></button>

            <div id="profileMenu" class="profile-menu hidden">
                <a
                    href="../../login-signup/logout.php"
                    class="profile-menu-item border-top"
                >
                    Log out
                </a>

            </div>

        </div>
    </header>

    <aside id="sidebar" class="sidebar">
        <div class="sidebar-brand">brewski</div>

        <nav class="sidebar-nav">

            <button
                type="button"
                class="nav-item active"
                data-view="orders"
            >
                Orders
            </button>

            <button
                type="button"
                class="nav-item"
                data-view="products"
            >
                Products
            </button>

            <button
                type="button"
                class="nav-item"
                data-view="../order_history/order_history.php"
            >
                Order History
            </button>

            <button
                type="button"
                class="nav-item"
                data-view="profile"
            >
                Profile
            </button>

        </nav>

    </aside>

    <main class="main-content" id="mainContent">

        <section id="ordersView">

            <div class="page-container">

                <div class="page-header">

                    <h1 class="page-title">
                        Orders
                    </h1>

                    <p class="subtitle">
                        <?php if ($is_signed_in): ?>Signed in as <?= $display_name ?>. <?php endif; ?>Track incoming orders
                        and update their status.
                    </p>

                </div>

                <div class="table-card">

                    <div class="empty-state">

                        <p class="empty-title">
                            No orders to show yet
                        </p>

                        <p class="empty-hint">
                            Orders placed by customers will appear here.
                        </p>

                    </div>

                </div>

            </div>

        </section>

        <section id="dynamicView" class="hidden"></section>

        <section id="placeholderView" class="hidden">

            <h1 id="placeholderTitle">
                Page Not Found
            </h1>

            <p class="subtitle">
                This section has not been implemented yet.
            </p>

        </section>

    </main>

    <script
        src="../staff.js?v=<?= filemtime(__DIR__ . '/../staff.js') ?>"
        defer
    ></script>

</body>

</html>

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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

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
    <script src="https://unpkg.com/lucide@latest"></script>

</head>

<body>

    <header class="topbar">
        <button type="button" id="sidebarToggle" class="icon-btn sidebar-toggle" aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
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
                    href="<?= htmlspecialchars(brewski_logout_url(), ENT_QUOTES, 'UTF-8') ?>"
                    class="profile-menu-item border-top"
                >
                    Log out
                </a>

            </div>

        </div>
    </header>

    <aside id="sidebar" class="sidebar" aria-hidden="true">
        <div class="sidebar-brand">brewski</div>

        <nav class="sidebar-nav">

            <button
                type="button"
                class="nav-item active"
                data-view="orders"
            >
                <i class="nav-icon" data-lucide="clipboard-list" aria-hidden="true"></i><span>Orders</span>
            </button>

            <button
                type="button"
                class="nav-item"
                data-view="products"
            >
                <i class="nav-icon" data-lucide="coffee" aria-hidden="true"></i><span>Products</span>
            </button>

            <button
                type="button"
                class="nav-item"
                data-view="../order_history/order_history.php"
            >
                <i class="nav-icon" data-lucide="history" aria-hidden="true"></i><span>Order History</span>
            </button>

            <button
                type="button"
                class="nav-item"
                data-view="../profile/profile.php"
            >
                <i class="nav-icon" data-lucide="circle-user-round" aria-hidden="true"></i><span>Profile</span>
            </button>

        </nav>

    </aside>

    <main class="main-content" id="mainContent">

        <section id="ordersView">

            <div class="page-container staff-orders">
                <div class="staff-welcome">
                    <p class="staff-welcome__eyebrow">YOUR WORKSPACE</p>
                    <h1 class="staff-welcome__title">Welcome, <?= $display_name ?></h1>
                    <p class="staff-welcome__subtitle">Review customer orders and keep pickup status up to date.</p>
                </div>
                <div class="page-header">
                    <h2 class="page-title">Today's orders</h2>
                    <p class="subtitle">
                        Manage today's active orders and pickup progress.
                    </p>
                </div>

                <div class="order-summary" aria-label="Order summary">
                    <article class="order-summary-card">
                        <span class="order-summary-label">Active orders</span>
                        <strong id="activeOrderCount">0</strong>
                        <span class="order-summary-note">Currently being handled</span>
                    </article>
                    <article class="order-summary-card">
                        <span class="order-summary-label">Ready for pickup</span>
                        <strong id="readyOrderCount">0</strong>
                        <span class="order-summary-note">Ready to leave the counter</span>
                    </article>
                    <article class="order-summary-card">
                        <span class="order-summary-label">Completed today</span>
                        <strong id="completedOrderCount">0</strong>
                        <span class="order-summary-note">Successfully fulfilled</span>
                    </article>
                </div>

                <div class="toolbar order-toolbar">
                    <div class="toolbar-filters">
                        <label class="order-search">
                            <span class="sr-only">Search orders</span>
                            <input class="toolbar-input" id="orderSearch" type="search" placeholder="Search order or customer">
                        </label>
                        <label>
                            <span class="sr-only">Filter order status</span>
                            <select class="toolbar-select" id="orderStatusFilter">
                                <option value="ALL">All statuses</option>
                                <option value="PENDING">Pending</option>
                                <option value="CONFIRMED">Confirmed</option>
                                <option value="PREPARING">Preparing</option>
                                <option value="READY">Ready</option>
                                <option value="COMPLETED">Completed</option>
                                <option value="CANCELLED">Cancelled</option>
                            </select>
                        </label>
                    </div>
                    <span class="order-count" id="orderCount"></span>
                </div>

                <div class="table-card order-board-card">
                    <div class="order-board" id="ordersBoard" aria-label="Orders by status"></div>
                    <div class="orders-empty hidden" id="ordersEmpty">
                        <span class="orders-empty-icon" aria-hidden="true"><i data-lucide="coffee"></i></span>
                        <p class="empty-title">No matching orders</p>
                        <p class="empty-hint">Try changing your search or status filter.</p>
                    </div>

                </div>
                <p class="demo-feedback" id="orderFeedback" role="status" aria-live="polite"></p>
            </div>
        </section>

        <section id="productsView" class="hidden">
            <div class="page-container staff-products">
                <div class="page-header">
                    <h1 class="page-title">Product availability</h1>
                    <p class="subtitle">Control which menu items customers can order right now.</p>
                </div>
                <div class="availability-overview">
                    <div>
                        <span class="availability-overview-label">Available to order</span>
                        <strong><span id="availableProductCount">0</span> <span class="availability-total">/ <span id="totalProductCount">0</span> products</span></strong>
                    </div>
                    <span class="availability-overview-icon" aria-hidden="true"><i data-lucide="circle-check"></i></span>
                </div>
                <div class="product-search-field">
                    <label class="sr-only" for="productSearch">Search products</label>
                    <input class="toolbar-input" id="productSearch" type="search" placeholder="Search products by name or category">
                </div>
                <div class="table-card product-table-card">
                    <div class="product-list-heading">
                        <div>
                            <h2>Menu items</h2>
                            <p>Turn an item off when it is temporarily unavailable.</p>
                        </div>
                        <div class="product-list-controls">
                            <label class="product-category-filter">
                                <span class="sr-only">Filter products by category</span>
                                <select class="toolbar-select" id="productCategoryFilter">
                                    <option value="ALL">All categories</option>
                                </select>
                            </label>
                            <span class="product-list-count" id="productListCount"></span>
                        </div>
                    </div>
                    <div class="product-availability-list" id="productAvailabilityList"></div>
                    <div class="orders-empty hidden" id="productsEmpty">
                        <p class="empty-title">No products in this category</p>
                    </div>
                    <div class="pagination-footer">
                        <p class="pagination-info" id="productsPageInfo"></p>
                        <div class="pagination-nav" id="productsPagination"></div>
                    </div>
                </div>
                <p class="demo-feedback" id="productFeedback" role="status" aria-live="polite"></p>
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

    <script>
        window.BREWSKI_CSRF = <?= json_encode($csrf_token) ?>;
        window.BREWSKI_ORDERS_API = <?= json_encode('orders_api.php') ?>;
    </script>

    <script
        src="../staff.js?v=<?= filemtime(__DIR__ . '/../staff.js') ?>"
        defer
    ></script>

    <script src="orders.js?v=<?= filemtime(__DIR__ . '/orders.js') ?>" defer></script>
    <?= brewski_session_timeout_markup() ?>

</body>

</html>

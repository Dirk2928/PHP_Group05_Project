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

            <div class="page-container staff-orders">
                <div class="page-header">
                    <h1 class="page-title">Orders</h1>
                    <p class="subtitle">
                        <?php if ($is_signed_in): ?>Signed in as <?= $display_name ?>. <?php endif; ?>
                        Review customer orders and keep delivery status up to date.
                    </p>
                </div>

                <div class="order-summary" aria-label="Order summary">
                    <article class="order-summary-card">
                        <span class="order-summary-label">Active orders</span>
                        <strong id="activeOrderCount">0</strong>
                        <span class="order-summary-note">Currently being handled</span>
                    </article>
                    <article class="order-summary-card">
                        <span class="order-summary-label">Ready for delivery</span>
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
                                <option value="PREPARING">Preparing</option>
                                <option value="READY">Ready</option>
                                <option value="DELIVERED">Delivered</option>
                                <option value="COMPLETED">Completed</option>
                            </select>
                        </label>
                    </div>
                    <span class="order-count" id="orderCount"></span>
                </div>

                <div class="table-card order-table-card">
                    <div class="order-table-scroll">
                        <table class="orders-table">
                            <thead>
                                <tr>
                                    <th>Order</th>
                                    <th>Customer</th>
                                    <th>Items</th>
                                    <th>Total</th>
                                    <th>Placed</th>
                                    <th>Status</th>
                                    <th>Next step</th>
                                </tr>
                            </thead>
                            <tbody id="ordersBody"></tbody>
                        </table>
                    </div>
                    <div class="orders-empty hidden" id="ordersEmpty">
                        <span class="orders-empty-icon" aria-hidden="true">☕</span>
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
                    <span class="availability-overview-icon" aria-hidden="true">✓</span>
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

    <script
        src="../staff.js?v=<?= filemtime(__DIR__ . '/../staff.js') ?>"
        defer
    ></script>

    <script src="orders.js?v=<?= filemtime(__DIR__ . '/orders.js') ?>" defer></script>

</body>

</html>
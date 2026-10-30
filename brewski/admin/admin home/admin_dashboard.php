<?php

require_once __DIR__ . '/../../login-signup/session_init.php';
require_once __DIR__ . '/../../Db/db_config.php';

brewski_require_role(['ADMIN']);

$profileUserId = (int) $_SESSION['user_id'];
$profileFirstName = (string) ($_SESSION['first_name'] ?? 'Admin');
$profileLastName = (string) ($_SESSION['last_name'] ?? '');
$profileEmail = (string) ($_SESSION['email'] ?? '');
$profileName = trim($profileFirstName . ' ' . $profileLastName) ?: 'Admin';
$profileInitials = strtoupper(
    mb_substr($profileFirstName, 0, 1) . mb_substr($profileLastName, 0, 1)
);
$profileInitials = $profileInitials !== '' ? $profileInitials : 'A';
$profileAvatarUrl = null;

try {
    $avatarConnection = brewski_db_connection();
    $avatarStatement = $avatarConnection->prepare(
        'SELECT avatar_mime_type, updated_at FROM users WHERE user_id = ? LIMIT 1'
    );
    if ($avatarStatement) {
        $avatarStatement->bind_param('i', $profileUserId);
        $avatarStatement->execute();
        $avatarStatement->bind_result($avatarMimeType, $avatarUpdatedAt);
        if ($avatarStatement->fetch() && $avatarMimeType) {
            $profileAvatarUrl = BREWSKI_BASE_URL . '/customer/user_avatar.php?id=' . $profileUserId
                . '&v=' . ($avatarUpdatedAt ? strtotime($avatarUpdatedAt) : time());
        }
        $avatarStatement->close();
    }
    $avatarConnection->close();
} catch (Throwable $error) {
    $profileAvatarUrl = null;
}

$escapeProfile = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Brewski Admin Dashboard</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../admin.css?v=<?= filemtime(__DIR__ . '/../admin.css') ?>">
    <link rel="stylesheet" href="dashboard.css?v=<?= filemtime(__DIR__ . '/dashboard.css') ?>">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
    <header class="topbar">
        <button type="button" id="sidebarToggle" class="icon-btn sidebar-toggle" aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
        <img class="topbar-logo" src="../../images/brewskilogo.png" alt="Brewski logo">
        <div class="profile-wrapper">
            <span class="profile-label">Admin</span>
            <button type="button" id="profileBtn" class="icon-btn profile-btn" aria-label="Open profile menu" aria-haspopup="true" aria-controls="profileMenu" aria-expanded="false">
                <?php if ($profileAvatarUrl): ?>
                    <img src="<?= $escapeProfile($profileAvatarUrl) ?>" alt="" class="profile-btn-avatar">
                <?php else: ?>
                    <span class="profile-btn-initials" aria-hidden="true"><?= $escapeProfile($profileInitials) ?></span>
                <?php endif; ?>
            </button>

            <div id="profileMenu" class="profile-menu profile-dropdown-panel hidden" aria-label="Profile menu">
                <div class="profile-user-summary">
                    <?php if ($profileAvatarUrl): ?>
                        <img src="<?= $escapeProfile($profileAvatarUrl) ?>" alt="" class="profile-summary-avatar">
                    <?php else: ?>
                        <span class="profile-summary-avatar profile-summary-initials" aria-hidden="true"><?= $escapeProfile($profileInitials) ?></span>
                    <?php endif; ?>
                    <span class="profile-summary-copy">
                        <strong><?= $escapeProfile($profileName) ?></strong>
                        <span><?= $escapeProfile($profileEmail) ?></span>
                    </span>
                </div>
                <a href="<?= $escapeProfile(BREWSKI_BASE_URL . '/admin/profile/profile.php') ?>" class="profile-menu-item">
                    <i data-lucide="user-round" aria-hidden="true"></i> My Profile
                </a>
                <a href="<?= $escapeProfile(brewski_logout_url()) ?>" class="profile-menu-item border-top">
                    <i data-lucide="log-out" aria-hidden="true"></i> Log out
                </a>
            </div>
        </div>
    </header>

    <aside id="sidebar" class="sidebar" aria-hidden="true">
        <div class="sidebar-brand">brewski</div>
        <nav class="sidebar-nav">
            <button type="button" class="nav-item active" data-view="home"><i class="nav-icon" data-lucide="layout-dashboard" aria-hidden="true"></i><span>Home</span></button>
            <button type="button" class="nav-item" data-view="../product%20management/product_management.php">
                <i class="nav-icon" data-lucide="coffee" aria-hidden="true"></i><span>Products</span>
            </button>
            <button type="button" class="nav-item nav-parent" id="customerParent" aria-expanded="false" aria-controls="customerSubmenu">
                <i class="nav-icon" data-lucide="users-round" aria-hidden="true"></i><span>Customer Management</span>
            </button>
            <div class="submenu" id="customerSubmenu">
                <button type="button" class="nav-item nav-subitem" data-view="../customer%20information/customer_information.php">
                    <i class="nav-icon" data-lucide="contact-round" aria-hidden="true"></i><span>Customer Information</span>
                </button>
                <button type="button" class="nav-item nav-subitem" data-view="../customer%20information/transactions.php">
                    <i class="nav-icon" data-lucide="receipt-text" aria-hidden="true"></i><span>Transactions</span>
                </button>
            </div>
            <button type="button" class="nav-item nav-parent" id="staffParent" aria-expanded="false" aria-controls="staffSubmenu">
                <i class="nav-icon" data-lucide="user-round-cog" aria-hidden="true"></i><span>Staff Management</span>
            </button>
            <div class="submenu" id="staffSubmenu">
                <button type="button" class="nav-item nav-subitem" data-view="../staff%20information/staff_information.php">
                    <i class="nav-icon" data-lucide="badge-info" aria-hidden="true"></i><span>Staff Information</span>
                </button>
            </div>
            <button type="button" class="nav-item" data-view="../logs/system_activity.php"><i class="nav-icon" data-lucide="activity" aria-hidden="true"></i><span>System Activity</span></button>
            <button type="button" class="nav-item" data-view="../system settings/settings.php"><i class="nav-icon" data-lucide="settings-2" aria-hidden="true"></i><span>System Settings</span></button>
            <button type="button" class="nav-item" data-view="../profile/profile.php"><i class="nav-icon" data-lucide="circle-user-round" aria-hidden="true"></i><span>Profile</span></button>
        </nav>
    </aside>

    <main class="main-content" id="mainContent">
        <section id="homeView" class="dashboard">
            <div class="dashboard-heading">
                <div>
                    <p class="dashboard-eyebrow">STORE OVERVIEW</p>
                    <h1>Welcome, <?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?></h1>
                    <p class="dashboard-subtitle">Sales, orders, and store activity at a glance.</p>
                </div>
            </div>

            <p id="dashboardMessage" class="dashboard-message hidden" role="alert"></p>

            <div class="dashboard-toolbar" aria-label="Analytics actions">
                <span class="dashboard-toolbar-label">ANALYTICS</span>
                <div class="export-actions">
                    <button type="button" class="dashboard-button dashboard-button-primary" id="printReport" title="Print this report or save it as a PDF">Print</button>
                </div>
            </div>

            <section class="dashboard-section" aria-labelledby="kpiHeading">
                <div class="section-heading">
                    <div>
                        <h2 id="kpiHeading">Today at a glance</h2>
                        <p>Key numbers for your store today</p>
                    </div>
                </div>
                <div class="kpi-grid">
                    <article class="kpi-card">
                        <div class="kpi-topline"><span class="kpi-icon kpi-icon-sales" aria-hidden="true">₱</span><span id="salesChange" class="kpi-change">—</span></div>
                        <p class="kpi-label">Today's sales</p>
                        <p id="todaySales" class="kpi-value">Loading…</p>
                        <p class="kpi-note">vs. yesterday</p>
                    </article>
                    <article class="kpi-card">
                        <div class="kpi-topline"><span class="kpi-icon kpi-icon-orders" aria-hidden="true">#</span><span id="ordersChange" class="kpi-change">—</span></div>
                        <p class="kpi-label">Number of orders</p>
                        <p id="todayOrders" class="kpi-value">Loading…</p>
                        <p class="kpi-note">orders today</p>
                    </article>
                    <article class="kpi-card">
                        <div class="kpi-topline"><span class="kpi-icon kpi-icon-average" aria-hidden="true">↗</span></div>
                        <p class="kpi-label">Average order value</p>
                        <p id="averageOrderValue" class="kpi-value">Loading…</p>
                        <p class="kpi-note">completed orders today</p>
                    </article>
                    <article class="kpi-card">
                        <div class="kpi-topline"><span class="kpi-icon kpi-icon-active" aria-hidden="true">◷</span><span class="kpi-status">Open</span></div>
                        <p class="kpi-label">Open orders</p>
                        <p id="openOrders" class="kpi-value">Loading…</p>
                        <p id="openOrdersNote" class="kpi-note">pending, confirmed, preparing, and ready</p>
                    </article>
                </div>
            </section>

            <section class="dashboard-section orders-section" aria-labelledby="ordersHeading">
                <div class="section-heading">
                    <div><h2 id="ordersHeading">Live order queue</h2><p>Current orders across the store</p></div>
                    <span class="live-indicator"><i></i> Live</span>
                </div>
                <div class="table-scroll">
                    <table class="dashboard-table">
                        <thead>
                            <tr><th>Order</th><th>Time</th><th>Items</th><th>Total</th><th>Status</th></tr>
                        </thead>
                        <tbody id="ordersTable"></tbody>
                    </table>
                </div>
                <div class="pagination-footer dashboard-pagination">
                    <p class="pagination-info" id="ordersPageInfo"></p>
                    <div class="pagination-nav" id="ordersPagination"></div>
                </div>
            </section>

            <div class="dashboard-grid dashboard-grid-middle">
                <section class="dashboard-section trend-section" aria-labelledby="trendHeading">
                    <div class="section-heading section-heading-wrap">
                        <div>
                            <h2 id="trendHeading">Sales trend</h2>
                            <p>Track revenue across your selected date range</p>
                        </div>
                        <div class="trend-options">
                            <label class="select-control">
                                <span class="visually-hidden">Sales trend interval</span>
                                <select id="trendInterval">
                                    <option value="hour">By hour</option>
                                    <option value="day" selected>By day</option>
                                    <option value="week">By week</option>
                                    <option value="month">By month</option>
                                    <option value="year">By year</option>
                                </select>
                            </label>
                        </div>
                    </div>
                    <div class="analytics-range-control" aria-label="Sales trend date range">
                        <label><span>Range by</span><select id="trendRangeUnit"><option value="month">Month</option><option value="year">Year</option></select></label>
                        <label><span>From</span><input type="month" id="trendRangeFrom" aria-label="Sales trend start"></label>
                        <label><span>To</span><input type="month" id="trendRangeTo" aria-label="Sales trend end"></label>
                    </div>
                    <div class="chart-summary">
                        <div><span class="chart-summary-label">Revenue</span><strong id="trendTotal">₱0</strong></div>
                        <span class="chart-legend"><i></i> Selected period</span>
                    </div>
                    <div class="sales-chart-wrap">
                        <svg id="salesChart" class="sales-chart" role="img" aria-label="Sales trend chart"></svg>
                    </div>

                    <div class="insight-grid">
                        <section class="insight-card" aria-labelledby="peakHeading">
                            <div class="insight-heading">
                                <div><h3 id="peakHeading">Peak hours</h3><p>Orders by day and time</p></div>
                                <span id="peakHours" class="peak-time">—</span>
                            </div>
                            <div class="analytics-range-control analytics-range-control--compact" aria-label="Peak hours date range">
                                <label><span>Range by</span><select id="peakRangeUnit"><option value="month">Month</option><option value="year">Year</option></select></label>
                                <label><span>From</span><input type="month" id="peakRangeFrom" aria-label="Peak hours start"></label>
                                <label><span>To</span><input type="month" id="peakRangeTo" aria-label="Peak hours end"></label>
                            </div>
                            <div class="heatmap-scroll">
                                <div id="peakHeatmap" class="heatmap" role="img" aria-label="Peak hours order heatmap"></div>
                            </div>
                            <div class="heatmap-legend"><span>Less busy</span><i></i><i></i><i></i><i></i><span>Busier</span></div>
                        </section>
                        <section class="insight-card" aria-labelledby="categoryHeading">
                            <div class="insight-heading">
                                <div><h3 id="categoryHeading">Sales breakdown</h3><p>Revenue by category</p></div>
                            </div>
                            <div class="analytics-range-control analytics-range-control--compact" aria-label="Sales breakdown date range">
                                <label><span>Range by</span><select id="categoryRangeUnit"><option value="month">Month</option><option value="year">Year</option></select></label>
                                <label><span>From</span><input type="month" id="categoryRangeFrom" aria-label="Sales breakdown start"></label>
                                <label><span>To</span><input type="month" id="categoryRangeTo" aria-label="Sales breakdown end"></label>
                            </div>
                            <div id="categoryBreakdown" class="category-breakdown"></div>
                        </section>
                    </div>
                </section>

                <div class="dashboard-side-stack">
                    <section class="dashboard-section sellers-section" aria-labelledby="sellersHeading">
                        <div class="section-heading">
                            <div><h2 id="sellersHeading">Best sellers</h2><p>Top items by units sold</p></div>
                            <span class="section-tag">Top 5</span>
                        </div>
                        <div class="analytics-range-control analytics-range-control--compact" aria-label="Best sellers date range">
                            <label><span>Range by</span><select id="sellerRangeUnit"><option value="month">Month</option><option value="year">Year</option></select></label>
                            <label><span>From</span><input type="month" id="sellerRangeFrom" aria-label="Best sellers start"></label>
                            <label><span>To</span><input type="month" id="sellerRangeTo" aria-label="Best sellers end"></label>
                        </div>
                        <div id="bestSellers" class="seller-list"></div>
                    </section>

                    <section class="dashboard-section alerts-section" aria-labelledby="alertsHeading">
                        <div class="section-heading">
                            <div><h2 id="alertsHeading">Alerts</h2><p>Low inventory and pending orders</p></div>
                            <span id="alertCount" class="alert-count">0</span>
                        </div>
                        <div id="alertsList" class="alerts-list"></div>
                    </section>
                </div>
            </div>

        </section>

        <section id="dynamicView" class="hidden"></section>
    </main>

    <script src="admin.js?v=<?= filemtime(__DIR__ . '/admin.js') ?>" defer></script>
    <script src="dashboard.js?v=<?= filemtime(__DIR__ . '/dashboard.js') ?>" defer></script>
    <?= brewski_session_timeout_markup() ?>
</body>
</html>

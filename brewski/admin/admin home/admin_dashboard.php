<?php

require_once __DIR__ . '/../../login-signup/session_init.php';

brewski_require_role(['ADMIN']);

$adminName = $_SESSION['first_name'] ?? 'Admin';
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
</head>
<body>
    <header class="topbar">
        <img class="topbar-logo" src="../../images/brewskilogo.png" alt="Brewski logo">
        <div class="profile-wrapper">
            <span class="profile-label">Admin</span>
            <button type="button" id="profileBtn" class="icon-btn profile-btn" aria-label="Open profile menu" aria-expanded="false"></button>

            <div id="profileMenu" class="profile-menu hidden">
                <a href="../../login-signup/logout.php" class="profile-menu-item border-top">Log out</a>
            </div>
        </div>
    </header>

    <aside id="sidebar" class="sidebar">
        <div class="sidebar-brand">brewski</div>
        <nav class="sidebar-nav">
            <button type="button" class="nav-item active" data-view="home">Home</button>
            <button type="button" class="nav-item" data-view="../product%20management/product_management.php">
                Beverage Menu
            </button>
            <button type="button" class="nav-item nav-parent" id="customerParent" aria-expanded="false" aria-controls="customerSubmenu">
                Customer Management
            </button>
            <div class="submenu" id="customerSubmenu">
                <button type="button" class="nav-item nav-subitem" data-view="../customer%20information/customer_information.php">
                    Customer Information
                </button>
                <button type="button" class="nav-item nav-subitem" data-view="../customer%20information/transactions.php">
                    Transactions
                </button>
            </div>
            <button type="button" class="nav-item nav-parent" id="staffParent" aria-expanded="false" aria-controls="staffSubmenu">
                Staff Management
            </button>
            <div class="submenu" id="staffSubmenu">
                <button type="button" class="nav-item nav-subitem" data-view="../staff%20information/staff_information.php">
                    Staff Information
                </button>
                <button type="button" class="nav-item nav-subitem" data-view="../staff%20information/transactions_handled.php">
                    Transactions Handled
                </button>
            </div>
            <button type="button" class="nav-item" data-view="../logs/authentication_logs.php">Activity Logs</button>
            <button type="button" class="nav-item" data-view="../system settings/settings.php">System Settings</button>
            <button type="button" class="nav-item" data-view="profile">Profile</button>
        </nav>
    </aside>

    <main class="main-content" id="mainContent">
        <section id="homeView" class="dashboard">
            <div class="dashboard-toolbar" aria-label="Dashboard filters and exports">
                <div class="date-range-control">
                    <label>
                        <span>From</span>
                        <input type="date" id="dateFrom" aria-label="Start date">
                    </label>
                    <label>
                        <span>To</span>
                        <input type="date" id="dateTo" aria-label="End date">
                    </label>
                </div>
                <label class="comparison-control">
                    <input type="checkbox" id="compareToggle">
                    <span>Compare</span>
                    <select id="comparePeriod" aria-label="Comparison period" disabled>
                        <option value="week">Last week</option>
                        <option value="month">Last month</option>
                    </select>
                </label>
                <div class="export-actions">
                    <button type="button" class="dashboard-button dashboard-button-secondary" id="exportCsv">Export CSV</button>
                    <button type="button" class="dashboard-button dashboard-button-primary" id="exportPdf">Export PDF</button>
                </div>
            </div>

            <div class="dashboard-heading">
                <div>
                    <p class="dashboard-eyebrow">STORE OVERVIEW</p>
                    <h1>Welcome, @<?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?></h1>
                    <p class="dashboard-subtitle">Sales, orders, and store activity at a glance.</p>
                </div>
                <span class="demo-badge"><span aria-hidden="true"></span> Demo data</span>
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
                        <div class="kpi-topline"><span class="kpi-icon kpi-icon-sales" aria-hidden="true">₱</span><span class="kpi-change positive">+12.8%</span></div>
                        <p class="kpi-label">Today's sales</p>
                        <p class="kpi-value">₱18,420.00</p>
                        <p class="kpi-note">vs. yesterday</p>
                    </article>
                    <article class="kpi-card">
                        <div class="kpi-topline"><span class="kpi-icon kpi-icon-orders" aria-hidden="true">#</span><span class="kpi-change positive">+8.3%</span></div>
                        <p class="kpi-label">Number of orders</p>
                        <p class="kpi-value">86</p>
                        <p class="kpi-note">orders today</p>
                    </article>
                    <article class="kpi-card">
                        <div class="kpi-topline"><span class="kpi-icon kpi-icon-average" aria-hidden="true">↗</span><span class="kpi-change positive">+4.1%</span></div>
                        <p class="kpi-label">Average order value</p>
                        <p class="kpi-value">₱214.19</p>
                        <p class="kpi-note">per completed order</p>
                    </article>
                    <article class="kpi-card">
                        <div class="kpi-topline"><span class="kpi-icon kpi-icon-active" aria-hidden="true">◷</span><span class="kpi-status">Needs attention</span></div>
                        <p class="kpi-label">Active / pending orders</p>
                        <p class="kpi-value">12</p>
                        <p class="kpi-note">3 pending · 9 in progress</p>
                    </article>
                </div>
            </section>

            <div class="dashboard-grid dashboard-grid-middle">
                <section class="dashboard-section trend-section" aria-labelledby="trendHeading">
                    <div class="section-heading section-heading-wrap">
                        <div>
                            <h2 id="trendHeading">Sales trend</h2>
                            <p>Track revenue across your selected date range</p>
                        </div>
                        <label class="select-control">
                            <span class="visually-hidden">Sales trend interval</span>
                            <select id="trendInterval">
                                <option value="hour">By hour</option>
                                <option value="day" selected>By day</option>
                                <option value="week">By week</option>
                            </select>
                        </label>
                    </div>
                    <div class="chart-summary">
                        <div><span class="chart-summary-label">Revenue</span><strong id="trendTotal">₱0</strong></div>
                        <span class="chart-legend"><i></i> Selected period <i class="legend-previous"></i> Previous period</span>
                    </div>
                    <div class="sales-chart-wrap">
                        <svg id="salesChart" class="sales-chart" role="img" aria-label="Sales trend chart"></svg>
                    </div>

                    <div class="insight-grid">
                        <section class="insight-card" aria-labelledby="peakHeading">
                            <div class="insight-heading">
                                <div><h3 id="peakHeading">Peak hours</h3><p>Orders by day and time</p></div>
                                <span class="peak-time">12 PM – 2 PM</span>
                            </div>
                            <div class="heatmap-scroll">
                                <div id="peakHeatmap" class="heatmap" role="img" aria-label="Peak hours order heatmap"></div>
                            </div>
                            <div class="heatmap-legend"><span>Less busy</span><i></i><i></i><i></i><i></i><span>Busier</span></div>
                        </section>
                        <section class="insight-card" aria-labelledby="categoryHeading">
                            <div class="insight-heading">
                                <div><h3 id="categoryHeading">Sales breakdown</h3><p>Revenue by category</p></div>
                                <span class="total-caption">Today</span>
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
                        <div id="bestSellers" class="seller-list"></div>
                    </section>

                    <section class="dashboard-section alerts-section" aria-labelledby="alertsHeading">
                        <div class="section-heading">
                            <div><h2 id="alertsHeading">Alerts</h2><p>Items that may need your attention</p></div>
                            <span id="alertCount" class="alert-count">0</span>
                        </div>
                        <div id="alertsList" class="alerts-list"></div>
                    </section>
                </div>
            </div>

            <div class="dashboard-grid dashboard-grid-bottom">
                <section class="dashboard-section orders-section" aria-labelledby="ordersHeading">
                    <div class="section-heading">
                        <div><h2 id="ordersHeading">Live order queue</h2><p>Recent orders across the store</p></div>
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
                </section>
                <section class="dashboard-section stock-section" aria-labelledby="stockHeading">
                    <div class="section-heading">
                        <div><h2 id="stockHeading">Low stock</h2><p>Restock these items soon</p></div>
                        <span class="section-tag section-tag-warning">4 items</span>
                    </div>
                    <div id="lowStockList" class="stock-list"></div>
                </section>
            </div>
        </section>

        <section id="dynamicView" class="hidden"></section>

        <section id="placeholderView" class="hidden">
            <h1 id="placeholderTitle">Page Not Found</h1>
            <p class="subtitle">This section has not been implemented yet.</p>
        </section>
    </main>

    <script src="admin.js?v=<?= filemtime(__DIR__ . '/admin.js') ?>" defer></script>
    <script src="dashboard.js?v=<?= filemtime(__DIR__ . '/dashboard.js') ?>" defer></script>
</body>
</html>

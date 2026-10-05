<?php

require_once __DIR__ . '/../../login-signup/session_init.php';

brewski_require_role(['ADMIN']);

$adminName = $_SESSION['first_name'] ?? 'Admin';

$stats = [
    'total_customers' => 0,
    'today_sales' => 0,
    'pending_orders' => 0,
    'low_stock' => 0,
];

$mysqli = null;
$hasDbConnection = false;

try {
    $mysqli = new mysqli('localhost', 'root', '', 'brewski_db');
    $hasDbConnection = true;

    $customerResult = $mysqli->query("SELECT COUNT(*) AS total FROM users WHERE role = 'CUSTOMER'");
    if ($customerResult && $customerResult->num_rows > 0) {
        $stats['total_customers'] = (int) $customerResult->fetch_assoc()['total'];
    }

    $salesResult = $mysqli->query("SELECT COALESCE(SUM(total_amount), 0) AS total FROM orders WHERE DATE(order_date) = CURDATE()");
    if ($salesResult && $salesResult->num_rows > 0) {
        $stats['today_sales'] = (float) $salesResult->fetch_assoc()['total'];
    }

    $pendingResult = $mysqli->query("SELECT COUNT(*) AS total FROM orders WHERE order_status = 'PENDING'");
    if ($pendingResult && $pendingResult->num_rows > 0) {
        $stats['pending_orders'] = (int) $pendingResult->fetch_assoc()['total'];
    }

    $stockResult = $mysqli->query("SELECT COUNT(*) AS total FROM products WHERE stock < 10");
    if ($stockResult && $stockResult->num_rows > 0) {
        $stats['low_stock'] = (int) $stockResult->fetch_assoc()['total'];
    }
} catch (Exception $e) {
    $hasDbConnection = false;
}

if ($mysqli) {
    $mysqli->close();
}
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
</head>
<body>


    <header class="topbar">
        <img class="topbar-logo" src="../../images/brewskilogo.png" alt="Brewski logo">
        <div class="profile-wrapper">
            <span class="profile-label">Admin</span>
            <button type="button" id="profileBtn" class="icon-btn profile-btn" aria-label="Open profile menu" aria-expanded="false"></button>

            <div id="profileMenu" class="profile-menu hidden">
                <button type="button" class="profile-menu-item">Profile</button>
                <button type="button" class="profile-menu-item border-top">Log out</button>
            </div>
        </div>
    </header>


    <aside id="sidebar" class="sidebar">
        <div class="sidebar-brand">brewski</div>
        <nav class="sidebar-nav">

            <button type="button" class="nav-item active" data-view="home">Home</button>


            <button type="button" class="nav-item" data-view="../product%20management/product_management.php">
                Product Management
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

            <button type="button" class="nav-item" data-view="../logs/authentication_logs.php">Authentication Logs</button>

            <button type="button" class="nav-item" data-view="profile">Profile</button>
        </nav>
    </aside>


    <main class="main-content" id="mainContent">


        <section id="homeView">
            <h1>Welcome, @<?= htmlspecialchars($adminName) ?></h1>
            <p class="subtitle">This page is for the overview of Brewski store activity.</p>

            <div class="stats-grid">
                <div class="stat-card">
                    <p class="stat-label">Total Customers</p>
                    <p class="stat-value"><?= $hasDbConnection ? number_format($stats['total_customers']) : '--' ?></p>
                </div>
                <div class="stat-card">
                    <p class="stat-label">Today's Sales</p>
                    <p class="stat-value"><?= $hasDbConnection ? '₱' . number_format($stats['today_sales'], 2) : '--' ?></p>
                </div>
                <div class="stat-card">
                    <p class="stat-label">Pending Orders</p>
                    <p class="stat-value"><?= $hasDbConnection ? number_format($stats['pending_orders']) : '--' ?></p>
                </div>
                <div class="stat-card">
                    <p class="stat-label">Low Stock Items</p>
                    <p class="stat-value"><?= $hasDbConnection ? number_format($stats['low_stock']) : '--' ?></p>
                </div>
            </div>
        </section>


        <section id="dynamicView" class="hidden">

        </section>


        <section id="placeholderView" class="hidden">
            <h1 id="placeholderTitle">Page Not Found</h1>
            <p class="subtitle">This section has not been implemented yet.</p>
        </section>

    </main>



    <script src="admin.js?v=<?= filemtime(__DIR__ . '/admin.js') ?>" defer></script>
</body>
</html>
<?php

require_once __DIR__ . '/../partials/bootstrap.php';
require_once __DIR__ . '/../../Db/connection.php';
require_once __DIR__ . '/../partials/order_support.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../../login-signup/login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$statuses = ['PENDING', 'CONFIRMED', 'PREPARING', 'READY', 'COMPLETED', 'CANCELLED'];
$status = strtoupper(trim((string) ($_GET['status'] ?? 'ALL')));
if ($status !== 'ALL' && !in_array($status, $statuses, true)) {
    $status = 'ALL';
}

$search = trim((string) ($_GET['order'] ?? ''));
$searchId = $search !== '' ? filter_var($search, FILTER_VALIDATE_INT) : null;
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = $page !== false && $page > 0 ? $page : 1;
$perPage = 10;

$conditions = ['o.user_id = ?'];
$parameters = [$userId];

if ($status !== 'ALL') {
    $conditions[] = 'o.order_status = ?';
    $parameters[] = $status;
}

if ($search !== '') {
    if ($searchId === false || $searchId <= 0) {
        $conditions[] = '1 = 0';
    } else {
        $conditions[] = 'o.order_id = ?';
        $parameters[] = (int) $searchId;
    }
}

$where = implode(' AND ', $conditions);
$countStatement = $pdo->prepare("SELECT COUNT(*) FROM orders o WHERE $where");
$countStatement->execute($parameters);
$totalOrders = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($totalOrders / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$ordersStatement = $pdo->prepare(
    "SELECT o.order_id, o.order_date, o.total_amount, o.order_status,
            op.payment_status, op.provider AS payment_provider,
            (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.order_id) AS item_count,
            (SELECT p.product_name
               FROM order_items oi
               JOIN products p ON p.product_id = oi.product_id
              WHERE oi.order_id = o.order_id
              ORDER BY oi.order_item_id ASC
              LIMIT 1) AS first_item
       FROM orders o
       LEFT JOIN order_payments op ON op.order_id = o.order_id
      WHERE $where
      ORDER BY o.order_date DESC, o.order_id DESC
      LIMIT $perPage OFFSET $offset"
);
$ordersStatement->execute($parameters);
$orders = $ordersStatement->fetchAll();

$countStatement = $pdo->prepare(
    'SELECT order_status, COUNT(*) AS order_count
       FROM orders
      WHERE user_id = ?
      GROUP BY order_status'
);
$countStatement->execute([$userId]);
$statusCounts = array_fill_keys($statuses, 0);
foreach ($countStatement->fetchAll() as $row) {
    $statusCounts[$row['order_status']] = (int) $row['order_count'];
}

$pageTitle = 'My orders | brewski';
$active = 'orders';
$extraStyles = ['customer_orders/orders.css'];
require __DIR__ . '/../partials/header.php';

$statusLabels = [
    'PENDING' => 'Pending',
    'CONFIRMED' => 'Confirmed',
    'PREPARING' => 'Preparing',
    'READY' => 'Ready',
    'COMPLETED' => 'Completed',
    'CANCELLED' => 'Cancelled',
];
$statusIcons = [
    'PENDING' => 'clock-3',
    'CONFIRMED' => 'badge-check',
    'PREPARING' => 'chef-hat',
    'READY' => 'package-check',
    'COMPLETED' => 'circle-check',
    'CANCELLED' => 'circle-x',
];
?>

<main class="orders-page">
    <div class="orders-heading">
        <div>
            <h1>My orders</h1>
            <p>Check the latest update or revisit the details of a previous order.</p>
        </div>
        <a class="orders-menu-link" href="../customer_menu/customermenu.php">
            <i data-lucide="coffee" aria-hidden="true"></i>
            Browse the menu
            <i data-lucide="arrow-up-right" aria-hidden="true"></i>
        </a>
    </div>

    <form class="orders-search" method="get" action="orders.php">
        <?php if ($status !== 'ALL'): ?>
            <input type="hidden" name="status" value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
        <?php endif; ?>
        <label for="order-search"><i data-lucide="search" aria-hidden="true"></i> Find an order</label>
        <div class="orders-search__row">
            <div class="orders-search__field">
                <i class="orders-search__icon" data-lucide="hash" aria-hidden="true"></i>
                <input
                    id="order-search"
                    type="search"
                    name="order"
                    value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="Enter an order number"
                    inputmode="numeric"
                    pattern="[0-9]*"
                >
            </div>
            <button type="submit"><i data-lucide="search" aria-hidden="true"></i> Search</button>
            <?php if ($search !== ''): ?>
                <a href="orders.php<?= $status !== 'ALL' ? '?status=' . urlencode($status) : '' ?>">
                    <i data-lucide="x" aria-hidden="true"></i> Clear
                </a>
            <?php endif; ?>
        </div>
    </form>

    <nav class="orders-filters" aria-label="Filter orders by status">
        <a class="<?= $status === 'ALL' ? 'is-active' : '' ?>" href="orders.php">
            <i data-lucide="receipt" aria-hidden="true"></i>
            All <span><?= array_sum($statusCounts) ?></span>
        </a>
        <?php foreach ($statuses as $filterStatus): ?>
            <a
                class="<?= $status === $filterStatus ? 'is-active' : '' ?>"
                href="orders.php?status=<?= urlencode($filterStatus) ?>"
            >
                <i data-lucide="<?= htmlspecialchars($statusIcons[$filterStatus], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                <?= htmlspecialchars($statusLabels[$filterStatus], ENT_QUOTES, 'UTF-8') ?>
                <span><?= $statusCounts[$filterStatus] ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($orders): ?>
        <div class="orders-list">
            <?php foreach ($orders as $order): ?>
                <?php
                    $orderStatus = (string) $order['order_status'];
                    $paymentStatus = (string) ($order['payment_status'] ?? '');
                    $storePickup = ($order['payment_provider'] ?? '') === 'in_store';
                    $itemCount = (int) $order['item_count'];
                ?>
                <article class="order-card">
                    <div class="order-card__top">
                        <div>
                            <span class="order-card__number">Order #<?= (int) $order['order_id'] ?></span>
                            <time datetime="<?= htmlspecialchars(date(DATE_ATOM, strtotime($order['order_date'])), ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars(date('M j, Y · g:i A', strtotime($order['order_date'])), ENT_QUOTES, 'UTF-8') ?>
                            </time>
                        </div>
                        <span class="order-status order-status--<?= strtolower($orderStatus) ?>">
                            <i data-lucide="<?= htmlspecialchars($statusIcons[$orderStatus] ?? 'circle-help', ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                            <?= htmlspecialchars($statusLabels[$orderStatus] ?? $orderStatus, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>
                    <?php if ($paymentStatus === 'PENDING' && !$storePickup): ?>
                        <p class="orders-notice" role="status">
                            Payment pending. This order will be sent to the café after payment is confirmed.
                        </p>
                    <?php elseif ($paymentStatus === 'FAILED'): ?>
                        <p class="orders-notice orders-notice--error" role="status">
                            Payment was not completed. Your saved cart is available at checkout.
                        </p>
                    <?php endif; ?>
                    <?php if ($storePickup && $paymentStatus === 'PENDING'): ?>
                        <p class="orders-notice" role="status">Pay cash when you pick up your order at the store.</p>
                    <?php endif; ?>

                    <div class="order-card__summary">
                        <div class="order-card__items">
                            <span class="order-card__icon" aria-hidden="true"><i data-lucide="coffee"></i></span>
                            <div>
                                <strong><?= htmlspecialchars((string) ($order['first_item'] ?? 'Order items'), ENT_QUOTES, 'UTF-8') ?></strong>
                                <span><i data-lucide="package" aria-hidden="true"></i> <?= $itemCount ?> <?= $itemCount === 1 ? 'item' : 'items' ?> in this order</span>
                            </div>
                        </div>
                        <div class="order-card__total">
                            <span><i data-lucide="wallet" aria-hidden="true"></i> Total</span>
                            <strong>₱<?= number_format((float) $order['total_amount'], 2) ?></strong>
                        </div>
                    </div>

                    <div class="order-card__bottom">
                        <span class="order-pickup">
                            <i data-lucide="store" aria-hidden="true"></i>
                            Store pickup
                        </span>
                        <a href="track_order.php?order_id=<?= (int) $order['order_id'] ?>">
                            View order <i data-lucide="arrow-right" aria-hidden="true"></i>
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <nav class="orders-pagination" aria-label="Orders pages">
            <span class="orders-pagination__count">
                <?= $totalOrders === 0
                    ? 'No orders'
                    : 'Showing ' . (($page - 1) * $perPage + 1) . ' to ' . min($page * $perPage, $totalOrders) . ' of ' . $totalOrders . ' orders' ?>
            </span>

            <div class="orders-pagination__pages">
                <?php if ($page > 1): ?>
                    <a href="?<?= http_build_query(['status' => $status, 'order' => $search, 'page' => $page - 1]) ?>"><i data-lucide="arrow-left" aria-hidden="true"></i> Newer</a>
                <?php endif; ?>

                <?php if ($totalPages > 1): ?>
                    <span>Page <?= $page ?> of <?= $totalPages ?></span>
                <?php endif; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?<?= http_build_query(['status' => $status, 'order' => $search, 'page' => $page + 1]) ?>">Older <i data-lucide="arrow-right" aria-hidden="true"></i></a>
                <?php endif; ?>
            </div>
        </nav>
    <?php else: ?>
        <section class="orders-empty">
            <span class="orders-empty__icon" aria-hidden="true"><i data-lucide="<?= $search !== '' ? 'search-x' : 'coffee' ?>"></i></span>
            <h2><?= $search !== '' ? 'No matching order found' : ($status === 'ALL' ? 'Your next favorite is waiting' : 'No orders in this category') ?></h2>
            <p>
                <?= $search !== ''
                    ? 'Double-check the order number and try again.'
                    : ($status === 'ALL' ? 'When you place an order, its updates will show up here.' : 'Try another status filter or browse the menu.') ?>
            </p>
            <a href="../customer_menu/customermenu.php"><i data-lucide="coffee" aria-hidden="true"></i> Explore the menu <i data-lucide="arrow-right" aria-hidden="true"></i></a>
        </section>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/../partials/footer.php'; ?>

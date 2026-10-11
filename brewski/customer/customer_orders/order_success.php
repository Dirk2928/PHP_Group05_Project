<?php

require_once __DIR__ . '/../partials/bootstrap.php';
require_once __DIR__ . '/../../Db/connection.php';
require_once __DIR__ . '/../partials/order_support.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../../login-signup/login.php');
    exit;
}

$orderId = (int) ($_GET['order_id'] ?? 0);
$userId  = (int) $_SESSION['user_id'];
$hasDeliveries = customer_has_deliveries_table($pdo);
$deliverySelect = $hasDeliveries ? 'd.delivery_status' : 'NULL AS delivery_status';
$deliveryJoin = $hasDeliveries ? 'LEFT JOIN deliveries d ON d.order_id = o.order_id' : '';

$stmt = $pdo->prepare(
    "SELECT o.order_id, o.total_amount, o.order_status, $deliverySelect
     FROM orders o
     $deliveryJoin
     WHERE o.order_id = ? AND o.user_id = ?"
);
$stmt->execute([$orderId, $userId]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: ../customer_home/customerhome.php');
    exit;
}

$pageTitle = 'Order placed | brewski';
$extraStyles = ['customer_orders/orders.css'];
require __DIR__ . '/../partials/header.php';
?>

<main class="order-success-page">
    <section class="order-success-card">
        <div class="order-success-hero">
            <div class="order-success-mark" aria-hidden="true">
                <i data-lucide="circle-check"></i>
            </div>
            <p class="orders-eyebrow">Thanks for choosing brewski</p>
            <h1>Order placed!</h1>
            <p class="order-success-message">
                Order <strong>#<?= (int)$order['order_id'] ?></strong> is in. We’ll keep you updated as it moves along.
            </p>
        </div>

    <?php if (!$hasDeliveries): ?>
        <p class="order-success-notice" role="status">
            <i data-lucide="info" aria-hidden="true"></i>
            <span>Delivery tracking is not set up yet. You can still view your order details and status.</span>
        </p>
    <?php endif; ?>

        <div class="order-success-details" aria-label="Order summary">
            <div class="order-success-detail">
                <span class="order-success-detail__icon"><i data-lucide="receipt" aria-hidden="true"></i></span>
                <span class="order-success-detail__copy"><small>Order status</small><strong><?= htmlspecialchars($order['order_status']) ?></strong></span>
            </div>
            <div class="order-success-detail">
                <span class="order-success-detail__icon"><i data-lucide="bike" aria-hidden="true"></i></span>
                <span class="order-success-detail__copy"><small>Delivery</small><strong><?= htmlspecialchars($order['delivery_status'] ?? ($hasDeliveries ? 'UNASSIGNED' : 'Not available')) ?></strong></span>
            </div>
            <div class="order-success-detail order-success-detail--total">
                <span class="order-success-detail__icon"><i data-lucide="wallet" aria-hidden="true"></i></span>
                <span class="order-success-detail__copy"><small>Total paid</small><strong>₱<?= number_format((float)$order['total_amount'], 2) ?></strong></span>
            </div>
        </div>

        <div class="order-success-actions">
            <a class="order-success-button order-success-button--primary" href="track_order.php?order_id=<?= (int)$order['order_id'] ?>">
                <i data-lucide="map-pin" aria-hidden="true"></i>
                View order details
                <i data-lucide="arrow-right" aria-hidden="true"></i>
            </a>
            <a class="order-success-button order-success-button--secondary" href="../customer_menu/customermenu.php">
                <i data-lucide="coffee" aria-hidden="true"></i>
                Back to menu
            </a>
        </div>
    </section>
</main>

<script>
    if (window.lucide) {
        window.lucide.createIcons();
    }
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
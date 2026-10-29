<?php

require_once __DIR__ . '/../partials/bootstrap.php';
require_once __DIR__ . '/../../Db/connection.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../../login-signup/login.php');
    exit;
}

$orderId = filter_var($_GET['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($orderId === false) {
    header('Location: orders.php');
    exit;
}

$statement = $pdo->prepare(
    'SELECT o.order_id, o.order_date, o.total_amount, o.order_status, o.order_notes,
            op.payment_status, op.provider AS payment_provider
       FROM orders o
       LEFT JOIN order_payments op ON op.order_id = o.order_id
      WHERE o.order_id = ? AND o.user_id = ?
      LIMIT 1'
);
$statement->execute([(int) $orderId, (int) $_SESSION['user_id']]);
$order = $statement->fetch();
if (!$order) {
    header('Location: orders.php');
    exit;
}

$pageTitle = 'Order details | brewski';
$extraStyles = ['customer_orders/orders.css'];
require __DIR__ . '/../partials/header.php';
?>
<main class="orders-page">
    <div class="orders-heading">
        <div>
            <h1>Order #<?= (int) $order['order_id'] ?></h1>
            <p>Pickup at the café. Follow your order status below.</p>
        </div>
        <a class="orders-menu-link" href="orders.php">Back to my orders</a>
    </div>
    <section class="order-card" aria-live="polite">
        <p><strong>Order status:</strong> <span id="order-status"><?= htmlspecialchars((string) $order['order_status'], ENT_QUOTES, 'UTF-8') ?></span></p>
        <p><strong>Pickup:</strong> At the café counter</p>
        <p><strong>Payment:</strong> <?= htmlspecialchars((string) ($order['payment_status'] ?? 'PENDING'), ENT_QUOTES, 'UTF-8') ?></p>
        <p><strong>Total:</strong> ₱<?= number_format((float) $order['total_amount'], 2) ?></p>
        <?php if (!empty($order['order_notes'])): ?>
            <p><strong>Order notes:</strong><br><?= nl2br(htmlspecialchars((string) $order['order_notes'], ENT_QUOTES, 'UTF-8')) ?></p>
        <?php endif; ?>
        <p><strong>Placed:</strong> <?= htmlspecialchars(date('M j, Y · g:i A', strtotime((string) $order['order_date'])), ENT_QUOTES, 'UTF-8') ?></p>
    </section>
</main>
<script>
(function () {
    var status = document.getElementById('order-status');
    var endpoint = 'track_order_status.php?order_id=<?= (int) $order['order_id'] ?>';
    window.setInterval(async function () {
        try {
            var response = await fetch(endpoint, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' });
            var data = await response.json();
            if (response.ok && data.ok && data.order_status) status.textContent = data.order_status;
        } catch (error) {
            console.error('Could not refresh order status.', error);
        }
    }, 15000);
})();
</script>
<?php require __DIR__ . '/../partials/footer.php'; ?>

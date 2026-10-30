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

$itemsStatement = $pdo->prepare(
    'SELECT p.product_name, s.size_name, oi.quantity, oi.unit_price, oi.subtotal, oi.customization_summary
       FROM order_items oi
       JOIN products p ON p.product_id = oi.product_id
       JOIN sizes s ON s.size_id = oi.size_id
      WHERE oi.order_id = ?
      ORDER BY oi.order_item_id ASC'
);
$itemsStatement->execute([(int) $orderId]);
$orderItems = $itemsStatement->fetchAll();

$orderStatuses = ['PENDING', 'CONFIRMED', 'PREPARING', 'READY', 'COMPLETED'];
$currentStatus = (string) $order['order_status'];
$statusLabels = [
    'PENDING' => 'Order received', 'CONFIRMED' => 'Confirmed', 'PREPARING' => 'Being prepared',
    'READY' => 'Ready for pickup', 'COMPLETED' => 'Picked up', 'CANCELLED' => 'Cancelled',
];
$statusIcons = ['PENDING' => 'clipboard-check', 'CONFIRMED' => 'badge-check', 'PREPARING' => 'chef-hat', 'READY' => 'package-check', 'COMPLETED' => 'circle-check', 'CANCELLED' => 'circle-x'];

$pageTitle = 'Order details | brewski';
$extraStyles = ['customer_orders/orders.css'];
require __DIR__ . '/../partials/header.php';
?>
<main class="orders-page order-detail-page">
    <div class="orders-heading">
        <div>
            <p class="orders-eyebrow">Order details</p>
            <h1>Order #<?= (int) $order['order_id'] ?></h1>
            <p>Placed <?= htmlspecialchars(date('M j, Y · g:i A', strtotime((string) $order['order_date'])), ENT_QUOTES, 'UTF-8') ?> · Store pickup</p>
        </div>
        <a class="orders-menu-link" href="orders.php"><i data-lucide="arrow-left" aria-hidden="true"></i> Back to my orders</a>
    </div>

    <div class="order-detail-grid">
        <div class="order-detail-main">
            <section class="order-detail-status" aria-live="polite">
                <div class="order-detail-status__heading">
                    <span class="order-detail-status__icon"><i data-lucide="<?= htmlspecialchars($statusIcons[$currentStatus] ?? 'circle-help', ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i></span>
                    <div><span class="orders-eyebrow">Current status</span><h2 id="order-status-label"><?= htmlspecialchars($statusLabels[$currentStatus] ?? $currentStatus, ENT_QUOTES, 'UTF-8') ?></h2><p>We’ll keep this page updated as your order moves along.</p></div>
                    <span class="order-status order-status--<?= strtolower($currentStatus) ?>" id="order-status"><?= htmlspecialchars($currentStatus, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <?php if ($currentStatus === 'CANCELLED'): ?>
                    <p class="order-cancelled-note">This order was cancelled. Please contact the café if you need help.</p>
                <?php else: ?>
                    <?php $currentIndex = array_search($currentStatus, $orderStatuses, true); ?>
                    <ol class="order-progress" aria-label="Order progress">
                        <?php foreach ($orderStatuses as $index => $step): $done = $currentIndex !== false && $index <= $currentIndex; ?>
                            <li class="<?= $done ? 'is-done' : '' ?> <?= $step === $currentStatus ? 'is-current' : '' ?>"><span class="order-progress__dot"><?php if ($done): ?><i data-lucide="check" aria-hidden="true"></i><?php else: ?><?= $index + 1 ?><?php endif; ?></span><span><?= htmlspecialchars($statusLabels[$step], ENT_QUOTES, 'UTF-8') ?></span></li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </section>

            <section class="order-detail-card">
                <div class="order-detail-card__heading"><div><p class="orders-eyebrow">Your order</p><h2>Items <span><?= count($orderItems) ?></span></h2></div><span class="order-pickup"><i data-lucide="store" aria-hidden="true"></i> Pickup at café counter</span></div>
                <?php if ($orderItems): ?>
                    <div class="order-detail-items">
                        <?php foreach ($orderItems as $item): ?>
                            <article class="order-detail-item"><span class="order-detail-item__icon"><i data-lucide="coffee" aria-hidden="true"></i></span><div class="order-detail-item__copy"><strong><?= htmlspecialchars((string) $item['product_name'], ENT_QUOTES, 'UTF-8') ?></strong><span><?= htmlspecialchars((string) $item['size_name'], ENT_QUOTES, 'UTF-8') ?> · Qty <?= (int) $item['quantity'] ?></span><?php if (!empty($item['customization_summary'])): ?><small><?= htmlspecialchars((string) $item['customization_summary'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?></div><strong class="order-detail-item__price">₱<?= number_format((float) $item['subtotal'], 2) ?></strong></article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?><p class="order-detail-empty-items">Order item details are unavailable.</p><?php endif; ?>
                <?php if (!empty($order['order_notes'])): ?><div class="order-detail-notes"><i data-lucide="message-square-text" aria-hidden="true"></i><div><strong>Note for the café</strong><p><?= nl2br(htmlspecialchars((string) $order['order_notes'], ENT_QUOTES, 'UTF-8')) ?></p></div></div><?php endif; ?>
            </section>
        </div>

        <aside class="order-detail-summary"><h2>Order summary</h2><div><span>Payment</span><strong><?= htmlspecialchars((string) ($order['payment_status'] ?? 'PENDING'), ENT_QUOTES, 'UTF-8') ?></strong></div><div><span>Pickup</span><strong>At the café</strong></div><div class="order-detail-summary__total"><span>Total</span><strong>₱<?= number_format((float) $order['total_amount'], 2) ?></strong></div><p><i data-lucide="info" aria-hidden="true"></i> Show your order number at the counter when you arrive.</p></aside>
    </div>
</main>
<script>
(function () {
    var status = document.getElementById('order-status');
    var endpoint = 'track_order_status.php?order_id=<?= (int) $order['order_id'] ?>';
    window.setInterval(async function () {
        try {
            var response = await fetch(endpoint, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' });
            var data = await response.json();
            if (response.ok && data.ok && data.order_status && data.order_status !== status.textContent) window.location.reload();
        } catch (error) {
            console.error('Could not refresh order status.', error);
        }
    }, 15000);
})();
</script>
<?php require __DIR__ . '/../partials/footer.php'; ?>

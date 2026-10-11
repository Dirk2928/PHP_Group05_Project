<?php

require_once __DIR__ . '/../partials/bootstrap.php';
require_once __DIR__ . '/../../Db/connection.php';
require_once __DIR__ . '/../partials/order_support.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../../login-signup/login.php');
    exit;
}

$userId  = (int) $_SESSION['user_id'];
$orderId = (int) ($_GET['order_id'] ?? 0);
$hasDeliveries = customer_has_deliveries_table($pdo);

$deliverySelect = $hasDeliveries
    ? 'd.delivery_id, d.delivery_status, d.delivery_fee, d.notes,
       d.assigned_at, d.picked_up_at, d.delivered_at, d.failed_at,
       u.first_name AS driver_first, u.last_name AS driver_last'
    : 'NULL AS delivery_id, NULL AS delivery_status,
       (o.total_amount - COALESCE((SELECT SUM(oi.subtotal)
                                    FROM order_items oi
                                   WHERE oi.order_id = o.order_id), 0)) AS delivery_fee,
       NULL AS notes, NULL AS assigned_at, NULL AS picked_up_at,
       NULL AS delivered_at, NULL AS failed_at,
       NULL AS driver_first, NULL AS driver_last';
$deliveryJoins = $hasDeliveries
    ? 'LEFT JOIN deliveries d ON d.order_id = o.order_id
       LEFT JOIN users u ON u.user_id = d.staff_id'
    : '';
$stmt = $pdo->prepare(
    "SELECT o.order_id, o.total_amount, o.order_status, o.order_date,
            a.address_line, a.city, a.province, a.postal_code,
            $deliverySelect
     FROM orders o
     LEFT JOIN addresses  a ON a.address_id  = o.address_id
     $deliveryJoins
     WHERE o.order_id = ? AND o.user_id = ?"
);
$stmt->execute([$orderId, $userId]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: orders.php');
    exit;
}

$itemStmt = $pdo->prepare(
    'SELECT oi.quantity, oi.unit_price, oi.subtotal,
            p.product_name, s.size_name
       FROM order_items oi
       LEFT JOIN products p ON p.product_id = oi.product_id
       LEFT JOIN sizes s ON s.size_id = oi.size_id
      WHERE oi.order_id = ?
      ORDER BY oi.order_item_id ASC'
);
$itemStmt->execute([$orderId]);
$items = $itemStmt->fetchAll();

$pageTitle = 'Track order #' . $orderId . ' | brewski';
$extraStyles = ['customer_orders/orders.css'];
require __DIR__ . '/../partials/header.php';

$status = $order['order_status'] === 'CANCELLED'
    ? 'CANCELLED'
    : ($order['delivery_status'] ?? 'UNASSIGNED');

$timeline = [
    'UNASSIGNED' => 'Order received — finding a rider',
    'ASSIGNED'   => 'Rider assigned',
    'PICKED_UP'  => 'Rider picked up your order',
    'IN_TRANSIT' => 'On the way to you',
    'DELIVERED'  => 'Delivered — enjoy!',
    'FAILED'     => 'Delivery failed',
    'CANCELLED'  => 'Delivery cancelled',
];

$order_of_steps = ['UNASSIGNED','ASSIGNED','PICKED_UP','IN_TRANSIT','DELIVERED'];
$current_index  = array_search($status, $order_of_steps, true);
if ($current_index === false) $current_index = -1;
?>

<main class="page order-track-page">

    <a href="orders.php" style="color:#5c4033; text-decoration:none; font-size:0.9rem;">← Back to my orders</a>

    <h1 style="margin:1.5rem 0 0.5rem;">Order #<?= (int)$order['order_id'] ?></h1>
    <p style="color:#5c4033; margin-bottom:2rem;">
        Placed on <?= htmlspecialchars(date('M j, Y g:i A', strtotime($order['order_date']))) ?>
    </p>

    <div style="display:flex; justify-content:space-between; gap:1rem; align-items:center; background:#faf5ee; border:1px solid #e8dac4; border-radius:0.9rem; padding:1rem 1.25rem; margin-bottom:1.5rem;">
        <strong>Order status</strong>
        <span class="order-status order-status--<?= htmlspecialchars(strtolower($order['order_status']), ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars(ucfirst(strtolower($order['order_status']))) ?>
        </span>
    </div>

    <section style="background:#f2e4cc; border-radius:1.25rem; padding:1.75rem; margin-bottom:1.5rem;">
        <h2 style="margin:0 0 1rem;">Delivery status</h2>

        <?php if (!$hasDeliveries && $order['order_status'] !== 'CANCELLED'): ?>
            <p style="margin:0; color:#5c4033;">Delivery updates are not set up yet. Your order status is shown above.</p>
        <?php elseif ($status === 'FAILED' || $status === 'CANCELLED'): ?>
            <div style="padding:1rem; background:#f8e3e0; border:1px solid #e6b8b2; border-radius:0.75rem; color:#a32a1f; margin-bottom:1rem;">
                <strong><?= htmlspecialchars($timeline[$status]) ?></strong>
                <?php if ($order['notes']): ?>
                    <br><small><?= htmlspecialchars($order['notes']) ?></small>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <ol style="list-style:none; padding:0; margin:0;">
                <?php foreach ($order_of_steps as $i => $step): ?>
                    <?php
                        $done    = $i <= $current_index;
                        $current = $i === $current_index;
                    ?>
                    <li style="display:flex; gap:0.75rem; padding:0.6rem 0; align-items:flex-start;">
                        <span style="
                            display:inline-flex; align-items:center; justify-content:center;
                            width:22px; height:22px; border-radius:50%; flex-shrink:0; margin-top:2px;
                            background:<?= $done ? '#1a0f0a' : '#e8dac4' ?>;
                            color:<?= $done ? '#faf5ee' : '#5c4033' ?>;
                            font-size:0.75rem; font-weight:700;
                        ">
                            <?= $done ? '✓' : ($i + 1) ?>
                        </span>
                        <span style="color:<?= $current ? '#1a0f0a' : '#5c4033' ?>; font-weight:<?= $current ? '600' : '400' ?>;">
                            <?= htmlspecialchars($timeline[$step]) ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>

        <?php if (!empty($order['driver_first'])): ?>
            <div style="margin-top:1rem; padding-top:1rem; border-top:1px solid #e8dac4;">
                <strong>Your rider:</strong> <?= htmlspecialchars($order['driver_first'] . ' ' . $order['driver_last']) ?>
            </div>
        <?php endif; ?>
    </section>

    <section style="background:#faf5ee; border:1px solid #e8dac4; border-radius:1.25rem; padding:1.75rem; margin-bottom:1.5rem;">
        <h2 style="margin:0 0 1rem;">Delivery to</h2>
        <?php if ($order['address_line']): ?>
            <p style="margin:0; color:#3b2218;">
                <?= htmlspecialchars($order['address_line']) ?><br>
                <?= htmlspecialchars($order['city'] . ', ' . $order['province'] . ' ' . $order['postal_code']) ?>
            </p>
        <?php else: ?>
            <p style="margin:0; color:#5c4033;">Address not available.</p>
        <?php endif; ?>
    </section>

    <section style="background:#faf5ee; border:1px solid #e8dac4; border-radius:1.25rem; padding:1.75rem; margin-bottom:1.5rem;">
        <h2 style="margin:0 0 1rem;">Items in this order</h2>
        <?php if ($items): ?>
            <?php foreach ($items as $item): ?>
                <div style="display:flex; justify-content:space-between; gap:1rem; padding:0.7rem 0; border-bottom:1px solid #e8dac4;">
                    <span>
                        <strong><?= htmlspecialchars($item['product_name'] ?? 'Unavailable item') ?></strong>
                        <small style="color:#5c4033;"> — <?= htmlspecialchars($item['size_name'] ?? 'Standard') ?> × <?= (int)$item['quantity'] ?></small>
                    </span>
                    <strong>₱<?= number_format((float)$item['subtotal'], 2) ?></strong>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="margin:0; color:#5c4033;">No item details are available for this order.</p>
        <?php endif; ?>
    </section>

    <section style="background:#faf5ee; border:1px solid #e8dac4; border-radius:1.25rem; padding:1.75rem;">
        <h2 style="margin:0 0 1rem;">Summary</h2>
        <div style="display:flex; justify-content:space-between; margin-bottom:0.5rem;">
            <span>Delivery fee</span>
            <span>₱<?= number_format((float)($order['delivery_fee'] ?? 0), 2) ?></span>
        </div>
        <div style="display:flex; justify-content:space-between; padding-top:1rem; border-top:1px solid #e8dac4; font-weight:700; font-size:1.1rem;">
            <span>Total</span>
            <span>₱<?= number_format((float)$order['total_amount'], 2) ?></span>
        </div>
    </section>

</main>

<?php require __DIR__ . '/../partials/footer.php'; ?>
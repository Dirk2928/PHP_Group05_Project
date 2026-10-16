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

if ($orderId <= 0) {
    header('Location: orders.php');
    exit;
}

$hasDeliveries = customer_has_deliveries_table($pdo);

$deliverySelect = $hasDeliveries
    ? 'd.delivery_id, d.delivery_status, d.delivery_fee, d.notes AS delivery_notes,
       d.assigned_at, d.picked_up_at, d.delivered_at, d.failed_at,
       u.first_name AS driver_first, u.last_name AS driver_last'
    : 'NULL AS delivery_id, NULL AS delivery_status,
       COALESCE(o.delivery_fee, 0) AS delivery_fee,
       NULL AS delivery_notes, NULL AS assigned_at, NULL AS picked_up_at,
       NULL AS delivered_at, NULL AS failed_at,
       NULL AS driver_first, NULL AS driver_last';
       
$deliveryJoins = $hasDeliveries
    ? 'LEFT JOIN deliveries d ON d.order_id = o.order_id
       LEFT JOIN users u ON u.user_id = d.staff_id'
    : '';

$stmt = $pdo->prepare(
    "SELECT o.order_id, o.total_amount, o.order_status, o.order_date, o.delivery_fee,
            a.address_line, a.city, a.province, a.postal_code,
            $deliverySelect
     FROM orders o
     LEFT JOIN addresses a ON a.address_id = o.address_id
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

$initialStatus = 'UNASSIGNED';
if ($order['order_status'] === 'CANCELLED') {
    $initialStatus = 'CANCELLED';
} elseif ($hasDeliveries && !empty($order['delivery_status'])) {
    $initialStatus = strtoupper($order['delivery_status']);
}

$timeline = [
    'UNASSIGNED' => 'Order received — finding a rider',
    'ASSIGNED'   => 'Rider assigned',
    'PICKED_UP'  => 'Rider picked up your order',
    'IN_TRANSIT' => 'On the way to you',
    'DELIVERED'  => 'Delivered — enjoy!',
    'FAILED'     => 'Delivery failed',
    'CANCELLED'  => 'Delivery cancelled',
];

$orderLabels = [
    'PENDING'   => 'Pending',
    'CONFIRMED' => 'Confirmed',
    'PREPARING' => 'Preparing',
    'READY'     => 'Ready',
    'COMPLETED' => 'Completed',
    'CANCELLED' => 'Cancelled',
];

$order_of_steps = ['UNASSIGNED', 'ASSIGNED', 'PICKED_UP', 'IN_TRANSIT', 'DELIVERED'];
$current_index = array_search($initialStatus, $order_of_steps, true);
if ($current_index === false) {
    $current_index = -1;
}

$showDeliverySection = $hasDeliveries || $order['order_status'] !== 'CANCELLED';
$showTimeline = !in_array($initialStatus, ['FAILED', 'CANCELLED'], true);
?>

<main class="page order-track-page">

    <a href="orders.php" style="color:#5c4033; text-decoration:none; font-size:0.9rem;">← Back to my orders</a>

    <h1 style="margin:1.5rem 0 0.5rem;">Order #<?= (int)$order['order_id'] ?></h1>
    <p style="color:#5c4033; margin-bottom:2rem;">
        Placed on <?= htmlspecialchars(date('M j, Y g:i A', strtotime($order['order_date']))) ?>
    </p>

    <div style="display:flex; justify-content:space-between; gap:1rem; align-items:center; background:#faf5ee; border:1px solid #e8dac4; border-radius:0.9rem; padding:1rem 1.25rem; margin-bottom:1.5rem;">
        <strong>Order status</strong>
        <span id="orderStatusBadge" class="order-status order-status--<?= htmlspecialchars(strtolower($order['order_status']), ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($orderLabels[strtoupper($order['order_status'])] ?? ucfirst(strtolower($order['order_status']))) ?>
        </span>
    </div>

    <?php if ($showDeliverySection): ?>
    <section id="deliverySection" style="background:#f2e4cc; border-radius:1.25rem; padding:1.75rem; margin-bottom:1.5rem;">
        <h2 style="margin:0 0 1rem;">Delivery status</h2>

        <?php if (!$hasDeliveries && $order['order_status'] !== 'CANCELLED'): ?>
            <p id="noDeliveryNotice" style="margin:0; color:#5c4033;">Delivery updates are not set up yet. Your order status is shown above.</p>
        <?php endif; ?>

        <div id="deliveryAlert" style="padding:1rem; background:#f8e3e0; border:1px solid #e6b8b2; border-radius:0.75rem; color:#a32a1f; margin-bottom:1rem;<?= $showTimeline ? ' display:none;' : '' ?>">
            <strong id="deliveryAlertText"><?= htmlspecialchars($timeline[$initialStatus] ?? '') ?></strong>
        </div>

        <ol id="deliveryTimeline" style="list-style:none; padding:0; margin:0;<?= $showTimeline ? '' : ' display:none;' ?>">
            <?php foreach ($order_of_steps as $i => $step): ?>
                <?php
                    $done = $i <= $current_index;
                    $current = $i === $current_index;
                ?>
                <li data-step="<?= htmlspecialchars($step, ENT_QUOTES, 'UTF-8') ?>" style="display:flex; gap:0.75rem; padding:0.6rem 0; align-items:flex-start;">
                    <span class="timeline-marker" style="
                        display:inline-flex; align-items:center; justify-content:center;
                        width:22px; height:22px; border-radius:50%; flex-shrink:0; margin-top:2px;
                        background:<?= $done ? '#1a0f0a' : '#e8dac4' ?>;
                        color:<?= $done ? '#faf5ee' : '#5c4033' ?>;
                        font-size:0.75rem; font-weight:700;
                    ">
                        <?= $done ? '✓' : ($i + 1) ?>
                    </span>
                    <span class="timeline-label" style="color:<?= $current ? '#1a0f0a' : '#5c4033' ?>; font-weight:<?= $current ? '600' : '400' ?>;">
                        <?= htmlspecialchars($timeline[$step]) ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ol>

        <div id="riderInfo" style="margin-top:1rem; padding-top:1rem; border-top:1px solid #e8dac4;<?= empty($order['driver_first']) ? ' display:none;' : '' ?>">
            <strong>Your rider:</strong> <span id="riderName"><?= htmlspecialchars(trim(($order['driver_first'] ?? '') . ' ' . ($order['driver_last'] ?? ''))) ?></span>
        </div>
    </section>
    <?php endif; ?>

    <section style="background:#faf5ee; border:1px solid #e8dac4; border-radius:1.25rem; padding:1.75rem; margin-bottom:1.5rem;">
        <h2 style="margin:0 0 1rem;">Delivery to</h2>
        <p id="deliveryAddress" style="margin:0; color:#3b2218;"><?php
            if (!empty($order['address_line'])) {
                echo htmlspecialchars($order['address_line']) . '<br>' .
                     htmlspecialchars($order['city'] . ', ' . $order['province'] . ' ' . $order['postal_code']);
            } else {
                echo '<span style="color:#5c4033;">Address not available.</span>';
            }
        ?></p>
    </section>

    <?php if (!empty($order['delivery_notes'])): ?>
    <section id="deliveryNotesSection" style="background:#faf5ee; border:1px solid #e8dac4; border-radius:1.25rem; padding:1.75rem; margin-bottom:1.5rem;">
        <h2 style="margin:0 0 1rem;">Delivery notes</h2>
        <p id="deliveryNotesText" style="margin:0; color:#3b2218; white-space:pre-wrap;"><?= htmlspecialchars($order['delivery_notes'], ENT_QUOTES, 'UTF-8') ?></p>
    </section>
    <?php endif; ?>

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
            <span>Subtotal</span>
            <span>₱<?= number_format(array_sum(array_column($items, 'subtotal')), 2) ?></span>
        </div>
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

<script>
(function () {
    var orderId = <?= (int) $orderId ?>;
    var hasDeliveries = <?= $hasDeliveries ? 'true' : 'false' ?>;
    var timeline = <?= json_encode($timeline) ?>;
    var orderLabels = <?= json_encode($orderLabels) ?>;
    var steps = ['UNASSIGNED', 'ASSIGNED', 'PICKED_UP', 'IN_TRANSIT', 'DELIVERED'];
    var endpoint = 'track_order_status.php?order_id=' + orderId;
    var badge = document.getElementById('orderStatusBadge');
    var alertBox = document.getElementById('deliveryAlert');
    var alertText = document.getElementById('deliveryAlertText');
    var timelineList = document.getElementById('deliveryTimeline');
    var riderInfo = document.getElementById('riderInfo');
    var riderName = document.getElementById('riderName');
    var notesSection = document.getElementById('deliveryNotesSection');
    var notesText = document.getElementById('deliveryNotesText');
    var noDeliveryNotice = document.getElementById('noDeliveryNotice');
    var lastSignature = '';

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function render(data) {
        var orderStatus = String(data.order_status || '').toUpperCase();
        var status = orderStatus === 'CANCELLED'
            ? 'CANCELLED'
            : (data.delivery_status ? String(data.delivery_status).toUpperCase() : 'UNASSIGNED');

        if (badge && orderLabels[orderStatus]) {
            badge.textContent = orderLabels[orderStatus];
            badge.className = 'order-status order-status--' + orderStatus.toLowerCase();
        }

        if (!hasDeliveries) {
            if (noDeliveryNotice && orderStatus !== 'CANCELLED') {
                noDeliveryNotice.style.display = '';
            }
            if (orderStatus === 'CANCELLED') {
                if (noDeliveryNotice) noDeliveryNotice.style.display = 'none';
                if (alertBox) {
                    alertText.textContent = timeline.CANCELLED || 'Delivery cancelled';
                    alertBox.style.display = '';
                }
                if (timelineList) timelineList.style.display = 'none';
            }
        } else if (status === 'FAILED' || status === 'CANCELLED') {
            if (noDeliveryNotice) noDeliveryNotice.style.display = 'none';
            if (timelineList) timelineList.style.display = 'none';
            if (alertBox) {
                alertText.textContent = timeline[status] || 'Delivery issue';
                alertBox.style.display = '';
            }
        } else {
            if (noDeliveryNotice) noDeliveryNotice.style.display = 'none';
            if (alertBox) alertBox.style.display = 'none';
            if (timelineList) {
                timelineList.style.display = '';
                var currentIndex = steps.indexOf(status);
                if (currentIndex === -1) currentIndex = 0;
                var items = timelineList.querySelectorAll('li[data-step]');
                items.forEach(function (li, i) {
                    var done = i <= currentIndex;
                    var current = i === currentIndex;
                    var marker = li.querySelector('.timeline-marker');
                    var label = li.querySelector('.timeline-label');
                    if (marker) {
                        marker.textContent = done ? '\u2713' : String(i + 1);
                        marker.style.background = done ? '#1a0f0a' : '#e8dac4';
                        marker.style.color = done ? '#faf5ee' : '#5c4033';
                    }
                    if (label) {
                        label.style.color = current ? '#1a0f0a' : '#5c4033';
                        label.style.fontWeight = current ? '600' : '400';
                    }
                });
            }
        }

        if (riderInfo && riderName) {
            if (data.driver_first) {
                riderName.textContent = (data.driver_first + ' ' + (data.driver_last || '')).trim();
                riderInfo.style.display = '';
            } else {
                riderInfo.style.display = 'none';
            }
        }

        if (notesSection && notesText) {
            if (data.delivery_notes) {
                notesText.textContent = data.delivery_notes;
                notesSection.style.display = '';
            } else {
                notesSection.style.display = 'none';
            }
        }
    }

    function poll() {
        fetch(endpoint, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                if (!response.ok) throw new Error('Request failed with status ' + response.status);
                return response.json();
            })
            .then(function (payload) {
                if (!payload || !payload.ok) {
                    console.warn('Invalid payload received');
                    return;
                }
                var signature = JSON.stringify(payload);
                if (signature !== lastSignature) {
                    lastSignature = signature;
                    render(payload);
                }
            })
            .catch(function (error) {
                console.error('Error polling order status:', error);
            });
    }

    poll();
    setInterval(poll, 5000);
})();
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
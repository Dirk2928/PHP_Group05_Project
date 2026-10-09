<?php

require_once __DIR__ . '/../partials/bootstrap.php';
require_once __DIR__ . '/../../Db/connection.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../../login-signup/login.php');
    exit;
}

$userId  = (int) $_SESSION['user_id'];
$orderId = (int) ($_GET['order_id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT o.order_id, o.total_amount, o.order_status, o.order_date,
            a.address_line, a.city, a.province, a.postal_code,
            d.delivery_id, d.delivery_status, d.delivery_fee, d.notes,
            d.assigned_at, d.picked_up_at, d.delivered_at, d.failed_at,
            u.first_name AS driver_first, u.last_name AS driver_last
     FROM orders o
     LEFT JOIN addresses  a ON a.address_id  = o.address_id
     LEFT JOIN deliveries d ON d.order_id    = o.order_id
     LEFT JOIN users      u ON u.user_id     = d.staff_id
     WHERE o.order_id = ? AND o.user_id = ?'
);
$stmt->execute([$orderId, $userId]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: ../customer_home/customerhome.php');
    exit;
}

$pageTitle = 'Track order #' . $orderId . ' | brewski';
require __DIR__ . '/../partials/header.php';

$status = $order['delivery_status'] ?? 'UNASSIGNED';

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

<main class="page" style="max-width: 720px; margin: 0 auto; padding: 3rem 1.5rem 4rem;">

    <a href="../customer_home/customerhome.php" style="color:#5c4033; text-decoration:none; font-size:0.9rem;">← Back to home</a>

    <h1 style="font-size:2rem; margin:1.5rem 0 0.5rem;">Order #<?= (int)$order['order_id'] ?></h1>
    <p style="color:#5c4033; margin-bottom:2rem;">
        Placed on <?= htmlspecialchars(date('M j, Y g:i A', strtotime($order['order_date']))) ?>
    </p>

    <section style="background:#f2e4cc; border-radius:1.25rem; padding:1.75rem; margin-bottom:1.5rem;">
        <h2 style="font-size:1.1rem; margin:0 0 1rem;">Delivery status</h2>

        <?php if ($status === 'FAILED' || $status === 'CANCELLED'): ?>
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
        <h2 style="font-size:1.1rem; margin:0 0 1rem;">Delivery to</h2>
        <?php if ($order['address_line']): ?>
            <p style="margin:0; color:#3b2218;">
                <?= htmlspecialchars($order['address_line']) ?><br>
                <?= htmlspecialchars($order['city'] . ', ' . $order['province'] . ' ' . $order['postal_code']) ?>
            </p>
        <?php else: ?>
            <p style="margin:0; color:#5c4033;">Address not available.</p>
        <?php endif; ?>
    </section>

    <section style="background:#faf5ee; border:1px solid #e8dac4; border-radius:1.25rem; padding:1.75rem;">
        <h2 style="font-size:1.1rem; margin:0 0 1rem;">Summary</h2>
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
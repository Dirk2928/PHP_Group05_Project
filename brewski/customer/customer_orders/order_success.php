<?php

require_once __DIR__ . '/../partials/bootstrap.php';
require_once __DIR__ . '/../../Db/connection.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../../login-signup/login.php');
    exit;
}

$orderId = (int) ($_GET['order_id'] ?? 0);
$userId  = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare(
    'SELECT o.order_id, o.total_amount, o.order_status, d.delivery_status
     FROM orders o
     LEFT JOIN deliveries d ON d.order_id = o.order_id
     WHERE o.order_id = ? AND o.user_id = ?'
);
$stmt->execute([$orderId, $userId]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: ../customer_home/customerhome.php');
    exit;
}

$pageTitle = 'Order placed | brewski';
require __DIR__ . '/../partials/header.php';
?>

<main style="max-width: 720px; margin: 0 auto; padding: 4rem 1.5rem; text-align:center;">

    <div style="font-size: 3rem;">☕</div>

    <h1 style="font-size: 2rem; margin: 1rem 0;">Order placed!</h1>

    <p style="color:#5c4033; margin-bottom: 2rem;">
        Your order <strong>#<?= (int)$order['order_id'] ?></strong> has been received.
        We'll notify you when a rider is on the way.
    </p>

    <div style="display:inline-block; text-align:left; background:#f2e4cc; padding:1.5rem 2rem; border-radius:1rem; margin-bottom:2rem;">
        <div><strong>Total:</strong> ₱<?= number_format((float)$order['total_amount'], 2) ?></div>
        <div><strong>Order status:</strong> <?= htmlspecialchars($order['order_status']) ?></div>
        <div><strong>Delivery:</strong> <?= htmlspecialchars($order['delivery_status'] ?? 'UNASSIGNED') ?></div>
    </div>

    <div style="display:flex; gap:1rem; justify-content:center;">
        <a href="track_order.php?order_id=<?= (int)$order['order_id'] ?>"
           style="padding:1rem 1.5rem; background:#1a0f0a; color:#faf5ee; border-radius:0.75rem; text-decoration:none; font-weight:600;">
            Track order
        </a>
        <a href="../customer_menu/customermenu.php"
           style="padding:1rem 1.5rem; background:#faf5ee; color:#1a0f0a; border:1px solid #e8dac4; border-radius:0.75rem; text-decoration:none; font-weight:600;">
            Back to menu
        </a>
    </div>

</main>

<?php require __DIR__ . '/../partials/footer.php'; ?>
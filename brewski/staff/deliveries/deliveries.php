<?php

session_start();
require_once __DIR__ . '/../../Db/connection.php';

// --- Staff auth ---
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'STAFF') {
    http_response_code(403);
    exit('Access denied. Staff only.');
}

$staffId = (int) $_SESSION['user_id'];

// --- Load all non-completed deliveries ---
$stmt = $pdo->prepare(
    'SELECT d.delivery_id, d.order_id, d.delivery_status, d.delivery_fee, d.notes,
            d.created_at, d.assigned_at, d.picked_up_at, d.delivered_at,
            o.total_amount,
            a.address_line, a.city, a.province, a.postal_code,
            u.first_name AS driver_first, u.last_name AS driver_last,
            cu.first_name AS customer_first, cu.last_name AS customer_last,
            cu.email      AS customer_email
     FROM deliveries d
     JOIN orders    o  ON o.order_id  = d.order_id
     JOIN users     cu ON cu.user_id  = o.user_id
     LEFT JOIN addresses a ON a.address_id = o.address_id
     LEFT JOIN users     u ON u.user_id    = d.staff_id
     ORDER BY
        FIELD(d.delivery_status, "UNASSIGNED","ASSIGNED","PICKED_UP","IN_TRANSIT","DELIVERED","FAILED","CANCELLED"),
        d.created_at DESC'
);
$stmt->execute();
$deliveries = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Deliveries | brewski staff</title>
<style>
    body { font-family: -apple-system, Segoe UI, sans-serif; background:#faf5ee; color:#1a0f0a; margin:0; padding:2rem; }
    h1 { margin:0 0 1.5rem; }
    .grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap:1rem; }
    .card { background:#f2e4cc; border-radius:1rem; padding:1.25rem; }
    .card h3 { margin:0 0 0.5rem; font-size:1.05rem; }
    .status { display:inline-block; padding:0.25rem 0.75rem; border-radius:1rem; font-size:0.75rem; font-weight:700; margin-bottom:0.75rem; }
    .st-UNASSIGNED { background:#e8dac4; color:#5c4033; }
    .st-ASSIGNED   { background:#ffe5b4; color:#8a5a00; }
    .st-PICKED_UP  { background:#cfe8ff; color:#004b8a; }
    .st-IN_TRANSIT { background:#cfe8ff; color:#004b8a; }
    .st-DELIVERED  { background:#c9ebd0; color:#2f6b3a; }
    .st-FAILED     { background:#f8d7d3; color:#a32a1f; }
    .st-CANCELLED  { background:#f8d7d3; color:#a32a1f; }
    .row { font-size:0.9rem; margin:0.25rem 0; color:#3b2218; }
    .actions { margin-top:0.75rem; display:flex; flex-wrap:wrap; gap:0.4rem; }
    button { font-family:inherit; font-size:0.8rem; font-weight:600; padding:0.5rem 0.75rem; border:none; border-radius:0.5rem; background:#1a0f0a; color:#faf5ee; cursor:pointer; }
    button.sec { background:#faf5ee; color:#1a0f0a; border:1px solid #e8dac4; }
    .empty { text-align:center; padding:3rem; color:#5c4033; }
</style>
</head>
<body>

<h1>Deliveries</h1>

<?php if (!$deliveries): ?>
    <div class="empty">No deliveries yet.</div>
<?php else: ?>
    <div class="grid">
        <?php foreach ($deliveries as $d): ?>
            <div class="card">
                <span class="status st-<?= htmlspecialchars($d['delivery_status']) ?>">
                    <?= htmlspecialchars($d['delivery_status']) ?>
                </span>

                <h3>Order #<?= (int)$d['order_id'] ?></h3>

                <p class="row"><strong>Customer:</strong> <?= htmlspecialchars($d['customer_first'] . ' ' . $d['customer_last']) ?></p>
                <p class="row"><strong>Email:</strong> <?= htmlspecialchars($d['customer_email']) ?></p>

                <?php if ($d['address_line']): ?>
                    <p class="row"><strong>Deliver to:</strong><br>
                        <?= htmlspecialchars($d['address_line']) ?><br>
                        <?= htmlspecialchars($d['city'] . ', ' . $d['province'] . ' ' . $d['postal_code']) ?>
                    </p>
                <?php endif; ?>

                <p class="row"><strong>Total:</strong> ₱<?= number_format((float)$d['total_amount'], 2) ?></p>
                <p class="row"><strong>Fee:</strong> ₱<?= number_format((float)$d['delivery_fee'], 2) ?></p>

                <?php if ($d['driver_first']): ?>
                    <p class="row"><strong>Rider:</strong> <?= htmlspecialchars($d['driver_first'] . ' ' . $d['driver_last']) ?></p>
                <?php else: ?>
                    <p class="row"><strong>Rider:</strong> <em>Unassigned</em></p>
                <?php endif; ?>

                <?php if ($d['notes']): ?>
                    <p class="row"><strong>Notes:</strong> <?= htmlspecialchars($d['notes']) ?></p>
                <?php endif; ?>

                <div class="actions">
                    <?php if ($d['delivery_status'] === 'UNASSIGNED'): ?>
                        <button onclick="setStatus(<?= (int)$d['delivery_id'] ?>, 'ASSIGNED')">Assign to me</button>
                    <?php endif; ?>
                    <?php if ($d['delivery_status'] === 'ASSIGNED'): ?>
                        <button onclick="setStatus(<?= (int)$d['delivery_id'] ?>, 'PICKED_UP')">Picked up</button>
                    <?php endif; ?>
                    <?php if ($d['delivery_status'] === 'PICKED_UP'): ?>
                        <button onclick="setStatus(<?= (int)$d['delivery_id'] ?>, 'IN_TRANSIT')">In transit</button>
                    <?php endif; ?>
                    <?php if ($d['delivery_status'] === 'IN_TRANSIT'): ?>
                        <button onclick="setStatus(<?= (int)$d['delivery_id'] ?>, 'DELIVERED')">Delivered</button>
                    <?php endif; ?>
                    <?php if (in_array($d['delivery_status'], ['ASSIGNED','PICKED_UP','IN_TRANSIT'], true)): ?>
                        <button class="sec" onclick="setStatus(<?= (int)$d['delivery_id'] ?>, 'FAILED')">Failed</button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
async function setStatus(deliveryId, status) {
    if (!confirm('Set delivery #' + deliveryId + ' to ' + status + '?')) return;

    const body = new FormData();
    body.append('delivery_id', deliveryId);
    body.append('status', status);
    body.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>');

    const res  = await fetch('update_delivery.php', { method: 'POST', body });
    const json = await res.json();

    if (json.ok) {
        location.reload();
    } else {
        alert(json.error || 'Update failed.');
    }
}
</script>

</body>
</html>
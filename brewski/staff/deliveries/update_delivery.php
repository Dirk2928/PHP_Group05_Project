<?php

session_start();
require_once __DIR__ . '/../../Db/connection.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'STAFF') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Staff only.']);
    exit;
}

$staffId    = (int) $_SESSION['user_id'];
$deliveryId = (int) ($_POST['delivery_id'] ?? 0);
$newStatus  = $_POST['status'] ?? '';
$notes      = trim($_POST['notes'] ?? '');

$allowed = ['ASSIGNED', 'PICKED_UP', 'IN_TRANSIT', 'DELIVERED', 'FAILED', 'CANCELLED'];

if ($deliveryId <= 0 || !in_array($newStatus, $allowed, true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid input.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT delivery_id, order_id, staff_id FROM deliveries WHERE delivery_id = ? FOR UPDATE'
    );
    $stmt->execute([$deliveryId]);
    $delivery = $stmt->fetch();

    if (!$delivery) {
        throw new RuntimeException('Delivery not found.');
    }

    $assignStaff = $delivery['staff_id'] === null ? $staffId : $delivery['staff_id'];

    $sets   = ['delivery_status = ?', 'staff_id = ?'];
    $params = [$newStatus, $assignStaff];

    if ($delivery['staff_id'] === null)  $sets[] = 'assigned_at = NOW()';
    if ($newStatus === 'PICKED_UP')      $sets[] = 'picked_up_at = NOW()';
    if ($newStatus === 'DELIVERED')      $sets[] = 'delivered_at = NOW()';
    if ($newStatus === 'FAILED')         $sets[] = 'failed_at = NOW()';
    if ($notes !== '') { $sets[] = 'notes = ?'; $params[] = $notes; }

    $params[] = $deliveryId;

    $pdo->prepare('UPDATE deliveries SET ' . implode(', ', $sets) . ' WHERE delivery_id = ?')
        ->execute($params);

    // Sync parent order status
    $map = [
        'DELIVERED' => 'COMPLETED',
        'FAILED'    => 'CANCELLED',
        'CANCELLED' => 'CANCELLED',
        'PICKED_UP' => 'READY',
    ];
    if (isset($map[$newStatus])) {
        $pdo->prepare('UPDATE orders SET order_status = ? WHERE order_id = ?')
            ->execute([$map[$newStatus], $delivery['order_id']]);
    }

    $pdo->commit();

    echo json_encode(['ok' => true, 'delivery_id' => $deliveryId, 'status' => $newStatus]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
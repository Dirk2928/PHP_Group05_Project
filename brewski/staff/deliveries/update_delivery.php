<?php

require_once __DIR__ . '/../../login-signup/session_init.php';
require_once __DIR__ . '/../../Db/connection.php';

header('Content-Type: application/json');

if (!brewski_is_logged_in() || !in_array(brewski_current_role(), ['STAFF', 'ADMIN'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Staff only.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$submittedToken = $_POST['csrf_token'] ?? '';

if (
    empty($_SESSION['csrf_token'])
    || !is_string($submittedToken)
    || !hash_equals($_SESSION['csrf_token'], $submittedToken)
) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid session token. Please reload the page.']);
    exit;
}

$staffId    = (int) $_SESSION['user_id'];
$deliveryId = (int) ($_POST['delivery_id'] ?? 0);
$newStatus  = strtoupper(trim((string) ($_POST['status'] ?? '')));
$notes      = trim((string) ($_POST['notes'] ?? ''));

$transitions = [
    'UNASSIGNED' => ['ASSIGNED', 'CANCELLED'],
    'ASSIGNED'   => ['PICKED_UP', 'FAILED', 'CANCELLED'],
    'PICKED_UP'  => ['IN_TRANSIT', 'FAILED', 'CANCELLED'],
    'IN_TRANSIT' => ['DELIVERED', 'FAILED'],
];

if ($deliveryId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please choose a delivery.']);
    exit;
}

if ($newStatus === '' || !preg_match('/^[A-Z_]+$/', $newStatus)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please choose a valid status.']);
    exit;
}

if (mb_strlen($notes) > 500) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Delivery notes must be 500 characters or fewer.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT delivery_id, order_id, staff_id, delivery_status
         FROM deliveries
         WHERE delivery_id = ?
         FOR UPDATE'
    );
    $stmt->execute([$deliveryId]);
    $delivery = $stmt->fetch();

    if (!$delivery) {
        throw new RuntimeException('Delivery not found.');
    }

    if (!in_array($newStatus, $transitions[$delivery['delivery_status']] ?? [], true)) {
        throw new RuntimeException(
            'A delivery that is ' . $delivery['delivery_status']
            . ' cannot be changed to ' . $newStatus . '.'
        );
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
<?php

require_once __DIR__ . '/../partials/bootstrap.php';
require_once __DIR__ . '/../../Db/connection.php';

header('Content-Type: application/json');

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid request']);
    exit;
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$orderId = (int) ($_GET['order_id'] ?? 0);

if ($orderId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid order ID']);
    exit;
}

try {
    $hasDeliveries = false;
    $checkTable = $pdo->query("SHOW TABLES LIKE 'deliveries'");
    if ($checkTable && $checkTable->rowCount() > 0) {
        $hasDeliveries = true;
    }

    $deliverySelect = $hasDeliveries
        ? 'd.delivery_status, d.notes AS delivery_notes,
           u.first_name AS driver_first, u.last_name AS driver_last'
        : 'NULL AS delivery_status, NULL AS delivery_notes,
           NULL AS driver_first, NULL AS driver_last';
    
    $deliveryJoin = $hasDeliveries
        ? 'LEFT JOIN deliveries d ON d.order_id = o.order_id
           LEFT JOIN users u ON u.user_id = d.staff_id'
        : '';

    $stmt = $pdo->prepare(
        "SELECT o.order_status,
                $deliverySelect
         FROM orders o
         $deliveryJoin
         WHERE o.order_id = ? AND o.user_id = ?
         LIMIT 1"
    );
    
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(['ok' => false, 'error' => 'Order not found']);
        exit;
    }

    $response = [
        'ok' => true,
        'order_status' => $order['order_status'],
        'delivery_status' => $order['delivery_status'],
        'delivery_notes' => $order['delivery_notes'],
        'driver_first' => $order['driver_first'],
        'driver_last' => $order['driver_last'],
    ];

    echo json_encode($response);

} catch (PDOException $e) {
    error_log('Error in track_order_status.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}
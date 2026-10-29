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
    $stmt = $pdo->prepare(
        "SELECT o.order_status
         FROM orders o
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
    ];

    echo json_encode($response);

} catch (PDOException $e) {
    error_log('Error in track_order_status.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}

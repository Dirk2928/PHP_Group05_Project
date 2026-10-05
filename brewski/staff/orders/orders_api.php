<?php
define('SESSION_NO_TOUCH', true);

require_once __DIR__ . '/../../login-signup/session_init.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$role = strtoupper((string) ($_SESSION['role'] ?? ''));

if (!isset($_SESSION['user_id']) || !in_array($role, ['STAFF', 'ADMIN'], true)) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

session_write_close();

mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli('localhost', 'root', '', 'brewski_db');

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'db_connection']);
    exit;
}

$conn->set_charset('utf8mb4');

$transitions = [
    'PENDING'   => ['CONFIRMED', 'CANCELLED'],
    'CONFIRMED' => ['PREPARING', 'CANCELLED'],
    'PREPARING' => ['READY', 'CANCELLED'],
    'READY'     => ['COMPLETED'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderId   = (int) ($_POST['order_id'] ?? 0);
    $newStatus = strtoupper(trim((string) ($_POST['status'] ?? '')));

    $stmt = $conn->prepare('SELECT order_status FROM orders WHERE order_id = ? LIMIT 1');
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $stmt->bind_result($currentStatus);

    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'order_not_found']);
        exit;
    }
    $stmt->close();

    $currentStatus = strtoupper((string) $currentStatus);

    if (!in_array($newStatus, $transitions[$currentStatus] ?? [], true)) {
        http_response_code(422);
        echo json_encode(['error' => 'invalid_transition']);
        exit;
    }

    $upd = $conn->prepare('UPDATE orders SET order_status = ? WHERE order_id = ?');
    $upd->bind_param('si', $newStatus, $orderId);
    $upd->execute();

    echo json_encode(['ok' => true]);
    exit;
}

$orders = [];

$result = $conn->query(
    "SELECT o.order_id,
            o.order_status AS status,
            o.total_amount,
            o.order_date AS created_at,
            TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS customer
     FROM orders o
     LEFT JOIN users u ON u.user_id = o.user_id
     WHERE o.order_status IN ('PENDING', 'CONFIRMED', 'PREPARING', 'READY')
     ORDER BY o.order_date ASC
     LIMIT 100"
);

if (!$result) {
    error_log('orders_api list query failed: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['error' => 'query_failed', 'detail' => $conn->error]);
    exit;
}

while ($row = $result->fetch_assoc()) {
    $row['order_id'] = (int) $row['order_id'];
    $row['items']    = [];
    $orders[$row['order_id']] = $row;
}

if ($orders) {
    $ids = implode(',', array_map('intval', array_keys($orders))); 

    $items = $conn->query(
        "SELECT oi.order_id, oi.quantity, COALESCE(p.name, 'Unknown item') AS name
         FROM order_items oi
         LEFT JOIN products p ON p.product_id = oi.product_id
         WHERE oi.order_id IN ($ids)"
    );

    if (!$items) {
        error_log('orders_api items query failed: ' . $conn->error);
    } else {
        while ($it = $items->fetch_assoc()) {
            $orders[(int) $it['order_id']]['items'][] = [
                'name'     => $it['name'],
                'quantity' => (int) $it['quantity'],
            ];
        }
    }
}

echo json_encode(['orders' => array_values($orders)]);
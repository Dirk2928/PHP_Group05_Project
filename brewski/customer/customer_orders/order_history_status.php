<?php

require_once __DIR__ . '/../partials/bootstrap.php';
require_once __DIR__ . '/../../Db/connection.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sign in to view your orders.']);
    exit;
}

$rawIds = $_GET['order_ids'] ?? '';
$orderIds = is_string($rawIds)
    ? array_values(array_unique(array_filter(array_map('intval', explode(',', $rawIds)), static fn (int $id): bool => $id > 0)))
    : [];

if ($orderIds === [] || count($orderIds) > 10) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid order list.']);
    exit;
}

try {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $statement = $pdo->prepare(
        "SELECT order_id, order_status
           FROM orders
          WHERE user_id = ? AND order_id IN ($placeholders)"
    );
    $statement->execute(array_merge([(int) $_SESSION['user_id']], $orderIds));
    $statuses = [];
    foreach ($statement->fetchAll() as $row) {
        $statuses[(string) $row['order_id']] = (string) $row['order_status'];
    }

    echo json_encode(['ok' => true, 'statuses' => $statuses]);
} catch (Throwable $error) {
    error_log('Could not refresh customer order history: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not refresh your orders.']);
}

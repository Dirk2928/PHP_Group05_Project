<?php

require_once __DIR__ . '/../partials/bootstrap.php';
require_once __DIR__ . '/../../Db/connection.php';
require_once __DIR__ . '/../partials/order_support.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function customer_receipt_respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function customer_receipt_money(int $cents): string
{
    return number_format($cents / 100, 2, '.', '');
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    customer_receipt_respond(405, [
        'ok' => false,
        'error' => 'Method not allowed.',
    ]);
}

if (empty($_SESSION['user_id'])) {
    customer_receipt_respond(401, [
        'ok' => false,
        'error' => 'Sign in to access this receipt.',
    ]);
}

$orderId = filter_var(
    $_GET['order_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($orderId === false) {
    customer_receipt_respond(400, [
        'ok' => false,
        'error' => 'A valid order_id is required.',
    ]);
}

try {
    $userId = (int) $_SESSION['user_id'];
    $hasDeliveries = customer_has_deliveries_table($pdo);
    $deliveryFeeSelect = $hasDeliveries
        ? 'd.delivery_fee'
        : 'NULL AS delivery_fee';
    $deliveryJoin = $hasDeliveries
        ? 'LEFT JOIN deliveries d ON d.order_id = o.order_id'
        : '';
    $deliveryNotesSelect = $hasDeliveries
        ? 'd.notes AS delivery_notes'
        : 'NULL AS delivery_notes';

    $orderStatement = $pdo->prepare(
        "SELECT o.order_id, o.order_date, o.order_status, o.total_amount,
                op.payment_status,
                u.first_name, u.last_name, u.email,
                a.address_line, a.city, a.province, a.postal_code,
                $deliveryFeeSelect, $deliveryNotesSelect
         FROM orders o
         LEFT JOIN order_payments op ON op.order_id = o.order_id
         INNER JOIN users u ON u.user_id = o.user_id
         INNER JOIN addresses a ON a.address_id = o.address_id
         $deliveryJoin
         WHERE o.order_id = ? AND o.user_id = ?
         LIMIT 1"
    );
    $orderStatement->execute([(int) $orderId, $userId]);
    $order = $orderStatement->fetch();

    if (!$order) {
        customer_receipt_respond(404, [
            'ok' => false,
            'error' => 'Receipt not found.',
        ]);
    }
    if ($order['payment_status'] !== null && $order['payment_status'] !== 'PAID') {
        customer_receipt_respond(409, [
            'ok' => false,
            'error' => 'This receipt will be available after payment is confirmed.',
        ]);
    }

    $itemsStatement = $pdo->prepare(
        'SELECT p.product_name, s.size_name, oi.quantity, oi.unit_price, oi.subtotal
         FROM order_items oi
         LEFT JOIN products p ON p.product_id = oi.product_id
         LEFT JOIN sizes s ON s.size_id = oi.size_id
         WHERE oi.order_id = ?
         ORDER BY oi.order_item_id ASC'
    );
    $itemsStatement->execute([(int) $orderId]);
    $orderItems = $itemsStatement->fetchAll();

    $items = [];
    $subtotalCents = 0;

    foreach ($orderItems as $item) {
        $unitPriceCents = (int) round((float) $item['unit_price'] * 100);
        $lineTotalCents = (int) round((float) $item['subtotal'] * 100);
        $subtotalCents += $lineTotalCents;

        $items[] = [
            'name' => $item['product_name'],
            'size' => $item['size_name'],
            'quantity' => (int) $item['quantity'],
            'unit_price' => customer_receipt_money($unitPriceCents),
            'line_total' => customer_receipt_money($lineTotalCents),
        ];
    }

    $totalCents = (int) round((float) $order['total_amount'] * 100);
    $deliveryFeeCents = $order['delivery_fee'] !== null
        ? (int) round((float) $order['delivery_fee'] * 100)
        : max(0, $totalCents - $subtotalCents);

    customer_receipt_respond(200, [
        'ok' => true,
        'receipt' => [
            'receipt_number' => 'BRW-' . str_pad((string) $order['order_id'], 8, '0', STR_PAD_LEFT),
            'order_id' => (int) $order['order_id'],
            'issued_at' => $order['order_date'],
            'status' => $order['order_status'],
            'currency' => 'PHP',
            'currency_symbol' => '₱',
            'customer' => [
                'name' => trim($order['first_name'] . ' ' . $order['last_name']),
                'email' => $order['email'],
            ],
            'delivery_address' => [
                'address_line' => $order['address_line'],
                'city' => $order['city'],
                'province' => $order['province'],
                'postal_code' => $order['postal_code'],
            ],
            'delivery_notes' => $order['delivery_notes'],
            'items' => $items,
            'subtotal' => customer_receipt_money($subtotalCents),
            'delivery_fee' => customer_receipt_money($deliveryFeeCents),
            'total' => customer_receipt_money($totalCents),
        ],
    ]);
} catch (Throwable $error) {
    error_log('Customer digital receipt API failed: ' . $error->getMessage());
    customer_receipt_respond(500, [
        'ok' => false,
        'error' => 'Could not retrieve this receipt. Please try again.',
    ]);
}

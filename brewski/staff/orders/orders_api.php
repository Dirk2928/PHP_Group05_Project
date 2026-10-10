<?php

require_once __DIR__ . '/../../login-signup/session_init.php';
require_once __DIR__ . '/../../Db/connection.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!brewski_is_logged_in() || !in_array(brewski_current_role(), ['STAFF', 'ADMIN'], true)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'You must be signed in as staff to manage orders.']);
    exit;
}

$csrfToken = (string) ($_SESSION['csrf_token'] ?? '');

$transitions = [
    'PENDING'   => ['CONFIRMED', 'CANCELLED'],
    'CONFIRMED' => ['PREPARING', 'CANCELLED'],
    'PREPARING' => ['READY', 'CANCELLED'],
    'READY'     => ['COMPLETED', 'CANCELLED'],
    'COMPLETED' => [],
    'CANCELLED' => [],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        $csrfToken === ''
        || !is_string($submittedToken)
        || !hash_equals($csrfToken, $submittedToken)
    ) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Your session expired. Please reload the page.']);
        exit;
    }

    session_write_close();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'update_status') {

        $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
        $newStatus = strtoupper(trim((string) ($_POST['status'] ?? '')));

        if ($orderId === false || $orderId <= 0) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Please choose a valid order.']);
            exit;
        }

        if ($newStatus === '' || !preg_match('/^[A-Z_]+$/', $newStatus)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Please choose a valid order status.']);
            exit;
        }

        $statement = $pdo->prepare('SELECT order_status FROM orders WHERE order_id = ? LIMIT 1');
        $statement->execute([(int) $orderId]);
        $currentStatus = $statement->fetchColumn();

        if ($currentStatus === false) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'That order no longer exists.']);
            exit;
        }

        $currentStatus = strtoupper((string) $currentStatus);

        if (!in_array($newStatus, $transitions[$currentStatus] ?? [], true)) {
            http_response_code(422);
            echo json_encode([
                'ok'    => false,
                'error' => 'An order that is ' . $currentStatus . ' cannot be changed to ' . $newStatus . '.',
            ]);
            exit;
        }

        $update = $pdo->prepare('UPDATE orders SET order_status = ? WHERE order_id = ?');
        $update->execute([$newStatus, (int) $orderId]);

        echo json_encode([
            'ok'      => true,
            'message' => 'Order #' . (int) $orderId . ' is now ' . $newStatus . '.',
        ]);
        exit;
    }

    if ($action === 'toggle_product') {

        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);

        if ($productId === false || $productId <= 0) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Please choose a valid product.']);
            exit;
        }

        $statement = $pdo->prepare('SELECT availability FROM products WHERE product_id = ? LIMIT 1');
        $statement->execute([(int) $productId]);
        $currentAvailability = $statement->fetchColumn();

        if ($currentAvailability === false) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'That product no longer exists.']);
            exit;
        }

        $newAvailability = ((int) $currentAvailability) === 1 ? 0 : 1;

        $update = $pdo->prepare('UPDATE products SET availability = ? WHERE product_id = ?');
        $update->execute([$newAvailability, (int) $productId]);

        echo json_encode([
            'ok'         => true,
            'product_id' => (int) $productId,
            'available'  => $newAvailability === 1,
            'message'    => 'Product availability updated.',
        ]);
        exit;
    }

    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'That action is not recognised.']);
    exit;
}

session_write_close();

try {

    $orders = [];

    $result = $pdo->query(
        "SELECT o.order_id,
                o.order_status AS status,
                o.total_amount,
                o.order_date AS created_at,
                TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS customer
           FROM orders o
           LEFT JOIN users u ON u.user_id = o.user_id
          ORDER BY o.order_date DESC, o.order_id DESC
          LIMIT 100"
    );

    foreach ($result as $row) {
        $row['order_id'] = (int) $row['order_id'];
        $row['total_amount'] = (float) $row['total_amount'];
        $row['items'] = [];

        $orders[$row['order_id']] = $row;
    }

    if ($orders) {
        $ids = array_keys($orders);
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        $itemStatement = $pdo->prepare(
            "SELECT oi.order_id,
                    oi.quantity,
                    COALESCE(p.product_name, 'Unknown item') AS name
               FROM order_items oi
               LEFT JOIN products p ON p.product_id = oi.product_id
              WHERE oi.order_id IN (" . $placeholders . ')'
        );
        $itemStatement->execute($ids);

        foreach ($itemStatement as $item) {
            $orders[(int) $item['order_id']]['items'][] = [
                'name'     => (string) $item['name'],
                'quantity' => (int) $item['quantity'],
            ];
        }
    }

    $products = $pdo->query(
        "SELECT p.product_id,
                p.product_name AS name,
                COALESCE(c.category_name, 'Uncategorised') AS category,
                p.price,
                p.availability
           FROM products p
           LEFT JOIN categories c ON c.category_id = p.category_id
          ORDER BY category ASC, p.product_name ASC"
    )->fetchAll();

    $productList = [];

    foreach ($products as $product) {
        $productList[] = [
            'id'        => (int) $product['product_id'],
            'name'      => (string) $product['name'],
            'category'  => (string) $product['category'],
            'price'     => (float) $product['price'],
            'available' => (int) $product['availability'] === 1,
        ];
    }

} catch (PDOException $exception) {

    error_log('orders_api query failed: ' . $exception->getMessage());

    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'We could not load the orders right now.']);
    exit;
}

echo json_encode([
    'ok'       => true,
    'orders'   => array_values($orders),
    'products' => $productList,
]);

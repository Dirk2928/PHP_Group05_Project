<?php

require_once __DIR__ . '/../../login-signup/session_init.php';
require_once __DIR__ . '/../../Db/connection.php';
require_once __DIR__ . '/../../Db/order_customizations.php';
require_once __DIR__ . '/../../Db/activity_log.php';

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

        $pdo->beginTransaction();
        $statement = $pdo->prepare(
            'SELECT o.order_status, op.payment_status, op.provider
               FROM orders o
               LEFT JOIN order_payments op ON op.order_id = o.order_id
              WHERE o.order_id = ?
              LIMIT 1 FOR UPDATE'
        );
        $statement->execute([(int) $orderId]);
        $currentOrder = $statement->fetch();

        if (!$currentOrder) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'That order no longer exists.']);
            exit;
        }

        if (
            $currentOrder['payment_status'] !== null
            && $currentOrder['payment_status'] !== 'PAID'
            && $currentOrder['provider'] !== 'in_store'
        ) {
            $pdo->rollBack();
            http_response_code(422);
            echo json_encode([
                'ok' => false,
                'error' => 'This order cannot be fulfilled until payment is confirmed.',
            ]);
            exit;
        }

        $currentStatus = strtoupper((string) $currentOrder['order_status']);

        if (!in_array($newStatus, $transitions[$currentStatus] ?? [], true)) {
            $pdo->rollBack();
            http_response_code(422);
            echo json_encode([
                'ok'    => false,
                'error' => 'An order that is ' . $currentStatus . ' cannot be changed to ' . $newStatus . '.',
            ]);
            exit;
        }

        $update = $pdo->prepare('UPDATE orders SET order_status = ? WHERE order_id = ?');
        $update->execute([$newStatus, (int) $orderId]);
        if ($currentOrder['provider'] === 'in_store' && $currentOrder['payment_status'] === 'PENDING') {
            if ($newStatus === 'COMPLETED') {
                $pdo->prepare(
                    "UPDATE order_payments SET payment_status = 'PAID' WHERE order_id = ? AND provider = 'in_store'"
                )->execute([(int) $orderId]);
            } elseif ($newStatus === 'CANCELLED') {
                $pdo->prepare(
                    "UPDATE order_payments SET payment_status = 'FAILED' WHERE order_id = ? AND provider = 'in_store'"
                )->execute([(int) $orderId]);
            }
        }
        brewski_record_activity(
            $pdo,
            'orders',
            'Updated order status',
            'Changed order #' . (int) $orderId . ' from ' . $currentStatus . ' to ' . $newStatus . '.',
            'order',
            (int) $orderId
        );
        $pdo->commit();

        if ($currentOrder['provider'] === 'in_store' && $newStatus === 'COMPLETED') {
            try {
                require_once __DIR__ . '/../../customer/partials/order_support.php';
                if (customer_send_order_receipt($pdo, (int) $orderId)) {
                    $pdo->prepare('UPDATE order_payments SET receipt_email_sent = TRUE WHERE order_id = ?')
                        ->execute([(int) $orderId]);
                }
            } catch (Throwable $error) {
                error_log('Could not email store pickup receipt for order ' . (int) $orderId . ': ' . $error->getMessage());
            }
        }

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
        brewski_record_activity(
            $pdo,
            'inventory',
            $newAvailability === 1 ? 'Made product available' : 'Paused product',
            'Changed availability for product #' . (int) $productId . '.',
            'product',
            (int) $productId
        );

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
    brewski_ensure_order_customization_columns($pdo);

    $orders = [];

    $result = $pdo->query(
        "SELECT o.order_id,
                o.order_status AS status,
                o.total_amount,
                o.order_date AS created_at,
                o.order_notes,
                TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS customer,
                op.provider AS payment_provider,
                op.payment_status
           FROM orders o
          LEFT JOIN order_payments op ON op.order_id = o.order_id
           LEFT JOIN users u ON u.user_id = o.user_id
          WHERE op.payment_status IS NULL OR op.payment_status = 'PAID' OR op.provider = 'in_store'
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
                    oi.customization_summary,
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
                'customization' => (string) ($item['customization_summary'] ?? ''),
            ];
        }
    }

    $products = $pdo->query(
        "SELECT p.product_id,
                p.product_name AS name,
                COALESCE(c.category_name, 'Uncategorised') AS category,
                p.price,
                p.availability,
                p.image_path,
                p.image_mime_type
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
            'image_url' => !empty($product['image_mime_type'])
                ? BREWSKI_BASE_URL . '/customer/product_image.php?id=' . (int) $product['product_id']
                : BREWSKI_BASE_URL . '/images/' . rawurlencode((string) ($product['image_path'] ?: 'brewskilogo.png')),
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

<?php

require_once __DIR__ . '/../partials/bootstrap.php';
require_once __DIR__ . '/../../Db/connection.php';

header('Content-Type: application/json');

// --- Auth guard ---
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not logged in.']);
    exit;
}

// --- Only accept POST ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

// --- CSRF ---
$token = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid session token.']);
    exit;
}

$userId    = (int) $_SESSION['user_id'];
$addressId = (int) ($_POST['address_id'] ?? 0);
$notes     = trim($_POST['notes'] ?? '');

if ($addressId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please select a delivery address.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Confirm the address belongs to this user
    $stmt = $pdo->prepare('SELECT address_id FROM addresses WHERE address_id = ? AND user_id = ?');
    $stmt->execute([$addressId, $userId]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Invalid delivery address.');
    }

    // 2. Get the cart
    $stmt = $pdo->prepare(
        'SELECT cart_id FROM carts
         WHERE user_id = ?
         ORDER BY cart_id ASC
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    $cartId = $stmt->fetchColumn();

    if (!$cartId) {
        throw new RuntimeException('Your cart is empty.');
    }

    // 3. Load cart items
    $stmt = $pdo->prepare(
        'SELECT ci.product_id, ci.size_id, ci.quantity, ci.unit_price
         FROM cart_items ci
         WHERE ci.cart_id = ?'
    );
    $stmt->execute([$cartId]);
    $items = $stmt->fetchAll();

    if (!$items) {
        throw new RuntimeException('Your cart is empty.');
    }

    // 4. Calculate subtotal
    $subtotal = 0.0;
    foreach ($items as $it) {
        $subtotal += (float) $it['unit_price'] * (int) $it['quantity'];
    }

    // 5. Delivery fee (flat ₱50, free over ₱500)
    $deliveryFee = $subtotal >= 500 ? 0.00 : 50.00;
    $total       = $subtotal + $deliveryFee;

    // 6. Insert the order
    $stmt = $pdo->prepare(
        'INSERT INTO orders (user_id, address_id, total_amount, order_status)
         VALUES (?, ?, ?, "PENDING")'
    );
    $stmt->execute([$userId, $addressId, $total]);
    $orderId = (int) $pdo->lastInsertId();

    // 7. Insert order items
    $stmtItem = $pdo->prepare(
        'INSERT INTO order_items (order_id, product_id, size_id, quantity, unit_price, subtotal)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($items as $it) {
        $lineTotal = (float) $it['unit_price'] * (int) $it['quantity'];
        $stmtItem->execute([
            $orderId,
            (int) $it['product_id'],
            (int) $it['size_id'],
            (int) $it['quantity'],
            (float) $it['unit_price'],
            $lineTotal,
        ]);
    }

    // 8. Insert the delivery row (UNASSIGNED until staff picks it up)
    $stmt = $pdo->prepare(
        'INSERT INTO deliveries (order_id, delivery_status, delivery_fee, notes)
         VALUES (?, "UNASSIGNED", ?, ?)'
    );
    $stmt->execute([$orderId, $deliveryFee, $notes !== '' ? $notes : null]);

    // 9. Clear the cart
    $pdo->prepare('DELETE FROM cart_items WHERE cart_id = ?')->execute([$cartId]);

    $pdo->commit();

    echo json_encode([
        'ok'       => true,
        'order_id' => $orderId,
        'total'    => number_format($total, 2, '.', ''),
        'redirect' => '../customer_orders/order_success.php?order_id=' . $orderId,
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
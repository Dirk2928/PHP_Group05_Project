<?php

require_once __DIR__ . '/../partials/bootstrap.php';
require_once __DIR__ . '/../../Db/connection.php';
require_once __DIR__ . '/../partials/order_support.php';
require_once __DIR__ . '/../../login-signup/email-service-client.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not logged in.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$token = $_POST['csrf_token'] ?? '';

if (
    empty($_SESSION['csrf_token'])
    || !is_string($token)
    || !hash_equals($_SESSION['csrf_token'], $token)
) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid session token.']);
    exit;
}

$userId    = (int) $_SESSION['user_id'];
$addressId = (int) ($_POST['address_id'] ?? 0);
$notes     = trim((string) ($_POST['notes'] ?? ''));

if ($addressId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please select a delivery address.']);
    exit;
}

if (mb_strlen($notes) > 500) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Delivery notes must be 500 characters or fewer.']);
    exit;
}

$hasDeliveries = customer_has_deliveries_table($pdo);

if ($notes !== '' && !$hasDeliveries) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'error' => 'Custom delivery notes are unavailable right now. Please try again later.',
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT address_id FROM addresses WHERE address_id = ? AND user_id = ?');
    $stmt->execute([$addressId, $userId]);

    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Invalid delivery address.');
    }

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

    $subtotal = 0.0;

    foreach ($items as $it) {
        $subtotal += (float) $it['unit_price'] * (int) $it['quantity'];
    }

    $deliveryFee = $subtotal >= 500 ? 0.00 : 50.00;
    $total       = $subtotal + $deliveryFee;

    $stmt = $pdo->prepare(
        'INSERT INTO orders (user_id, address_id, total_amount, order_status)
         VALUES (?, ?, ?, "PENDING")'
    );
    $stmt->execute([$userId, $addressId, $total]);
    $orderId = (int) $pdo->lastInsertId();

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

    if ($hasDeliveries) {
        $stmt = $pdo->prepare(
            'INSERT INTO deliveries (order_id, delivery_status, delivery_fee, notes)
             VALUES (?, "UNASSIGNED", ?, ?)'
        );
        $stmt->execute([$orderId, $deliveryFee, $notes !== '' ? $notes : null]);
    }

    $pdo->prepare('DELETE FROM cart_items WHERE cart_id = ?')->execute([$cartId]);

    $pdo->commit();

    $receiptEmailSent = false;
    try {
        $notesSelect = $hasDeliveries
            ? 'd.notes AS delivery_notes'
            : 'NULL AS delivery_notes';
        $deliveryJoin = $hasDeliveries
            ? 'LEFT JOIN deliveries d ON d.order_id = o.order_id'
            : '';
        $receiptStatement = $pdo->prepare(
            'SELECT u.email, u.first_name, u.last_name, o.order_date, o.total_amount,
                    a.address_line, a.city, a.province, a.postal_code,
                    ' . $notesSelect . '
             FROM orders o
             INNER JOIN users u ON u.user_id = o.user_id
             INNER JOIN addresses a ON a.address_id = o.address_id
             ' . $deliveryJoin . '
             WHERE o.order_id = ? AND o.user_id = ?
             LIMIT 1'
        );
        $receiptStatement->execute([$orderId, $userId]);
        $receiptOrder = $receiptStatement->fetch();

        $receiptItemsStatement = $pdo->prepare(
            'SELECT p.product_name, s.size_name, oi.quantity, oi.unit_price, oi.subtotal
             FROM order_items oi
             LEFT JOIN products p ON p.product_id = oi.product_id
             LEFT JOIN sizes s ON s.size_id = oi.size_id
             WHERE oi.order_id = ?
             ORDER BY oi.order_item_id ASC'
        );
        $receiptItemsStatement->execute([$orderId]);
        $receiptItems = $receiptItemsStatement->fetchAll();

        if (!$receiptOrder || !$receiptItems) {
            throw new RuntimeException('Saved order details are unavailable for the receipt email.');
        }

        $emailItems = [];
        $subtotalCents = 0;
        foreach ($receiptItems as $receiptItem) {
            $unitPriceCents = (int) round((float) $receiptItem['unit_price'] * 100);
            $lineTotalCents = (int) round((float) $receiptItem['subtotal'] * 100);
            $subtotalCents += $lineTotalCents;
            $emailItems[] = [
                'name' => (string) $receiptItem['product_name'],
                'size' => (string) $receiptItem['size_name'],
                'quantity' => (int) $receiptItem['quantity'],
                'unitPrice' => number_format($unitPriceCents / 100, 2, '.', ''),
                'lineTotal' => number_format($lineTotalCents / 100, 2, '.', ''),
            ];
        }

        $totalCents = (int) round((float) $receiptOrder['total_amount'] * 100);
        $receiptEmailSent = email_service_send('send-order-receipt', [
            'email' => (string) $receiptOrder['email'],
            'customerName' => trim($receiptOrder['first_name'] . ' ' . $receiptOrder['last_name']),
            'receiptNumber' => 'BRW-' . str_pad((string) $orderId, 8, '0', STR_PAD_LEFT),
            'orderDate' => (string) $receiptOrder['order_date'],
            'address' => [
                'addressLine' => (string) $receiptOrder['address_line'],
                'city' => (string) $receiptOrder['city'],
                'province' => (string) $receiptOrder['province'],
                'postalCode' => (string) $receiptOrder['postal_code'],
            ],
            'deliveryNotes' => (string) ($receiptOrder['delivery_notes'] ?? ''),
            'items' => $emailItems,
            'subtotal' => number_format($subtotalCents / 100, 2, '.', ''),
            'deliveryFee' => number_format($deliveryFee, 2, '.', ''),
            'total' => number_format($totalCents / 100, 2, '.', ''),
        ]);
    } catch (Throwable $emailError) {
        error_log('Could not email receipt for order ' . $orderId . ': ' . $emailError->getMessage());
    }

    echo json_encode([
        'ok'                 => true,
        'order_id'           => $orderId,
        'total'              => number_format($total, 2, '.', ''),
        'receipt_email_sent' => $receiptEmailSent,
        'redirect'           => '../customer_orders/order_success.php?order_id=' . $orderId
            . '&receipt_email_sent=' . ($receiptEmailSent ? '1' : '0'),
    ]);

} catch (RuntimeException $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('place_order failed: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'We could not place your order. Please try again.']);
}

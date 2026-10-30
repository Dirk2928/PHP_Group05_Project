<?php

ob_start();
ini_set('display_errors', '0');

require_once __DIR__ . '/../partials/bootstrap.php';
require_once __DIR__ . '/../../Db/connection.php';
require_once __DIR__ . '/../../Db/order_customizations.php';
require_once __DIR__ . '/../../Db/db_config.php';
require_once __DIR__ . '/../partials/order_support.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function customer_place_order_respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

register_shutdown_function(static function (): void {
    $body = (string) ob_get_clean();

    if (is_array(json_decode($body, true))) {
        echo $body;
        return;
    }

    $fatal = error_get_last();
    $reason = trim($body);
    if ($reason === '') {
        $reason = (string) ($fatal['message'] ?? 'The script stopped before it could reply.');
    }
    error_log('place_order.php returned a non-JSON response: ' . $reason);

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok' => false,
        'error' => 'We could not start secure checkout. Please try again.',
    ]);
});

if (empty($_SESSION['user_id'])) {
    customer_place_order_respond(401, ['ok' => false, 'error' => 'Sign in to continue.']);
}
if (brewski_current_role() !== 'CUSTOMER') {
    customer_place_order_respond(403, [
        'ok' => false,
        'error' => 'Only customer accounts can place orders.',
    ]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    customer_place_order_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

$submittedToken = $_POST['csrf_token'] ?? '';
if (
    empty($_SESSION['csrf_token'])
    || !is_string($submittedToken)
    || !hash_equals($_SESSION['csrf_token'], $submittedToken)
) {
    customer_place_order_respond(403, ['ok' => false, 'error' => 'Invalid session token.']);
}

if ((string) ($_POST['action'] ?? '') !== 'create_payment') {
    customer_place_order_respond(422, ['ok' => false, 'error' => 'Invalid payment request.']);
}

$paymentMode = (string) ($_POST['payment_mode'] ?? 'online');
if (!in_array($paymentMode, ['online', 'in_store'], true)) {
    customer_place_order_respond(422, ['ok' => false, 'error' => 'Please choose a payment method.']);
}
$submittedOrderNotes = $_POST['order_notes'] ?? '';
if (!is_string($submittedOrderNotes)) {
    customer_place_order_respond(422, [
        'ok' => false,
        'error' => 'Order notes must be plain text.',
    ]);
}
$orderNotes = trim($submittedOrderNotes);
if (mb_strlen($orderNotes, 'UTF-8') > 1000) {
    customer_place_order_respond(422, [
        'ok' => false,
        'error' => 'Order notes must be 1,000 characters or fewer.',
    ]);
}

$userId = (int) $_SESSION['user_id'];
$publicBase = $paymentMode === 'online' ? brewski_app_public_url() : null;
if ($paymentMode === 'online' && $publicBase === null) {
    error_log('place_order: brewski_app_public_url() returned null (check APP_URL / env config on Render).');
    customer_place_order_respond(503, [
        'ok' => false,
        'error' => 'Secure checkout is not configured for this site yet.',
    ]);
}
$publicBase = $publicBase !== null ? rtrim($publicBase, '/') : null;

try {
    brewski_ensure_order_customization_columns($pdo);
    $pdo->beginTransaction();

    $cartStatement = $pdo->prepare(
        'SELECT cart_id FROM carts WHERE user_id = ? ORDER BY cart_id ASC LIMIT 1 FOR UPDATE'
    );
    $cartStatement->execute([$userId]);
    $cartId = $cartStatement->fetchColumn();
    if (!$cartId) {
        throw new RuntimeException('Your cart is empty.');
    }

    $stalePaymentResolved = false;
    if ($paymentMode === 'online') {
        $pendingStatement = $pdo->prepare(
            "SELECT op.payment_id, op.order_id, op.provider_session_id
               FROM order_payments op
               INNER JOIN orders o ON o.order_id = op.order_id
              WHERE o.user_id = ? AND op.provider = 'paymongo' AND op.payment_status = 'PENDING'
              ORDER BY op.payment_id DESC
              LIMIT 1
              FOR UPDATE"
        );
        $pendingStatement->execute([$userId]);
        $pendingPayment = $pendingStatement->fetch();
        if ($pendingPayment) {
            $sessionLookupFailed = false;
            $sessionResponse = [];
            try {
                $sessionResponse = customer_paymongo_request(
                    'GET',
                    'checkout_sessions/' . rawurlencode((string) $pendingPayment['provider_session_id'])
                );
            } catch (Throwable $lookupError) {
                $sessionLookupFailed = true;
                error_log(sprintf(
                    'place_order: could not look up pending PayMongo session %s for user %d (payment_id %d): %s',
                    (string) $pendingPayment['provider_session_id'],
                    $userId,
                    (int) $pendingPayment['payment_id'],
                    $lookupError->getMessage()
                ));
            }

            if ($sessionLookupFailed) {
                customer_mark_payment_failed(
                    $pdo,
                    (int) $pendingPayment['payment_id'],
                    (int) $pendingPayment['order_id']
                );
                $stalePaymentResolved = true;
            } else {
                $sessionAttributes = $sessionResponse['data']['attributes'] ?? [];
                $sessionStatus = (string) ($sessionAttributes['status'] ?? '');
                $existingCheckoutUrl = (string) ($sessionAttributes['checkout_url'] ?? '');
                if (
                    customer_paymongo_session_is_paid($sessionAttributes)
                    || in_array($sessionStatus, ['completed', 'paid'], true)
                ) {
                    throw new RuntimeException('Payment is being confirmed. Please check My orders shortly.');
                }
                if ($sessionStatus === 'active') {
                    if (
                        !filter_var($existingCheckoutUrl, FILTER_VALIDATE_URL)
                        || parse_url($existingCheckoutUrl, PHP_URL_SCHEME) !== 'https'
                        || parse_url($existingCheckoutUrl, PHP_URL_HOST) !== 'checkout.paymongo.com'
                    ) {
                        throw new RuntimeException('The payment provider returned an invalid checkout link.');
                    }
                    $pdo->commit();
                    customer_place_order_respond(200, [
                        'ok' => true,
                        'redirect' => $existingCheckoutUrl,
                    ]);
                }
                if (!in_array($sessionStatus, ['expired', 'failed'], true)) {
                    error_log('place_order: pending session returned unexpected status "' . $sessionStatus . '"');
                    throw new RuntimeException('Could not verify your current payment. Please try again shortly.');
                }

                customer_mark_payment_failed(
                    $pdo,
                    (int) $pendingPayment['payment_id'],
                    (int) $pendingPayment['order_id']
                );
                $stalePaymentResolved = true;
            }
        }

    }

    if ($stalePaymentResolved) {
        $pdo->commit();
        $pdo->beginTransaction();
        $cartStatement = $pdo->prepare(
            'SELECT cart_id FROM carts WHERE user_id = ? ORDER BY cart_id ASC LIMIT 1 FOR UPDATE'
        );
        $cartStatement->execute([$userId]);
        $cartId = $cartStatement->fetchColumn();
        if (!$cartId) {
            throw new RuntimeException('Your cart is empty.');
        }
    }

    $itemsStatement = $pdo->prepare(
        'SELECT product_id, size_id, quantity, unit_price, customization_summary
           FROM cart_items
          WHERE cart_id = ?
          ORDER BY cart_item_id ASC'
    );
    $itemsStatement->execute([(int) $cartId]);
    $items = $itemsStatement->fetchAll();
    if (!$items) {
        throw new RuntimeException('Your cart is empty.');
    }

    $subtotalCents = 0;
    foreach ($items as $item) {
        if ((int) $item['quantity'] < 1 || (float) $item['unit_price'] < 0) {
            throw new RuntimeException('Your cart contains an invalid item. Please refresh and try again.');
        }
        $subtotalCents += (int) round((float) $item['unit_price'] * 100) * (int) $item['quantity'];
    }
    $totalCents = $subtotalCents;

    $orderColumns = $pdo->query('SHOW COLUMNS FROM orders')->fetchAll(PDO::FETCH_COLUMN);
    $orderColumnNames = array_fill_keys($orderColumns, true);
    if (isset($orderColumnNames['address_id'])) {
        $addressColumn = $pdo->query("SHOW COLUMNS FROM orders LIKE 'address_id'")->fetch();
        if ($addressColumn && strtoupper((string) ($addressColumn['Null'] ?? '')) === 'NO'
            && ($addressColumn['Default'] ?? null) === null) {
            throw new RuntimeException(
                'Pickup checkout needs the database update 20261026_allow_store_pickup.sql. Please contact support.'
            );
        }
    }

    $orderAmount = number_format($totalCents / 100, 2, '.', '');
    if (isset($orderColumnNames['order_notes'])) {
        $orderStatement = $pdo->prepare(
            "INSERT INTO orders (user_id, total_amount, order_status, order_notes)
             VALUES (?, ?, 'PENDING', ?)"
        );
        $orderStatement->execute([$userId, $orderAmount, $orderNotes !== '' ? $orderNotes : null]);
    } else {
        $orderStatement = $pdo->prepare(
            "INSERT INTO orders (user_id, total_amount, order_status)
             VALUES (?, ?, 'PENDING')"
        );
        $orderStatement->execute([$userId, $orderAmount]);
    }
    $orderId = (int) $pdo->lastInsertId();

    $orderItemStatement = $pdo->prepare(
        'INSERT INTO order_items (order_id, product_id, size_id, quantity, unit_price, subtotal, customization_summary)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($items as $item) {
        $lineCents = (int) round((float) $item['unit_price'] * 100) * (int) $item['quantity'];
        $orderItemStatement->execute([
            $orderId,
            (int) $item['product_id'],
            (int) $item['size_id'],
            (int) $item['quantity'],
            number_format((float) $item['unit_price'], 2, '.', ''),
            number_format($lineCents / 100, 2, '.', ''),
            $item['customization_summary'],
        ]);
    }

    if ($paymentMode === 'in_store') {
        $paymentStatement = $pdo->prepare(
            "INSERT INTO order_payments
                (order_id, provider, provider_session_id, checkout_url, payment_status, payment_method)
             VALUES (?, 'in_store', ?, '', 'PENDING', 'cash')"
        );
        $paymentStatement->execute([$orderId, 'in-store-' . $orderId]);
        $pdo->prepare('DELETE FROM cart_items WHERE cart_id = ?')->execute([(int) $cartId]);
        $pdo->commit();
        customer_place_order_respond(200, [
            'ok' => true,
            'order_id' => $orderId,
            'redirect' => '../customer_orders/order_success.php?order_id=' . $orderId,
        ]);
    }

    $successUrl = $publicBase . '/customer/checkout/payment_return.php?order_id=' . $orderId;
    $cancelUrl = $publicBase . '/customer/checkout/payment_cancel.php?order_id=' . $orderId;

    $allowedPaymentMethods = [
        'card', 'gcash', 'paymaya', 'grab_pay', 'qrph', 'billease', 'dob', 'brankas', 'shopeepay',
    ];
    $configuredPaymentMethods = trim((string) getenv('BREWSKI_PAYMONGO_PAYMENT_METHODS'));
    $paymentMethodTypes = $configuredPaymentMethods === ''
        ? ['qrph']
        : array_values(array_intersect(
            $allowedPaymentMethods,
            array_map('trim', explode(',', strtolower($configuredPaymentMethods)))
        ));
    if ($paymentMethodTypes === []) {
        throw new RuntimeException('No supported PayMongo payment methods are configured.');
    }

    $sessionResponse = customer_paymongo_request('POST', 'checkout_sessions', [
        'data' => [
            'attributes' => [
                'line_items' => [[
                    'name' => 'Brewski order #' . $orderId,
                    'amount' => $totalCents,
                    'currency' => 'PHP',
                    'quantity' => 1,
                ]],
                'payment_method_types' => $paymentMethodTypes,
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'reference_number' => 'BRW-' . $orderId,
                'send_email_receipt' => false,
            ],
        ],
    ]);

    $sessionId = (string) ($sessionResponse['data']['id'] ?? '');
    $checkoutUrl = (string) ($sessionResponse['data']['attributes']['checkout_url'] ?? '');
    if (
        $sessionId === ''
        || !filter_var($checkoutUrl, FILTER_VALIDATE_URL)
        || parse_url($checkoutUrl, PHP_URL_SCHEME) !== 'https'
        || parse_url($checkoutUrl, PHP_URL_HOST) !== 'checkout.paymongo.com'
    ) {
        error_log('place_order: PayMongo returned no usable checkout session for order #' . $orderId);
        throw new RuntimeException('The payment provider returned an invalid checkout link.');
    }

    $paymentStatement = $pdo->prepare(
        'INSERT INTO order_payments (order_id, provider_session_id, checkout_url)
         VALUES (?, ?, ?)'
    );
    $paymentStatement->execute([$orderId, $sessionId, brewski_personal_encrypt($checkoutUrl, 'checkout_url')]);
    $pdo->commit();

    customer_place_order_respond(200, [
        'ok' => true,
        'order_id' => $orderId,
        'redirect' => $checkoutUrl,
    ]);
} catch (RuntimeException $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log(sprintf(
        'place_order RuntimeException (user %d): %s at %s:%d',
        $userId,
        $error->getMessage(),
        basename($error->getFile()),
        $error->getLine()
    ));
    customer_place_order_respond(422, [
        'ok' => false,
        'error' => $error->getMessage(),
    ]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log(sprintf(
        'PayMongo checkout creation failed (user %d): %s: %s at %s:%d',
        $userId,
        get_class($error),
        $error->getMessage(),
        basename($error->getFile()),
        $error->getLine()
    ));
    customer_place_order_respond(500, [
        'ok' => false,
        'error' => 'We could not start secure checkout. Please try again.',
    ]);
}

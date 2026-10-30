<?php

function customer_paymongo_session_is_paid(array $attributes): bool
{
    $payments = $attributes['payments'] ?? [];
    if (!is_array($payments)) {
        return false;
    }

    foreach ($payments as $payment) {
        if (($payment['attributes']['status'] ?? '') === 'paid') {
            return true;
        }
    }

    return false;
}

function customer_mark_payment_failed(PDO $pdo, int $paymentId, int $orderId): bool
{
    $paymentStatement = $pdo->prepare(
        "UPDATE order_payments
            SET payment_status = 'FAILED'
          WHERE payment_id = ? AND order_id = ? AND payment_status = 'PENDING'"
    );
    $paymentStatement->execute([$paymentId, $orderId]);
    if ($paymentStatement->rowCount() !== 1) {
        return false;
    }

    $orderStatement = $pdo->prepare(
        "UPDATE orders SET order_status = 'CANCELLED'
          WHERE order_id = ? AND order_status = 'PENDING'"
    );
    $orderStatement->execute([$orderId]);

    return true;
}

function customer_send_order_receipt(PDO $pdo, int $orderId): bool
{
    require_once __DIR__ . '/../../login-signup/email-service-client.php';

    $statement = $pdo->prepare(
        'SELECT u.email, u.first_name, u.last_name, o.order_date, o.total_amount,
                o.order_status
           FROM orders o
           INNER JOIN users u ON u.user_id = o.user_id
          WHERE o.order_id = ?
          LIMIT 1'
    );
    $statement->execute([$orderId]);
    $order = $statement->fetch();

    if (!$order) {
        throw new RuntimeException('Saved order details are unavailable for the receipt email.');
    }
    $order = brewski_decrypt_user_fields($order);

    $itemsStatement = $pdo->prepare(
        'SELECT p.product_name, s.size_name, oi.quantity, oi.unit_price, oi.subtotal
           FROM order_items oi
           LEFT JOIN products p ON p.product_id = oi.product_id
           LEFT JOIN sizes s ON s.size_id = oi.size_id
          WHERE oi.order_id = ?
          ORDER BY oi.order_item_id ASC'
    );
    $itemsStatement->execute([$orderId]);
    $receiptItems = $itemsStatement->fetchAll();

    if (!$receiptItems) {
        throw new RuntimeException('Saved order items are unavailable for the receipt email.');
    }

    $emailItems = [];
    $subtotalCents = 0;
    foreach ($receiptItems as $item) {
        $unitPriceCents = (int) round((float) $item['unit_price'] * 100);
        $lineTotalCents = (int) round((float) $item['subtotal'] * 100);
        $subtotalCents += $lineTotalCents;
        $emailItems[] = [
            'name' => (string) $item['product_name'],
            'size' => (string) $item['size_name'],
            'quantity' => (int) $item['quantity'],
            'unitPrice' => number_format($unitPriceCents / 100, 2, '.', ''),
            'lineTotal' => number_format($lineTotalCents / 100, 2, '.', ''),
        ];
    }

    $totalCents = (int) round((float) $order['total_amount'] * 100);
    return email_service_send('send-order-receipt', [
        'email' => (string) $order['email'],
        'customerName' => trim($order['first_name'] . ' ' . $order['last_name']),
        'receiptNumber' => 'BRW-' . str_pad((string) $orderId, 8, '0', STR_PAD_LEFT),
        'orderDate' => (string) $order['order_date'],
        'fulfillment' => 'Store pickup',
        'items' => $emailItems,
        'subtotal' => number_format($subtotalCents / 100, 2, '.', ''),
        'total' => number_format($totalCents / 100, 2, '.', ''),
    ]);
}

function customer_paymongo_request(string $method, string $path, ?array $payload = null): array
{
    $secretKey = trim((string) getenv('BREWSKI_PAYMONGO_SECRET_KEY'));

    if ($secretKey === '') {
        throw new RuntimeException('Online payments are not configured yet.');
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('Payment processing is unavailable on this server.');
    }

    $curl = curl_init('https://api.paymongo.com/v2/' . ltrim($path, '/'));
    if ($curl === false) {
        throw new RuntimeException('Could not connect to the payment provider.');
    }

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
    ];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERPWD => $secretKey . ':',
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ];

    if ($payload !== null) {
        $encodedPayload = json_encode($payload);
        if ($encodedPayload === false) {
            curl_close($curl);
            throw new RuntimeException('Could not prepare the payment request.');
        }
        $options[CURLOPT_POSTFIELDS] = $encodedPayload;
    }

    curl_setopt_array($curl, $options);
    $responseBody = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if (!is_string($responseBody)) {
        error_log('PayMongo API request failed; cURL error: ' . $curlError);
        throw new RuntimeException('The payment provider could not be reached. Please try again.');
    }

    $response = json_decode($responseBody, true);
    if (!is_array($response)) {
        error_log('PayMongo API returned an invalid JSON response.');
        throw new RuntimeException('The payment provider returned an invalid response.');
    }

    if ($status < 200 || $status >= 300) {
        $providerErrorCode = (string) ($response['errors'][0]['code'] ?? 'unknown_error');
        $providerDetail = trim((string) ($response['errors'][0]['detail'] ?? ''));
        $providerPointer = trim((string) ($response['errors'][0]['source']['pointer'] ?? ''));
        error_log(
            'PayMongo API rejected a request (HTTP ' . $status . '): '
            . substr($responseBody, 0, 1500)
        );
        $diagnostic = 'PayMongo rejected checkout (HTTP ' . $status . ', ' . $providerErrorCode;
        if ($providerPointer !== '') {
            $diagnostic .= ' at ' . $providerPointer;
        }
        if ($providerDetail !== '') {
            $diagnostic .= '): ' . mb_substr($providerDetail, 0, 180, 'UTF-8');
        } else {
            $diagnostic .= ').';
        }
        throw new RuntimeException($diagnostic);
    }

    return $response;
}

function customer_paymongo_signature_is_valid(string $body, string $signatureHeader, string $secret, bool $liveMode): bool
{
    $parts = [];
    foreach (explode(',', $signatureHeader) as $part) {
        $pair = explode('=', trim($part), 2);
        if (count($pair) === 2) {
            $parts[$pair[0]] = $pair[1];
        }
    }

    $timestamp = $parts['t'] ?? '';
    $signature = $parts[$liveMode ? 'li' : 'te'] ?? '';
    if ($timestamp === '' || $signature === '') {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    return hash_equals($expected, $signature);
}

function customer_complete_paymongo_payment(PDO $pdo, array $session): ?array
{
    $sessionId = (string) ($session['id'] ?? '');
    $attributes = $session['attributes'] ?? [];
    $referenceNumber = (string) ($attributes['reference_number'] ?? '');
    $payments = $attributes['payments'] ?? [];

    if ($sessionId === '' || !is_array($payments)) {
        return null;
    }

    $paidPayment = null;
    foreach ($payments as $payment) {
        if (($payment['attributes']['status'] ?? '') === 'paid') {
            $paidPayment = $payment;
            break;
        }
    }
    if ($paidPayment === null) {
        return null;
    }

    $lineItems = $attributes['line_items'] ?? [];
    if (!is_array($lineItems)) {
        return null;
    }
    $sessionAmount = 0;
    foreach ($lineItems as $lineItem) {
        if (
            !isset($lineItem['amount'], $lineItem['quantity'])
            || strtoupper((string) ($lineItem['currency'] ?? '')) !== 'PHP'
        ) {
            return null;
        }
        $sessionAmount += (int) $lineItem['amount'] * (int) $lineItem['quantity'];
    }

    try {
        $pdo->beginTransaction();
        $statement = $pdo->prepare(
            'SELECT op.order_id, op.payment_status, o.user_id, o.total_amount
               FROM order_payments op
               INNER JOIN orders o ON o.order_id = op.order_id
              WHERE op.provider_session_id = ?
              LIMIT 1
              FOR UPDATE'
        );
        $statement->execute([$sessionId]);
        $paymentRecord = $statement->fetch();

        if (!$paymentRecord) {
            $pdo->rollBack();
            return null;
        }

        $orderId = (int) $paymentRecord['order_id'];
        $expectedReference = 'BRW-' . $orderId;
        $expectedAmount = (int) round((float) $paymentRecord['total_amount'] * 100);
        if ($referenceNumber !== $expectedReference || $sessionAmount !== $expectedAmount) {
            $pdo->rollBack();
            error_log('PayMongo webhook order reference or amount did not match order ' . $orderId . '.');
            return null;
        }

        $method = (string) ($paidPayment['attributes']['source']['type'] ?? '');
        if ($paymentRecord['payment_status'] !== 'PAID') {
            $update = $pdo->prepare(
                "UPDATE order_payments
                    SET payment_status = 'PAID', payment_method = ?
                  WHERE provider_session_id = ? AND payment_status <> 'PAID'"
            );
            $update->execute([$method !== '' ? $method : null, $sessionId]);

            $cartStatement = $pdo->prepare(
                'SELECT cart_id
                   FROM carts
                  WHERE user_id = ?
                  ORDER BY cart_id ASC
                  LIMIT 1
                  FOR UPDATE'
            );
            $cartStatement->execute([(int) $paymentRecord['user_id']]);
            $cartId = $cartStatement->fetchColumn();

            if ($cartId) {
                $cartItemsStatement = $pdo->prepare(
                    'SELECT product_id, size_id, quantity, unit_price
                       FROM cart_items
                      WHERE cart_id = ?
                      ORDER BY product_id, size_id'
                );
                $cartItemsStatement->execute([(int) $cartId]);
                $cartItems = $cartItemsStatement->fetchAll();

                $orderItemsStatement = $pdo->prepare(
                    'SELECT product_id, size_id, quantity, unit_price
                       FROM order_items
                      WHERE order_id = ?
                      ORDER BY product_id, size_id'
                );
                $orderItemsStatement->execute([$orderId]);
                $orderItems = $orderItemsStatement->fetchAll();

                $normalizeItems = static function (array $items): array {
                    return array_map(
                        static fn (array $item): array => [
                            (int) $item['product_id'],
                            (int) $item['size_id'],
                            (int) $item['quantity'],
                            number_format((float) $item['unit_price'], 2, '.', ''),
                        ],
                        $items
                    );
                };

                if ($normalizeItems($cartItems) === $normalizeItems($orderItems)) {
                    $pdo->prepare('DELETE FROM cart_items WHERE cart_id = ?')->execute([(int) $cartId]);
                }
            }
        }

        $pdo->commit();
        $receiptStatement = $pdo->prepare(
            'SELECT receipt_email_sent FROM order_payments WHERE provider_session_id = ?'
        );
        $receiptStatement->execute([$sessionId]);
        $receiptEmailSent = (bool) $receiptStatement->fetchColumn();

        if (!$receiptEmailSent) {
            try {
                $receiptEmailSent = customer_send_order_receipt($pdo, $orderId);
                if ($receiptEmailSent) {
                    $markReceipt = $pdo->prepare(
                        'UPDATE order_payments SET receipt_email_sent = TRUE WHERE provider_session_id = ?'
                    );
                    $markReceipt->execute([$sessionId]);
                }
            } catch (Throwable $emailError) {
                error_log(
                    'Could not email receipt for paid order ' . $orderId . ': '
                    . $emailError->getMessage()
                );
            }
        }

        return [
            'order_id' => $orderId,
            'receipt_email_sent' => $receiptEmailSent,
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

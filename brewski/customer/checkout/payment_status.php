<?php

require_once __DIR__ . '/../partials/bootstrap.php';
require_once __DIR__ . '/../../Db/connection.php';
require_once __DIR__ . '/../partials/order_support.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function customer_payment_status_respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if (empty($_SESSION['user_id'])) {
    customer_payment_status_respond(401, ['ok' => false, 'error' => 'Sign in to view payment status.']);
}
if (brewski_current_role() !== 'CUSTOMER') {
    customer_payment_status_respond(403, ['ok' => false, 'error' => 'Customer access is required.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    customer_payment_status_respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

$orderId = filter_var(
    $_GET['order_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
if ($orderId === false) {
    customer_payment_status_respond(400, ['ok' => false, 'error' => 'A valid order is required.']);
}

$userId = (int) $_SESSION['user_id'];
try {
    $statement = $pdo->prepare(
        'SELECT op.payment_status, op.receipt_email_sent
           FROM order_payments op
           INNER JOIN orders o ON o.order_id = op.order_id
          WHERE o.order_id = ? AND o.user_id = ?
          LIMIT 1'
    );
    $statement->execute([(int) $orderId, $userId]);
    $payment = $statement->fetch();
    if (!$payment) {
        customer_payment_status_respond(404, ['ok' => false, 'error' => 'Payment not found.']);
    }

    $receiptEmailSent = (bool) $payment['receipt_email_sent'];
    if ($payment['payment_status'] === 'PAID' && !$receiptEmailSent) {
        try {
            $receiptEmailSent = customer_send_order_receipt($pdo, (int) $orderId);
            if ($receiptEmailSent) {
                $markReceipt = $pdo->prepare(
                    'UPDATE order_payments SET receipt_email_sent = TRUE WHERE order_id = ?'
                );
                $markReceipt->execute([(int) $orderId]);
            }
        } catch (Throwable $emailError) {
            error_log(
                'Could not send receipt for paid order ' . (int) $orderId . ': '
                . $emailError->getMessage()
            );
        }
    }
} catch (Throwable $error) {
    error_log('Could not load payment status for order ' . (int) $orderId . ': ' . $error->getMessage());
    customer_payment_status_respond(500, [
        'ok' => false,
        'error' => 'Could not retrieve this payment status. Please try again.',
    ]);
}

customer_payment_status_respond(200, [
    'ok' => true,
    'payment_status' => (string) $payment['payment_status'],
    'receipt_email_sent' => $receiptEmailSent,
]);

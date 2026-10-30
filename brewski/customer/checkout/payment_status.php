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
        'SELECT op.payment_id, op.provider_session_id, op.provider,
                op.payment_status, op.receipt_email_sent
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

    if (
        strtoupper((string) $payment['payment_status']) === 'PENDING'
        && strtolower((string) $payment['provider']) === 'paymongo'
    ) {
        $now = time();
        $reconcileChecks = $_SESSION['paymongo_reconcile_checks'] ?? [];
        if (!is_array($reconcileChecks)) {
            $reconcileChecks = [];
        }
        foreach ($reconcileChecks as $checkedOrderId => $checkedAt) {
            if (!is_numeric($checkedAt) || $now - (int) $checkedAt > 900) {
                unset($reconcileChecks[$checkedOrderId]);
            }
        }

        $lastCheck = (int) ($reconcileChecks[(int) $orderId] ?? 0);
        if ($now - $lastCheck >= 8) {
            $reconcileChecks[(int) $orderId] = $now;
            $_SESSION['paymongo_reconcile_checks'] = $reconcileChecks;

            try {
                $sessionResponse = customer_paymongo_request(
                    'GET',
                    'checkout_sessions/' . rawurlencode((string) $payment['provider_session_id'])
                );
                $session = $sessionResponse['data'] ?? [];
                $attributes = $session['attributes'] ?? [];
                $sessionStatus = strtolower((string) ($attributes['status'] ?? ''));
                $sessionIsPaid = customer_paymongo_session_is_paid($attributes)
                    || in_array($sessionStatus, ['paid', 'completed'], true);

                if ($sessionIsPaid) {
                    if (customer_complete_paymongo_payment($pdo, $session) === null) {
                        error_log('PayMongo reports a paid session that could not be matched to order ' . (int) $orderId . '.');
                    }
                } elseif (in_array($sessionStatus, ['expired', 'failed'], true)) {
                    $pdo->beginTransaction();
                    customer_mark_payment_failed($pdo, (int) $payment['payment_id'], (int) $orderId);
                    $pdo->commit();
                }
            } catch (Throwable $reconcileError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log(
                    'Could not reconcile PayMongo payment for order ' . (int) $orderId . ': '
                    . $reconcileError->getMessage()
                );
            }

            $refreshPayment = $pdo->prepare(
                'SELECT payment_status, receipt_email_sent
                   FROM order_payments
                  WHERE order_id = ?
                  LIMIT 1'
            );
            $refreshPayment->execute([(int) $orderId]);
            $payment = $refreshPayment->fetch() ?: $payment;
        }
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

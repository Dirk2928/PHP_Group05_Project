<?php

require_once __DIR__ . '/../partials/bootstrap.php';
require_once __DIR__ . '/../../Db/connection.php';
require_once __DIR__ . '/../partials/order_support.php';

if (empty($_SESSION['user_id']) || brewski_current_role() !== 'CUSTOMER') {
    header('Location: ../../login-signup/login.php');
    exit;
}

$orderId = filter_var(
    $_GET['order_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
if ($orderId === false) {
    header('Location: checkout.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$statement = $pdo->prepare(
    'SELECT op.payment_id, op.provider_session_id, op.payment_status
       FROM order_payments op
       INNER JOIN orders o ON o.order_id = op.order_id
      WHERE o.order_id = ? AND o.user_id = ?
      LIMIT 1'
);
$statement->execute([(int) $orderId, $userId]);
$payment = $statement->fetch();
if (!$payment) {
    header('Location: checkout.php');
    exit;
}
if ($payment['payment_status'] === 'PAID') {
    header('Location: payment_return.php?order_id=' . (int) $orderId);
    exit;
}
if ($payment['payment_status'] !== 'PENDING') {
    header('Location: checkout.php?payment=cancelled');
    exit;
}

$sessionId = (string) $payment['provider_session_id'];
try {
    $sessionResponse = customer_paymongo_request(
        'GET',
        'checkout_sessions/' . rawurlencode($sessionId)
    );
    $sessionAttributes = $sessionResponse['data']['attributes'] ?? [];
    $sessionStatus = (string) ($sessionAttributes['status'] ?? '');
    if (
        in_array($sessionStatus, ['paid', 'completed'], true)
        || customer_paymongo_session_is_paid($sessionAttributes)
    ) {
        header('Location: payment_return.php?order_id=' . (int) $orderId);
        exit;
    }
} catch (Throwable $lookupError) {
    error_log(sprintf(
        'payment_cancel: could not look up PayMongo session %s for user %d, order %d: %s',
        $sessionId,
        $userId,
        (int) $orderId,
        $lookupError->getMessage()
    ));
}

try {
    customer_paymongo_request(
        'POST',
        'checkout_sessions/' . rawurlencode($sessionId) . '/expire'
    );
} catch (Throwable $expireError) {
    error_log(sprintf(
        'payment_cancel: could not expire PayMongo session %s for user %d, order %d: %s',
        $sessionId,
        $userId,
        (int) $orderId,
        $expireError->getMessage()
    ));
}

try {
    $pdo->beginTransaction();
    $lockStatement = $pdo->prepare(
        'SELECT payment_status FROM order_payments WHERE payment_id = ? AND order_id = ? FOR UPDATE'
    );
    $lockStatement->execute([(int) $payment['payment_id'], (int) $orderId]);
    $currentStatus = $lockStatement->fetchColumn();

    if ($currentStatus === 'PAID') {
        $pdo->commit();
        header('Location: payment_return.php?order_id=' . (int) $orderId);
        exit;
    }

    if ($currentStatus === 'PENDING') {
        customer_mark_payment_failed($pdo, (int) $payment['payment_id'], (int) $orderId);
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log(sprintf(
        'payment_cancel: could not mark payment %d failed for user %d, order %d: %s',
        (int) $payment['payment_id'],
        $userId,
        (int) $orderId,
        $error->getMessage()
    ));
}

header('Location: checkout.php?payment=cancelled');
exit;

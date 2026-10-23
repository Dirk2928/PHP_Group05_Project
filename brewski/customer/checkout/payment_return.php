<?php

require_once __DIR__ . '/../partials/bootstrap.php';
require_once __DIR__ . '/../../Db/connection.php';
require_once __DIR__ . '/../partials/order_support.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../../login-signup/login.php');
    exit;
}
if (brewski_current_role() !== 'CUSTOMER') {
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

if ($payment['payment_status'] === 'PENDING') {
    try {
        $sessionResponse = customer_paymongo_request(
            'GET',
            'checkout_sessions/' . rawurlencode((string) $payment['provider_session_id'])
        );
        $sessionAttributes = $sessionResponse['data']['attributes'] ?? [];
        $sessionStatus = (string) ($sessionAttributes['status'] ?? '');
        $sessionIsPaid = customer_paymongo_session_is_paid($sessionAttributes)
            || in_array($sessionStatus, ['paid', 'completed'], true);

        if (!$sessionIsPaid) {
            $pdo->beginTransaction();
            customer_mark_payment_failed($pdo, (int) $payment['payment_id'], (int) $orderId);
            $pdo->commit();
        }
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log(sprintf(
            'payment_return: could not reconcile PayMongo session %s for order %d, user %d: %s',
            (string) $payment['provider_session_id'],
            (int) $orderId,
            $userId,
            $error->getMessage()
        ));
    }
}

$pageTitle = 'Confirming payment | brewski';
$extraStyles = ['customer-typography.css', 'checkout/checkout.css'];
require __DIR__ . '/../partials/header.php';
?>

<main class="payment-return page">
    <section class="payment-return__card" aria-labelledby="payment-return-title">
        <span class="payment-return__icon" aria-hidden="true"><i data-lucide="shield-check"></i></span>
        <p class="payment-return__eyebrow">Secure payment</p>
        <h1 id="payment-return-title">Confirming your payment</h1>
        <p id="payment-return-message" role="status" aria-live="polite">
            PayMongo is confirming your payment. This can take a few moments.
        </p>
        <a id="payment-return-orders" class="payment-return__link" href="../customer_orders/orders.php" hidden>
            View your orders
        </a>
        <a class="payment-return__back" href="checkout.php">Return to checkout</a>
    </section>
</main>

<script>
(function () {
    var message = document.getElementById('payment-return-message');
    var ordersLink = document.getElementById('payment-return-orders');
    var attempts = 0;
    var maximumAttempts = 15;
    var orderId = <?= (int) $orderId ?>;

    function checkPayment() {
        fetch('payment_status.php?order_id=' + encodeURIComponent(orderId), {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store'
        }).then(function (response) {
            return response.json().then(function (result) {
                if (!response.ok || !result.ok) {
                    throw new Error(result.error || 'Could not confirm this payment.');
                }
                return result;
            });
        }).then(function (result) {
            if (result.payment_status === 'PAID') {
                window.location.replace(
                    '../customer_orders/order_success.php?order_id=' + encodeURIComponent(orderId)
                    + '&receipt_email_sent=' + (result.receipt_email_sent ? '1' : '0')
                );
                return;
            }

            if (result.payment_status === 'FAILED') {
                message.textContent = 'This payment was not completed. Your cart is still saved; return to checkout to try again.';
                ordersLink.hidden = false;
                return;
            }

            attempts += 1;
            if (attempts < maximumAttempts) {
                window.setTimeout(checkPayment, 2000);
                return;
            }
            message.textContent = 'Your payment is still being confirmed. You can check your order status from My orders shortly.';
            ordersLink.hidden = false;
        }).catch(function (error) {
            message.textContent = error.message + ' Please check My orders in a moment.';
            ordersLink.hidden = false;
        });
    }

    checkPayment();
})();
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>

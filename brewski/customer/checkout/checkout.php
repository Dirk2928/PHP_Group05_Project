<?php

require_once __DIR__ . '/../partials/bootstrap.php';
require_once __DIR__ . '/../../Db/connection.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../../login-signup/login.php');
    exit;
}
if (brewski_current_role() !== 'CUSTOMER') {
    header('Location: ../../login-signup/login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

$pageTitle   = 'Checkout | brewski';
$active      = 'checkout';
$extraStyles = ['partials/menu.css', 'customer-typography.css', 'checkout/checkout.css'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$stmt = $pdo->prepare(
    'SELECT address_id, address_line, city, province, postal_code
     FROM addresses WHERE user_id = ? ORDER BY address_id DESC'
);
$stmt->execute([$userId]);
$addresses = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT cart_id FROM carts
     WHERE user_id = ?
     ORDER BY cart_id ASC
     LIMIT 1'
);
$stmt->execute([$userId]);
$cartId = $stmt->fetchColumn();

$items    = [];
$subtotal = 0.0;

if ($cartId) {
    $stmt = $pdo->prepare(
        'SELECT ci.cart_item_id, ci.product_id, ci.size_id, ci.quantity, ci.unit_price,
                p.product_name, s.size_name
         FROM cart_items ci
         JOIN products p ON p.product_id = ci.product_id
         JOIN sizes    s ON s.size_id    = ci.size_id
         WHERE ci.cart_id = ?'
    );
    $stmt->execute([$cartId]);
    $items = $stmt->fetchAll();

    foreach ($items as $it) {
        $subtotal += (float) $it['unit_price'] * (int) $it['quantity'];
    }
}

$deliveryFee = $subtotal >= 500 ? 0.00 : 50.00;
$total       = $subtotal + $deliveryFee;

require __DIR__ . '/../partials/header.php';
?>

<main class="page checkout-page" style="max-width: 980px; margin: 0 auto; padding: 2.5rem 1.5rem 4rem;">

    <p id="cart-sync-status" role="status" hidden
        style="padding:1rem; background:#faf5ee; border:1px solid #e8dac4; border-radius:0.75rem; margin-bottom:1rem;"></p>

    <h1 style="font-size: 2rem; margin-bottom: 0.5rem;">Checkout</h1>
    <p style="color:#5c4033; margin-bottom: 2rem;">Review your order and pick a delivery address.</p>

    <?php if (($_GET['payment'] ?? '') === 'cancelled'): ?>
        <p class="checkout-payment__notice" role="status" style="padding:1rem; border:1px solid #e8dac4; border-radius:0.75rem; background:#fffaf2; margin-bottom:1.25rem;">
            Payment was not completed. Your cart is still saved, and you can continue whenever you are ready.
        </p>
    <?php endif; ?>

    <?php if (!$items): ?>

        <div style="padding:1.5rem; background:#f8e3e0; border:1px solid #e6b8b2; border-radius:0.75rem; color:#a32a1f;">
            Your cart is empty.
            <a href="../customer_menu/customermenu.php">Browse the menu</a>.
        </div>

    <?php else: ?>

    <div style="display:grid; grid-template-columns: 1fr 380px; gap:1.5rem; align-items:start;">

        <section>

            <h2 style="font-size:1.15rem; margin-bottom:1rem;">Delivery address</h2>

            <?php if (!$addresses): ?>

                <div style="padding:1rem; background:#faf5ee; border:1px solid #e8dac4; border-radius:0.75rem; margin-bottom:1.5rem;">
                    You have no saved addresses yet.
                    <a href="../customer_profile/customer_profile.php">Add one in your profile</a>.
                </div>

            <?php else: ?>

                <form id="checkout-form" style="display:flex; flex-direction:column; gap:0.75rem; margin-bottom:2rem;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <?php foreach ($addresses as $i => $a): ?>
                        <label style="display:flex; gap:0.75rem; padding:1rem; background:#f2e4cc; border-radius:0.75rem; cursor:pointer;">
                            <input type="radio" name="address_id" value="<?= (int)$a['address_id'] ?>" <?= $i === 0 ? 'checked' : '' ?> required>
                            <span>
                                <strong><?= htmlspecialchars($a['address_line']) ?></strong><br>
                                <small><?= htmlspecialchars($a['city'] . ', ' . $a['province'] . ' ' . $a['postal_code']) ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>

                    <label style="display:flex; flex-direction:column; gap:0.4rem; margin-top:0.5rem;">
                        <span style="font-size:0.85rem; font-weight:600; color:#3b2218;">Delivery notes (optional)</span>
                        <textarea name="notes" rows="2" placeholder="e.g. Leave at the front desk"
                            style="padding:0.75rem; border:1px solid #e8dac4; border-radius:0.75rem; font-family:inherit; background:#faf5ee;"></textarea>
                    </label>
                </form>

            <?php endif; ?>

            <h2 style="font-size:1.15rem; margin-bottom:1rem;">Payment method</h2>
            <div class="checkout-payment">
                <span class="checkout-payment__icon" aria-hidden="true"><i data-lucide="shield-check"></i></span>
                <div class="checkout-payment__copy">
                    <strong>Secure checkout with PayMongo</strong>
                    <small>Choose your preferred payment method on PayMongo’s secure payment page.</small>
                    <div class="checkout-payment__methods" aria-label="Available payment methods">
                        <span>GCash</span>
                        <span>Maya</span>
                        <span>QR Ph</span>
                        <span>Cards</span>
                        <span>Online banking</span>
                        <span>More</span>
                    </div>
                </div>
            </div>

            <h2 style="font-size:1.15rem; margin-bottom:1rem;">Your items</h2>

            <ul style="list-style:none; padding:0; margin:0;">
                <?php foreach ($items as $it): ?>
                    <li style="display:flex; justify-content:space-between; padding:0.75rem 0; border-bottom:1px solid #e8dac4;">
                        <span>
                            <strong><?= htmlspecialchars($it['product_name']) ?></strong>
                            <small style="color:#5c4033;"> — <?= htmlspecialchars($it['size_name']) ?> × <?= (int)$it['quantity'] ?></small>
                        </span>
                        <span>₱<?= number_format((float)$it['unit_price'] * (int)$it['quantity'], 2) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>

        </section>

        <aside style="background:#f2e4cc; border-radius:1.25rem; padding:1.75rem; position:sticky; top:1.5rem;">

            <h2 style="font-size:1.15rem; margin-bottom:1rem;">Order summary</h2>

            <div style="display:flex; justify-content:space-between; margin-bottom:0.5rem;">
                <span>Subtotal</span>
                <span>₱<?= number_format($subtotal, 2) ?></span>
            </div>

            <div style="display:flex; justify-content:space-between; margin-bottom:1rem;">
                <span>Delivery fee</span>
                <span><?= $deliveryFee > 0 ? '₱' . number_format($deliveryFee, 2) : 'FREE' ?></span>
            </div>

            <div style="display:flex; justify-content:space-between; padding-top:1rem; border-top:1px solid #e8dac4; font-size:1.15rem; font-weight:700;">
                <span>Total</span>
                <span>₱<?= number_format($total, 2) ?></span>
            </div>

            <button
                type="button"
                id="place-order-btn"
                <?= !$addresses ? 'disabled' : '' ?>
                style="width:100%; margin-top:1.5rem; padding:1rem; background:#1a0f0a; color:#faf5ee; border:none; border-radius:0.75rem; font-weight:600; cursor:pointer; <?= !$addresses ? 'opacity:0.5; cursor:not-allowed;' : '' ?>"
            >
                Continue to payment
            </button>

            <p class="checkout-payment__notice">Your order is sent to the café only after PayMongo confirms your payment. Card details are entered on PayMongo, not stored by Brewski.</p>
            <p id="checkout-msg" style="margin-top:1rem; font-size:0.9rem;"></p>

        </aside>

    </div>

    <?php endif; ?>

</main>

<style>
    #order-confirmation-dialog {
        width: min(440px, calc(100% - 2rem));
        padding: 0;
        border: 1px solid #e8dac4;
        border-radius: 1rem;
        background: #faf5ee;
        color: #1a0f0a;
        box-shadow: 0 1.5rem 4rem rgba(26, 15, 10, 0.28);
    }

    #order-confirmation-dialog::backdrop {
        background: rgba(26, 15, 10, 0.58);
        backdrop-filter: blur(3px);
    }

    .order-confirmation-dialog__content {
        display: flex;
        flex-direction: column;
        gap: 1rem;
        padding: 1.5rem;
    }

    .order-confirmation-dialog__content h2,
    .order-confirmation-dialog__content p {
        margin: 0;
    }

    .order-confirmation-dialog__content p {
        color: #5c4033;
        line-height: 1.5;
    }

    .order-confirmation-dialog__actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.65rem;
    }

    .order-confirmation-dialog__actions button {
        flex: 1 1 8rem;
        min-height: 2.8rem;
        padding: 0.65rem 0.85rem;
        border: 1px solid #e8dac4;
        border-radius: 0.65rem;
        background: #fff;
        color: #1a0f0a;
        font: inherit;
        font-weight: 600;
        cursor: pointer;
    }

    .order-confirmation-dialog__actions button[type="submit"] {
        border-color: #1a0f0a;
        background: #1a0f0a;
        color: #faf5ee;
    }

    .order-confirmation-dialog__actions button:disabled {
        cursor: wait;
        opacity: 0.65;
    }

    .checkout-page button,
    .checkout-page input,
    .checkout-page textarea,
    .checkout-page select,
    .order-confirmation-dialog {
        font-family: inherit;
    }
</style>

<dialog id="order-confirmation-dialog" aria-labelledby="order-confirmation-title">
    <form id="order-confirmation-form" class="order-confirmation-dialog__content">
        <h2 id="order-confirmation-title">Continue to secure payment?</h2>
        <p>You’ll choose and complete your payment on PayMongo’s secure checkout page.</p>
        <p id="order-confirmation-message" role="status" aria-live="polite"></p>
        <div class="order-confirmation-dialog__actions">
            <button type="button" id="order-confirmation-cancel">Cancel</button>
            <button type="submit" id="order-confirmation-proceed">Proceed</button>
        </div>
    </form>
</dialog>

<script>
(function () {
    var placeOrderButton = document.getElementById('place-order-btn');
    var checkoutMessage = document.getElementById('checkout-msg');
    var checkoutForm = document.getElementById('checkout-form');
    var dialog = document.getElementById('order-confirmation-dialog');
    var confirmationForm = document.getElementById('order-confirmation-form');
    var confirmationMessage = document.getElementById('order-confirmation-message');
    var proceedButton = document.getElementById('order-confirmation-proceed');

    if (!placeOrderButton || !checkoutForm || !dialog || !confirmationForm) {
        return;
    }

    function showError(message, target) {
        target.textContent = message;
        target.style.color = '#a32a1f';
    }

    function formData(action) {
        var data = new FormData(checkoutForm);
        data.set('action', action);
        return data;
    }

    function describe(result, fallback) {
        var message = result.error || fallback;

        if (result.detail) {
            message += ' [' + result.detail + ']';
        }

        return message;
    }

    async function postPlaceOrder(action, extra) {
        var data = formData(action);

        if (extra) {
            Object.keys(extra).forEach(function (key) {
                data.set(key, extra[key]);
            });
        }

        var response = await fetch('place_order.php', { method: 'POST', body: data });
        var body = await response.text();
        var result;

        try {
            result = JSON.parse(body);
        } catch (parseError) {
            throw new Error(
                'the server sent an unexpected reply (HTTP ' + response.status + '): '
                + body.replace(/\s+/g, ' ').slice(0, 160)
            );
        }

        if (!response.ok || !result.ok) {
            console.error('place_order.php replied with HTTP ' + response.status + ': ' + body);
        }

        return { response: response, result: result };
    }

    placeOrderButton.addEventListener('click', function () {
        checkoutMessage.textContent = '';
        confirmationMessage.textContent = '';
        dialog.showModal();
    });

    document.getElementById('order-confirmation-cancel').addEventListener('click', function () {
        dialog.close();
    });

    confirmationForm.addEventListener('submit', async function (event) {
        event.preventDefault();
        proceedButton.disabled = true;
        confirmationMessage.textContent = 'Preparing secure checkout…';
        confirmationMessage.style.color = '#5c4033';

        try {
            var call = await postPlaceOrder('create_payment');
            var result = call.result;

            if (result.ok) {
                window.location.href = result.redirect;
                return;
            }

            showError(describe(result, 'Could not start secure checkout.'), confirmationMessage);
        } catch (error) {
            showError('Could not start secure checkout: ' + error.message, confirmationMessage);
        }

        proceedButton.disabled = false;
    });

    dialog.addEventListener('close', function () {
        confirmationMessage.textContent = '';
    });
})();
</script>

<script>
(function () {
    var params = new URLSearchParams(window.location.search);
    if (params.get('cart_synced') === '1') {
        return;
    }

    var syncUrl = document.querySelector('meta[name="cart-session-url"]');
    var csrfToken = document.querySelector('meta[name="csrf-token"]');
    var syncEnabled = document.querySelector('meta[name="cart-sync-enabled"]');
    var status = document.getElementById('cart-sync-status');

    if (!syncUrl || !csrfToken || !syncEnabled || syncEnabled.content !== '1') {
        return;
    }

    var items;
    try {
        items = JSON.parse(localStorage.getItem('brewski_cart') || '[]');
    } catch (error) {
        console.error('Could not read the browser cart on checkout:', error);
        return;
    }

    if (!Array.isArray(items) || items.length === 0) {
        return;
    }

    status.hidden = false;
    status.textContent = 'Updating your cart before checkout...';

    fetch(syncUrl.content, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken.content
        },
        body: JSON.stringify({ items: items })
    }).then(function (response) {
        return response.json().then(function (result) {
            if (!response.ok || !result.ok) {
                throw new Error(result.error || 'Could not update the checkout cart.');
            }
        });
    }).then(function () {
        params.set('cart_synced', '1');
        window.location.replace(window.location.pathname + '?' + params.toString());
    }).catch(function (error) {
        console.error('Could not sync the cart on checkout:', error);
        status.style.background = '#f8e3e0';
        status.style.borderColor = '#e6b8b2';
        status.style.color = '#a32a1f';
        status.textContent = error.message;
    });
})();
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>

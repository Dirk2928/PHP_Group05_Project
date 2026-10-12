<?php

require_once __DIR__ . '/../partials/bootstrap.php';
require_once __DIR__ . '/../../Db/connection.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../../login-signup/login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

$pageTitle   = 'Checkout | brewski';
$active      = 'checkout';
$extraStyles = ['partials/menu.css'];

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

<main class="page" style="max-width: 980px; margin: 0 auto; padding: 2.5rem 1.5rem 4rem;">

    <p id="cart-sync-status" role="status" hidden
        style="padding:1rem; background:#faf5ee; border:1px solid #e8dac4; border-radius:0.75rem; margin-bottom:1rem;"></p>

    <h1 style="font-size: 2rem; margin-bottom: 0.5rem;">Checkout</h1>
    <p style="color:#5c4033; margin-bottom: 2rem;">Review your order and pick a delivery address.</p>

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
                Place order
            </button>

            <p id="checkout-msg" style="margin-top:1rem; font-size:0.9rem;"></p>

        </aside>

    </div>

    <?php endif; ?>

</main>

<style>
    #order-otp-dialog {
        width: min(440px, calc(100% - 2rem));
        padding: 0;
        border: 1px solid #e8dac4;
        border-radius: 1rem;
        background: #faf5ee;
        color: #1a0f0a;
        box-shadow: 0 1.5rem 4rem rgba(26, 15, 10, 0.28);
    }

    #order-otp-dialog::backdrop {
        background: rgba(26, 15, 10, 0.58);
        backdrop-filter: blur(3px);
    }

    .order-otp-dialog__content {
        display: flex;
        flex-direction: column;
        gap: 1rem;
        padding: 1.5rem;
    }

    .order-otp-dialog__content h2,
    .order-otp-dialog__content p {
        margin: 0;
    }

    .order-otp-dialog__content p {
        color: #5c4033;
        line-height: 1.5;
    }

    .order-otp-dialog__content label {
        display: flex;
        flex-direction: column;
        gap: 0.45rem;
        font-size: 0.9rem;
        font-weight: 600;
    }

    .order-otp-dialog__content input {
        width: 100%;
        padding: 0.85rem 1rem;
        border: 1px solid #e8dac4;
        border-radius: 0.65rem;
        background: #fff;
        color: #1a0f0a;
        font: inherit;
        font-size: 1.25rem;
        letter-spacing: 0.3rem;
        text-align: center;
    }

    .order-otp-dialog__actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.65rem;
    }

    .order-otp-dialog__actions button {
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

    .order-otp-dialog__actions button[type="submit"] {
        border-color: #1a0f0a;
        background: #1a0f0a;
        color: #faf5ee;
    }

    .order-otp-dialog__actions button:disabled {
        cursor: wait;
        opacity: 0.65;
    }
</style>

<dialog id="order-otp-dialog" aria-labelledby="order-otp-title">
    <form id="order-otp-form" class="order-otp-dialog__content">
        <h2 id="order-otp-title">Verify your order</h2>
        <p id="order-otp-instructions">We’ll email you a 6-digit code. It expires in 10 minutes.</p>
        <label for="order-otp-code">
            Verification code
            <input
                type="text"
                id="order-otp-code"
                name="otp"
                inputmode="numeric"
                autocomplete="one-time-code"
                pattern="[0-9]{6}"
                maxlength="6"
                required
            >
        </label>
        <p id="order-otp-message" role="status" aria-live="polite"></p>
        <div class="order-otp-dialog__actions">
            <button type="button" id="order-otp-resend">Resend code</button>
            <button type="button" id="order-otp-cancel">Cancel</button>
            <button type="submit" id="order-otp-verify">Verify &amp; place order</button>
        </div>
    </form>
</dialog>

<script>
(function () {
    var placeOrderButton = document.getElementById('place-order-btn');
    var checkoutMessage = document.getElementById('checkout-msg');
    var checkoutForm = document.getElementById('checkout-form');
    var dialog = document.getElementById('order-otp-dialog');
    var otpForm = document.getElementById('order-otp-form');
    var otpInput = document.getElementById('order-otp-code');
    var otpMessage = document.getElementById('order-otp-message');
    var verifyButton = document.getElementById('order-otp-verify');
    var resendButton = document.getElementById('order-otp-resend');

    if (!placeOrderButton || !checkoutForm || !dialog || !otpForm) {
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

    async function sendCode() {
        resendButton.disabled = true;
        otpMessage.textContent = 'Sending verification code…';
        otpMessage.style.color = '#5c4033';

        try {
            var response = await fetch('place_order.php', {
                method: 'POST',
                body: formData('request_otp')
            });
            var result = await response.json();

            if (!response.ok || !result.ok) {
                showError(result.error || 'Could not send a verification code.', otpMessage);
                resendButton.disabled = false;
                return false;
            }

            otpMessage.textContent = result.message;
            otpMessage.style.color = '#2f6b3a';
            resendButton.disabled = false;
            otpInput.value = '';
            otpInput.focus();
            return true;
        } catch (error) {
            showError('Network error. Please try again.', otpMessage);
            resendButton.disabled = false;
            return false;
        }
    }

    placeOrderButton.addEventListener('click', async function () {
        placeOrderButton.disabled = true;
        placeOrderButton.textContent = 'Sending code…';
        checkoutMessage.textContent = '';

        var sent = await sendCode();
        placeOrderButton.disabled = false;
        placeOrderButton.textContent = 'Place order';

        if (sent) {
            dialog.showModal();
        } else if (otpMessage.textContent) {
            showError(otpMessage.textContent, checkoutMessage);
        }
    });

    resendButton.addEventListener('click', sendCode);

    document.getElementById('order-otp-cancel').addEventListener('click', function () {
        dialog.close();
    });

    otpForm.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (!otpForm.reportValidity()) {
            return;
        }

        verifyButton.disabled = true;
        resendButton.disabled = true;
        otpMessage.textContent = 'Verifying code and placing your order…';
        otpMessage.style.color = '#5c4033';

        var data = formData('verify_otp');
        data.set('otp', otpInput.value);

        try {
            var response = await fetch('place_order.php', { method: 'POST', body: data });
            var result = await response.json();

            if (result.ok) {
                try {
                    localStorage.removeItem('brewski_cart');
                } catch (storageError) {
                    console.error('Order placed, but the browser cart could not be cleared:', storageError);
                }
                window.location.href = result.redirect;
                return;
            }

            showError(result.error || 'Could not verify your code.', otpMessage);
        } catch (error) {
            showError('Network error. Please try again.', otpMessage);
        }

        verifyButton.disabled = false;
        resendButton.disabled = false;
    });

    dialog.addEventListener('close', function () {
        otpMessage.textContent = '';
        otpInput.value = '';
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
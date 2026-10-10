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
$extraStyles = ['menu.css'];

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

<script>
document.getElementById('place-order-btn')?.addEventListener('click', async () => {
    const btn  = document.getElementById('place-order-btn');
    const msg  = document.getElementById('checkout-msg');
    const form = document.getElementById('checkout-form');

    if (!form) return;

    const data = new FormData(form);
    btn.disabled = true;
    btn.textContent = 'Placing order…';
    msg.textContent = '';

    try {
        const res  = await fetch('place_order.php', { method: 'POST', body: data });
        const json = await res.json();

        if (json.ok) {
            window.location.href = json.redirect;
        } else {
            msg.style.color = '#a32a1f';
            msg.textContent = json.error || 'Something went wrong.';
            btn.disabled = false;
            btn.textContent = 'Place order';
        }
    } catch (e) {
        msg.style.color = '#a32a1f';
        msg.textContent = 'Network error. Please try again.';
        btn.disabled = false;
        btn.textContent = 'Place order';
    }
});
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
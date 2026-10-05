<?php

require_once __DIR__ . '/../../login-signup/session_init.php';

brewski_require_role(['STAFF', 'ADMIN']);

$is_signed_in = brewski_is_logged_in();

$first_name = $_SESSION['first_name'] ?? '';
$last_name  = $_SESSION['last_name'] ?? '';

$full_name = trim($first_name . ' ' . $last_name);

if ($full_name === '') {
    $full_name = 'Staff';
}

$display_name = htmlspecialchars(
    $full_name,
    ENT_QUOTES,
    'UTF-8'
);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>brewski Staff</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600&display=swap" rel="stylesheet">

    <link
        rel="stylesheet"
        href="../staff.css?v=<?= filemtime(__DIR__ . '/../staff.css') ?>"
    >

    <style>
        .orders-table { width: 100%; border-collapse: collapse; }
        .orders-table th,
        .orders-table td { padding: .75rem 1rem; text-align: left; vertical-align: top; border-bottom: 1px solid #e5e5e5; }
        .orders-table ul { margin: 0; padding-left: 1.1rem; }
        .badge { display: inline-block; padding: .2rem .6rem; border-radius: 999px; font-size: .8rem; font-weight: 600; }
        .badge-pending   { background: #fff3cd; color: #7a5b00; }
        .badge-confirmed { background: #e8e0ff; color: #3d2a8c; }
        .badge-preparing { background: #d9eaff; color: #0b4a8f; }
        .badge-ready     { background: #d6f5dd; color: #176b2c; }
        .order-actions button { margin: 0 .25rem .25rem 0; padding: .35rem .7rem; border: 0; border-radius: 6px; cursor: pointer; }
        .btn-advance { background: #4b2e1e; color: #fff; }
        .btn-cancel  { background: #eee; color: #a11; }
        .live-status { font-size: .8rem; color: #777; margin-top: .5rem; }
        .live-status.error { color: #b00020; }
    </style>

</head>

<body>

    <header class="topbar">
        <img class="topbar-logo" src="../../images/brewskilogo.png" alt="Brewski logo">
        <div class="profile-wrapper">
            <span class="profile-label">Staff</span>

            <button
                type="button"
                id="profileBtn"
                class="icon-btn profile-btn"
                aria-label="Open profile menu"
                aria-expanded="false"
            ></button>

            <div id="profileMenu" class="profile-menu hidden">
                <a
                    href="../../login-signup/logout.php"
                    class="profile-menu-item border-top"
                >
                    Log out
                </a>

            </div>

        </div>
    </header>

    <aside id="sidebar" class="sidebar">
        <div class="sidebar-brand">brewski</div>

        <nav class="sidebar-nav">

            <button
                type="button"
                class="nav-item active"
                data-view="orders"
            >
                Orders
            </button>

            <button
                type="button"
                class="nav-item"
                data-view="products"
            >
                Products
            </button>

            <button
                type="button"
                class="nav-item"
                data-view="../order_history/order_history.php"
            >
                Order History
            </button>

            <button
                type="button"
                class="nav-item"
                data-view="profile"
            >
                Profile
            </button>

        </nav>

    </aside>

    <main class="main-content" id="mainContent">

        <section id="ordersView">

            <div class="page-container">

                <div class="page-header">

                    <h1 class="page-title">
                        Orders
                    </h1>

                    <p class="subtitle">
                        <?php if ($is_signed_in): ?>Signed in as <?= $display_name ?>. <?php endif; ?>Track incoming orders
                        and update their status.
                    </p>

                </div>

                <div class="table-card">

                    <table class="orders-table" id="ordersTable" hidden>
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Customer</th>
                                <th>Items</th>
                                <th>Total</th>
                                <th>Placed</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="ordersBody"></tbody>
                    </table>

                    <div class="empty-state" id="ordersEmpty">

                        <p class="empty-title">
                            No orders to show yet
                        </p>

                        <p class="empty-hint">
                            Orders placed by customers will appear here.
                        </p>

                    </div>

                </div>

                <p class="live-status" id="liveStatus">Connecting...</p>

            </div>

        </section>

        <section id="dynamicView" class="hidden"></section>

        <section id="placeholderView" class="hidden">

            <h1 id="placeholderTitle">
                Page Not Found
            </h1>

            <p class="subtitle">
                This section has not been implemented yet.
            </p>

        </section>

    </main>

    <script
        src="../staff.js?v=<?= filemtime(__DIR__ . '/../staff.js') ?>"
        defer
    ></script>

    <script>
    (function () {
        const POLL_MS       = 2000;   
        const MAX_FAILURES  = 5;     
        const API_URL       = 'orders_api.php';

        const section = document.getElementById('ordersView');
        const table   = document.getElementById('ordersTable');
        const body    = document.getElementById('ordersBody');
        const empty   = document.getElementById('ordersEmpty');
        const live    = document.getElementById('liveStatus');

        let lastSnapshot = '';
        let timer = null;
        let failures = 0;
        let stopped = false;

        const NEXT = {
            PENDING:   { label: 'Confirm',         to: 'CONFIRMED', cancel: true },
            CONFIRMED: { label: 'Start preparing', to: 'PREPARING', cancel: true },
            PREPARING: { label: 'Mark ready',      to: 'READY',     cancel: true },
            READY:     { label: 'Complete',        to: 'COMPLETED', cancel: false }
        };

        function esc(value) {
            const div = document.createElement('div');
            div.textContent = String(value ?? '');
            return div.innerHTML;
        }

        function setStatus(message, isError) {
            live.textContent = message;
            live.classList.toggle('error', !!isError);
        }

        function render(orders) {
            if (orders.length === 0) {
                table.hidden = true;
                empty.hidden = false;
                body.innerHTML = '';
                return;
            }

            table.hidden = false;
            empty.hidden = true;

            body.innerHTML = orders.map(function (o) {
                const status = String(o.status).toUpperCase();
                const next   = NEXT[status];

                const items = o.items.length
                    ? '<ul>' + o.items.map(function (i) {
                          return '<li>' + esc(i.quantity) + ' &times; ' + esc(i.name) + '</li>';
                      }).join('') + '</ul>'
                    : '-';

                let actions = '';
                if (next) {
                    actions += '<button class="btn-advance" data-id="' + o.order_id +
                               '" data-status="' + next.to + '">' + next.label + '</button>';
                    if (next.cancel) {
                        actions += '<button class="btn-cancel" data-id="' + o.order_id +
                                   '" data-status="CANCELLED">Cancel</button>';
                    }
                }

                return '<tr>' +
                    '<td>#' + esc(o.order_id) + '</td>' +
                    '<td>' + esc(o.customer || 'Customer') + '</td>' +
                    '<td>' + items + '</td>' +
                    '<td>' + esc(Number(o.total_amount).toFixed(2)) + '</td>' +
                    '<td>' + esc(o.created_at) + '</td>' +
                    '<td><span class="badge badge-' + status.toLowerCase() + '">' + esc(status) + '</span></td>' +
                    '<td class="order-actions">' + actions + '</td>' +
                '</tr>';
            }).join('');
        }

        function fail(message) {
            failures++;

            if (failures >= MAX_FAILURES) {
                stopped = true;
                setStatus(message + ' - stopped after ' + MAX_FAILURES + ' failed attempts. Reload the page to retry.', true);
            } else {
                setStatus(message + ' - retrying...', true);
            }
        }

        async function fetchOrders() {
            try {
                const res = await fetch(API_URL, { cache: 'no-store' });

                if (res.redirected && res.url.indexOf('login') !== -1) {
                    window.location.href = res.url;
                    return;
                }

                const type = res.headers.get('content-type') || '';

                if (!type.includes('application/json')) {
                    const text = await res.text();
                    const snippet = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 160);
                    fail('API returned a non-JSON response (HTTP ' + res.status + '): ' + snippet);
                    return;
                }

                const data = await res.json();

                if (data.error) {
                    fail('API error: ' + data.error + (data.detail ? ' (' + data.detail + ')' : ''));
                    return;
                }

                if (data.orders) {
                    failures = 0;

                    const snapshot = JSON.stringify(data.orders);

                    if (snapshot !== lastSnapshot) {
                        lastSnapshot = snapshot;
                        render(data.orders);
                    }

                    setStatus('Live - updated ' + new Date().toLocaleTimeString(), false);
                }
            } catch (err) {
                fail('Connection problem');
            }
        }

        function schedule() {
            clearTimeout(timer);
            if (!stopped) {
                timer = setTimeout(tick, POLL_MS);
            }
        }

        async function tick() {
            if (!document.hidden && !section.classList.contains('hidden')) {
                await fetchOrders();
            }
            schedule();
        }

        body.addEventListener('click', async function (e) {
            const btn = e.target.closest('button[data-id]');
            if (!btn) return;

            if (btn.dataset.status === 'CANCELLED' && !confirm('Cancel this order?')) {
                return;
            }

            btn.disabled = true;

            const form = new FormData();
            form.append('order_id', btn.dataset.id);
            form.append('status', btn.dataset.status);

            try {
                await fetch(API_URL, { method: 'POST', body: form });
            } catch (err) {
                setStatus('Could not update the order - try again.', true);
            }

            fetchOrders(); 
        });

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && !stopped) tick();
        });

        tick();
    })();
    </script>

</body>

</html>
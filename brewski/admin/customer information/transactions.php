<?php

require_once __DIR__ . '/../../login-signup/session_init.php';
require_once __DIR__ . '/../../Db/connection.php';

brewski_require_role(['ADMIN']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$endpoint = htmlspecialchars(
    str_replace(' ', '%20', $_SERVER['SCRIPT_NAME'] ?? ''),
    ENT_QUOTES,
    'UTF-8'
);

$orderStatuses = [
    'PENDING',
    'CONFIRMED',
    'PREPARING',
    'READY',
    'COMPLETED',
    'CANCELLED',
];

function admin_transactions_respond(bool $ok, string $message, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'ok'      => $ok,
        'message' => $message,
        'error'   => $ok ? '' : $message,
    ]);

    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        admin_transactions_respond(false, 'Your session expired. Please reload the page.', 403);
    }

    $action = (string) ($_POST['action'] ?? '');
    $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);

    if ($orderId === false || $orderId <= 0) {
        admin_transactions_respond(false, 'Please choose a valid transaction.');
    }

    $exists = $pdo->prepare('SELECT order_id FROM orders WHERE order_id = ? LIMIT 1');
    $exists->execute([(int) $orderId]);

    if (!$exists->fetch()) {
        admin_transactions_respond(false, 'That transaction no longer exists.', 404);
    }

    if ($action === 'update') {

        $statusInput = strtoupper(trim((string) ($_POST['order_status'] ?? '')));

        if ($statusInput === '' || !preg_match('/^[A-Z_]+$/', $statusInput)) {
            admin_transactions_respond(false, 'Please choose a valid order status.');
        }

        if (!in_array($statusInput, $orderStatuses, true)) {
            admin_transactions_respond(false, 'Please choose a valid order status.');
        }

        $update = $pdo->prepare('UPDATE orders SET order_status = ? WHERE order_id = ?');
        $update->execute([$statusInput, (int) $orderId]);

        $_SESSION['admin_flash'] = [
            'type'    => 'success',
            'message' => 'Transaction #' . (int) $orderId . ' is now ' . $statusInput . '.',
        ];

        admin_transactions_respond(true, 'Transaction updated.');
    }

    if ($action === 'delete') {

        $delete = $pdo->prepare('DELETE FROM orders WHERE order_id = ?');
        $delete->execute([(int) $orderId]);

        $_SESSION['admin_flash'] = [
            'type'    => 'success',
            'message' => 'Transaction #' . (int) $orderId . ' has been deleted.',
        ];

        admin_transactions_respond(true, 'Transaction deleted.');
    }

    admin_transactions_respond(false, 'That action is not recognised.');
}

$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

$transactions = $pdo->query(
    "SELECT o.order_id,
            o.order_date,
            o.total_amount,
            o.order_status,
            TRIM(CONCAT(COALESCE(cu.first_name, ''), ' ', COALESCE(cu.last_name, ''))) AS customer_name,
            TRIM(CONCAT(COALESCE(su.first_name, ''), ' ', COALESCE(su.last_name, ''))) AS staff_name,
            (SELECT GROUP_CONCAT(
                        CONCAT(oi.quantity, 'x ', COALESCE(p.product_name, 'Unknown item'))
                        SEPARATOR ', '
                    )
               FROM order_items oi
               LEFT JOIN products p ON p.product_id = oi.product_id
              WHERE oi.order_id = o.order_id) AS item_summary
     FROM orders o
     LEFT JOIN users cu ON cu.user_id = o.user_id
     LEFT JOIN deliveries d ON d.order_id = o.order_id
     LEFT JOIN users su ON su.user_id = d.staff_id
     ORDER BY o.order_date DESC, o.order_id DESC"
)->fetchAll();

function admin_transaction_badge(string $status): string
{
    if ($status === 'COMPLETED') {
        return 'badge-active';
    }

    if ($status === 'CANCELLED') {
        return 'badge-locked';
    }

    return 'badge-pending';
}
?>

<div class="page-container">

    <div class="page-header">
        <h1 class="page-title">Transactions</h1>
        <p class="subtitle">Review store sales, the order status, and the staff member who handled each order.</p>
    </div>

    <p
        id="txnStatus"
        class="table-status<?= $flash ? ' is-' . htmlspecialchars((string) $flash['type'], ENT_QUOTES, 'UTF-8') : '' ?>"
        role="status"
        aria-live="polite"
    ><?= $flash ? htmlspecialchars((string) $flash['message'], ENT_QUOTES, 'UTF-8') : '' ?></p>

    <div class="toolbar">
        <div class="toolbar-filters">
            <div class="search-field">
                <svg class="search-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
                <input type="text" id="txnSearch" class="toolbar-input" placeholder="Search by receipt, customer, product..." aria-label="Search transactions">
            </div>

            <select id="txnStatusFilter" class="toolbar-select" aria-label="Filter by order status">
                <option value="all">All statuses</option>
                <?php foreach ($orderStatuses as $statusOption): ?>
                    <option value="<?= htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="table-card">
        <div class="table-scroll">
            <table class="data-table table-wide">
                <thead>
                    <tr>
                        <th>Date / Time</th>
                        <th>Customer Name</th>
                        <th>Items</th>
                        <th>Status</th>
                        <th>Total</th>
                        <th>Staff</th>
                        <th class="col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody id="txnTableBody">
                    <?php foreach ($transactions as $transaction): ?>
                        <?php
                        $orderId = (int) $transaction['order_id'];
                        $orderStamp = strtotime((string) $transaction['order_date']);
                        $orderDateTime = $orderStamp !== false ? date('Y-m-d\TH:i', $orderStamp) : '';
                        $orderStatus = (string) $transaction['order_status'];
                        $orderCustomer = trim((string) $transaction['customer_name']);
                        $orderStaff = trim((string) $transaction['staff_name']);
                        $orderItems = (string) ($transaction['item_summary'] ?? '');
                        $orderTotal = (float) $transaction['total_amount'];

                        if ($orderCustomer === '') {
                            $orderCustomer = 'Guest';
                        }

                        if ($orderItems === '') {
                            $orderItems = 'No items';
                        }

                        if ($orderStaff === '') {
                            $orderStaff = 'Unassigned';
                        }
                        ?>
                        <tr
                            data-id="<?= $orderId ?>"
                            data-datetime="<?= htmlspecialchars($orderDateTime, ENT_QUOTES, 'UTF-8') ?>"
                            data-customer="<?= htmlspecialchars($orderCustomer, ENT_QUOTES, 'UTF-8') ?>"
                            data-items="<?= htmlspecialchars($orderItems, ENT_QUOTES, 'UTF-8') ?>"
                            data-status="<?= htmlspecialchars($orderStatus, ENT_QUOTES, 'UTF-8') ?>"
                            data-amount="<?= number_format($orderTotal, 2, '.', '') ?>"
                            data-staff="<?= htmlspecialchars($orderStaff, ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <td>
                                <div class="cell-stack">
                                    <span class="cell-strong js-date"><?= htmlspecialchars($orderStamp !== false ? date('M j, Y', $orderStamp) : '—', ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="cell-sub js-time"><?= htmlspecialchars($orderStamp !== false ? date('h:i A', $orderStamp) : '', ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            </td>

                            <td><span class="cell-strong js-customer"><?= htmlspecialchars($orderCustomer, ENT_QUOTES, 'UTF-8') ?></span></td>

                            <td><span class="cell-muted js-items"><?= htmlspecialchars($orderItems, ENT_QUOTES, 'UTF-8') ?></span></td>

                            <td><span class="badge <?= admin_transaction_badge($orderStatus) ?> js-status"><?= htmlspecialchars($orderStatus, ENT_QUOTES, 'UTF-8') ?></span></td>

                            <td><span class="cell-amount js-amount">&#8369;<?= number_format($orderTotal, 2) ?></span></td>

                            <td><span class="cell-muted js-staff"><?= htmlspecialchars($orderStaff, ENT_QUOTES, 'UTF-8') ?></span></td>

                            <td class="col-actions">
                                <div class="table-actions">
                                    <button type="button" class="btn btn-view" data-action="view">View</button>
                                    <button type="button" class="btn btn-edit" data-action="edit">Edit</button>
                                    <button type="button" class="btn btn-delete" data-action="delete">Delete</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <tr id="txnNoResultsRow" class="hidden">
                        <td colspan="7" class="empty-cell">
                            <div class="empty-state">
                                <p class="empty-title">No transactions found</p>
                                <p class="empty-hint">Try adjusting your search or filter criteria.</p>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    (function () {
        const ENDPOINT = <?= json_encode($endpoint, JSON_UNESCAPED_SLASHES) ?>;
        const CSRF = <?= json_encode($csrfToken) ?>;
        const STATUSES = <?= json_encode($orderStatuses) ?>;

        const searchInput = document.getElementById('txnSearch');
        const statusFilter = document.getElementById('txnStatusFilter');
        const tbody = document.getElementById('txnTableBody');
        const noResultsRow = document.getElementById('txnNoResultsRow');
        const statusLine = document.getElementById('txnStatus');

        if (!searchInput || !tbody) return;

        const STATUS_CLASS = {
            COMPLETED: 'badge-active',
            CANCELLED: 'badge-locked'
        };

        const rows = Array.from(tbody.querySelectorAll('tr[data-id]'));

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function setStatus(message, type) {
            if (!statusLine) return;

            statusLine.textContent = message;
            statusLine.classList.remove('is-success');
            statusLine.classList.remove('is-error');

            if (type) {
                statusLine.classList.add('is-' + type);
            }
        }

        function statusClass(status) {
            return STATUS_CLASS[status] || 'badge-pending';
        }

        function formatDate(value) {
            const date = new Date(value);
            if (isNaN(date.getTime())) return value;

            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }

        function formatTime(value) {
            const date = new Date(value);
            if (isNaN(date.getTime())) return '';

            return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
        }

        function formatPeso(value) {
            return '₱' + Number(value).toFixed(2);
        }

        function statusOptions(selected) {
            return STATUSES.map(function (status) {
                return '<option value="' + status + '"' + (status === selected ? ' selected' : '') + '>' +
                    status + '</option>';
            }).join('');
        }

        function applyFilters() {
            const term = searchInput.value.trim().toLowerCase();
            const status = statusFilter.value;

            rows.forEach(function (row) {
                const haystack = [
                    row.dataset.id,
                    row.dataset.customer,
                    row.dataset.items,
                    row.dataset.staff
                ].join(' ').toLowerCase();

                const matchesTerm = !term || haystack.includes(term);
                const matchesStatus = status === 'all' || row.dataset.status === status;

                row.classList.toggle('hidden', !(matchesTerm && matchesStatus));
            });

            const visible = rows.filter(function (row) {
                return !row.classList.contains('hidden');
            }).length;

            noResultsRow.classList.toggle('hidden', visible > 0);
        }

        function send(body) {
            return fetch(ENDPOINT, {
                method: 'POST',
                body: body,
                credentials: 'same-origin'
            }).then(function (response) {
                return response.json();
            });
        }

        function viewTransaction(row) {
            const data = row.dataset;

            window.openAdminModal({
                title: 'Transaction #' + data.id,
                body: '<dl class="detail-list">' +
                        '<dt>Transaction No.</dt><dd>#' + escapeHtml(data.id) + '</dd>' +
                        '<dt>Date &amp; time</dt><dd>' + escapeHtml(formatDate(data.datetime) + ', ' + formatTime(data.datetime)) + '</dd>' +
                        '<dt>Customer name</dt><dd>' + escapeHtml(data.customer) + '</dd>' +
                        '<dt>Items</dt><dd>' + escapeHtml(data.items) + '</dd>' +
                        '<dt>Status</dt><dd><span class="badge ' + statusClass(data.status) + '">' + escapeHtml(data.status) + '</span></dd>' +
                        '<dt>Total</dt><dd>' + escapeHtml(formatPeso(data.amount)) + '</dd>' +
                        '<dt>Staff</dt><dd>' + escapeHtml(data.staff) + '</dd>' +
                      '</dl>'
            });
        }

        function editTransaction(row) {
            const data = row.dataset;

            window.openAdminModal({
                title: 'Edit transaction #' + data.id,
                body: '<form class="form-grid" id="txnEditForm">' +
                        '<input type="hidden" name="csrf_token" value="' + escapeHtml(CSRF) + '">' +
                        '<input type="hidden" name="action" value="update">' +
                        '<input type="hidden" name="order_id" value="' + escapeHtml(data.id) + '">' +
                        '<div class="form-field form-field-full">' +
                            '<label for="txnEditStatus">Order status</label>' +
                            '<select id="txnEditStatus" name="order_status">' + statusOptions(data.status) + '</select>' +
                        '</div>' +
                      '</form>',
                footer: [
                    { label: 'Cancel', className: 'btn-secondary' },
                    {
                        label: 'Save changes',
                        className: 'btn-primary',
                        onClick: function (handle) {
                            const form = handle.element.querySelector('#txnEditForm');

                            if (!form.reportValidity()) return false;

                            setStatus('Saving...', '');

                            send(new FormData(form)).then(function (json) {
                                if (json.ok) {
                                    handle.close();
                                    window.brewskiReloadView();
                                } else {
                                    setStatus(json.error || 'Could not save the transaction.', 'error');
                                }
                            }).catch(function () {
                                setStatus('Network error. Please try again.', 'error');
                            });

                            return false;
                        }
                    }
                ]
            });
        }

        function deleteTransaction(row) {
            if (!confirm('Delete transaction #' + row.dataset.id + '? This action cannot be undone.')) return;

            const body = new FormData();
            body.append('csrf_token', CSRF);
            body.append('action', 'delete');
            body.append('order_id', row.dataset.id);

            setStatus('Deleting...', '');

            send(body).then(function (json) {
                if (json.ok) {
                    window.brewskiReloadView();
                } else {
                    setStatus(json.error || 'Could not delete the transaction.', 'error');
                }
            }).catch(function () {
                setStatus('Network error. Please try again.', 'error');
            });
        }

        searchInput.addEventListener('input', applyFilters);
        statusFilter.addEventListener('change', applyFilters);

        tbody.addEventListener('click', function (event) {
            const button = event.target.closest('button[data-action]');
            if (!button) return;

            const row = button.closest('tr[data-id]');
            if (!row) return;

            if (button.dataset.action === 'view') viewTransaction(row);
            if (button.dataset.action === 'edit') editTransaction(row);
            if (button.dataset.action === 'delete') deleteTransaction(row);
        });

        applyFilters();
    })();
</script>

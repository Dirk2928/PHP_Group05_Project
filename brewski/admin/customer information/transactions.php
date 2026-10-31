<?php

require_once __DIR__ . '/../../login-signup/session_init.php';
require_once __DIR__ . '/../../Db/connection.php';
require_once __DIR__ . '/../../Db/activity_log.php';

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
    'CANCELLED',
    'COMPLETED',
    'CONFIRMED',
    'PENDING',
    'PREPARING',
    'READY',
];
$pageSize = 5;

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

    $exists = $pdo->prepare(
        'SELECT o.order_id, o.order_status, op.payment_status, op.provider
           FROM orders o
           LEFT JOIN order_payments op ON op.order_id = o.order_id
          WHERE o.order_id = ?
          LIMIT 1'
    );
    $exists->execute([(int) $orderId]);
    $transaction = $exists->fetch();

    if (!$transaction) {
        admin_transactions_respond(false, 'That transaction no longer exists.', 404);
    }
    if (
        $action === 'delete'
        && $transaction['order_status'] !== 'CANCELLED'
        &&
        $transaction['payment_status'] !== null
        && $transaction['payment_status'] !== 'PAID'
    ) {
        admin_transactions_respond(
            false,
            $transaction['provider'] === 'in_store'
                ? 'Use the staff orders board to collect the store payment and complete this order.'
                : 'This order cannot be changed until PayMongo confirms payment.',
            422
        );
    }

    if ($action === 'update') {

        if ($transaction['order_status'] === 'COMPLETED') {
            admin_transactions_respond(false, 'Completed orders cannot be edited.', 422);
        }

        $statusInput = strtoupper(trim((string) ($_POST['order_status'] ?? '')));

        if ($statusInput === '' || !preg_match('/^[A-Z_]+$/', $statusInput)) {
            admin_transactions_respond(false, 'Please choose a valid order status.');
        }

        if (!in_array($statusInput, $orderStatuses, true)) {
            admin_transactions_respond(false, 'Please choose a valid order status.');
        }

        $update = $pdo->prepare('UPDATE orders SET order_status = ? WHERE order_id = ?');
        $update->execute([$statusInput, (int) $orderId]);
        brewski_record_activity($pdo, 'orders', 'Updated order status', 'Changed order #' . (int) $orderId . ' to ' . $statusInput . '.', 'order', (int) $orderId);

        $_SESSION['admin_flash'] = [
            'type'    => 'success',
            'message' => 'Transaction #' . (int) $orderId . ' is now ' . $statusInput . '.',
        ];

        admin_transactions_respond(true, 'Transaction updated.');
    }

    if ($action === 'delete') {

        $delete = $pdo->prepare('DELETE FROM orders WHERE order_id = ?');
        $delete->execute([(int) $orderId]);
        brewski_record_activity($pdo, 'orders', 'Deleted order', 'Deleted order #' . (int) $orderId . '.', 'order', (int) $orderId);

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
            cu.first_name AS customer_first_name,
            cu.last_name AS customer_last_name,
            NULL AS staff_name,
            (SELECT GROUP_CONCAT(
                        CONCAT(oi.quantity, 'x ', COALESCE(p.product_name, 'Unknown item'))
                        SEPARATOR ', '
                    )
               FROM order_items oi
               LEFT JOIN products p ON p.product_id = oi.product_id
              WHERE oi.order_id = o.order_id) AS item_summary
     FROM orders o
     LEFT JOIN order_payments op ON op.order_id = o.order_id
     LEFT JOIN users cu ON cu.user_id = o.user_id
     WHERE op.payment_status IS NULL OR op.payment_status = 'PAID' OR op.provider = 'in_store'
     ORDER BY o.order_date DESC, o.order_id DESC"
)->fetchAll();
foreach ($transactions as &$transaction) {
    $transaction['customer_name'] = trim(
        (string) brewski_personal_decrypt($transaction['customer_first_name'], 'first_name') . ' '
        . (string) brewski_personal_decrypt($transaction['customer_last_name'], 'last_name')
    );
}
unset($transaction);

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

<div class="page-container customer-management-page">

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
                        <th><span class="table-heading-label"><i data-lucide="calendar-clock" aria-hidden="true"></i>Date / Time</span></th>
                        <th><span class="table-heading-label"><i data-lucide="user-round" aria-hidden="true"></i>Customer Name</span></th>
                        <th><span class="table-heading-label"><i data-lucide="package" aria-hidden="true"></i>Items</span></th>
                        <th><span class="table-heading-label"><i data-lucide="circle-check" aria-hidden="true"></i>Status</span></th>
                        <th><span class="table-heading-label"><i data-lucide="banknote" aria-hidden="true"></i>Total</span></th>
                        <th><span class="table-heading-label"><i data-lucide="user-round-cog" aria-hidden="true"></i>Staff</span></th>
                        <th class="col-actions"><span class="table-heading-label"><i data-lucide="ellipsis" aria-hidden="true"></i>Actions</span></th>
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
                                    <button type="button" class="btn btn-edit" data-action="edit"<?= $orderStatus === 'COMPLETED' ? ' disabled title="Completed orders cannot be edited."' : '' ?>>Edit</button>
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

        <div class="pagination-footer">
            <p class="pagination-info" id="txnPageInfo"></p>
            <div class="pagination-nav" id="txnPagination"></div>
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
        const pagination = document.getElementById('txnPagination');
        const pageInfo = document.getElementById('txnPageInfo');
        const pageSize = <?= (int) $pageSize ?>;

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

        let currentPage = 1;

        function pageButton(label, disabled, active, handler) {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'page-link' + (label === 'Previous' || label === 'Next' ? ' pagination-control' : '') + (active ? ' active' : '');
            item.textContent = label;
            item.disabled = disabled;
            item.addEventListener('click', handler);
            return item;
        }

        function renderPagination(pageCount) {
            pagination.innerHTML = '';

            pagination.appendChild(pageButton('Previous', currentPage === 1, false, function () {
                currentPage--;
                render();
            }));

            for (let page = 1; page <= pageCount; page++) {
                (function (target) {
                    pagination.appendChild(pageButton(String(target), false, target === currentPage, function () {
                        currentPage = target;
                        render();
                    }));
                })(page);
            }

            pagination.appendChild(pageButton('Next', currentPage === pageCount, false, function () {
                currentPage++;
                render();
            }));
        }

        function render() {
            const term = searchInput.value.trim().toLowerCase();
            const status = statusFilter.value;

            const filtered = rows.filter(function (row) {
                const haystack = [
                    row.dataset.id,
                    row.dataset.customer,
                    row.dataset.items,
                    row.dataset.staff
                ].join(' ').toLowerCase();

                const matchesTerm = !term || haystack.includes(term);
                const matchesStatus = status === 'all' || row.dataset.status === status;

                return matchesTerm && matchesStatus;
            });

            const pageCount = Math.max(1, Math.ceil(filtered.length / pageSize));
            if (currentPage > pageCount) currentPage = pageCount;

            const start = (currentPage - 1) * pageSize;
            const pageRows = filtered.slice(start, start + pageSize);

            rows.forEach(function (row) { row.classList.add('hidden'); });
            pageRows.forEach(function (row) { row.classList.remove('hidden'); });

            noResultsRow.classList.toggle('hidden', filtered.length > 0);

            pageInfo.textContent = filtered.length === 0
                ? 'No results'
                : 'Showing ' + (start + 1) + ' to ' + Math.min(start + pageSize, filtered.length) +
                  ' of ' + filtered.length + ' transactions';

            renderPagination(pageCount);
        }

        function send(body) {
            return fetch(ENDPOINT, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }).then(function (response) {
                return response.text().then(function (payload) {
                    var json;

                    try {
                        json = JSON.parse(payload);
                    } catch (error) {
                        if (response.redirected || response.url.indexOf('/login') !== -1) {
                            throw new Error('Your Admin session has expired. Please sign in again.');
                        }

                        throw new Error('The server returned an unexpected response. Please reload and try again.');
                    }

                    if (!response.ok) {
                        throw new Error(json.error || json.message || 'The request could not be completed.');
                    }

                    return json;
                });
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
                            }).catch(function (error) {
                                setStatus(error.message || 'The request could not be completed. Please try again.', 'error');
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
            }).catch(function (error) {
                setStatus(error.message || 'The request could not be completed. Please try again.', 'error');
            });
        }

        searchInput.addEventListener('input', function () {
            currentPage = 1;
            render();
        });
        statusFilter.addEventListener('change', function () {
            currentPage = 1;
            render();
        });

        tbody.addEventListener('click', function (event) {
            const button = event.target.closest('button[data-action]');
            if (!button) return;

            const row = button.closest('tr[data-id]');
            if (!row) return;

            if (button.dataset.action === 'view') viewTransaction(row);
            if (button.dataset.action === 'edit') editTransaction(row);
            if (button.dataset.action === 'delete') deleteTransaction(row);
        });

        render();
    })();
</script>
<?php echo brewski_session_timeout_markup(); ?>

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

$deliveryStatuses = [
    'UNASSIGNED',
    'ASSIGNED',
    'PICKED_UP',
    'IN_TRANSIT',
    'DELIVERED',
    'FAILED',
    'CANCELLED',
];

function admin_handled_respond(bool $ok, string $message, int $status = 200): void
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

function admin_handled_badge(string $status): string
{
    if ($status === 'DELIVERED') {
        return 'badge-active';
    }

    if ($status === 'FAILED' || $status === 'CANCELLED') {
        return 'badge-locked';
    }

    return 'badge-pending';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        admin_handled_respond(false, 'Your session expired. Please reload the page.', 403);
    }

    $action = (string) ($_POST['action'] ?? '');
    $deliveryId = filter_var($_POST['delivery_id'] ?? null, FILTER_VALIDATE_INT);

    if ($deliveryId === false || $deliveryId <= 0) {
        admin_handled_respond(false, 'Please choose a valid delivery record.');
    }

    $exists = $pdo->prepare('SELECT delivery_id FROM deliveries WHERE delivery_id = ? LIMIT 1');
    $exists->execute([(int) $deliveryId]);

    if (!$exists->fetch()) {
        admin_handled_respond(false, 'That delivery record no longer exists.', 404);
    }

    if ($action === 'update') {

        $statusInput = strtoupper(trim((string) ($_POST['delivery_status'] ?? '')));
        $notesInput = trim((string) ($_POST['notes'] ?? ''));

        if (!in_array($statusInput, $deliveryStatuses, true)) {
            admin_handled_respond(false, 'Please choose a valid delivery status.');
        }

        if (mb_strlen($notesInput) > 500) {
            admin_handled_respond(false, 'Delivery notes must be 500 characters or fewer.');
        }

        $update = $pdo->prepare(
            'UPDATE deliveries SET delivery_status = ?, notes = ? WHERE delivery_id = ?'
        );
        $update->execute([
            $statusInput,
            $notesInput !== '' ? $notesInput : null,
            (int) $deliveryId,
        ]);

        $_SESSION['admin_flash'] = [
            'type'    => 'success',
            'message' => 'Delivery #' . (int) $deliveryId . ' is now ' . $statusInput . '.',
        ];

        admin_handled_respond(true, 'Delivery updated.');
    }

    if ($action === 'delete') {

        try {
            $delete = $pdo->prepare('DELETE FROM deliveries WHERE delivery_id = ?');
            $delete->execute([(int) $deliveryId]);
        } catch (PDOException $exception) {
            admin_handled_respond(
                false,
                'That delivery record is still linked to other records, so it cannot be deleted.',
                409
            );
        }

        $_SESSION['admin_flash'] = [
            'type'    => 'success',
            'message' => 'Delivery #' . (int) $deliveryId . ' has been deleted.',
        ];

        admin_handled_respond(true, 'Delivery deleted.');
    }

    admin_handled_respond(false, 'That action is not recognised.');
}

$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

$deliveries = $pdo->query(
    "SELECT d.delivery_id,
            d.delivery_status,
            d.notes,
            d.delivery_fee,
            o.order_id,
            o.order_date,
            o.total_amount,
            TRIM(CONCAT(COALESCE(cu.first_name, ''), ' ', COALESCE(cu.last_name, ''))) AS customer_name,
            TRIM(CONCAT(COALESCE(su.first_name, ''), ' ', COALESCE(su.last_name, ''))) AS staff_name,
            (SELECT GROUP_CONCAT(
                        CONCAT(oi.quantity, 'x ', COALESCE(p.product_name, 'Unknown item'))
                        SEPARATOR ', '
                    )
               FROM order_items oi
               LEFT JOIN products p ON p.product_id = oi.product_id
              WHERE oi.order_id = o.order_id) AS item_summary
     FROM deliveries d
     JOIN orders o ON o.order_id = d.order_id
     LEFT JOIN users cu ON cu.user_id = o.user_id
     LEFT JOIN users su ON su.user_id = d.staff_id
     ORDER BY o.order_date DESC, d.delivery_id DESC"
)->fetchAll();

$staffNames = [];

foreach ($deliveries as $delivery) {
    $name = trim((string) $delivery['staff_name']);

    if ($name !== '' && !in_array($name, $staffNames, true)) {
        $staffNames[] = $name;
    }
}

sort($staffNames);
?>

<div class="page-container">

    <div class="page-header">
        <h1 class="page-title">Transactions Handled</h1>
        <p class="subtitle">Review the orders processed and delivered by each staff member.</p>
    </div>

    <p
        id="handledStatus"
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
                <input type="text" id="handledSearch" class="toolbar-input" placeholder="Search by receipt, staff, customer..." aria-label="Search handled transactions">
            </div>

            <select id="handledStaffFilter" class="toolbar-select" aria-label="Filter by staff">
                <option value="all">All staff</option>
                <?php foreach ($staffNames as $staffName): ?>
                    <option value="<?= htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>

            <select id="handledStatusFilter" class="toolbar-select" aria-label="Filter by status">
                <option value="all">All statuses</option>
                <?php foreach ($deliveryStatuses as $statusOption): ?>
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
                        <th>Staff Name</th>
                        <th>Customer Name</th>
                        <th>Items</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th class="col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody id="handledTableBody">
                    <?php foreach ($deliveries as $delivery): ?>
                        <?php
                        $deliveryId = (int) $delivery['delivery_id'];
                        $deliveryStamp = strtotime((string) $delivery['order_date']);
                        $deliveryStatus = (string) $delivery['delivery_status'];
                        $deliveryNotes = (string) ($delivery['notes'] ?? '');
                        $deliveryCustomer = trim((string) $delivery['customer_name']);
                        $deliveryStaff = trim((string) $delivery['staff_name']);
                        $deliveryItems = (string) ($delivery['item_summary'] ?? '');
                        $deliveryTotal = (float) $delivery['total_amount'];

                        if ($deliveryCustomer === '') {
                            $deliveryCustomer = 'Guest';
                        }

                        if ($deliveryStaff === '') {
                            $deliveryStaff = 'Unassigned';
                        }

                        if ($deliveryItems === '') {
                            $deliveryItems = 'No items';
                        }
                        ?>
                        <tr
                            data-id="<?= $deliveryId ?>"
                            data-order="<?= (int) $delivery['order_id'] ?>"
                            data-datetime="<?= htmlspecialchars($deliveryStamp !== false ? date('Y-m-d\TH:i', $deliveryStamp) : '', ENT_QUOTES, 'UTF-8') ?>"
                            data-staff="<?= htmlspecialchars($deliveryStaff, ENT_QUOTES, 'UTF-8') ?>"
                            data-customer="<?= htmlspecialchars($deliveryCustomer, ENT_QUOTES, 'UTF-8') ?>"
                            data-items="<?= htmlspecialchars($deliveryItems, ENT_QUOTES, 'UTF-8') ?>"
                            data-amount="<?= number_format($deliveryTotal, 2, '.', '') ?>"
                            data-status="<?= htmlspecialchars($deliveryStatus, ENT_QUOTES, 'UTF-8') ?>"
                            data-notes="<?= htmlspecialchars($deliveryNotes, ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <td>
                                <div class="cell-stack">
                                    <span class="cell-strong js-handled-date"><?= htmlspecialchars($deliveryStamp !== false ? date('M j, Y', $deliveryStamp) : '—', ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="cell-sub js-handled-time"><?= htmlspecialchars($deliveryStamp !== false ? date('h:i A', $deliveryStamp) : '', ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            </td>

                            <td><span class="cell-strong js-handled-staff"><?= htmlspecialchars($deliveryStaff, ENT_QUOTES, 'UTF-8') ?></span></td>

                            <td><span class="cell-muted js-handled-customer"><?= htmlspecialchars($deliveryCustomer, ENT_QUOTES, 'UTF-8') ?></span></td>

                            <td><span class="cell-muted js-handled-items"><?= htmlspecialchars($deliveryItems, ENT_QUOTES, 'UTF-8') ?></span></td>

                            <td><span class="cell-amount js-handled-amount">&#8369;<?= number_format($deliveryTotal, 2) ?></span></td>

                            <td><span class="badge <?= admin_handled_badge($deliveryStatus) ?> js-handled-status"><?= htmlspecialchars($deliveryStatus, ENT_QUOTES, 'UTF-8') ?></span></td>

                            <td class="col-actions">
                                <div class="table-actions">
                                    <button type="button" class="btn btn-view" data-action="view">View</button>
                                    <button type="button" class="btn btn-edit" data-action="edit">Edit</button>
                                    <button type="button" class="btn btn-delete" data-action="delete">Delete</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <tr id="handledNoResults" class="hidden">
                        <td colspan="7" class="empty-cell">
                            <div class="empty-state">
                                <p class="empty-title">No handled transactions found</p>
                                <p class="empty-hint">Try adjusting your search or filters.</p>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="pagination-footer">
            <p class="pagination-info" id="handledCount"></p>
        </div>
    </div>
</div>

<script>
    (function () {
        const ENDPOINT = <?= json_encode($endpoint, JSON_UNESCAPED_SLASHES) ?>;
        const CSRF = <?= json_encode($csrfToken) ?>;
        const STATUSES = <?= json_encode($deliveryStatuses) ?>;

        const search = document.getElementById('handledSearch');
        const staffFilter = document.getElementById('handledStaffFilter');
        const statusFilter = document.getElementById('handledStatusFilter');
        const tbody = document.getElementById('handledTableBody');
        const noResults = document.getElementById('handledNoResults');
        const count = document.getElementById('handledCount');
        const statusLine = document.getElementById('handledStatus');

        if (!search || !tbody) return;

        const STATUS_CLASS = {
            DELIVERED: 'badge-active',
            FAILED: 'badge-locked',
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

        function send(body) {
            return fetch(ENDPOINT, {
                method: 'POST',
                body: body,
                credentials: 'same-origin'
            }).then(function (response) {
                return response.json();
            });
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
            const term = search.value.trim().toLowerCase();
            const staff = staffFilter.value;
            const status = statusFilter.value;
            let visible = 0;

            rows.forEach(function (row) {
                const data = row.dataset;
                const haystack = (data.id + ' ' + data.staff + ' ' + data.customer + ' ' + data.items).toLowerCase();

                const show = (!term || haystack.includes(term)) &&
                    (staff === 'all' || data.staff === staff) &&
                    (status === 'all' || data.status === status);

                row.classList.toggle('hidden', !show);

                if (show) visible++;
            });

            noResults.classList.toggle('hidden', visible > 0);
            count.textContent = visible
                ? 'Showing ' + visible + ' of ' + rows.length + ' transactions'
                : 'No results';
        }

        function viewDelivery(row) {
            const data = row.dataset;

            window.openAdminModal({
                title: 'Delivery #' + data.id,
                body: '<dl class="detail-list">' +
                        '<dt>Delivery No.</dt><dd>#' + escapeHtml(data.id) + '</dd>' +
                        '<dt>Order No.</dt><dd>#' + escapeHtml(data.order) + '</dd>' +
                        '<dt>Date &amp; time</dt><dd>' + escapeHtml(formatDate(data.datetime) + ', ' + formatTime(data.datetime)) + '</dd>' +
                        '<dt>Staff name</dt><dd>' + escapeHtml(data.staff) + '</dd>' +
                        '<dt>Customer name</dt><dd>' + escapeHtml(data.customer) + '</dd>' +
                        '<dt>Items</dt><dd>' + escapeHtml(data.items) + '</dd>' +
                        '<dt>Amount</dt><dd>' + escapeHtml(formatPeso(data.amount)) + '</dd>' +
                        '<dt>Status</dt><dd><span class="badge ' + statusClass(data.status) + '">' + escapeHtml(data.status) + '</span></dd>' +
                        '<dt>Notes</dt><dd>' + escapeHtml(data.notes || 'None') + '</dd>' +
                      '</dl>'
            });
        }

        function editDelivery(row) {
            const data = row.dataset;

            window.openAdminModal({
                title: 'Edit delivery #' + data.id,
                body: '<form class="form-grid" id="handledEditForm">' +
                        '<input type="hidden" name="csrf_token" value="' + escapeHtml(CSRF) + '">' +
                        '<input type="hidden" name="action" value="update">' +
                        '<input type="hidden" name="delivery_id" value="' + escapeHtml(data.id) + '">' +
                        '<div class="form-field form-field-full">' +
                            '<label for="handledEditStatus">Delivery status</label>' +
                            '<select id="handledEditStatus" name="delivery_status">' + statusOptions(data.status) + '</select>' +
                        '</div>' +
                        '<div class="form-field form-field-full">' +
                            '<label for="handledEditNotes">Delivery notes</label>' +
                            '<textarea id="handledEditNotes" name="notes" maxlength="500" rows="3">' + escapeHtml(data.notes) + '</textarea>' +
                            '<span class="form-hint">Up to 500 characters.</span>' +
                        '</div>' +
                      '</form>',
                footer: [
                    { label: 'Cancel', className: 'btn-secondary' },
                    {
                        label: 'Save changes',
                        className: 'btn-primary',
                        onClick: function (handle) {
                            const form = handle.element.querySelector('#handledEditForm');

                            if (!form.reportValidity()) return false;

                            setStatus('Saving...', '');

                            send(new FormData(form)).then(function (json) {
                                if (json.ok) {
                                    handle.close();
                                    window.brewskiReloadView();
                                } else {
                                    setStatus(json.error || 'Could not save the delivery.', 'error');
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

        function deleteDelivery(row) {
            if (!confirm('Delete delivery #' + row.dataset.id + '? This action cannot be undone.')) return;

            const body = new FormData();
            body.append('csrf_token', CSRF);
            body.append('action', 'delete');
            body.append('delivery_id', row.dataset.id);

            setStatus('Deleting...', '');

            send(body).then(function (json) {
                if (json.ok) {
                    window.brewskiReloadView();
                } else {
                    setStatus(json.error || 'Could not delete the delivery.', 'error');
                }
            }).catch(function () {
                setStatus('Network error. Please try again.', 'error');
            });
        }

        search.addEventListener('input', applyFilters);
        staffFilter.addEventListener('change', applyFilters);
        statusFilter.addEventListener('change', applyFilters);

        tbody.addEventListener('click', function (event) {
            const action = event.target.closest('button[data-action]');
            if (!action) return;

            const row = action.closest('tr[data-id]');
            if (!row) return;

            if (action.dataset.action === 'view') viewDelivery(row);
            if (action.dataset.action === 'edit') editDelivery(row);
            if (action.dataset.action === 'delete') deleteDelivery(row);
        });

        applyFilters();
    })();
</script>

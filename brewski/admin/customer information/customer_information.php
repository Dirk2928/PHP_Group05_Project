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

function customer_information_respond(bool $ok, string $message, int $status = 200): void
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

function customer_information_ids(): array
{
    $raw = $_POST['ids'] ?? [];

    if (!is_array($raw)) {
        $raw = [$raw];
    }

    $ids = [];

    foreach ($raw as $value) {
        $id = filter_var($value, FILTER_VALIDATE_INT);

        if ($id !== false && $id > 0) {
            $ids[] = (int) $id;
        }
    }

    return array_values(array_unique($ids));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        customer_information_respond(false, 'Your session expired. Please reload the page.', 403);
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'update') {

        $customerId = filter_var($_POST['customer_id'] ?? null, FILTER_VALIDATE_INT);
        $firstName  = trim((string) ($_POST['first_name'] ?? ''));
        $lastName   = trim((string) ($_POST['last_name'] ?? ''));
        $email      = trim((string) ($_POST['email'] ?? ''));
        $activeRaw  = (string) ($_POST['is_active'] ?? '');

        if ($customerId === false || $customerId <= 0) {
            customer_information_respond(false, 'Please choose a customer to update.');
        }

        if (
            $firstName === ''
            || mb_strlen($firstName) > 100
            || !preg_match("/^[A-Za-zÀ-ÿ\s'\-]+$/u", $firstName)
        ) {
            customer_information_respond(false, 'Please enter a valid first name.');
        }

        if (
            $lastName === ''
            || mb_strlen($lastName) > 100
            || !preg_match("/^[A-Za-zÀ-ÿ\s'\-]+$/u", $lastName)
        ) {
            customer_information_respond(false, 'Please enter a valid last name.');
        }

        if (
            $email === ''
            || mb_strlen($email) > 255
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            customer_information_respond(false, 'Please enter a valid email address.');
        }

        if ($activeRaw !== '0' && $activeRaw !== '1') {
            customer_information_respond(false, 'Please choose a valid status.');
        }

        $target = $pdo->prepare(
            "SELECT user_id FROM users WHERE user_id = ? AND role = 'CUSTOMER' LIMIT 1"
        );
        $target->execute([(int) $customerId]);

        if (!$target->fetch()) {
            customer_information_respond(false, 'That customer no longer exists.', 404);
        }

        $duplicate = $pdo->prepare(
            'SELECT user_id FROM users WHERE email = ? AND user_id <> ? LIMIT 1'
        );
        $duplicate->execute([$email, (int) $customerId]);

        if ($duplicate->fetch()) {
            customer_information_respond(false, 'That email address is already used by another account.');
        }

        $update = $pdo->prepare(
            'UPDATE users
             SET first_name = ?, last_name = ?, email = ?, is_active = ?
             WHERE user_id = ?'
        );
        $update->execute([
            $firstName,
            $lastName,
            $email,
            (int) $activeRaw,
            (int) $customerId,
        ]);

        $_SESSION['admin_flash'] = [
            'type'    => 'success',
            'message' => 'Customer #' . (int) $customerId . ' has been updated.',
        ];

        customer_information_respond(true, 'Customer updated.');
    }

    if ($action === 'delete') {

        $ids = customer_information_ids();

        if (!$ids) {
            customer_information_respond(false, 'Please choose at least one customer to delete.');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $removed = 0;

        $pdo->beginTransaction();

        try {

            $delete = $pdo->prepare(
                "DELETE FROM users WHERE role = 'CUSTOMER' AND user_id IN ($placeholders)"
            );
            $delete->execute($ids);
            $removed = $delete->rowCount();

            $pdo->commit();

        } catch (PDOException $error) {

            $pdo->rollBack();

            customer_information_respond(
                false,
                'Those customers still have orders on record, so they cannot be deleted.',
                409
            );
        }

        if ($removed === 0) {
            customer_information_respond(false, 'No matching customers were found.', 404);
        }

        $_SESSION['admin_flash'] = [
            'type'    => 'success',
            'message' => $removed . ' customer(s) deleted.',
        ];

        customer_information_respond(true, 'Customers deleted.');
    }

    customer_information_respond(false, 'That action is not recognised.');
}

$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

$customers = $pdo->query(
    "SELECT user_id, first_name, last_name, email, is_active, created_at
     FROM users
     WHERE role = 'CUSTOMER'
     ORDER BY user_id DESC"
)->fetchAll();

$pageSize = 5;
?>

<div class="page-container">

    <div class="page-header">
        <h1 class="page-title">Customer Information</h1>
        <p class="subtitle">Manage customer accounts, access levels, and verification status.</p>
    </div>

    <p
        id="customerStatus"
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
                <input type="text" id="searchInput" class="toolbar-input" placeholder="Search by name or ID..." aria-label="Search customers">
            </div>

            <select id="statusFilter" class="toolbar-select" aria-label="Filter by status">
                <option value="all">All Statuses</option>
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
            </select>
        </div>

        <div id="bulkActions" class="bulk-actions-bar">
            <span><span class="bulk-count" id="selectedCount">0</span> selected</span>
            <button type="button" id="deleteSelectedBtn" class="btn btn-delete">Delete selected</button>
        </div>
    </div>

    <div class="table-card">
        <div class="table-scroll">
            <table class="data-table table-wide">
                <thead>
                    <tr>
                        <th class="col-check">
                            <input type="checkbox" id="selectAll" class="row-checkbox" aria-label="Select all customers on this page">
                        </th>
                        <th>Customer No.</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Created Date</th>
                        <th class="col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody id="customerTableBody">
                    <?php foreach ($customers as $customer): ?>
                        <?php
                        $customerId = (int) $customer['user_id'];
                        $customerName = trim($customer['first_name'] . ' ' . $customer['last_name']);
                        $customerEmail = (string) $customer['email'];
                        $customerStatus = ((int) $customer['is_active']) === 1 ? 'Active' : 'Inactive';
                        $customerCreated = $customer['created_at'] !== null
                            ? date('Y-m-d', strtotime((string) $customer['created_at']))
                            : '';
                        ?>
                        <tr
                            data-id="<?= $customerId ?>"
                            data-name="<?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?>"
                            data-email="<?= htmlspecialchars($customerEmail, ENT_QUOTES, 'UTF-8') ?>"
                            data-first="<?= htmlspecialchars((string) $customer['first_name'], ENT_QUOTES, 'UTF-8') ?>"
                            data-last="<?= htmlspecialchars((string) $customer['last_name'], ENT_QUOTES, 'UTF-8') ?>"
                            data-status="<?= $customerStatus ?>"
                            data-created="<?= htmlspecialchars($customerCreated, ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <td class="col-check">
                                <input type="checkbox" class="row-checkbox" value="<?= $customerId ?>" aria-label="Select <?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?>">
                            </td>

                            <td><span class="cell-id">#<?= $customerId ?></span></td>

                            <td><span class="cell-strong js-name"><?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?></span></td>

                            <td><span class="cell-muted"><?= htmlspecialchars($customerEmail, ENT_QUOTES, 'UTF-8') ?></span></td>

                            <td>
                                <span class="js-status">
                                    <span class="badge <?= $customerStatus === 'Active' ? 'badge-active' : 'badge-inactive' ?>">
                                        <?= $customerStatus ?>
                                    </span>
                                </span>
                            </td>

                            <td><span class="cell-muted js-created"><?= htmlspecialchars($customerCreated, ENT_QUOTES, 'UTF-8') ?></span></td>

                            <td class="col-actions">
                                <div class="table-actions">
                                    <button type="button" class="btn btn-view" data-action="view">View</button>
                                    <button type="button" class="btn btn-edit" data-action="edit">Edit</button>
                                    <button type="button" class="btn btn-delete" data-action="delete">Delete</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <tr id="noResultsRow" class="hidden">
                        <td colspan="7" class="empty-cell">
                            <div class="empty-state">
                                <p class="empty-title">No customers found</p>
                                <p class="empty-hint">Try adjusting your search or filter criteria.</p>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="pagination-footer">
            <p class="pagination-info" id="pageInfo"></p>
            <div class="pagination-nav" id="paginationNav"></div>
        </div>
    </div>
</div>

<script>
    (function () {
        const ENDPOINT = <?= json_encode($endpoint, JSON_UNESCAPED_SLASHES) ?>;
        const CSRF = <?= json_encode($csrfToken) ?>;
        const pageSize = <?= (int) $pageSize ?>;

        const searchInput = document.getElementById('searchInput');
        const statusFilter = document.getElementById('statusFilter');
        const selectAllCb = document.getElementById('selectAll');
        const tbody = document.getElementById('customerTableBody');
        const noResultsRow = document.getElementById('noResultsRow');
        const bulkActions = document.getElementById('bulkActions');
        const selectedCountSpan = document.getElementById('selectedCount');
        const deleteSelectedBtn = document.getElementById('deleteSelectedBtn');
        const pageInfo = document.getElementById('pageInfo');
        const paginationNav = document.getElementById('paginationNav');
        const statusLine = document.getElementById('customerStatus');

        if (!searchInput || !tbody) return;

        const STATUS_CLASS = {
            Active: 'badge-active',
            Inactive: 'badge-inactive'
        };

        let allRows = Array.from(tbody.querySelectorAll('tr[data-id]'));
        let filteredRows = allRows.slice();
        let currentPage = 1;

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

        function statusBadge(status) {
            return '<span class="badge ' + (STATUS_CLASS[status] || 'badge-inactive') + '">' +
                escapeHtml(status) + '</span>';
        }

        function currentPageRows() {
            const start = (currentPage - 1) * pageSize;
            return filteredRows.slice(start, start + pageSize);
        }

        function render() {
            const term = searchInput.value.trim().toLowerCase();
            const status = statusFilter.value;

            filteredRows = allRows.filter(function (row) {
                const matchesTerm = !term ||
                    row.dataset.name.toLowerCase().includes(term) ||
                    row.dataset.id.toLowerCase().includes(term);
                const matchesStatus = status === 'all' || row.dataset.status === status;

                return matchesTerm && matchesStatus;
            });

            const pageCount = Math.max(1, Math.ceil(filteredRows.length / pageSize));
            if (currentPage > pageCount) currentPage = pageCount;

            const pageRows = currentPageRows();
            const start = (currentPage - 1) * pageSize;

            allRows.forEach(function (row) { row.classList.add('hidden'); });
            pageRows.forEach(function (row) { row.classList.remove('hidden'); });

            noResultsRow.classList.toggle('hidden', filteredRows.length > 0);

            pageInfo.textContent = filteredRows.length === 0
                ? 'No results'
                : 'Showing ' + (start + 1) + ' to ' + Math.min(start + pageSize, filteredRows.length) +
                  ' of ' + filteredRows.length + ' results';

            renderPagination(pageCount);
            syncSelectAll();
            updateBulkUI();
        }

        function renderPagination(pageCount) {
            paginationNav.innerHTML = '';

            paginationNav.appendChild(pageButton('Previous', currentPage === 1, false, function () {
                currentPage--;
                render();
            }));

            for (let page = 1; page <= pageCount; page++) {
                paginationNav.appendChild(pageButton(String(page), false, page === currentPage, function () {
                    currentPage = page;
                    render();
                }));
            }

            paginationNav.appendChild(pageButton('Next', currentPage === pageCount, false, function () {
                currentPage++;
                render();
            }));
        }

        function pageButton(label, disabled, isActive, onClick) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'page-link' + (isActive ? ' active' : '');
            button.textContent = label;
            button.disabled = disabled;
            if (isActive) button.setAttribute('aria-current', 'page');
            button.addEventListener('click', onClick);

            return button;
        }

        function syncSelectAll() {
            const pageRows = currentPageRows();
            const checked = pageRows.filter(function (row) {
                const box = row.querySelector('.row-checkbox');
                return box && box.checked;
            }).length;

            selectAllCb.checked = pageRows.length > 0 && checked === pageRows.length;
            selectAllCb.indeterminate = checked > 0 && checked < pageRows.length;
        }

        function updateBulkUI() {
            const count = tbody.querySelectorAll('.row-checkbox:checked').length;
            selectedCountSpan.textContent = count;
            bulkActions.classList.toggle('visible', count > 0);
        }

        function resetSelection() {
            tbody.querySelectorAll('.row-checkbox:checked').forEach(function (box) {
                box.checked = false;
            });
            selectAllCb.checked = false;
            selectAllCb.indeterminate = false;
        }

        function send(body) {
            return fetch(ENDPOINT, {
                method: 'POST',
                body: body,
                credentials: 'same-origin'
            }).then(function (response) {
                return response.json().then(function (json) {
                    return json;
                });
            });
        }

        function deletePayload(ids) {
            const body = new FormData();
            body.append('csrf_token', CSRF);
            body.append('action', 'delete');
            ids.forEach(function (id) { body.append('ids[]', id); });

            return body;
        }

        function viewCustomer(row) {
            const data = row.dataset;

            window.openAdminModal({
                title: 'Customer #' + data.id,
                body: '<dl class="detail-list">' +
                        '<dt>Customer No.</dt><dd>#' + escapeHtml(data.id) + '</dd>' +
                        '<dt>Name</dt><dd>' + escapeHtml(data.name) + '</dd>' +
                        '<dt>Email</dt><dd>' + escapeHtml(data.email) + '</dd>' +
                        '<dt>Status</dt><dd>' + statusBadge(data.status) + '</dd>' +
                        '<dt>Created Date</dt><dd>' + escapeHtml(data.created) + '</dd>' +
                      '</dl>'
            });
        }

        function editCustomer(row) {
            const data = row.dataset;

            window.openAdminModal({
                title: 'Edit customer #' + data.id,
                body: '<form class="form-grid" id="customerEditForm">' +
                        '<input type="hidden" name="csrf_token" value="' + escapeHtml(CSRF) + '">' +
                        '<input type="hidden" name="action" value="update">' +
                        '<input type="hidden" name="customer_id" value="' + escapeHtml(data.id) + '">' +
                        '<div class="form-field">' +
                            '<label for="customerEditFirst">First name</label>' +
                            '<input type="text" id="customerEditFirst" name="first_name" value="' + escapeHtml(data.first) + '" maxlength="100" required>' +
                        '</div>' +
                        '<div class="form-field">' +
                            '<label for="customerEditLast">Last name</label>' +
                            '<input type="text" id="customerEditLast" name="last_name" value="' + escapeHtml(data.last) + '" maxlength="100" required>' +
                        '</div>' +
                        '<div class="form-field form-field-full">' +
                            '<label for="customerEditEmail">Email address</label>' +
                            '<input type="email" id="customerEditEmail" name="email" value="' + escapeHtml(data.email) + '" maxlength="255" required>' +
                        '</div>' +
                        '<div class="form-field form-field-full">' +
                            '<label for="customerEditStatus">Status</label>' +
                            '<select id="customerEditStatus" name="is_active">' +
                                '<option value="1"' + (data.status === 'Active' ? ' selected' : '') + '>Active</option>' +
                                '<option value="0"' + (data.status === 'Inactive' ? ' selected' : '') + '>Inactive</option>' +
                            '</select>' +
                        '</div>' +
                      '</form>',
                footer: [
                    { label: 'Cancel', className: 'btn-secondary' },
                    {
                        label: 'Save changes',
                        className: 'btn-primary',
                        onClick: function (handle) {
                            const form = handle.element.querySelector('#customerEditForm');

                            if (!form.reportValidity()) return false;

                            setStatus('Saving...', '');

                            send(new FormData(form)).then(function (json) {
                                if (json.ok) {
                                    window.brewskiReloadView();
                                } else {
                                    setStatus(json.error || 'Could not save the customer.', 'error');
                                }
                            }).catch(function () {
                                setStatus('Network error. Please try again.', 'error');
                            });

                            return true;
                        }
                    }
                ]
            });
        }

        function deleteCustomers(ids) {
            setStatus('Deleting...', '');

            send(deletePayload(ids)).then(function (json) {
                if (json.ok) {
                    window.brewskiReloadView();
                } else {
                    setStatus(json.error || 'Could not delete the selected customers.', 'error');
                }
            }).catch(function () {
                setStatus('Network error. Please try again.', 'error');
            });
        }

        function deleteCustomer(row) {
            if (!confirm('Delete customer #' + row.dataset.id + '? This action cannot be undone.')) return;

            deleteCustomers([row.dataset.id]);
        }

        function deleteSelected() {
            const checked = Array.from(tbody.querySelectorAll('.row-checkbox:checked'));
            if (checked.length === 0) return;

            if (!confirm('Delete ' + checked.length + ' selected customer(s)? This action cannot be undone.')) return;

            deleteCustomers(checked.map(function (box) { return box.value; }));
        }

        searchInput.addEventListener('input', function () {
            currentPage = 1;
            resetSelection();
            render();
        });

        statusFilter.addEventListener('change', function () {
            currentPage = 1;
            resetSelection();
            render();
        });

        selectAllCb.addEventListener('change', function () {
            currentPageRows().forEach(function (row) {
                const box = row.querySelector('.row-checkbox');
                if (box) box.checked = selectAllCb.checked;
            });
            updateBulkUI();
        });

        tbody.addEventListener('change', function (event) {
            if (event.target.classList.contains('row-checkbox')) {
                syncSelectAll();
                updateBulkUI();
            }
        });

        tbody.addEventListener('click', function (event) {
            const button = event.target.closest('button[data-action]');
            if (!button) return;

            const row = button.closest('tr[data-id]');
            if (!row) return;

            if (button.dataset.action === 'view') viewCustomer(row);
            if (button.dataset.action === 'edit') editCustomer(row);
            if (button.dataset.action === 'delete') deleteCustomer(row);
        });

        deleteSelectedBtn.addEventListener('click', deleteSelected);

        render();
    })();
</script>

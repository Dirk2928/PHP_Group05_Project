<?php




require_once __DIR__ . '/../../login-signup/session_init.php';

brewski_require_role(['ADMIN']);

$transactions = [
    ['id' => 'TXN-1007', 'datetime' => '2026-09-29T14:32', 'customer' => 'Ana de los Reyes', 'product' => 'Caramel Macchiato (Large)', 'method' => 'GCash', 'amount' => 195.00, 'staff' => 'Marco Reyes'],
    ['id' => 'TXN-1006', 'datetime' => '2026-09-29T13:58', 'customer' => 'Juan Dela Cruz',   'product' => 'Iced Americano (Medium)',  'method' => 'Cash',  'amount' => 120.00, 'staff' => 'Liza Bautista'],
    ['id' => 'TXN-1005', 'datetime' => '2026-09-29T12:41', 'customer' => 'Jose Rizal',       'product' => 'Spanish Latte (Large)',    'method' => 'Card',  'amount' => 210.00, 'staff' => 'Marco Reyes'],
    ['id' => 'TXN-1004', 'datetime' => '2026-09-28T18:05', 'customer' => 'Maria Santos',     'product' => 'Matcha Latte (Medium)',    'method' => 'GCash', 'amount' => 175.00, 'staff' => 'Liza Bautista'],
    ['id' => 'TXN-1003', 'datetime' => '2026-09-28T16:22', 'customer' => 'Pedro Penduko',    'product' => 'Cold Brew (Large)',        'method' => 'Cash',  'amount' => 160.00, 'staff' => 'Noel Aquino'],
    ['id' => 'TXN-1002', 'datetime' => '2026-09-28T11:10', 'customer' => 'Ana de los Reyes', 'product' => 'Cafe Latte (Medium)',      'method' => 'Card',  'amount' => 145.00, 'staff' => 'Noel Aquino'],
    ['id' => 'TXN-1001', 'datetime' => '2026-09-28T09:14', 'customer' => 'Juan Dela Cruz',   'product' => 'Iced Latte (Large)',       'method' => 'GCash', 'amount' => 185.00, 'staff' => 'Marco Reyes'],
];

$paymentMethods = ['Cash', 'GCash', 'Card'];
?>

<div class="page-container">


    <div class="page-header">
        <h1 class="page-title">Transactions</h1>
        <p class="subtitle">Review store sales, the payment used, and the staff member who handled each order.</p>
    </div>


    <div class="toolbar">
        <div class="toolbar-filters">
            <div class="search-field">
                <svg class="search-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
                <input type="text" id="txnSearch" class="toolbar-input" placeholder="Search by receipt, customer, product..." aria-label="Search transactions">
            </div>

            <select id="txnMethodFilter" class="toolbar-select" aria-label="Filter by payment method">
                <option value="all">All payment methods</option>
                <?php foreach ($paymentMethods as $method): ?>
                    <option value="<?= htmlspecialchars($method) ?>"><?= htmlspecialchars($method) ?></option>
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
                        <th>Product</th>
                        <th>Payment</th>
                        <th>Staff</th>
                        <th class="col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody id="txnTableBody">
                    <?php foreach ($transactions as $t): ?>
                    <tr data-id="<?= htmlspecialchars($t['id']) ?>"
                        data-datetime="<?= htmlspecialchars($t['datetime']) ?>"
                        data-customer="<?= htmlspecialchars($t['customer']) ?>"
                        data-product="<?= htmlspecialchars($t['product']) ?>"
                        data-method="<?= htmlspecialchars($t['method']) ?>"
                        data-amount="<?= number_format((float) $t['amount'], 2, '.', '') ?>"
                        data-staff="<?= htmlspecialchars($t['staff']) ?>">

                        <td>
                            <div class="cell-stack">
                                <span class="cell-strong js-date"><?= htmlspecialchars(date('M j, Y', strtotime($t['datetime']))) ?></span>
                                <span class="cell-sub js-time"><?= htmlspecialchars(date('h:i A', strtotime($t['datetime']))) ?></span>
                            </div>
                        </td>

                        <td><span class="cell-strong js-customer"><?= htmlspecialchars($t['customer']) ?></span></td>

                        <td><span class="cell-muted js-product"><?= htmlspecialchars($t['product']) ?></span></td>

                        <td>
                            <div class="cell-stack">
                                <span class="cell-amount js-amount">&#8369;<?= number_format((float) $t['amount'], 2) ?></span>
                                <span class="badge badge-method js-method"><?= htmlspecialchars($t['method']) ?></span>
                            </div>
                        </td>

                        <td><span class="cell-muted js-staff"><?= htmlspecialchars($t['staff']) ?></span></td>

                        <td class="col-actions">
                            <div class="table-actions">
                                <button type="button" class="btn btn-view" data-action="view">View</button>
                                <button type="button" class="btn btn-edit" data-action="edit">Edit</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>


                    <tr id="txnNoResults" class="hidden">
                        <td colspan="6" class="empty-cell">
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
            <p class="pagination-info" id="txnCount"></p>
        </div>
    </div>
</div>

<script>



    (function () {
        const searchInput = document.getElementById('txnSearch');
        const methodFilter = document.getElementById('txnMethodFilter');
        const tbody = document.getElementById('txnTableBody');
        const noResultsRow = document.getElementById('txnNoResults');
        const countLabel = document.getElementById('txnCount');

        if (!searchInput) return;

        const METHODS = <?= json_encode($paymentMethods, JSON_UNESCAPED_SLASHES) ?>;
        const rows = Array.from(tbody.querySelectorAll('tr[data-id]'));



        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function formatDate(value) {
            const parsed = new Date(value);
            if (isNaN(parsed)) return value;
            return parsed.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }

        function formatTime(value) {
            const parsed = new Date(value);
            if (isNaN(parsed)) return '';
            return parsed.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
        }

        function formatPeso(value) {
            return '₱' + Number(value).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function methodOptions(selected) {
            return METHODS.map(function (method) {
                return '<option value="' + escapeHtml(method) + '"' +
                    (method === selected ? ' selected' : '') + '>' + escapeHtml(method) + '</option>';
            }).join('');
        }

        function paintRow(row) {
            const data = row.dataset;

            row.querySelector('.js-date').textContent = formatDate(data.datetime);
            row.querySelector('.js-time').textContent = formatTime(data.datetime);
            row.querySelector('.js-customer').textContent = data.customer;
            row.querySelector('.js-product').textContent = data.product;
            row.querySelector('.js-amount').textContent = formatPeso(data.amount);
            row.querySelector('.js-method').textContent = data.method;
            row.querySelector('.js-staff').textContent = data.staff;
        }

        function applyFilters() {
            const term = searchInput.value.trim().toLowerCase();
            const method = methodFilter.value;
            let visible = 0;

            rows.forEach(function (row) {
                const data = row.dataset;
                const haystack = (data.id + ' ' + data.customer + ' ' + data.product + ' ' + data.staff).toLowerCase();

                const matchesTerm = !term || haystack.includes(term);
                const matchesMethod = method === 'all' || data.method === method;

                if (matchesTerm && matchesMethod) {
                    row.classList.remove('hidden');
                    visible++;
                } else {
                    row.classList.add('hidden');
                }
            });

            noResultsRow.classList.toggle('hidden', visible > 0);
            countLabel.textContent = visible === 0
                ? 'No results'
                : 'Showing ' + visible + ' of ' + rows.length + ' transactions';
        }

        function viewTransaction(row) {
            const data = row.dataset;

            window.openAdminModal({
                title: 'Transaction ' + data.id,
                body: '<dl class="detail-list">' +
                        '<dt>Transaction No.</dt><dd>' + escapeHtml(data.id) + '</dd>' +
                        '<dt>Date &amp; time</dt><dd>' + escapeHtml(formatDate(data.datetime) + ', ' + formatTime(data.datetime)) + '</dd>' +
                        '<dt>Customer name</dt><dd>' + escapeHtml(data.customer) + '</dd>' +
                        '<dt>Product</dt><dd>' + escapeHtml(data.product) + '</dd>' +
                        '<dt>Payment</dt><dd>' + escapeHtml(formatPeso(data.amount)) +
                            ' <span class="badge badge-method">' + escapeHtml(data.method) + '</span></dd>' +
                        '<dt>Staff</dt><dd>' + escapeHtml(data.staff) + '</dd>' +
                      '</dl>'
            });
        }

        function editTransaction(row) {
            const data = row.dataset;

            window.openAdminModal({
                title: 'Edit transaction ' + data.id,
                body: '<form class="form-grid" id="txnEditForm">' +
                        '<div class="form-field form-field-full">' +
                            '<label for="txnEditDatetime">Date &amp; time</label>' +
                            '<input type="datetime-local" id="txnEditDatetime" value="' + escapeHtml(data.datetime) + '" required>' +
                        '</div>' +
                        '<div class="form-field">' +
                            '<label for="txnEditCustomer">Customer name</label>' +
                            '<input type="text" id="txnEditCustomer" value="' + escapeHtml(data.customer) + '" required>' +
                        '</div>' +
                        '<div class="form-field">' +
                            '<label for="txnEditStaff">Staff</label>' +
                            '<input type="text" id="txnEditStaff" value="' + escapeHtml(data.staff) + '" required>' +
                        '</div>' +
                        '<div class="form-field form-field-full">' +
                            '<label for="txnEditProduct">Product</label>' +
                            '<input type="text" id="txnEditProduct" value="' + escapeHtml(data.product) + '" required>' +
                        '</div>' +
                        '<div class="form-field">' +
                            '<label for="txnEditMethod">Payment method</label>' +
                            '<select id="txnEditMethod">' + methodOptions(data.method) + '</select>' +
                        '</div>' +
                        '<div class="form-field">' +
                            '<label for="txnEditAmount">Amount (PHP)</label>' +
                            '<input type="number" id="txnEditAmount" step="0.01" min="0" value="' + escapeHtml(data.amount) + '" required>' +
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

                            row.dataset.datetime = handle.element.querySelector('#txnEditDatetime').value;
                            row.dataset.customer = handle.element.querySelector('#txnEditCustomer').value.trim();
                            row.dataset.staff = handle.element.querySelector('#txnEditStaff').value.trim();
                            row.dataset.product = handle.element.querySelector('#txnEditProduct').value.trim();
                            row.dataset.method = handle.element.querySelector('#txnEditMethod').value;
                            row.dataset.amount = Number(handle.element.querySelector('#txnEditAmount').value).toFixed(2);

                            paintRow(row);
                            applyFilters();

                            return true;
                        }
                    }
                ]
            });
        }

        searchInput.addEventListener('input', applyFilters);
        methodFilter.addEventListener('change', applyFilters);

        tbody.addEventListener('click', function (event) {
            const button = event.target.closest('button[data-action]');
            if (!button) return;

            const row = button.closest('tr[data-id]');
            if (!row) return;

            if (button.dataset.action === 'view') viewTransaction(row);
            if (button.dataset.action === 'edit') editTransaction(row);
        });

        applyFilters();
    })();
</script>

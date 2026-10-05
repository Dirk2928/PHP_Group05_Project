<?php


require_once __DIR__ . '/../../login-signup/session_init.php';

brewski_require_role(['ADMIN']);

$customers = [
    ['id' => 'CUST-001', 'name' => 'Juan Dela Cruz', 'status' => 'Active', 'created_at' => '2023-01-15'],
    ['id' => 'CUST-002', 'name' => 'Maria Santos', 'status' => 'Locked', 'created_at' => '2023-03-22'],
    ['id' => 'CUST-003', 'name' => 'Pedro Penduko', 'status' => 'Pending', 'created_at' => '2024-05-10'],
    ['id' => 'CUST-004', 'name' => 'Ana de los Reyes', 'status' => 'Active', 'created_at' => '2024-08-01'],
    ['id' => 'CUST-005', 'name' => 'Jose Rizal', 'status' => 'Locked', 'created_at' => '2022-12-30'],
];


$pageSize = 5;
?>

<div class="page-container">


    <div class="page-header">
        <h1 class="page-title">Customer Information</h1>
        <p class="subtitle">Manage customer accounts, access levels, and verification status.</p>
    </div>


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
                <option value="Pending">Pending</option>
                <option value="Locked">Locked</option>
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
                        <th>Status</th>
                        <th>Created Date</th>
                        <th class="col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody id="customerTableBody">
                    <?php foreach ($customers as $c): ?>
                    <tr data-id="<?= htmlspecialchars($c['id']) ?>"
                        data-name="<?= htmlspecialchars($c['name']) ?>"
                        data-status="<?= htmlspecialchars($c['status']) ?>"
                        data-created="<?= htmlspecialchars($c['created_at']) ?>">

                        <td class="col-check">
                            <input type="checkbox" class="row-checkbox" value="<?= htmlspecialchars($c['id']) ?>" aria-label="Select <?= htmlspecialchars($c['name']) ?>">
                        </td>

                        <td><span class="cell-id">#<?= htmlspecialchars($c['id']) ?></span></td>

                        <td><span class="cell-strong js-name"><?= htmlspecialchars($c['name']) ?></span></td>

                        <td>
                            <?php
                            $badgeClass = match ($c['status']) {
                                'Active'  => 'badge-active',
                                'Pending' => 'badge-pending',
                                'Locked'  => 'badge-locked',
                                default   => 'badge-inactive',
                            };
                            ?>
                            <span class="js-status"><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($c['status']) ?></span></span>
                        </td>

                        <td><span class="cell-muted js-created"><?= htmlspecialchars($c['created_at']) ?></span></td>

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
                        <td colspan="6" class="empty-cell">
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

        if (!searchInput) return;

        const STATUS_CLASS = {
            Active: 'badge-active',
            Pending: 'badge-pending',
            Locked: 'badge-locked'
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

        function statusBadge(status) {
            return '<span class="badge ' + (STATUS_CLASS[status] || 'badge-inactive') + '">' +
                escapeHtml(status) + '</span>';
        }

        function statusOptions(selected) {
            return ['Active', 'Pending', 'Locked'].map(function (status) {
                return '<option value="' + status + '"' + (status === selected ? ' selected' : '') + '>' +
                    status + '</option>';
            }).join('');
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

        function paintRow(row) {
            row.querySelector('.js-name').textContent = row.dataset.name;
            row.querySelector('.js-status').innerHTML = statusBadge(row.dataset.status);
            row.querySelector('.js-created').textContent = row.dataset.created;
        }

        function viewCustomer(row) {
            const data = row.dataset;

            window.openAdminModal({
                title: 'Customer ' + data.id,
                body: '<dl class="detail-list">' +
                        '<dt>Customer No.</dt><dd>#' + escapeHtml(data.id) + '</dd>' +
                        '<dt>Name</dt><dd>' + escapeHtml(data.name) + '</dd>' +
                        '<dt>Status</dt><dd>' + statusBadge(data.status) + '</dd>' +
                        '<dt>Created Date</dt><dd>' + escapeHtml(data.created) + '</dd>' +
                      '</dl>'
            });
        }

        function editCustomer(row) {
            const data = row.dataset;

            window.openAdminModal({
                title: 'Edit customer ' + data.id,
                body: '<form class="form-grid" id="customerEditForm">' +
                        '<div class="form-field form-field-full">' +
                            '<label for="customerEditName">Name</label>' +
                            '<input type="text" id="customerEditName" value="' + escapeHtml(data.name) + '" required>' +
                        '</div>' +
                        '<div class="form-field">' +
                            '<label for="customerEditStatus">Status</label>' +
                            '<select id="customerEditStatus">' + statusOptions(data.status) + '</select>' +
                        '</div>' +
                        '<div class="form-field">' +
                            '<label for="customerEditCreated">Created date</label>' +
                            '<input type="date" id="customerEditCreated" value="' + escapeHtml(data.created) + '" required>' +
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

                            row.dataset.name = handle.element.querySelector('#customerEditName').value.trim();
                            row.dataset.status = handle.element.querySelector('#customerEditStatus').value;
                            row.dataset.created = handle.element.querySelector('#customerEditCreated').value;

                            paintRow(row);
                            render();

                            return true;
                        }
                    }
                ]
            });
        }

        function deleteCustomer(row) {
            if (!confirm('Delete customer #' + row.dataset.id + '? This action cannot be undone.')) return;

            allRows = allRows.filter(function (candidate) { return candidate !== row; });
            row.remove();
            render();
        }

        function deleteSelected() {
            const checked = Array.from(tbody.querySelectorAll('.row-checkbox:checked'));
            if (checked.length === 0) return;

            if (!confirm('Delete ' + checked.length + ' selected customer(s)? This action cannot be undone.')) return;

            checked.forEach(function (box) {
                const row = box.closest('tr[data-id]');
                allRows = allRows.filter(function (candidate) { return candidate !== row; });
                row.remove();
            });

            render();
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

<?php

require_once __DIR__ . '/../../login-signup/session_init.php';

brewski_require_role(['ADMIN']);

$transactions = [
	['id' => 'TXN-1007', 'datetime' => '2026-09-29T14:32', 'staff' => 'Marco Reyes', 'customer' => 'Ana de los Reyes', 'product' => 'Caramel Macchiato (Large)', 'amount' => 195.00, 'status' => 'Completed'],
	['id' => 'TXN-1006', 'datetime' => '2026-09-29T13:58', 'staff' => 'Liza Bautista', 'customer' => 'Juan Dela Cruz', 'product' => 'Iced Americano (Medium)', 'amount' => 120.00, 'status' => 'Completed'],
	['id' => 'TXN-1005', 'datetime' => '2026-09-29T12:41', 'staff' => 'Marco Reyes', 'customer' => 'Jose Rizal', 'product' => 'Spanish Latte (Large)', 'amount' => 210.00, 'status' => 'Preparing'],
	['id' => 'TXN-1004', 'datetime' => '2026-09-28T18:05', 'staff' => 'Liza Bautista', 'customer' => 'Maria Santos', 'product' => 'Matcha Latte (Medium)', 'amount' => 175.00, 'status' => 'Completed'],
	['id' => 'TXN-1003', 'datetime' => '2026-09-28T16:22', 'staff' => 'Noel Aquino', 'customer' => 'Pedro Penduko', 'product' => 'Cold Brew (Large)', 'amount' => 160.00, 'status' => 'Cancelled'],
];
$statuses = ['Completed', 'Preparing', 'Cancelled'];
?>

<div class="page-container">
	<div class="page-header"><h1 class="page-title">Transactions Handled</h1><p class="subtitle">Review the orders processed by each staff member.</p></div>
	<div class="toolbar"><div class="toolbar-filters"><div class="search-field"><svg class="search-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg><input type="text" id="handledSearch" class="toolbar-input" placeholder="Search by receipt, staff, customer..." aria-label="Search handled transactions"></div><select id="handledStaffFilter" class="toolbar-select" aria-label="Filter by staff"><option value="all">All staff</option><?php foreach (array_unique(array_column($transactions, 'staff')) as $staff): ?><option value="<?= htmlspecialchars($staff) ?>"><?= htmlspecialchars($staff) ?></option><?php endforeach; ?></select><select id="handledStatusFilter" class="toolbar-select" aria-label="Filter by status"><option value="all">All statuses</option><?php foreach ($statuses as $status): ?><option value="<?= htmlspecialchars($status) ?>"><?= htmlspecialchars($status) ?></option><?php endforeach; ?></select></div></div>
	<div class="table-card"><div class="table-scroll"><table class="data-table table-wide"><thead><tr><th>Date / Time</th><th>Staff Name</th><th>Customer Name</th><th>Product</th><th>Amount</th><th>Status</th><th class="col-actions">Actions</th></tr></thead><tbody id="handledTableBody"><?php foreach ($transactions as $transaction): ?><tr data-id="<?= htmlspecialchars($transaction['id']) ?>" data-datetime="<?= htmlspecialchars($transaction['datetime']) ?>" data-staff="<?= htmlspecialchars($transaction['staff']) ?>" data-customer="<?= htmlspecialchars($transaction['customer']) ?>" data-product="<?= htmlspecialchars($transaction['product']) ?>" data-amount="<?= number_format((float) $transaction['amount'], 2, '.', '') ?>" data-status="<?= htmlspecialchars($transaction['status']) ?>"><td><div class="cell-stack"><span class="cell-strong js-handled-date"><?= htmlspecialchars(date('M j, Y', strtotime($transaction['datetime']))) ?></span><span class="cell-sub js-handled-time"><?= htmlspecialchars(date('h:i A', strtotime($transaction['datetime']))) ?></span></div></td><td><span class="cell-strong js-handled-staff"><?= htmlspecialchars($transaction['staff']) ?></span></td><td><span class="cell-muted js-handled-customer"><?= htmlspecialchars($transaction['customer']) ?></span></td><td><span class="cell-muted"><?= htmlspecialchars($transaction['product']) ?></span></td><td><span class="cell-amount">&#8369;<?= number_format((float) $transaction['amount'], 2) ?></span></td><td><span class="badge badge-method js-handled-status"><?= htmlspecialchars($transaction['status']) ?></span></td><td class="col-actions"><div class="table-actions"><button type="button" class="btn btn-view" data-action="view">View</button><button type="button" class="btn btn-edit" data-action="edit">Edit</button></div></td></tr><?php endforeach; ?><tr id="handledNoResults" class="hidden"><td colspan="7" class="empty-cell"><div class="empty-state"><p class="empty-title">No handled transactions found</p><p class="empty-hint">Try adjusting your search or filters.</p></div></td></tr></tbody></table></div><div class="pagination-footer"><p class="pagination-info" id="handledCount"></p></div></div>
</div>

<script>
(function () {
	const search = document.getElementById('handledSearch');
	const staffFilter = document.getElementById('handledStaffFilter');
	const statusFilter = document.getElementById('handledStatusFilter');
	const tbody = document.getElementById('handledTableBody');
	const noResults = document.getElementById('handledNoResults');
	const count = document.getElementById('handledCount');

	if (!search) return;

	const rows = Array.from(tbody.querySelectorAll('tr[data-id]'));

	function escapeHtml(value) {
		return String(value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function formatDate(value) {
		const date = new Date(value);
		return isNaN(date)
			? value
			: date.toLocaleDateString('en-US', {
				month: 'short',
				day: 'numeric',
				year: 'numeric'
			});
	}

	function formatTime(value) {
		const date = new Date(value);
		return isNaN(date)
			? ''
			: date.toLocaleTimeString('en-US', {
				hour: '2-digit',
				minute: '2-digit'
			});
	}

	function formatPeso(value) {
		return '&#8369;' + Number(value).toLocaleString('en-US', {
			minimumFractionDigits: 2,
			maximumFractionDigits: 2
		});
	}

	function applyFilters() {
		const term = search.value.trim().toLowerCase();
		const staff = staffFilter.value;
		const status = statusFilter.value;
		let visible = 0;

		rows.forEach(function (row) {
			const data = row.dataset;
			const haystack = (data.id + ' ' + data.staff + ' ' + data.customer + ' ' + data.product).toLowerCase();
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

	function view(row) {
		const data = row.dataset;

		window.openAdminModal({
			title: 'Transaction ' + data.id,
			body: '<dl class="detail-list">' +
				'<dt>Transaction No.</dt><dd>' + escapeHtml(data.id) + '</dd>' +
				'<dt>Date &amp; time</dt><dd>' + escapeHtml(formatDate(data.datetime) + ', ' + formatTime(data.datetime)) + '</dd>' +
				'<dt>Staff name</dt><dd>' + escapeHtml(data.staff) + '</dd>' +
				'<dt>Customer name</dt><dd>' + escapeHtml(data.customer) + '</dd>' +
				'<dt>Product</dt><dd>' + escapeHtml(data.product) + '</dd>' +
				'<dt>Amount</dt><dd>' + formatPeso(data.amount) + '</dd>' +
				'<dt>Status</dt><dd>' + escapeHtml(data.status) + '</dd>' +
				'</dl>'
		});
	}

	function edit(row) {
		const data = row.dataset;

		window.openAdminModal({
			title: 'Edit handled transaction ' + data.id,
			body: '<form class="form-grid" id="handledEditForm">' +
				'<div class="form-field">' +
					'<label for="handledEditStaff">Staff name</label>' +
					'<input id="handledEditStaff" value="' + escapeHtml(data.staff) + '" required>' +
				'</div>' +
				'<div class="form-field">' +
					'<label for="handledEditStatus">Status</label>' +
					'<select id="handledEditStatus">' +
						'<option>Completed</option>' +
						'<option>Preparing</option>' +
						'<option>Cancelled</option>' +
					'</select>' +
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

						row.dataset.staff = handle.element.querySelector('#handledEditStaff').value.trim();
						row.dataset.status = handle.element.querySelector('#handledEditStatus').value;
						row.querySelector('.js-handled-staff').textContent = row.dataset.staff;
						row.querySelector('.js-handled-status').textContent = row.dataset.status;
						applyFilters();
						return true;
					}
				}
			]
		});

		setTimeout(function () {
			document.getElementById('handledEditStatus').value = data.status;
		}, 0);
	}

	search.addEventListener('input', applyFilters);
	staffFilter.addEventListener('change', applyFilters);
	statusFilter.addEventListener('change', applyFilters);
	tbody.addEventListener('click', function (event) {
		const action = event.target.closest('button[data-action]');
		if (!action) return;

		const row = action.closest('tr[data-id]');
		if (action.dataset.action === 'view') view(row);
		if (action.dataset.action === 'edit') edit(row);
	});
	applyFilters();
})();
</script>

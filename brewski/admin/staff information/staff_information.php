<?php

require_once __DIR__ . '/../../login-signup/session_init.php';

brewski_require_role(['ADMIN']);

$staffMembers = [
	['id' => 'STAFF-001', 'name' => 'Marco Reyes', 'role' => 'Barista', 'status' => 'Active', 'created_at' => '2024-01-15'],
	['id' => 'STAFF-002', 'name' => 'Liza Bautista', 'role' => 'Cashier', 'status' => 'Active', 'created_at' => '2024-02-22'],
	['id' => 'STAFF-003', 'name' => 'Noel Aquino', 'role' => 'Barista', 'status' => 'Pending', 'created_at' => '2024-05-10'],
	['id' => 'STAFF-004', 'name' => 'Bea Garcia', 'role' => 'Supervisor', 'status' => 'Locked', 'created_at' => '2023-08-01'],
];
$pageSize = 5;
?>

<div class="page-container">
	<div class="page-header"><h1 class="page-title">Staff Information</h1><p class="subtitle">Manage staff accounts, roles, and access status.</p></div>
	<div class="toolbar"><div class="toolbar-filters"><div class="search-field"><svg class="search-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg><input type="text" id="staffSearchInput" class="toolbar-input" placeholder="Search by name or ID..." aria-label="Search staff"></div><select id="staffStatusFilter" class="toolbar-select" aria-label="Filter staff by status"><option value="all">All Statuses</option><option value="Active">Active</option><option value="Pending">Pending</option><option value="Locked">Locked</option></select></div><div id="staffBulkActions" class="bulk-actions-bar"><span><span class="bulk-count" id="staffSelectedCount">0</span> selected</span><button type="button" id="deleteStaffBtn" class="btn btn-delete">Delete selected</button></div></div>
	<div class="table-card"><div class="table-scroll"><table class="data-table table-wide"><thead><tr><th class="col-check"><input type="checkbox" id="staffSelectAll" class="row-checkbox" aria-label="Select all staff"></th><th>Staff No.</th><th>Name</th><th>Role</th><th>Status</th><th>Created Date</th><th class="col-actions">Actions</th></tr></thead><tbody id="staffTableBody"><?php foreach ($staffMembers as $staff): ?><tr data-id="<?= htmlspecialchars($staff['id']) ?>" data-name="<?= htmlspecialchars($staff['name']) ?>" data-role="<?= htmlspecialchars($staff['role']) ?>" data-status="<?= htmlspecialchars($staff['status']) ?>" data-created="<?= htmlspecialchars($staff['created_at']) ?>"><td class="col-check"><input type="checkbox" class="row-checkbox" value="<?= htmlspecialchars($staff['id']) ?>" aria-label="Select <?= htmlspecialchars($staff['name']) ?>"></td><td><span class="cell-id">#<?= htmlspecialchars($staff['id']) ?></span></td><td><span class="cell-strong js-staff-name"><?= htmlspecialchars($staff['name']) ?></span></td><td><span class="cell-muted js-staff-role"><?= htmlspecialchars($staff['role']) ?></span></td><td><span class="js-staff-status"><span class="badge badge-<?= strtolower($staff['status']) ?>"><?= htmlspecialchars($staff['status']) ?></span></span></td><td><span class="cell-muted js-staff-created"><?= htmlspecialchars($staff['created_at']) ?></span></td><td class="col-actions"><div class="table-actions"><button type="button" class="btn btn-view" data-action="view">View</button><button type="button" class="btn btn-edit" data-action="edit">Edit</button><button type="button" class="btn btn-delete" data-action="delete">Delete</button></div></td></tr><?php endforeach; ?><tr id="staffNoResults" class="hidden"><td colspan="7" class="empty-cell"><div class="empty-state"><p class="empty-title">No staff found</p><p class="empty-hint">Try adjusting your search or filter criteria.</p></div></td></tr></tbody></table></div><div class="pagination-footer"><p class="pagination-info" id="staffPageInfo"></p><div class="pagination-nav" id="staffPaginationNav"></div></div></div>
</div>

<script>
(function () {
	const pageSize = <?= (int) $pageSize ?>;
	const search = document.getElementById('staffSearchInput');
	const statusFilter = document.getElementById('staffStatusFilter');
	const tbody = document.getElementById('staffTableBody');
	const noResults = document.getElementById('staffNoResults');
	const selectAll = document.getElementById('staffSelectAll');
	const bulkActions = document.getElementById('staffBulkActions');
	const selectedCount = document.getElementById('staffSelectedCount');
	const pageInfo = document.getElementById('staffPageInfo');
	const pagination = document.getElementById('staffPaginationNav');

	if (!search) return;

	const statusClasses = {
		Active: 'badge-active',
		Pending: 'badge-pending',
		Locked: 'badge-locked'
	};
	let rows = Array.from(tbody.querySelectorAll('tr[data-id]'));
	let filtered = rows.slice();
	let currentPage = 1;

	function escapeHtml(value) {
		return String(value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function badge(status) {
		return '<span class="badge ' + (statusClasses[status] || 'badge-inactive') + '">' +
			escapeHtml(status) +
			'</span>';
	}

	function pageRows() {
		return filtered.slice((currentPage - 1) * pageSize, currentPage * pageSize);
	}

	function pageButton(label, disabled, active, handler) {
		const item = document.createElement('button');
		item.type = 'button';
		item.className = 'page-link' + (active ? ' active' : '');
		item.textContent = label;
		item.disabled = disabled;
		item.addEventListener('click', handler);
		return item;
	}

	function renderPagination(count) {
		pagination.innerHTML = '';
		pagination.appendChild(pageButton('Previous', currentPage === 1, false, function () {
			currentPage--;
			render();
		}));

		for (
			let page = 1;
			page <= count;
			page++
		) {
			pagination.appendChild(pageButton(String(page), false, page === currentPage, function () {
				currentPage = page;
				render();
			}));
		}

		pagination.appendChild(pageButton('Next', currentPage === count, false, function () {
			currentPage++;
			render();
		}));
	}

	function syncSelection() {
		const visible = pageRows();
		const checked = visible.filter(function (row) {
			return row.querySelector('.row-checkbox').checked;
		}).length;

		selectAll.checked = visible.length > 0 && checked === visible.length;
		selectAll.indeterminate = checked > 0 && checked < visible.length;

		const total = tbody.querySelectorAll('.row-checkbox:checked').length;
		selectedCount.textContent = total;
		bulkActions.classList.toggle('visible', total > 0);
	}

	function render() {
		const term = search.value.trim().toLowerCase();
		const status = statusFilter.value;

		filtered = rows.filter(function (row) {
			return (!term || (row.dataset.name + ' ' + row.dataset.id).toLowerCase().includes(term)) &&
				(status === 'all' || row.dataset.status === status);
		});

		const count = Math.max(1, Math.ceil(filtered.length / pageSize));
		if (currentPage > count) currentPage = count;

		rows.forEach(function (row) {
			row.classList.add('hidden');
		});
		pageRows().forEach(function (row) {
			row.classList.remove('hidden');
		});

		noResults.classList.toggle('hidden', filtered.length > 0);

		const start = (currentPage - 1) * pageSize;
		pageInfo.textContent = filtered.length
			? 'Showing ' + (start + 1) + ' to ' + Math.min(start + pageSize, filtered.length) + ' of ' + filtered.length + ' results'
			: 'No results';

		renderPagination(count);
		syncSelection();
	}

	function removeRows(selected) {
		selected.forEach(function (box) {
			const row = box.closest('tr[data-id]');
			rows = rows.filter(function (candidate) {
				return candidate !== row;
			});
			row.remove();
		});
		render();
	}

	function openStaffModal(row, editing) {
		const data = row.dataset;

		if (!editing) {
			window.openAdminModal({
				title: 'Staff ' + data.id,
				body: '<dl class="detail-list">' +
					'<dt>Staff No.</dt><dd>#' + escapeHtml(data.id) + '</dd>' +
					'<dt>Name</dt><dd>' + escapeHtml(data.name) + '</dd>' +
					'<dt>Role</dt><dd>' + escapeHtml(data.role) + '</dd>' +
					'<dt>Status</dt><dd>' + badge(data.status) + '</dd>' +
					'<dt>Created Date</dt><dd>' + escapeHtml(data.created) + '</dd>' +
					'</dl>'
			});
			return;
		}

		window.openAdminModal({
			title: 'Edit staff ' + data.id,
			body: '<form class="form-grid" id="staffEditForm">' +
				'<div class="form-field form-field-full">' +
					'<label for="staffEditName">Name</label>' +
					'<input id="staffEditName" value="' + escapeHtml(data.name) + '" required>' +
				'</div>' +
				'<div class="form-field">' +
					'<label for="staffEditRole">Role</label>' +
					'<input id="staffEditRole" value="' + escapeHtml(data.role) + '" required>' +
				'</div>' +
				'<div class="form-field">' +
					'<label for="staffEditStatus">Status</label>' +
					'<select id="staffEditStatus">' +
						'<option>Active</option>' +
						'<option>Pending</option>' +
						'<option>Locked</option>' +
					'</select>' +
				'</div>' +
				'</form>',
			footer: [
				{ label: 'Cancel', className: 'btn-secondary' },
				{
					label: 'Save changes',
					className: 'btn-primary',
					onClick: function (handle) {
						const form = handle.element.querySelector('#staffEditForm');
						if (!form.reportValidity()) return false;

						row.dataset.name = handle.element.querySelector('#staffEditName').value.trim();
						row.dataset.role = handle.element.querySelector('#staffEditRole').value.trim();
						row.dataset.status = handle.element.querySelector('#staffEditStatus').value;
						row.querySelector('.js-staff-name').textContent = row.dataset.name;
						row.querySelector('.js-staff-role').textContent = row.dataset.role;
						row.querySelector('.js-staff-status').innerHTML = badge(row.dataset.status);
						render();
						return true;
					}
				}
			]
		});

		setTimeout(function () {
			document.getElementById('staffEditStatus').value = data.status;
		}, 0);
	}

	search.addEventListener('input', function () {
		currentPage = 1;
		render();
	});
	statusFilter.addEventListener('change', function () {
		currentPage = 1;
		render();
	});
	selectAll.addEventListener('change', function () {
		pageRows().forEach(function (row) {
			row.querySelector('.row-checkbox').checked = selectAll.checked;
		});
		syncSelection();
	});
	document.getElementById('deleteStaffBtn').addEventListener('click', function () {
		const checked = Array.from(tbody.querySelectorAll('.row-checkbox:checked'));
		if (checked.length && confirm('Delete ' + checked.length + ' selected staff member(s)? This action cannot be undone.')) {
			removeRows(checked);
		}
	});
	tbody.addEventListener('change', function (event) {
		if (event.target.classList.contains('row-checkbox')) syncSelection();
	});
	tbody.addEventListener('click', function (event) {
		const action = event.target.closest('button[data-action]');
		if (!action) return;

		const row = action.closest('tr[data-id]');
		if (action.dataset.action === 'view') openStaffModal(row, false);
		if (action.dataset.action === 'edit') openStaffModal(row, true);
		if (action.dataset.action === 'delete' && confirm('Delete staff #' + row.dataset.id + '? This action cannot be undone.')) {
			removeRows([row.querySelector('.row-checkbox')]);
		}
	});
	render();
})();
</script>

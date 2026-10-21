<?php

require_once __DIR__ . '/../../login-signup/session_init.php';
require_once __DIR__ . '/../../Db/connection.php';

brewski_require_role(['STAFF', 'ADMIN']);

$statuses = ['PENDING', 'CONFIRMED', 'PREPARING', 'READY', 'COMPLETED', 'CANCELLED'];
$transactions = [];
$loadError = '';

try {
	$statement = $pdo->query(
		"SELECT o.order_id,
		        o.order_date,
		        o.total_amount,
		        o.order_status,
		        op.payment_status,
		        TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS customer,
		        oi.order_item_id,
		        oi.quantity,
		        COALESCE(p.product_name, 'Unknown item') AS product_name,
		        COALESCE(s.size_name, 'Standard') AS size_name
		   FROM orders o
		   LEFT JOIN order_payments op ON op.order_id = o.order_id
		   LEFT JOIN users u ON u.user_id = o.user_id
		   LEFT JOIN order_items oi ON oi.order_id = o.order_id
		   LEFT JOIN products p ON p.product_id = oi.product_id
		   LEFT JOIN sizes s ON s.size_id = oi.size_id
		  WHERE op.payment_status IS NULL OR op.payment_status = 'PAID'
		  ORDER BY o.order_date DESC, o.order_id DESC, oi.order_item_id ASC"
	);

	foreach ($statement as $row) {
		$orderId = (int) $row['order_id'];

		if (!isset($transactions[$orderId])) {
			$transactions[$orderId] = [
				'id' => 'ORD-' . $orderId,
				'datetime' => date('c', strtotime((string) $row['order_date'])),
				'customer' => trim((string) $row['customer']) ?: 'Guest',
				'items' => [],
				'amount' => (float) $row['total_amount'],
				'status' => strtoupper((string) $row['order_status']),
			];
		}

		if ($row['order_item_id'] !== null) {
			$transactions[$orderId]['items'][] =
				(string) $row['product_name'] . ' (' . (string) $row['size_name'] . ') x ' . (int) $row['quantity'];
		}
	}
} catch (PDOException $exception) {
	error_log('staff order history query failed: ' . $exception->getMessage());
	$loadError = 'Order history could not be loaded right now. Please try again later.';
}

foreach ($transactions as &$transaction) {
	$transaction['product'] = $transaction['items']
		? implode(', ', $transaction['items'])
		: 'No items';
	unset($transaction['items']);
}
unset($transaction);
?>

<div class="page-container">
	<div class="page-header"><h1 class="page-title">Order History</h1><p class="subtitle">Review the orders you have processed.</p></div>
	<?php if ($loadError !== ''): ?><p class="empty-hint" role="alert"><?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
	<div class="toolbar"><div class="toolbar-filters"><div class="search-field"><svg class="search-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg><input type="text" id="historySearch" class="toolbar-input" placeholder="Search by receipt, customer, product..." aria-label="Search order history"></div><select id="historyStatusFilter" class="toolbar-select" aria-label="Filter by status"><option value="all">All statuses</option><?php foreach ($statuses as $status): ?><option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ucwords(strtolower(str_replace('_', ' ', $status))), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div></div>
	<div class="table-card"><div class="table-scroll"><table class="data-table table-wide"><thead><tr><th>Date / Time</th><th>Transaction No.</th><th>Customer Name</th><th>Product</th><th>Amount</th><th>Status</th><th class="col-actions">Actions</th></tr></thead><tbody id="historyTableBody"><?php foreach ($transactions as $transaction): ?><tr data-id="<?= htmlspecialchars($transaction['id']) ?>" data-datetime="<?= htmlspecialchars($transaction['datetime']) ?>" data-customer="<?= htmlspecialchars($transaction['customer']) ?>" data-product="<?= htmlspecialchars($transaction['product']) ?>" data-amount="<?= number_format((float) $transaction['amount'], 2, '.', '') ?>" data-status="<?= htmlspecialchars($transaction['status']) ?>"><td><div class="cell-stack"><span class="cell-strong"><?= htmlspecialchars(date('M j, Y', strtotime($transaction['datetime']))) ?></span><span class="cell-sub"><?= htmlspecialchars(date('h:i A', strtotime($transaction['datetime']))) ?></span></div></td><td><span class="cell-strong"><?= htmlspecialchars($transaction['id']) ?></span></td><td><span class="cell-muted"><?= htmlspecialchars($transaction['customer']) ?></span></td><td><span class="cell-muted"><?= htmlspecialchars($transaction['product']) ?></span></td><td><span class="cell-amount">&#8369;<?= number_format((float) $transaction['amount'], 2) ?></span></td><td><span class="badge badge-method"><?= htmlspecialchars($transaction['status']) ?></span></td><td class="col-actions"><div class="table-actions"><button type="button" class="btn btn-view" data-action="view">View</button></div></td></tr><?php endforeach; ?><tr id="historyNoResults" class="hidden"><td colspan="7" class="empty-cell"><div class="empty-state"><p class="empty-title">No orders found</p><p class="empty-hint">Try adjusting your search or filters.</p></div></td></tr></tbody></table></div><div class="pagination-footer"><p class="pagination-info" id="historyCount"></p><div class="pagination-nav" id="historyPagination"></div></div></div>
</div>

<script>
(function () {
	const search = document.getElementById('historySearch');
	const statusFilter = document.getElementById('historyStatusFilter');
	const tbody = document.getElementById('historyTableBody');
	const noResults = document.getElementById('historyNoResults');
	const count = document.getElementById('historyCount');
	const pagination = document.getElementById('historyPagination');
	const pageSize = 5;

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

	let currentPage = 1;

	function pageButton(label, disabled, active, handler) {
		const item = document.createElement('button');
		item.type = 'button';
		item.className = 'page-link' + (active ? ' active' : '');
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
		const term = search.value.trim().toLowerCase();
		const status = statusFilter.value;

		const filtered = rows.filter(function (row) {
			const data = row.dataset;
			const haystack = (data.id + ' ' + data.customer + ' ' + data.product).toLowerCase();

			return (!term || haystack.includes(term)) &&
				(status === 'all' || data.status === status);
		});

		const pageCount = Math.max(1, Math.ceil(filtered.length / pageSize));
		if (currentPage > pageCount) currentPage = pageCount;

		const start = (currentPage - 1) * pageSize;
		const pageRows = filtered.slice(start, start + pageSize);

		rows.forEach(function (row) { row.classList.add('hidden'); });
		pageRows.forEach(function (row) { row.classList.remove('hidden'); });

		noResults.classList.toggle('hidden', filtered.length > 0);
		count.textContent = filtered.length
			? 'Showing ' + (start + 1) + ' to ' + Math.min(start + pageSize, filtered.length) +
				' of ' + filtered.length + ' orders'
			: 'No results';

		renderPagination(pageCount);
	}

	function view(row) {
		const data = row.dataset;

		window.openAdminModal({
			title: 'Transaction ' + data.id,
			body: '<dl class="detail-list">' +
				'<dt>Transaction No.</dt><dd>' + escapeHtml(data.id) + '</dd>' +
				'<dt>Date &amp; time</dt><dd>' + escapeHtml(formatDate(data.datetime) + ', ' + formatTime(data.datetime)) + '</dd>' +
				'<dt>Customer name</dt><dd>' + escapeHtml(data.customer) + '</dd>' +
				'<dt>Products</dt><dd>' + escapeHtml(data.product) + '</dd>' +
				'<dt>Amount</dt><dd>' + formatPeso(data.amount) + '</dd>' +
				'<dt>Status</dt><dd>' + escapeHtml(data.status.replace(/_/g, ' ')) + '</dd>' +
				'</dl>'
		});
	}

	search.addEventListener('input', function () {
		currentPage = 1;
		render();
	});
	statusFilter.addEventListener('change', function () {
		currentPage = 1;
		render();
	});
	tbody.addEventListener('click', function (event) {
		const action = event.target.closest('button[data-action]');
		if (!action) return;

		const row = action.closest('tr[data-id]');
		if (action.dataset.action === 'view') view(row);
	});
	render();
})();
</script><?php echo brewski_session_timeout_markup(); ?>

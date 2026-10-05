<?php



require_once __DIR__ . '/../../login-signup/session_init.php';

brewski_require_role(['ADMIN']);

$logs = [
    ['id' => 'LOG-2021', 'logged_at' => '2026-09-29 14:05', 'name' => 'Marco Reyes',     'role' => 'STAFF',    'activity' => 'Logged in'],
    ['id' => 'LOG-2020', 'logged_at' => '2026-09-29 13:41', 'name' => 'Ana de los Reyes', 'role' => 'CUSTOMER', 'activity' => 'Logged out'],
    ['id' => 'LOG-2019', 'logged_at' => '2026-09-29 12:58', 'name' => 'Admin User',       'role' => 'ADMIN',    'activity' => 'Logged in'],
    ['id' => 'LOG-2018', 'logged_at' => '2026-09-29 11:22', 'name' => 'Liza Bautista',    'role' => 'STAFF',    'activity' => 'Logged out'],
    ['id' => 'LOG-2017', 'logged_at' => '2026-09-29 10:47', 'name' => 'Juan Dela Cruz',   'role' => 'CUSTOMER', 'activity' => 'Logged in'],
    ['id' => 'LOG-2016', 'logged_at' => '2026-09-29 09:30', 'name' => 'Noel Aquino',      'role' => 'STAFF',    'activity' => 'Logged out'],
    ['id' => 'LOG-2015', 'logged_at' => '2026-09-29 08:52', 'name' => 'Maria Santos',     'role' => 'CUSTOMER', 'activity' => 'Logged in'],
    ['id' => 'LOG-2014', 'logged_at' => '2026-09-28 19:16', 'name' => 'Admin User',       'role' => 'ADMIN',    'activity' => 'Logged out'],
];

$roles = ['ADMIN', 'STAFF', 'CUSTOMER'];
?>

<div class="page-container">


    <div class="page-header">
        <h1 class="page-title">Authentication Logs</h1>
        <p class="subtitle">Sign-in and sign-out history, most recent first, with the role each account holds.</p>
    </div>


    <div class="toolbar">
        <div class="toolbar-filters">
            <div class="search-field">
                <svg class="search-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
                <input type="text" id="logSearch" class="toolbar-input" placeholder="Search by name..." aria-label="Search logs by name">
            </div>

            <select id="logRoleFilter" class="toolbar-select" aria-label="Filter by role">
                <option value="all">All roles</option>
                <?php foreach ($roles as $role): ?>
                    <option value="<?= htmlspecialchars($role) ?>"><?= htmlspecialchars(ucfirst(strtolower($role))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>


    <div class="table-card">
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Name</th>
                        <th>Role</th>
                        <th>Activity</th>
                    </tr>
                </thead>
                <tbody id="logTableBody">
                    <?php foreach ($logs as $log): ?>
                    <?php
                    $roleClass = match ($log['role']) {
                        'ADMIN' => 'badge-role-admin',
                        'STAFF' => 'badge-role-staff',
                        default => 'badge-role-customer',
                    };
                    ?>
                    <tr data-id="<?= htmlspecialchars($log['id']) ?>"
                        data-name="<?= htmlspecialchars($log['name']) ?>"
                        data-role="<?= htmlspecialchars($log['role']) ?>">

                        <td><span class="cell-muted"><?= htmlspecialchars(date('M j, Y', strtotime($log['logged_at']))) ?></span></td>

                        <td><span class="cell-muted"><?= htmlspecialchars(date('h:i A', strtotime($log['logged_at']))) ?></span></td>

                        <td><span class="cell-strong"><?= htmlspecialchars($log['name']) ?></span></td>

                        <td>
                            <span class="badge <?= $roleClass ?>"><?= htmlspecialchars(ucfirst(strtolower($log['role']))) ?></span>
                        </td>

                        <td><span class="cell-muted"><?= htmlspecialchars($log['activity']) ?></span></td>
                    </tr>
                    <?php endforeach; ?>


                    <tr id="logNoResults" class="hidden">
                        <td colspan="5" class="empty-cell">
                            <div class="empty-state">
                                <p class="empty-title">No log entries found</p>
                                <p class="empty-hint">Try adjusting your search or filter criteria.</p>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>


        <div class="pagination-footer">
            <p class="pagination-info" id="logCount"></p>
        </div>
    </div>
</div>

<script>



    (function () {
        const searchInput = document.getElementById('logSearch');
        const roleFilter = document.getElementById('logRoleFilter');
        const tbody = document.getElementById('logTableBody');
        const noResultsRow = document.getElementById('logNoResults');
        const countLabel = document.getElementById('logCount');

        if (!searchInput) return;

        const rows = Array.from(tbody.querySelectorAll('tr[data-id]'));

        function applyFilters() {
            const term = searchInput.value.trim().toLowerCase();
            const role = roleFilter.value;
            let visible = 0;

            rows.forEach(function (row) {
                const matchesTerm = !term || row.dataset.name.toLowerCase().includes(term);
                const matchesRole = role === 'all' || row.dataset.role === role;

                if (matchesTerm && matchesRole) {
                    row.classList.remove('hidden');
                    visible++;
                } else {
                    row.classList.add('hidden');
                }
            });

            noResultsRow.classList.toggle('hidden', visible > 0);
            countLabel.textContent = visible === 0
                ? 'No results'
                : 'Showing ' + visible + ' of ' + rows.length + ' log entries';
        }

        searchInput.addEventListener('input', applyFilters);
        roleFilter.addEventListener('change', applyFilters);

        applyFilters();
    })();
</script>

<?php

require_once __DIR__ . '/../../login-signup/session_init.php';
require_once __DIR__ . '/../../Db/connection.php';

// Only needed if connection.php does not already load the file that defines
// brewski_personal_decrypt(). Replace YOUR_PERSONAL_DATA_FILE.php with its real name.
if (!function_exists('brewski_personal_decrypt')) {
    require_once __DIR__ . '/../../Db/YOUR_PERSONAL_DATA_FILE.php';
}

brewski_require_role(['ADMIN']);

$logs = [];
$loadError = '';
$databaseName = '';

try {


    $pdo->exec("SET time_zone = '+00:00'");
    $databaseName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $statement = $pdo->query(
        "SELECT activity_id, actor_name, actor_role, category, action, description,
                target_type, target_id, created_at
           FROM system_activity_logs
          WHERE actor_role IN ('ADMIN', 'STAFF', 'CUSTOMER')
          ORDER BY created_at DESC, activity_id DESC"
    );
    $logs = $statement->fetchAll();
} catch (Throwable $error) {
    error_log('Could not load system activity: ' . $error->getMessage());
    if ((string) $error->getCode() === '42S02') {
        $databaseLabel = $databaseName !== '' ? ' The application database is "' . $databaseName . '".' : '';
        $loadError = 'The system_activity_logs table is missing from the application database.'
            . $databaseLabel . ' Run the system activity migration on the Aiven database used by this app.';
    } else {
        $loadError = 'System activity query failed (' . (string) $error->getCode() . '): '
            . $error->getMessage();
    }
}

$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$categoryLabels = [
    'authentication' => 'Authentication',
    'orders' => 'Orders',
    'inventory' => 'Inventory',
    'team' => 'Team management',
    'profile' => 'Profile',
    'security' => 'Security',
    'account' => 'Account',
    'preferences' => 'Preferences',
    'system' => 'System',
];

/*
 * Decrypt personal data stored as "v1:..." tokens.
 * The field name is part of the AES-GCM authentication, so each token is
 * tried against the possible field names until one succeeds.
 */
$decryptToken = static function (string $token): string {
    static $cache = [];
    if (isset($cache[$token])) {
        return $cache[$token];
    }
    if (function_exists('brewski_personal_decrypt')) {
        foreach (['first_name', 'last_name', 'email', 'avatar_data'] as $field) {
            try {
                $plain = brewski_personal_decrypt($token, $field);
                if (is_string($plain) && $plain !== '') {
                    return $cache[$token] = $plain;
                }
            } catch (Throwable $decryptError) {
                // Wrong field name for this token; try the next one.
            }
        }
    }
    return $cache[$token] = $token; // Could not decrypt: show the raw value.
};

// Replaces every "v1:..." token found inside a string with its decrypted value.
$decryptText = static function ($value) use ($decryptToken): string {
    return (string) preg_replace_callback(
        '/v1:[A-Za-z0-9+\/]+=*/',
        static fn (array $match): string => $decryptToken($match[0]),
        (string) $value
    );
};

// Collect user IDs referenced as "User #27" (Person) or "account #50" (Details).
$userIds = [];
foreach ($logs as $logRow) {
    if (preg_match('/^User #(\d+)$/i', trim((string) $logRow['actor_name']), $idMatch)) {
        $userIds[(int) $idMatch[1]] = true;
    }
    if (preg_match_all('/\baccount #(\d+)/i', (string) $logRow['description'], $idMatches)) {
        foreach ($idMatches[1] as $accountId) {
            $userIds[(int) $accountId] = true;
        }
    }
}

// Look those users up once and decrypt their real names.
$userNames = [];
if ($userIds) {
    try {
        $ids = array_keys($userIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $lookup = $pdo->prepare(
            "SELECT user_id, first_name, last_name FROM users WHERE user_id IN ($placeholders)"
        );
        $lookup->execute($ids);
        foreach ($lookup->fetchAll() as $userRow) {
            try {
                $userRow = brewski_decrypt_user_fields($userRow, true);
            } catch (Throwable $nameError) {
                continue; // Could not decrypt this user; keep the "User #" label.
            }
            $fullName = trim((string) $userRow['first_name'] . ' ' . (string) $userRow['last_name']);
            if ($fullName !== '') {
                $userNames[(int) $userRow['user_id']] = $fullName;
            }
        }
    } catch (Throwable $lookupError) {
        error_log('Could not look up system activity users: ' . $lookupError->getMessage());
    }
}

foreach ($logs as &$logRow) {
    $actor = $decryptText($logRow['actor_name']);
    if (preg_match('/^User #(\d+)$/i', trim($actor), $actorMatch)
        && isset($userNames[(int) $actorMatch[1]])) {
        $actor = $userNames[(int) $actorMatch[1]];
    }
    $logRow['actor_name'] = $actor;
    $logRow['action']     = $decryptText($logRow['action']);

    $description = $decryptText($logRow['description']);
    $description = (string) preg_replace_callback(
        '/\baccount #(\d+)/i',
        static function (array $match) use ($userNames): string {
            $id = (int) $match[1];
            return isset($userNames[$id])
                ? 'account ' . $userNames[$id] . ' (#' . $id . ')'
                : $match[0];
        },
        $description
    );
    $logRow['description'] = $description;
}
unset($logRow);
?>

<div class="page-container system-activity-page">
    <div class="page-header">
        <h1 class="page-title">System Activity</h1>
        <p class="subtitle">A shared audit trail of customer, staff, and administrator activity.</p>
    </div>

    <?php if ($loadError !== ''): ?>
        <div class="activity-migration-notice" role="status"><?= $escape($loadError) ?></div>
    <?php endif; ?>

    <div class="toolbar system-activity-filters" aria-label="Filter system activity">
        <div class="toolbar-filters">
            <div class="search-field">
                <svg class="search-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
                <input type="search" id="activitySearch" class="toolbar-input" placeholder="Search people or activity..." aria-label="Search people or activity">
            </div>
            <select id="activityRole" class="toolbar-select" aria-label="Filter by role">
                <option value="all">All roles</option>
                <option value="ADMIN">Admins</option>
                <option value="STAFF">Staff</option>
                <option value="CUSTOMER">Customers</option>
            </select>
            <select id="activityCategory" class="toolbar-select" aria-label="Filter by activity type">
                <option value="all">All activity</option>
                <?php foreach ($categoryLabels as $category => $label): ?>
                    <option value="<?= $escape($category) ?>"><?= $escape($label) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="activity-date-filter">
                <span>From</span>
                <input type="date" id="activityFrom" class="toolbar-input" aria-label="Start date">
            </label>
            <label class="activity-date-filter">
                <span>To</span>
                <input type="date" id="activityTo" class="toolbar-input" aria-label="End date">
            </label>
            <button type="button" id="activityRefresh" class="btn btn-secondary">Refresh</button>
            <button type="button" id="activityReset" class="btn btn-secondary">Reset</button>
        </div>
    </div>

    <div class="table-card">
        <div class="table-scroll">
            <table class="data-table system-activity-table">
                <thead>
                    <tr>
                        <th><span class="table-heading-label"><i data-lucide="calendar-clock" aria-hidden="true"></i>Date / time</span></th>
                        <th><span class="table-heading-label"><i data-lucide="user-round" aria-hidden="true"></i>Person</span></th>
                        <th><span class="table-heading-label"><i data-lucide="shield" aria-hidden="true"></i>Role</span></th>
                        <th><span class="table-heading-label"><i data-lucide="activity" aria-hidden="true"></i>Activity</span></th>
                        <th><span class="table-heading-label"><i data-lucide="file-text" aria-hidden="true"></i>Details</span></th>
                    </tr>
                </thead>
                <tbody id="activityTableBody">
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $role = strtoupper((string) $log['actor_role']);


                        if ($role === 'ADMIN') {
                            $roleClass = 'badge-role-admin';
                            $roleLabel = 'Admin';
                        } elseif ($role === 'STAFF') {
                            $roleClass = 'badge-role-staff';
                            $roleLabel = 'Staff';
                        } else {
                            $roleClass = 'badge-role-customer';
                            $roleLabel = 'Customer';
                        }

                        $createdAt = null;
                        try {
                            $createdAt = (new DateTimeImmutable(
                                (string) $log['created_at'],
                                new DateTimeZone('UTC')
                            ))->setTimezone(new DateTimeZone('Asia/Manila'));
                        } catch (Throwable $error) {
                            error_log('Could not format system activity timestamp: ' . $error->getMessage());
                        }
                        $category = strtolower((string) $log['category']);
                        ?>
                        <tr data-id="<?= (int) $log['activity_id'] ?>"
                            data-name="<?= $escape($log['actor_name']) ?>"
                            data-role="<?= $escape($role) ?>"
                            data-category="<?= $escape($category) ?>"
                            data-search="<?= $escape($log['action'] . ' ' . $log['description'] . ' ' . ($log['target_type'] ?? '') . ' ' . ($log['target_id'] ?? '')) ?>"
                            data-date="<?= $escape($createdAt ? $createdAt->format('Y-m-d') : '') ?>">
                            <td><span class="cell-muted"><?= $escape($createdAt ? $createdAt->format('M j, Y · h:i A') : $log['created_at']) ?></span></td>
                            <td><span class="cell-strong"><?= $escape($log['actor_name']) ?></span></td>
                            <td><span class="badge <?= $roleClass ?>"><?= $roleLabel ?></span></td>
                            <td>
                                <span class="activity-action"><?= $escape($log['action']) ?></span>
                                <span class="activity-category"><?= $escape($categoryLabels[$category] ?? ucfirst($category)) ?></span>
                            </td>
                            <td><span class="cell-muted"><?= $escape($log['description']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr id="activityNoResults" class="<?= $logs ? 'hidden' : '' ?>">
                        <td colspan="5" class="empty-cell">
                            <div class="empty-state">
                                <p class="empty-title"><?= $logs ? 'No activity found' : 'No activity recorded yet' ?></p>
                                <p class="empty-hint"><?= $logs ? 'Try changing or clearing the filters.' : ($loadError !== '' ? 'Resolve the database message above, then refresh this page.' : 'Activity will appear here as it happens.') ?></p>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="pagination-footer">
            <p class="pagination-info" id="activityCount"></p>
            <label class="activity-page-size">
                Rows per page
                <select id="activityPageSize" aria-label="Rows per page">
                    <option value="10" selected>10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </select>
            </label>
            <div class="pagination-nav" id="activityPagination"></div>
        </div>
    </div>
</div>

<script>
function initializeSystemActivity() {
    const body = document.getElementById('activityTableBody');
    if (!body) return;

    const search = document.getElementById('activitySearch');
    const role = document.getElementById('activityRole');
    const category = document.getElementById('activityCategory');
    const from = document.getElementById('activityFrom');
    const to = document.getElementById('activityTo');
    const reset = document.getElementById('activityReset');
    const refresh = document.getElementById('activityRefresh');
    const count = document.getElementById('activityCount');
    const pagination = document.getElementById('activityPagination');
    const pageSizeSelect = document.getElementById('activityPageSize');
    const noResults = document.getElementById('activityNoResults');
    const rows = Array.from(body.querySelectorAll('tr[data-id]'));
    let pageSize = Number(pageSizeSelect.value);
    let currentPage = 1;

    function addPageButton(label, disabled, active, handler) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'page-link' + (label === 'Previous' || label === 'Next' ? ' pagination-control' : '') + (active ? ' active' : '');
        button.textContent = label;
        button.disabled = disabled;
        button.addEventListener('click', handler);
        pagination.appendChild(button);
    }

    function addEllipsis() {
        const ellipsis = document.createElement('span');
        ellipsis.className = 'pagination-ellipsis';
        ellipsis.setAttribute('aria-hidden', 'true');
        ellipsis.textContent = '…';
        pagination.appendChild(ellipsis);
    }

    function render() {
        const term = search.value.trim().toLowerCase();
        const filtered = rows.filter(function (row) {
            const rowDate = row.dataset.date;
            const haystack = (row.dataset.name + ' ' + row.dataset.search).toLowerCase();
            return (!term || haystack.includes(term))
                && (role.value === 'all' || row.dataset.role === role.value)
                && (category.value === 'all' || row.dataset.category === category.value)
                && (!from.value || rowDate >= from.value)
                && (!to.value || rowDate <= to.value);
        });

        const pageCount = Math.max(1, Math.ceil(filtered.length / pageSize));
        currentPage = Math.min(currentPage, pageCount);
        const start = (currentPage - 1) * pageSize;
        rows.forEach(function (row) { row.classList.add('hidden'); });
        filtered.slice(start, start + pageSize).forEach(function (row) { row.classList.remove('hidden'); });
        noResults.classList.toggle('hidden', filtered.length > 0);
        count.textContent = filtered.length
            ? 'Showing ' + (start + 1) + ' to ' + Math.min(start + pageSize, filtered.length) + ' of ' + filtered.length + ' activities'
            : 'No results';

        pagination.innerHTML = '';
        addPageButton('Previous', currentPage === 1 || filtered.length === 0, false, function () { currentPage--; render(); });
        const visiblePages = pageCount <= 6
            ? Array.from({ length: pageCount }, function (_, index) { return index + 1; })
            : [1, 2, 3, null, pageCount - 2, pageCount - 1, pageCount];
        visiblePages.forEach(function (page) {
            if (page === null) {
                addEllipsis();
                return;
            }
            (function (target) {
                addPageButton(String(target), false, target === currentPage, function () { currentPage = target; render(); });
            })(page);
        });
        addPageButton('Next', currentPage === pageCount || filtered.length === 0, false, function () { currentPage++; render(); });
    }

    [search, role, category, from, to].forEach(function (control) {
        control.addEventListener(control === search ? 'input' : 'change', function () {
            currentPage = 1;
            render();
        });
    });
    pageSizeSelect.addEventListener('change', function () {
        pageSize = Number(pageSizeSelect.value);
        currentPage = 1;
        render();
    });
    reset.addEventListener('click', function () {
        search.value = '';
        role.value = 'all';
        category.value = 'all';
        from.value = '';
        to.value = '';
        currentPage = 1;
        render();
    });
    refresh.addEventListener('click', function () {
        if (typeof window.brewskiReloadView === 'function') {
            window.brewskiReloadView();
        } else {
            window.location.reload();
        }
    });
    render();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeSystemActivity, { once: true });
} else {
    initializeSystemActivity();
}
</script>
<?php echo brewski_session_timeout_markup(); ?>
<?php

require_once __DIR__ . '/../../login-signup/session_init.php';
require_once __DIR__ . '/../../Db/connection.php';
require_once __DIR__ . '/../../Db/staff_columns.php';

brewski_require_role(['ADMIN']);

brewski_ensure_staff_columns($pdo);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$endpoint = htmlspecialchars(
    str_replace(' ', '%20', $_SERVER['SCRIPT_NAME'] ?? ''),
    ENT_QUOTES,
    'UTF-8'
);

$pageSize = 5;

function admin_staff_respond(bool $ok, string $message, int $status = 200): void
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

function admin_staff_identity_errors(array $input, PDO $pdo, int $ignoreUserId = 0): array
{
    $errors = [];

    $firstName = trim((string) ($input['first_name'] ?? ''));
    $lastName  = trim((string) ($input['last_name'] ?? ''));
    $email     = trim((string) ($input['email'] ?? ''));
    $jobTitle  = trim((string) ($input['job_title'] ?? ''));

    if ($firstName === '') {
        $errors[] = 'Please enter the staff member\'s first name.';
    } elseif (mb_strlen($firstName) > 100) {
        $errors[] = 'First name must be 100 characters or fewer.';
    } elseif (!preg_match("/^[A-Za-zÀ-ÿ\s'\-]+$/u", $firstName)) {
        $errors[] = 'First name may only contain letters, spaces, hyphens, and apostrophes.';
    }

    if ($lastName === '') {
        $errors[] = 'Please enter the staff member\'s last name.';
    } elseif (mb_strlen($lastName) > 100) {
        $errors[] = 'Last name must be 100 characters or fewer.';
    } elseif (!preg_match("/^[A-Za-zÀ-ÿ\s'\-]+$/u", $lastName)) {
        $errors[] = 'Last name may only contain letters, spaces, hyphens, and apostrophes.';
    }

    if ($jobTitle === '') {
        $errors[] = 'Please enter the staff member\'s job title.';
    } elseif (mb_strlen($jobTitle) > 50) {
        $errors[] = 'Job title must be 50 characters or fewer.';
    } elseif (!preg_match("/^[A-Za-z0-9À-ÿ\s'\-&\/]+$/u", $jobTitle)) {
        $errors[] = 'Job title may only contain letters, numbers, spaces, and simple punctuation.';
    }

    if ($email === '') {
        $errors[] = 'Please enter an email address.';
    } elseif (mb_strlen($email) > 255) {
        $errors[] = 'Email address must be 255 characters or fewer.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } else {
        $statement = $pdo->prepare(
            'SELECT user_id FROM users WHERE email = ? AND user_id <> ? LIMIT 1'
        );
        $statement->execute([$email, $ignoreUserId]);

        if ($statement->fetch()) {
            $errors[] = 'That email address is already registered.';
        }
    }

    return $errors;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        admin_staff_respond(false, 'Your session expired. Please reload the page.', 403);
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {

        $errors = admin_staff_identity_errors($_POST, $pdo);

        $password = (string) ($_POST['password'] ?? '');

        if ($password === '') {
            $errors[] = 'Please enter a password for the new staff account.';
        } else {
            $policy = brewski_password_policy();

            foreach (brewski_password_failures($password, $policy) as $failure) {
                $errors[] = $failure;
            }
        }

        if ($errors) {
            admin_staff_respond(false, implode(' ', $errors), 422);
        }

        $firstName = trim((string) $_POST['first_name']);
        $lastName  = trim((string) $_POST['last_name']);
        $email     = trim((string) $_POST['email']);
        $jobTitle  = trim((string) $_POST['job_title']);
        $isActive  = (string) ($_POST['is_active'] ?? '0') === '1' ? 1 : 0;

        $insert = $pdo->prepare(
            'INSERT INTO users (first_name, last_name, email, password, role, job_title, is_active)
             VALUES (?, ?, ?, ?, "STAFF", ?, ?)'
        );
        $insert->execute([
            $firstName,
            $lastName,
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            $jobTitle,
            $isActive,
        ]);

        $newId = (int) $pdo->lastInsertId();

        $_SESSION['admin_flash'] = [
            'type'    => 'success',
            'message' => 'Staff account #' . $newId . ' was created successfully.',
        ];

        admin_staff_respond(true, 'Staff account created.');
    }

    if ($action === 'update') {

        $staffId = filter_var($_POST['staff_id'] ?? null, FILTER_VALIDATE_INT);

        if ($staffId === false || $staffId <= 0) {
            admin_staff_respond(false, 'Please choose a valid staff member.');
        }

        $exists = $pdo->prepare(
            "SELECT user_id FROM users WHERE user_id = ? AND role = 'STAFF' LIMIT 1"
        );
        $exists->execute([(int) $staffId]);

        if (!$exists->fetch()) {
            admin_staff_respond(false, 'That staff member no longer exists.', 404);
        }

        $errors = admin_staff_identity_errors($_POST, $pdo, (int) $staffId);

        $newPassword = (string) ($_POST['new_password'] ?? '');

        if ($newPassword !== '') {
            if (mb_strlen($newPassword) > 72) {
                $errors[] = 'Password must be 72 characters or fewer.';
            } else {
                $policy = brewski_password_policy();

                foreach (brewski_password_failures($newPassword, $policy) as $failure) {
                    $errors[] = $failure;
                }
            }
        }

        if ($errors) {
            admin_staff_respond(false, implode(' ', $errors), 422);
        }

        $firstName = trim((string) $_POST['first_name']);
        $lastName  = trim((string) $_POST['last_name']);
        $email     = trim((string) $_POST['email']);
        $jobTitle  = trim((string) $_POST['job_title']);
        $isActive  = (string) ($_POST['is_active'] ?? '0') === '1' ? 1 : 0;

        $update = $pdo->prepare(
            'UPDATE users
                SET first_name = ?, last_name = ?, email = ?, job_title = ?, is_active = ?
              WHERE user_id = ? AND role = "STAFF"'
        );
        $update->execute([$firstName, $lastName, $email, $jobTitle, $isActive, (int) $staffId]);

        if ($newPassword !== '') {
            $passwordUpdate = $pdo->prepare('UPDATE users SET password = ? WHERE user_id = ?');
            $passwordUpdate->execute([
                password_hash($newPassword, PASSWORD_DEFAULT),
                (int) $staffId,
            ]);
        }

        $_SESSION['admin_flash'] = [
            'type'    => 'success',
            'message' => 'Staff account #' . (int) $staffId . ' was updated.',
        ];

        admin_staff_respond(true, 'Staff account updated.');
    }

    if ($action === 'delete') {

        $ids = $_POST['ids'] ?? [];

        if (!is_array($ids) || !$ids) {
            admin_staff_respond(false, 'Please select at least one staff member to delete.');
        }

        $cleanIds = [];

        foreach ($ids as $candidate) {
            $candidate = filter_var($candidate, FILTER_VALIDATE_INT);

            if ($candidate !== false && $candidate > 0) {
                $cleanIds[] = (int) $candidate;
            }
        }

        $cleanIds = array_values(array_unique($cleanIds));

        if (!$cleanIds) {
            admin_staff_respond(false, 'Please select at least one staff member to delete.');
        }

        $placeholders = implode(', ', array_fill(0, count($cleanIds), '?'));

        try {
            $delete = $pdo->prepare(
                "DELETE FROM users WHERE role = 'STAFF' AND user_id IN (" . $placeholders . ')'
            );
            $delete->execute($cleanIds);
        } catch (PDOException $exception) {
            admin_staff_respond(
                false,
                'Those staff members are still linked to other records, so they cannot be deleted.',
                409
            );
        }

        $deleted = $delete->rowCount();

        $_SESSION['admin_flash'] = [
            'type'    => 'success',
            'message' => $deleted === 1
                ? '1 staff account was deleted.'
                : $deleted . ' staff accounts were deleted.',
        ];

        admin_staff_respond(true, 'Staff accounts deleted.');
    }

    admin_staff_respond(false, 'That action is not recognised.');
}

$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

$staffMembers = $pdo->query(
    "SELECT user_id, first_name, last_name, email, job_title, is_active, created_at
       FROM users
      WHERE role = 'STAFF'
      ORDER BY user_id DESC"
)->fetchAll();
?>

<div class="page-container">

    <div class="page-header">
        <h1 class="page-title">Staff Information</h1>
        <p class="subtitle">Manage staff accounts, job titles, and access status.</p>
    </div>

    <p
        id="staffStatus"
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
                <input type="text" id="staffSearchInput" class="toolbar-input" placeholder="Search by name or ID..." aria-label="Search staff">
            </div>

            <select id="staffStatusFilter" class="toolbar-select" aria-label="Filter staff by status">
                <option value="all">All statuses</option>
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
            </select>
        </div>

        <button type="button" id="addStaffBtn" class="btn btn-primary">Add staff</button>

        <div id="staffBulkActions" class="bulk-actions-bar">
            <span><span class="bulk-count" id="staffSelectedCount">0</span> selected</span>
            <button type="button" id="deleteStaffBtn" class="btn btn-delete">Delete selected</button>
        </div>
    </div>

    <div class="table-card">
        <div class="table-scroll">
            <table class="data-table table-wide">
                <thead>
                    <tr>
                        <th class="col-check"><input type="checkbox" id="staffSelectAll" class="row-checkbox" aria-label="Select all staff"></th>
                        <th>Staff No.</th>
                        <th>Name</th>
                        <th>Job Title</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Created Date</th>
                        <th class="col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody id="staffTableBody">
                    <?php foreach ($staffMembers as $staff): ?>
                        <?php
                        $staffId = (int) $staff['user_id'];
                        $staffName = trim((string) $staff['first_name'] . ' ' . (string) $staff['last_name']);
                        $staffEmail = (string) $staff['email'];
                        $staffJob = trim((string) ($staff['job_title'] ?? ''));
                        $staffActive = (int) $staff['is_active'] === 1;
                        $staffStatusLabel = $staffActive ? 'Active' : 'Inactive';
                        $staffCreated = (string) $staff['created_at'];
                        $staffCreatedStamp = strtotime($staffCreated);

                        if ($staffJob === '') {
                            $staffJob = 'Unassigned';
                        }
                        ?>
                        <tr
                            data-id="<?= $staffId ?>"
                            data-name="<?= htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8') ?>"
                            data-email="<?= htmlspecialchars($staffEmail, ENT_QUOTES, 'UTF-8') ?>"
                            data-first="<?= htmlspecialchars((string) $staff['first_name'], ENT_QUOTES, 'UTF-8') ?>"
                            data-last="<?= htmlspecialchars((string) $staff['last_name'], ENT_QUOTES, 'UTF-8') ?>"
                            data-job="<?= htmlspecialchars($staffJob, ENT_QUOTES, 'UTF-8') ?>"
                            data-active="<?= $staffActive ? '1' : '0' ?>"
                            data-status="<?= htmlspecialchars($staffStatusLabel, ENT_QUOTES, 'UTF-8') ?>"
                            data-created="<?= htmlspecialchars($staffCreatedStamp !== false ? date('M j, Y', $staffCreatedStamp) : '—', ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <td class="col-check">
                                <input type="checkbox" class="row-checkbox" value="<?= $staffId ?>" aria-label="Select <?= htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8') ?>">
                            </td>

                            <td><span class="cell-id">#<?= $staffId ?></span></td>

                            <td><span class="cell-strong js-staff-name"><?= htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8') ?></span></td>

                            <td><span class="cell-muted js-staff-job"><?= htmlspecialchars($staffJob, ENT_QUOTES, 'UTF-8') ?></span></td>

                            <td><span class="cell-muted js-staff-email"><?= htmlspecialchars($staffEmail, ENT_QUOTES, 'UTF-8') ?></span></td>

                            <td>
                                <span class="js-staff-status">
                                    <span class="badge <?= $staffActive ? 'badge-active' : 'badge-inactive' ?>"><?= htmlspecialchars($staffStatusLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                </span>
                            </td>

                            <td><span class="cell-muted js-staff-created"><?= htmlspecialchars($staffCreatedStamp !== false ? date('M j, Y', $staffCreatedStamp) : '—', ENT_QUOTES, 'UTF-8') ?></span></td>

                            <td class="col-actions">
                                <div class="table-actions">
                                    <button type="button" class="btn btn-view" data-action="view">View</button>
                                    <button type="button" class="btn btn-edit" data-action="edit">Edit</button>
                                    <button type="button" class="btn btn-delete" data-action="delete">Delete</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <tr id="staffNoResults" class="hidden">
                        <td colspan="8" class="empty-cell">
                            <div class="empty-state">
                                <p class="empty-title">No staff found</p>
                                <p class="empty-hint">Try adjusting your search or filter criteria.</p>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="pagination-footer">
            <p class="pagination-info" id="staffPageInfo"></p>
            <div class="pagination-nav" id="staffPaginationNav"></div>
        </div>
    </div>
</div>

<script>
    (function () {
        const ENDPOINT = <?= json_encode($endpoint, JSON_UNESCAPED_SLASHES) ?>;
        const CSRF = <?= json_encode($csrfToken) ?>;
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
        const statusLine = document.getElementById('staffStatus');

        if (!search || !tbody) return;

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

            for (let page = 1; page <= count; page++) {
                (function (target) {
                    pagination.appendChild(pageButton(String(target), false, target === currentPage, function () {
                        currentPage = target;
                        render();
                    }));
                })(page);
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
                const haystack = (row.dataset.name + ' ' + row.dataset.id + ' ' + row.dataset.email).toLowerCase();

                return (!term || haystack.includes(term)) &&
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

        function activeOptions(selected) {
            return '<option value="1"' + (selected ? ' selected' : '') + '>Active</option>' +
                '<option value="0"' + (selected ? '' : ' selected') + '>Inactive</option>';
        }

        function createStaff() {
            window.openAdminModal({
                title: 'Add staff',
                body: '<form class="form-grid" id="staffCreateForm">' +
                        '<input type="hidden" name="csrf_token" value="' + escapeHtml(CSRF) + '">' +
                        '<input type="hidden" name="action" value="create">' +
                        '<div class="form-field">' +
                            '<label for="staffCreateFirst">First name</label>' +
                            '<input id="staffCreateFirst" name="first_name" maxlength="100" required>' +
                        '</div>' +
                        '<div class="form-field">' +
                            '<label for="staffCreateLast">Last name</label>' +
                            '<input id="staffCreateLast" name="last_name" maxlength="100" required>' +
                        '</div>' +
                        '<div class="form-field form-field-full">' +
                            '<label for="staffCreateEmail">Email address</label>' +
                            '<input type="email" id="staffCreateEmail" name="email" maxlength="255" required>' +
                        '</div>' +
                        '<div class="form-field">' +
                            '<label for="staffCreateJob">Job title</label>' +
                            '<input id="staffCreateJob" name="job_title" maxlength="50" required>' +
                        '</div>' +
                        '<div class="form-field">' +
                            '<label for="staffCreateStatus">Status</label>' +
                            '<select id="staffCreateStatus" name="is_active">' + activeOptions(true) + '</select>' +
                        '</div>' +
                        '<div class="form-field form-field-full">' +
                            '<label for="staffCreatePassword">Temporary password</label>' +
                            '<input type="password" id="staffCreatePassword" name="password" required>' +
                            '<span class="form-hint">' + escapeHtml(<?= json_encode(brewski_password_policy_hint()) ?>) + '</span>' +
                        '</div>' +
                      '</form>',
                footer: [
                    { label: 'Cancel', className: 'btn-secondary' },
                    {
                        label: 'Create staff',
                        className: 'btn-primary',
                        onClick: function (handle) {
                            const form = handle.element.querySelector('#staffCreateForm');

                            if (!form.reportValidity()) return false;

                            setStatus('Creating...', '');

                            send(new FormData(form)).then(function (json) {
                                if (json.ok) {
                                    handle.close();
                                    window.brewskiReloadView();
                                } else {
                                    setStatus(json.error || 'Could not create the staff account.', 'error');
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

        function viewStaff(row) {
            const data = row.dataset;

            window.openAdminModal({
                title: 'Staff #' + data.id,
                body: '<dl class="detail-list">' +
                        '<dt>Staff No.</dt><dd>#' + escapeHtml(data.id) + '</dd>' +
                        '<dt>Name</dt><dd>' + escapeHtml(data.name) + '</dd>' +
                        '<dt>Email</dt><dd>' + escapeHtml(data.email) + '</dd>' +
                        '<dt>Job title</dt><dd>' + escapeHtml(data.job) + '</dd>' +
                        '<dt>Status</dt><dd>' + escapeHtml(data.status) + '</dd>' +
                        '<dt>Created date</dt><dd>' + escapeHtml(data.created) + '</dd>' +
                      '</dl>'
            });
        }

        function editStaff(row) {
            const data = row.dataset;

            window.openAdminModal({
                title: 'Edit staff #' + data.id,
                body: '<form class="form-grid" id="staffEditForm">' +
                        '<input type="hidden" name="csrf_token" value="' + escapeHtml(CSRF) + '">' +
                        '<input type="hidden" name="action" value="update">' +
                        '<input type="hidden" name="staff_id" value="' + escapeHtml(data.id) + '">' +
                        '<div class="form-field">' +
                            '<label for="staffEditFirst">First name</label>' +
                            '<input id="staffEditFirst" name="first_name" maxlength="100" value="' + escapeHtml(data.first) + '" required>' +
                        '</div>' +
                        '<div class="form-field">' +
                            '<label for="staffEditLast">Last name</label>' +
                            '<input id="staffEditLast" name="last_name" maxlength="100" value="' + escapeHtml(data.last) + '" required>' +
                        '</div>' +
                        '<div class="form-field form-field-full">' +
                            '<label for="staffEditEmail">Email address</label>' +
                            '<input type="email" id="staffEditEmail" name="email" maxlength="255" value="' + escapeHtml(data.email) + '" required>' +
                        '</div>' +
                        '<div class="form-field">' +
                            '<label for="staffEditJob">Job title</label>' +
                            '<input id="staffEditJob" name="job_title" maxlength="50" value="' + escapeHtml(data.job) + '" required>' +
                        '</div>' +
                        '<div class="form-field">' +
                            '<label for="staffEditStatus">Status</label>' +
                            '<select id="staffEditStatus" name="is_active">' + activeOptions(data.active === '1') + '</select>' +
                        '</div>' +
                        '<div class="form-field form-field-full">' +
                            '<label for="staffEditPassword">New password</label>' +
                            '<input type="password" id="staffEditPassword" name="new_password">' +
                            '<span class="form-hint">Leave blank to keep the current password.</span>' +
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

                            setStatus('Saving...', '');

                            send(new FormData(form)).then(function (json) {
                                if (json.ok) {
                                    handle.close();
                                    window.brewskiReloadView();
                                } else {
                                    setStatus(json.error || 'Could not save the staff account.', 'error');
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

        function deleteStaff(ids) {
            const body = new FormData();
            body.append('csrf_token', CSRF);
            body.append('action', 'delete');

            ids.forEach(function (id) {
                body.append('ids[]', id);
            });

            setStatus('Deleting...', '');

            send(body).then(function (json) {
                if (json.ok) {
                    window.brewskiReloadView();
                } else {
                    setStatus(json.error || 'Could not delete the selected staff.', 'error');
                }
            }).catch(function () {
                setStatus('Network error. Please try again.', 'error');
            });
        }

        document.getElementById('addStaffBtn').addEventListener('click', createStaff);

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

            if (!checked.length) return;

            if (confirm('Delete ' + checked.length + ' selected staff member(s)? This action cannot be undone.')) {
                deleteStaff(checked.map(function (box) {
                    return box.value;
                }));
            }
        });

        tbody.addEventListener('change', function (event) {
            if (event.target.classList.contains('row-checkbox')) syncSelection();
        });

        tbody.addEventListener('click', function (event) {
            const action = event.target.closest('button[data-action]');
            if (!action) return;

            const row = action.closest('tr[data-id]');
            if (!row) return;

            if (action.dataset.action === 'view') viewStaff(row);
            if (action.dataset.action === 'edit') editStaff(row);

            if (action.dataset.action === 'delete' &&
                confirm('Delete staff #' + row.dataset.id + '? This action cannot be undone.')) {
                deleteStaff([row.dataset.id]);
            }
        });

        render();
    })();
</script>

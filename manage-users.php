<?php
// manage-users.php - Manage Users (Admin)

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';

// Check if user is logged in and is admin
if (!User::isLoggedIn() || $_SESSION['role'] != 'admin') {
    header('Location: login.php');
    exit();
}

$db = new Database();
$db->connect();

$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action == 'update_role') {
        $user_id = $_POST['user_id'];
        $role = $_POST['role'];
        
        $data = array('role' => $role);
        if ($db->update('users', 'user_id', $user_id, $data)) {
            $success_message = 'User role updated successfully!';
        } else {
            $error_message = 'Failed to update user role!';
        }
    } elseif ($action == 'toggle_status') {
        $user_id = $_POST['user_id'];
        $is_active = $_POST['is_active'] == '1' ? '0' : '1';
        
        $data = array('is_active' => $is_active);
        if ($db->update('users', 'user_id', $user_id, $data)) {
            $success_message = 'User status updated successfully!';
        } else {
            $error_message = 'Failed to update user status!';
        }
    } elseif ($action == 'delete') {
        $user_id = $_POST['user_id'];
        if ($db->delete('users', 'user_id', $user_id)) {
            $success_message = 'User deleted successfully!';
        } else {
            $error_message = 'Failed to delete user!';
        }
    }
}

$all_users = $db->select("SELECT * FROM users ORDER BY created_at DESC");
if (!$all_users) $all_users = [];

// KPI stats
$total_users    = count($all_users);
$active_users   = count(array_filter($all_users, fn($u) => (int)$u['is_active'] === 1));
$admin_users    = count(array_filter($all_users, fn($u) => $u['role'] === 'admin'));
$employee_users = count(array_filter($all_users, fn($u) => $u['role'] === 'employee'));

// Current admin for sidebar avatar
$adminUser_ = (new User($db))->getUserById($_SESSION['user_id']);

$hideMainNavbar = true;
$pageTitle = 'Manage Users – Admin';
require_once 'inc/header.php';
?>

<style>
.adm-wrap { display:flex; min-height:100vh; }
.adm-sidebar {
    width:240px; flex-shrink:0;
    background:linear-gradient(180deg,#1a2e4a 0%,#0f1e32 100%);
    color:#c8d6e8; display:flex; flex-direction:column;
    position:sticky; top:0; height:100vh; overflow-y:auto;
}
.adm-sidebar .sb-brand { padding:1.4rem 1.5rem 1rem; border-bottom:1px solid rgba(255,255,255,.08); }
.adm-sidebar .sb-brand span { display:block; font-size:.7rem; text-transform:uppercase; letter-spacing:1.5px; opacity:.5; margin-bottom:.3rem; }
.adm-sidebar .sb-brand strong { font-size:1rem; color:#fff; }
.adm-sidebar nav { flex:1; padding:.75rem 0; }
.adm-sidebar nav a {
    display:flex; align-items:center; gap:.75rem; padding:.65rem 1.5rem;
    color:#c8d6e8; text-decoration:none; font-size:.875rem; font-weight:500;
    transition:all .2s; border-left:3px solid transparent;
}
.adm-sidebar nav a:hover,
.adm-sidebar nav a.active { background:rgba(255,255,255,.07); color:#fff; border-left-color:#3b82f6; }
.adm-sidebar nav a i { font-size:1rem; width:1.1rem; text-align:center; }
.adm-sidebar .sb-sep { padding:.5rem 1.5rem .25rem; font-size:.68rem; text-transform:uppercase; letter-spacing:1.5px; opacity:.4; margin-top:.5rem; }
.adm-sidebar .sb-user { padding:1rem 1.5rem; border-top:1px solid rgba(255,255,255,.08); display:flex; align-items:center; gap:.75rem; }
.adm-sidebar .sb-user .avatar {
    width:34px; height:34px; border-radius:50%;
    background:linear-gradient(135deg,#3b82f6,#6366f1);
    display:flex; align-items:center; justify-content:center;
    font-size:.875rem; font-weight:700; color:#fff; flex-shrink:0;
}
.adm-sidebar .sb-user .info small { display:block; font-size:.7rem; opacity:.5; }
.adm-sidebar .sb-user .info strong { font-size:.8rem; color:#fff; }

.adm-main { flex:1; padding:2rem; overflow-x:hidden; }
.adm-page-header { display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; flex-wrap:wrap; margin-bottom:1.5rem; }
.adm-page-header h2 { margin:0; font-size:1.6rem; font-weight:800; color:#0f172a; }
.adm-page-header p { margin:.2rem 0 0; color:#64748b; font-size:.9rem; }

.metric-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:1rem; margin-bottom:1.5rem; }
.metric-card { background:#fff; border-radius:14px; padding:1.2rem 1.3rem; box-shadow:0 1px 4px rgba(0,0,0,.07); }
.metric-card .icon { width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; margin-bottom:.8rem; }
.metric-card .value { font-size:1.8rem; font-weight:800; line-height:1; color:#0f172a; }
.metric-card .label { font-size:.8rem; color:#64748b; margin-top:.3rem; }
.metric-card.blue   .icon { background:#dbeafe; color:#2563eb; }
.metric-card.green  .icon { background:#dcfce7; color:#16a34a; }
.metric-card.amber  .icon { background:#fef3c7; color:#d97706; }
.metric-card.purple .icon { background:#ede9fe; color:#7c3aed; }

.surface-card { background:#fff; border-radius:14px; padding:1.25rem; box-shadow:0 1px 4px rgba(0,0,0,.07); margin-bottom:1.5rem; }
.usr-table thead th { background:#0f1e32; color:#fff; font-size:.82rem; font-weight:600; white-space:nowrap; }
.usr-table td { font-size:.85rem; vertical-align:middle; }
.usr-table tbody tr:hover { background:#f8fafc; }

.role-badge { display:inline-block; padding:.25em .75em; border-radius:999px; font-size:.77rem; font-weight:600; }
.role-admin    { background:#fee2e2; color:#991b1b; }
.role-employee { background:#fef3c7; color:#92400e; }
.role-user     { background:#dbeafe; color:#1d4ed8; }
.status-active   { background:#dcfce7; color:#166534; }
.status-inactive { background:#e2e8f0; color:#334155; }

@media (max-width:900px) {
    .adm-sidebar { display:none; }
    .adm-main    { padding:1rem; }
}
</style>

<div class="adm-wrap">
    <aside class="adm-sidebar">
        <div class="sb-brand">
            <span>Management Panel</span>
            <strong>Railway Admin</strong>
        </div>
        <nav>
            <div class="sb-sep">Main</div>
            <a href="admin-dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a href="reports.php"><i class="bi bi-bar-chart-line"></i> Reports</a>

            <div class="sb-sep">Operations</div>
            <a href="manage-trains.php"><i class="bi bi-train-front"></i> Trains</a>
            <a href="manage-routes.php"><i class="bi bi-map"></i> Routes</a>
            <a href="manage-bookings.php"><i class="bi bi-ticket-perforated"></i> Bookings</a>
            <a href="manage-payments.php"><i class="bi bi-cash-stack"></i> Payments</a>

            <div class="sb-sep">Users</div>
            <a href="manage-users.php" class="active"><i class="bi bi-people"></i> Users</a>

            <div class="sb-sep">System</div>
            <a href="notifications.php"><i class="bi bi-bell"></i> Notifications</a>
            <a href="profile.php"><i class="bi bi-person-gear"></i> My Profile</a>
            <a href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
        </nav>
        <div class="sb-user">
            <div class="avatar"><?= strtoupper(substr($adminUser_['full_name'] ?? 'A', 0, 1)) ?></div>
            <div class="info">
                <strong><?= htmlspecialchars($adminUser_['full_name'] ?? 'Admin') ?></strong>
                <small>Administrator</small>
            </div>
        </div>
    </aside>

    <section class="adm-main">

        <!-- Page Header -->
        <div class="adm-page-header">
            <div>
                <h2><i class="bi bi-people me-2 text-primary"></i>Manage Users</h2>
                <p>View and manage all registered users, roles, and account statuses.</p>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="metric-grid">
            <div class="metric-card blue">
                <div class="icon"><i class="bi bi-people-fill"></i></div>
                <div class="value"><?= number_format($total_users) ?></div>
                <div class="label">Total Users</div>
            </div>
            <div class="metric-card green">
                <div class="icon"><i class="bi bi-check2-circle"></i></div>
                <div class="value"><?= number_format($active_users) ?></div>
                <div class="label">Active Accounts</div>
            </div>
            <div class="metric-card amber">
                <div class="icon"><i class="bi bi-person-badge"></i></div>
                <div class="value"><?= number_format($employee_users) ?></div>
                <div class="label">Employees</div>
            </div>
            <div class="metric-card purple">
                <div class="icon"><i class="bi bi-shield-lock"></i></div>
                <div class="value"><?= number_format($admin_users) ?></div>
                <div class="label">Administrators</div>
            </div>
        </div>

        <!-- Search + Table -->
        <div class="surface-card">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h5 class="mb-0 fw-bold"><i class="bi bi-table me-2 text-primary"></i>User Registry</h5>
                <div class="input-group input-group-sm" style="max-width:300px;">
                    <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" id="userSearchInput" class="form-control border-start-0"
                           placeholder="Search name, email, username…">
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 usr-table" id="userTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>User</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($all_users)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="bi bi-people fs-2 d-block mb-2"></i>No users found.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($all_users as $u): ?>
                        <tr>
                            <td class="text-muted small"><?= (int)$u['user_id'] ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#6366f1);display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700;color:#fff;flex-shrink:0;">
                                        <?= strtoupper(substr($u['full_name'] ?? $u['username'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="fw-semibold"><?= htmlspecialchars($u['full_name']) ?></div>
                                        <div class="text-muted" style="font-size:.75rem;">@<?= htmlspecialchars($u['username']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="small"><?= htmlspecialchars($u['email']) ?></td>
                            <td class="small text-muted"><?= htmlspecialchars($u['phone'] ?? '—') ?></td>
                            <td>
                                <span class="role-badge role-<?= htmlspecialchars($u['role']) ?>">
                                    <?= ucfirst(htmlspecialchars($u['role'])) ?>
                                </span>
                            </td>
                            <td>
                                <span class="role-badge <?= (int)$u['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                    <?= (int)$u['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td class="small text-muted text-nowrap"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                            <td>
                                <div class="d-flex gap-1">
                                    <button type="button" class="btn btn-outline-primary btn-sm" title="Edit User"
                                            onclick='openEditModal(<?= htmlspecialchars(json_encode($u), ENT_QUOTES, "UTF-8") ?>)'>
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                    <?php if ((int)$u['user_id'] !== (int)$_SESSION['user_id']): ?>
                                    <button type="button" class="btn btn-outline-danger btn-sm" title="Delete User"
                                            onclick="openDeleteModal(<?= (int)$u['user_id'] ?>, <?= htmlspecialchars(json_encode($u['full_name']), ENT_QUOTES, 'UTF-8') ?>)">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header">
                <div>
                    <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:#64748b;font-weight:700;">User Management</div>
                    <h5 class="modal-title mb-0">Edit User</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="manage-users.php">
                <input type="hidden" name="action" value="update_role">
                <input type="hidden" name="user_id" id="editUserId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Username</label>
                        <input type="text" class="form-control" id="editUsername" readonly style="background:#f8fafc;">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Email</label>
                        <input type="text" class="form-control" id="editEmail" readonly style="background:#f8fafc;">
                    </div>
                    <div class="mb-0">
                        <label class="form-label small fw-semibold">Role</label>
                        <select name="role" id="editRole" class="form-select" required>
                            <option value="user">User</option>
                            <option value="employee">Employee</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Role</button>
                </div>
            </form>
            <div class="px-3 pb-3">
                <form method="POST" action="manage-users.php">
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="user_id" id="toggleUserId">
                    <input type="hidden" name="is_active" id="toggleCurrentStatus">
                    <button type="submit" class="btn w-100" id="toggleStatusBtn">Toggle Status</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-0">
                <h5 class="modal-title">Delete User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="manage-users.php">
                <div class="modal-body pt-0">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="user_id" id="deleteUserId">
                    <p class="mb-0" id="deleteUserMessage">Are you sure you want to delete this user? This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Live search
    var searchInput = document.getElementById('userSearchInput');
    var tableRows   = document.querySelectorAll('#userTable tbody tr');
    searchInput.addEventListener('input', function () {
        var term = this.value.toLowerCase().trim();
        tableRows.forEach(function (row) {
            row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
        });
    });

    // Edit modal
    window.openEditModal = function (user) {
        document.getElementById('editUserId').value          = user.user_id;
        document.getElementById('editUsername').value        = user.username;
        document.getElementById('editEmail').value           = user.email;
        document.getElementById('editRole').value            = user.role;
        document.getElementById('toggleUserId').value        = user.user_id;
        document.getElementById('toggleCurrentStatus').value = user.is_active;

        var toggleBtn = document.getElementById('toggleStatusBtn');
        if (parseInt(user.is_active) === 1) {
            toggleBtn.textContent = 'Deactivate User';
            toggleBtn.className   = 'btn btn-outline-warning w-100';
        } else {
            toggleBtn.textContent = 'Activate User';
            toggleBtn.className   = 'btn btn-outline-success w-100';
        }
        new bootstrap.Modal(document.getElementById('editUserModal')).show();
    };

    // Delete modal
    window.openDeleteModal = function (userId, fullName) {
        document.getElementById('deleteUserId').value    = userId;
        document.getElementById('deleteUserMessage').textContent =
            'Are you sure you want to delete "' + fullName + '"? This action cannot be undone.';
        new bootstrap.Modal(document.getElementById('deleteUserModal')).show();
    };
});
</script>

<?php require_once 'inc/footer.php'; ?>

<?php
// audit-logs.php – Admin: Full Audit Trail

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';

if (!User::isLoggedIn() || $_SESSION['role'] !== 'admin') {
    header('Location: login.php'); exit();
}

$db = new Database();
$db->connect();
$conn = $db->getConnection();

$user_obj   = new User($db);
$adminUser_ = $user_obj->getUserById($_SESSION['user_id']);

// ── Filters ────────────────────────────────────────────────────────────────
$filter_module = trim($_GET['module'] ?? 'all');
$filter_action = trim($_GET['action'] ?? '');
$filter_user   = trim($_GET['user']   ?? '');
$date_from     = $_GET['date_from'] ?? '';
$date_to       = $_GET['date_to']   ?? '';
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 30;
$offset        = ($page - 1) * $per_page;

$wheres = [];
if ($filter_module !== 'all' && $filter_module !== '') {
    $m = $conn->real_escape_string($filter_module);
    $wheres[] = "al.module = '{$m}'";
}
if ($filter_action !== '') {
    $a = $conn->real_escape_string($filter_action);
    $wheres[] = "al.action LIKE '%{$a}%'";
}
if ($filter_user !== '') {
    $u = $conn->real_escape_string($filter_user);
    $wheres[] = "(u.full_name LIKE '%{$u}%' OR u.username LIKE '%{$u}%')";
}
if ($date_from !== '') {
    $df = $conn->real_escape_string($date_from);
    $wheres[] = "DATE(al.created_at) >= '{$df}'";
}
if ($date_to !== '') {
    $dt = $conn->real_escape_string($date_to);
    $wheres[] = "DATE(al.created_at) <= '{$dt}'";
}
$where_sql = $wheres ? 'WHERE ' . implode(' AND ', $wheres) : '';

$total_rows  = (int)($db->selectRow("SELECT COUNT(*) AS n FROM audit_logs al LEFT JOIN users u ON al.user_id = u.user_id {$where_sql}")['n'] ?? 0);
$total_pages = max(1, (int)ceil($total_rows / $per_page));

$logs = $db->select(
    "SELECT al.*, u.full_name, u.username
     FROM audit_logs al
     LEFT JOIN users u ON al.user_id = u.user_id
     {$where_sql}
     ORDER BY al.created_at DESC
     LIMIT {$per_page} OFFSET {$offset}"
) ?: [];

// Distinct modules for filter dropdown
$modules = $db->select("SELECT DISTINCT module FROM audit_logs ORDER BY module") ?: [];

// KPIs
$kpi = $db->selectRow(
    "SELECT COUNT(*) AS total,
            SUM(DATE(created_at) = CURDATE()) AS today,
            COUNT(DISTINCT user_id) AS unique_users,
            COUNT(DISTINCT module)  AS modules_touched
     FROM audit_logs"
) ?? [];

$hideMainNavbar = true;
$pageTitle = 'Audit Logs – Admin';
require_once 'inc/header.php';

function aUrl(array $ov = []): string {
    global $filter_module, $filter_action, $filter_user, $date_from, $date_to, $page;
    $p = array_filter(array_merge([
        'module'    => $filter_module !== 'all' ? $filter_module : null,
        'action'    => $filter_action ?: null,
        'user'      => $filter_user   ?: null,
        'date_from' => $date_from     ?: null,
        'date_to'   => $date_to       ?: null,
        'page'      => $page > 1      ? $page : null,
    ], $ov));
    $qs = http_build_query($p);
    return 'audit-logs.php' . ($qs ? "?{$qs}" : '');
}
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
.adm-main { flex:1; padding:2rem; overflow-x:hidden; background:#f8fafc; }

.kpi-card { border-radius:14px; border:none; box-shadow:0 1px 4px rgba(0,0,0,.07); }
.filter-bar { background:#fff; border-radius:14px; box-shadow:0 1px 6px rgba(0,0,0,.07); padding:1.25rem; margin-bottom:1.25rem; }
.log-table thead th { background:#0f1e32; color:#fff; font-weight:600; font-size:.8rem; white-space:nowrap; }
.log-table td { font-size:.82rem; vertical-align:middle; }
.log-table tbody tr:hover { background:#f8fafc; }

.mod-pill {
    display:inline-block; padding:.2em .65em; border-radius:999px; font-size:.71rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em;
}
.mod-bookings  { background:#dbeafe; color:#1e40af; }
.mod-trains    { background:#d1fae5; color:#065f46; }
.mod-users     { background:#ede9fe; color:#4c1d95; }
.mod-routes    { background:#fef3c7; color:#92400e; }
.mod-payments  { background:#ecfdf5; color:#065f46; }
.mod-cargo     { background:#fff7ed; color:#9a3412; }
.mod-seats     { background:#f0fdf4; color:#15803d; }
.mod-discounts { background:#fdf4ff; color:#701a75; }
.mod-auth      { background:#fee2e2; color:#7f1d1d; }
.mod-other     { background:#f1f5f9; color:#475569; }

.diff-box { background:#1e293b; border-radius:8px; padding:.5rem .75rem; font-family:monospace; font-size:.72rem; color:#94a3b8; max-width:320px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

@media(max-width:900px) { .adm-sidebar { display:none; } .adm-main { padding:1rem; } }
</style>

<div class="adm-wrap">
<!-- ── Sidebar ── -->
<aside class="adm-sidebar">
    <div class="sb-brand">
        <span>Management Panel</span>
        <strong><i class="bi bi-train-front-fill me-1"></i> Railway Admin</strong>
    </div>
    <nav>
        <div class="sb-sep">Main</div>
        <a href="admin-dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="reports.php"><i class="bi bi-bar-chart-line"></i> Reports</a>
        <a href="audit-logs.php" class="active"><i class="bi bi-journal-text"></i> Audit Logs</a>

        <div class="sb-sep">Operations</div>
        <a href="manage-trains.php"><i class="bi bi-train-front"></i> Trains</a>
        <a href="manage-routes.php"><i class="bi bi-signpost-split"></i> Routes</a>
        <a href="manage-bookings.php"><i class="bi bi-ticket-perforated"></i> Bookings</a>
        <a href="manage-payments.php"><i class="bi bi-credit-card"></i> Payments</a>
        <a href="cargo-shipments.php"><i class="bi bi-box-seam"></i> Cargo</a>

        <div class="sb-sep">People</div>
        <a href="manage-users.php"><i class="bi bi-people"></i> Users</a>
        <a href="notifications.php"><i class="bi bi-bell"></i> Notifications</a>

        <div class="sb-sep">Account</div>
        <a href="profile.php"><i class="bi bi-person-gear"></i> Profile</a>
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

<!-- ── Main ── -->
<main class="adm-main">
    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
        <div>
            <h2 class="fw-black mb-0" style="color:#0f172a;">Audit Logs</h2>
            <p class="text-muted mb-0" style="font-size:.9rem;">Complete action trail for all admin &amp; employee operations.</p>
        </div>
        <a href="audit-logs.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-counterclockwise me-1"></i>Clear Filters</a>
    </div>

    <!-- KPIs -->
    <div class="row g-3 mb-4">
        <?php
        $kpis = [
            ['Total Entries',    $kpi['total']           ?? 0, 'bi-journal-text',  '#2563eb', '#eff6ff'],
            ['Today',           $kpi['today']            ?? 0, 'bi-calendar-check','#0891b2', '#ecfeff'],
            ['Unique Users',    $kpi['unique_users']     ?? 0, 'bi-people-fill',   '#7c3aed', '#f5f3ff'],
            ['Modules Touched', $kpi['modules_touched']  ?? 0, 'bi-grid-3x3-gap', '#059669', '#f0fdf4'],
        ];
        foreach ($kpis as [$lbl, $val, $ico, $clr, $bg]):
        ?>
        <div class="col-lg-3 col-sm-6">
            <div class="card kpi-card p-3">
                <div class="d-flex align-items-center gap-3">
                    <div style="width:46px;height:46px;border-radius:10px;background:<?= $bg ?>;display:flex;align-items:center;justify-content:center;">
                        <i class="bi <?= $ico ?>" style="font-size:1.3rem;color:<?= $clr ?>;"></i>
                    </div>
                    <div>
                        <div class="fw-black" style="font-size:1.5rem;color:#0f172a;line-height:1;"><?= number_format($val) ?></div>
                        <div style="font-size:.78rem;color:#6b7280;"><?= $lbl ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filter bar -->
    <form method="GET" class="filter-bar">
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Module</label>
                <select name="module" class="form-select form-select-sm">
                    <option value="all">All modules</option>
                    <?php foreach ($modules as $m): ?>
                    <option value="<?= htmlspecialchars($m['module']) ?>"
                        <?= $filter_module === $m['module'] ? 'selected' : '' ?>>
                        <?= ucfirst(htmlspecialchars($m['module'])) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Action keyword</label>
                <input type="text" name="action" class="form-control form-control-sm"
                       placeholder="e.g. UPDATE" value="<?= htmlspecialchars($filter_action) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">User</label>
                <input type="text" name="user" class="form-control form-control-sm"
                       placeholder="Name / username" value="<?= htmlspecialchars($filter_user) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">From</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($date_from) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">To</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($date_to) ?>">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="audit-logs.php" class="btn btn-outline-secondary btn-sm">✕</a>
            </div>
        </div>
    </form>

    <!-- Table -->
    <div class="card shadow-sm" style="border-radius:14px;overflow:hidden;">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <span class="fw-bold"><?= number_format($total_rows) ?> entries <?= $filter_module !== 'all' || $filter_action || $filter_user ? '(filtered)' : '' ?></span>
            <span class="text-muted" style="font-size:.8rem;">Page <?= $page ?> of <?= $total_pages ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 log-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Timestamp</th>
                        <th>User</th>
                        <th>Module</th>
                        <th>Action</th>
                        <th>Description</th>
                        <th>Record ID</th>
                        <th>Changes</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="9" class="text-center py-5 text-muted">No audit entries found.</td></tr>
                <?php else: foreach ($logs as $log): ?>
                    <tr>
                        <td class="text-muted" style="font-size:.75rem;"><?= (int)$log['log_id'] ?></td>
                        <td style="white-space:nowrap;">
                            <span style="font-size:.8rem;"><?= date('d M Y', strtotime($log['created_at'])) ?></span><br>
                            <span style="font-size:.72rem;color:#6b7280;"><?= date('H:i:s', strtotime($log['created_at'])) ?></span>
                        </td>
                        <td>
                            <?php if ($log['full_name']): ?>
                                <span class="fw-semibold" style="font-size:.82rem;"><?= htmlspecialchars($log['full_name']) ?></span><br>
                                <span class="badge bg-secondary" style="font-size:.65rem;"><?= htmlspecialchars($log['user_role']) ?></span>
                            <?php else: ?>
                                <span class="text-muted">System / Guest</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $mc = 'mod-' . strtolower($log['module']); ?>
                            <span class="mod-pill <?= in_array($mc, ['mod-bookings','mod-trains','mod-users','mod-routes','mod-payments','mod-cargo','mod-seats','mod-discounts','mod-auth']) ? $mc : 'mod-other' ?>">
                                <?= htmlspecialchars($log['module']) ?>
                            </span>
                        </td>
                        <td><code style="font-size:.75rem;color:#1e40af;"><?= htmlspecialchars($log['action']) ?></code></td>
                        <td style="max-width:280px;" title="<?= htmlspecialchars($log['description']) ?>">
                            <?= htmlspecialchars(mb_strimwidth($log['description'] ?? '', 0, 80, '…')) ?>
                        </td>
                        <td><?= $log['record_id'] ? '<span class="badge bg-light text-dark">' . (int)$log['record_id'] . '</span>' : '—' ?></td>
                        <td>
                            <?php if ($log['old_value'] || $log['new_value']): ?>
                            <button class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:.7rem;"
                                    data-bs-toggle="modal" data-bs-target="#diffModal"
                                    data-old="<?= htmlspecialchars($log['old_value'] ?? '') ?>"
                                    data-new="<?= htmlspecialchars($log['new_value'] ?? '') ?>">
                                <i class="bi bi-file-diff"></i> Diff
                            </button>
                            <?php else: echo '—'; endif; ?>
                        </td>
                        <td><span style="font-size:.72rem;color:#6b7280;"><?= htmlspecialchars($log['ip_address'] ?? '') ?></span></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="card-footer bg-white d-flex justify-content-between align-items-center py-2">
            <span class="text-muted" style="font-size:.82rem;">Showing <?= number_format(($page-1)*$per_page+1) ?>–<?= number_format(min($page*$per_page,$total_rows)) ?> of <?= number_format($total_rows) ?></span>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= $page<=1?'disabled':'' ?>">
                        <a class="page-link" href="<?= aUrl(['page'=>$page-1]) ?>">‹</a>
                    </li>
                    <?php for ($p=max(1,$page-2); $p<=min($total_pages,$page+2); $p++): ?>
                    <li class="page-item <?= $p==$page?'active':'' ?>">
                        <a class="page-link" href="<?= aUrl(['page'=>$p]) ?>"><?= $p ?></a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>">
                        <a class="page-link" href="<?= aUrl(['page'=>$page+1]) ?>">›</a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</main>
</div>

<!-- Diff Modal -->
<div class="modal fade" id="diffModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Change Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <p class="fw-semibold text-danger mb-2">Before</p>
                        <pre id="diffOld" class="bg-light rounded p-3" style="font-size:.8rem;white-space:pre-wrap;word-break:break-all;min-height:80px;"></pre>
                    </div>
                    <div class="col-md-6">
                        <p class="fw-semibold text-success mb-2">After</p>
                        <pre id="diffNew" class="bg-light rounded p-3" style="font-size:.8rem;white-space:pre-wrap;word-break:break-all;min-height:80px;"></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('diffModal').addEventListener('show.bs.modal', function (e) {
    var btn = e.relatedTarget;
    document.getElementById('diffOld').textContent = btn.dataset.old || '(empty)';
    document.getElementById('diffNew').textContent = btn.dataset.new || '(empty)';
});
</script>

<?php require_once 'inc/footer.php'; ?>

<?php
// notifications.php - User Notifications
require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';

if (!User::isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$db = new Database();
$db->connect();
$user_id = (int)$_SESSION['user_id'];

// Mark all as read when the page is opened
$db->query("UPDATE notifications SET is_read=1 WHERE user_id={$user_id} AND is_read=0");

// Fetch notifications for this user (latest first)
$notifications = $db->select("SELECT * FROM notifications WHERE user_id = {$user_id} ORDER BY created_at DESC LIMIT 100");
if (!$notifications) $notifications = [];

$_role = $_SESSION['role'] ?? 'user';
$_userObj  = new User($db);
$_thisUser = $_userObj->getUserById($user_id);

$hideMainNavbar = true;
$pageTitle = 'Notifications – Railway System';
require_once 'inc/header.php';
?>

<style>
/* ── Shell ────────────────────────────────────────── */
.adm-wrap { display:flex; min-height:calc(100vh - 64px); }

/* ── Admin Sidebar ────────────────────────────────── */
.adm-sidebar {
    width:240px; flex-shrink:0;
    background:linear-gradient(180deg,#1a2e4a 0%,#0f1e32 100%);
    color:#c8d6e8; display:flex; flex-direction:column;
    position:sticky; top:64px; height:calc(100vh - 64px); overflow-y:auto;
}
.adm-sidebar .sb-brand { padding:1.4rem 1.5rem 1rem; border-bottom:1px solid rgba(255,255,255,.08); }
.adm-sidebar .sb-brand span { font-size:.7rem; text-transform:uppercase; letter-spacing:1.5px; opacity:.5; display:block; margin-bottom:.3rem; }
.adm-sidebar .sb-brand strong { font-size:1rem; color:#fff; }
.adm-sidebar nav { flex:1; padding:.75rem 0; }
.adm-sidebar nav a {
    display:flex; align-items:center; gap:.75rem;
    padding:.65rem 1.5rem; color:#c8d6e8; text-decoration:none;
    font-size:.875rem; font-weight:500; transition:all .2s;
    border-left:3px solid transparent;
}
.adm-sidebar nav a:hover, .adm-sidebar nav a.active {
    background:rgba(255,255,255,.07); color:#fff; border-left-color:#3b82f6;
}
.adm-sidebar nav a i { font-size:1rem; width:1.1rem; text-align:center; }
.adm-sidebar .sb-sep { padding:.5rem 1.5rem .25rem; font-size:.68rem; text-transform:uppercase; letter-spacing:1.5px; opacity:.4; margin-top:.5rem; }
.adm-sidebar .sb-user { padding:1rem 1.5rem; border-top:1px solid rgba(255,255,255,.08); display:flex; align-items:center; gap:.75rem; }
.adm-sidebar .sb-user .avatar { width:34px; height:34px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#6366f1); display:flex; align-items:center; justify-content:center; font-size:.875rem; font-weight:700; color:#fff; flex-shrink:0; }
.adm-sidebar .sb-user .info small { display:block; font-size:.7rem; opacity:.5; }
.adm-sidebar .sb-user .info strong { font-size:.8rem; color:#fff; }

/* ── Employee Sidebar ─────────────────────────────── */
.emp-sidebar {
    width:240px; flex-shrink:0;
    background:linear-gradient(180deg,#012117 0%,#064e3b 100%);
    display:flex; flex-direction:column;
    position:sticky; top:64px; height:calc(100vh - 64px); overflow-y:auto;
}
.emp-sidebar .emp-sb-brand { padding:1.4rem 1.25rem 1.2rem; border-bottom:1px solid rgba(255,255,255,.1); }
.emp-sidebar .emp-sb-brand .brand-icon { width:38px; height:38px; border-radius:10px; background:rgba(16,185,129,.2); color:#34d399; display:flex; align-items:center; justify-content:center; font-size:1.2rem; margin-bottom:.55rem; }
.emp-sidebar .emp-sb-brand .brand-name { font-weight:800; font-size:.95rem; color:#fff; line-height:1.2; }
.emp-sidebar .emp-sb-brand .brand-role { font-size:.7rem; color:rgba(255,255,255,.4); margin-top:.15rem; }
.emp-sidebar .sb-sep { font-size:.65rem; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:rgba(255,255,255,.28); padding:.9rem 1.25rem .3rem; }
.emp-sidebar nav a {
    display:flex; align-items:center; gap:.7rem;
    padding:.62rem 1.25rem; color:rgba(255,255,255,.65); text-decoration:none;
    font-size:.875rem; font-weight:500; transition:background .15s,color .15s,border-color .15s;
    border-left:3px solid transparent;
}
.emp-sidebar nav a:hover { background:rgba(255,255,255,.08); color:#fff; border-left-color:rgba(52,211,153,.4); }
.emp-sidebar nav a.active { background:rgba(16,185,129,.15); color:#fff; border-left-color:#10b981; font-weight:600; }
.emp-sidebar nav a i { font-size:.95rem; width:18px; text-align:center; }
.emp-sb-footer { margin-top:auto; padding:1rem 1.25rem; border-top:1px solid rgba(255,255,255,.08); font-size:.71rem; color:rgba(255,255,255,.3); text-align:center; }

/* ── Main ──────────────────────────────────────────── */
.adm-main { flex:1; padding:2rem; overflow-x:hidden; background:#f8fafc; }

/* ── Page header ───────────────────────────────────── */
.adm-page-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1.75rem; flex-wrap:wrap; gap:1rem; }
.adm-page-header h2 { font-size:1.5rem; font-weight:800; color:#0f172a; margin:0; }
.adm-page-header p  { color:#64748b; margin:.2rem 0 0; font-size:.875rem; }

/* ── Notification card ─────────────────────────────── */
.notif-card { background:#fff; border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,.08); overflow:hidden; max-width:760px; }
.notif-card-head {
    background:linear-gradient(135deg,#0f1e32,#1e3a5f);
    color:#fff; padding:1.1rem 1.5rem;
    display:flex; align-items:center; justify-content:space-between;
}
.notif-card-head h4 { margin:0; font-weight:800; font-size:1rem; }
.notif-empty { text-align:center; padding:4rem 2rem; color:#94a3b8; }
.notif-empty i { font-size:3.5rem; display:block; margin-bottom:1rem; opacity:.3; }
.notif-item {
    display:flex; align-items:flex-start; gap:1rem;
    padding:1rem 1.5rem; border-bottom:1px solid #f1f5f9; transition:background .15s;
}
.notif-item:last-child { border-bottom:none; }
.notif-item:hover { background:#f8fafc; }
.notif-ico { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1rem; flex-shrink:0; margin-top:.1rem; }
.notif-body { flex:1; min-width:0; }
.notif-msg { font-size:.88rem; color:#1e293b; line-height:1.5; }
.notif-time { font-size:.74rem; color:#94a3b8; margin-top:.2rem; }

@media(max-width:900px) { .adm-sidebar, .emp-sidebar { display:none; } .adm-main { padding:1rem; } }
</style>

<div class="adm-wrap">

<?php if ($_role === 'admin'): ?>
<!-- ══ ADMIN SIDEBAR ══════════════════════════════════ -->
<aside class="adm-sidebar">
    <div class="sb-brand">
        <span>Management Panel</span>
        <strong><i class="bi bi-train-front-fill me-1"></i> Railway Admin</strong>
    </div>
    <nav>
        <div class="sb-sep">Main</div>
        <a href="admin-dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="reports.php"><i class="bi bi-bar-chart-line"></i> Reports</a>
        <a href="audit-logs.php"><i class="bi bi-journal-text"></i> Audit Logs</a>
        <div class="sb-sep">Operations</div>
        <a href="manage-trains.php"><i class="bi bi-train-front"></i> Trains</a>
        <a href="manage-routes.php"><i class="bi bi-signpost-split"></i> Routes</a>
        <a href="manage-bookings.php"><i class="bi bi-ticket-perforated"></i> Bookings</a>
        <a href="manage-payments.php"><i class="bi bi-credit-card"></i> Payments</a>
        <a href="cargo-shipments.php"><i class="bi bi-box-seam"></i> Cargo</a>
        <a href="operations-hub.php"><i class="bi bi-diagram-3"></i> Operations Hub</a>
        <div class="sb-sep">People</div>
        <a href="manage-users.php"><i class="bi bi-people"></i> Users</a>
        <a href="notifications.php" class="active"><i class="bi bi-bell"></i> Notifications</a>
        <div class="sb-sep">Account</div>
        <a href="profile.php"><i class="bi bi-person-gear"></i> Profile</a>
        <a href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </nav>
    <div class="sb-user">
        <div class="avatar"><?= strtoupper(substr($_thisUser['full_name'] ?? 'A', 0, 1)) ?></div>
        <div class="info">
            <strong><?= htmlspecialchars($_thisUser['full_name'] ?? 'Admin') ?></strong>
            <small>Administrator</small>
        </div>
    </div>
</aside>

<?php elseif ($_role === 'employee'): ?>
<!-- ══ EMPLOYEE SIDEBAR ═══════════════════════════════ -->
<aside class="emp-sidebar">
    <div class="emp-sb-brand">
        <div class="brand-icon"><i class="bi bi-train-front-fill"></i></div>
        <div class="brand-name">Employee Panel</div>
        <div class="brand-role">Operations &amp; Management</div>
    </div>
    <nav>
        <div class="sb-sep">Main</div>
        <a href="employee-dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <div class="sb-sep">Operations</div>
        <a href="my-trains.php"><i class="bi bi-train-front"></i> My Trains</a>
        <a href="check-passengers.php"><i class="bi bi-people"></i> Passengers</a>
        <a href="assign-seats.php"><i class="bi bi-grid-3x3-gap"></i> Seat Management</a>
        <a href="operations-hub.php"><i class="bi bi-diagram-3"></i> Operations Hub</a>
        <div class="sb-sep">Bookings</div>
        <a href="check-passengers.php?view=bookings"><i class="bi bi-journal-check"></i> Today's Bookings</a>
        <div class="sb-sep">Account</div>
        <a href="notifications.php" class="active"><i class="bi bi-bell"></i> Notifications</a>
        <a href="profile.php"><i class="bi bi-person-circle"></i> My Profile</a>
        <a href="logout.php" style="color:rgba(252,165,165,.8)!important;"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </nav>
    <div class="emp-sb-footer">Railway Management System</div>
</aside>
<?php endif; ?>

<!-- ══ MAIN ═════════════════════════════════════════════ -->
<main class="adm-main">

    <div class="adm-page-header">
        <div>
            <h2><i class="bi bi-bell-fill me-2 text-primary"></i>Notifications</h2>
            <p><?= count($notifications) ?> notification<?= count($notifications) !== 1 ? 's' : '' ?> &nbsp;·&nbsp; All marked as read</p>
        </div>
        <?php if ($_role === 'admin'): ?>
        <a href="admin-dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Dashboard
        </a>
        <?php elseif ($_role === 'employee'): ?>
        <a href="employee-dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Dashboard
        </a>
        <?php else: ?>
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Dashboard
        </a>
        <?php endif; ?>
    </div>

    <div class="notif-card">
        <div class="notif-card-head">
            <h4><i class="bi bi-bell-fill me-2"></i>All Notifications</h4>
            <span style="font-size:.8rem;opacity:.65;"><?= count($notifications) ?> total</span>
        </div>

        <?php if (empty($notifications)): ?>
        <div class="notif-empty">
            <i class="bi bi-bell-slash"></i>
            <p class="fw-semibold mb-1">No notifications yet</p>
            <small>You'll see booking updates, cancellations, and alerts here.</small>
        </div>
        <?php else: ?>
        <?php foreach ($notifications as $notif):
            $ts   = strtotime($notif['created_at']);
            $diff = time() - $ts;
            if ($diff < 60)         $ago = 'Just now';
            elseif ($diff < 3600)   $ago = floor($diff/60) . ' min ago';
            elseif ($diff < 86400)  $ago = floor($diff/3600) . ' hr ago';
            elseif ($diff < 604800) $ago = floor($diff/86400) . ' days ago';
            else                    $ago = date('d M Y', $ts);

            $msg       = htmlspecialchars($notif['message']);
            $ico_bg    = '#dbeafe'; $ico_color = '#2563eb'; $icon = 'bi-info-circle-fill';
            if (stripos($msg,'cancel')  !== false) { $ico_bg='#fee2e2'; $ico_color='#dc2626'; $icon='bi-x-circle-fill'; }
            elseif (stripos($msg,'confirm') !== false || stripos($msg,'success') !== false) { $ico_bg='#dcfce7'; $ico_color='#16a34a'; $icon='bi-check-circle-fill'; }
            elseif (stripos($msg,'refund')  !== false) { $ico_bg='#fef3c7'; $ico_color='#d97706'; $icon='bi-cash-coin'; }
            elseif (stripos($msg,'payment') !== false) { $ico_bg='#ede9fe'; $ico_color='#7c3aed'; $icon='bi-credit-card-fill'; }
        ?>
        <div class="notif-item">
            <div class="notif-ico" style="background:<?= $ico_bg ?>;color:<?= $ico_color ?>;"><i class="bi <?= $icon ?>"></i></div>
            <div class="notif-body">
                <div class="notif-msg"><?= $msg ?></div>
                <div class="notif-time"><i class="bi bi-clock me-1"></i><?= $ago ?> &nbsp;·&nbsp; <?= date('d M Y, H:i', $ts) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

</main>
</div><!-- /.adm-wrap -->

<?php require_once 'inc/footer.php'; ?>

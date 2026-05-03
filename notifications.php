<?php
// notifications.php – User Notifications (all roles)
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
$_role   = $_SESSION['role'] ?? 'user';

// ── AJAX actions (mark read / mark unread / delete / mark-all-read) ────────
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $nid    = (int)($_POST['notif_id'] ?? 0);
    switch ($action) {
        case 'mark_read':
            if ($nid > 0) $db->query("UPDATE notifications SET is_read=1 WHERE notification_id={$nid} AND user_id={$user_id}");
            echo json_encode(['ok'=>true]); break;
        case 'mark_unread':
            if ($nid > 0) $db->query("UPDATE notifications SET is_read=0 WHERE notification_id={$nid} AND user_id={$user_id}");
            echo json_encode(['ok'=>true]); break;
        case 'delete':
            if ($nid > 0) $db->query("DELETE FROM notifications WHERE notification_id={$nid} AND user_id={$user_id}");
            echo json_encode(['ok'=>true]); break;
        case 'mark_all_read':
            $db->query("UPDATE notifications SET is_read=1 WHERE user_id={$user_id} AND is_read=0");
            echo json_encode(['ok'=>true]); break;
        default:
            echo json_encode(['ok'=>false]);
    }
    exit();
}

// ── Fetch notifications (unread first, then latest) ────────────────────────
$notifications = $db->select(
    "SELECT * FROM notifications WHERE user_id = {$user_id} ORDER BY is_read ASC, created_at DESC LIMIT 200"
) ?: [];

$unread_count = count(array_filter($notifications, fn($n) => !(int)$n['is_read']));

$_userObj  = new User($db);
$_thisUser = $_userObj->getUserById($user_id);

// ── Notification type → visual style map ──────────────────────────────────
// Uses the DB `type` column if present, falls back to message text detection.
const NOTIF_STYLES = [
    'booking' => ['color'=>'#0284c7','bg'=>'#e0f2fe','icon'=>'bi-ticket-perforated-fill','label'=>'Booking'],
    'confirm' => ['color'=>'#16a34a','bg'=>'#dcfce7','icon'=>'bi-check-circle-fill',     'label'=>'Confirmed'],
    'payment' => ['color'=>'#7c3aed','bg'=>'#ede9fe','icon'=>'bi-credit-card-fill',      'label'=>'Payment'],
    'cancel'  => ['color'=>'#ef4444','bg'=>'#fee2e2','icon'=>'bi-x-circle-fill',         'label'=>'Cancelled'],
    'refund'  => ['color'=>'#d97706','bg'=>'#fef3c7','icon'=>'bi-cash-coin',             'label'=>'Refund'],
    'update'  => ['color'=>'#0891b2','bg'=>'#e0f7ff','icon'=>'bi-arrow-repeat',          'label'=>'Updated'],
    'alert'   => ['color'=>'#ea580c','bg'=>'#fff7ed','icon'=>'bi-exclamation-triangle-fill','label'=>'Alert'],
    'info'    => ['color'=>'#2563eb','bg'=>'#dbeafe','icon'=>'bi-info-circle-fill',      'label'=>'Info'],
];

function notif_classify(array $row): array {
    $db_type = $row['type'] ?? '';
    if ($db_type && isset(NOTIF_STYLES[$db_type])) {
        return ['type' => $db_type] + NOTIF_STYLES[$db_type];
    }
    // fallback: detect from message text
    $m = strtolower($row['message'] ?? '');
    if (str_contains($m,'cancel'))                                return ['type'=>'cancel']  + NOTIF_STYLES['cancel'];
    if (str_contains($m,'refund'))                                return ['type'=>'refund']  + NOTIF_STYLES['refund'];
    if (str_contains($m,'payment')||str_contains($m,'paid'))      return ['type'=>'payment'] + NOTIF_STYLES['payment'];
    if (str_contains($m,'confirm')||str_contains($m,'success'))   return ['type'=>'confirm'] + NOTIF_STYLES['confirm'];
    if (str_contains($m,'update')||str_contains($m,'changed'))    return ['type'=>'update']  + NOTIF_STYLES['update'];
    if (str_contains($m,'book'))                                   return ['type'=>'booking'] + NOTIF_STYLES['booking'];
    if (str_contains($m,'delay')||str_contains($m,'schedule'))    return ['type'=>'alert']   + NOTIF_STYLES['alert'];
    return                                                                ['type'=>'info']    + NOTIF_STYLES['info'];
}

function time_ago(int $ts): string {
    $d = time() - $ts;
    if ($d < 60)      return 'Just now';
    if ($d < 3600)    return floor($d/60).' min ago';
    if ($d < 86400)   return floor($d/3600).' hr ago';
    if ($d < 604800)  return floor($d/86400).' days ago';
    return date('d M Y', $ts);
}

// ── Role-specific tab config ───────────────────────────────────────────────
// Admins and employees see different tab labels/priorities
$is_staff = in_array($_role, ['admin', 'employee'], true);

$hideMainNavbar = true;
$pageTitle = 'Notifications – Railway System';
require_once 'inc/header.php';

$back_url = match($_role) { 'admin'=>'admin-dashboard.php', 'employee'=>'employee-dashboard.php', default=>'dashboard.php' };
?>

<style>
body { background:#f0f4f8; }

/* ── Layout shells ───────────────────────────────────── */
.adm-wrap  { display:flex; min-height:calc(100vh - 64px); }
.adm-main  { flex:1; overflow-x:hidden; background:#f0f4f8; }
.notif-shell { max-width:900px; margin:0 auto; padding:2rem 1rem 4rem; }

/* ── Admin sidebar ───────────────────────────────────── */
.adm-sidebar {
    width:230px; flex-shrink:0;
    background:linear-gradient(180deg,#1a2e4a,#0f1e32);
    color:#c8d6e8; display:flex; flex-direction:column;
    position:sticky; top:64px; height:calc(100vh - 64px); overflow-y:auto;
}
.adm-sidebar .sb-brand { padding:1.2rem 1.4rem .9rem; border-bottom:1px solid rgba(255,255,255,.08); }
.adm-sidebar .sb-brand span  { font-size:.67rem; text-transform:uppercase; letter-spacing:1.5px; opacity:.4; display:block; margin-bottom:.2rem; }
.adm-sidebar .sb-brand strong{ font-size:.95rem; color:#fff; }
.adm-sidebar nav { flex:1; padding:.6rem 0; }
.adm-sidebar nav a {
    display:flex; align-items:center; gap:.7rem;
    padding:.6rem 1.4rem; color:#c8d6e8; text-decoration:none;
    font-size:.85rem; font-weight:500; transition:all .18s;
    border-left:3px solid transparent;
}
.adm-sidebar nav a:hover,.adm-sidebar nav a.active { background:rgba(255,255,255,.07); color:#fff; border-left-color:#3b82f6; }
.adm-sidebar nav a i { width:1rem; text-align:center; }
.adm-sidebar .sb-sep { padding:.6rem 1.4rem .2rem; font-size:.65rem; text-transform:uppercase; letter-spacing:1.5px; opacity:.38; }
.adm-sidebar .sb-user { padding:.9rem 1.4rem; border-top:1px solid rgba(255,255,255,.08); display:flex; align-items:center; gap:.7rem; }
.adm-sidebar .sb-user .avatar { width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#6366f1);color:#fff;font-size:.8rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0; }
.adm-sidebar .sb-user .info small  { display:block; font-size:.67rem; opacity:.45; }
.adm-sidebar .sb-user .info strong { font-size:.78rem; color:#fff; }

/* ── Employee sidebar ────────────────────────────────── */
.emp-sidebar {
    width:230px; flex-shrink:0;
    background:linear-gradient(180deg,#012117,#064e3b);
    display:flex; flex-direction:column;
    position:sticky; top:64px; height:calc(100vh - 64px); overflow-y:auto;
}
.emp-sidebar .emp-sb-brand { padding:1.2rem 1.2rem 1rem; border-bottom:1px solid rgba(255,255,255,.1); }
.emp-sidebar .emp-sb-brand .brand-icon { width:36px;height:36px;border-radius:9px;background:rgba(16,185,129,.2);color:#34d399;display:flex;align-items:center;justify-content:center;font-size:1.1rem;margin-bottom:.45rem; }
.emp-sidebar .emp-sb-brand .brand-name { font-weight:800; font-size:.92rem; color:#fff; }
.emp-sidebar .emp-sb-brand .brand-role { font-size:.68rem; color:rgba(255,255,255,.38); }
.emp-sidebar .sb-sep { font-size:.63rem; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:rgba(255,255,255,.27); padding:.85rem 1.2rem .25rem; }
.emp-sidebar nav a {
    display:flex; align-items:center; gap:.65rem;
    padding:.58rem 1.2rem; color:rgba(255,255,255,.62); text-decoration:none;
    font-size:.85rem; font-weight:500; transition:all .15s;
    border-left:3px solid transparent;
}
.emp-sidebar nav a:hover { background:rgba(255,255,255,.07); color:#fff; border-left-color:rgba(52,211,153,.4); }
.emp-sidebar nav a.active { background:rgba(16,185,129,.15); color:#fff; border-left-color:#10b981; font-weight:600; }
.emp-sidebar nav a i { width:18px; text-align:center; }
.emp-sb-footer { margin-top:auto; padding:.9rem 1.2rem; border-top:1px solid rgba(255,255,255,.08); font-size:.68rem; color:rgba(255,255,255,.28); text-align:center; }

/* ── User "sidebar" – just a back link strip ─────────── */
.user-topstrip {
    background:linear-gradient(135deg,#0f2040,#1a3c6e);
    color:#fff; padding:.85rem 1.5rem; display:flex; align-items:center; gap:1rem;
    flex-wrap:wrap;
}
.user-topstrip .uts-brand { font-weight:800; font-size:1rem; letter-spacing:-.2px; }
.user-topstrip nav { display:flex; gap:.2rem; flex-wrap:wrap; margin-left:auto; }
.user-topstrip nav a {
    color:rgba(255,255,255,.75); text-decoration:none; font-size:.82rem;
    padding:.3rem .8rem; border-radius:999px; transition:all .15s;
}
.user-topstrip nav a:hover,.user-topstrip nav a.active { background:rgba(255,255,255,.15); color:#fff; }

/* ── Top bar ─────────────────────────────────────────── */
.notif-topbar { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:.75rem; margin-bottom:1.5rem; }
.notif-topbar-left h2 { font-size:1.5rem; font-weight:800; color:#0f172a; margin:0; }
.notif-topbar-left p  { font-size:.84rem; color:#64748b; margin:.2rem 0 0; }
.notif-topbar-right   { display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; }

/* ── Filter tabs ─────────────────────────────────────── */
.notif-tabs { display:flex; gap:.4rem; flex-wrap:wrap; margin-bottom:1.2rem; }
.notif-tab { border:none; border-radius:999px; padding:.35rem 1rem; font-size:.8rem; font-weight:600; cursor:pointer; background:#e2e8f0; color:#475569; transition:all .15s; display:flex; align-items:center; gap:.35rem; }
.notif-tab.active,.notif-tab:hover { background:#2563eb; color:#fff; }
.tab-cnt { display:inline-flex; align-items:center; justify-content:center; background:rgba(0,0,0,.12); border-radius:999px; min-width:18px; height:18px; font-size:.66rem; padding:0 4px; }
.notif-tab.active .tab-cnt,.notif-tab:hover .tab-cnt { background:rgba(255,255,255,.3); }

/* ── Card wrapper ────────────────────────────────────── */
.notif-main-card { background:#fff; border-radius:16px; box-shadow:0 4px 24px rgba(0,0,0,.07); overflow:hidden; }

/* ── Empty ───────────────────────────────────────────── */
.notif-empty { text-align:center; padding:4rem 2rem; color:#94a3b8; }
.notif-empty i { font-size:4rem; display:block; margin-bottom:1rem; opacity:.2; }

/* ── Notification row ────────────────────────────────── */
.notif-row {
    display:flex; align-items:flex-start; gap:1rem;
    padding:1rem 1.25rem; border-bottom:1px solid #f1f5f9;
    transition:background .15s; position:relative;
}
.notif-row:last-child { border-bottom:none; }
.notif-row:hover       { background:#f8fafc; }
.notif-row.unread      { background:#f0f7ff; }
.notif-row.unread:hover{ background:#e8f3ff; }
.unread-dot { width:9px; height:9px; border-radius:50%; background:#2563eb; position:absolute; top:1.3rem; right:1.1rem; }
.notif-ico { width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0; margin-top:.1rem; }
.notif-body { flex:1; min-width:0; }
.notif-label { display:inline-block; font-size:.68rem; font-weight:700; border-radius:999px; padding:.1rem .55rem; margin-bottom:.3rem; text-transform:uppercase; letter-spacing:.4px; }
.notif-view-link { font-size:.72rem; font-weight:600; color:#2563eb; text-decoration:none; display:inline-flex; align-items:center; }
.notif-view-link:hover { text-decoration:underline; }
.notif-msg  { font-size:.875rem; color:#1e293b; line-height:1.55; }
.notif-time { font-size:.74rem; color:#94a3b8; margin-top:.3rem; }

/* ── Row hover actions ───────────────────────────────── */
.notif-actions { display:flex; flex-direction:column; gap:.35rem; align-items:flex-end; flex-shrink:0; padding-top:.15rem; opacity:0; transition:opacity .15s; }
.notif-row:hover .notif-actions { opacity:1; }
.notif-act-btn { background:none; border:1px solid #e2e8f0; border-radius:6px; padding:.2rem .6rem; font-size:.72rem; cursor:pointer; display:flex; align-items:center; gap:.3rem; white-space:nowrap; color:#64748b; transition:all .15s; }
.notif-act-btn.read-btn:hover { background:#dbeafe; border-color:#93c5fd; color:#1d4ed8; }
.notif-act-btn.del-btn:hover  { background:#fee2e2; border-color:#fca5a5; color:#dc2626; }

/* ── Footer note ─────────────────────────────────────── */
.notif-footer-note { text-align:center; padding:.85rem; font-size:.75rem; color:#94a3b8; border-top:1px solid #f1f5f9; }

@media(max-width:860px){ .adm-sidebar,.emp-sidebar{ display:none; } }
</style>

<div class="adm-wrap">

<?php if ($_role === 'admin'): ?>
<!-- ADMIN SIDEBAR -->
<aside class="adm-sidebar">
    <div class="sb-brand">
        <span>Management Panel</span>
        <strong><i class="bi bi-train-front-fill me-1"></i>Railway Admin</strong>
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
<!-- EMPLOYEE SIDEBAR -->
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
        <div class="sb-sep">Account</div>
        <a href="notifications.php" class="active"><i class="bi bi-bell"></i> Notifications</a>
        <a href="profile.php"><i class="bi bi-person-circle"></i> My Profile</a>
        <a href="logout.php" style="color:rgba(252,165,165,.8)!important;"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </nav>
    <div class="emp-sb-footer">Railway Management System</div>
</aside>
<?php endif; ?>

<!-- MAIN -->
<main class="adm-main">

<?php if ($_role === 'user'): ?>
<!-- User nav strip (no sidebar) -->
<div class="user-topstrip">
    <span class="uts-brand"><i class="bi bi-train-front-fill me-2"></i>Pakistan Railways</span>
    <nav>
        <a href="dashboard.php"><i class="bi bi-house me-1"></i>Home</a>
        <a href="bookings.php"><i class="bi bi-ticket-perforated me-1"></i>My Bookings</a>
        <a href="notifications.php" class="active"><i class="bi bi-bell me-1"></i>Notifications</a>
        <a href="profile.php"><i class="bi bi-person me-1"></i>Profile</a>
        <a href="logout.php"><i class="bi bi-box-arrow-right me-1"></i>Logout</a>
    </nav>
</div>
<?php endif; ?>

<div class="notif-shell">

    <!-- Page header -->
    <div class="notif-topbar">
        <div class="notif-topbar-left">
            <h2>
                <i class="bi bi-bell-fill me-2" style="color:#2563eb;font-size:1.25rem;vertical-align:middle;"></i>Notifications
                <?php if ($unread_count > 0): ?>
                <span class="badge rounded-pill bg-danger ms-1" style="font-size:.68rem;vertical-align:middle;"><?= $unread_count ?></span>
                <?php endif; ?>
            </h2>
            <p>
                <?= count($notifications) ?> notification<?= count($notifications) !== 1 ? 's' : '' ?>
                &nbsp;·&nbsp;
                <?php if ($unread_count > 0): ?>
                    <span style="color:#2563eb;font-weight:600;"><?= $unread_count ?> unread</span>
                <?php else: ?>
                    <span style="color:#16a34a;font-weight:600;"><i class="bi bi-check-all me-1"></i>All caught up</span>
                <?php endif; ?>
            </p>
        </div>
        <div class="notif-topbar-right">
            <?php if ($unread_count > 0): ?>
            <button class="btn btn-outline-primary btn-sm" onclick="markAllRead()">
                <i class="bi bi-check-all me-1"></i>Mark all read
            </button>
            <?php endif; ?>
            <a href="<?= $back_url ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Dashboard
            </a>
        </div>
    </div>

    <!-- Filter tabs -->
    <?php
    $type_counts = ['all' => count($notifications)];
    foreach ($notifications as $n) {
        $t = notif_classify($n)['type'];
        $type_counts[$t] = ($type_counts[$t] ?? 0) + 1;
    }
    // Tab labels differ slightly for staff (admin/employee) vs regular users
    $tab_defs = $is_staff ? [
        'all'     => ['All',         'bi-grid-fill'],
        'booking' => ['New Bookings','bi-ticket-perforated-fill'],
        'payment' => ['Payments',    'bi-credit-card-fill'],
        'cancel'  => ['Cancelled',   'bi-x-circle-fill'],
        'update'  => ['Updated',     'bi-arrow-repeat'],
        'refund'  => ['Refunds',     'bi-cash-coin'],
        'confirm' => ['Confirmed',   'bi-check-circle-fill'],
        'alert'   => ['Alerts',      'bi-exclamation-triangle-fill'],
        'info'    => ['Info',        'bi-info-circle-fill'],
    ] : [
        'all'     => ['All',       'bi-grid-fill'],
        'booking' => ['Booking',   'bi-ticket-perforated-fill'],
        'confirm' => ['Confirmed', 'bi-check-circle-fill'],
        'payment' => ['Payment',   'bi-credit-card-fill'],
        'update'  => ['Updated',   'bi-arrow-repeat'],
        'refund'  => ['Refund',    'bi-cash-coin'],
        'cancel'  => ['Cancelled', 'bi-x-circle-fill'],
        'alert'   => ['Alerts',    'bi-exclamation-triangle-fill'],
        'info'    => ['Info',      'bi-info-circle-fill'],
    ];
    ?>
    <div class="notif-tabs">
        <?php foreach ($tab_defs as $key => [$label, $icon]): ?>
        <?php $cnt = $type_counts[$key] ?? 0; if ($key !== 'all' && $cnt === 0) continue; ?>
        <button class="notif-tab <?= $key === 'all' ? 'active' : '' ?>"
                data-tab="<?= $key ?>" onclick="switchTab('<?= $key ?>', this)">
            <i class="bi <?= $icon ?>"></i> <?= $label ?>
            <span class="tab-cnt"><?= $cnt ?></span>
        </button>
        <?php endforeach; ?>
    </div>

    <!-- List -->
    <div class="notif-main-card" id="notifList">
        <?php if (empty($notifications)): ?>
        <div class="notif-empty">
            <i class="bi bi-bell-slash"></i>
            <p class="fw-semibold mb-1">No notifications yet</p>
            <p class="mt-1 text-muted" style="font-size:.84rem;">
                <?= $is_staff
                    ? 'Booking activity, payments, and operational alerts will appear here.'
                    : 'Booking confirmations, payment receipts, and travel alerts will show up here.' ?>
            </p>
        </div>
        <?php else: ?>

        <?php foreach ($notifications as $notif):
            $ts      = strtotime($notif['created_at']);
            $cls     = notif_classify($notif);
            $ago     = time_ago($ts);
            $isNew   = !(int)$notif['is_read'];
            $nid     = (int)$notif['notification_id'];
            $rel_id  = (int)($notif['related_id'] ?? 0);
            // Build a "View booking" link if we have a related booking ID
            $view_link = '';
            if ($rel_id > 0) {
                $view_link = $_role === 'user'
                    ? "booking_details.php?id={$rel_id}"
                    : "booking_details.php?id={$rel_id}";
            }
        ?>
        <div class="notif-row <?= $isNew ? 'unread' : '' ?>"
             id="notif-<?= $nid ?>"
             data-type="<?= $cls['type'] ?>">

            <?php if ($isNew): ?><div class="unread-dot" id="dot-<?= $nid ?>"></div><?php endif; ?>

            <div class="notif-ico" style="background:<?= $cls['bg'] ?>;color:<?= $cls['color'] ?>;">
                <i class="bi <?= $cls['icon'] ?>"></i>
            </div>

            <div class="notif-body">
                <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                    <span class="notif-label" style="background:<?= $cls['bg'] ?>;color:<?= $cls['color'] ?>;">
                        <?= $cls['label'] ?>
                    </span>
                    <?php if ($view_link): ?>
                    <a href="<?= htmlspecialchars($view_link) ?>" class="notif-view-link">
                        <i class="bi bi-arrow-up-right-square me-1"></i>View booking
                    </a>
                    <?php endif; ?>
                </div>
                <div class="notif-msg"><?= htmlspecialchars($notif['message']) ?></div>
                <div class="notif-time">
                    <i class="bi bi-clock me-1"></i><?= $ago ?>
                    &nbsp;·&nbsp; <?= date('d M Y, H:i', $ts) ?>
                </div>
            </div>

            <div class="notif-actions">
                <?php if ($isNew): ?>
                <button class="notif-act-btn read-btn" onclick="markRead(<?= $nid ?>)">
                    <i class="bi bi-check2"></i> Mark read
                </button>
                <?php else: ?>
                <button class="notif-act-btn read-btn" onclick="markUnread(<?= $nid ?>)">
                    <i class="bi bi-dot"></i> Mark unread
                </button>
                <?php endif; ?>
                <?php if ($view_link): ?>
                <a href="<?= htmlspecialchars($view_link) ?>" class="notif-act-btn" style="text-decoration:none;">
                    <i class="bi bi-eye"></i> View
                </a>
                <?php endif; ?>
                <button class="notif-act-btn del-btn" onclick="deleteNotif(<?= $nid ?>)">
                    <i class="bi bi-trash3"></i> Delete
                </button>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if (count($notifications) >= 200): ?>
        <div class="notif-footer-note">Showing the latest 200 notifications</div>
        <?php endif; ?>

        <?php endif; ?>
    </div>

    <!-- Empty-filter placeholder -->
    <div id="emptyFilter" class="notif-main-card" style="display:none;">
        <div class="notif-empty">
            <i class="bi bi-funnel"></i>
            <p class="fw-semibold mb-1">No notifications in this category</p>
        </div>
    </div>

</div><!-- /.notif-shell -->
</main>
</div><!-- /.adm-wrap -->

<script>
/* ── Tab filter ───────────────────────────────────── */
function switchTab(type, btn) {
    document.querySelectorAll('.notif-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    const rows = document.querySelectorAll('.notif-row');
    let vis = 0;
    rows.forEach(r => {
        const show = type === 'all' || r.dataset.type === type;
        r.style.display = show ? '' : 'none';
        if (show) vis++;
    });
    document.getElementById('emptyFilter').style.display = vis ? 'none' : '';
    document.getElementById('notifList').style.display   = vis ? ''     : 'none';
}

/* ── AJAX helper ──────────────────────────────────── */
function notifAjax(data, cb) {
    const fd = new FormData();
    Object.entries(data).forEach(([k,v]) => fd.append(k, v));
    fetch('notifications.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    }).then(r => r.json()).then(cb).catch(() => {});
}

/* ── Mark read ────────────────────────────────────── */
function markRead(nid) {
    notifAjax({ action:'mark_read', notif_id:nid }, d => {
        if (!d.ok) return;
        const row = document.getElementById('notif-'+nid);
        const dot = document.getElementById('dot-'+nid);
        if (row) row.classList.remove('unread');
        if (dot) dot.remove();
        const btn = row?.querySelector('.read-btn');
        if (btn) { btn.innerHTML = '<i class="bi bi-dot"></i> Mark unread'; btn.onclick = () => markUnread(nid); }
        adjustBadge(-1);
    });
}

/* ── Mark unread ──────────────────────────────────── */
function markUnread(nid) {
    notifAjax({ action:'mark_unread', notif_id:nid }, d => {
        if (!d.ok) return;
        const row = document.getElementById('notif-'+nid);
        if (row) {
            row.classList.add('unread');
            if (!document.getElementById('dot-'+nid)) {
                const dot = document.createElement('div');
                dot.className = 'unread-dot'; dot.id = 'dot-'+nid;
                row.appendChild(dot);
            }
            const btn = row.querySelector('.read-btn');
            if (btn) { btn.innerHTML = '<i class="bi bi-check2"></i> Mark read'; btn.onclick = () => markRead(nid); }
        }
        adjustBadge(+1);
    });
}

/* ── Delete ───────────────────────────────────────── */
function deleteNotif(nid) {
    notifAjax({ action:'delete', notif_id:nid }, d => {
        if (!d.ok) return;
        const row = document.getElementById('notif-'+nid);
        const wasUnread = row?.classList.contains('unread');
        if (row) {
            row.style.transition = 'opacity .25s,max-height .3s,padding .3s';
            row.style.opacity = '0'; row.style.maxHeight = '0'; row.style.overflow = 'hidden'; row.style.padding = '0';
            setTimeout(() => row.remove(), 310);
        }
        if (wasUnread) adjustBadge(-1);
    });
}

/* ── Mark all read ────────────────────────────────── */
function markAllRead() {
    notifAjax({ action:'mark_all_read' }, d => {
        if (!d.ok) return;
        document.querySelectorAll('.notif-row.unread').forEach(row => {
            row.classList.remove('unread');
            row.querySelector('.unread-dot')?.remove();
            const btn  = row.querySelector('.read-btn');
            const nid2 = row.id.replace('notif-','');
            if (btn) { btn.innerHTML = '<i class="bi bi-dot"></i> Mark unread'; btn.onclick = () => markUnread(nid2); }
        });
        adjustBadge(-9999);
        document.querySelector('button[onclick="markAllRead()"]')?.remove();
    });
}

/* ── Badge / subtitle sync ────────────────────────── */
let _unread = <?= $unread_count ?>;
function adjustBadge(delta) {
    _unread = Math.max(0, delta === -9999 ? 0 : _unread + delta);
    const badge = document.querySelector('h2 .badge');
    if (badge) { badge.textContent = _unread; badge.style.display = _unread ? '' : 'none'; }
    const sub = document.querySelector('.notif-topbar-left p');
    if (sub) {
        const cnt = document.querySelectorAll('.notif-row').length;
        sub.innerHTML = cnt + ' notification' + (cnt!==1?'s':'') + ' &nbsp;·&nbsp; '
            + (_unread > 0
                ? '<span style="color:#2563eb;font-weight:600;">'+_unread+' unread</span>'
                : '<span style="color:#16a34a;font-weight:600;"><i class="bi bi-check-all me-1"></i>All caught up</span>');
    }
}
</script>

<?php require_once 'inc/footer.php'; ?>

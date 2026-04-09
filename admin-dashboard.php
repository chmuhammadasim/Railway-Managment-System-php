<?php
// admin-dashboard.php - Admin Dashboard

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/Booking.php';
require_once 'src/classes/Payment.php';
require_once 'src/classes/Train.php';

if (!User::isLoggedIn() || $_SESSION['role'] != 'admin') {
    header('Location: login.php');
    exit();
}

$db = new Database();
$db->connect();

$user_id  = $_SESSION['user_id'];
$user_obj = new User($db);
$user     = $user_obj->getUserById($user_id);

// ── KPI counts ──────────────────────────────────────────────────────────────
$total_users     = (int)($db->selectRow("SELECT COUNT(*) AS c FROM users WHERE role='user'")['c']    ?? 0);
$total_employees = (int)($db->selectRow("SELECT COUNT(*) AS c FROM users WHERE role='employee'")['c'] ?? 0);
$active_trains   = (int)($db->selectRow("SELECT COUNT(*) AS c FROM trains WHERE status='active'")['c'] ?? 0);
$total_trains    = (int)($db->selectRow("SELECT COUNT(*) AS c FROM trains")['c'] ?? 0);
$total_routes    = (int)($db->selectRow("SELECT COUNT(*) AS c FROM routes WHERE status='scheduled'")['c'] ?? 0);

$confirmed_bookings  = (int)($db->selectRow("SELECT COUNT(*) AS c FROM bookings WHERE booking_status='confirmed'")['c']  ?? 0);
$pending_bookings_n  = (int)($db->selectRow("SELECT COUNT(*) AS c FROM bookings WHERE booking_status='pending'")['c']   ?? 0);
$cancelled_bookings  = (int)($db->selectRow("SELECT COUNT(*) AS c FROM bookings WHERE booking_status='cancelled'")['c'] ?? 0);
$total_bookings      = $confirmed_bookings + $pending_bookings_n + $cancelled_bookings;

$total_revenue       = (float)($db->selectRow("SELECT IFNULL(SUM(amount),0) AS s FROM payments WHERE payment_status='completed'")['s'] ?? 0);
$payment_overview    = $db->selectRow(
    "SELECT
        COUNT(*)                         AS total_txns,
        SUM(payment_status='completed')  AS completed_txns,
        SUM(payment_status='pending')    AS pending_txns,
        SUM(payment_status='failed')     AS failed_txns,
        SUM(payment_status='refunded')   AS refunded_txns
     FROM payments"
);
$total_payment_txns  = (int)($payment_overview['total_txns'] ?? 0);
$completed_payments_n = (int)($payment_overview['completed_txns'] ?? 0);
$pending_payments_n  = (int)($payment_overview['pending_txns'] ?? 0);
$failed_payments_n   = (int)($payment_overview['failed_txns'] ?? 0);
$refunded_payments_n = (int)($payment_overview['refunded_txns'] ?? 0);

$train_status_overview = $db->selectRow(
    "SELECT
        SUM(status='maintenance') AS maintenance_n,
        SUM(status='inactive')    AS inactive_n
     FROM trains"
);
$maintenance_trains_n = (int)($train_status_overview['maintenance_n'] ?? 0);
$inactive_trains_n    = (int)($train_status_overview['inactive_n'] ?? 0);

// Today's activity
$today_bookings = (int)($db->selectRow("SELECT COUNT(*) AS c FROM bookings WHERE DATE(booking_date)=CURDATE()")['c']                            ?? 0);
$today_revenue  = (float)($db->selectRow("SELECT IFNULL(SUM(amount),0) AS s FROM payments WHERE DATE(payment_date)=CURDATE() AND payment_status='completed'")['s'] ?? 0);
$today_users    = (int)($db->selectRow("SELECT COUNT(*) AS c FROM users WHERE DATE(created_at)=CURDATE()")['c']                                 ?? 0);

$today_route_ops = $db->selectRow(
    "SELECT
        COUNT(*)                    AS routes_today,
        SUM(r.status='scheduled')   AS scheduled_today,
        SUM(r.status='completed')   AS completed_today,
        SUM(r.status='cancelled')   AS cancelled_today,
        COALESCE(SUM(t.total_seats), 0)                     AS capacity_today,
        COALESCE(SUM(t.total_seats - r.available_seats), 0) AS committed_seats_today
     FROM routes r
     JOIN trains t ON r.train_id = t.train_id
     WHERE r.journey_date = CURDATE()"
);
$routes_today_n         = (int)($today_route_ops['routes_today'] ?? 0);
$scheduled_routes_today = (int)($today_route_ops['scheduled_today'] ?? 0);
$completed_routes_today = (int)($today_route_ops['completed_today'] ?? 0);
$cancelled_routes_today = (int)($today_route_ops['cancelled_today'] ?? 0);
$today_capacity         = (int)($today_route_ops['capacity_today'] ?? 0);
$today_committed_seats  = (int)($today_route_ops['committed_seats_today'] ?? 0);

$fleet_availability_pct   = $total_trains > 0 ? (int)round(($active_trains / $total_trains) * 100) : 0;
$booking_confirmation_pct = $total_bookings > 0 ? (int)round(($confirmed_bookings / $total_bookings) * 100) : 0;
$payment_clearance_pct    = $total_payment_txns > 0 ? (int)round(($completed_payments_n / $total_payment_txns) * 100) : 0;
$today_load_pct           = $today_capacity > 0 ? (int)round(($today_committed_seats / $today_capacity) * 100) : 0;

// Booking status breakdown for doughnut
$status_counts = [
    'Confirmed'  => $confirmed_bookings,
    'Pending'    => $pending_bookings_n,
    'Cancelled'  => $cancelled_bookings,
];

// Bookings per month – last 6 months
$bpm_rows = $db->select("SELECT DATE_FORMAT(booking_date,'%b %Y') AS lbl, COUNT(*) AS cnt FROM bookings GROUP BY DATE_FORMAT(booking_date,'%Y-%m') ORDER BY DATE_FORMAT(booking_date,'%Y-%m') DESC LIMIT 6");
$bpm_rows = $bpm_rows ? array_reverse($bpm_rows) : [];
$bpm_labels = array_column($bpm_rows, 'lbl');
$bpm_data   = array_map('intval', array_column($bpm_rows, 'cnt'));

// Revenue per month – last 6 months
$rpm_rows = $db->select("SELECT DATE_FORMAT(payment_date,'%b %Y') AS lbl, SUM(amount) AS total FROM payments WHERE payment_status='completed' GROUP BY DATE_FORMAT(payment_date,'%Y-%m') ORDER BY DATE_FORMAT(payment_date,'%Y-%m') DESC LIMIT 6");
$rpm_rows = $rpm_rows ? array_reverse($rpm_rows) : [];
$rpm_labels = array_column($rpm_rows, 'lbl');
$rpm_data   = array_map('floatval', array_column($rpm_rows, 'total'));

// Recent bookings (10)
$recent_bookings = $db->select("SELECT b.booking_id, b.booking_reference, b.booking_status, b.payment_status, b.total_fare, b.journey_date, b.booking_date, u.full_name, r.departure_city, r.arrival_city, t.train_name FROM bookings b JOIN users u ON b.user_id=u.user_id JOIN routes r ON b.route_id=r.route_id JOIN trains t ON r.train_id=t.train_id ORDER BY b.booking_date DESC LIMIT 10");
if (!$recent_bookings) $recent_bookings = [];

// Recent users (8)
$recent_users = $db->select("SELECT user_id, username, full_name, email, role, created_at FROM users WHERE role!='admin' ORDER BY created_at DESC LIMIT 8");
if (!$recent_users) $recent_users = [];

// Recent payments (6)
$recent_payments = $db->select(
    "SELECT p.payment_id, p.amount, p.payment_method, p.transaction_id,
            p.payment_status, p.payment_date, p.created_at,
            b.booking_reference,
            u.full_name
     FROM payments p
     JOIN bookings b ON p.booking_id = b.booking_id
     JOIN users u    ON p.user_id    = u.user_id
     ORDER BY COALESCE(p.payment_date, p.created_at) DESC
     LIMIT 6"
);
if (!$recent_payments) $recent_payments = [];

// Today's live route watch
$today_route_watch = $db->select(
    "SELECT r.route_id, r.departure_city, r.arrival_city, r.departure_time, r.arrival_time,
            r.status, r.available_seats,
            t.train_name, t.train_number, t.total_seats,
            COALESCE(SUM(CASE WHEN b.booking_status='confirmed' THEN b.number_of_seats ELSE 0 END), 0) AS confirmed_seats,
            COALESCE(SUM(CASE WHEN b.booking_status='pending' THEN 1 ELSE 0 END), 0) AS pending_bookings
     FROM routes r
     JOIN trains t ON r.train_id = t.train_id
     LEFT JOIN bookings b ON b.route_id = r.route_id
     WHERE r.journey_date = CURDATE()
     GROUP BY r.route_id, r.departure_city, r.arrival_city, r.departure_time, r.arrival_time,
              r.status, r.available_seats, t.train_name, t.train_number, t.total_seats
     ORDER BY CASE r.status WHEN 'scheduled' THEN 0 WHEN 'completed' THEN 1 ELSE 2 END, r.departure_time ASC
     LIMIT 8"
);
if (!$today_route_watch) $today_route_watch = [];

// Upcoming capacity alerts
$capacity_alerts = $db->select(
    "SELECT r.route_id, r.departure_city, r.arrival_city, r.journey_date, r.departure_time,
            r.available_seats, r.status,
            t.train_name, t.total_seats
     FROM routes r
     JOIN trains t ON r.train_id = t.train_id
     WHERE r.status='scheduled' AND r.journey_date >= CURDATE()
     ORDER BY (r.available_seats / NULLIF(t.total_seats, 0)) ASC, r.journey_date ASC, r.departure_time ASC
     LIMIT 5"
);
if (!$capacity_alerts) $capacity_alerts = [];

// Top 5 trains by revenue
$top_trains = $db->select("SELECT t.train_name, IFNULL(SUM(b.total_fare),0) AS revenue, COUNT(b.booking_id) AS bookings FROM trains t LEFT JOIN routes r ON r.train_id=t.train_id LEFT JOIN bookings b ON b.route_id=r.route_id AND b.payment_status='completed' GROUP BY t.train_id ORDER BY revenue DESC LIMIT 5");
if (!$top_trains) $top_trains = [];
$max_revenue = $top_trains ? max(array_column($top_trains, 'revenue')) : 1;
?>
<?php
$hideMainNavbar = true;
$pageTitle = 'Admin Dashboard';
require_once 'inc/header.php';
?>


<style>
/* ── Dashboard shell ─────────────────────────────── */
.adm-wrap   { display:flex; min-height:calc(100vh - 64px); }

/* ── Sidebar ─────────────────────────────────────── */
.adm-sidebar {
    width: 240px; flex-shrink:0;
    background: linear-gradient(180deg,#1a2e4a 0%,#0f1e32 100%);
    color:#c8d6e8; display:flex; flex-direction:column;
    position:sticky; top:64px; height:calc(100vh - 64px); overflow-y:auto;
}
.adm-sidebar .sb-brand {
    padding:1.4rem 1.5rem 1rem;
    border-bottom:1px solid rgba(255,255,255,.08);
}
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
    background:rgba(255,255,255,.07); color:#fff;
    border-left-color:#3b82f6;
}
.adm-sidebar nav a i { font-size:1rem; width:1.1rem; text-align:center; }
.adm-sidebar .sb-sep { padding:.5rem 1.5rem .25rem; font-size:.68rem; text-transform:uppercase; letter-spacing:1.5px; opacity:.4; margin-top:.5rem; }
.adm-sidebar .sb-user {
    padding:1rem 1.5rem; border-top:1px solid rgba(255,255,255,.08);
    display:flex; align-items:center; gap:.75rem;
}
.adm-sidebar .sb-user .avatar {
    width:34px; height:34px; border-radius:50%;
    background:linear-gradient(135deg,#3b82f6,#6366f1);
    display:flex; align-items:center; justify-content:center;
    font-size:.875rem; font-weight:700; color:#fff; flex-shrink:0;
}
.adm-sidebar .sb-user .info small { display:block; font-size:.7rem; opacity:.5; }
.adm-sidebar .sb-user .info strong { font-size:.8rem; color:#fff; }

/* ── Main content ────────────────────────────────── */
.adm-main { flex:1; padding:2rem; overflow-x:hidden; }

/* ── Page header ─────────────────────────────────── */
.adm-page-header {
    display:flex; justify-content:space-between; align-items:flex-start;
    margin-bottom:1.75rem; flex-wrap:wrap; gap:1rem;
}
.adm-page-header h2 { font-size:1.6rem; font-weight:800; color:#0f172a; margin:0; }
.adm-page-header p  { color:#64748b; margin:.2rem 0 0; font-size:.875rem; }
.adm-date-badge {
    background:#fff; border:1px solid #e2e8f0; border-radius:10px;
    padding:.5rem 1rem; font-size:.8rem; color:#475569;
    display:flex; align-items:center; gap:.4rem; box-shadow:0 1px 3px rgba(0,0,0,.06);
}

/* ── Today's strip ───────────────────────────────── */
.today-strip {
    display:grid; grid-template-columns:repeat(3,1fr); gap:1rem;
    background:linear-gradient(135deg,#1e40af,#3b82f6);
    border-radius:14px; padding:1.2rem 1.5rem; margin-bottom:1.75rem;
    color:#fff;
}
.today-strip .ts-item { text-align:center; }
.today-strip .ts-item .ts-val { font-size:1.75rem; font-weight:800; }
.today-strip .ts-item .ts-lbl { font-size:.75rem; opacity:.8; margin-top:.1rem; }

/* ── KPI cards ───────────────────────────────────── */
.kpi-grid {
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(185px,1fr));
    gap:1rem; margin-bottom:1.75rem;
}
.kpi-card {
    background:#fff; border-radius:14px;
    padding:1.2rem 1.4rem; position:relative; overflow:hidden;
    box-shadow:0 1px 4px rgba(0,0,0,.07); transition:transform .2s,box-shadow .2s;
    cursor:default;
}
.kpi-card:hover { transform:translateY(-3px); box-shadow:0 6px 18px rgba(0,0,0,.1); }
.kpi-card .kpi-icon {
    width:42px; height:42px; border-radius:10px;
    display:flex; align-items:center; justify-content:center;
    font-size:1.2rem; margin-bottom:.75rem;
}
.kpi-card .kpi-val { font-size:1.9rem; font-weight:800; color:#0f172a; line-height:1; }
.kpi-card .kpi-lbl { font-size:.78rem; color:#64748b; margin-top:.3rem; font-weight:500; }
.kpi-card .kpi-link {
    display:inline-flex; align-items:center; gap:.25rem;
    font-size:.73rem; color:#3b82f6; text-decoration:none; margin-top:.6rem;
    font-weight:600;
}
.kpi-card .kpi-link:hover { text-decoration:underline; }
.kpi-card .kpi-bg {
    position:absolute; right:-12px; bottom:-12px;
    font-size:5rem; opacity:.05; pointer-events:none;
}
/* colour variants */
.kpi-blue   .kpi-icon { background:#dbeafe; color:#2563eb; }
.kpi-green  .kpi-icon { background:#dcfce7; color:#16a34a; }
.kpi-amber  .kpi-icon { background:#fef3c7; color:#d97706; }
.kpi-red    .kpi-icon { background:#fee2e2; color:#dc2626; }
.kpi-purple .kpi-icon { background:#ede9fe; color:#7c3aed; }
.kpi-teal   .kpi-icon { background:#ccfbf1; color:#0d9488; }

/* ── Charts row ──────────────────────────────────── */
.chart-row { display:grid; grid-template-columns:2fr 1fr; gap:1rem; margin-bottom:1.75rem; }
.chart-card {
    background:#fff; border-radius:14px; padding:1.4rem;
    box-shadow:0 1px 4px rgba(0,0,0,.07);
}
.chart-card h4 { font-size:.9rem; font-weight:700; color:#0f172a; margin:0 0 1rem; }

/* ── Pulse cards ─────────────────────────────────── */
.pulse-grid {
    display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; margin-bottom:1.75rem;
}
.pulse-card {
    background:#fff; border-radius:14px; padding:1.1rem 1.2rem;
    box-shadow:0 1px 4px rgba(0,0,0,.07);
}
.pulse-card .pc-head {
    display:flex; justify-content:space-between; align-items:flex-start; gap:.75rem; margin-bottom:.85rem;
}
.pulse-card .pc-head i { font-size:1.2rem; color:#3b82f6; }
.pulse-card .pc-title {
    font-size:.72rem; text-transform:uppercase; letter-spacing:.08em; color:#64748b; font-weight:700;
}
.pulse-card .pc-value { font-size:1.5rem; font-weight:800; color:#0f172a; line-height:1.1; }
.pulse-card .pc-meta {
    display:flex; justify-content:space-between; align-items:center; gap:.75rem;
    margin-top:.55rem; font-size:.75rem; color:#64748b;
}

/* ── Operations row ──────────────────────────────── */
.ops-row { display:grid; grid-template-columns:1.4fr 1fr; gap:1rem; margin-bottom:1.75rem; }
.stack-col { display:flex; flex-direction:column; gap:1rem; }
.compact-list { display:flex; flex-direction:column; gap:.9rem; }
.compact-item { padding-bottom:.9rem; border-bottom:1px solid #f1f5f9; }
.compact-item:last-child { padding-bottom:0; border-bottom:none; }
.meta-line { font-size:.75rem; color:#64748b; }
.tiny-action {
    font-size:.74rem; color:#3b82f6; text-decoration:none; font-weight:600;
}
.tiny-action:hover { text-decoration:underline; }

/* ── Search card ─────────────────────────────────── */
.lookup-note { font-size:.74rem; color:#64748b; margin-top:.7rem; }

/* ── Content row ─────────────────────────────────── */
.content-row { display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1.75rem; }

/* ── Section cards ───────────────────────────────── */
.dash-section {
    background:#fff; border-radius:14px; padding:1.4rem;
    box-shadow:0 1px 4px rgba(0,0,0,.07);
}
.dash-section-header {
    display:flex; justify-content:space-between; align-items:center;
    margin-bottom:1rem; padding-bottom:.75rem; border-bottom:1px solid #f1f5f9;
}
.dash-section-header h4 { font-size:.9rem; font-weight:700; color:#0f172a; margin:0; }
.dash-section-header a { font-size:.78rem; color:#3b82f6; text-decoration:none; font-weight:600; }
.dash-section-header a:hover { text-decoration:underline; }

/* ── Alert badges ────────────────────────────────── */
.alert-strip {
    display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1.75rem;
}
.alert-card {
    border-radius:12px; padding:1rem 1.2rem;
    display:flex; align-items:center; gap:1rem;
}
.alert-card.warn  { background:#fffbeb; border:1px solid #fde68a; }
.alert-card.info  { background:#eff6ff; border:1px solid #bfdbfe; }
.alert-card .ac-icon {
    width:40px; height:40px; border-radius:10px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center; font-size:1.2rem;
}
.warn .ac-icon { background:#fef3c7; color:#d97706; }
.info .ac-icon { background:#dbeafe; color:#2563eb; }
.alert-card .ac-body .ac-num { font-size:1.4rem; font-weight:800; }
.alert-card .ac-body .ac-lbl { font-size:.78rem; color:#64748b; }
.warn .ac-body .ac-num { color:#92400e; }
.info .ac-body .ac-num { color:#1e40af; }
.alert-card a.ac-btn {
    margin-left:auto; font-size:.75rem; padding:.4rem .9rem;
    border-radius:8px; text-decoration:none; font-weight:600; white-space:nowrap;
}
.warn a.ac-btn { background:#fef3c7; color:#92400e; border:1px solid #fde68a; }
.info a.ac-btn { background:#dbeafe; color:#1e40af; border:1px solid #bfdbfe; }
.alert-card a.ac-btn:hover { opacity:.8; }

/* ── Quick actions ───────────────────────────────── */
.qa-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:.75rem; }
.qa-btn {
    display:flex; flex-direction:column; align-items:center; gap:.5rem;
    background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px;
    padding:.9rem .5rem; text-decoration:none; color:#334155;
    font-size:.78rem; font-weight:600; transition:all .2s; text-align:center;
}
.qa-btn i { font-size:1.3rem; color:#3b82f6; }
.qa-btn:hover { background:#eff6ff; border-color:#93c5fd; color:#1e40af; }
.qa-btn:hover i { color:#1d4ed8; }

/* ── Mini table ──────────────────────────────────── */
.mini-table { width:100%; border-collapse:collapse; font-size:.82rem; }
.mini-table th { padding:.5rem .6rem; color:#64748b; font-weight:600; text-align:left; border-bottom:2px solid #f1f5f9; background:transparent; font-size:.75rem; text-transform:uppercase; letter-spacing:.4px; }
.mini-table td { padding:.55rem .6rem; border-bottom:1px solid #f8fafc; color:#334155; }
.mini-table tbody tr:hover { background:#f8fafc; }
.mini-table tbody tr:last-child td { border-bottom:none; }
.mini-table .ref { font-family:monospace; font-size:.77rem; color:#6366f1; }

/* ── Progress bar ────────────────────────────────── */
.prog-bar { background:#f1f5f9; border-radius:99px; height:6px; overflow:hidden; }
.prog-bar-fill { height:100%; border-radius:99px; background:linear-gradient(90deg,#3b82f6,#6366f1); transition:width .6s ease; }

/* ── Booking status pill ─────────────────────────── */
.pill { display:inline-block; padding:.2em .65em; border-radius:999px; font-size:.72rem; font-weight:600; }
.pill-confirmed { background:#dcfce7; color:#166534; }
.pill-pending   { background:#fef3c7; color:#92400e; }
.pill-cancelled { background:#fee2e2; color:#991b1b; }
.pill-completed { background:#dbeafe; color:#1e40af; }
.pill-refunded  { background:#ede9fe; color:#6b21a8; }
.pill-user      { background:#e0f2fe; color:#0369a1; }
.pill-employee  { background:#f0fdf4; color:#166534; }

/* ── Responsive ──────────────────────────────────── */
@media (max-width:1100px) {
    .chart-row   { grid-template-columns:1fr; }
    .ops-row     { grid-template-columns:1fr; }
    .content-row { grid-template-columns:1fr; }
}
@media (max-width:860px) {
    .adm-sidebar { display:none; position:fixed; z-index:1050; }
    .adm-sidebar.open { display:flex !important; flex-direction:column; }
    .alert-strip { grid-template-columns:1fr; }
    .today-strip { grid-template-columns:1fr 1fr; }
    .pulse-grid  { grid-template-columns:1fr 1fr; }
    .kpi-grid    { grid-template-columns:repeat(2,1fr); }
    .qa-grid     { grid-template-columns:repeat(2,1fr); }
}
@media (max-width:560px) {
    .pulse-grid { grid-template-columns:1fr; }
}
.adm-mobile-bar {
    display:none; background:linear-gradient(90deg,#1a2e4a,#0f1e32); color:#fff;
    padding:.6rem 1rem; align-items:center; gap:.75rem;
    position:sticky; top:0; z-index:100;
}
@media (max-width:860px) { .adm-mobile-bar { display:flex; } }
</style>

<div class="adm-mobile-bar">
    <button id="admSidebarToggle" class="btn btn-sm btn-outline-light" style="border-color:rgba(255,255,255,.4);">
        <i class="bi bi-list"></i>
    </button>
    <span class="fw-bold"><i class="bi bi-speedometer2 me-1"></i>Admin Panel</span>
    <?php if ($pending_bookings_n + $pending_payments_n > 0): ?>
    <span class="ms-auto badge" style="background:#ef4444;"><?= $pending_bookings_n + $pending_payments_n ?> pending</span>
    <?php endif; ?>
</div>

<div class="adm-wrap">

    <!-- ══ SIDEBAR ══════════════════════════════════════════ -->
    <aside class="adm-sidebar" id="admSidebar">
        <div class="sb-brand">
            <span>Management Panel</span>
            <strong>🚂 Railway Admin</strong>
        </div>

        <nav>
            <div class="sb-sep">Main</div>
            <a href="admin-dashboard.php" class="active"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a href="reports.php"><i class="bi bi-bar-chart-line"></i> Reports</a>

            <div class="sb-sep">Operations</div>
            <a href="manage-trains.php"><i class="bi bi-train-front"></i> Trains</a>
            <a href="manage-routes.php"><i class="bi bi-map"></i> Routes</a>
            <a href="operations-hub.php?tab=stations"><i class="bi bi-building"></i> Stations</a>
            <a href="booking-admin.php" style="justify-content:space-between;">
                <span><i class="bi bi-ticket-perforated"></i> Bookings</span>
                <?php if ($pending_bookings_n > 0): ?>
                <span style="background:#f59e0b;color:#fff;border-radius:999px;padding:.1em .45em;font-size:.67rem;font-weight:700;"><?= $pending_bookings_n ?></span>
                <?php endif; ?>
            </a>
            <a href="cargo-shipments.php"><i class="bi bi-box-seam"></i> Cargo</a>
            <a href="operations-hub.php"><i class="bi bi-diagram-3"></i> Operations Hub</a>

            <div class="sb-sep">Finance</div>
            <a href="manage-payments.php" style="justify-content:space-between;">
                <span><i class="bi bi-credit-card"></i> Payments</span>
                <?php if ($pending_payments_n > 0): ?>
                <span style="background:#ef4444;color:#fff;border-radius:999px;padding:.1em .45em;font-size:.67rem;font-weight:700;"><?= $pending_payments_n ?></span>
                <?php endif; ?>
            </a>

            <div class="sb-sep">Users</div>
            <a href="manage-users.php"><i class="bi bi-people"></i> Users</a>

            <div class="sb-sep">System</div>
            <a href="audit-logs.php"><i class="bi bi-clock-history"></i> Audit Logs</a>
            <a href="notifications.php"><i class="bi bi-bell"></i> Notifications</a>
            <a href="profile.php"><i class="bi bi-person-gear"></i> My Profile</a>
            <a href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
        </nav>

        <div class="sb-user">
            <div class="avatar"><?= strtoupper(substr($user['full_name'] ?? 'A', 0, 1)) ?></div>
            <div class="info">
                <strong><?= htmlspecialchars($user['full_name'] ?? 'Admin') ?></strong>
                <small>Administrator</small>
            </div>
        </div>
    </aside>

    <!-- ══ MAIN CONTENT ══════════════════════════════════════ -->
    <main class="adm-main">

        <!-- Page Header -->
        <div class="adm-page-header">
            <div>
                <h2>Good <?= (int)date('H') < 12 ? 'Morning' : ((int)date('H') < 17 ? 'Afternoon' : 'Evening') ?>, <?= htmlspecialchars(explode(' ', $user['full_name'] ?? 'Admin')[0]) ?> 👋</h2>
                <p>Here's what's happening with your railway system today.</p>
            </div>
            <div class="adm-date-badge">
                <i class="bi bi-calendar3"></i>
                <?= date('l, d F Y') ?>
            </div>
        </div>

        <!-- Today's Activity Strip -->
        <div class="today-strip">
            <div class="ts-item">
                <div class="ts-val"><?= $today_bookings ?></div>
                <div class="ts-lbl">Bookings Today</div>
            </div>
            <div class="ts-item">
                <div class="ts-val">Rs.&nbsp;<?= number_format($today_revenue, 0) ?></div>
                <div class="ts-lbl">Revenue Today</div>
            </div>
            <div class="ts-item">
                <div class="ts-val"><?= $today_users ?></div>
                <div class="ts-lbl">New Users Today</div>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card kpi-blue">
                <div class="kpi-icon"><i class="bi bi-people-fill"></i></div>
                <div class="kpi-val"><?= number_format($total_users) ?></div>
                <div class="kpi-lbl">Registered Users</div>
                <a class="kpi-link" href="manage-users.php">Manage <i class="bi bi-arrow-right"></i></a>
                <div class="kpi-bg"><i class="bi bi-people-fill"></i></div>
            </div>
            <div class="kpi-card kpi-teal">
                <div class="kpi-icon"><i class="bi bi-person-badge-fill"></i></div>
                <div class="kpi-val"><?= number_format($total_employees) ?></div>
                <div class="kpi-lbl">Employees</div>
                <a class="kpi-link" href="manage-users.php">Manage <i class="bi bi-arrow-right"></i></a>
                <div class="kpi-bg"><i class="bi bi-person-badge-fill"></i></div>
            </div>
            <div class="kpi-card kpi-purple">
                <div class="kpi-icon"><i class="bi bi-train-front-fill"></i></div>
                <div class="kpi-val"><?= number_format($active_trains) ?></div>
                <div class="kpi-lbl">Active Trains</div>
                <a class="kpi-link" href="manage-trains.php">Manage <i class="bi bi-arrow-right"></i></a>
                <div class="kpi-bg"><i class="bi bi-train-front-fill"></i></div>
            </div>
            <div class="kpi-card kpi-teal">
                <div class="kpi-icon"><i class="bi bi-map-fill"></i></div>
                <div class="kpi-val"><?= number_format($total_routes) ?></div>
                <div class="kpi-lbl">Scheduled Routes</div>
                <a class="kpi-link" href="manage-routes.php">Manage <i class="bi bi-arrow-right"></i></a>
                <div class="kpi-bg"><i class="bi bi-map-fill"></i></div>
            </div>
            <div class="kpi-card kpi-green">
                <div class="kpi-icon"><i class="bi bi-ticket-perforated-fill"></i></div>
                <div class="kpi-val"><?= number_format($total_bookings) ?></div>
                <div class="kpi-lbl">Total Bookings</div>
                <a class="kpi-link" href="manage-bookings.php">View all <i class="bi bi-arrow-right"></i></a>
                <div class="kpi-bg"><i class="bi bi-ticket-perforated-fill"></i></div>
            </div>
            <div class="kpi-card kpi-amber">
                <div class="kpi-icon"><i class="bi bi-currency-rupee"></i></div>
                <div class="kpi-val" style="font-size:1.35rem;">Rs.&nbsp;<?= number_format($total_revenue, 0) ?></div>
                <div class="kpi-lbl">Total Revenue</div>
                <a class="kpi-link" href="reports.php">Reports <i class="bi bi-arrow-right"></i></a>
                <div class="kpi-bg"><i class="bi bi-currency-rupee"></i></div>
            </div>
        </div>

        <!-- Alert Strip -->
        <div class="alert-strip">
            <div class="alert-card warn">
                <div class="ac-icon"><i class="bi bi-clock-history"></i></div>
                <div class="ac-body">
                    <div class="ac-num"><?= $pending_bookings_n ?></div>
                    <div class="ac-lbl">Pending Bookings</div>
                </div>
                <a href="manage-bookings.php?status=pending" class="ac-btn">Review</a>
            </div>
            <div class="alert-card info">
                <div class="ac-icon"><i class="bi bi-credit-card"></i></div>
                <div class="ac-body">
                    <div class="ac-num"><?= $pending_payments_n ?></div>
                    <div class="ac-lbl">Pending Payments</div>
                </div>
                <a href="manage-payments.php?status=pending" class="ac-btn">View</a>
            </div>
        </div>

        <!-- Operational Pulse -->
        <div class="pulse-grid">
            <div class="pulse-card">
                <div class="pc-head">
                    <div>
                        <div class="pc-title">Fleet Availability</div>
                        <div class="pc-value"><?= $fleet_availability_pct ?>%</div>
                    </div>
                    <i class="bi bi-train-front"></i>
                </div>
                <div class="prog-bar"><div class="prog-bar-fill" style="width:<?= $fleet_availability_pct ?>%;"></div></div>
                <div class="pc-meta">
                    <span><?= $active_trains ?> active of <?= $total_trains ?></span>
                    <a class="tiny-action" href="manage-trains.php">Open</a>
                </div>
            </div>
            <div class="pulse-card">
                <div class="pc-head">
                    <div>
                        <div class="pc-title">Booking Confirmation</div>
                        <div class="pc-value"><?= $booking_confirmation_pct ?>%</div>
                    </div>
                    <i class="bi bi-patch-check"></i>
                </div>
                <div class="prog-bar"><div class="prog-bar-fill" style="width:<?= $booking_confirmation_pct ?>%; background:linear-gradient(90deg,#22c55e,#16a34a);"></div></div>
                <div class="pc-meta">
                    <span><?= $confirmed_bookings ?> confirmed of <?= $total_bookings ?></span>
                    <a class="tiny-action" href="manage-bookings.php?status=pending">Review queue</a>
                </div>
            </div>
            <div class="pulse-card">
                <div class="pc-head">
                    <div>
                        <div class="pc-title">Payment Clearance</div>
                        <div class="pc-value"><?= $payment_clearance_pct ?>%</div>
                    </div>
                    <i class="bi bi-wallet2"></i>
                </div>
                <div class="prog-bar"><div class="prog-bar-fill" style="width:<?= $payment_clearance_pct ?>%; background:linear-gradient(90deg,#f59e0b,#d97706);"></div></div>
                <div class="pc-meta">
                    <span><?= $completed_payments_n ?> settled of <?= $total_payment_txns ?></span>
                    <a class="tiny-action" href="manage-payments.php">Open</a>
                </div>
            </div>
            <div class="pulse-card">
                <div class="pc-head">
                    <div>
                        <div class="pc-title">Today Seat Load</div>
                        <div class="pc-value"><?= $today_load_pct ?>%</div>
                    </div>
                    <i class="bi bi-bar-chart-steps"></i>
                </div>
                <div class="prog-bar"><div class="prog-bar-fill" style="width:<?= $today_load_pct ?>%; background:linear-gradient(90deg,#8b5cf6,#6366f1);"></div></div>
                <div class="pc-meta">
                    <span><?= $today_committed_seats ?> of <?= $today_capacity ?> seats committed</span>
                    <a class="tiny-action" href="manage-routes.php">Open</a>
                </div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="chart-row">
            <!-- Bookings + Revenue combined -->
            <div class="chart-card">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                    <h4 style="margin:0;">Bookings &amp; Revenue <span style="font-size:.75rem;font-weight:400;color:#94a3b8;">(last 6 months)</span></h4>
                    <a href="reports.php" style="font-size:.75rem;color:#3b82f6;font-weight:600;text-decoration:none;">Full Report →</a>
                </div>
                <canvas id="combinedChart" height="100"></canvas>
            </div>

            <!-- Booking Status Doughnut -->
            <div class="chart-card" style="display:flex;flex-direction:column;align-items:center;">
                <h4 style="align-self:flex-start;">Booking Status</h4>
                <canvas id="statusChart" style="max-width:200px;max-height:200px;"></canvas>
                <div style="margin-top:1rem;width:100%;">
                    <?php
                    $status_colors = ['Confirmed'=>'#22c55e','Pending'=>'#f59e0b','Cancelled'=>'#ef4444'];
                    foreach ($status_counts as $lbl => $val):
                        $pct = $total_bookings > 0 ? round($val/$total_bookings*100) : 0;
                    ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem;font-size:.8rem;">
                        <span style="display:flex;align-items:center;gap:.4rem;">
                            <span style="width:10px;height:10px;border-radius:50%;background:<?= $status_colors[$lbl] ?>;display:inline-block;"></span>
                            <?= $lbl ?>
                        </span>
                        <span style="font-weight:700;"><?= $val ?> <span style="color:#94a3b8;">(<?= $pct ?>%)</span></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Operations Row -->
        <div class="ops-row">

            <div class="dash-section">
                <div class="dash-section-header">
                    <h4><i class="bi bi-broadcast-pin me-1" style="color:#3b82f6;"></i> Today Route Watch</h4>
                    <a href="manage-routes.php">Manage routes →</a>
                </div>

                <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem;">
                    <span class="pill pill-completed"><?= $routes_today_n ?> routes today</span>
                    <span class="pill pill-confirmed"><?= $scheduled_routes_today ?> scheduled</span>
                    <span class="pill pill-completed"><?= $completed_routes_today ?> completed</span>
                    <span class="pill pill-cancelled"><?= $cancelled_routes_today ?> cancelled</span>
                </div>

                <?php if ($today_route_watch): ?>
                <div style="overflow-x:auto;">
                    <table class="mini-table">
                        <thead>
                            <tr>
                                <th>Train</th>
                                <th>Route</th>
                                <th>Departure</th>
                                <th>Load</th>
                                <th>Available</th>
                                <th>Pending</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($today_route_watch as $rt): ?>
                            <?php
                            $route_load_pct = (int)($rt['total_seats'] > 0 ? round(($rt['confirmed_seats'] / $rt['total_seats']) * 100) : 0);
                            ?>
                            <tr>
                                <td>
                                    <div style="font-weight:600;"><?= htmlspecialchars($rt['train_name']) ?></div>
                                    <div class="meta-line"><?= htmlspecialchars($rt['train_number']) ?></div>
                                </td>
                                <td style="font-size:.78rem;"><?= htmlspecialchars($rt['departure_city']) ?> → <?= htmlspecialchars($rt['arrival_city']) ?></td>
                                <td style="white-space:nowrap;">
                                    <?= date('H:i', strtotime($rt['departure_time'])) ?>
                                    <div class="meta-line">Arrive <?= date('H:i', strtotime($rt['arrival_time'])) ?></div>
                                </td>
                                <td style="min-width:120px;">
                                    <div style="display:flex;justify-content:space-between;font-size:.74rem;margin-bottom:.2rem;">
                                        <span><?= $rt['confirmed_seats'] ?> booked</span>
                                        <span><?= $route_load_pct ?>%</span>
                                    </div>
                                    <div class="prog-bar"><div class="prog-bar-fill" style="width:<?= $route_load_pct ?>%; background:<?= $route_load_pct >= 80 ? 'linear-gradient(90deg,#ef4444,#dc2626)' : 'linear-gradient(90deg,#3b82f6,#6366f1)' ?>;"></div></div>
                                </td>
                                <td><?= (int)$rt['available_seats'] ?></td>
                                <td><?= (int)$rt['pending_bookings'] ?></td>
                                <td><span class="pill pill-<?= strtolower($rt['status']) ?>"><?= ucfirst($rt['status']) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <p style="color:#94a3b8;font-size:.85rem;text-align:center;padding:1rem 0;">No routes scheduled for today.</p>
                <?php endif; ?>
            </div>

            <div class="stack-col">
                <div class="dash-section">
                    <div class="dash-section-header">
                        <h4><i class="bi bi-exclamation-triangle me-1" style="color:#f59e0b;"></i> Capacity Alerts</h4>
                        <a href="manage-routes.php">Routes →</a>
                    </div>
                    <?php if ($capacity_alerts): ?>
                    <div class="compact-list">
                        <?php foreach ($capacity_alerts as $alert): ?>
                        <?php
                        $free_pct = (int)($alert['total_seats'] > 0 ? round(($alert['available_seats'] / $alert['total_seats']) * 100) : 0);
                        $load_pct = 100 - $free_pct;
                        $pill_class = $free_pct <= 15 ? 'pill-cancelled' : ($free_pct <= 35 ? 'pill-pending' : 'pill-completed');
                        ?>
                        <div class="compact-item">
                            <div style="display:flex;justify-content:space-between;gap:.75rem;align-items:flex-start;">
                                <div>
                                    <div style="font-weight:700;color:#0f172a;"><?= htmlspecialchars($alert['train_name']) ?></div>
                                    <div class="meta-line"><?= htmlspecialchars($alert['departure_city']) ?> → <?= htmlspecialchars($alert['arrival_city']) ?></div>
                                </div>
                                <span class="pill <?= $pill_class ?>"><?= (int)$alert['available_seats'] ?> free</span>
                            </div>
                            <div class="meta-line" style="margin:.35rem 0 .45rem;">
                                <?= date('d M Y', strtotime($alert['journey_date'])) ?> at <?= date('H:i', strtotime($alert['departure_time'])) ?>
                            </div>
                            <div class="prog-bar"><div class="prog-bar-fill" style="width:<?= $load_pct ?>%; background:<?= $load_pct >= 85 ? 'linear-gradient(90deg,#ef4444,#dc2626)' : 'linear-gradient(90deg,#f59e0b,#d97706)' ?>;"></div></div>
                            <div class="pc-meta">
                                <span><?= $free_pct ?>% seats still free</span>
                                <a class="tiny-action" href="manage-routes.php">Review</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                        <p style="color:#94a3b8;font-size:.85rem;text-align:center;padding:1rem 0;">No capacity alerts right now.</p>
                    <?php endif; ?>
                </div>

                <div class="dash-section">
                    <div class="dash-section-header">
                        <h4><i class="bi bi-cash-coin me-1" style="color:#16a34a;"></i> Recent Payments</h4>
                        <a href="manage-payments.php">Payments →</a>
                    </div>
                    <?php if ($recent_payments): ?>
                    <div style="overflow-x:auto;">
                        <table class="mini-table">
                            <thead>
                                <tr>
                                    <th>Booking</th>
                                    <th>Passenger</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_payments as $pay): ?>
                                <tr>
                                    <td><span class="ref"><?= htmlspecialchars($pay['booking_reference']) ?></span></td>
                                    <td><?= htmlspecialchars($pay['full_name']) ?></td>
                                    <td style="white-space:nowrap;">Rs.&nbsp;<?= number_format($pay['amount'], 0) ?></td>
                                    <td><span class="pill pill-<?= strtolower($pay['payment_status']) ?>"><?= ucfirst($pay['payment_status']) ?></span></td>
                                    <td>
                                        <a class="tiny-action" href="manage-payments.php?q=<?= urlencode($pay['booking_reference']) ?>">Open</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                        <p style="color:#94a3b8;font-size:.85rem;text-align:center;padding:1rem 0;">No payment activity recorded yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Content Row: Recent Bookings + Quick Actions -->
        <div class="content-row">

            <!-- Recent Bookings -->
            <div class="dash-section">
                <div class="dash-section-header">
                    <h4><i class="bi bi-ticket-perforated me-1" style="color:#3b82f6;"></i> Recent Bookings</h4>
                    <a href="manage-bookings.php">View all →</a>
                </div>
                <?php if ($recent_bookings): ?>
                <div style="overflow-x:auto;">
                <table class="mini-table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Passenger</th>
                            <th>Route</th>
                            <th>Date</th>
                            <th>Fare</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_bookings as $bk): ?>
                        <tr>
                            <td><span class="ref"><?= htmlspecialchars($bk['booking_reference']) ?></span></td>
                            <td><?= htmlspecialchars($bk['full_name']) ?></td>
                            <td style="font-size:.75rem;">
                                <?= htmlspecialchars($bk['departure_city']) ?> → <?= htmlspecialchars($bk['arrival_city']) ?>
                            </td>
                            <td style="font-size:.75rem;white-space:nowrap;"><?= date('d M Y', strtotime($bk['journey_date'])) ?></td>
                            <td style="white-space:nowrap;">Rs.&nbsp;<?= number_format($bk['total_fare'], 0) ?></td>
                            <td>
                                <span class="pill pill-<?= strtolower($bk['booking_status']) ?>">
                                    <?= ucfirst($bk['booking_status']) ?>
                                </span>
                            </td>
                            <td>
                                <a class="tiny-action" href="booking_details.php?id=<?= $bk['booking_id'] ?>">View</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php else: ?>
                    <p style="color:#94a3b8;font-size:.85rem;text-align:center;padding:1rem 0;">No bookings yet.</p>
                <?php endif; ?>
            </div>

            <!-- Right column: Quick Actions + Top Trains -->
            <div style="display:flex;flex-direction:column;gap:1rem;">

                <!-- Quick Actions -->
                <div class="dash-section">
                    <div class="dash-section-header">
                        <h4><i class="bi bi-lightning-charge me-1" style="color:#f59e0b;"></i> Quick Actions</h4>
                    </div>
                    <div class="qa-grid">
                        <a href="manage-bookings.php" class="qa-btn">
                            <i class="bi bi-ticket-perforated-fill"></i>Bookings
                        </a>
                        <a href="manage-bookings.php?status=pending" class="qa-btn">
                            <i class="bi bi-hourglass-split"></i>Pending Queue
                        </a>
                        <a href="manage-payments.php" class="qa-btn">
                            <i class="bi bi-cash-stack"></i>Payments
                        </a>
                        <a href="manage-trains.php" class="qa-btn">
                            <i class="bi bi-train-front-fill"></i>Trains
                        </a>
                        <a href="manage-routes.php" class="qa-btn">
                            <i class="bi bi-map-fill"></i>Routes
                        </a>
                        <a href="operations-hub.php?tab=stations" class="qa-btn">
                            <i class="bi bi-building-fill" style="color:#0f766e;"></i>Stations
                        </a>
                        <a href="operations-hub.php" class="qa-btn">
                            <i class="bi bi-diagram-3-fill" style="color:#0369a1;"></i>Operations Hub
                        </a>
                        <a href="manage-users.php" class="qa-btn">
                            <i class="bi bi-people-fill"></i>Users
                        </a>
                        <a href="manage-users.php?action=add&role=employee" class="qa-btn">
                            <i class="bi bi-person-plus-fill" style="color:#0d9488;"></i>New Employee
                        </a>
                        <a href="reports.php" class="qa-btn">
                            <i class="bi bi-bar-chart-line-fill" style="color:#7c3aed;"></i>Reports
                        </a>
                        <a href="notifications.php" class="qa-btn">
                            <i class="bi bi-bell-fill" style="color:#dc2626;"></i>Notifications
                        </a>
                    </div>
                </div>

                <div class="dash-section">
                    <div class="dash-section-header">
                        <h4><i class="bi bi-search me-1" style="color:#3b82f6;"></i> Quick Lookup</h4>
                    </div>
                    <form id="adminLookupForm" class="row g-2">
                        <div class="col-12">
                            <select id="lookupTarget" class="form-select form-select-sm">
                                <option value="manage-bookings.php">Booking reference</option>
                                <option value="manage-payments.php">Payment / transaction</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <input id="lookupQuery" type="text" class="form-control form-control-sm" placeholder="Enter reference, name, or transaction ID">
                        </div>
                        <div class="col-12 d-grid">
                            <button type="submit" class="btn btn-primary btn-sm">Search Now</button>
                        </div>
                    </form>
                    <div class="lookup-note">
                        Jump straight into booking or payment records without leaving the dashboard first.
                    </div>
                </div>

                <!-- Top Trains by Revenue -->
                <div class="dash-section">
                    <div class="dash-section-header">
                        <h4><i class="bi bi-trophy me-1" style="color:#f59e0b;"></i> Top Trains by Revenue</h4>
                        <a href="reports.php?tab=income_trains">Details →</a>
                    </div>
                    <?php foreach ($top_trains as $tt):
                        $pct = $max_revenue > 0 ? ($tt['revenue'] / $max_revenue * 100) : 0;
                    ?>
                    <div style="margin-bottom:.85rem;">
                        <div style="display:flex;justify-content:space-between;font-size:.8rem;margin-bottom:.25rem;">
                            <span style="font-weight:600;color:#334155;max-width:55%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <?= htmlspecialchars($tt['train_name']) ?>
                            </span>
                            <span style="color:#3b82f6;font-weight:700;">Rs.&nbsp;<?= number_format($tt['revenue'], 0) ?></span>
                        </div>
                        <div class="prog-bar">
                            <div class="prog-bar-fill" style="width:<?= round($pct) ?>%;"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($top_trains)): ?>
                        <p style="color:#94a3b8;font-size:.8rem;text-align:center;">No revenue data yet.</p>
                    <?php endif; ?>
                </div>

            </div><!-- /right column -->
        </div><!-- /content-row -->

        <!-- Recent Users -->
        <div class="dash-section" style="margin-bottom:2rem;">
            <div class="dash-section-header">
                <h4><i class="bi bi-people me-1" style="color:#3b82f6;"></i> Recently Registered Users</h4>
                <a href="manage-users.php">View all →</a>
            </div>
            <?php if ($recent_users): ?>
            <div style="overflow-x:auto;">
            <table class="mini-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Full Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Joined</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_users as $i => $u): ?>
                    <tr>
                        <td style="color:#94a3b8;"><?= $i+1 ?></td>
                        <td style="font-weight:600;"><?= htmlspecialchars($u['full_name']) ?></td>
                        <td style="color:#6366f1;">@<?= htmlspecialchars($u['username']) ?></td>
                        <td style="font-size:.77rem;color:#64748b;"><?= htmlspecialchars($u['email']) ?></td>
                        <td>
                            <span class="pill pill-<?= strtolower($u['role']) ?>"><?= ucfirst($u['role']) ?></span>
                        </td>
                        <td style="font-size:.75rem;white-space:nowrap;color:#64748b;">
                            <?= date('d M Y', strtotime($u['created_at'])) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php else: ?>
                <p style="color:#94a3b8;font-size:.85rem;text-align:center;padding:1rem 0;">No users found.</p>
            <?php endif; ?>
        </div>

    </main><!-- /adm-main -->
</div><!-- /adm-wrap -->

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function() {
    // Combined Bookings + Revenue chart
    const bpmLabels = <?= json_encode($bpm_labels) ?>;
    const bpmData   = <?= json_encode($bpm_data) ?>;
    const rpmData   = <?= json_encode($rpm_data) ?>;

    const ctx = document.getElementById('combinedChart');
    if (ctx) {
        new Chart(ctx, {
            data: {
                labels: bpmLabels,
                datasets: [
                    {
                        type: 'bar',
                        label: 'Bookings',
                        data: bpmData,
                        backgroundColor: 'rgba(99,102,241,0.25)',
                        borderColor: 'rgba(99,102,241,0.8)',
                        borderWidth: 2,
                        yAxisID: 'y',
                        borderRadius: 6,
                    },
                    {
                        type: 'line',
                        label: 'Revenue (Rs.)',
                        data: rpmData,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59,130,246,0.08)',
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#3b82f6',
                        pointRadius: 4,
                        yAxisID: 'y2',
                    }
                ]
            },
            options: {
                responsive: true,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'top', labels: { font: { size: 12 } } } },
                scales: {
                    y:  { position: 'left',  beginAtZero: true, ticks: { font: { size: 11 } }, grid: { color: 'rgba(0,0,0,.05)' } },
                    y2: { position: 'right', beginAtZero: true, ticks: { font: { size: 11 }, callback: v => 'Rs.' + v.toLocaleString() }, grid: { drawOnChartArea: false } },
                    x:  { ticks: { font: { size: 11 } }, grid: { display: false } }
                }
            }
        });
    }

    // Booking status doughnut
    const sCtx = document.getElementById('statusChart');
    if (sCtx) {
        new Chart(sCtx, {
            type: 'doughnut',
            data: {
                labels: ['Confirmed', 'Pending', 'Cancelled'],
                datasets: [{
                    data: [<?= $confirmed_bookings ?>, <?= $pending_bookings_n ?>, <?= $cancelled_bookings ?>],
                    backgroundColor: ['#22c55e', '#f59e0b', '#ef4444'],
                    borderWidth: 0,
                    hoverOffset: 6
                }]
            },
            options: {
                cutout: '68%',
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: ctx => ' ' + ctx.label + ': ' + ctx.parsed } }
                }
            }
        });
    }

    const lookupForm = document.getElementById('adminLookupForm');
    if (lookupForm) {
        lookupForm.addEventListener('submit', function(event) {
            event.preventDefault();
            const target = document.getElementById('lookupTarget').value;
            const query = document.getElementById('lookupQuery').value.trim();
            if (!query) {
                document.getElementById('lookupQuery').focus();
                return;
            }
            window.location.href = target + '?q=' + encodeURIComponent(query);
        });
    }
})();
</script>

<!-- Mobile sidebar overlay -->
<div id="admOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1040;" onclick="closeAdmSidebar()"></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var toggle  = document.getElementById('admSidebarToggle');
    var sidebar = document.getElementById('admSidebar');
    var overlay = document.getElementById('admOverlay');

    function openAdmSidebar() {
        sidebar.classList.add('open');
        overlay.style.display = 'block';
        document.body.style.overflow = 'hidden';
    }

    window.closeAdmSidebar = function () {
        sidebar.classList.remove('open');
        overlay.style.display = 'none';
        document.body.style.overflow = '';
    };

    if (toggle) toggle.addEventListener('click', openAdmSidebar);
});
</script>

<?php require_once 'inc/footer.php'; ?>

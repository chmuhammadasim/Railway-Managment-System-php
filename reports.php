<?php
// reports.php - Admin Reports Page
// Covers: list of trains, list of routes, monthly/quarterly/yearly income of trains & routes

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';

if (!User::isLoggedIn() || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$db = new Database();
$db->connect();

// ---- Filters ----
$period     = isset($_GET['period']) ? $_GET['period'] : 'monthly';    // monthly | quarterly | yearly
$year       = isset($_GET['year'])   ? (int)$_GET['year']   : (int)date('Y');
$report_tab = isset($_GET['tab'])    ? $_GET['tab']         : 'trains'; // trains | routes | income_trains | income_routes

// ---- 1. List of Trains ----
$trains = $db->select("SELECT t.*,
    (SELECT COUNT(*) FROM routes r WHERE r.train_id = t.train_id) AS total_routes,
    (SELECT COUNT(*) FROM bookings b JOIN routes r ON b.route_id = r.route_id WHERE r.train_id = t.train_id AND b.booking_status = 'confirmed') AS total_bookings,
    (SELECT IFNULL(SUM(b.total_fare),0) FROM bookings b JOIN routes r ON b.route_id = r.route_id WHERE r.train_id = t.train_id AND b.payment_status = 'completed') AS total_revenue
    FROM trains t ORDER BY t.train_name ASC");
if (!$trains) $trains = array();

// ---- 2. List of Routes ----
$routes = $db->select("SELECT r.*, t.train_name, t.train_number,
    (SELECT COUNT(*) FROM bookings b WHERE b.route_id = r.route_id AND b.booking_status = 'confirmed') AS total_bookings,
    (SELECT IFNULL(SUM(b.total_fare),0) FROM bookings b WHERE b.route_id = r.route_id AND b.payment_status = 'completed') AS total_revenue
    FROM routes r JOIN trains t ON r.train_id = t.train_id
    ORDER BY r.journey_date DESC, r.departure_time ASC");
if (!$routes) $routes = array();

// ---- 3. Income by Train (period) ----
if ($period === 'monthly') {
    $income_group_format = '%Y-%m';
    $period_label        = 'Month';
} elseif ($period === 'quarterly') {
    $income_group_format = '%Y-Q';   // we will post-process
    $period_label        = 'Quarter';
} else { // yearly
    $income_group_format = '%Y';
    $period_label        = 'Year';
}

// Income per train per period
if ($period === 'quarterly') {
    $income_trains = $db->select("SELECT t.train_name, t.train_number,
        YEAR(b.booking_date) AS yr,
        QUARTER(b.booking_date) AS qtr,
        COUNT(b.booking_id) AS total_bookings,
        SUM(b.total_fare) AS total_revenue
        FROM bookings b
        JOIN routes r ON b.route_id = r.route_id
        JOIN trains t ON r.train_id = t.train_id
        WHERE b.payment_status = 'completed' AND YEAR(b.booking_date) = {$year}
        GROUP BY t.train_id, yr, qtr
        ORDER BY t.train_name, yr, qtr");
} elseif ($period === 'monthly') {
    $income_trains = $db->select("SELECT t.train_name, t.train_number,
        DATE_FORMAT(b.booking_date, '%Y-%m') AS period_label,
        COUNT(b.booking_id) AS total_bookings,
        SUM(b.total_fare) AS total_revenue
        FROM bookings b
        JOIN routes r ON b.route_id = r.route_id
        JOIN trains t ON r.train_id = t.train_id
        WHERE b.payment_status = 'completed' AND YEAR(b.booking_date) = {$year}
        GROUP BY t.train_id, period_label
        ORDER BY t.train_name, period_label");
} else { // yearly
    $income_trains = $db->select("SELECT t.train_name, t.train_number,
        YEAR(b.booking_date) AS period_label,
        COUNT(b.booking_id) AS total_bookings,
        SUM(b.total_fare) AS total_revenue
        FROM bookings b
        JOIN routes r ON b.route_id = r.route_id
        JOIN trains t ON r.train_id = t.train_id
        WHERE b.payment_status = 'completed'
        GROUP BY t.train_id, period_label
        ORDER BY t.train_name, period_label");
}
if (!$income_trains) $income_trains = array();

// Income per route per period
if ($period === 'quarterly') {
    $income_routes = $db->select("SELECT CONCAT(r.departure_city,' → ',r.arrival_city) AS route_name,
        t.train_name,
        YEAR(b.booking_date) AS yr,
        QUARTER(b.booking_date) AS qtr,
        COUNT(b.booking_id) AS total_bookings,
        SUM(b.total_fare) AS total_revenue
        FROM bookings b
        JOIN routes r ON b.route_id = r.route_id
        JOIN trains t ON r.train_id = t.train_id
        WHERE b.payment_status = 'completed' AND YEAR(b.booking_date) = {$year}
        GROUP BY r.route_id, yr, qtr
        ORDER BY route_name, yr, qtr");
} elseif ($period === 'monthly') {
    $income_routes = $db->select("SELECT CONCAT(r.departure_city,' → ',r.arrival_city) AS route_name,
        t.train_name,
        DATE_FORMAT(b.booking_date, '%Y-%m') AS period_label,
        COUNT(b.booking_id) AS total_bookings,
        SUM(b.total_fare) AS total_revenue
        FROM bookings b
        JOIN routes r ON b.route_id = r.route_id
        JOIN trains t ON r.train_id = t.train_id
        WHERE b.payment_status = 'completed' AND YEAR(b.booking_date) = {$year}
        GROUP BY r.route_id, period_label
        ORDER BY route_name, period_label");
} else {
    $income_routes = $db->select("SELECT CONCAT(r.departure_city,' → ',r.arrival_city) AS route_name,
        t.train_name,
        YEAR(b.booking_date) AS period_label,
        COUNT(b.booking_id) AS total_bookings,
        SUM(b.total_fare) AS total_revenue
        FROM bookings b
        JOIN routes r ON b.route_id = r.route_id
        JOIN trains t ON r.train_id = t.train_id
        WHERE b.payment_status = 'completed'
        GROUP BY r.route_id, period_label
        ORDER BY route_name, period_label");
}
if (!$income_routes) $income_routes = array();

// ---- Summary Stats ----
$total_revenue  = $db->selectRow("SELECT IFNULL(SUM(amount),0) AS total FROM payments WHERE payment_status='completed'");
$total_bookings = $db->selectRow("SELECT COUNT(*) AS total FROM bookings WHERE booking_status='confirmed'");
$active_trains  = $db->selectRow("SELECT COUNT(*) AS total FROM trains WHERE status='active'");
$active_routes  = $db->selectRow("SELECT COUNT(*) AS total FROM routes WHERE status='scheduled'");

// ---- Monthly revenue for current year (for overview chart) ----
$monthly_overview = $db->select("SELECT DATE_FORMAT(booking_date,'%b') AS mon,
    MONTH(booking_date) AS mon_num,
    IFNULL(SUM(total_fare),0) AS revenue,
    COUNT(*) AS bookings
    FROM bookings
    WHERE YEAR(booking_date) = " . (int)date('Y') . " AND booking_status = 'confirmed'
    GROUP BY mon_num, mon
    ORDER BY mon_num ASC");
if (!$monthly_overview) $monthly_overview = [];

// Available years for the filter dropdown
$years_result = $db->select("SELECT DISTINCT YEAR(booking_date) AS yr FROM bookings ORDER BY yr DESC");
$available_years = $years_result ? array_column($years_result, 'yr') : array(date('Y'));

// Current admin user for sidebar
require_once 'src/classes/User.php';
$_userObj   = new User($db);
$_adminUser = $_userObj->getUserById($_SESSION['user_id']);

$hideMainNavbar = true;
$pageTitle      = 'Reports & Analytics';
$extraScripts   = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js'];
require_once 'inc/header.php';
?>

<style>
/* ── Shell ─────────────────────────────────────────── */
.adm-wrap  { display:flex; min-height:calc(100vh - 64px); }

/* ── Sidebar ───────────────────────────────────────── */
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

/* ── Main ──────────────────────────────────────────── */
.adm-main { flex:1; padding:2rem; overflow-x:hidden; background:#f8fafc; }

/* ── Page header ───────────────────────────────────── */
.adm-page-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1.75rem; flex-wrap:wrap; gap:1rem; }
.adm-page-header h2 { font-size:1.6rem; font-weight:800; color:#0f172a; margin:0; }
.adm-page-header p  { color:#64748b; margin:.2rem 0 0; font-size:.875rem; }

/* ── KPI cards ─────────────────────────────────────── */
.kpi-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(185px,1fr)); gap:1rem; margin-bottom:1.75rem; }
.kpi-card { background:#fff; border-radius:14px; padding:1.2rem 1.4rem; position:relative; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.07); transition:transform .2s,box-shadow .2s; }
.kpi-card:hover { transform:translateY(-3px); box-shadow:0 6px 18px rgba(0,0,0,.1); }
.kpi-card .kpi-icon { width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; margin-bottom:.75rem; }
.kpi-card .kpi-val { font-size:1.9rem; font-weight:800; color:#0f172a; line-height:1; }
.kpi-card .kpi-lbl { font-size:.78rem; color:#64748b; margin-top:.3rem; font-weight:500; }
.kpi-card .kpi-bg  { position:absolute; right:-12px; bottom:-12px; font-size:5rem; opacity:.05; pointer-events:none; }
.kpi-blue   .kpi-icon { background:#dbeafe; color:#2563eb; }
.kpi-green  .kpi-icon { background:#dcfce7; color:#16a34a; }
.kpi-amber  .kpi-icon { background:#fef3c7; color:#d97706; }
.kpi-purple .kpi-icon { background:#ede9fe; color:#7c3aed; }

/* ── Report cards & tables ─────────────────────────── */
.report-card { background:#fff; border-radius:14px; padding:1.5rem; box-shadow:0 1px 4px rgba(0,0,0,.07); margin-bottom:1.5rem; }
.report-card h5 { font-size:.95rem; font-weight:700; color:#0f172a; margin-bottom:1rem; }
.rpt-table { width:100%; border-collapse:collapse; font-size:.82rem; }
.rpt-table th { padding:.6rem .75rem; background:#0f1e32; color:#fff; font-weight:600; font-size:.75rem; text-transform:uppercase; letter-spacing:.4px; border:none; white-space:nowrap; }
.rpt-table td { padding:.6rem .75rem; border-bottom:1px solid #f1f5f9; color:#334155; vertical-align:middle; }
.rpt-table tbody tr:hover { background:#f8fafc; }
.rpt-table tbody tr:last-child td { border-bottom:none; }
.rpt-table tfoot td { background:#f1f5f9; font-weight:700; color:#0f172a; padding:.65rem .75rem; }

/* ── Status badges ─────────────────────────────────── */
.badge-active       { background:#dcfce7; color:#166534; padding:.25em .7em; border-radius:999px; font-size:.74rem; font-weight:600; display:inline-block; }
.badge-inactive     { background:#fee2e2; color:#991b1b; padding:.25em .7em; border-radius:999px; font-size:.74rem; font-weight:600; display:inline-block; }
.badge-maintenance  { background:#fef3c7; color:#92400e; padding:.25em .7em; border-radius:999px; font-size:.74rem; font-weight:600; display:inline-block; }
.badge-scheduled    { background:#dbeafe; color:#1e40af; padding:.25em .7em; border-radius:999px; font-size:.74rem; font-weight:600; display:inline-block; }
.badge-cancelled    { background:#fee2e2; color:#991b1b; padding:.25em .7em; border-radius:999px; font-size:.74rem; font-weight:600; display:inline-block; }
.badge-completed    { background:#dbeafe; color:#1e40af; padding:.25em .7em; border-radius:999px; font-size:.74rem; font-weight:600; display:inline-block; }

/* ── Filter bar ────────────────────────────────────── */
.filter-bar { background:#fff; border-radius:12px; padding:1rem 1.5rem; box-shadow:0 1px 4px rgba(0,0,0,.07); margin-bottom:1.25rem; display:flex; flex-wrap:wrap; gap:.75rem; align-items:center; }

/* ── Tab nav ───────────────────────────────────────── */
.rpt-tabs { display:flex; gap:.35rem; flex-wrap:wrap; margin-bottom:1.25rem; }
.rpt-tab { padding:.45rem 1.1rem; border-radius:8px; font-size:.82rem; font-weight:600; border:1.5px solid #e2e8f0; background:#fff; color:#64748b; cursor:pointer; text-decoration:none; transition:all .15s; }
.rpt-tab:hover { border-color:#3b82f6; color:#1d4ed8; background:#eff6ff; }
.rpt-tab.active { background:#0f1e32; border-color:#0f1e32; color:#fff; }

/* ── Chart card ────────────────────────────────────── */
.chart-card { background:#fff; border-radius:14px; padding:1.4rem; box-shadow:0 1px 4px rgba(0,0,0,.07); margin-bottom:1.5rem; }
.chart-card h5 { font-size:.9rem; font-weight:700; color:#0f172a; margin-bottom:1rem; }

/* ── Mobile ────────────────────────────────────────── */
@media(max-width:900px) { .adm-sidebar { display:none; } .adm-main { padding:1rem; } }
@media print {
    .adm-sidebar, .no-print { display:none !important; }
    .adm-main { padding:0; }
    body { background:white; }
    .report-card, .chart-card { box-shadow:none; border:1px solid #ddd; }
}
</style>

<div class="adm-wrap">

<!-- ══ SIDEBAR ══════════════════════════════════════════ -->
<aside class="adm-sidebar">
    <div class="sb-brand">
        <span>Management Panel</span>
        <strong><i class="bi bi-train-front-fill me-1"></i> Railway Admin</strong>
    </div>
    <nav>
        <div class="sb-sep">Main</div>
        <a href="admin-dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="reports.php" class="active"><i class="bi bi-bar-chart-line"></i> Reports</a>
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
        <a href="notifications.php"><i class="bi bi-bell"></i> Notifications</a>

        <div class="sb-sep">Account</div>
        <a href="profile.php"><i class="bi bi-person-gear"></i> Profile</a>
        <a href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </nav>
    <div class="sb-user">
        <div class="avatar"><?= strtoupper(substr($_adminUser['full_name'] ?? 'A', 0, 1)) ?></div>
        <div class="info">
            <strong><?= htmlspecialchars($_adminUser['full_name'] ?? 'Admin') ?></strong>
            <small>Administrator</small>
        </div>
    </div>
</aside>

<!-- ══ MAIN ═════════════════════════════════════════════ -->
<main class="adm-main">

    <!-- Page Header -->
    <div class="adm-page-header">
        <div>
            <h2><i class="bi bi-bar-chart-line me-2 text-primary"></i>Reports &amp; Analytics</h2>
            <p>Train lists, route lists, and income breakdowns</p>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <span class="text-muted small no-print"><i class="bi bi-calendar3 me-1"></i><?= date('d M Y') ?></span>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm no-print">
                <i class="bi bi-printer me-1"></i>Print
            </button>
        </div>
    </div>

        <!-- Page Header -->
        <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h3 class="mb-1"><i class="bi bi-bar-chart-line me-2"></i>Reports & Analytics</h3>
                <div style="opacity:0.85;">Train lists, route lists, and income reports</div>
            </div>
            <button onclick="window.print()" class="btn btn-light no-print">
                <i class="bi bi-printer me-1"></i>Print Report
            </button>
        </div>

    <!-- KPI cards -->
    <div class="kpi-grid">
        <div class="kpi-card kpi-blue">
            <div class="kpi-icon"><i class="bi bi-currency-rupee"></i></div>
            <div class="kpi-val"><?= number_format($total_revenue['total'], 0) ?></div>
            <div class="kpi-lbl">Total Revenue (Rs.)</div>
            <i class="bi bi-currency-rupee kpi-bg"></i>
        </div>
        <div class="kpi-card kpi-green">
            <div class="kpi-icon"><i class="bi bi-ticket-perforated"></i></div>
            <div class="kpi-val"><?= number_format($total_bookings['total']) ?></div>
            <div class="kpi-lbl">Confirmed Bookings</div>
            <i class="bi bi-ticket-perforated kpi-bg"></i>
        </div>
        <div class="kpi-card kpi-amber">
            <div class="kpi-icon"><i class="bi bi-train-front"></i></div>
            <div class="kpi-val"><?= $active_trains['total'] ?></div>
            <div class="kpi-lbl">Active Trains</div>
            <i class="bi bi-train-front kpi-bg"></i>
        </div>
        <div class="kpi-card kpi-purple">
            <div class="kpi-icon"><i class="bi bi-signpost-split"></i></div>
            <div class="kpi-val"><?= $active_routes['total'] ?></div>
            <div class="kpi-lbl">Scheduled Routes</div>
            <i class="bi bi-signpost-split kpi-bg"></i>
        </div>
    </div>

    <!-- Monthly Revenue Chart -->
    <?php if (!empty($monthly_overview)): ?>
    <div class="chart-card">
        <h5><i class="bi bi-bar-chart-line me-2 text-primary"></i>Monthly Revenue Overview – <?= date('Y') ?></h5>
        <canvas id="monthlyOverviewChart" style="max-height:280px;"></canvas>
            <script>
            document.addEventListener('DOMContentLoaded', function(){
                const labels = <?= json_encode(array_column($monthly_overview, 'mon')) ?>;
                const rev    = <?= json_encode(array_map('floatval', array_column($monthly_overview, 'revenue'))) ?>;
                const bkgs   = <?= json_encode(array_map('intval',   array_column($monthly_overview, 'bookings'))) ?>;
                new Chart(document.getElementById('monthlyOverviewChart'), {
                    type: 'bar',
                    data: {
                        labels,
                        datasets: [
                            { label: 'Revenue (Rs.)', data: rev, backgroundColor: 'rgba(37,99,235,0.75)', borderColor: '#1e40af', borderWidth: 1, yAxisID: 'y' },
                            { label: 'Bookings', data: bkgs, type: 'line', borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,.15)', tension: 0.35, pointRadius: 4, yAxisID: 'y1' }
                        ]
                    },
                    options: {
                        responsive: true,
                        interaction: { mode: 'index', intersect: false },
                        plugins: { legend: { position: 'top' } },
                        scales: {
                            y:  { beginAtZero: true, position: 'left',  title: { display: true, text: 'Revenue (Rs.)' } },
                            y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Bookings' } }
                        }
                    }
                });
            });
            </script>
    </div>
    <?php endif; ?>

    <!-- Report Tab Navigation -->
    <div class="rpt-tabs no-print">
        <a class="rpt-tab <?= $report_tab === 'trains'        ? 'active' : '' ?>" href="?tab=trains&period=<?= $period ?>&year=<?= $year ?>"><i class="bi bi-train-front me-1"></i>Train List</a>
        <a class="rpt-tab <?= $report_tab === 'routes'        ? 'active' : '' ?>" href="?tab=routes&period=<?= $period ?>&year=<?= $year ?>"><i class="bi bi-map me-1"></i>Route List</a>
        <a class="rpt-tab <?= $report_tab === 'income_trains' ? 'active' : '' ?>" href="?tab=income_trains&period=<?= $period ?>&year=<?= $year ?>"><i class="bi bi-cash-stack me-1"></i>Income by Train</a>
        <a class="rpt-tab <?= $report_tab === 'income_routes' ? 'active' : '' ?>" href="?tab=income_routes&period=<?= $period ?>&year=<?= $year ?>"><i class="bi bi-currency-dollar me-1"></i>Income by Route</a>
    </div>

    <!-- Period Filter (income tabs) -->
    <?php if (in_array($report_tab, ['income_trains', 'income_routes'])): ?>
    <div class="filter-bar no-print">
        <form method="GET" class="d-flex flex-wrap gap-2 align-items-center">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($report_tab) ?>">
            <label class="fw-600 text-muted small mb-0">Period:</label>
            <select name="period" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                <option value="monthly"   <?= $period === 'monthly'   ? 'selected' : '' ?>>Monthly</option>
                <option value="quarterly" <?= $period === 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
                <option value="yearly"    <?= $period === 'yearly'    ? 'selected' : '' ?>>Yearly</option>
            </select>
            <?php if ($period !== 'yearly'): ?>
            <label class="fw-600 text-muted small mb-0">Year:</label>
            <select name="year" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                <?php foreach ($available_years as $y): ?>
                <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
        </form>
    </div>
    <?php endif; ?>

    <!-- ============ TRAIN LIST ============ -->
    <?php if ($report_tab === 'trains'): ?>
    <div class="report-card">
        <div class="d-flex justify-content-between align-items-center mb-3 no-print">
            <h5 class="mb-0"><i class="bi bi-train-front me-2 text-primary"></i>All Trains</h5>
            <a href="manage-trains.php" class="btn btn-sm btn-primary">Manage Trains</a>
        </div>
        <div class="table-responsive">
            <table class="rpt-table">
                <thead>
                    <tr>
                        <th>#</th><th>Train Name</th><th>Number</th><th>Type</th>
                        <th>Total Seats</th><th>Avail. Seats</th><th>Status</th>
                        <th>Routes</th><th>Bookings</th><th>Revenue (Rs.)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($trains)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">No trains found.</td></tr>
                    <?php else: ?>
                    <?php foreach ($trains as $i => $t): ?>
                    <tr>
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td><strong><?= htmlspecialchars($t['train_name']) ?></strong></td>
                        <td class="font-monospace"><?= htmlspecialchars($t['train_number']) ?></td>
                        <td><?= htmlspecialchars($t['train_type']) ?></td>
                        <td><?= $t['total_seats'] ?></td>
                        <td><?= $t['available_seats'] ?></td>
                        <td><span class="badge-<?= $t['status'] ?>"><?= ucfirst($t['status']) ?></span></td>
                        <td><?= $t['total_routes'] ?></td>
                        <td><?= $t['total_bookings'] ?></td>
                        <td><strong>Rs. <?= number_format($t['total_revenue'], 0) ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($trains)): ?>
                <tfoot>
                    <tr>
                        <td colspan="9" class="text-end">Grand Total Revenue:</td>
                        <td><strong>Rs. <?= number_format(array_sum(array_column($trains, 'total_revenue')), 0) ?></strong></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
        <?php if (!empty($trains)): ?>
        <div class="mt-4" style="max-width:680px;">
            <canvas id="trainRevenueChart"></canvas>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function(){
            const labels = <?= json_encode(array_column($trains, 'train_name')) ?>;
            const data   = <?= json_encode(array_map('floatval', array_column($trains, 'total_revenue'))) ?>;
            new Chart(document.getElementById('trainRevenueChart'), {
                type: 'bar',
                data: { labels, datasets:[{ label:'Revenue (Rs.)', data, backgroundColor:'rgba(37,99,235,0.75)', borderColor:'#1e40af', borderWidth:1 }] },
                options:{ responsive:true, plugins:{ legend:{display:false}, title:{display:true,text:'Revenue per Train'} }, scales:{ y:{beginAtZero:true} } }
            });
        });
        </script>
        <?php endif; ?>
    </div>

    <!-- ============ ROUTE LIST ============ -->
    <?php elseif ($report_tab === 'routes'): ?>
    <div class="report-card">
        <div class="d-flex justify-content-between align-items-center mb-3 no-print">
            <h5 class="mb-0"><i class="bi bi-map me-2 text-primary"></i>All Routes</h5>
            <a href="manage-routes.php" class="btn btn-sm btn-primary">Manage Routes</a>
        </div>
        <div class="table-responsive">
            <table class="rpt-table">
                <thead>
                    <tr>
                        <th>#</th><th>Train</th><th>Route</th><th>Dep.</th><th>Arr.</th>
                        <th>Date</th><th>Dist (km)</th><th>Base Fare</th>
                        <th>Avail. Seats</th><th>Status</th><th>Bookings</th><th>Revenue (Rs.)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($routes)): ?>
                    <tr><td colspan="12" class="text-center text-muted py-4">No routes found.</td></tr>
                    <?php else: ?>
                    <?php foreach ($routes as $i => $r): ?>
                    <tr>
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td><?= htmlspecialchars($r['train_name']) ?></td>
                        <td><strong><?= htmlspecialchars($r['departure_city']) ?> → <?= htmlspecialchars($r['arrival_city']) ?></strong></td>
                        <td><?= date('H:i', strtotime($r['departure_time'])) ?></td>
                        <td><?= date('H:i', strtotime($r['arrival_time'])) ?></td>
                        <td><?= date('d M Y', strtotime($r['journey_date'])) ?></td>
                        <td><?= number_format($r['distance_km'], 0) ?></td>
                        <td>Rs. <?= number_format($r['base_fare'], 0) ?></td>
                        <td><?= $r['available_seats'] ?></td>
                        <td><span class="badge-<?= $r['status'] === 'scheduled' ? 'scheduled' : ($r['status'] === 'cancelled' ? 'cancelled' : 'maintenance') ?>"><?= ucfirst($r['status']) ?></span></td>
                        <td><?= $r['total_bookings'] ?></td>
                        <td>Rs. <?= number_format($r['total_revenue'], 0) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($routes)): ?>
                <tfoot>
                    <tr>
                        <td colspan="11" class="text-end">Grand Total Revenue:</td>
                        <td><strong>Rs. <?= number_format(array_sum(array_column($routes, 'total_revenue')), 0) ?></strong></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <!-- ============ INCOME BY TRAIN ============ -->
    <?php elseif ($report_tab === 'income_trains'): ?>
    <div class="report-card">
        <h5><i class="bi bi-cash-stack me-2 text-primary"></i><?= ucfirst($period) ?> Income by Train <?= $period !== 'yearly' ? "($year)" : '' ?></h5>
        <div class="table-responsive">
            <table class="rpt-table">
                <thead>
                    <tr><th>#</th><th>Train Name</th><th>Train No.</th><th><?= $period_label ?></th><th>Bookings</th><th>Revenue (Rs.)</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($income_trains)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No income data for the selected period.</td></tr>
                    <?php else: ?>
                    <?php $grand_total = 0;
                    foreach ($income_trains as $i => $row):
                        $grand_total += $row['total_revenue'];
                        $period_display = $period === 'quarterly' ? $row['yr'] . ' Q' . $row['qtr'] : $row['period_label'];
                    ?>
                    <tr>
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td><?= htmlspecialchars($row['train_name']) ?></td>
                        <td class="font-monospace"><?= htmlspecialchars($row['train_number']) ?></td>
                        <td><?= htmlspecialchars($period_display) ?></td>
                        <td><?= $row['total_bookings'] ?></td>
                        <td><strong>Rs. <?= number_format($row['total_revenue'], 0) ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($income_trains)): ?>
                <tfoot><tr><td colspan="5" class="text-end">Grand Total:</td><td><strong>Rs. <?= number_format($grand_total, 0) ?></strong></td></tr></tfoot>
                <?php endif; ?>
            </table>
        </div>
        <?php if (!empty($income_trains)): ?>
        <?php $chart_data_trains = array();
        foreach ($income_trains as $row) {
            $lbl = $period === 'quarterly' ? $row['yr'].' Q'.$row['qtr'] : $row['period_label'];
            if (!isset($chart_data_trains[$lbl])) $chart_data_trains[$lbl] = 0;
            $chart_data_trains[$lbl] += floatval($row['total_revenue']);
        } ?>
        <div class="mt-4" style="max-width:680px;">
            <canvas id="incomePeriodChart"></canvas>
        </div>
        <script>
        (function(){
            const labels = <?= json_encode(array_keys($chart_data_trains)) ?>;
            const data   = <?= json_encode(array_values($chart_data_trains)) ?>;
            new Chart(document.getElementById('incomePeriodChart'), {
                type: 'line',
                data: { labels, datasets:[{ label:'Revenue (Rs.)', data, fill:true, backgroundColor:'rgba(37,99,235,.12)', borderColor:'#2563eb', tension:.3, pointBackgroundColor:'#1e40af' }] },
                options:{ responsive:true, plugins:{ title:{display:true,text:'<?= ucfirst($period) ?> Income (All Trains)'} }, scales:{ y:{beginAtZero:true} } }
            });
        })();
        </script>
        <?php endif; ?>
    </div>

    <!-- ============ INCOME BY ROUTE ============ -->
    <?php elseif ($report_tab === 'income_routes'): ?>
    <div class="report-card">
        <h5><i class="bi bi-currency-dollar me-2 text-primary"></i><?= ucfirst($period) ?> Income by Route <?= $period !== 'yearly' ? "($year)" : '' ?></h5>
        <div class="table-responsive">
            <table class="rpt-table">
                <thead>
                    <tr><th>#</th><th>Route</th><th>Train</th><th><?= $period_label ?></th><th>Bookings</th><th>Revenue (Rs.)</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($income_routes)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No income data for the selected period.</td></tr>
                    <?php else: ?>
                    <?php $grand_total = 0;
                    foreach ($income_routes as $i => $row):
                        $grand_total += $row['total_revenue'];
                        $period_display = $period === 'quarterly' ? $row['yr'] . ' Q' . $row['qtr'] : $row['period_label'];
                    ?>
                    <tr>
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td><strong><?= htmlspecialchars($row['route_name']) ?></strong></td>
                        <td><?= htmlspecialchars($row['train_name']) ?></td>
                        <td><?= htmlspecialchars($period_display) ?></td>
                        <td><?= $row['total_bookings'] ?></td>
                        <td><strong>Rs. <?= number_format($row['total_revenue'], 0) ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($income_routes)): ?>
                <tfoot><tr><td colspan="5" class="text-end">Grand Total:</td><td><strong>Rs. <?= number_format($grand_total, 0) ?></strong></td></tr></tfoot>
                <?php endif; ?>
            </table>
        </div>
        <?php if (!empty($income_routes)): ?>
        <?php $chart_data_routes = array();
        foreach ($income_routes as $row) {
            $lbl = $period === 'quarterly' ? $row['yr'].' Q'.$row['qtr'] : $row['period_label'];
            if (!isset($chart_data_routes[$lbl])) $chart_data_routes[$lbl] = 0;
            $chart_data_routes[$lbl] += floatval($row['total_revenue']);
        } ?>
        <div class="mt-4" style="max-width:680px;">
            <canvas id="incomeRouteChart"></canvas>
        </div>
        <script>
        (function(){
            const labels = <?= json_encode(array_keys($chart_data_routes)) ?>;
            const data   = <?= json_encode(array_values($chart_data_routes)) ?>;
            new Chart(document.getElementById('incomeRouteChart'), {
                type: 'bar',
                data: { labels, datasets:[{ label:'Revenue (Rs.)', data, backgroundColor:'rgba(37,99,235,.75)', borderColor:'#1e40af', borderWidth:1 }] },
                options:{ responsive:true, plugins:{ title:{display:true,text:'<?= ucfirst($period) ?> Income (All Routes)'} }, scales:{ y:{beginAtZero:true} } }
            });
        })();
        </script>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</main>
</div><!-- /.adm-wrap -->

<?php require_once 'inc/footer.php'; ?>

<?php
// reports.php - Enhanced Admin Reports & Analytics
// Covers: KPIs, charts, train/route lists, income breakdown, booking analytics, export

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';

if (!User::isLoggedIn() || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$db = new Database();
$db->connect();
$conn = $db->getConnection();

// ---- Filters ----
$period     = $_GET['period'] ?? 'monthly';
$year       = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$report_tab = $_GET['tab']    ?? 'overview';
$date_from  = $_GET['from']   ?? '';
$date_to    = $_GET['to']     ?? '';
$export_csv = isset($_GET['export']) && $_GET['export'] === 'csv';

// ---- Search / Filter Params ----
$search_train   = trim($_GET['train']   ?? '');
$search_city    = trim($_GET['city']    ?? '');
$filter_status  = $_GET['fstatus'] ?? 'all';   // all | active | inactive | maintenance (trains) / scheduled | completed | cancelled (routes)
$sort_by_rpt    = $_GET['sort']    ?? 'revenue'; // revenue | name | bookings | seats
$sort_dir_rpt   = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
$per_page       = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;

// Build reusable date filter for bookings/routes
$date_where = '';
$date_params_route = '';
if ($date_from !== '') {
    $safe_from = $conn->real_escape_string($date_from);
    $date_where .= " AND b.booking_date >= '{$safe_from}'";
    $date_params_route .= " AND r.journey_date >= '{$safe_from}'";
}
if ($date_to !== '') {
    $safe_to = $conn->real_escape_string($date_to);
    $date_where .= " AND b.booking_date <= '{$safe_to} 23:59:59'";
    $date_params_route .= " AND r.journey_date <= '{$safe_to}'";
}

// Build reusable payment date filter for KPIs
$pmt_date_where = '';
if ($date_from !== '') {
    $safe_from = $conn->real_escape_string($date_from);
    $pmt_date_where .= " AND p.payment_date >= '{$safe_from}'";
}
if ($date_to !== '') {
    $safe_to = $conn->real_escape_string($date_to);
    $pmt_date_where .= " AND p.payment_date <= '{$safe_to} 23:59:59'";
}

// Train filter subquery
$train_filter_join = '';
$train_filter_where = '';
if ($search_train !== '') {
    $safe_train = $conn->real_escape_string($search_train);
    $train_filter_where .= " AND (t.train_name LIKE '%{$safe_train}%' OR t.train_number LIKE '%{$safe_train}%')";
}
if ($filter_status !== 'all' && in_array($report_tab, ['trains'])) {
    $safe_fs = $conn->real_escape_string($filter_status);
    $train_filter_where .= " AND t.status = '{$safe_fs}'";
}

// City filter for routes
$city_filter_where = '';
if ($search_city !== '') {
    $safe_city = $conn->real_escape_string($search_city);
    $city_filter_where .= " AND (r.departure_city LIKE '%{$safe_city}%' OR r.arrival_city LIKE '%{$safe_city}%')";
}

// Status filter for routes
$route_status_where = '';
if ($filter_status !== 'all' && in_array($report_tab, ['routes'])) {
    $safe_fs = $conn->real_escape_string($filter_status);
    $route_status_where .= " AND r.status = '{$safe_fs}'";
}

// ---- CSV Export (trains tab) ----
if ($export_csv && $report_tab === 'trains') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="train-report-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['#', 'Train Name', 'Number', 'Type', 'Total Seats', 'Available', 'Status', 'Routes', 'Bookings', 'Revenue (Rs.)']);
    $csv_trains = $db->select("SELECT t.*, (SELECT COUNT(*) FROM routes r WHERE r.train_id=t.train_id) AS tr, (SELECT COUNT(*) FROM bookings b JOIN routes r ON b.route_id=r.route_id WHERE r.train_id=t.train_id AND b.booking_status='confirmed') AS tb, (SELECT IFNULL(SUM(b.total_fare),0) FROM bookings b JOIN routes r ON b.route_id=r.route_id WHERE r.train_id=t.train_id AND b.payment_status='completed') AS rev FROM trains t ORDER BY t.train_name ASC");
    $i=1; foreach ($csv_trains?:[] as $t) { fputcsv($out, [$i++,$t['train_name'],$t['train_number'],$t['train_type'],$t['total_seats'],$t['available_seats'],$t['status'],$t['tr'],$t['tb'],$t['rev']]); }
    fclose($out); exit;
}

// ---- KPI Stats (respect date range) ----
$total_revenue    = (float)($db->selectRow("SELECT IFNULL(SUM(p.amount),0) AS v FROM payments p WHERE p.payment_status='completed'{$pmt_date_where}")['v'] ?? 0);
$total_bookings_n = (int)($db->selectRow("SELECT COUNT(*) AS v FROM bookings b WHERE b.booking_status='confirmed'{$date_where}")['v'] ?? 0);
$pending_bookings = (int)($db->selectRow("SELECT COUNT(*) AS v FROM bookings b WHERE b.booking_status='pending'{$date_where}")['v'] ?? 0);
$cancelled_bkgs   = (int)($db->selectRow("SELECT COUNT(*) AS v FROM bookings b WHERE b.booking_status='cancelled'{$date_where}")['v'] ?? 0);
$active_trains_n  = (int)($db->selectRow("SELECT COUNT(*) AS v FROM trains WHERE status='active'")['v'] ?? 0);
$active_routes_n  = (int)($db->selectRow("SELECT COUNT(*) AS v FROM routes WHERE status='scheduled' AND journey_date >= CURDATE()")['v'] ?? 0);
$total_users_n    = (int)($db->selectRow("SELECT COUNT(*) AS v FROM users WHERE role='user'")['v'] ?? 0);
$today_revenue    = (float)($db->selectRow("SELECT IFNULL(SUM(amount),0) AS v FROM payments WHERE DATE(payment_date)=CURDATE() AND payment_status='completed'")['v'] ?? 0);
$today_bookings   = (int)($db->selectRow("SELECT COUNT(*) AS v FROM bookings WHERE DATE(booking_date)=CURDATE()")['v'] ?? 0);
$avg_fare         = $total_bookings_n > 0 ? round($total_revenue / $total_bookings_n, 2) : 0;
$total_all_bkgs   = $total_bookings_n + $pending_bookings + $cancelled_bkgs;
$cancellation_rate = $total_all_bkgs > 0 ? round(($cancelled_bkgs / $total_all_bkgs) * 100, 1) : 0;

// Sort mapping for trains
$train_sort_map = [
    'revenue'  => 'total_revenue',
    'name'     => 't.train_name',
    'bookings' => 'total_bookings',
    'seats'    => 't.total_seats',
];
$train_order_col = $train_sort_map[$sort_by_rpt] ?? 'total_revenue';
if ($train_order_col === 'total_revenue' || $train_order_col === 'total_bookings') {
    $train_order_clause = "ORDER BY {$train_order_col} {$sort_dir_rpt}, t.train_name ASC";
} else {
    $train_order_clause = "ORDER BY {$train_order_col} {$sort_dir_rpt}";
}

// ---- 1. List of Trains (with filters) ----
$trains = $db->select("SELECT t.*,
    (SELECT COUNT(*) FROM routes r WHERE r.train_id = t.train_id) AS total_routes,
    (SELECT COUNT(*) FROM bookings b JOIN routes r2 ON b.route_id = r2.route_id WHERE r2.train_id = t.train_id AND b.booking_status = 'confirmed'{$date_where}) AS total_bookings,
    (SELECT IFNULL(SUM(b.total_fare),0) FROM bookings b JOIN routes r2 ON b.route_id = r2.route_id WHERE r2.train_id = t.train_id AND b.payment_status = 'completed'{$date_where}) AS total_revenue
    FROM trains t WHERE 1=1 {$train_filter_where}
    {$train_order_clause}
    LIMIT {$per_page}");
if (!$trains) $trains = [];

// ---- 2. List of Routes (with filters) ----
$routes = $db->select("SELECT r.*, t.train_name, t.train_number,
    (SELECT COUNT(*) FROM bookings b WHERE b.route_id = r.route_id AND b.booking_status = 'confirmed'{$date_where}) AS total_bookings,
    (SELECT IFNULL(SUM(b.total_fare),0) FROM bookings b WHERE b.route_id = r.route_id AND b.payment_status = 'completed'{$date_where}) AS total_revenue
    FROM routes r JOIN trains t ON r.train_id = t.train_id
    WHERE 1=1 {$city_filter_where} {$route_status_where} {$train_filter_where} {$date_params_route}
    ORDER BY r.journey_date DESC, r.departure_time ASC
    LIMIT {$per_page}");
if (!$routes) $routes = [];

// ---- 3. Income by Train (period) ----
$income_train_where = '';
if ($search_train !== '') {
    $safe_t = $conn->real_escape_string($search_train);
    $income_train_where .= " AND (t.train_name LIKE '%{$safe_t}%' OR t.train_number LIKE '%{$safe_t}%')";
}
$period_label = match($period) { 'quarterly' => 'Quarter', 'yearly' => 'Year', default => 'Month' };
if ($period === 'quarterly') {
    $income_trains = $db->select("SELECT t.train_name, t.train_number, YEAR(b.booking_date) AS yr, QUARTER(b.booking_date) AS qtr, COUNT(b.booking_id) AS total_bookings, SUM(b.total_fare) AS total_revenue FROM bookings b JOIN routes r ON b.route_id=r.route_id JOIN trains t ON r.train_id=t.train_id WHERE b.payment_status='completed' AND YEAR(b.booking_date)={$year}{$date_where}{$income_train_where} GROUP BY t.train_id, yr, qtr ORDER BY t.train_name, yr, qtr");
} elseif ($period === 'monthly') {
    $income_trains = $db->select("SELECT t.train_name, t.train_number, DATE_FORMAT(b.booking_date,'%Y-%m') AS period_label, COUNT(b.booking_id) AS total_bookings, SUM(b.total_fare) AS total_revenue FROM bookings b JOIN routes r ON b.route_id=r.route_id JOIN trains t ON r.train_id=t.train_id WHERE b.payment_status='completed' AND YEAR(b.booking_date)={$year}{$date_where}{$income_train_where} GROUP BY t.train_id, period_label ORDER BY t.train_name, period_label");
} else {
    $income_trains = $db->select("SELECT t.train_name, t.train_number, YEAR(b.booking_date) AS period_label, COUNT(b.booking_id) AS total_bookings, SUM(b.total_fare) AS total_revenue FROM bookings b JOIN routes r ON b.route_id=r.route_id JOIN trains t ON r.train_id=t.train_id WHERE b.payment_status='completed'{$date_where}{$income_train_where} GROUP BY t.train_id, period_label ORDER BY t.train_name, period_label");
}
if (!$income_trains) $income_trains = [];

// Income per route per period (with filters)
$income_route_city_where = '';
if ($search_city !== '') {
    $safe_c = $conn->real_escape_string($search_city);
    $income_route_city_where .= " AND (r.departure_city LIKE '%{$safe_c}%' OR r.arrival_city LIKE '%{$safe_c}%')";
}
if ($period === 'quarterly') {
    $income_routes = $db->select("SELECT CONCAT(r.departure_city,' → ',r.arrival_city) AS route_name, t.train_name, YEAR(b.booking_date) AS yr, QUARTER(b.booking_date) AS qtr, COUNT(b.booking_id) AS total_bookings, SUM(b.total_fare) AS total_revenue FROM bookings b JOIN routes r ON b.route_id=r.route_id JOIN trains t ON r.train_id=t.train_id WHERE b.payment_status='completed' AND YEAR(b.booking_date)={$year}{$date_where}{$income_train_where}{$income_route_city_where} GROUP BY r.route_id, yr, qtr ORDER BY route_name, yr, qtr");
} elseif ($period === 'monthly') {
    $income_routes = $db->select("SELECT CONCAT(r.departure_city,' → ',r.arrival_city) AS route_name, t.train_name, DATE_FORMAT(b.booking_date,'%Y-%m') AS period_label, COUNT(b.booking_id) AS total_bookings, SUM(b.total_fare) AS total_revenue FROM bookings b JOIN routes r ON b.route_id=r.route_id JOIN trains t ON r.train_id=t.train_id WHERE b.payment_status='completed' AND YEAR(b.booking_date)={$year}{$date_where}{$income_train_where}{$income_route_city_where} GROUP BY r.route_id, period_label ORDER BY route_name, period_label");
} else {
    $income_routes = $db->select("SELECT CONCAT(r.departure_city,' → ',r.arrival_city) AS route_name, t.train_name, YEAR(b.booking_date) AS period_label, COUNT(b.booking_id) AS total_bookings, SUM(b.total_fare) AS total_revenue FROM bookings b JOIN routes r ON b.route_id=r.route_id JOIN trains t ON r.train_id=t.train_id WHERE b.payment_status='completed'{$date_where}{$income_train_where}{$income_route_city_where} GROUP BY r.route_id, period_label ORDER BY route_name, period_label");
}
if (!$income_routes) $income_routes = [];

// ---- Additional Analytics (with date range) ----
// Booking status distribution
$booking_status_dist = $db->select("SELECT booking_status, COUNT(*) AS cnt FROM bookings b WHERE 1=1{$date_where} GROUP BY booking_status");
$bs_map = ['confirmed' => 0, 'pending' => 0, 'cancelled' => 0];
foreach ($booking_status_dist ?: [] as $row) $bs_map[$row['booking_status']] = (int)$row['cnt'];

// Payment method distribution
$payment_method_dist = $db->select("SELECT payment_method, COUNT(*) AS cnt, SUM(amount) AS total FROM payments p WHERE p.payment_status='completed'{$pmt_date_where} GROUP BY payment_method ORDER BY cnt DESC");
if (!$payment_method_dist) $payment_method_dist = [];

// Top 5 routes by revenue
$top_routes = $db->select("SELECT CONCAT(r.departure_city,'→',r.arrival_city) AS route_name, COUNT(b.booking_id) AS bkgs, SUM(b.total_fare) AS rev FROM bookings b JOIN routes r ON b.route_id=r.route_id WHERE b.payment_status='completed'{$date_where} GROUP BY r.departure_city, r.arrival_city ORDER BY rev DESC LIMIT 5");
if (!$top_routes) $top_routes = [];

// Top 5 trains by revenue
$top_trains = $db->select("SELECT t.train_name, COUNT(b.booking_id) AS bkgs, SUM(b.total_fare) AS rev FROM bookings b JOIN routes r ON b.route_id=r.route_id JOIN trains t ON r.train_id=t.train_id WHERE b.payment_status='completed'{$date_where} GROUP BY t.train_id ORDER BY rev DESC LIMIT 5");
if (!$top_trains) $top_trains = [];

// Monthly revenue for current year
$y_for_monthly = $date_from ? (int)date('Y', strtotime($date_from)) : (int)date('Y');
$monthly_overview = $db->select("SELECT DATE_FORMAT(b.booking_date,'%b') AS mon, MONTH(b.booking_date) AS mon_num, IFNULL(SUM(b.total_fare),0) AS revenue, COUNT(*) AS bookings FROM bookings b WHERE YEAR(b.booking_date)={$y_for_monthly} AND b.booking_status='confirmed'{$date_where} GROUP BY mon_num, mon ORDER BY mon_num ASC");
if (!$monthly_overview) $monthly_overview = [];

// Daily booking trend (last 30 days)
$daily_trend = $db->select("SELECT DATE(b.booking_date) AS dt, COUNT(*) AS cnt, SUM(b.total_fare) AS rev FROM bookings b WHERE b.booking_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND b.booking_status='confirmed'{$date_where} GROUP BY dt ORDER BY dt ASC");
if (!$daily_trend) $daily_trend = [];

// Occupancy rate per train
$occupancy = $db->select("SELECT t.train_name, t.total_seats, COALESCE(ss.bkd,0) AS booked_seats, ROUND((COALESCE(ss.bkd,0)/NULLIF(t.total_seats,0))*100,1) AS pct FROM trains t LEFT JOIN (SELECT s.train_id, COUNT(*) AS bkd FROM seats s WHERE s.status='booked' GROUP BY s.train_id) ss ON ss.train_id=t.train_id WHERE t.status='active' ORDER BY pct DESC");
if (!$occupancy) $occupancy = [];

// Revenue by city
$rev_by_city = $db->select("SELECT r.departure_city AS city, SUM(b.total_fare) AS rev, COUNT(b.booking_id) AS bkgs FROM bookings b JOIN routes r ON b.route_id=r.route_id WHERE b.payment_status='completed'{$date_where} GROUP BY r.departure_city ORDER BY rev DESC LIMIT 8");
if (!$rev_by_city) $rev_by_city = [];

// Train type comparison
$train_type_comp = $db->select("SELECT t.train_type, COUNT(DISTINCT t.train_id) AS train_count, COUNT(b.booking_id) AS bkgs, SUM(b.total_fare) AS rev FROM trains t LEFT JOIN routes r ON r.train_id=t.train_id LEFT JOIN bookings b ON b.route_id=r.route_id AND b.payment_status='completed'{$date_where} GROUP BY t.train_type");
if (!$train_type_comp) $train_type_comp = [];

// Available years
$years_result = $db->select("SELECT DISTINCT YEAR(booking_date) AS yr FROM bookings ORDER BY yr DESC");
$available_years = $years_result ? array_column($years_result, 'yr') : [date('Y')];

// Current admin user for sidebar
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

.adm-main { flex:1; padding:2rem; overflow-x:hidden; background:#f8fafc; }

.adm-page-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1.25rem; flex-wrap:wrap; gap:1rem; }
.adm-page-header h2 { font-size:1.6rem; font-weight:800; color:#0f172a; margin:0; }
.adm-page-header p  { color:#64748b; margin:.2rem 0 0; font-size:.875rem; }

/* ── KPI cards ─────────────────────────────────────── */
.kpi-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(170px,1fr)); gap:.75rem; margin-bottom:1.25rem; }
.kpi-card { background:#fff; border-radius:12px; padding:1rem 1.2rem; position:relative; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.07); transition:transform .2s,box-shadow .2s; cursor:default; }
.kpi-card:hover { transform:translateY(-2px); box-shadow:0 6px 18px rgba(0,0,0,.1); }
.kpi-card .kpi-icon { width:36px; height:36px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:1.1rem; margin-bottom:.55rem; }
.kpi-card .kpi-val { font-size:1.55rem; font-weight:800; color:#0f172a; line-height:1; }
.kpi-card .kpi-lbl { font-size:.72rem; color:#64748b; margin-top:.2rem; font-weight:500; }
.kpi-card .kpi-sub { font-size:.66rem; color:#94a3b8; margin-top:.1rem; }
.kpi-blue   .kpi-icon { background:#dbeafe; color:#2563eb; }
.kpi-green  .kpi-icon { background:#dcfce7; color:#16a34a; }
.kpi-amber  .kpi-icon { background:#fef3c7; color:#d97706; }
.kpi-purple .kpi-icon { background:#ede9fe; color:#7c3aed; }
.kpi-red    .kpi-icon { background:#fee2e2; color:#dc2626; }
.kpi-teal   .kpi-icon { background:#ccfbf1; color:#0d9488; }
.kpi-indigo .kpi-icon { background:#e0e7ff; color:#4338ca; }

/* ── Charts row ────────────────────────────────────── */
.charts-row { display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1.25rem; }
.chart-card { background:#fff; border-radius:14px; padding:1.3rem; box-shadow:0 1px 4px rgba(0,0,0,.07); }
.chart-card h5 { font-size:.88rem; font-weight:700; color:#0f172a; margin-bottom:.85rem; }
.chart-card canvas { max-height:260px; }

/* ── Report cards & tables ─────────────────────────── */
.report-card { background:#fff; border-radius:14px; padding:1.4rem; box-shadow:0 1px 4px rgba(0,0,0,.07); margin-bottom:1.25rem; }
.report-card h5 { font-size:.9rem; font-weight:700; color:#0f172a; margin-bottom:.85rem; }
.rpt-table { width:100%; border-collapse:collapse; font-size:.8rem; }
.rpt-table th { padding:.55rem .7rem; background:#0f1e32; color:#fff; font-weight:600; font-size:.72rem; text-transform:uppercase; letter-spacing:.4px; border:none; white-space:nowrap; }
.rpt-table td { padding:.55rem .7rem; border-bottom:1px solid #f1f5f9; color:#334155; vertical-align:middle; }
.rpt-table tbody tr:hover { background:#f8fafc; }
.rpt-table tbody tr:last-child td { border-bottom:none; }
.rpt-table tfoot td { background:#f1f5f9; font-weight:700; color:#0f172a; padding:.6rem .7rem; }

/* ── Badges ────────────────────────────────────────── */
.badge-active,.badge-scheduled,.badge-completed { background:#dcfce7;color:#166534;padding:.2em .65em;border-radius:999px;font-size:.72rem;font-weight:600;display:inline-block; }
.badge-inactive,.badge-cancelled { background:#fee2e2;color:#991b1b;padding:.2em .65em;border-radius:999px;font-size:.72rem;font-weight:600;display:inline-block; }
.badge-maintenance { background:#fef3c7;color:#92400e;padding:.2em .65em;border-radius:999px;font-size:.72rem;font-weight:600;display:inline-block; }
.badge-pending { background:#fef3c7;color:#92400e;padding:.2em .65em;border-radius:999px;font-size:.72rem;font-weight:600;display:inline-block; }
.text-success { color:#16a34a; }
.text-danger  { color:#dc2626; }
.text-warning { color:#d97706; }

/* ── Filter bar ────────────────────────────────────── */
.filter-bar { background:#fff; border-radius:12px; padding:.85rem 1.2rem; box-shadow:0 1px 4px rgba(0,0,0,.07); margin-bottom:1rem; display:flex; flex-wrap:wrap; gap:.6rem; align-items:center; }

/* ── Tab nav ───────────────────────────────────────── */
.rpt-tabs { display:flex; gap:.3rem; flex-wrap:wrap; margin-bottom:1rem; }
.rpt-tab { padding:.4rem 1rem; border-radius:7px; font-size:.78rem; font-weight:600; border:1.5px solid #e2e8f0; background:#fff; color:#64748b; cursor:pointer; text-decoration:none; transition:all .15s; }
.rpt-tab:hover { border-color:#3b82f6; color:#1d4ed8; background:#eff6ff; }
.rpt-tab.active { background:#0f1e32; border-color:#0f1e32; color:#fff; }

/* ── Mini stat chips ───────────────────────────────── */
.stat-chip-row { display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:.75rem; }
.stat-chip { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:.4rem .8rem; font-size:.75rem; font-weight:600; display:flex; align-items:center; gap:.4rem; }

/* ── Mobile / Print ────────────────────────────────── */
@media(max-width:900px) { .adm-sidebar { display:none; } .adm-main { padding:1rem; } .charts-row { grid-template-columns:1fr; } }
@media print {
    .adm-sidebar, .no-print { display:none !important; }
    .adm-main { padding:0; }
    body { background:white; }
    .report-card, .chart-card { box-shadow:none; border:1px solid #ddd; break-inside:avoid; }
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
        <a href="train-seats-report.php"><i class="bi bi-diagram-3"></i> Seat Report</a>
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
            <p>Comprehensive railway system analytics, revenue insights &amp; performance metrics</p>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap no-print">
            <span class="text-muted small"><i class="bi bi-calendar3 me-1"></i><?= date('d M Y') ?></span>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer me-1"></i>Print</button>
            <a href="?tab=trains&export=csv" class="btn btn-outline-success btn-sm"><i class="bi bi-download me-1"></i>CSV</a>
        </div>
    </div>

    <!-- ═══ SEARCH / FILTER BAR ═══ -->
    <form method="GET" class="filter-bar no-print" id="searchFilterForm">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($report_tab) ?>">
        <?php if (in_array($report_tab, ['income_trains', 'income_routes'])): ?>
        <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">
        <input type="hidden" name="year" value="<?= $year ?>">
        <?php endif; ?>

        <div style="display:flex;align-items:center;gap:.3rem;">
            <i class="bi bi-calendar-range text-muted"></i>
            <input type="date" name="from" value="<?= htmlspecialchars($date_from) ?>" class="form-control form-control-sm" style="width:140px;font-size:.75rem;" title="From Date">
            <span class="text-muted small">to</span>
            <input type="date" name="to" value="<?= htmlspecialchars($date_to) ?>" class="form-control form-control-sm" style="width:140px;font-size:.75rem;" title="To Date">
        </div>

        <?php if ($report_tab !== 'routes'): ?>
        <div style="display:flex;align-items:center;gap:.3rem;">
            <i class="bi bi-train-front text-muted"></i>
            <input type="text" name="train" value="<?= htmlspecialchars($search_train) ?>" placeholder="Train name or number..." class="form-control form-control-sm" style="width:180px;font-size:.75rem;">
        </div>
        <?php endif; ?>

        <?php if (in_array($report_tab, ['routes', 'income_routes'])): ?>
        <div style="display:flex;align-items:center;gap:.3rem;">
            <i class="bi bi-geo-alt text-muted"></i>
            <input type="text" name="city" value="<?= htmlspecialchars($search_city) ?>" placeholder="City name..." class="form-control form-control-sm" style="width:150px;font-size:.75rem;">
        </div>
        <?php endif; ?>

        <select name="fstatus" class="form-select form-select-sm" style="width:auto;font-size:.75rem;">
            <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>All Status</option>
            <?php if ($report_tab === 'trains'): ?>
            <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            <option value="maintenance" <?= $filter_status === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
            <?php elseif ($report_tab === 'routes'): ?>
            <option value="scheduled" <?= $filter_status === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
            <option value="completed" <?= $filter_status === 'completed' ? 'selected' : '' ?>>Completed</option>
            <option value="cancelled" <?= $filter_status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
            <?php endif; ?>
        </select>

        <?php if ($report_tab === 'trains'): ?>
        <select name="sort" class="form-select form-select-sm" style="width:auto;font-size:.75rem;">
            <option value="revenue" <?= $sort_by_rpt === 'revenue' ? 'selected' : '' ?>>Sort: Revenue</option>
            <option value="name" <?= $sort_by_rpt === 'name' ? 'selected' : '' ?>>Sort: Name</option>
            <option value="bookings" <?= $sort_by_rpt === 'bookings' ? 'selected' : '' ?>>Sort: Bookings</option>
            <option value="seats" <?= $sort_by_rpt === 'seats' ? 'selected' : '' ?>>Sort: Seats</option>
        </select>
        <select name="dir" class="form-select form-select-sm" style="width:auto;font-size:.75rem;">
            <option value="desc" <?= ($_GET['dir'] ?? 'desc') === 'desc' ? 'selected' : '' ?>>Desc</option>
            <option value="asc" <?= ($_GET['dir'] ?? '') === 'asc' ? 'selected' : '' ?>>Asc</option>
        </select>
        <?php endif; ?>

        <button type="submit" class="btn btn-sm btn-primary" style="font-size:.75rem;"><i class="bi bi-funnel me-1"></i>Apply</button>
        <?php if ($date_from || $date_to || $search_train || $search_city || $filter_status !== 'all'): ?>
        <a href="?tab=<?= htmlspecialchars($report_tab) ?><?= in_array($report_tab, ['income_trains','income_routes']) ? '&period='.$period.'&year='.$year : '' ?>" class="btn btn-sm btn-outline-danger" style="font-size:.75rem;"><i class="bi bi-x-lg"></i> Clear</a>
        <?php endif; ?>
    </form>

    <!-- KPI cards (8 cards) -->
    <div class="kpi-grid">
        <div class="kpi-card kpi-blue">
            <div class="kpi-icon"><i class="bi bi-cash-stack"></i></div>
            <div class="kpi-val">Rs.<?= number_format($total_revenue, 0) ?></div>
            <div class="kpi-lbl">Total Revenue</div>
            <div class="kpi-sub">All-time completed payments</div>
        </div>
        <div class="kpi-card kpi-green">
            <div class="kpi-icon"><i class="bi bi-check-circle"></i></div>
            <div class="kpi-val"><?= number_format($total_bookings_n) ?></div>
            <div class="kpi-lbl">Confirmed Bookings</div>
            <div class="kpi-sub">Avg. fare: Rs.<?= number_format($avg_fare, 0) ?></div>
        </div>
        <div class="kpi-card kpi-amber">
            <div class="kpi-icon"><i class="bi bi-hourglass-split"></i></div>
            <div class="kpi-val"><?= $pending_bookings ?></div>
            <div class="kpi-lbl">Pending Bookings</div>
        </div>
        <div class="kpi-card kpi-red">
            <div class="kpi-icon"><i class="bi bi-x-circle"></i></div>
            <div class="kpi-val"><?= $cancellation_rate ?>%</div>
            <div class="kpi-lbl">Cancellation Rate</div>
            <div class="kpi-sub"><?= $cancelled_bkgs ?> cancelled</div>
        </div>
        <div class="kpi-card kpi-teal">
            <div class="kpi-icon"><i class="bi bi-train-front"></i></div>
            <div class="kpi-val"><?= $active_trains_n ?></div>
            <div class="kpi-lbl">Active Trains</div>
        </div>
        <div class="kpi-card kpi-purple">
            <div class="kpi-icon"><i class="bi bi-signpost-split"></i></div>
            <div class="kpi-val"><?= $active_routes_n ?></div>
            <div class="kpi-lbl">Upcoming Routes</div>
        </div>
        <div class="kpi-card kpi-indigo">
            <div class="kpi-icon"><i class="bi bi-people"></i></div>
            <div class="kpi-val"><?= $total_users_n ?></div>
            <div class="kpi-lbl">Registered Users</div>
        </div>
        <div class="kpi-card kpi-green">
            <div class="kpi-icon"><i class="bi bi-calendar-check"></i></div>
            <div class="kpi-val">Rs.<?= number_format($today_revenue, 0) ?></div>
            <div class="kpi-lbl">Today's Revenue</div>
            <div class="kpi-sub"><?= $today_bookings ?> bookings today</div>
        </div>
    </div>

    <!-- Overview Charts Row -->
    <div class="charts-row">
        <!-- Monthly Revenue Chart -->
        <div class="chart-card">
            <h5><i class="bi bi-graph-up me-2"></i>Monthly Revenue – <?= date('Y') ?></h5>
            <canvas id="monthlyOverviewChart"></canvas>
        </div>
        <!-- Booking Status Doughnut -->
        <div class="chart-card">
            <h5><i class="bi bi-pie-chart me-2"></i>Booking Status Distribution</h5>
            <canvas id="bookingStatusChart"></canvas>
        </div>
    </div>

    <!-- Second Charts Row -->
    <div class="charts-row">
        <!-- Top 5 Trains -->
        <div class="chart-card">
            <h5><i class="bi bi-trophy me-2"></i>Top 5 Trains by Revenue</h5>
            <canvas id="topTrainsChart"></canvas>
        </div>
        <!-- Payment Methods -->
        <div class="chart-card">
            <h5><i class="bi bi-credit-card me-2"></i>Payment Methods</h5>
            <canvas id="paymentMethodChart"></canvas>
        </div>
    </div>

    <!-- Third Charts Row -->
    <div class="charts-row">
        <!-- Revenue by City -->
        <div class="chart-card">
            <h5><i class="bi bi-geo-alt me-2"></i>Revenue by Departure City</h5>
            <canvas id="revByCityChart"></canvas>
        </div>
        <!-- Occupancy Rate -->
        <div class="chart-card">
            <h5><i class="bi bi-speedometer me-2"></i>Train Occupancy Rate (%)</h5>
            <canvas id="occupancyChart"></canvas>
        </div>
    </div>

    <!-- Report Tab Navigation -->
    <div class="rpt-tabs no-print">
        <a class="rpt-tab <?= $report_tab === 'overview'      ? 'active' : '' ?>" href="?tab=overview"><i class="bi bi-speedometer2 me-1"></i>Overview</a>
        <a class="rpt-tab <?= $report_tab === 'trains'        ? 'active' : '' ?>" href="?tab=trains"><i class="bi bi-train-front me-1"></i>Trains</a>
        <a class="rpt-tab <?= $report_tab === 'routes'        ? 'active' : '' ?>" href="?tab=routes"><i class="bi bi-map me-1"></i>Routes</a>
        <a class="rpt-tab <?= $report_tab === 'income_trains' ? 'active' : '' ?>" href="?tab=income_trains&period=<?= $period ?>&year=<?= $year ?>"><i class="bi bi-cash-stack me-1"></i>Income: Trains</a>
        <a class="rpt-tab <?= $report_tab === 'income_routes' ? 'active' : '' ?>" href="?tab=income_routes&period=<?= $period ?>&year=<?= $year ?>"><i class="bi bi-currency-dollar me-1"></i>Income: Routes</a>
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

    <!-- ============ OVERVIEW TAB ============ -->
    <?php if ($report_tab === 'overview'): ?>
    <!-- Daily Booking Trend -->
    <div class="chart-card">
        <h5><i class="bi bi-graph-up-arrow me-2"></i>Daily Booking Trend (Last 30 Days)</h5>
        <canvas id="dailyTrendChart" style="max-height:220px;"></canvas>
    </div>
    <!-- Train Type Comparison Table -->
    <div class="report-card">
        <h5><i class="bi bi-tags me-2"></i>Train Type Comparison</h5>
        <div class="table-responsive">
            <table class="rpt-table">
                <thead><tr><th>Type</th><th>Trains</th><th>Bookings</th><th>Revenue (Rs.)</th><th>Avg. Rev/Train</th></tr></thead>
                <tbody>
                    <?php foreach ($train_type_comp as $tt): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($tt['train_type']) ?></strong></td>
                        <td><?= (int)$tt['train_count'] ?></td>
                        <td><?= (int)$tt['bkgs'] ?></td>
                        <td>Rs. <?= number_format((float)$tt['rev'], 0) ?></td>
                        <td>Rs. <?= number_format((int)$tt['train_count'] > 0 ? (float)$tt['rev'] / (int)$tt['train_count'] : 0, 0) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============ TRAIN LIST ============ -->
    <?php if ($report_tab === 'trains'): ?>
    <div class="report-card">
        <div class="d-flex justify-content-between align-items-center mb-3 no-print">
            <h5 class="mb-0"><i class="bi bi-train-front me-2"></i>All Trains — Revenue Overview</h5>
            <div>
                <a href="manage-trains.php" class="btn btn-sm btn-primary me-1">Manage</a>
                <a href="?tab=trains&export=csv" class="btn btn-sm btn-outline-success"><i class="bi bi-download me-1"></i>CSV</a>
            </div>
        </div>
        <div class="table-responsive">
            <table class="rpt-table">
                <thead><tr><th>#</th><th>Train Name</th><th>Number</th><th>Type</th><th>Total Seats</th><th>Avail.</th><th>Status</th><th>Routes</th><th>Bookings</th><th>Revenue (Rs.)</th></tr></thead>
                <tbody>
                    <?php if (empty($trains)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">No trains found.</td></tr>
                    <?php else: foreach ($trains as $i => $t): ?>
                    <tr>
                        <td class="text-muted"><?= $i+1 ?></td>
                        <td><strong><?= htmlspecialchars($t['train_name']) ?></strong></td>
                        <td class="font-monospace"><?= htmlspecialchars($t['train_number']) ?></td>
                        <td><?= htmlspecialchars($t['train_type']) ?></td>
                        <td><?= $t['total_seats'] ?></td><td><?= $t['available_seats'] ?></td>
                        <td><span class="badge-<?= $t['status'] ?>"><?= ucfirst($t['status']) ?></span></td>
                        <td><?= $t['total_routes'] ?></td><td><?= $t['total_bookings'] ?></td>
                        <td><strong>Rs. <?= number_format($t['total_revenue'], 0) ?></strong></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <?php if (!empty($trains)): ?>
                <tfoot><tr><td colspan="9" class="text-end">Grand Total:</td><td><strong>Rs. <?= number_format(array_sum(array_column($trains,'total_revenue')),0) ?></strong></td></tr></tfoot>
                <?php endif; ?>
            </table>
        </div>
        <?php if (!empty($trains)): ?>
        <div class="mt-4"><canvas id="trainRevenueChart" style="max-height:300px;"></canvas></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ============ ROUTE LIST ============ -->
    <?php if ($report_tab === 'routes'): ?>
    <div class="report-card">
        <div class="d-flex justify-content-between align-items-center mb-3 no-print">
            <h5 class="mb-0"><i class="bi bi-map me-2"></i>All Routes</h5>
            <a href="manage-routes.php" class="btn btn-sm btn-primary">Manage Routes</a>
        </div>
        <div class="table-responsive">
            <table class="rpt-table">
                <thead><tr><th>#</th><th>Train</th><th>Route</th><th>Dep.</th><th>Arr.</th><th>Date</th><th>Dist(km)</th><th>Fare</th><th>Seats</th><th>Status</th><th>Bookings</th><th>Revenue</th></tr></thead>
                <tbody>
                    <?php if (empty($routes)): ?>
                    <tr><td colspan="12" class="text-center text-muted py-4">No routes found.</td></tr>
                    <?php else: foreach ($routes as $i => $r): $stClass = $r['status']==='scheduled'?'badge-scheduled':($r['status']==='completed'?'badge-completed':'badge-cancelled'); ?>
                    <tr>
                        <td class="text-muted"><?= $i+1 ?></td>
                        <td><?= htmlspecialchars($r['train_name']) ?></td>
                        <td><strong><?= htmlspecialchars($r['departure_city']) ?> → <?= htmlspecialchars($r['arrival_city']) ?></strong></td>
                        <td><?= date('H:i',strtotime($r['departure_time'])) ?></td>
                        <td><?= date('H:i',strtotime($r['arrival_time'])) ?></td>
                        <td><?= date('d M Y',strtotime($r['journey_date'])) ?></td>
                        <td><?= number_format($r['distance_km'],0) ?></td>
                        <td>Rs.<?= number_format($r['base_fare'],0) ?></td>
                        <td><?= $r['available_seats'] ?></td>
                        <td><span class="<?= $stClass ?>"><?= ucfirst($r['status']) ?></span></td>
                        <td><?= $r['total_bookings'] ?></td>
                        <td>Rs.<?= number_format($r['total_revenue'],0) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <?php if (!empty($routes)): ?>
                <tfoot><tr><td colspan="11" class="text-end">Grand Total:</td><td><strong>Rs. <?= number_format(array_sum(array_column($routes,'total_revenue')),0) ?></strong></td></tr></tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============ INCOME BY TRAIN ============ -->
    <?php if ($report_tab === 'income_trains'): ?>
    <div class="report-card">
        <h5><i class="bi bi-cash-stack me-2"></i><?= ucfirst($period) ?> Income by Train <?= $period!=='yearly'?"($year)":'' ?></h5>
        <div class="table-responsive">
            <table class="rpt-table">
                <thead><tr><th>#</th><th>Train</th><th>No.</th><th><?= $period_label ?></th><th>Bookings</th><th>Revenue (Rs.)</th></tr></thead>
                <tbody>
                    <?php if (empty($income_trains)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No income data.</td></tr>
                    <?php else: $gt=0; foreach ($income_trains as $i=>$row): $gt+=$row['total_revenue']; $pd=$period==='quarterly'?$row['yr'].' Q'.$row['qtr']:$row['period_label']; ?>
                    <tr><td><?= $i+1 ?></td><td><?= htmlspecialchars($row['train_name']) ?></td><td class="font-monospace"><?= htmlspecialchars($row['train_number']) ?></td><td><?= htmlspecialchars($pd) ?></td><td><?= $row['total_bookings'] ?></td><td><strong>Rs. <?= number_format($row['total_revenue'],0) ?></strong></td></tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <?php if (!empty($income_trains)): ?>
                <tfoot><tr><td colspan="5" class="text-end">Grand Total:</td><td><strong>Rs. <?= number_format($gt,0) ?></strong></td></tr></tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============ INCOME BY ROUTE ============ -->
    <?php if ($report_tab === 'income_routes'): ?>
    <div class="report-card">
        <h5><i class="bi bi-currency-dollar me-2"></i><?= ucfirst($period) ?> Income by Route <?= $period!=='yearly'?"($year)":'' ?></h5>
        <div class="table-responsive">
            <table class="rpt-table">
                <thead><tr><th>#</th><th>Route</th><th>Train</th><th><?= $period_label ?></th><th>Bookings</th><th>Revenue (Rs.)</th></tr></thead>
                <tbody>
                    <?php if (empty($income_routes)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No income data.</td></tr>
                    <?php else: $gt=0; foreach ($income_routes as $i=>$row): $gt+=$row['total_revenue']; $pd=$period==='quarterly'?$row['yr'].' Q'.$row['qtr']:$row['period_label']; ?>
                    <tr><td><?= $i+1 ?></td><td><strong><?= htmlspecialchars($row['route_name']) ?></strong></td><td><?= htmlspecialchars($row['train_name']) ?></td><td><?= htmlspecialchars($pd) ?></td><td><?= $row['total_bookings'] ?></td><td><strong>Rs. <?= number_format($row['total_revenue'],0) ?></strong></td></tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <?php if (!empty($income_routes)): ?>
                <tfoot><tr><td colspan="5" class="text-end">Grand Total:</td><td><strong>Rs. <?= number_format($gt,0) ?></strong></td></tr></tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
    <?php endif; ?>

</main>
</div>

<!-- ═══════ CHARTS JS ═══════ -->
<script>
document.addEventListener('DOMContentLoaded', function(){
    const chartOptions = { responsive:true, maintainAspectRatio:true, plugins:{ legend:{position:'top',labels:{boxWidth:12,padding:12,font:{size:11}}} } };

    // Monthly Revenue Chart
    <?php if (!empty($monthly_overview)): ?>
    new Chart(document.getElementById('monthlyOverviewChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($monthly_overview,'mon')) ?>,
            datasets: [
                { label:'Revenue (Rs.)', data:<?= json_encode(array_map('floatval',array_column($monthly_overview,'revenue'))) ?>, backgroundColor:'rgba(37,99,235,0.7)', borderColor:'#1e40af', borderWidth:1, yAxisID:'y', borderRadius:4 },
                { label:'Bookings', data:<?= json_encode(array_map('intval',array_column($monthly_overview,'bookings'))) ?>, type:'line', borderColor:'#f59e0b', backgroundColor:'rgba(245,158,11,.1)', tension:.35, pointRadius:3, yAxisID:'y1' }
            ]
        },
        options: {
            ...chartOptions,
            scales:{ y:{beginAtZero:true,position:'left',title:{display:true,text:'Revenue (Rs.)'}}, y1:{beginAtZero:true,position:'right',grid:{drawOnChartArea:false},title:{display:true,text:'Bookings'}} }
        }
    });
    <?php endif; ?>

    // Booking Status Doughnut
    new Chart(document.getElementById('bookingStatusChart'), {
        type: 'doughnut',
        data: {
            labels: ['Confirmed','Pending','Cancelled'],
            datasets: [{ data: [<?= $bs_map['confirmed'] ?>,<?= $bs_map['pending'] ?>,<?= $bs_map['cancelled'] ?>], backgroundColor: ['#16a34a','#f59e0b','#ef4444'], borderWidth:0 }]
        },
        options: { ...chartOptions, cutout:'65%' }
    });

    // Top 5 Trains
    <?php if (!empty($top_trains)): ?>
    new Chart(document.getElementById('topTrainsChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($top_trains,'train_name')) ?>,
            datasets: [{ label:'Revenue (Rs.)', data:<?= json_encode(array_map('floatval',array_column($top_trains,'rev'))) ?>, backgroundColor:['#2563eb','#7c3aed','#059669','#d97706','#dc2626'], borderRadius:4 }]
        },
        options: { ...chartOptions, indexAxis:'y', plugins:{...chartOptions.plugins, legend:{display:false}} }
    });
    <?php endif; ?>

    // Payment Methods
    <?php if (!empty($payment_method_dist)): ?>
    new Chart(document.getElementById('paymentMethodChart'), {
        type: 'pie',
        data: {
            labels: <?= json_encode(array_column($payment_method_dist,'payment_method')) ?>,
            datasets: [{ data:<?= json_encode(array_map('intval',array_column($payment_method_dist,'cnt'))) ?>, backgroundColor:['#2563eb','#7c3aed','#059669','#d97706','#dc2626','#0891b2'] }]
        },
        options: chartOptions
    });
    <?php endif; ?>

    // Revenue by City
    <?php if (!empty($rev_by_city)): ?>
    new Chart(document.getElementById('revByCityChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($rev_by_city,'city')) ?>,
            datasets: [{ label:'Revenue (Rs.)', data:<?= json_encode(array_map('floatval',array_column($rev_by_city,'rev'))) ?>, backgroundColor:'rgba(37,99,235,0.7)', borderRadius:4 }]
        },
        options: { ...chartOptions, plugins:{...chartOptions.plugins, legend:{display:false}} }
    });
    <?php endif; ?>

    // Occupancy Rate
    <?php if (!empty($occupancy)): ?>
    new Chart(document.getElementById('occupancyChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($occupancy,'train_name')) ?>,
            datasets: [{ label:'Occupancy %', data:<?= json_encode(array_map('floatval',array_column($occupancy,'pct'))) ?>, backgroundColor: <?= json_encode(array_map(function($o){ $p=(float)$o['pct']; return $p>=80?'rgba(239,68,68,0.7)':($p>=40?'rgba(245,158,11,0.7)':'rgba(34,197,94,0.7)'); },$occupancy)) ?>, borderRadius:4 }]
        },
        options: { ...chartOptions, plugins:{...chartOptions.plugins, legend:{display:false}}, scales:{y:{max:100,beginAtZero:true,title:{display:true,text:'%'}} } }
    });
    <?php endif; ?>

    // Train Revenue Chart (trains tab)
    <?php if ($report_tab==='trains' && !empty($trains)): ?>
    new Chart(document.getElementById('trainRevenueChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($trains,'train_name')) ?>,
            datasets: [{ label:'Revenue (Rs.)', data:<?= json_encode(array_map('floatval',array_column($trains,'total_revenue'))) ?>, backgroundColor:'rgba(37,99,235,0.7)', borderColor:'#1e40af', borderWidth:1, borderRadius:4 }]
        },
        options: { ...chartOptions, plugins:{...chartOptions.plugins, legend:{display:false}, title:{display:true,text:'Revenue per Train'}}, scales:{y:{beginAtZero:true}} }
    });
    <?php endif; ?>

    // Daily Booking Trend
    <?php if (!empty($daily_trend)): ?>
    new Chart(document.getElementById('dailyTrendChart'), {
        type: 'line',
        data: {
            labels: <?= json_encode(array_map(function($d){return date('d M',strtotime($d['dt']));},$daily_trend)) ?>,
            datasets: [
                { label:'Bookings', data:<?= json_encode(array_map('intval',array_column($daily_trend,'cnt'))) ?>, borderColor:'#2563eb', backgroundColor:'rgba(37,99,235,.1)', fill:true, tension:.3, pointRadius:2 },
                { label:'Revenue (Rs.)', data:<?= json_encode(array_map('floatval',array_column($daily_trend,'rev'))) ?>, borderColor:'#f59e0b', backgroundColor:'transparent', tension:.3, pointRadius:2, yAxisID:'y1' }
            ]
        },
        options: { ...chartOptions, scales:{ y:{beginAtZero:true,title:{display:true,text:'Bookings'}}, y1:{beginAtZero:true,position:'right',grid:{drawOnChartArea:false},title:{display:true,text:'Revenue (Rs.)'}} } }
    });
    <?php endif; ?>
});
</script>

<?php require_once 'inc/footer.php'; ?>

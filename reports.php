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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Railway Management System Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="public/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        body { background: #f0f2f5; }
        .page-header { background: linear-gradient(135deg,#1a3c6e,#2d6a9f); color:#fff; padding: 1.5rem 2rem; border-radius: 10px; margin-bottom: 2rem; }
        .stat-card { background:#fff; border-radius:10px; padding:1.2rem 1.5rem; box-shadow:0 2px 8px rgba(0,0,0,0.08); text-align:center; }
        .stat-card .value { font-size:2rem; font-weight:700; color:#1a3c6e; }
        .stat-card .label { color:#666; font-size:0.9rem; }
        .report-card { background:#fff; border-radius:10px; padding:1.5rem; box-shadow:0 2px 8px rgba(0,0,0,0.08); margin-bottom:2rem; }
        .table th { background:#1a3c6e; color:#fff; }
        .badge-active  { background:#d4edda; color:#155724; padding:0.3em 0.7em; border-radius:12px; font-size:0.8rem; }
        .badge-inactive{ background:#f8d7da; color:#721c24; padding:0.3em 0.7em; border-radius:12px; font-size:0.8rem; }
        .badge-maintenance{ background:#fff3cd; color:#856404; padding:0.3em 0.7em; border-radius:12px; font-size:0.8rem; }
        .filter-bar { background:#fff; border-radius:10px; padding:1rem 1.5rem; box-shadow:0 2px 8px rgba(0,0,0,0.08); margin-bottom:1.5rem; }
        @media print {
            .no-print { display:none !important; }
            body { background:white; }
            .report-card { box-shadow:none; }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark no-print">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php">🚂 Railway System</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item"><a class="nav-link" href="admin-dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="manage-trains.php">Trains</a></li>
                    <li class="nav-item"><a class="nav-link" href="manage-routes.php">Routes</a></li>
                    <li class="nav-item"><a class="nav-link" href="manage-users.php">Users</a></li>
                    <li class="nav-item"><a class="nav-link active fw-bold" href="reports.php">Reports</a></li>
                    <li class="nav-item"><a class="nav-link text-danger" href="logout.php">Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container py-4">

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

        <!-- Summary Stats -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="value"><?= number_format($total_revenue['total'], 0) ?></div>
                    <div class="label">Total Revenue (Rs.)</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="value"><?= $total_bookings['total'] ?></div>
                    <div class="label">Confirmed Bookings</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="value"><?= $active_trains['total'] ?></div>
                    <div class="label">Active Trains</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="value"><?= $active_routes['total'] ?></div>
                    <div class="label">Scheduled Routes</div>
                </div>
            </div>
        </div>

        <!-- Monthly Revenue Overview Chart -->
        <?php if (!empty($monthly_overview)): ?>
        <div class="report-card mb-4">
            <h5 class="mb-3"><i class="bi bi-bar-chart-line me-2"></i>Monthly Revenue Overview – <?= date('Y') ?></h5>
            <canvas id="monthlyOverviewChart" style="max-height:280px;"></canvas>
            <script>
            (function(){
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
            })();
            </script>
        </div>
        <?php endif; ?>

        <!-- Tab Navigation -->
        <ul class="nav nav-tabs mb-4 no-print" id="reportTabs">            <li class="nav-item">
                <a class="nav-link <?= $report_tab === 'trains' ? 'active' : '' ?>" href="?tab=trains&period=<?= $period ?>&year=<?= $year ?>">
                    <i class="bi bi-train-front me-1"></i>Train List
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $report_tab === 'routes' ? 'active' : '' ?>" href="?tab=routes&period=<?= $period ?>&year=<?= $year ?>">
                    <i class="bi bi-map me-1"></i>Route List
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $report_tab === 'income_trains' ? 'active' : '' ?>" href="?tab=income_trains&period=<?= $period ?>&year=<?= $year ?>">
                    <i class="bi bi-cash-stack me-1"></i>Income by Train
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $report_tab === 'income_routes' ? 'active' : '' ?>" href="?tab=income_routes&period=<?= $period ?>&year=<?= $year ?>">
                    <i class="bi bi-currency-dollar me-1"></i>Income by Route
                </a>
            </li>
        </ul>

        <!-- Period Filter (for income tabs) -->
        <?php if (in_array($report_tab, ['income_trains', 'income_routes'])): ?>
        <div class="filter-bar d-flex flex-wrap gap-3 align-items-center no-print">
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-center">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($report_tab) ?>">
                <div>
                    <label class="me-1 fw-bold">Period:</label>
                    <select name="period" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()">
                        <option value="monthly"   <?= $period === 'monthly'   ? 'selected' : '' ?>>Monthly</option>
                        <option value="quarterly" <?= $period === 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
                        <option value="yearly"    <?= $period === 'yearly'    ? 'selected' : '' ?>>Yearly</option>
                    </select>
                </div>
                <?php if ($period !== 'yearly'): ?>
                <div>
                    <label class="me-1 fw-bold">Year:</label>
                    <select name="year" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()">
                        <?php foreach ($available_years as $y): ?>
                            <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </form>
        </div>
        <?php endif; ?>

        <!-- ============ TRAIN LIST ============ -->
        <?php if ($report_tab === 'trains'): ?>
        <div class="report-card">
            <div class="d-flex justify-content-between align-items-center mb-3 no-print">
                <h5 class="mb-0"><i class="bi bi-train-front me-2"></i>List of All Trains</h5>
                <a href="manage-trains.php" class="btn btn-sm btn-primary">Manage Trains</a>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Train Name</th>
                            <th>Train Number</th>
                            <th>Type</th>
                            <th>Total Seats</th>
                            <th>Available Seats</th>
                            <th>Status</th>
                            <th>Total Routes</th>
                            <th>Confirmed Bookings</th>
                            <th>Total Revenue (Rs.)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($trains)): ?>
                            <tr><td colspan="10" class="text-center text-muted">No trains found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($trains as $i => $t): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><strong><?= htmlspecialchars($t['train_name']) ?></strong></td>
                                <td><?= htmlspecialchars($t['train_number']) ?></td>
                                <td><?= htmlspecialchars($t['train_type']) ?></td>
                                <td><?= $t['total_seats'] ?></td>
                                <td><?= $t['available_seats'] ?></td>
                                <td>
                                    <span class="badge-<?= $t['status'] ?>">
                                        <?= ucfirst($t['status']) ?>
                                    </span>
                                </td>
                                <td><?= $t['total_routes'] ?></td>
                                <td><?= $t['total_bookings'] ?></td>
                                <td><strong><?= number_format($t['total_revenue'], 2) ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="table-secondary fw-bold">
                                <td colspan="9" class="text-end">Grand Total Revenue:</td>
                                <td>Rs. <?= number_format(array_sum(array_column($trains, 'total_revenue')), 2) ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <!-- Chart -->
            <?php if (!empty($trains)): ?>
            <div class="mt-4" style="max-width:700px;">
                <canvas id="trainRevenueChart"></canvas>
            </div>
            <script>
            (function(){
                const labels = <?= json_encode(array_column($trains, 'train_name')) ?>;
                const data   = <?= json_encode(array_map('floatval', array_column($trains, 'total_revenue'))) ?>;
                new Chart(document.getElementById('trainRevenueChart'), {
                    type: 'bar',
                    data: { labels, datasets:[{ label:'Revenue (Rs.)', data, backgroundColor:'rgba(26,60,110,0.7)', borderColor:'#1a3c6e', borderWidth:1 }] },
                    options:{ responsive:true, plugins:{ legend:{display:false}, title:{display:true,text:'Revenue per Train'} }, scales:{ y:{beginAtZero:true} } }
                });
            })();
            </script>
            <?php endif; ?>
        </div>

        <!-- ============ ROUTE LIST ============ -->
        <?php elseif ($report_tab === 'routes'): ?>
        <div class="report-card">
            <div class="d-flex justify-content-between align-items-center mb-3 no-print">
                <h5 class="mb-0"><i class="bi bi-map me-2"></i>List of All Routes</h5>
                <a href="manage-routes.php" class="btn btn-sm btn-primary">Manage Routes</a>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Train</th>
                            <th>Route</th>
                            <th>Departure</th>
                            <th>Arrival</th>
                            <th>Date</th>
                            <th>Distance (km)</th>
                            <th>Base Fare (Rs.)</th>
                            <th>Available Seats</th>
                            <th>Status</th>
                            <th>Bookings</th>
                            <th>Revenue (Rs.)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($routes)): ?>
                            <tr><td colspan="12" class="text-center text-muted">No routes found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($routes as $i => $r): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($r['train_name']) ?></td>
                                <td><strong><?= htmlspecialchars($r['departure_city']) ?> → <?= htmlspecialchars($r['arrival_city']) ?></strong></td>
                                <td><?= date('H:i', strtotime($r['departure_time'])) ?></td>
                                <td><?= date('H:i', strtotime($r['arrival_time'])) ?></td>
                                <td><?= date('d M Y', strtotime($r['journey_date'])) ?></td>
                                <td><?= number_format($r['distance_km'], 0) ?></td>
                                <td><?= number_format($r['base_fare'], 2) ?></td>
                                <td><?= $r['available_seats'] ?></td>
                                <td>
                                    <span class="badge-<?= $r['status'] === 'scheduled' ? 'active' : ($r['status'] === 'cancelled' ? 'inactive' : 'maintenance') ?>">
                                        <?= ucfirst($r['status']) ?>
                                    </span>
                                </td>
                                <td><?= $r['total_bookings'] ?></td>
                                <td><?= number_format($r['total_revenue'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="table-secondary fw-bold">
                                <td colspan="11" class="text-end">Grand Total Revenue:</td>
                                <td>Rs. <?= number_format(array_sum(array_column($routes, 'total_revenue')), 2) ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ============ INCOME BY TRAIN ============ -->
        <?php elseif ($report_tab === 'income_trains'): ?>
        <div class="report-card">
            <h5 class="mb-3"><i class="bi bi-cash-stack me-2"></i>
                <?= ucfirst($period) ?> Income by Train
                <?= $period !== 'yearly' ? "($year)" : '' ?>
            </h5>
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Train Name</th>
                            <th>Train No.</th>
                            <th><?= $period_label ?></th>
                            <th>Bookings</th>
                            <th>Revenue (Rs.)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($income_trains)): ?>
                            <tr><td colspan="6" class="text-center text-muted">No income data found for the selected period.</td></tr>
                        <?php else: ?>
                            <?php
                            $grand_total = 0;
                            foreach ($income_trains as $i => $row):
                                $grand_total += $row['total_revenue'];
                                $period_display = $period === 'quarterly'
                                    ? $row['yr'] . ' Q' . $row['qtr']
                                    : $row['period_label'];
                            ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($row['train_name']) ?></td>
                                <td><?= htmlspecialchars($row['train_number']) ?></td>
                                <td><?= htmlspecialchars($period_display) ?></td>
                                <td><?= $row['total_bookings'] ?></td>
                                <td><strong><?= number_format($row['total_revenue'], 2) ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="table-secondary fw-bold">
                                <td colspan="5" class="text-end">Grand Total:</td>
                                <td>Rs. <?= number_format($grand_total, 2) ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <!-- Chart -->
            <?php if (!empty($income_trains)): ?>
            <?php
            // Build chart data: aggregate by period label
            $chart_data_trains = array();
            foreach ($income_trains as $row) {
                $lbl = $period === 'quarterly' ? $row['yr'].' Q'.$row['qtr'] : $row['period_label'];
                if (!isset($chart_data_trains[$lbl])) $chart_data_trains[$lbl] = 0;
                $chart_data_trains[$lbl] += floatval($row['total_revenue']);
            }
            ?>
            <div class="mt-4" style="max-width:700px;">
                <canvas id="incomePeriodChart"></canvas>
            </div>
            <script>
            (function(){
                const labels = <?= json_encode(array_keys($chart_data_trains)) ?>;
                const data   = <?= json_encode(array_values($chart_data_trains)) ?>;
                new Chart(document.getElementById('incomePeriodChart'), {
                    type: 'line',
                    data: { labels, datasets:[{ label:'Revenue (Rs.)', data, fill:true, backgroundColor:'rgba(45,106,159,0.15)', borderColor:'#2d6a9f', tension:0.3, pointBackgroundColor:'#1a3c6e' }] },
                    options:{ responsive:true, plugins:{ title:{display:true,text:'<?= ucfirst($period) ?> Income (All Trains)'} }, scales:{ y:{beginAtZero:true} } }
                });
            })();
            </script>
            <?php endif; ?>
        </div>

        <!-- ============ INCOME BY ROUTE ============ -->
        <?php elseif ($report_tab === 'income_routes'): ?>
        <div class="report-card">
            <h5 class="mb-3"><i class="bi bi-currency-dollar me-2"></i>
                <?= ucfirst($period) ?> Income by Route
                <?= $period !== 'yearly' ? "($year)" : '' ?>
            </h5>
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Route</th>
                            <th>Train</th>
                            <th><?= $period_label ?></th>
                            <th>Bookings</th>
                            <th>Revenue (Rs.)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($income_routes)): ?>
                            <tr><td colspan="6" class="text-center text-muted">No income data found for the selected period.</td></tr>
                        <?php else: ?>
                            <?php
                            $grand_total = 0;
                            foreach ($income_routes as $i => $row):
                                $grand_total += $row['total_revenue'];
                                $period_display = $period === 'quarterly'
                                    ? $row['yr'] . ' Q' . $row['qtr']
                                    : $row['period_label'];
                            ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><strong><?= htmlspecialchars($row['route_name']) ?></strong></td>
                                <td><?= htmlspecialchars($row['train_name']) ?></td>
                                <td><?= htmlspecialchars($period_display) ?></td>
                                <td><?= $row['total_bookings'] ?></td>
                                <td><strong><?= number_format($row['total_revenue'], 2) ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="table-secondary fw-bold">
                                <td colspan="5" class="text-end">Grand Total:</td>
                                <td>Rs. <?= number_format($grand_total, 2) ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <!-- Chart -->
            <?php if (!empty($income_routes)): ?>
            <?php
            $chart_data_routes = array();
            foreach ($income_routes as $row) {
                $lbl = $period === 'quarterly' ? $row['yr'].' Q'.$row['qtr'] : $row['period_label'];
                if (!isset($chart_data_routes[$lbl])) $chart_data_routes[$lbl] = 0;
                $chart_data_routes[$lbl] += floatval($row['total_revenue']);
            }
            ?>
            <div class="mt-4" style="max-width:700px;">
                <canvas id="incomeRouteChart"></canvas>
            </div>
            <script>
            (function(){
                const labels = <?= json_encode(array_keys($chart_data_routes)) ?>;
                const data   = <?= json_encode(array_values($chart_data_routes)) ?>;
                new Chart(document.getElementById('incomeRouteChart'), {
                    type: 'bar',
                    data: { labels, datasets:[{ label:'Revenue (Rs.)', data, backgroundColor:'rgba(45,106,159,0.7)', borderColor:'#1a3c6e', borderWidth:1 }] },
                    options:{ responsive:true, plugins:{ title:{display:true,text:'<?= ucfirst($period) ?> Income (All Routes)'} }, scales:{ y:{beginAtZero:true} } }
                });
            })();
            </script>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div><!-- /container -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

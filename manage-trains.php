<?php
// manage-trains.php - Manage Trains (Admin)

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/Train.php';

if (!User::isLoggedIn() || $_SESSION['role'] != 'admin') {
    header('Location: login.php');
    exit();
}

function manageTrainsUrl(string $status, string $type, string $search): string {
    $params = array_filter([
        'status' => $status !== 'all' ? $status : null,
        'type' => $type !== 'all' ? $type : null,
        'q' => $search !== '' ? $search : null,
    ]);

    return 'manage-trains.php' . ($params ? '?' . http_build_query($params) : '');
}

$db = new Database();
$db->connect();
$conn = $db->getConnection();

$train_obj = new Train($db);
$user_obj = new User($db);
$user = $user_obj->getUserById($_SESSION['user_id']);

$filter_status = $_GET['status'] ?? 'all';
$filter_type = $_GET['type'] ?? 'all';
$search = trim($_GET['q'] ?? '');

$flash_key = 'manage_trains_flash';
$success_message = '';
$error_message = '';

if (!empty($_SESSION[$flash_key])) {
    if (($_SESSION[$flash_key]['type'] ?? '') === 'success') {
        $success_message = $_SESSION[$flash_key]['message'] ?? '';
    } else {
        $error_message = $_SESSION[$flash_key]['message'] ?? '';
    }
    unset($_SESSION[$flash_key]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $flash_type = 'danger';
    $flash_message = 'Unable to process the train request.';

    $train_name = trim($_POST['train_name'] ?? '');
    $train_number = trim($_POST['train_number'] ?? '');
    $train_type = trim($_POST['train_type'] ?? '');
    $total_seats = (int)($_POST['total_seats'] ?? 0);
    $status = trim($_POST['status'] ?? 'active');

    $allowed_types = ['Express', 'Mail', 'Passenger', 'Freight'];
    $allowed_statuses = ['active', 'inactive', 'maintenance'];

    if ($action === 'add' || $action === 'edit') {
        if ($train_name === '' || $train_number === '') {
            $flash_message = 'Train name and train number are required.';
        } elseif (!in_array($train_type, $allowed_types, true)) {
            $flash_message = 'Select a valid train type.';
        } elseif (!in_array($status, $allowed_statuses, true)) {
            $flash_message = 'Select a valid train status.';
        } elseif ($total_seats < 1) {
            $flash_message = 'Total seats must be greater than zero.';
        } elseif ($action === 'add') {
            $train_id = $train_obj->createTrain([
                'train_name' => $train_name,
                'train_number' => $train_number,
                'train_type' => $train_type,
                'total_seats' => $total_seats,
                'available_seats' => $total_seats,
                'status' => $status,
            ]);

            if ($train_id) {
                $flash_type = 'success';
                $flash_message = 'Train added successfully.';
            } else {
                $db_error = $db->getError();
                $flash_message = stripos($db_error, 'Duplicate') !== false
                    ? 'A train with this number already exists.'
                    : 'Failed to add train.';
            }
        } else {
            $train_id = (int)($_POST['train_id'] ?? 0);
            $existing_train = $train_obj->getTrainById($train_id);

            if (!$existing_train) {
                $flash_message = 'Selected train was not found.';
            } else {
                $route_count = (int)($db->selectRow("SELECT COUNT(*) AS c FROM routes WHERE train_id = {$train_id}")['c'] ?? 0);

                if ($route_count > 0 && $total_seats !== (int)$existing_train['total_seats']) {
                    $flash_message = 'Total seats cannot be changed after routes have been created for a train.';
                } else {
                    $data = [
                        'train_name' => $train_name,
                        'train_number' => $train_number,
                        'train_type' => $train_type,
                        'status' => $status,
                        'total_seats' => $total_seats,
                        'available_seats' => $route_count > 0 ? (int)$existing_train['available_seats'] : $total_seats,
                    ];

                    if ($db->update('trains', 'train_id', $train_id, $data)) {
                        $flash_type = 'success';
                        $flash_message = 'Train updated successfully.';
                    } else {
                        $db_error = $db->getError();
                        $flash_message = stripos($db_error, 'Duplicate') !== false
                            ? 'A train with this number already exists.'
                            : 'Failed to update train.';
                    }
                }
            }
        }
    } elseif ($action === 'delete') {
        $train_id = (int)($_POST['train_id'] ?? 0);
        $route_count = (int)($db->selectRow("SELECT COUNT(*) AS c FROM routes WHERE train_id = {$train_id}")['c'] ?? 0);

        if ($route_count > 0) {
            $flash_message = 'This train already has routes assigned. Remove or reassign those routes before deleting the train.';
        } elseif ($train_id > 0 && $db->delete('trains', 'train_id', $train_id)) {
            $flash_type = 'success';
            $flash_message = 'Train deleted successfully.';
        } else {
            $flash_message = 'Failed to delete train.';
        }
    }

    $_SESSION[$flash_key] = [
        'type' => $flash_type,
        'message' => $flash_message,
    ];

    header('Location: ' . manageTrainsUrl($filter_status, $filter_type, $search));
    exit();
}

$where_parts = [];

if ($filter_status !== 'all') {
    $safe_status = $conn->real_escape_string($filter_status);
    $where_parts[] = "t.status = '{$safe_status}'";
}
if ($filter_type !== 'all') {
    $safe_type = $conn->real_escape_string($filter_type);
    $where_parts[] = "t.train_type = '{$safe_type}'";
}
if ($search !== '') {
    $safe_search = $conn->real_escape_string($search);
    $where_parts[] = "(t.train_name LIKE '%{$safe_search}%' OR t.train_number LIKE '%{$safe_search}%' OR t.train_type LIKE '%{$safe_search}%')";
}

$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

$all_trains = $db->select(
    "SELECT t.*,
            COALESCE(route_stats.total_routes, 0) AS total_routes,
            COALESCE(route_stats.today_routes, 0) AS today_routes,
            COALESCE(route_stats.upcoming_routes, 0) AS upcoming_routes,
            COALESCE(route_stats.scheduled_routes, 0) AS scheduled_routes,
            COALESCE(route_stats.completed_routes, 0) AS completed_routes,
            COALESCE(route_stats.cancelled_routes, 0) AS cancelled_routes,
            COALESCE(booking_stats.confirmed_bookings, 0) AS confirmed_bookings,
            COALESCE(booking_stats.total_revenue, 0) AS total_revenue
     FROM trains t
     LEFT JOIN (
        SELECT train_id,
               COUNT(*) AS total_routes,
               SUM(CASE WHEN journey_date = CURDATE() THEN 1 ELSE 0 END) AS today_routes,
               SUM(CASE WHEN journey_date >= CURDATE() AND status = 'scheduled' THEN 1 ELSE 0 END) AS upcoming_routes,
               SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) AS scheduled_routes,
               SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_routes,
               SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_routes
        FROM routes
        GROUP BY train_id
     ) route_stats ON route_stats.train_id = t.train_id
     LEFT JOIN (
        SELECT r.train_id,
               SUM(CASE WHEN b.booking_status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_bookings,
               SUM(CASE WHEN b.payment_status = 'completed' THEN b.total_fare ELSE 0 END) AS total_revenue
        FROM routes r
        LEFT JOIN bookings b ON b.route_id = r.route_id
        GROUP BY r.train_id
     ) booking_stats ON booking_stats.train_id = t.train_id
     {$where_sql}
     ORDER BY CASE t.status WHEN 'active' THEN 0 WHEN 'maintenance' THEN 1 ELSE 2 END, t.train_name ASC"
);
if (!$all_trains) {
    $all_trains = [];
}

$total_trains = count($all_trains);
$active_trains = count(array_filter($all_trains, fn($train) => $train['status'] === 'active'));
$maintenance_trains = count(array_filter($all_trains, fn($train) => $train['status'] === 'maintenance'));
$inactive_trains = count(array_filter($all_trains, fn($train) => $train['status'] === 'inactive'));
$upcoming_services = array_sum(array_map('intval', array_column($all_trains, 'upcoming_routes')));
$average_capacity = $total_trains > 0 ? (int)round(array_sum(array_map('intval', array_column($all_trains, 'total_seats'))) / $total_trains) : 0;
$confirmed_bookings_total = array_sum(array_map('intval', array_column($all_trains, 'confirmed_bookings')));

$top_train = null;
foreach ($all_trains as $train) {
    if ($top_train === null || (float)$train['total_revenue'] > (float)$top_train['total_revenue']) {
        $top_train = $train;
    }
}

$watch_list = array_values(array_filter(
    $all_trains,
    fn($train) => $train['status'] === 'maintenance' || $train['status'] === 'inactive'
));

$hideMainNavbar = true;
$pageTitle = 'Manage Trains – Admin';
require_once 'inc/header.php';
?>

<style>
.adm-wrap { display:flex; min-height:calc(100vh - 64px); }
.adm-sidebar {
    width:240px; flex-shrink:0;
    background:linear-gradient(180deg,#1a2e4a 0%,#0f1e32 100%);
    color:#c8d6e8; display:flex; flex-direction:column;
    position:sticky; top:64px; height:calc(100vh - 64px); overflow-y:auto;
}
.adm-sidebar .sb-brand {
    padding:1.4rem 1.5rem 1rem;
    border-bottom:1px solid rgba(255,255,255,.08);
}
.adm-sidebar .sb-brand span { display:block; font-size:.7rem; text-transform:uppercase; letter-spacing:1.5px; opacity:.5; margin-bottom:.3rem; }
.adm-sidebar .sb-brand strong { font-size:1rem; color:#fff; }
.adm-sidebar nav { flex:1; padding:.75rem 0; }
.adm-sidebar nav a {
    display:flex; align-items:center; gap:.75rem;
    padding:.65rem 1.5rem; color:#c8d6e8; text-decoration:none;
    font-size:.875rem; font-weight:500; transition:all .2s;
    border-left:3px solid transparent;
}
.adm-sidebar nav a:hover,
.adm-sidebar nav a.active {
    background:rgba(255,255,255,.07); color:#fff; border-left-color:#3b82f6;
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

.adm-main { flex:1; padding:2rem; overflow-x:hidden; }
.adm-page-header {
    display:flex; justify-content:space-between; align-items:flex-start;
    gap:1rem; flex-wrap:wrap; margin-bottom:1.5rem;
}
.adm-page-header h2 { margin:0; font-size:1.6rem; font-weight:800; color:#0f172a; }
.adm-page-header p { margin:.2rem 0 0; color:#64748b; font-size:.9rem; }

.metric-grid {
    display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:1rem; margin-bottom:1.5rem;
}
.metric-card {
    background:#fff; border-radius:14px; padding:1.2rem 1.3rem; box-shadow:0 1px 4px rgba(0,0,0,.07);
}
.metric-card .icon {
    width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center;
    font-size:1.2rem; margin-bottom:.8rem;
}
.metric-card .value { font-size:1.8rem; font-weight:800; line-height:1; color:#0f172a; }
.metric-card .label { font-size:.8rem; color:#64748b; margin-top:.3rem; }
.metric-card.blue .icon { background:#dbeafe; color:#2563eb; }
.metric-card.green .icon { background:#dcfce7; color:#16a34a; }
.metric-card.amber .icon { background:#fef3c7; color:#d97706; }
.metric-card.purple .icon { background:#ede9fe; color:#7c3aed; }

.surface-card {
    background:#fff; border-radius:14px; padding:1.25rem; box-shadow:0 1px 4px rgba(0,0,0,.07);
}
.card-title-row {
    display:flex; justify-content:space-between; align-items:center; gap:1rem; margin-bottom:1rem;
}
.card-title-row h4 { margin:0; font-size:.95rem; font-weight:700; color:#0f172a; }
.card-title-row a { font-size:.8rem; text-decoration:none; font-weight:600; color:#3b82f6; }

.filter-grid {
    display:grid; grid-template-columns:2fr 1fr 1fr auto; gap:.75rem; align-items:end;
}
.focus-list { display:flex; flex-direction:column; gap:.85rem; }
.focus-item {
    display:flex; justify-content:space-between; align-items:flex-start; gap:.75rem;
    padding-bottom:.85rem; border-bottom:1px solid #f1f5f9;
}
.focus-item:last-child { padding-bottom:0; border-bottom:none; }

.train-table thead th {
    background:#0f1e32; color:#fff; font-size:.8rem; font-weight:600; white-space:nowrap;
}
.train-table td { font-size:.85rem; vertical-align:middle; }
.train-table tbody tr:hover { background:#f8fafc; }

.meta-line { font-size:.75rem; color:#64748b; }
.pill { display:inline-block; padding:.25em .75em; border-radius:999px; font-size:.75rem; font-weight:600; }
.pill-active { background:#dcfce7; color:#166534; }
.pill-maintenance { background:#fef3c7; color:#92400e; }
.pill-inactive { background:#e2e8f0; color:#334155; }
.pill-info { background:#dbeafe; color:#1d4ed8; }

.progress-shell { background:#e2e8f0; border-radius:999px; height:7px; overflow:hidden; }
.progress-fill { height:100%; border-radius:999px; background:linear-gradient(90deg,#3b82f6,#6366f1); }
.action-stack { display:flex; gap:.45rem; flex-wrap:wrap; }
.modal-section-label { font-size:.72rem; text-transform:uppercase; letter-spacing:.08em; color:#64748b; font-weight:700; }

@media (max-width:1100px) {
    .filter-grid { grid-template-columns:1fr 1fr; }
}
@media (max-width:900px) {
    .adm-sidebar { display:none; }
    .adm-main { padding:1rem; }
}
@media (max-width:640px) {
    .filter-grid { grid-template-columns:1fr; }
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
            <a href="manage-trains.php" class="active"><i class="bi bi-train-front"></i> Trains</a>
            <a href="train-seats-report.php"><i class="bi bi-diagram-3"></i> Seat Report</a>
            <a href="manage-routes.php"><i class="bi bi-map"></i> Routes</a>
            <a href="operations-hub.php?tab=stations"><i class="bi bi-building"></i> Stations</a>
            <a href="manage-bookings.php"><i class="bi bi-ticket-perforated"></i> Bookings</a>
            <a href="manage-payments.php"><i class="bi bi-cash-stack"></i> Payments</a>
            <a href="operations-hub.php"><i class="bi bi-diagram-3"></i> Operations Hub</a>

            <div class="sb-sep">Users</div>
            <a href="manage-users.php"><i class="bi bi-people"></i> Users</a>

            <div class="sb-sep">System</div>
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

    <section class="adm-main">
        <div class="adm-page-header">
            <div>
                <h2>Train Operations</h2>
                <p>Manage fleet records, review service readiness, and keep train metadata aligned with operational routes.</p>
            </div>
            <button type="button" class="btn btn-primary" id="openAddTrainBtn">
                <i class="bi bi-plus-circle me-1"></i>Add Train
            </button>
        </div>

        <?php if ($success_message): ?>
        <div class="alert alert-success border-0 shadow-sm"><?= htmlspecialchars($success_message) ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
        <div class="alert alert-danger border-0 shadow-sm"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>

        <div class="metric-grid">
            <div class="metric-card blue">
                <div class="icon"><i class="bi bi-train-front-fill"></i></div>
                <div class="value"><?= number_format($total_trains) ?></div>
                <div class="label">Trains in current view</div>
            </div>
            <div class="metric-card green">
                <div class="icon"><i class="bi bi-check2-circle"></i></div>
                <div class="value"><?= number_format($active_trains) ?></div>
                <div class="label">Active fleet units</div>
            </div>
            <div class="metric-card amber">
                <div class="icon"><i class="bi bi-tools"></i></div>
                <div class="value"><?= number_format($maintenance_trains) ?></div>
                <div class="label">Under maintenance</div>
            </div>
            <div class="metric-card purple">
                <div class="icon"><i class="bi bi-calendar2-check"></i></div>
                <div class="value"><?= number_format($upcoming_services) ?></div>
                <div class="label">Upcoming scheduled services</div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-xl-8">
                <div class="surface-card h-100">
                    <div class="card-title-row">
                        <h4><i class="bi bi-funnel me-2 text-primary"></i>Filter Fleet</h4>
                        <a href="manage-trains.php">Reset filters</a>
                    </div>
                    <form method="GET" class="filter-grid">
                        <div>
                            <label class="form-label small fw-semibold">Search</label>
                            <input type="text" name="q" class="form-control" placeholder="Train name, number, or type" value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <div>
                            <label class="form-label small fw-semibold">Status</label>
                            <select name="status" class="form-select">
                                <?php foreach (['all' => 'All Statuses', 'active' => 'Active', 'maintenance' => 'Maintenance', 'inactive' => 'Inactive'] as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $filter_status === $value ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label small fw-semibold">Type</label>
                            <select name="type" class="form-select">
                                <?php foreach (['all' => 'All Types', 'Express' => 'Express', 'Mail' => 'Mail', 'Passenger' => 'Passenger', 'Freight' => 'Freight'] as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $filter_type === $value ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-search me-1"></i>Apply
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="surface-card h-100">
                    <div class="card-title-row">
                        <h4><i class="bi bi-activity me-2 text-success"></i>Fleet Focus</h4>
                    </div>
                    <div class="focus-list">
                        <div class="focus-item">
                            <div>
                                <div class="fw-semibold">Average train capacity</div>
                                <div class="meta-line">Based on filtered fleet records.</div>
                            </div>
                            <span class="pill pill-info"><?= $average_capacity ?> seats</span>
                        </div>
                        <div class="focus-item">
                            <div>
                                <div class="fw-semibold">Confirmed bookings handled</div>
                                <div class="meta-line">Trips already converted into confirmed journeys.</div>
                            </div>
                            <span class="pill pill-active"><?= number_format($confirmed_bookings_total) ?></span>
                        </div>
                        <div class="focus-item">
                            <div>
                                <div class="fw-semibold">Inactive fleet units</div>
                                <div class="meta-line">Units not currently available for service planning.</div>
                            </div>
                            <span class="pill pill-inactive"><?= $inactive_trains ?></span>
                        </div>
                        <div class="focus-item">
                            <div>
                                <div class="fw-semibold">Top earning unit</div>
                                <div class="meta-line"><?= $top_train ? htmlspecialchars($top_train['train_name']) : 'No revenue data yet' ?></div>
                            </div>
                            <span class="pill pill-info">Rs. <?= number_format($top_train['total_revenue'] ?? 0, 0) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-xl-8">
                <div class="surface-card">
                    <div class="card-title-row">
                        <h4><i class="bi bi-table me-2 text-primary"></i>Fleet Registry</h4>
                        <span class="meta-line"><?= number_format($total_trains) ?> records loaded</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table train-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Train</th>
                                    <th>Profile</th>
                                    <th>Capacity</th>
                                    <th>Service Load</th>
                                    <th>Revenue</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($all_trains)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">No train records match the selected filters.</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($all_trains as $train): ?>
                                <?php
                                $available_seats = (int)$train['available_seats'];
                                $total_seats = max(1, (int)$train['total_seats']);
                                $availability_pct = (int)round(($available_seats / $total_seats) * 100);
                                $status_class = 'pill-' . strtolower($train['status']);
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($train['train_name']) ?></div>
                                        <div class="meta-line"><?= htmlspecialchars($train['train_number']) ?></div>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars($train['train_type']) ?></div>
                                        <div class="meta-line"><?= (int)$train['total_routes'] ?> lifetime routes, <?= (int)$train['today_routes'] ?> today</div>
                                    </td>
                                    <td style="min-width:170px;">
                                        <div class="d-flex justify-content-between small mb-1">
                                            <span><?= $available_seats ?> free</span>
                                            <span><?= $availability_pct ?>%</span>
                                        </div>
                                        <div class="progress-shell mb-1">
                                            <div class="progress-fill" style="width:<?= $availability_pct ?>%; background:<?= $availability_pct <= 20 ? 'linear-gradient(90deg,#ef4444,#dc2626)' : 'linear-gradient(90deg,#3b82f6,#6366f1)' ?>;"></div>
                                        </div>
                                        <div class="meta-line">Total capacity <?= $total_seats ?> seats</div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?= (int)$train['upcoming_routes'] ?> upcoming</div>
                                        <div class="meta-line"><?= (int)$train['scheduled_routes'] ?> scheduled, <?= (int)$train['completed_routes'] ?> completed</div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold">Rs. <?= number_format((float)$train['total_revenue'], 0) ?></div>
                                        <div class="meta-line"><?= (int)$train['confirmed_bookings'] ?> confirmed bookings</div>
                                    </td>
                                    <td><span class="pill <?= $status_class ?>"><?= ucfirst($train['status']) ?></span></td>
                                    <td>
                                        <div class="action-stack">
                                            <button type="button" class="btn btn-outline-primary btn-sm" data-role="edit-train" data-train="<?= htmlspecialchars(json_encode($train), ENT_QUOTES, 'UTF-8') ?>">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                            <a href="manage-routes.php?train_id=<?= (int)$train['train_id'] ?>" class="btn btn-outline-secondary btn-sm">
                                                <i class="bi bi-map"></i>
                                            </a>
                                            <button type="button" class="btn btn-outline-danger btn-sm" data-role="delete-train" data-id="<?= (int)$train['train_id'] ?>" data-name="<?= htmlspecialchars($train['train_name'], ENT_QUOTES, 'UTF-8') ?>">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="surface-card h-100">
                    <div class="card-title-row">
                        <h4><i class="bi bi-exclamation-triangle me-2 text-warning"></i>Attention Queue</h4>
                        <a href="manage-routes.php">Open routes</a>
                    </div>
                    <?php if (!empty($watch_list)): ?>
                    <div class="focus-list">
                        <?php foreach (array_slice($watch_list, 0, 5) as $watch_train): ?>
                        <div class="focus-item">
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars($watch_train['train_name']) ?></div>
                                <div class="meta-line"><?= htmlspecialchars($watch_train['train_number']) ?> · <?= htmlspecialchars($watch_train['train_type']) ?></div>
                                <div class="meta-line"><?= (int)$watch_train['upcoming_routes'] ?> upcoming routes linked</div>
                            </div>
                            <span class="pill pill-<?= strtolower($watch_train['status']) ?>"><?= ucfirst($watch_train['status']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-muted mb-0">No trains currently need operational attention.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<div class="modal fade" id="trainModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header">
                <div>
                    <div class="modal-section-label" id="trainModalEyebrow">Fleet Configuration</div>
                    <h5 class="modal-title mb-0" id="trainModalTitle">Add Train</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" id="trainFormAction" value="add">
                    <input type="hidden" name="train_id" id="trainIdField" value="">

                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold">Train Name</label>
                            <input type="text" class="form-control" name="train_name" id="trainNameField" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Train Number</label>
                            <input type="text" class="form-control" name="train_number" id="trainNumberField" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Type</label>
                            <select class="form-select" name="train_type" id="trainTypeField" required>
                                <option value="Express">Express</option>
                                <option value="Mail">Mail</option>
                                <option value="Passenger">Passenger</option>
                                <option value="Freight">Freight</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Total Seats</label>
                            <input type="number" min="1" class="form-control" name="total_seats" id="trainSeatsField" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Status</label>
                            <select class="form-select" name="status" id="trainStatusField" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="maintenance">Maintenance</option>
                            </select>
                        </div>
                    </div>

                    <div class="alert alert-warning mt-3 mb-0 d-none" id="seatLockNote">
                        Total seats are locked for trains that already have routes, so seat inventory stays consistent with existing services.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="trainSubmitButton">Save Train</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteTrainModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header">
                <h5 class="modal-title mb-0">Delete Train</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="train_id" id="deleteTrainIdField" value="">
                    <p class="mb-0" id="deleteTrainMessage">Are you sure you want to delete this train?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Train</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const trainModal = new bootstrap.Modal(document.getElementById('trainModal'));
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteTrainModal'));

    const modalTitle = document.getElementById('trainModalTitle');
    const modalEyebrow = document.getElementById('trainModalEyebrow');
    const actionField = document.getElementById('trainFormAction');
    const idField = document.getElementById('trainIdField');
    const nameField = document.getElementById('trainNameField');
    const numberField = document.getElementById('trainNumberField');
    const typeField = document.getElementById('trainTypeField');
    const seatsField = document.getElementById('trainSeatsField');
    const statusField = document.getElementById('trainStatusField');
    const submitButton = document.getElementById('trainSubmitButton');
    const seatLockNote = document.getElementById('seatLockNote');

    document.getElementById('openAddTrainBtn').addEventListener('click', function () {
        modalTitle.textContent = 'Add Train';
        modalEyebrow.textContent = 'Fleet Configuration';
        actionField.value = 'add';
        idField.value = '';
        nameField.value = '';
        numberField.value = '';
        typeField.value = 'Express';
        seatsField.value = '';
        seatsField.readOnly = false;
        statusField.value = 'active';
        submitButton.textContent = 'Save Train';
        seatLockNote.classList.add('d-none');
        trainModal.show();
    });

    document.querySelectorAll('[data-role="edit-train"]').forEach(function (button) {
        button.addEventListener('click', function () {
            const train = JSON.parse(this.dataset.train);
            const hasRoutes = Number(train.total_routes) > 0;

            modalTitle.textContent = 'Edit Train';
            modalEyebrow.textContent = 'Fleet Update';
            actionField.value = 'edit';
            idField.value = train.train_id;
            nameField.value = train.train_name;
            numberField.value = train.train_number;
            typeField.value = train.train_type;
            seatsField.value = train.total_seats;
            seatsField.readOnly = hasRoutes;
            statusField.value = train.status;
            submitButton.textContent = 'Update Train';

            if (hasRoutes) {
                seatLockNote.classList.remove('d-none');
            } else {
                seatLockNote.classList.add('d-none');
            }

            trainModal.show();
        });
    });

    document.querySelectorAll('[data-role="delete-train"]').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('deleteTrainIdField').value = this.dataset.id;
            document.getElementById('deleteTrainMessage').textContent = 'Delete ' + this.dataset.name + '? This cannot be undone once the train has no routes attached.';
            deleteModal.show();
        });
    });
});
</script>

<?php require_once 'inc/footer.php'; ?>

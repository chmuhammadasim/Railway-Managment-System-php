<?php
// manage-routes.php - Manage Routes (Admin)

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/Train.php';
require_once 'src/classes/Operations.php';

if (!User::isLoggedIn() || $_SESSION['role'] != 'admin') {
    header('Location: login.php');
    exit();
}

function manageRoutesUrl(string $status, string $window, int $trainId, string $search): string {
    $params = array_filter([
        'status' => $status !== 'all' ? $status : null,
        'window' => $window !== 'upcoming' ? $window : null,
        'train_id' => $trainId > 0 ? $trainId : null,
        'q' => $search !== '' ? $search : null,
    ]);

    return 'manage-routes.php' . ($params ? '?' . http_build_query($params) : '');
}

$db = new Database();
$db->connect();
$conn = $db->getConnection();

$train_obj = new Train($db);
$operations = new Operations($db);
$operations->ensureSchema();
$user_obj = new User($db);
$user = $user_obj->getUserById($_SESSION['user_id']);
$stations = $operations->getStations(true);

$filter_status = $_GET['status'] ?? 'all';
$filter_window = $_GET['window'] ?? 'upcoming';
$filter_train_id = (int)($_GET['train_id'] ?? 0);
$search = trim($_GET['q'] ?? '');

$flash_key = 'manage_routes_flash';
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

$all_trains = $db->select(
    "SELECT train_id, train_name, train_number, train_type, total_seats, status
     FROM trains
     ORDER BY CASE status WHEN 'active' THEN 0 WHEN 'maintenance' THEN 1 ELSE 2 END, train_name ASC"
);
if (!$all_trains) {
    $all_trains = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $flash_type = 'danger';
    $flash_message = 'Unable to process the route request.';

    $train_id = (int)($_POST['train_id'] ?? 0);
    $departure_city = trim($_POST['departure_city'] ?? '');
    $arrival_city = trim($_POST['arrival_city'] ?? '');
    $departure_time = trim($_POST['departure_time'] ?? '');
    $arrival_time = trim($_POST['arrival_time'] ?? '');
    $journey_date = trim($_POST['journey_date'] ?? '');
    $distance_km = (float)($_POST['distance_km'] ?? 0);
    $base_fare = (float)($_POST['base_fare'] ?? 0);
    $status = trim($_POST['status'] ?? 'scheduled');

    $allowed_statuses = ['scheduled', 'cancelled', 'completed'];
    $selected_train = $train_id > 0 ? $train_obj->getTrainById($train_id) : null;

    if ($action === 'add' || $action === 'edit') {
        if (!$selected_train) {
            $flash_message = 'Select a valid train for the route.';
        } elseif ($departure_city === '' || $arrival_city === '') {
            $flash_message = 'Departure and arrival cities are required.';
        } elseif (strcasecmp($departure_city, $arrival_city) === 0) {
            $flash_message = 'Departure and arrival cities must be different.';
        } elseif ($departure_time === '' || $arrival_time === '' || $journey_date === '') {
            $flash_message = 'Departure time, arrival time, and journey date are required.';
        } elseif ($distance_km <= 0 || $base_fare <= 0) {
            $flash_message = 'Distance and base fare must be greater than zero.';
        } elseif (!in_array($status, $allowed_statuses, true)) {
            $flash_message = 'Select a valid route status.';
        } elseif ($action === 'add') {
            $route_id = $db->insert('routes', [
                'train_id' => $train_id,
                'departure_city' => $departure_city,
                'arrival_city' => $arrival_city,
                'departure_time' => $departure_time,
                'arrival_time' => $arrival_time,
                'distance_km' => $distance_km,
                'base_fare' => $base_fare,
                'journey_date' => $journey_date,
                'available_seats' => (int)$selected_train['total_seats'],
                'status' => $status,
            ]);

            if ($route_id) {
                $train_obj->createSeats($train_id, $route_id, (int)$selected_train['total_seats']);
                $flash_type = 'success';
                $flash_message = 'Route created and seats generated successfully.';
            } else {
                $db_error = $db->getError();
                $flash_message = stripos($db_error, 'unique_train_date') !== false || stripos($db_error, 'Duplicate') !== false
                    ? 'This train already has a route scheduled for that journey date.'
                    : 'Failed to add route.';
            }
        } else {
            $route_id = (int)($_POST['route_id'] ?? 0);
            $existing_route = $train_obj->getRouteById($route_id);

            if (!$existing_route) {
                $flash_message = 'Selected route was not found.';
            } elseif ((int)$existing_route['train_id'] !== $train_id) {
                $flash_message = 'Train assignment cannot be changed after a route is created. Create a new route instead.';
            } else {
                $booking_count = (int)($db->selectRow("SELECT COUNT(*) AS c FROM bookings WHERE route_id = {$route_id}")['c'] ?? 0);

                $data = [
                    'train_id' => $train_id,
                    'departure_city' => $departure_city,
                    'arrival_city' => $arrival_city,
                    'departure_time' => $departure_time,
                    'arrival_time' => $arrival_time,
                    'distance_km' => $distance_km,
                    'base_fare' => $base_fare,
                    'journey_date' => $journey_date,
                    'status' => $status,
                ];

                if ($db->update('routes', 'route_id', $route_id, $data)) {
                    $flash_type = 'success';
                    $flash_message = $booking_count > 0
                        ? 'Route updated. Existing bookings remain attached to the revised schedule.'
                        : 'Route updated successfully.';
                } else {
                    $db_error = $db->getError();
                    $flash_message = stripos($db_error, 'unique_train_date') !== false || stripos($db_error, 'Duplicate') !== false
                        ? 'This train already has another route scheduled for that journey date.'
                        : 'Failed to update route.';
                }
            }
        }
    } elseif ($action === 'delete') {
        $route_id = (int)($_POST['route_id'] ?? 0);
        $booking_count = (int)($db->selectRow("SELECT COUNT(*) AS c FROM bookings WHERE route_id = {$route_id}")['c'] ?? 0);

        if ($booking_count > 0) {
            $flash_message = 'This route has bookings attached. Cancel or move those bookings before deleting the route.';
        } elseif ($route_id > 0 && $db->delete('routes', 'route_id', $route_id)) {
            $flash_type = 'success';
            $flash_message = 'Route deleted successfully.';
        } else {
            $flash_message = 'Failed to delete route.';
        }
    }

    $_SESSION[$flash_key] = [
        'type' => $flash_type,
        'message' => $flash_message,
    ];

    header('Location: ' . manageRoutesUrl($filter_status, $filter_window, $filter_train_id, $search));
    exit();
}

$where_parts = [];

if ($filter_status !== 'all') {
    $safe_status = $conn->real_escape_string($filter_status);
    $where_parts[] = "r.status = '{$safe_status}'";
}
if ($filter_train_id > 0) {
    $where_parts[] = "r.train_id = {$filter_train_id}";
}
if ($filter_window === 'today') {
    $where_parts[] = 'r.journey_date = CURDATE()';
} elseif ($filter_window === 'next7') {
    $where_parts[] = 'r.journey_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)';
} elseif ($filter_window === 'past') {
    $where_parts[] = 'r.journey_date < CURDATE()';
} elseif ($filter_window === 'all') {
    // no extra filter
} else {
    $filter_window = 'upcoming';
    $where_parts[] = 'r.journey_date >= CURDATE()';
}
if ($search !== '') {
    $safe_search = $conn->real_escape_string($search);
    $where_parts[] = "(t.train_name LIKE '%{$safe_search}%' OR t.train_number LIKE '%{$safe_search}%' OR r.departure_city LIKE '%{$safe_search}%' OR r.arrival_city LIKE '%{$safe_search}%')";
}

$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

$all_routes = $db->select(
    "SELECT r.*, t.train_name, t.train_number, t.train_type, t.total_seats,
            COALESCE(SUM(CASE WHEN b.booking_status = 'confirmed' THEN b.number_of_seats ELSE 0 END), 0) AS confirmed_seats,
            COALESCE(SUM(CASE WHEN b.booking_status = 'pending' THEN b.number_of_seats ELSE 0 END), 0) AS pending_seats,
            COALESCE(SUM(CASE WHEN b.booking_status = 'confirmed' THEN b.total_fare ELSE 0 END), 0) AS confirmed_revenue,
            COUNT(DISTINCT b.booking_id) AS booking_count,
            COALESCE(SUM(CASE WHEN b.booking_status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_bookings
     FROM routes r
     JOIN trains t ON r.train_id = t.train_id
     LEFT JOIN bookings b ON b.route_id = r.route_id
     {$where_sql}
     GROUP BY r.route_id, r.train_id, r.departure_city, r.arrival_city, r.departure_time, r.arrival_time,
              r.distance_km, r.base_fare, r.journey_date, r.available_seats, r.status,
              t.train_name, t.train_number, t.train_type, t.total_seats
     ORDER BY CASE
                WHEN r.journey_date = CURDATE() THEN 0
                WHEN r.journey_date > CURDATE() THEN 1
                ELSE 2
              END,
              r.journey_date ASC,
              r.departure_time ASC"
);
if (!$all_routes) {
    $all_routes = [];
}

$total_routes = count($all_routes);
$today_routes = count(array_filter($all_routes, fn($route) => $route['journey_date'] === date('Y-m-d')));
$scheduled_routes = count(array_filter($all_routes, fn($route) => $route['status'] === 'scheduled'));
$completed_routes = count(array_filter($all_routes, fn($route) => $route['status'] === 'completed'));
$cancelled_routes = count(array_filter($all_routes, fn($route) => $route['status'] === 'cancelled'));
$total_confirmed_seats = array_sum(array_map('intval', array_column($all_routes, 'confirmed_seats')));
$total_route_revenue = array_sum(array_map('floatval', array_column($all_routes, 'confirmed_revenue')));

$live_watch = array_values(array_filter(
    $all_routes,
    fn($route) => $route['journey_date'] === date('Y-m-d') || ((int)$route['pending_bookings']) > 0
));

$alert_routes = array_values(array_filter(
    $all_routes,
    function ($route) {
        $total_seats = max(1, (int)$route['total_seats']);
        $free_pct = (int)round(((int)$route['available_seats'] / $total_seats) * 100);
        return $route['status'] === 'scheduled' && ($free_pct <= 20 || (int)$route['pending_bookings'] > 0);
    }
));

$best_route = null;
foreach ($all_routes as $route) {
    if ($best_route === null || (float)$route['confirmed_revenue'] > (float)$best_route['confirmed_revenue']) {
        $best_route = $route;
    }
}

$hideMainNavbar = true;
$pageTitle = 'Manage Routes – Admin';
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
.adm-sidebar .sb-brand { padding:1.4rem 1.5rem 1rem; border-bottom:1px solid rgba(255,255,255,.08); }
.adm-sidebar .sb-brand span { display:block; font-size:.7rem; text-transform:uppercase; letter-spacing:1.5px; opacity:.5; margin-bottom:.3rem; }
.adm-sidebar .sb-brand strong { font-size:1rem; color:#fff; }
.adm-sidebar nav { flex:1; padding:.75rem 0; }
.adm-sidebar nav a {
    display:flex; align-items:center; gap:.75rem; padding:.65rem 1.5rem; color:#c8d6e8;
    text-decoration:none; font-size:.875rem; font-weight:500; transition:all .2s; border-left:3px solid transparent;
}
.adm-sidebar nav a:hover,
.adm-sidebar nav a.active { background:rgba(255,255,255,.07); color:#fff; border-left-color:#3b82f6; }
.adm-sidebar nav a i { font-size:1rem; width:1.1rem; text-align:center; }
.adm-sidebar .sb-sep { padding:.5rem 1.5rem .25rem; font-size:.68rem; text-transform:uppercase; letter-spacing:1.5px; opacity:.4; margin-top:.5rem; }
.adm-sidebar .sb-user {
    padding:1rem 1.5rem; border-top:1px solid rgba(255,255,255,.08); display:flex; align-items:center; gap:.75rem;
}
.adm-sidebar .sb-user .avatar {
    width:34px; height:34px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#6366f1);
    display:flex; align-items:center; justify-content:center; font-size:.875rem; font-weight:700; color:#fff; flex-shrink:0;
}
.adm-sidebar .sb-user .info small { display:block; font-size:.7rem; opacity:.5; }
.adm-sidebar .sb-user .info strong { font-size:.8rem; color:#fff; }

.adm-main { flex:1; padding:2rem; overflow-x:hidden; }
.adm-page-header { display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; flex-wrap:wrap; margin-bottom:1.5rem; }
.adm-page-header h2 { margin:0; font-size:1.6rem; font-weight:800; color:#0f172a; }
.adm-page-header p { margin:.2rem 0 0; color:#64748b; font-size:.9rem; }

.metric-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(190px, 1fr)); gap:1rem; margin-bottom:1.5rem; }
.metric-card { background:#fff; border-radius:14px; padding:1.2rem 1.3rem; box-shadow:0 1px 4px rgba(0,0,0,.07); }
.metric-card .icon { width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; margin-bottom:.8rem; }
.metric-card .value { font-size:1.8rem; font-weight:800; line-height:1; color:#0f172a; }
.metric-card .label { font-size:.8rem; color:#64748b; margin-top:.3rem; }
.metric-card.blue .icon { background:#dbeafe; color:#2563eb; }
.metric-card.green .icon { background:#dcfce7; color:#16a34a; }
.metric-card.amber .icon { background:#fef3c7; color:#d97706; }
.metric-card.purple .icon { background:#ede9fe; color:#7c3aed; }

.surface-card { background:#fff; border-radius:14px; padding:1.25rem; box-shadow:0 1px 4px rgba(0,0,0,.07); }
.card-title-row { display:flex; justify-content:space-between; align-items:center; gap:1rem; margin-bottom:1rem; }
.card-title-row h4 { margin:0; font-size:.95rem; font-weight:700; color:#0f172a; }
.card-title-row a { font-size:.8rem; text-decoration:none; font-weight:600; color:#3b82f6; }

.filter-grid { display:grid; grid-template-columns:2fr 1fr 1fr 1fr auto; gap:.75rem; align-items:end; }
.focus-list { display:flex; flex-direction:column; gap:.85rem; }
.focus-item { display:flex; justify-content:space-between; align-items:flex-start; gap:.75rem; padding-bottom:.85rem; border-bottom:1px solid #f1f5f9; }
.focus-item:last-child { padding-bottom:0; border-bottom:none; }

.route-table thead th { background:#0f1e32; color:#fff; font-size:.8rem; font-weight:600; white-space:nowrap; }
.route-table td { font-size:.85rem; vertical-align:middle; }
.route-table tbody tr:hover { background:#f8fafc; }

.meta-line { font-size:.75rem; color:#64748b; }
.pill { display:inline-block; padding:.25em .75em; border-radius:999px; font-size:.75rem; font-weight:600; }
.pill-scheduled { background:#dbeafe; color:#1d4ed8; }
.pill-completed { background:#dcfce7; color:#166534; }
.pill-cancelled { background:#fee2e2; color:#991b1b; }
.pill-info { background:#e0f2fe; color:#0369a1; }
.pill-alert { background:#fef3c7; color:#92400e; }

.progress-shell { background:#e2e8f0; border-radius:999px; height:7px; overflow:hidden; }
.progress-fill { height:100%; border-radius:999px; background:linear-gradient(90deg,#3b82f6,#6366f1); }
.action-stack { display:flex; gap:.45rem; flex-wrap:wrap; }
.tiny-note { font-size:.74rem; color:#64748b; }
.modal-section-label { font-size:.72rem; text-transform:uppercase; letter-spacing:.08em; color:#64748b; font-weight:700; }

@media (max-width:1200px) {
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
            <a href="manage-trains.php"><i class="bi bi-train-front"></i> Trains</a>
            <a href="manage-routes.php" class="active"><i class="bi bi-map"></i> Routes</a>
            <a href="operations-hub.php?tab=stations"><i class="bi bi-building"></i> Stations</a>
            <a href="manage-bookings.php"><i class="bi bi-ticket-perforated"></i> Bookings</a>
            <a href="manage-payments.php"><i class="bi bi-cash-stack"></i> Payments</a>

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
                <h2>Route Planning</h2>
                <p>Control scheduling, capacity visibility, and service readiness across all planned train journeys.</p>
            </div>
            <button type="button" class="btn btn-primary" id="openAddRouteBtn">
                <i class="bi bi-plus-circle me-1"></i>Add Route
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
                <div class="icon"><i class="bi bi-signpost-2-fill"></i></div>
                <div class="value"><?= number_format($total_routes) ?></div>
                <div class="label">Routes in current view</div>
            </div>
            <div class="metric-card green">
                <div class="icon"><i class="bi bi-calendar2-check"></i></div>
                <div class="value"><?= number_format($today_routes) ?></div>
                <div class="label">Running today</div>
            </div>
            <div class="metric-card amber">
                <div class="icon"><i class="bi bi-people-fill"></i></div>
                <div class="value"><?= number_format($total_confirmed_seats) ?></div>
                <div class="label">Confirmed seats sold</div>
            </div>
            <div class="metric-card purple">
                <div class="icon"><i class="bi bi-cash-coin"></i></div>
                <div class="value">Rs. <?= number_format($total_route_revenue, 0) ?></div>
                <div class="label">Revenue across filtered routes</div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-xl-8">
                <div class="surface-card h-100">
                    <div class="card-title-row">
                        <h4><i class="bi bi-funnel me-2 text-primary"></i>Filter Routes</h4>
                        <a href="manage-routes.php">Reset filters</a>
                    </div>
                    <form method="GET" class="filter-grid">
                        <div>
                            <label class="form-label small fw-semibold">Search</label>
                            <input type="text" name="q" class="form-control" placeholder="Train, city, or route" value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <div>
                            <label class="form-label small fw-semibold">Status</label>
                            <select name="status" class="form-select">
                                <?php foreach (['all' => 'All Statuses', 'scheduled' => 'Scheduled', 'completed' => 'Completed', 'cancelled' => 'Cancelled'] as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $filter_status === $value ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label small fw-semibold">Window</label>
                            <select name="window" class="form-select">
                                <?php foreach (['upcoming' => 'Upcoming', 'today' => 'Today', 'next7' => 'Next 7 Days', 'past' => 'Past', 'all' => 'All Dates'] as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $filter_window === $value ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label small fw-semibold">Train</label>
                            <select name="train_id" class="form-select">
                                <option value="0">All Trains</option>
                                <?php foreach ($all_trains as $train): ?>
                                <option value="<?= (int)$train['train_id'] ?>" <?= $filter_train_id === (int)$train['train_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($train['train_name']) ?> (<?= htmlspecialchars($train['train_number']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i>Apply</button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="surface-card h-100">
                    <div class="card-title-row">
                        <h4><i class="bi bi-activity me-2 text-success"></i>Route Snapshot</h4>
                    </div>
                    <div class="focus-list">
                        <div class="focus-item">
                            <div>
                                <div class="fw-semibold">Scheduled services</div>
                                <div class="meta-line">Journeys still open for boarding and seat assignments.</div>
                            </div>
                            <span class="pill pill-scheduled"><?= $scheduled_routes ?></span>
                        </div>
                        <div class="focus-item">
                            <div>
                                <div class="fw-semibold">Completed services</div>
                                <div class="meta-line">Historical services useful for reporting and reconciliation.</div>
                            </div>
                            <span class="pill pill-completed"><?= $completed_routes ?></span>
                        </div>
                        <div class="focus-item">
                            <div>
                                <div class="fw-semibold">Cancelled services</div>
                                <div class="meta-line">Routes requiring customer follow-up or rescheduling.</div>
                            </div>
                            <span class="pill pill-cancelled"><?= $cancelled_routes ?></span>
                        </div>
                        <div class="focus-item">
                            <div>
                                <div class="fw-semibold">Top route in view</div>
                                <div class="meta-line">
                                    <?= $best_route ? htmlspecialchars($best_route['departure_city'] . ' → ' . $best_route['arrival_city']) : 'No revenue data yet' ?>
                                </div>
                            </div>
                            <span class="pill pill-info">Rs. <?= number_format($best_route['confirmed_revenue'] ?? 0, 0) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-xl-8">
                <div class="surface-card">
                    <div class="card-title-row">
                        <h4><i class="bi bi-table me-2 text-primary"></i>Route Registry</h4>
                        <span class="tiny-note">Seats are generated automatically when a new route is created.</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table route-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Train</th>
                                    <th>Route</th>
                                    <th>Schedule</th>
                                    <th>Load</th>
                                    <th>Fare</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($all_routes)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">No routes match the selected filters.</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($all_routes as $route): ?>
                                <?php
                                $total_seats = max(1, (int)$route['total_seats']);
                                $confirmed_seats = (int)$route['confirmed_seats'];
                                $load_pct = (int)round(($confirmed_seats / $total_seats) * 100);
                                $status_class = 'pill-' . strtolower($route['status']);
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($route['train_name']) ?></div>
                                        <div class="meta-line"><?= htmlspecialchars($route['train_number']) ?> · <?= htmlspecialchars($route['train_type']) ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($route['departure_city']) ?> → <?= htmlspecialchars($route['arrival_city']) ?></div>
                                        <div class="meta-line"><?= number_format((float)$route['distance_km'], 0) ?> km</div>
                                    </td>
                                    <td>
                                        <div><?= date('d M Y', strtotime($route['journey_date'])) ?></div>
                                        <div class="meta-line"><?= date('H:i', strtotime($route['departure_time'])) ?> to <?= date('H:i', strtotime($route['arrival_time'])) ?></div>
                                    </td>
                                    <td style="min-width:190px;">
                                        <div class="d-flex justify-content-between small mb-1">
                                            <span><?= $confirmed_seats ?> confirmed</span>
                                            <span><?= $load_pct ?>%</span>
                                        </div>
                                        <div class="progress-shell mb-1">
                                            <div class="progress-fill" style="width:<?= $load_pct ?>%; background:<?= $load_pct >= 80 ? 'linear-gradient(90deg,#ef4444,#dc2626)' : 'linear-gradient(90deg,#3b82f6,#6366f1)' ?>;"></div>
                                        </div>
                                        <div class="meta-line"><?= (int)$route['available_seats'] ?> free · <?= (int)$route['pending_bookings'] ?> pending bookings</div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold">Rs. <?= number_format((float)$route['base_fare'], 0) ?></div>
                                        <div class="meta-line">Revenue Rs. <?= number_format((float)$route['confirmed_revenue'], 0) ?></div>
                                    </td>
                                    <td><span class="pill <?= $status_class ?>"><?= ucfirst($route['status']) ?></span></td>
                                    <td>
                                        <div class="action-stack">
                                            <button type="button" class="btn btn-outline-primary btn-sm" data-role="edit-route" data-route="<?= htmlspecialchars(json_encode($route), ENT_QUOTES, 'UTF-8') ?>">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                            <a href="manage-trains.php?status=all&type=all&q=<?= urlencode($route['train_number']) ?>" class="btn btn-outline-secondary btn-sm">
                                                <i class="bi bi-train-front"></i>
                                            </a>
                                            <button type="button" class="btn btn-outline-danger btn-sm" data-role="delete-route" data-id="<?= (int)$route['route_id'] ?>" data-name="<?= htmlspecialchars($route['departure_city'] . ' → ' . $route['arrival_city'], ENT_QUOTES, 'UTF-8') ?>">
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
                <div class="surface-card mb-3">
                    <div class="card-title-row">
                        <h4><i class="bi bi-broadcast-pin me-2 text-primary"></i>Today Watch</h4>
                    </div>
                    <?php if (!empty($live_watch)): ?>
                    <div class="focus-list">
                        <?php foreach (array_slice($live_watch, 0, 4) as $watch): ?>
                        <div class="focus-item">
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars($watch['departure_city']) ?> → <?= htmlspecialchars($watch['arrival_city']) ?></div>
                                <div class="meta-line"><?= htmlspecialchars($watch['train_name']) ?> · <?= date('d M, H:i', strtotime($watch['journey_date'] . ' ' . $watch['departure_time'])) ?></div>
                                <div class="meta-line"><?= (int)$watch['pending_bookings'] ?> pending booking(s)</div>
                            </div>
                            <span class="pill pill-<?= strtolower($watch['status']) ?>"><?= ucfirst($watch['status']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-muted mb-0">No route activity needs special watch right now.</p>
                    <?php endif; ?>
                </div>

                <div class="surface-card">
                    <div class="card-title-row">
                        <h4><i class="bi bi-exclamation-triangle me-2 text-warning"></i>Capacity Alerts</h4>
                    </div>
                    <?php if (!empty($alert_routes)): ?>
                    <div class="focus-list">
                        <?php foreach (array_slice($alert_routes, 0, 4) as $alert): ?>
                        <?php
                        $total_capacity = max(1, (int)$alert['total_seats']);
                        $free_pct = (int)round(((int)$alert['available_seats'] / $total_capacity) * 100);
                        ?>
                        <div class="focus-item">
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars($alert['departure_city']) ?> → <?= htmlspecialchars($alert['arrival_city']) ?></div>
                                <div class="meta-line"><?= htmlspecialchars($alert['train_name']) ?> · <?= date('d M Y', strtotime($alert['journey_date'])) ?></div>
                                <div class="meta-line"><?= $free_pct ?>% free seats remaining</div>
                            </div>
                            <span class="pill pill-alert"><?= (int)$alert['available_seats'] ?> free</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-muted mb-0">No route capacity alerts in the current view.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<div class="modal fade" id="routeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header">
                <div>
                    <div class="modal-section-label" id="routeModalEyebrow">Schedule Builder</div>
                    <h5 class="modal-title mb-0" id="routeModalTitle">Add Route</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" id="routeFormAction" value="add">
                    <input type="hidden" name="route_id" id="routeIdField" value="">
                    <input type="hidden" name="train_id" id="routeTrainHidden" value="">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Train</label>
                            <select class="form-select" id="routeTrainSelect" required>
                                <option value="">Select a train</option>
                                <?php foreach ($all_trains as $train): ?>
                                <option value="<?= (int)$train['train_id'] ?>" data-seats="<?= (int)$train['total_seats'] ?>" data-status="<?= htmlspecialchars($train['status'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($train['train_name']) ?> (<?= htmlspecialchars($train['train_number']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Status</label>
                            <select class="form-select" name="status" id="routeStatusField" required>
                                <option value="scheduled">Scheduled</option>
                                <option value="cancelled">Cancelled</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Departure City</label>
                            <input type="text" class="form-control" name="departure_city" id="departureCityField" list="stationCityList" required>
                            <div class="form-text">Use a registered station city when available.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Arrival City</label>
                            <input type="text" class="form-control" name="arrival_city" id="arrivalCityField" list="stationCityList" required>
                            <div class="form-text">Station cities from Operations Hub appear here.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Journey Date</label>
                            <input type="date" class="form-control" name="journey_date" id="journeyDateField" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Departure Time</label>
                            <input type="time" class="form-control" name="departure_time" id="departureTimeField" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Arrival Time</label>
                            <input type="time" class="form-control" name="arrival_time" id="arrivalTimeField" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Distance (KM)</label>
                            <input type="number" step="0.01" min="0.01" class="form-control" name="distance_km" id="distanceField" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Base Fare (Rs.)</label>
                            <input type="number" step="0.01" min="0.01" class="form-control" name="base_fare" id="fareField" required>
                        </div>
                    </div>

                    <div class="alert alert-info mt-3 mb-0" id="routeSeatInfo">
                        Select a train to see the automatic seat capacity that will be created for this route.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="routeSubmitButton">Save Route</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteRouteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header">
                <h5 class="modal-title mb-0">Delete Route</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="route_id" id="deleteRouteIdField" value="">
                    <p class="mb-0" id="deleteRouteMessage">Are you sure you want to delete this route?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Route</button>
                </div>
            </form>
        </div>
    </div>
</div>

<datalist id="stationCityList">
    <?php foreach ($stations as $station): ?>
    <option value="<?= htmlspecialchars($station['city']) ?>"><?= htmlspecialchars($station['station_code'] . ' | ' . $station['station_name']) ?></option>
    <?php endforeach; ?>
</datalist>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const routeModal = new bootstrap.Modal(document.getElementById('routeModal'));
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteRouteModal'));

    const routeModalTitle = document.getElementById('routeModalTitle');
    const routeModalEyebrow = document.getElementById('routeModalEyebrow');
    const routeFormAction = document.getElementById('routeFormAction');
    const routeIdField = document.getElementById('routeIdField');
    const routeTrainSelect = document.getElementById('routeTrainSelect');
    const routeTrainHidden = document.getElementById('routeTrainHidden');
    const routeStatusField = document.getElementById('routeStatusField');
    const departureCityField = document.getElementById('departureCityField');
    const arrivalCityField = document.getElementById('arrivalCityField');
    const journeyDateField = document.getElementById('journeyDateField');
    const departureTimeField = document.getElementById('departureTimeField');
    const arrivalTimeField = document.getElementById('arrivalTimeField');
    const distanceField = document.getElementById('distanceField');
    const fareField = document.getElementById('fareField');
    const routeSeatInfo = document.getElementById('routeSeatInfo');
    const routeSubmitButton = document.getElementById('routeSubmitButton');

    function updateSeatInfo() {
        const selected = routeTrainSelect.options[routeTrainSelect.selectedIndex];
        routeTrainHidden.value = routeTrainSelect.value;

        if (!routeTrainSelect.value) {
            routeSeatInfo.className = 'alert alert-info mt-3 mb-0';
            routeSeatInfo.textContent = 'Select a train to see the automatic seat capacity that will be created for this route.';
            return;
        }

        const seats = selected.getAttribute('data-seats');
        const status = selected.getAttribute('data-status');
        routeSeatInfo.className = status === 'active' ? 'alert alert-info mt-3 mb-0' : 'alert alert-warning mt-3 mb-0';
        routeSeatInfo.textContent = 'This route will start with ' + seats + ' available seats from the selected train (' + status + ' fleet status).';
    }

    routeTrainSelect.addEventListener('change', updateSeatInfo);

    document.getElementById('openAddRouteBtn').addEventListener('click', function () {
        routeModalTitle.textContent = 'Add Route';
        routeModalEyebrow.textContent = 'Schedule Builder';
        routeFormAction.value = 'add';
        routeIdField.value = '';
        routeTrainSelect.disabled = false;
        routeTrainSelect.value = '<?= $filter_train_id > 0 ? (int)$filter_train_id : '' ?>';
        routeTrainHidden.value = routeTrainSelect.value;
        routeStatusField.value = 'scheduled';
        departureCityField.value = '';
        arrivalCityField.value = '';
        journeyDateField.value = '';
        departureTimeField.value = '';
        arrivalTimeField.value = '';
        distanceField.value = '';
        fareField.value = '';
        routeSubmitButton.textContent = 'Save Route';
        updateSeatInfo();
        routeModal.show();
    });

    document.querySelectorAll('[data-role="edit-route"]').forEach(function (button) {
        button.addEventListener('click', function () {
            const route = JSON.parse(this.dataset.route);

            routeModalTitle.textContent = 'Edit Route';
            routeModalEyebrow.textContent = 'Schedule Update';
            routeFormAction.value = 'edit';
            routeIdField.value = route.route_id;
            routeTrainSelect.value = route.train_id;
            routeTrainSelect.disabled = true;
            routeTrainHidden.value = route.train_id;
            routeStatusField.value = route.status;
            departureCityField.value = route.departure_city;
            arrivalCityField.value = route.arrival_city;
            journeyDateField.value = route.journey_date;
            departureTimeField.value = route.departure_time;
            arrivalTimeField.value = route.arrival_time;
            distanceField.value = route.distance_km;
            fareField.value = route.base_fare;
            routeSubmitButton.textContent = 'Update Route';
            routeSeatInfo.className = 'alert alert-warning mt-3 mb-0';
            routeSeatInfo.textContent = 'Train assignment and seat inventory stay locked after route creation. Existing bookings remain tied to this route.';
            routeModal.show();
        });
    });

    document.querySelectorAll('[data-role="delete-route"]').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('deleteRouteIdField').value = this.dataset.id;
            document.getElementById('deleteRouteMessage').textContent = 'Delete route ' + this.dataset.name + '? Only routes without bookings can be removed.';
            deleteModal.show();
        });
    });
});
</script>

<?php require_once 'inc/footer.php'; ?>

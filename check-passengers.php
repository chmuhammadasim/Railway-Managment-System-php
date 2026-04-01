<?php
// check-passengers.php – Employee: Passenger Manifest & Check-In

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';

if (!User::isLoggedIn() || $_SESSION['role'] !== 'employee') {
    header('Location: login.php');
    exit();
}

$db   = new Database();
$db->connect();
$conn = $db->getConnection();

// ── Filters ───────────────────────────────────────────────────────────────────
$route_id      = isset($_GET['route_id'])  ? (int)$_GET['route_id']  : 0;
$train_id      = isset($_GET['train_id'])  ? (int)$_GET['train_id']  : 0;
$filter_date   = $_GET['date']             ?? date('Y-m-d');
$filter_status = $_GET['bstatus']          ?? 'all';
$search        = trim($_GET['q']           ?? '');
$view          = $_GET['view']             ?? 'passengers'; // passengers | bookings

// ── Build route list for selector ────────────────────────────────────────────
$date_for_routes = $filter_date ?: date('Y-m-d');
$route_filter_sql = $train_id ? "AND r.train_id = {$train_id}" : '';
$routes_for_date = $db->select(
    "SELECT r.route_id, r.departure_city, r.arrival_city, r.departure_time,
            t.train_name, r.available_seats
     FROM routes r
     JOIN trains t ON r.train_id = t.train_id
     WHERE r.journey_date = '{$conn->real_escape_string($date_for_routes)}'
     {$route_filter_sql}
     ORDER BY r.departure_time ASC"
);
if (!$routes_for_date) $routes_for_date = [];

// ── Build WHERE clauses ───────────────────────────────────────────────────────
$where_parts = [];
if ($filter_date !== '') {
    $df = $conn->real_escape_string($filter_date);
    $where_parts[] = "b.journey_date = '{$df}'";
}
if ($route_id > 0) {
    $where_parts[] = "b.route_id = {$route_id}";
} elseif ($train_id > 0) {
    $where_parts[] = "r.train_id = {$train_id}";
}
if ($filter_status !== 'all') {
    $fs = $conn->real_escape_string($filter_status);
    $where_parts[] = "b.booking_status = '{$fs}'";
}
if ($search !== '') {
    $sq = $conn->real_escape_string($search);
    $where_parts[] = "(bs.passenger_name LIKE '%{$sq}%' OR u.full_name LIKE '%{$sq}%' OR b.booking_reference LIKE '%{$sq}%')";
}
$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

// ── Passenger manifest query ──────────────────────────────────────────────────
$passengers = $db->select(
    "SELECT bs.passenger_name, bs.passenger_age, bs.passenger_gender,
            s.seat_number, s.seat_type,
            b.booking_id, b.booking_reference, b.booking_status, b.payment_status,
            b.number_of_seats, b.total_fare,
            u.full_name AS booker_name, u.phone, u.email,
            r.departure_city, r.arrival_city, r.departure_time,
            t.train_name
     FROM booking_seats bs
     JOIN bookings b  ON bs.booking_id = b.booking_id
     JOIN seats    s  ON bs.seat_id    = s.seat_id
     JOIN users    u  ON b.user_id     = u.user_id
     JOIN routes   r  ON b.route_id    = r.route_id
     JOIN trains   t  ON r.train_id    = t.train_id
     {$where_sql}
     ORDER BY s.seat_number ASC, b.booking_date DESC"
);
if (!$passengers) $passengers = [];

// ── Booking-level view (no seat duplication) ──────────────────────────────────
$booking_where = [];
if ($filter_date !== '') {
    $df = $conn->real_escape_string($filter_date);
    $booking_where[] = "b.journey_date = '{$df}'";
}
if ($route_id > 0) {
    $booking_where[] = "b.route_id = {$route_id}";
} elseif ($train_id > 0) {
    $booking_where[] = "r.train_id = {$train_id}";
}
if ($filter_status !== 'all') {
    $fs = $conn->real_escape_string($filter_status);
    $booking_where[] = "b.booking_status = '{$fs}'";
}
if ($search !== '') {
    $sq = $conn->real_escape_string($search);
    $booking_where[] = "(u.full_name LIKE '%{$sq}%' OR b.booking_reference LIKE '%{$sq}%' OR u.phone LIKE '%{$sq}%')";
}
$bk_where_sql = $booking_where ? 'WHERE ' . implode(' AND ', $booking_where) : '';

$bookings_list = $db->select(
    "SELECT b.booking_id, b.booking_reference, b.booking_status, b.payment_status,
            b.number_of_seats, b.total_fare, b.booking_date, b.journey_date,
            u.full_name, u.phone, u.email,
            r.departure_city, r.arrival_city, r.departure_time,
            t.train_name
     FROM bookings b
     JOIN users  u ON b.user_id   = u.user_id
     JOIN routes r ON b.route_id  = r.route_id
     JOIN trains t ON r.train_id  = t.train_id
     {$bk_where_sql}
     ORDER BY b.booking_date DESC"
);
if (!$bookings_list) $bookings_list = [];

// ── KPIs for the filtered set ─────────────────────────────────────────────────
$total_passengers = count($passengers);
$total_bookings   = count($bookings_list);
$confirmed_n = count(array_filter($bookings_list, fn($b) => $b['booking_status'] === 'confirmed'));
$pending_n   = count(array_filter($bookings_list, fn($b) => $b['booking_status'] === 'pending'));

$hideMainNavbar = true;
$pageTitle = 'Passenger Manifest – Employee';
require_once 'inc/header.php';
?>

<style>
/* ─── Layout ─────────────────────────────────────────── */
.emp-wrap  { display:flex; height:100vh; overflow:hidden; }
.emp-sidebar {
    width:240px; flex-shrink:0;
    background:linear-gradient(180deg,#012117 0%,#064e3b 100%);
    display:flex; flex-direction:column;
    position:sticky; top:0; height:100vh; overflow-y:auto;
}
.emp-sb-brand { padding:1.4rem 1.25rem 1.2rem; border-bottom:1px solid rgba(255,255,255,.1); }
.emp-sb-brand .brand-icon {
    width:38px; height:38px; border-radius:10px;
    background:rgba(16,185,129,.2); color:#34d399;
    display:flex; align-items:center; justify-content:center;
    font-size:1.2rem; margin-bottom:.55rem;
}
.emp-sb-brand .brand-name { font-weight:800; font-size:.95rem; color:#fff; line-height:1.2; }
.emp-sb-brand .brand-role { font-size:.7rem; color:rgba(255,255,255,.4); margin-top:.15rem; }
.sb-sep {
    font-size:.65rem; font-weight:700; letter-spacing:.1em;
    text-transform:uppercase; color:rgba(255,255,255,.28);
    padding:.9rem 1.25rem .3rem;
}
.emp-sidebar nav a {
    display:flex; align-items:center; gap:.7rem;
    padding:.62rem 1.25rem; color:rgba(255,255,255,.65);
    text-decoration:none; font-size:.875rem; font-weight:500;
    transition:background .15s,color .15s,border-color .15s;
    border-left:3px solid transparent;
}
.emp-sidebar nav a:hover { background:rgba(255,255,255,.08); color:#fff; border-left-color:rgba(52,211,153,.4); }
.emp-sidebar nav a.active { background:rgba(16,185,129,.15); color:#fff; border-left-color:#10b981; font-weight:600; }
.emp-sidebar nav a i { font-size:.95rem; width:18px; text-align:center; }
.emp-sb-footer {
    margin-top:auto; padding:1rem 1.25rem;
    border-top:1px solid rgba(255,255,255,.08);
    font-size:.71rem; color:rgba(255,255,255,.3); text-align:center;
}
.emp-main { flex:1; overflow-y:auto; background:#f8fafc; }
.emp-page-header {
    background:#fff; border-bottom:1px solid #e5e7eb;
    padding:1.1rem 1.75rem;
    display:flex; align-items:center; justify-content:space-between; gap:1rem;
    position:sticky; top:0; z-index:100;
}
.emp-page-header .ph-title { font-size:1.05rem; font-weight:700; color:#0f172a; margin:0; }
.emp-page-header .ph-sub   { font-size:.78rem; color:#6b7280; margin:0; }
.emp-content { padding:1.5rem 1.75rem; }

/* ─── Metric cards ───────────────────────────────────── */
.metric-card {
    background:#fff; border-radius:14px; padding:1.25rem;
    border:1px solid #e5e7eb;
    box-shadow:0 1px 4px rgba(0,0,0,.06);
    transition:box-shadow .2s,transform .2s; height:100%;
}
.metric-card:hover { box-shadow:0 6px 20px rgba(0,0,0,.09); transform:translateY(-2px); }
.mc-ico {
    width:46px; height:46px; border-radius:12px;
    display:flex; align-items:center; justify-content:center;
    font-size:1.25rem; margin-bottom:.9rem;
}
.mc-val { font-size:2rem; font-weight:900; color:#0f172a; line-height:1; }
.mc-lbl { font-size:.76rem; color:#6b7280; font-weight:500; margin-top:.25rem; }

/* ─── Status badges ──────────────────────────────────── */
.sp { display:inline-block; padding:.22em .72em; border-radius:20px; font-size:.75rem; font-weight:600; white-space:nowrap; }
.sp-confirmed { background:#dcfce7; color:#15803d; }
.sp-pending   { background:#fef3c7; color:#92400e; }
.sp-cancelled { background:#fee2e2; color:#7f1d1d; }
.sp-completed { background:#dcfce7; color:#15803d; }
.sp-refunded  { background:#ede9fe; color:#4c1d95; }
.sp-failed    { background:#fee2e2; color:#7f1d1d; }

/* ─── Table ───────────────────────────────────────────── */
.pax-table thead th {
    background:linear-gradient(135deg,#012117,#064e3b);
    color:#fff; font-weight:600; font-size:.8rem;
    white-space:nowrap; padding:.85rem .75rem;
    border:none;
}
.pax-table tbody tr:hover { background:#f0fdf4; }
.pax-table td { font-size:.84rem; vertical-align:middle; padding:.7rem .75rem; }
.pax-table { border-radius:0 0 14px 14px; overflow:hidden; }
.surface-card {
    background:#fff; border-radius:14px;
    border:1px solid #e5e7eb;
    box-shadow:0 1px 6px rgba(0,0,0,.06);
    overflow:hidden;
}
.surface-card .sc-head {
    padding:.9rem 1.25rem; border-bottom:1px solid #f1f5f9;
    display:flex; align-items:center; justify-content:space-between;
}
.surface-card .sc-head h6 { font-weight:700; margin:0; font-size:.9rem; color:#0f172a; }

/* ─── Filter panel ───────────────────────────────────── */
.filter-bar {
    background:#fff; border-radius:14px; border:1px solid #e5e7eb;
    box-shadow:0 1px 4px rgba(0,0,0,.05);
    padding:1.1rem 1.25rem; margin-bottom:1.25rem;
}
.filter-bar label { font-size:.73rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#374151; }

/* ─── View toggle ─────────────────────────────────────── */
.view-toggle {
    display:inline-flex; border-radius:10px; overflow:hidden;
    border:1.5px solid #d1fae5; background:#f0fdf4;
}
.view-toggle a {
    padding:.42rem 1rem; font-size:.82rem; font-weight:600;
    color:#065f46; text-decoration:none;
    transition:background .15s,color .15s;
}
.view-toggle a.active { background:#10b981; color:#fff; }
.view-toggle a:not(.active):hover { background:#dcfce7; }

/* Print */
@media print {
    .no-print { display:none !important; }
    .emp-sidebar { display:none; }
    .emp-main    { overflow:visible; }
    .pax-table thead th { background:#064e3b !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
}
@media(max-width:768px){ .emp-sidebar{display:none;} .emp-content{padding:1rem;} }
</style>

<div class="emp-wrap">
<!-- ─── Sidebar ──────────────────────────────────────── -->
<aside class="emp-sidebar no-print">
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
        <a href="check-passengers.php" class="active"><i class="bi bi-people"></i> Passengers</a>
        <a href="assign-seats.php"><i class="bi bi-grid-3x3-gap"></i> Seat Management</a>
        <div class="sb-sep">Account</div>
        <a href="profile.php"><i class="bi bi-person-circle"></i> My Profile</a>
        <a href="logout.php" style="color:rgba(252,165,165,.8)!important;"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </nav>
    <div class="emp-sb-footer">Railway Management System</div>
</aside>

<main class="emp-main">

    <!-- Page Header -->
    <div class="emp-page-header no-print">
        <div>
            <p class="ph-title"><i class="bi bi-people-fill me-2" style="color:#10b981;"></i>Passenger Manifest</p>
            <p class="ph-sub">
                <?= $filter_date ? date('D, d M Y', strtotime($filter_date)) : 'All dates' ?>
                <?php if ($route_id && !empty($routes_for_date)):
                    $sel_route = array_values(array_filter($routes_for_date, fn($r) => (int)$r['route_id'] === $route_id));
                    if (!empty($sel_route)):
                        $sr = $sel_route[0]; ?>
                    &nbsp;&middot;&nbsp;
                    <?= htmlspecialchars($sr['departure_city']) ?> &rarr; <?= htmlspecialchars($sr['arrival_city']) ?>
                    (<?= htmlspecialchars($sr['train_name']) ?>)
                <?php endif; endif; ?>
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button onclick="window.print()" class="btn btn-outline-success btn-sm">
                <i class="bi bi-printer me-1"></i>Print Manifest
            </button>
            <a href="employee-dashboard.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Dashboard
            </a>
        </div>
    </div>

    <div class="emp-content">
    <!-- KPI Cards -->
    <div class="row g-3 mb-4 no-print">
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <div class="mc-ico" style="background:#dcfce7;"><i class="bi bi-person-fill" style="color:#16a34a;font-size:1.3rem;"></i></div>
                <div class="mc-val"><?= number_format($total_passengers) ?></div>
                <div class="mc-lbl">Total Passengers</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <div class="mc-ico" style="background:#dbeafe;"><i class="bi bi-journal-text" style="color:#2563eb;font-size:1.3rem;"></i></div>
                <div class="mc-val"><?= number_format($total_bookings) ?></div>
                <div class="mc-lbl">Bookings</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <div class="mc-ico" style="background:#dcfce7;"><i class="bi bi-check-circle-fill" style="color:#16a34a;font-size:1.3rem;"></i></div>
                <div class="mc-val"><?= number_format($confirmed_n) ?></div>
                <div class="mc-lbl">Confirmed</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <div class="mc-ico" style="background:#fef3c7;"><i class="bi bi-hourglass-split" style="color:#d97706;font-size:1.3rem;"></i></div>
                <div class="mc-val"><?= number_format($pending_n) ?></div>
                <div class="mc-lbl">Pending</div>
            </div>
        </div>
    </div>

    <!-- Filter bar -->
    <form method="GET" class="filter-bar no-print">
        <div class="row g-2 align-items-end">
            <!-- View toggle -->
            <div class="col-12 mb-1">
                <div class="view-toggle">
                    <a href="?<?= http_build_query(array_merge($_GET, ['view' => 'passengers'])) ?>"
                       class="<?= $view === 'passengers' ? 'active' : '' ?>">
                       <i class="bi bi-person-lines-fill me-1"></i>Passengers
                    </a>
                    <a href="?<?= http_build_query(array_merge($_GET, ['view' => 'bookings'])) ?>"
                       class="<?= $view === 'bookings' ? 'active' : '' ?>">
                       <i class="bi bi-journal-check me-1"></i>Bookings
                    </a>
                </div>
            </div>
            <div class="col-12 col-sm-4 col-lg-3">
                <label class="form-label mb-1 small fw-semibold">Journey Date</label>
                <input type="date" name="date" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($filter_date) ?>">
            </div>
            <div class="col-12 col-sm-4 col-lg-3">
                <label class="form-label mb-1 small fw-semibold">Route</label>
                <select name="route_id" class="form-select form-select-sm" onchange="">
                    <option value="">— All Routes —</option>
                    <?php foreach ($routes_for_date as $rd): ?>
                    <option value="<?= $rd['route_id'] ?>" <?= $route_id === (int)$rd['route_id'] ? 'selected':'' ?>>
                        <?= htmlspecialchars($rd['departure_city']) ?> → <?= htmlspecialchars($rd['arrival_city']) ?>
                        (<?= htmlspecialchars($rd['train_name']) ?>, <?= date('H:i', strtotime($rd['departure_time'])) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-sm-3 col-lg-2">
                <label class="form-label mb-1 small fw-semibold">Status</label>
                <select name="bstatus" class="form-select form-select-sm">
                    <?php foreach (['all'=>'All','confirmed'=>'Confirmed','pending'=>'Pending','cancelled'=>'Cancelled'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= $filter_status === $v ? 'selected':'' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm-5 col-lg-3">
                <label class="form-label mb-1 small fw-semibold">Search</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" class="form-control"
                           placeholder="Name / Booking Ref / Phone"
                           value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
            <div class="col-12 col-sm-auto d-flex gap-1">
                <button type="submit" class="btn btn-success btn-sm px-3">
                    <i class="bi bi-funnel me-1"></i>Apply
                </button>
                <a href="check-passengers.php" class="btn btn-outline-secondary btn-sm" title="Reset">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </div>
    </form>

    <!-- Print header (only on print) -->
    <div class="d-none d-print-block mb-3 text-center">
        <h4 class="fw-bold">🚂 Pakistan Railways – Passenger Manifest</h4>
        <div>Date: <?= $filter_date ? date('D, d M Y', strtotime($filter_date)) : 'All Dates' ?></div>
        <div>Printed: <?= date('d M Y H:i') ?></div>
        <hr>
    </div>

    <!-- ── PASSENGERS VIEW ── -->
    <?php if ($view === 'passengers'): ?>
    <div class="surface-card">
        <div class="sc-head no-print">
            <h6><i class="bi bi-person-lines-fill me-2 text-success"></i>Passenger List (<?= $total_passengers ?>)</h6>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 pax-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Passenger Name</th>
                        <th>Age</th>
                        <th>Gender</th>
                        <th>Seat</th>
                        <th>Class</th>
                        <th>Booking Ref</th>
                        <th>Route</th>
                        <th>B.Status</th>
                        <th>Payment</th>
                        <th>Booker / Phone</th>
                        <th class="no-print">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($passengers)): ?>
                    <tr><td colspan="12" class="text-center py-5 text-muted">
                        <i class="bi bi-people d-block fs-2 mb-2"></i>No passengers found for the selected filters.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($passengers as $n => $p): ?>
                    <tr>
                        <td class="text-muted small"><?= $n + 1 ?></td>
                        <td>
                            <div class="fw-semibold small"><?= htmlspecialchars($p['passenger_name']) ?></div>
                        </td>
                        <td class="small text-center"><?= htmlspecialchars($p['passenger_age'] ?? '—') ?></td>
                        <td class="small text-center"><?= $p['passenger_gender'] === 'M' ? 'M' : ($p['passenger_gender'] === 'F' ? 'F' : (htmlspecialchars($p['passenger_gender'] ?? '—'))) ?></td>
                        <td class="fw-bold small text-center"><?= htmlspecialchars($p['seat_number']) ?></td>
                        <td class="small text-center">
                            <span class="badge bg-secondary bg-opacity-15 text-dark" style="font-size:.7rem;">
                                <?= ucfirst($p['seat_type']) ?>
                            </span>
                        </td>
                        <td>
                            <a href="booking-emp.php?id=<?= $p['booking_id'] ?>"
                               class="fw-bold text-primary text-decoration-none small">
                                <?= htmlspecialchars($p['booking_reference']) ?>
                            </a>
                        </td>
                        <td class="small text-nowrap">
                            <?= htmlspecialchars($p['departure_city']) ?> → <?= htmlspecialchars($p['arrival_city']) ?>
                            <div class="text-muted" style="font-size:.7rem;"><?= htmlspecialchars($p['train_name']) ?></div>
                        </td>
                        <td><span class="sp sp-<?= $p['booking_status'] ?>"><?= ucfirst($p['booking_status']) ?></span></td>
                        <td><span class="sp sp-<?= $p['payment_status'] ?>"><?= ucfirst($p['payment_status']) ?></span></td>
                        <td>
                            <div class="small fw-semibold"><?= htmlspecialchars($p['booker_name']) ?></div>
                            <div class="text-muted" style="font-size:.7rem;"><?= htmlspecialchars($p['phone'] ?? '') ?></div>
                        </td>
                        <td class="no-print">
                            <a href="booking-emp.php?id=<?= $p['booking_id'] ?>"
                               class="btn btn-outline-success btn-sm py-0 px-2">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
                <?php if (!empty($passengers)): ?>
                <tfoot class="table-secondary">
                    <tr>
                        <td colspan="11" class="text-end small fw-bold">Total Passengers:</td>
                        <td class="small fw-bold no-print"><?= $total_passengers ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <!-- ── BOOKINGS VIEW ── -->
    <?php else: ?>
    <div class="surface-card">
        <div class="sc-head no-print">
            <h6><i class="bi bi-journal-check me-2 text-success"></i>Bookings (<?= $total_bookings ?>)</h6>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 pax-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Booking Ref</th>
                        <th>Passenger / Contact</th>
                        <th>Route</th>
                        <th>Journey Date</th>
                        <th>Seats</th>
                        <th>Fare</th>
                        <th>Status</th>
                        <th>Payment</th>
                        <th class="no-print">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($bookings_list)): ?>
                    <tr><td colspan="10" class="text-center py-5 text-muted">
                        <i class="bi bi-journal-x d-block fs-2 mb-2"></i>No bookings found.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($bookings_list as $n => $bk): ?>
                    <tr>
                        <td class="text-muted small"><?= $n + 1 ?></td>
                        <td>
                            <a href="booking-emp.php?id=<?= $bk['booking_id'] ?>"
                               class="fw-bold text-primary text-decoration-none small">
                                <?= htmlspecialchars($bk['booking_reference']) ?>
                            </a>
                        </td>
                        <td>
                            <div class="small fw-semibold"><?= htmlspecialchars($bk['full_name']) ?></div>
                            <div class="text-muted" style="font-size:.7rem;">
                                <?= htmlspecialchars($bk['phone'] ?? '') ?>
                                <?= $bk['email'] ? ' · ' . htmlspecialchars($bk['email']) : '' ?>
                            </div>
                        </td>
                        <td class="small text-nowrap">
                            <?= htmlspecialchars($bk['departure_city']) ?> → <?= htmlspecialchars($bk['arrival_city']) ?>
                            <div class="text-muted" style="font-size:.7rem;"><?= htmlspecialchars($bk['train_name']) ?> · <?= date('H:i', strtotime($bk['departure_time'])) ?></div>
                        </td>
                        <td class="small text-nowrap"><?= date('d M Y', strtotime($bk['journey_date'])) ?></td>
                        <td class="text-center small"><?= (int)$bk['number_of_seats'] ?></td>
                        <td class="small text-nowrap">Rs. <?= number_format($bk['total_fare'], 0) ?></td>
                        <td><span class="sp sp-<?= $bk['booking_status'] ?>"><?= ucfirst($bk['booking_status']) ?></span></td>
                        <td><span class="sp sp-<?= $bk['payment_status'] ?>"><?= ucfirst($bk['payment_status']) ?></span></td>
                        <td class="no-print">
                            <div class="d-flex gap-1">
                                <a href="booking-emp.php?id=<?= $bk['booking_id'] ?>"
                                   class="btn btn-outline-success btn-sm py-0 px-2" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="booking_details.php?id=<?= $bk['booking_id'] ?>"
                                   class="btn btn-outline-primary btn-sm py-0 px-2" title="E-Ticket">
                                    <i class="bi bi-ticket-detailed"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
                <?php if (!empty($bookings_list)): ?>
                <tfoot class="table-secondary">
                    <tr>
                        <td colspan="5" class="text-end small fw-bold">Totals:</td>
                        <td class="small fw-bold"><?= array_sum(array_column($bookings_list, 'number_of_seats')) ?> seats</td>
                        <td class="small fw-bold">Rs. <?= number_format(array_sum(array_column($bookings_list, 'total_fare')), 0) ?></td>
                        <td colspan="3" class="no-print"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
    <?php endif; ?>

    </div><!-- /emp-content -->
</main>
</div>

<?php require_once 'inc/footer.php'; ?>

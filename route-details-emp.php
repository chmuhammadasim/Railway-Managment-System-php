<?php
// route-details-emp.php – Employee: Route Detail View

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

// Accept ?id= or ?train_id= (show first route for a train today)
$route_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$train_id = isset($_GET['train_id']) ? (int)$_GET['train_id'] : 0;

if (!$route_id && $train_id) {
    $r = $db->selectRow("SELECT route_id FROM routes
                          WHERE train_id = {$train_id}
                          AND DATE(journey_date) = CURDATE()
                          ORDER BY departure_time ASC LIMIT 1");
    if ($r) $route_id = (int)$r['route_id'];
}

if (!$route_id) {
    header('Location: employee-dashboard.php');
    exit();
}

// ── Status update (AJAX + form) ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_status = $_POST['status'] ?? '';
    $note       = trim($_POST['note'] ?? '');
    $allowed    = ['scheduled', 'cancelled', 'completed'];

    if (in_array($new_status, $allowed)) {
        $db->query("UPDATE routes SET status = '{$conn->real_escape_string($new_status)}' WHERE route_id = {$route_id}");
        $success_message = 'Route status updated to ' . ucfirst($new_status) . '.';
    }

    if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit();
    }
}

// ── Route details ─────────────────────────────────────────────────────────────
$route = $db->selectRow(
    "SELECT r.*, t.train_name, t.train_number, t.train_type, t.total_seats
     FROM routes r
     JOIN trains t ON r.train_id = t.train_id
     WHERE r.route_id = {$route_id}"
);
if (!$route) {
    header('Location: employee-dashboard.php');
    exit();
}

// ── Booking / passenger counts ────────────────────────────────────────────────
$stats = $db->selectRow(
    "SELECT
        COUNT(*)                                  AS total_bookings,
        SUM(booking_status = 'confirmed')         AS confirmed,
        SUM(booking_status = 'pending')           AS pending,
        SUM(booking_status = 'cancelled')         AS cancelled,
        COALESCE(SUM(number_of_seats), 0)         AS total_seats_booked,
        COALESCE(SUM(CASE WHEN booking_status = 'confirmed' THEN total_fare ELSE 0 END), 0) AS revenue
     FROM bookings
     WHERE route_id = {$route_id}"
);

// ── Passenger manifest ────────────────────────────────────────────────────────
$passengers = $db->select(
    "SELECT bs.passenger_name, bs.passenger_age, bs.passenger_gender,
            s.seat_number, s.seat_type,
            b.booking_reference, b.booking_status, b.payment_status,
            b.booking_id,
            u.full_name AS booker_name, u.phone
     FROM booking_seats bs
     JOIN bookings b  ON bs.booking_id = b.booking_id
     JOIN seats    s  ON bs.seat_id    = s.seat_id
     JOIN users    u  ON b.user_id     = u.user_id
     WHERE b.route_id = {$route_id}
     ORDER BY s.seat_number ASC"
);
if (!$passengers) $passengers = [];

// ── Seat map data ─────────────────────────────────────────────────────────────
$all_seats = $db->select(
    "SELECT s.*, bs.passenger_name
     FROM seats s
     LEFT JOIN booking_seats bs ON s.seat_id = bs.seat_id
     WHERE s.route_id = {$route_id}
     ORDER BY s.seat_number ASC"
);
if (!$all_seats) $all_seats = [];

// Seat map grouped by row (letter)
$seat_map = [];
foreach ($all_seats as $seat) {
    $row = substr($seat['seat_number'], 0, 1);
    $seat_map[$row][] = $seat;
}

$search_pass = trim($_GET['search'] ?? '');

$hideMainNavbar = true;
$pageTitle = 'Route Details – ' . $route['departure_city'] . ' → ' . $route['arrival_city'];
require_once 'inc/header.php';
?>

<style>
.emp-wrap { display:flex; min-height:calc(100vh - 60px); }
.emp-sidebar {
    width:230px; flex-shrink:0;
    background:linear-gradient(180deg,#064e3b 0%,#065f46 100%);
    padding:1.5rem 0; position:sticky; top:60px;
    height:calc(100vh - 60px); overflow-y:auto;
}
.emp-sidebar .sb-brand { padding:.5rem 1.25rem 1.25rem; font-weight:800; font-size:1.05rem; color:#d1fae5; border-bottom:1px solid rgba(255,255,255,.15); margin-bottom:.75rem; }
.emp-sidebar a { display:flex; align-items:center; gap:.65rem; padding:.55rem 1.25rem; color:rgba(255,255,255,.82); text-decoration:none; font-size:.88rem; transition:background .2s,color .2s; }
.emp-sidebar a:hover, .emp-sidebar a.active { background:rgba(255,255,255,.16); color:#fff; }
.emp-sidebar .sb-section { font-size:.7rem; text-transform:uppercase; letter-spacing:.08em; color:rgba(255,255,255,.42); padding:.9rem 1.25rem .3rem; font-weight:700; }
.emp-main { flex:1; padding:1.75rem; overflow:hidden; }

/* ── Route header ── */
.route-banner {
    background:linear-gradient(135deg,#064e3b 0%,#059669 100%);
    border-radius:14px; color:#fff; padding:1.4rem 1.8rem;
}
.rj-city { font-size:1.9rem; font-weight:900; }
.rj-label { font-size:.75rem; opacity:.7; text-transform:uppercase; letter-spacing:.5px; }
.rj-line  { flex:1; margin:0 1rem; }
.rj-line-bar { height:3px; background:rgba(255,255,255,.4); border-radius:3px; position:relative; }
.rj-line-bar::after { content:'🚄'; position:absolute; top:-11px; left:50%; transform:translateX(-50%); font-size:1.2rem; }

/* ── Status badge ── */
.sp { display:inline-block; padding:.3em .9em; border-radius:20px; font-size:.8rem; font-weight:700; }
.sp-scheduled { background:#d1fae5; color:#065f46; }
.sp-confirmed { background:#d1fae5; color:#065f46; }
.sp-pending   { background:#fef3c7; color:#92400e; }
.sp-cancelled { background:#fee2e2; color:#7f1d1d; }
.sp-completed { background:#e0e7ff; color:#3730a3; }
.sp-booked    { background:#fee2e2; color:#7f1d1d; }
.sp-available { background:#d1fae5; color:#065f46; }
.sp-reserved  { background:#fef9c3; color:#78350f; }

/* ── Stat ── */
.kpi-card { border-radius:12px; border:none; box-shadow:0 2px 8px rgba(0,0,0,.07); }
.kpi-icon { width:44px; height:44px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; }

/* ── Passenger table ── */
.pax-table thead th { background:#064e3b; color:#fff; font-weight:600; font-size:.82rem; white-space:nowrap; }
.pax-table tbody tr:hover { background:#f0fdf4; }
.pax-table td { font-size:.84rem; vertical-align:middle; }

/* ── Seat map ── */
.seat-map { display:flex; flex-direction:column; gap:.4rem; }
.seat-row { display:flex; gap:.4rem; align-items:center; }
.seat-row-label { width:22px; font-size:.75rem; font-weight:700; color:#6b7280; text-align:center; }
.seat {
    width:42px; height:34px; border-radius:6px; font-size:.72rem; font-weight:700;
    display:flex; align-items:center; justify-content:center;
    cursor:default; border:2px solid transparent; transition:all .15s;
    position:relative;
}
.seat-available { background:#d1fae5; border-color:#6ee7b7; color:#065f46; }
.seat-booked    { background:#fee2e2; border-color:#fca5a5; color:#7f1d1d; }
.seat-reserved  { background:#fef9c3; border-color:#fde047; color:#78350f; }
.seat[title]:hover::after {
    content:attr(title); position:absolute; bottom:110%; left:50%; transform:translateX(-50%);
    background:#1e293b; color:#fff; padding:.2rem .5rem; border-radius:5px; white-space:nowrap;
    font-size:.68rem; z-index:10; pointer-events:none;
}

/* ── Print ── */
.occ-bar { height:6px; border-radius:4px; background:#e5e7eb; overflow:hidden; }
.occ-fill { height:100%; border-radius:4px; background:linear-gradient(90deg,#10b981,#059669); }
@media print { .no-print { display:none !important; } }
@media (max-width:768px) { .emp-sidebar { display:none; } .emp-main { padding:1rem; } }
</style>

<div class="emp-wrap">
<!-- Sidebar -->
<aside class="emp-sidebar no-print">
    <div class="sb-brand"><i class="bi bi-train-front-fill me-2"></i>Employee Panel</div>
    <div class="sb-section">Main</div>
    <a href="employee-dashboard.php"><i class="bi bi-speedometer2"></i>Dashboard</a>
    <div class="sb-section">Operations</div>
    <a href="my-trains.php"><i class="bi bi-train-front"></i>My Trains</a>
    <a href="check-passengers.php"><i class="bi bi-people"></i>Passengers</a>
    <a href="assign-seats.php"><i class="bi bi-grid-3x3-gap"></i>Seat Management</a>
    <div class="sb-section">Account</div>
    <a href="profile.php"><i class="bi bi-person-circle"></i>My Profile</a>
    <a href="logout.php"><i class="bi bi-box-arrow-right"></i>Logout</a>
</aside>

<main class="emp-main">

    <!-- Back + actions -->
    <div class="d-flex justify-content-between align-items-center mb-3 no-print flex-wrap gap-2">
        <a href="employee-dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Dashboard
        </a>
        <div class="d-flex gap-2 flex-wrap">
            <a href="check-passengers.php?route_id=<?= $route_id ?>" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-people me-1"></i>Passenger List
            </a>
            <a href="assign-seats.php?route_id=<?= $route_id ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-grid-3x3-gap me-1"></i>Seat Map
            </a>
            <button onclick="window.print()" class="btn btn-outline-success btn-sm">
                <i class="bi bi-printer me-1"></i>Print
            </button>
        </div>
    </div>

    <?php if (!empty($success_message)): ?>
    <div class="alert alert-success border-0 rounded-3 py-2 mb-3">
        <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success_message) ?>
    </div>
    <?php endif; ?>

    <!-- Route Banner -->
    <div class="route-banner mb-4 d-flex align-items-center flex-wrap gap-3">
        <div class="text-center">
            <div class="rj-city"><?= htmlspecialchars($route['departure_city']) ?></div>
            <div class="rj-label">Departure <?= date('H:i', strtotime($route['departure_time'])) ?></div>
        </div>
        <div class="rj-line flex-grow-1">
            <div class="rj-line-bar"></div>
            <div class="text-center mt-2" style="font-size:.78rem; opacity:.75;">
                <?= number_format($route['distance_km'], 0) ?> km &nbsp;·&nbsp;
                <?= htmlspecialchars($route['train_type']) ?>
            </div>
        </div>
        <div class="text-center">
            <div class="rj-city"><?= htmlspecialchars($route['arrival_city']) ?></div>
            <div class="rj-label">Arrival <?= date('H:i', strtotime($route['arrival_time'])) ?></div>
        </div>
        <div class="vr mx-2 d-none d-md-block" style="opacity:.3;"></div>
        <div class="text-center ms-md-2">
            <div class="fw-bold" style="font-size:.9rem;"><?= htmlspecialchars($route['train_name']) ?></div>
            <div style="font-size:.78rem; opacity:.8;"><?= htmlspecialchars($route['train_number']) ?></div>
            <div class="mt-1">
                <span class="sp sp-<?= $route['status'] ?>"><?= ucfirst($route['status']) ?></span>
            </div>
            <div style="font-size:.78rem; opacity:.75; margin-top:.3rem;">
                <?= date('D, d M Y', strtotime($route['journey_date'])) ?>
            </div>
        </div>
    </div>

    <!-- Stats + Status Update -->
    <div class="row g-3 mb-4 align-items-start">
        <!-- Stats -->
        <div class="col-lg-8">
            <div class="row g-3">
                <?php
                $booked_seats = (int)($stats['total_seats_booked'] ?? 0);
                $total_seats  = (int)($route['total_seats'] ?? 1);
                $occ_pct      = $total_seats > 0 ? round($booked_seats / $total_seats * 100) : 0;
                ?>
                <?php foreach ([
                    [(int)($stats['confirmed']  ?? 0), 'Confirmed', 'bi-check-circle',  'bg-success text-white', '#f0fdf4'],
                    [(int)($stats['pending']    ?? 0), 'Pending',   'bi-hourglass',     'bg-warning text-dark',  '#fffbeb'],
                    [(int)($stats['cancelled']  ?? 0), 'Cancelled', 'bi-x-circle',      'bg-danger text-white',  '#fef2f2'],
                    ['Rs.'.number_format($stats['revenue'] ?? 0,0), 'Revenue', 'bi-currency-dollar', 'bg-primary text-white', '#eff6ff'],
                ] as [$v, $l, $ic, $ibg, $bg]): ?>
                <div class="col-6 col-sm-3">
                    <div class="kpi-card card p-3" style="background:<?= $bg ?>;">
                        <div class="d-flex align-items-center gap-2">
                            <div class="kpi-icon <?= $ibg ?>" style="width:38px;height:38px;font-size:1rem;">
                                <i class="bi <?= $ic ?>"></i>
                            </div>
                            <div>
                                <div class="fw-bold"><?= $v ?></div>
                                <div class="text-muted" style="font-size:.72rem;"><?= $l ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- Occupancy bar -->
                <div class="col-12">
                    <div class="card border-0 shadow-sm p-3">
                        <div class="d-flex justify-content-between small mb-2 fw-semibold">
                            <span>Seat Occupancy</span>
                            <span><?= $booked_seats ?> / <?= $total_seats ?> seats booked (<?= $occ_pct ?>%)</span>
                        </div>
                        <div class="occ-bar" style="height:12px;">
                            <div class="occ-fill" style="width:<?= $occ_pct ?>%;
                                background:<?= $occ_pct > 80 ? 'linear-gradient(90deg,#ef4444,#dc2626)' : 'linear-gradient(90deg,#10b981,#059669)' ?>;"></div>
                        </div>
                        <div class="d-flex justify-content-between text-muted mt-1" style="font-size:.72rem;">
                            <span><?= $route['available_seats'] ?> seats still available</span>
                            <span>Rs. <?= number_format($route['base_fare'], 2) ?> / seat</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Status update panel -->
        <div class="col-lg-4 no-print">
            <div class="card border-0 shadow-sm p-3">
                <h6 class="fw-bold mb-3"><i class="bi bi-sliders me-2 text-success"></i>Update Route Status</h6>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Route Status</label>
                        <select name="status" class="form-select">
                            <?php foreach (['scheduled','cancelled','completed'] as $st): ?>
                            <option value="<?= $st ?>" <?= $route['status'] === $st ? 'selected':'' ?>>
                                <?= ucfirst($st) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-success w-100">
                        <i class="bi bi-check2 me-1"></i>Update Status
                    </button>
                </form>
            </div>

            <!-- Route info card -->
            <div class="card border-0 shadow-sm p-3 mt-3">
                <h6 class="fw-bold mb-3"><i class="bi bi-info-circle me-2 text-primary"></i>Route Info</h6>
                <?php foreach ([
                    ['Train',          htmlspecialchars($route['train_name'])],
                    ['Train No.',      htmlspecialchars($route['train_number'])],
                    ['Type',           htmlspecialchars($route['train_type'])],
                    ['Distance',       number_format($route['distance_km'], 0) . ' km'],
                    ['Base Fare',      'Rs. ' . number_format($route['base_fare'], 2)],
                    ['Available Seats', (int)$route['available_seats']],
                ] as [$lbl, $val]): ?>
                <div class="d-flex justify-content-between py-1 border-bottom" style="font-size:.83rem;">
                    <span class="text-muted"><?= $lbl ?></span>
                    <strong><?= $val ?></strong>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Tabs: Passenger Manifest | Seat Map -->
    <ul class="nav nav-tabs mb-3 no-print" id="routeTabs">
        <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#tab-passengers">
                <i class="bi bi-people me-1"></i>Passenger Manifest (<?= count($passengers) ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#tab-seatmap">
                <i class="bi bi-grid-3x3-gap me-1"></i>Seat Map
            </a>
        </li>
    </ul>

    <div class="tab-content">
        <!-- Passenger manifest tab -->
        <div class="tab-pane fade show active" id="tab-passengers">
            <div class="card border-0 shadow-sm">
                <!-- Search -->
                <div class="card-header bg-white border-bottom d-flex gap-2 align-items-center flex-wrap no-print">
                    <h6 class="mb-0 fw-bold me-auto"><i class="bi bi-list-ul me-2"></i>Passenger Manifest</h6>
                    <form method="GET" class="d-flex gap-2">
                        <input type="hidden" name="id" value="<?= $route_id ?>">
                        <input type="text" name="search" class="form-control form-control-sm"
                               placeholder="Search passenger…"
                               value="<?= htmlspecialchars($search_pass) ?>" style="width:180px;">
                        <button type="submit" class="btn btn-success btn-sm">
                            <i class="bi bi-search"></i>
                        </button>
                    </form>
                    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-printer me-1"></i>Print
                    </button>
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
                                <th>Booking Status</th>
                                <th>Payment</th>
                                <th class="no-print">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $filtered = $passengers;
                        if ($search_pass !== '') {
                            $sq = strtolower($search_pass);
                            $filtered = array_filter($passengers, fn($p) =>
                                stripos($p['passenger_name'], $sq) !== false ||
                                stripos($p['booking_reference'], $sq) !== false ||
                                stripos($p['booker_name'], $sq) !== false
                            );
                        }
                        if (empty($filtered)): ?>
                            <tr><td colspan="10" class="text-center py-4 text-muted">
                                <i class="bi bi-people d-block fs-3 mb-2"></i>No passengers found.
                            </td></tr>
                        <?php else: ?>
                        <?php $n = 0; foreach ($filtered as $p): $n++; ?>
                            <tr>
                                <td class="text-muted small"><?= $n ?></td>
                                <td>
                                    <div class="fw-semibold small"><?= htmlspecialchars($p['passenger_name']) ?></div>
                                    <div class="text-muted" style="font-size:.72rem;"><?= htmlspecialchars($p['booker_name']) ?></div>
                                </td>
                                <td class="small"><?= htmlspecialchars($p['passenger_age'] ?? '—') ?></td>
                                <td class="small"><?= $p['passenger_gender'] === 'M' ? 'Male' : ($p['passenger_gender'] === 'F' ? 'Female' : (htmlspecialchars($p['passenger_gender'] ?? '—'))) ?></td>
                                <td class="fw-bold small"><?= htmlspecialchars($p['seat_number']) ?></td>
                                <td><span class="badge bg-secondary bg-opacity-15 text-dark" style="font-size:.72rem;"><?= ucfirst($p['seat_type']) ?></span></td>
                                <td>
                                    <a href="booking-emp.php?id=<?= $p['booking_id'] ?>"
                                       class="fw-bold text-primary text-decoration-none small">
                                        <?= htmlspecialchars($p['booking_reference']) ?>
                                    </a>
                                </td>
                                <td><span class="sp sp-<?= $p['booking_status'] ?>"><?= ucfirst($p['booking_status']) ?></span></td>
                                <td><span class="sp sp-<?= $p['payment_status'] ?>"><?= ucfirst($p['payment_status']) ?></span></td>
                                <td class="no-print">
                                    <a href="booking-emp.php?id=<?= $p['booking_id'] ?>"
                                       class="btn btn-outline-primary btn-sm py-0 px-2">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                        <?php if (!empty($filtered)): ?>
                        <tfoot>
                            <tr class="table-secondary fw-bold">
                                <td colspan="9" class="text-end small">Total passengers shown:</td>
                                <td class="small no-print"><?= count($filtered) ?></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

        <!-- Seat map tab -->
        <div class="tab-pane fade" id="tab-seatmap">
            <div class="card border-0 shadow-sm p-4">
                <div class="d-flex align-items-center gap-4 mb-3 flex-wrap">
                    <h6 class="fw-bold mb-0"><i class="bi bi-grid-3x3-gap me-2"></i>Seat Map</h6>
                    <div class="d-flex gap-2 flex-wrap">
                        <span class="d-flex align-items-center gap-1 small"><span class="badge" style="background:#d1fae5;color:#065f46;width:20px;height:20px;">&nbsp;</span> Available</span>
                        <span class="d-flex align-items-center gap-1 small"><span class="badge" style="background:#fee2e2;color:#7f1d1d;width:20px;height:20px;">&nbsp;</span> Booked</span>
                        <span class="d-flex align-items-center gap-1 small"><span class="badge" style="background:#fef9c3;color:#78350f;width:20px;height:20px;">&nbsp;</span> Reserved</span>
                    </div>
                    <a href="assign-seats.php?route_id=<?= $route_id ?>" class="btn btn-success btn-sm ms-auto">
                        <i class="bi bi-pencil me-1"></i>Manage Seats
                    </a>
                </div>

                <?php if (empty($seat_map)): ?>
                <p class="text-muted">No seat data available for this route.</p>
                <?php else: ?>
                <div class="d-flex justify-content-center">
                    <div>
                        <!-- Column headers -->
                        <div class="seat-row mb-1">
                            <div class="seat-row-label"></div>
                            <?php
                            $max_cols = max(array_map('count', $seat_map));
                            for ($c = 1; $c <= $max_cols; $c++):
                            ?>
                            <div style="width:42px; text-align:center; font-size:.72rem; color:#9ca3af;"><?= $c ?></div>
                            <?php endfor; ?>
                        </div>
                        <div class="seat-map">
                        <?php foreach ($seat_map as $row_letter => $seats): ?>
                        <div class="seat-row">
                            <div class="seat-row-label"><?= $row_letter ?></div>
                            <?php foreach ($seats as $seat): ?>
                            <?php
                            $cls   = 'seat-' . $seat['status'];
                            $tip   = $seat['status'] === 'booked' ? ($seat['passenger_name'] ?? 'Booked') :
                                     ($seat['status'] === 'reserved' ? 'Reserved' : 'Available');
                            ?>
                            <div class="seat <?= $cls ?>"
                                 title="<?= htmlspecialchars($seat['seat_number'] . ': ' . $tip) ?>">
                                <?= htmlspecialchars($seat['seat_number']) ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</main>
</div>

<?php require_once 'inc/footer.php'; ?>

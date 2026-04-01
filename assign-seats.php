<?php
// assign-seats.php – Employee: Seat Management

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

$success_message = '';
$error_message   = '';

// ── POST: Reserve / Unreserve / Reassign seat ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['post_action'] ?? '';
    $seat_id     = (int)($_POST['seat_id']     ?? 0);
    $route_id_p  = (int)($_POST['route_id']    ?? 0);

    if ($seat_id > 0 && $route_id_p > 0) {
        if ($post_action === 'reserve') {
            $existing = $db->selectRow("SELECT status FROM seats WHERE seat_id = {$seat_id}");
            if ($existing && $existing['status'] === 'available') {
                $db->query("UPDATE seats SET status='reserved' WHERE seat_id = {$seat_id}");
                $db->query("UPDATE routes SET available_seats = available_seats - 1 WHERE route_id = {$route_id_p} AND available_seats > 0");
                $success_message = 'Seat marked as reserved.';
            } else {
                $error_message = 'Seat is not available.';
            }
        } elseif ($post_action === 'unreserve') {
            $existing = $db->selectRow("SELECT status FROM seats WHERE seat_id = {$seat_id}");
            if ($existing && $existing['status'] === 'reserved') {
                $db->query("UPDATE seats SET status='available' WHERE seat_id = {$seat_id}");
                $db->query("UPDATE routes SET available_seats = available_seats + 1 WHERE route_id = {$route_id_p}");
                $success_message = 'Seat reservation removed.';
            } else {
                $error_message = 'Seat is not reserved.';
            }
        } elseif ($post_action === 'bulk_reserve') {
            $seat_ids_raw = $_POST['seat_ids'] ?? '';
            $ids = array_filter(array_map('intval', explode(',', $seat_ids_raw)));
            $reserved = 0;
            foreach ($ids as $sid) {
                $ex = $db->selectRow("SELECT status FROM seats WHERE seat_id = {$sid}");
                if ($ex && $ex['status'] === 'available') {
                    $db->query("UPDATE seats SET status='reserved' WHERE seat_id = {$sid}");
                    $reserved++;
                }
            }
            if ($reserved > 0) {
                $db->query("UPDATE routes SET available_seats = available_seats - {$reserved} WHERE route_id = {$route_id_p} AND available_seats >= {$reserved}");
                $success_message = "{$reserved} seat(s) reserved.";
            } else {
                $error_message = 'No available seats selected.';
            }
        }
    }

    $qs = http_build_query(array_filter(['route_id' => $route_id_p ?: null]));
    header('Location: assign-seats.php' . ($qs ? "?{$qs}" : ''));
    exit();
}

// ── Load routes (for selector) ────────────────────────────────────────────────
$route_id = isset($_GET['route_id']) ? (int)$_GET['route_id'] : 0;
$train_id = isset($_GET['train_id']) ? (int)$_GET['train_id'] : 0;

// Build available routes list (today + future scheduled)
$all_routes = $db->select(
    "SELECT r.route_id, r.departure_city, r.arrival_city, r.journey_date,
            r.departure_time, r.available_seats, r.status,
            t.train_name, t.train_number
     FROM routes r
     JOIN trains t ON r.train_id = t.train_id
     WHERE r.journey_date >= CURDATE()
     AND r.status = 'scheduled'
     " . ($train_id ? "AND r.train_id = {$train_id}" : "") . "
     ORDER BY r.journey_date ASC, r.departure_time ASC
     LIMIT 50"
);
if (!$all_routes) $all_routes = [];

// Default to first route if none selected
if (!$route_id && !empty($all_routes)) {
    $route_id = (int)$all_routes[0]['route_id'];
}

// ── Route info ────────────────────────────────────────────────────────────────
$route = null;
$seat_map = [];
$seat_stats = ['available' => 0, 'booked' => 0, 'reserved' => 0, 'total' => 0];

if ($route_id) {
    $route = $db->selectRow(
        "SELECT r.*, t.train_name, t.train_number, t.total_seats
         FROM routes r JOIN trains t ON r.train_id = t.train_id
         WHERE r.route_id = {$route_id}"
    );

    if ($route) {
        $all_seats = $db->select(
            "SELECT s.*, bs.passenger_name, b.booking_reference, b.booking_id
             FROM seats s
             LEFT JOIN booking_seats bs ON s.seat_id = bs.seat_id
             LEFT JOIN bookings     b  ON bs.booking_id = b.booking_id
             WHERE s.route_id = {$route_id}
             ORDER BY s.seat_number ASC"
        );
        if (!$all_seats) $all_seats = [];

        foreach ($all_seats as $seat) {
            $row = substr($seat['seat_number'], 0, 1);
            $seat_map[$row][] = $seat;
            $seat_stats[$seat['status']] = ($seat_stats[$seat['status']] ?? 0) + 1;
            $seat_stats['total']++;
        }
    }
}

$filter_type = $_GET['type'] ?? 'all'; // all, available, booked, reserved

$hideMainNavbar = true;
$pageTitle = 'Seat Management – Employee';
//require_once 'inc/header.php';
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

/* ── Seat grid ── */
.seat-container { overflow-x:auto; }
.seat-grid { display:inline-grid; gap:.45rem; }
.seat-row-wrap { display:flex; align-items:center; gap:.45rem; }
.row-label { width:24px; text-align:center; font-size:.72rem; font-weight:700; color:#6b7280; }
.seat {
    width:52px; height:42px; border-radius:8px; font-size:.7rem; font-weight:700;
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    border:2px solid transparent; cursor:pointer; transition:all .15s;
    position:relative; user-select:none;
}
.seat .seat-num  { font-size:.72rem; font-weight:700; line-height:1; }
.seat .seat-name { font-size:.58rem; line-height:1.2; text-align:center; max-width:48px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.seat-available { background:#d1fae5; border-color:#6ee7b7; color:#065f46; }
.seat-available:hover { background:#a7f3d0; border-color:#34d399; transform:scale(1.08); box-shadow:0 3px 10px rgba(6,95,70,.2); }
.seat-booked    { background:#fee2e2; border-color:#fca5a5; color:#7f1d1d; cursor:default; }
.seat-reserved  { background:#fef9c3; border-color:#fde047; color:#78350f; }
.seat-reserved:hover { background:#fde68a; border-color:#facc15; transform:scale(1.08); }
.seat.selected  { outline:3px solid #2563eb; outline-offset:2px; }

/* seat type dot */
.type-dot {
    width:5px; height:5px; border-radius:50%; position:absolute; top:4px; right:4px;
}
.type-economy { background:#6b7280; }
.type-premium { background:#7c3aed; }
.type-luxury  { background:#d97706; }

/* ── Legend ── */
.legend-item { display:flex; align-items:center; gap:.4rem; font-size:.8rem; }
.legend-box  { width:18px; height:14px; border-radius:3px; border:2px solid transparent; }

/* ── Stats ── */
.occ-bar { height:8px; border-radius:4px; background:#e5e7eb; overflow:hidden; }
.occ-fill { height:100%; border-radius:4px; background:linear-gradient(90deg,#10b981,#059669); }
.sp { display:inline-block; padding:.25em .7em; border-radius:20px; font-size:.77rem; font-weight:600; }
.sp-available { background:#d1fae5; color:#065f46; }
.sp-booked    { background:#fee2e2; color:#7f1d1d; }
.sp-reserved  { background:#fef9c3; color:#78350f; }

@media (max-width:768px) { .emp-sidebar { display:none; } .emp-main { padding:1rem; } }
</style>

<div class="emp-wrap">
<aside class="emp-sidebar">
    <div class="sb-brand"><i class="bi bi-train-front-fill me-2"></i>Employee Panel</div>
    <div class="sb-section">Main</div>
    <a href="employee-dashboard.php"><i class="bi bi-speedometer2"></i>Dashboard</a>
    <div class="sb-section">Operations</div>
    <a href="my-trains.php"><i class="bi bi-train-front"></i>My Trains</a>
    <a href="check-passengers.php"><i class="bi bi-people"></i>Passengers</a>
    <a href="assign-seats.php" class="active"><i class="bi bi-grid-3x3-gap"></i>Seat Management</a>
    <div class="sb-section">Account</div>
    <a href="profile.php"><i class="bi bi-person-circle"></i>My Profile</a>
    <a href="logout.php"><i class="bi bi-box-arrow-right"></i>Logout</a>
</aside>

<main class="emp-main">

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-0"><i class="bi bi-grid-3x3-gap me-2 text-success"></i>Seat Management</h4>
            <small class="text-muted">Reserve, unreserve, and view seat assignments by route</small>
        </div>
        <a href="employee-dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Dashboard
        </a>
    </div>

    <?php if ($success_message): ?>
    <div class="alert alert-success border-0 rounded-3 py-2 mb-3">
        <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success_message) ?>
    </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
    <div class="alert alert-danger border-0 rounded-3 py-2 mb-3">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error_message) ?>
    </div>
    <?php endif; ?>

    <!-- Route Selector -->
    <div class="card border-0 shadow-sm p-3 mb-4">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-7">
                <label class="form-label small fw-semibold mb-1">Select Route</label>
                <select name="route_id" class="form-select" onchange="this.form.submit()">
                    <option value="">— Choose a route —</option>
                    <?php foreach ($all_routes as $r): ?>
                    <option value="<?= $r['route_id'] ?>" <?= $route_id === (int)$r['route_id'] ? 'selected':'' ?>>
                        <?= htmlspecialchars($r['train_name']) ?> &nbsp;|&nbsp;
                        <?= htmlspecialchars($r['departure_city']) ?> → <?= htmlspecialchars($r['arrival_city']) ?> &nbsp;|&nbsp;
                        <?= date('d M Y', strtotime($r['journey_date'])) ?>
                        (<?= $r['available_seats'] ?> free)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold mb-1">Show</label>
                <select name="type" class="form-select" onchange="this.form.submit()">
                    <?php foreach (['all'=>'All Seats','available'=>'Available','booked'=>'Booked','reserved'=>'Reserved'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= $filter_type === $v ? 'selected':'' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <button type="submit" class="btn btn-success w-100"><i class="bi bi-funnel me-1"></i>Apply</button>
            </div>
        </form>
    </div>

    <?php if ($route && !empty($seat_map)): ?>

    <!-- Route summary + Occupancy -->
    <div class="row g-3 mb-4 align-items-center">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm p-3">
                <div class="fw-bold mb-1">
                    <?= htmlspecialchars($route['departure_city']) ?> → <?= htmlspecialchars($route['arrival_city']) ?>
                    &nbsp;<span class="badge bg-success bg-opacity-15 text-success"><?= htmlspecialchars($route['train_name']) ?></span>
                </div>
                <div class="text-muted small mb-2">
                    <?= date('D, d M Y', strtotime($route['journey_date'])) ?> &nbsp;·&nbsp;
                    <?= date('H:i', strtotime($route['departure_time'])) ?> → <?= date('H:i', strtotime($route['arrival_time'])) ?>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <span class="sp sp-available"><?= $seat_stats['available'] ?> Available</span>
                    <span class="sp sp-booked"><?= $seat_stats['booked'] ?> Booked</span>
                    <span class="sp sp-reserved"><?= $seat_stats['reserved'] ?> Reserved</span>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm p-3">
                <?php $booked_n = $seat_stats['booked'] + $seat_stats['reserved']; ?>
                <div class="d-flex justify-content-between small fw-semibold mb-2">
                    <span>Occupancy</span>
                    <span><?= $booked_n ?> / <?= $seat_stats['total'] ?></span>
                </div>
                <div class="occ-bar">
                    <div class="occ-fill" style="width:<?= $seat_stats['total'] > 0 ? round($booked_n / $seat_stats['total'] * 100) : 0 ?>%;"></div>
                </div>
                <div class="d-flex justify-content-between text-muted mt-1" style="font-size:.72rem;">
                    <span><?= $seat_stats['available'] ?> seats still free</span>
                    <a href="route-details-emp.php?id=<?= $route_id ?>" class="text-success text-decoration-none">
                        <i class="bi bi-map me-1"></i>Route Details
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Seat Map -->
    <div class="card border-0 shadow-sm p-4">
        <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
            <h6 class="fw-bold mb-0"><i class="bi bi-grid-3x3-gap me-2"></i>Seat Map</h6>
            <!-- Legend -->
            <div class="d-flex gap-3 flex-wrap ms-auto">
                <div class="legend-item">
                    <div class="legend-box" style="background:#d1fae5; border-color:#6ee7b7;"></div> Available
                </div>
                <div class="legend-item">
                    <div class="legend-box" style="background:#fee2e2; border-color:#fca5a5;"></div> Booked
                </div>
                <div class="legend-item">
                    <div class="legend-box" style="background:#fef9c3; border-color:#fde047;"></div> Reserved
                </div>
                <div class="legend-item">
                    <span class="type-dot type-economy d-inline-block"></span> Economy
                    <span class="type-dot type-premium d-inline-block ms-2"></span> Premium
                    <span class="type-dot type-luxury  d-inline-block ms-2"></span> Luxury
                </div>
            </div>
        </div>

        <!-- Bulk reserve form -->
        <form method="POST" id="bulkForm">
            <input type="hidden" name="post_action" value="bulk_reserve">
            <input type="hidden" name="route_id"    value="<?= $route_id ?>">
            <input type="hidden" name="seat_ids"    id="selectedSeatIds">

            <div id="bulkBar" class="d-none mb-3 p-2 bg-primary bg-opacity-10 rounded-3 d-flex align-items-center gap-3">
                <span id="selectedCount" class="fw-semibold small text-primary">0 seats selected</span>
                <button type="submit" class="btn btn-warning btn-sm" onclick="return confirm('Reserve selected seats?')">
                    <i class="bi bi-lock me-1"></i>Reserve Selected
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearSelection()">
                    Clear
                </button>
            </div>

            <!-- Seat grid -->
            <div class="seat-container">
                <!-- Column headers -->
                <?php
                $max_cols = max(array_map('count', $seat_map));
                ?>
                <div class="seat-row-wrap mb-1">
                    <div class="row-label"></div>
                    <?php for ($c = 1; $c <= $max_cols; $c++): ?>
                    <div style="width:52px; text-align:center; font-size:.72rem; color:#9ca3af;"><?= $c ?></div>
                    <?php endfor; ?>
                </div>

                <?php foreach ($seat_map as $row_letter => $seats): ?>
                <div class="seat-row-wrap">
                    <div class="row-label"><?= $row_letter ?></div>
                    <?php foreach ($seats as $seat):
                        // Apply filter
                        if ($filter_type !== 'all' && $seat['status'] !== $filter_type) {
                            echo '<div class="seat" style="visibility:hidden;"></div>';
                            continue;
                        }
                    ?>
                    <div class="seat seat-<?= $seat['status'] ?>"
                         data-seat-id="<?= $seat['seat_id'] ?>"
                         data-status="<?= $seat['status'] ?>"
                         data-route="<?= $route_id ?>"
                         onclick="handleSeatClick(this)"
                         title="<?= htmlspecialchars($seat['seat_number'] . ': ' . ucfirst($seat['status']) . ($seat['passenger_name'] ? ' – ' . $seat['passenger_name'] : '')) ?>">
                        <div class="type-dot type-<?= $seat['seat_type'] ?>"></div>
                        <span class="seat-num"><?= htmlspecialchars($seat['seat_number']) ?></span>
                        <?php if ($seat['passenger_name']): ?>
                        <span class="seat-name"><?= htmlspecialchars(explode(' ', $seat['passenger_name'])[0]) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </form>
    </div>

    <!-- Seat Details Table (reserved seats management) -->
    <?php
    $reserved_seats = $db->select(
        "SELECT s.seat_id, s.seat_number, s.seat_type
         FROM seats s
         WHERE s.route_id = {$route_id} AND s.status = 'reserved'
         ORDER BY s.seat_number ASC"
    );
    if ($reserved_seats && count($reserved_seats) > 0):
    ?>
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white border-bottom">
            <h6 class="mb-0 fw-bold"><i class="bi bi-lock me-2 text-warning"></i>Reserved Seats (<?= count($reserved_seats) ?>)</h6>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:.84rem;">
                <thead style="background:#fffbeb;">
                    <tr>
                        <th>Seat No.</th>
                        <th>Type</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reserved_seats as $rs): ?>
                    <tr>
                        <td class="fw-bold"><?= htmlspecialchars($rs['seat_number']) ?></td>
                        <td><?= ucfirst($rs['seat_type']) ?></td>
                        <td>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="post_action" value="unreserve">
                                <input type="hidden" name="seat_id"     value="<?= $rs['seat_id'] ?>">
                                <input type="hidden" name="route_id"    value="<?= $route_id ?>">
                                <button type="submit" class="btn btn-outline-warning btn-sm py-0 px-2"
                                        onclick="return confirm('Unreserve this seat?')">
                                    <i class="bi bi-unlock me-1"></i>Unreserve
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php elseif ($route_id && $route): ?>
    <div class="alert alert-info border-0 rounded-3">
        <i class="bi bi-info-circle me-2"></i>No seat records found for this route.
        Seats are created when the route is set up.
    </div>
    <?php elseif (empty($all_routes)): ?>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-calendar-x d-block fs-1 mb-3"></i>
        No upcoming scheduled routes found.
    </div>
    <?php endif; ?>

</main>
</div>

<script>
var selectedSeats = new Set();

function handleSeatClick(el) {
    var status = el.dataset.status;

    if (status === 'booked') return; // Can't interact with booked seats

    if (status === 'reserved') {
        // Single unreserve via form submit
        if (!confirm('Unreserve seat ' + el.querySelector('.seat-num').textContent + '?')) return;
        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML =
            '<input name="post_action" value="unreserve">' +
            '<input name="seat_id"     value="' + el.dataset.seatId + '">' +
            '<input name="route_id"    value="' + el.dataset.route  + '">';
        document.body.appendChild(form);
        form.submit();
        return;
    }

    // Available: toggle selection for bulk reserve
    var sid = el.dataset.seatId;
    if (selectedSeats.has(sid)) {
        selectedSeats.delete(sid);
        el.classList.remove('selected');
    } else {
        selectedSeats.add(sid);
        el.classList.add('selected');
    }
    updateBulkBar();
}

function updateBulkBar() {
    var n   = selectedSeats.size;
    var bar = document.getElementById('bulkBar');
    document.getElementById('selectedCount').textContent = n + ' seat(s) selected';
    document.getElementById('selectedSeatIds').value = Array.from(selectedSeats).join(',');
    if (n > 0) {
        bar.classList.remove('d-none');
        bar.classList.add('d-flex');
    } else {
        bar.classList.add('d-none');
        bar.classList.remove('d-flex');
    }
}

function clearSelection() {
    selectedSeats.clear();
    document.querySelectorAll('.seat.selected').forEach(function (el) {
        el.classList.remove('selected');
    });
    updateBulkBar();
}
</script>

<?php require_once 'inc/footer.php'; ?>

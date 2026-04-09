<?php
// assign-seats.php – Employee: Seat Management

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/Operations.php';

if (!User::isLoggedIn() || $_SESSION['role'] !== 'employee') {
    header('Location: login.php');
    exit();
}

$db   = new Database();
$db->connect();
$conn = $db->getConnection();
$operations = new Operations($db);
$operations->ensureSchema();

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

    if ($success_message !== '' && $route_id_p > 0) {
        $operations->processWaitlist($route_id_p);
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

/* ─── Surface cards ───────────────────────────────────── */
.surface-card {
    background:#fff; border-radius:14px;
    border:1px solid #e5e7eb;
    box-shadow:0 1px 6px rgba(0,0,0,.06);
    overflow:hidden;
}
.surface-card .sc-head {
    padding:.9rem 1.25rem; border-bottom:1px solid #f1f5f9;
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.5rem;
}
.surface-card .sc-head h6 { font-weight:700; margin:0; font-size:.9rem; color:#0f172a; }
.sc-body { padding:1.25rem; }

/* ─── Seat grid ───────────────────────────────────────── */
.seat-container { overflow-x:auto; padding-bottom:.5rem; }
.seat-row-wrap { display:flex; align-items:center; gap:.45rem; margin-bottom:.35rem; }
.row-label { width:24px; text-align:center; font-size:.72rem; font-weight:700; color:#9ca3af; }
.seat {
    width:54px; height:44px; border-radius:10px; font-size:.7rem; font-weight:700;
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    border:2px solid transparent; cursor:pointer; transition:all .15s;
    position:relative; user-select:none;
}
.seat .seat-num  { font-size:.73rem; font-weight:700; line-height:1; }
.seat .seat-name { font-size:.58rem; line-height:1.2; text-align:center; max-width:50px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; margin-top:.1rem; }
.seat-available { background:#dcfce7; border-color:#86efac; color:#15803d; }
.seat-available:hover { background:#bbf7d0; border-color:#4ade80; transform:scale(1.1); box-shadow:0 4px 12px rgba(21,128,61,.2); }
.seat-booked    { background:#fee2e2; border-color:#fca5a5; color:#991b1b; cursor:default; }
.seat-reserved  { background:#fef9c3; border-color:#fde047; color:#78350f; }
.seat-reserved:hover { background:#fde68a; border-color:#facc15; transform:scale(1.1); }
.seat.selected  { outline:3px solid #2563eb; outline-offset:2px; box-shadow:0 0 0 5px rgba(37,99,235,.15); }
/* Type dot */
.type-dot { width:5px; height:5px; border-radius:50%; position:absolute; top:4px; right:4px; }
.type-economy { background:#9ca3af; }
.type-premium { background:#7c3aed; }
.type-luxury  { background:#d97706; }
/* Legend */
.legend-item { display:flex; align-items:center; gap:.4rem; font-size:.78rem; color:#374151; }
.legend-box  { width:20px; height:15px; border-radius:4px; border:2px solid transparent; }
/* Status badges */
.sp { display:inline-block; padding:.22em .72em; border-radius:20px; font-size:.75rem; font-weight:600; white-space:nowrap; }
.sp-available { background:#dcfce7; color:#15803d; }
.sp-booked    { background:#fee2e2; color:#991b1b; }
.sp-reserved  { background:#fef9c3; color:#78350f; }
/* Occupancy bar */
.occ-bar { height:8px; border-radius:4px; background:#e5e7eb; overflow:hidden; }
.occ-fill { height:100%; border-radius:4px; }
/* Bulk bar */
.bulk-bar {
    background:linear-gradient(135deg,#eff6ff,#dbeafe);
    border:1.5px solid #bfdbfe; border-radius:12px;
    padding:.75rem 1.1rem;
    display:flex; align-items:center; gap:.75rem; flex-wrap:wrap;
}
/* Route selector panel */
.route-selector-card {
    background:#fff; border-radius:14px;
    border:1px solid #e5e7eb;
    box-shadow:0 1px 4px rgba(0,0,0,.05);
    padding:1.1rem 1.25rem;
}
.route-selector-card label { font-size:.73rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#374151; }
/* Route info banner */
.route-info-band {
    background:linear-gradient(135deg,#f0fdf4,#dcfce7);
    border:1.5px solid #bbf7d0; border-radius:14px;
    padding:1.1rem 1.4rem;
    display:flex; align-items:center; gap:1.5rem; flex-wrap:wrap;
    margin-bottom:1.25rem;
}
.ri-route { font-size:1.15rem; font-weight:800; color:#0f172a; }
.ri-route .arrow { color:#10b981; margin:0 .4rem; }
.ri-meta { font-size:.78rem; color:#6b7280; margin-top:.2rem; }
.ri-stats { display:flex; gap:.5rem; flex-wrap:wrap; margin-top:.5rem; }
@media(max-width:768px){ .emp-sidebar{display:none;} .emp-content{padding:1rem;} }
</style>

<div class="emp-wrap">
<!-- ─── Sidebar ──────────────────────────────────────── -->
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
        <a href="assign-seats.php" class="active"><i class="bi bi-grid-3x3-gap"></i> Seat Management</a>
        <a href="operations-hub.php"><i class="bi bi-diagram-3"></i> Operations Hub</a>
        <div class="sb-sep">Account</div>
        <a href="profile.php"><i class="bi bi-person-circle"></i> My Profile</a>
        <a href="logout.php" style="color:rgba(252,165,165,.8)!important;"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </nav>
    <div class="emp-sb-footer">Railway Management System</div>
</aside>

<main class="emp-main">
    <!-- Page Header -->
    <div class="emp-page-header">
        <div>
            <p class="ph-title"><i class="bi bi-grid-3x3-gap me-2" style="color:#10b981;"></i>Seat Management</p>
            <p class="ph-sub">Reserve, unreserve, and view seat assignments by route</p>
        </div>
        <a href="employee-dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Dashboard
        </a>
    </div>

    <div class="emp-content">
    <?php if ($success_message): ?>
    <div class="alert alert-success border-0 rounded-3 py-2 mb-3 d-flex align-items-center gap-2">
        <i class="bi bi-check-circle-fill text-success"></i><?= htmlspecialchars($success_message) ?>
    </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
    <div class="alert alert-danger border-0 rounded-3 py-2 mb-3 d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill text-danger"></i><?= htmlspecialchars($error_message) ?>
    </div>
    <?php endif; ?>

    <!-- Route Selector -->
    <div class="route-selector-card mb-4">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-7">
                <label class="form-label mb-1"><i class="bi bi-map me-1"></i>Select Route</label>
                <select name="route_id" class="form-select" onchange="this.form.submit()">
                    <option value="">— Choose a route —</option>
                    <?php foreach ($all_routes as $r): ?>
                    <option value="<?= $r['route_id'] ?>" <?= $route_id === (int)$r['route_id'] ? 'selected':'' ?>>
                        <?= htmlspecialchars($r['train_name']) ?> –
                        <?= htmlspecialchars($r['departure_city']) ?> → <?= htmlspecialchars($r['arrival_city']) ?> –
                        <?= date('d M Y', strtotime($r['journey_date'])) ?>
                        (<?= $r['available_seats'] ?> free)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label mb-1"><i class="bi bi-funnel me-1"></i>Show Seats</label>
                <select name="type" class="form-select" onchange="this.form.submit()">
                    <?php foreach (['all'=>'All Seats','available'=>'Available Only','booked'=>'Booked Only','reserved'=>'Reserved Only'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= $filter_type === $v ? 'selected':'' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <button type="submit" class="btn btn-success w-100"><i class="bi bi-arrow-right me-1"></i>Go</button>
            </div>
        </form>
    </div>

    <?php if ($route && !empty($seat_map)): ?>

    <!-- Route Info Banner with Real-time Availability -->
    <?php $booked_n = $seat_stats['booked'] + $seat_stats['reserved'];
    $occ_pct = $seat_stats['total'] > 0 ? round($booked_n / $seat_stats['total'] * 100) : 0;
    ?>
    <div class="route-info-band mb-4" id="routeBand">
        <div style="flex:1;">
            <div class="ri-route">
                <i class="bi bi-geo-alt-fill me-1" style="color:#10b981;"></i>
                <?= htmlspecialchars($route['departure_city']) ?>
                <span class="arrow">→</span>
                <?= htmlspecialchars($route['arrival_city']) ?>
            </div>
            <div class="ri-meta">
                <i class="bi bi-train-front me-1"></i><?= htmlspecialchars($route['train_name']) ?> &nbsp;&middot;&nbsp;
                <?= date('D, d M Y', strtotime($route['journey_date'])) ?> &nbsp;&middot;&nbsp;
                <?= date('H:i', strtotime($route['departure_time'])) ?> &rarr; <?= date('H:i', strtotime($route['arrival_time'])) ?>
            </div>
            <div class="ri-stats" id="seatStats">
                <span class="sp sp-available"><i class="bi bi-check-circle me-1"></i><span id="cntAvailable"><?= $seat_stats['available'] ?></span> Available</span>
                <span class="sp sp-booked"><i class="bi bi-x-circle me-1"></i><span id="cntBooked"><?= $seat_stats['booked'] ?></span> Booked</span>
                <span class="sp sp-reserved"><i class="bi bi-lock me-1"></i><span id="cntReserved"><?= $seat_stats['reserved'] ?></span> Reserved</span>
                <span id="liveIndicator" style="display:none;" class="badge bg-success ms-2" style="font-size:.7rem;">● LIVE</span>
            </div>
            <!-- Per-class inventory -->
            <div class="d-flex gap-3 mt-2 flex-wrap" id="classBars">
                <?php
                $class_colors = ['economy'=>['#6b7280','#e5e7eb'],'premium'=>['#7c3aed','#ede9fe'],'luxury'=>['#d97706','#fef3c7']];
                foreach (['economy','premium','luxury'] as $cls):
                    $ct = $seat_stats['total'] > 0 ? $db->selectRow("SELECT COUNT(*) AS n FROM seats WHERE route_id={$route_id} AND seat_type='{$cls}'")['n'] ?? 0 : 0;
                    $ca = $ct > 0 ? $db->selectRow("SELECT COUNT(*) AS n FROM seats WHERE route_id={$route_id} AND seat_type='{$cls}' AND status='available'")['n'] ?? 0 : 0;
                    if (!$ct) continue;
                    $cp = $ct > 0 ? round(($ct-$ca)/$ct*100) : 0;
                    [$clr,$bg] = $class_colors[$cls];
                ?>
                <div id="classBar-<?= $cls ?>" style="min-width:120px;">
                    <div style="font-size:.7rem;font-weight:700;color:<?= $clr ?>;text-transform:uppercase;letter-spacing:.06em;"><?= $cls ?></div>
                    <div style="height:6px;background:<?= $bg ?>;border-radius:3px;margin:.2rem 0;overflow:hidden;">
                        <div data-occ="<?= $cp ?>" style="height:100%;width:<?= $cp ?>%;background:<?= $clr ?>;border-radius:3px;transition:width .5s;"></div>
                    </div>
                    <div style="font-size:.68rem;color:#6b7280;"><span data-avail="<?= $cls ?>"><?= $ca ?></span>/<?= $ct ?> free</div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div style="min-width:200px;">
            <div style="font-size:.78rem;font-weight:600;color:#374151;margin-bottom:.4rem;">
                Occupancy — <span id="occPct"><?= $occ_pct ?></span>% (<span id="occNum"><?= $booked_n ?></span> / <?= $seat_stats['total'] ?>)
            </div>
            <div class="occ-bar">
                <div class="occ-fill" id="occBar" style="width:<?= $occ_pct ?>%;background:<?= $occ_pct > 80 ? 'linear-gradient(90deg,#ef4444,#dc2626)' : ($occ_pct > 60 ? 'linear-gradient(90deg,#f59e0b,#d97706)' : 'linear-gradient(90deg,#10b981,#059669)') ?>;"></div>
            </div>
            <div style="font-size:.7rem;color:#6b7280;margin-top:.35rem;">
                <a href="route-details-emp.php?id=<?= $route_id ?>" class="text-success text-decoration-none">
                    <i class="bi bi-map me-1"></i>View Route Details
                </a>
                &nbsp;·&nbsp;
                <span id="lastUpdated" style="color:#9ca3af;">Live</span>
            </div>
        </div>
    </div>

    <!-- Seat Map -->
    <div class="surface-card mb-4">
        <div class="sc-head">
            <h6><i class="bi bi-grid-3x3-gap me-2"></i>Seat Map</h6>
            <!-- Legend -->
            <div class="d-flex gap-3 flex-wrap">
                <div class="legend-item">
                    <div class="legend-box" style="background:#dcfce7;border-color:#86efac;"></div> Available
                </div>
                <div class="legend-item">
                    <div class="legend-box" style="background:#fee2e2;border-color:#fca5a5;"></div> Booked
                </div>
                <div class="legend-item">
                    <div class="legend-box" style="background:#fef9c3;border-color:#fde047;"></div> Reserved
                </div>
                <div class="legend-item">
                    <span class="type-dot type-economy d-inline-block" style="position:relative;"></span> Economy
                    <span class="type-dot type-premium d-inline-block ms-2" style="position:relative;"></span> Premium
                    <span class="type-dot type-luxury d-inline-block ms-2" style="position:relative;"></span> Luxury
                </div>
            </div>
        </div>
        <div class="sc-body">
        <!-- Bulk reserve form -->
        <form method="POST" id="bulkForm">
            <input type="hidden" name="post_action" value="bulk_reserve">
            <input type="hidden" name="route_id"    value="<?= $route_id ?>">
            <input type="hidden" name="seat_ids"    id="selectedSeatIds">

            <div id="bulkBar" class="bulk-bar d-none mb-3">
                <i class="bi bi-cursor-fill text-primary"></i>
                <span id="selectedCount" class="fw-semibold small text-primary">0 seats selected</span>
                <button type="submit" class="btn btn-warning btn-sm" onclick="return confirm('Reserve selected seats?')">
                    <i class="bi bi-lock me-1"></i>Reserve Selected
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearSelection()">
                    <i class="bi bi-x me-1"></i>Clear
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
        </form>
        </div><!-- /sc-body -->
    </div><!-- /surface-card seat map -->

    <!-- Reserved Seats Management Table -->
    <?php
    $reserved_seats = $db->select(
        "SELECT s.seat_id, s.seat_number, s.seat_type
         FROM seats s
         WHERE s.route_id = {$route_id} AND s.status = 'reserved'
         ORDER BY s.seat_number ASC"
    );
    if ($reserved_seats && count($reserved_seats) > 0):
    ?>
    <div class="surface-card">
        <div class="sc-head">
            <h6><i class="bi bi-lock me-2" style="color:#d97706;"></i>Reserved Seats (<?= count($reserved_seats) ?>)</h6>
            <span style="font-size:.75rem;color:#6b7280;">Click Unreserve to free a seat</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:.84rem;">
                <thead style="background:#fffbeb;">
                    <tr>
                        <th style="padding:.75rem;">Seat No.</th>
                        <th style="padding:.75rem;">Type</th>
                        <th style="padding:.75rem;">Action</th>
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
    <div class="alert alert-info border-0 rounded-3 d-flex align-items-center gap-2">
        <i class="bi bi-info-circle-fill text-info"></i>
        No seat records found for this route. Seats are created when the route is set up.
    </div>
    <?php elseif (empty($all_routes)): ?>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-calendar-x d-block fs-1 mb-3 opacity-25"></i>
        <strong>No upcoming scheduled routes found.</strong><br>
        <small>Routes must be scheduled and active to appear here.</small>
    </div>
    <?php endif; ?>

    </div><!-- /emp-content -->
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

<!-- Real-time seat availability polling -->
<script>
(function () {
    var routeId = <?= (int)$route_id ?>;
    if (!routeId) return;

    var pollInterval = 15000; // 15 seconds
    var liveEl = document.getElementById('liveIndicator');
    var lastUpdEl = document.getElementById('lastUpdated');

    function setColor(pct) {
        if (pct > 80) return 'linear-gradient(90deg,#ef4444,#dc2626)';
        if (pct > 60) return 'linear-gradient(90deg,#f59e0b,#d97706)';
        return 'linear-gradient(90deg,#10b981,#059669)';
    }

    function refreshSeats() {
        fetch('api/seat-availability.php?route_id=' + routeId, { credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) return null;
                return r.json();
            })
            .then(function (data) {
                if (!data || data.error) return;

                // Update counter spans
                var avEl = document.getElementById('cntAvailable');
                var bkEl = document.getElementById('cntBooked');
                var rsEl = document.getElementById('cntReserved');
                if (avEl) avEl.textContent = data.stats.available;
                if (bkEl) bkEl.textContent = data.stats.booked;
                if (rsEl) rsEl.textContent = data.stats.reserved;

                // Update occupancy bar
                var booked_n = data.stats.booked + data.stats.reserved;
                var total = data.stats.total;
                var pct = total > 0 ? Math.round(booked_n / total * 100) : 0;
                var occBar = document.getElementById('occBar');
                var occPct = document.getElementById('occPct');
                var occNum = document.getElementById('occNum');
                if (occBar) { occBar.style.width = pct + '%'; occBar.style.background = setColor(pct); }
                if (occPct) occPct.textContent = pct;
                if (occNum) occNum.textContent = booked_n;

                // Update per-class inventory bars
                var classes = ['economy', 'premium', 'luxury'];
                classes.forEach(function (cls) {
                    var info = data.by_class[cls];
                    if (!info) return;
                    var bar = document.querySelector('#classBar-' + cls);
                    if (!bar) return;
                    var clsPct = info.total > 0 ? Math.round((info.total - info.available) / info.total * 100) : 0;
                    var fill = bar.querySelector('[data-occ]');
                    if (fill) { fill.style.width = clsPct + '%'; fill.dataset.occ = clsPct; }
                    var availSpan = bar.querySelector('[data-avail="' + cls + '"]');
                    if (availSpan) availSpan.textContent = info.available;
                });

                // Update individual seat tile statuses (without disrupting selection)
                if (data.seats && data.seats.length) {
                    data.seats.forEach(function (s) {
                        var tile = document.querySelector('.seat[data-seat-id="' + s.seat_id + '"]');
                        if (!tile) return;
                        var oldStatus = tile.dataset.status;
                        if (oldStatus === s.status) return; // no change
                        // Preserve selected class
                        var wasSelected = tile.classList.contains('selected');
                        tile.classList.remove('seat-available', 'seat-booked', 'seat-reserved', 'seat-maintenance');
                        tile.classList.add('seat-' + s.status);
                        tile.dataset.status = s.status;
                        if (wasSelected && s.status !== 'available') {
                            tile.classList.remove('selected');
                            selectedSeats.delete(parseInt(s.seat_id));
                            updateBulkBar();
                        }
                        // Update tooltip
                        var label = s.seat_number + ': ' + s.status.charAt(0).toUpperCase() + s.status.slice(1);
                        if (s.passenger_name) label += ' \u2013 ' + s.passenger_name;
                        tile.title = label;
                        // Update passenger name inside tile
                        var nameEl = tile.querySelector('.seat-name');
                        if (s.passenger_name) {
                            if (!nameEl) {
                                nameEl = document.createElement('span');
                                nameEl.className = 'seat-name';
                                tile.appendChild(nameEl);
                            }
                            nameEl.textContent = s.passenger_name.split(' ')[0];
                        } else if (nameEl) {
                            nameEl.remove();
                        }
                    });
                }

                // Show live badge and timestamp
                if (liveEl) liveEl.style.display = '';
                if (lastUpdEl) lastUpdEl.textContent = 'Updated ' + new Date().toLocaleTimeString();
            })
            .catch(function () {
                // silently ignore network errors; badge fades if offline
                if (liveEl) liveEl.style.display = 'none';
            });
    }

    // Kick off polling
    setInterval(refreshSeats, pollInterval);
    // Show live badge immediately to indicate polling is active
    if (liveEl) liveEl.style.display = '';
}());
</script>

<?php require_once 'inc/footer.php'; ?>

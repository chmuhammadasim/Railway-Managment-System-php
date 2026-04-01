<?php
// employee-dashboard.php – Employee Dashboard

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';

if (!User::isLoggedIn() || $_SESSION['role'] !== 'employee') {
    header('Location: login.php');
    exit();
}

$db = new Database();
$db->connect();

$user_id  = $_SESSION['user_id'];
$user_obj = new User($db);
$user     = $user_obj->getUserById($user_id);

// ── KPIs ──────────────────────────────────────────────────────────────────────
$kpi = $db->selectRow(
    "SELECT
        (SELECT COUNT(*) FROM routes
            WHERE DATE(journey_date) = CURDATE()) AS today_routes,
        (SELECT COUNT(*) FROM bookings b
            JOIN routes r ON b.route_id = r.route_id
            WHERE DATE(r.journey_date) = CURDATE()
            AND b.booking_status = 'confirmed') AS today_confirmed,
        (SELECT COUNT(*) FROM bookings
            WHERE booking_status = 'pending') AS pending_bookings,
        (SELECT COUNT(*) FROM trains WHERE status = 'active') AS active_trains,
        (SELECT COALESCE(SUM(b.number_of_seats), 0) FROM bookings b
            JOIN routes r ON b.route_id = r.route_id
            WHERE DATE(r.journey_date) = CURDATE()
            AND b.booking_status = 'confirmed') AS today_passengers,
        (SELECT COUNT(*) FROM routes
            WHERE journey_date > CURDATE() AND status = 'scheduled') AS upcoming_routes"
);

// ── Today's schedule ──────────────────────────────────────────────────────────
$today_routes = $db->select(
    "SELECT r.*, t.train_name, t.train_number, t.train_type,
            (SELECT COUNT(*) FROM bookings b
             WHERE b.route_id = r.route_id AND b.booking_status IN ('confirmed','pending')) AS total_bookings,
            (SELECT COUNT(*) FROM bookings b
             WHERE b.route_id = r.route_id AND b.booking_status = 'confirmed') AS confirmed_bookings
     FROM routes r
     JOIN trains t ON r.train_id = t.train_id
     WHERE DATE(r.journey_date) = CURDATE()
     ORDER BY r.departure_time ASC"
);
if (!$today_routes) $today_routes = [];

// ── Recent bookings today ─────────────────────────────────────────────────────
$recent_bookings = $db->select(
    "SELECT b.*, u.full_name, u.phone,
            r.departure_city, r.arrival_city, r.departure_time,
            t.train_name
     FROM bookings b
     JOIN users  u ON b.user_id   = u.user_id
     JOIN routes r ON b.route_id  = r.route_id
     JOIN trains t ON r.train_id  = t.train_id
     WHERE DATE(r.journey_date) = CURDATE()
     ORDER BY b.booking_date DESC
     LIMIT 12"
);
if (!$recent_bookings) $recent_bookings = [];

// ── Upcoming routes (next 7 days) ─────────────────────────────────────────────
$upcoming = $db->select(
    "SELECT r.*, t.train_name,
            (SELECT COUNT(*) FROM bookings b
             WHERE b.route_id = r.route_id AND b.booking_status IN ('confirmed','pending')) AS total_bookings
     FROM routes r
     JOIN trains t ON r.train_id = t.train_id
     WHERE r.journey_date BETWEEN CURDATE() + INTERVAL 1 DAY AND CURDATE() + INTERVAL 7 DAY
     AND r.status = 'scheduled'
     ORDER BY r.journey_date ASC, r.departure_time ASC
     LIMIT 8"
);
if (!$upcoming) $upcoming = [];

$hour     = (int)date('H');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');

$hideMainNavbar = true;
$pageTitle = 'Employee Dashboard – Railway System';
require_once 'inc/header.php';
?>

<style>
/* ── Sidebar ── */
.emp-wrap { display:flex; min-height:calc(100vh - 60px); }
.emp-sidebar {
    width:230px; flex-shrink:0;
    background:linear-gradient(180deg,#064e3b 0%,#065f46 100%);
    padding:1.5rem 0; position:sticky; top:60px;
    height:calc(100vh - 60px); overflow-y:auto;
}
.emp-sidebar .sb-brand {
    padding:.5rem 1.25rem 1.25rem; font-weight:800; font-size:1.05rem; color:#d1fae5;
    border-bottom:1px solid rgba(255,255,255,.15); margin-bottom:.75rem;
}
.emp-sidebar a {
    display:flex; align-items:center; gap:.65rem; padding:.55rem 1.25rem;
    color:rgba(255,255,255,.82); text-decoration:none; font-size:.88rem;
    transition:background .2s,color .2s;
}
.emp-sidebar a:hover,
.emp-sidebar a.active { background:rgba(255,255,255,.16); color:#fff; }
.emp-sidebar .sb-section {
    font-size:.7rem; text-transform:uppercase; letter-spacing:.08em;
    color:rgba(255,255,255,.42); padding:.9rem 1.25rem .3rem; font-weight:700;
}
.emp-main { flex:1; padding:1.75rem; overflow:hidden; }

/* ── KPI cards ── */
.kpi-card { border-radius:12px; border:none; box-shadow:0 2px 8px rgba(0,0,0,.07); }
.kpi-icon { width:46px; height:46px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; }

/* ── Status pills ── */
.sp { display:inline-block; padding:.25em .7em; border-radius:20px; font-size:.77rem; font-weight:600; }
.sp-scheduled { background:#d1fae5; color:#065f46; }
.sp-confirmed { background:#d1fae5; color:#065f46; }
.sp-pending   { background:#fef3c7; color:#92400e; }
.sp-cancelled { background:#fee2e2; color:#7f1d1d; }
.sp-completed { background:#e0e7ff; color:#3730a3; }
.sp-delayed   { background:#ffedd5; color:#9a3412; }

/* ── Today strip ── */
.today-strip {
    background:linear-gradient(135deg,#064e3b,#059669);
    border-radius:14px; color:#fff; padding:1.2rem 1.5rem;
}

/* ── Tables ── */
.emp-table thead th { background:#064e3b; color:#fff; font-weight:600; font-size:.82rem; white-space:nowrap; }
.emp-table tbody tr:hover { background:#f0fdf4; }
.emp-table td { font-size:.84rem; vertical-align:middle; }

/* ── Occupancy bar ── */
.occ-bar { height:6px; border-radius:4px; background:#e5e7eb; overflow:hidden; }
.occ-fill { height:100%; border-radius:4px;
    background:linear-gradient(90deg,#10b981,#059669); transition:width .4s; }

/* ── Quick action cards ── */
.qa-card {
    border:none; border-radius:14px; box-shadow:0 2px 10px rgba(0,0,0,.08);
    transition:transform .18s,box-shadow .18s; cursor:pointer; text-decoration:none;
    display:block; color:inherit;
}
.qa-card:hover { transform:translateY(-3px); box-shadow:0 6px 18px rgba(0,0,0,.14); color:inherit; }

@media (max-width:768px) {
    .emp-sidebar { display:none; position:fixed; z-index:1050; }
    .emp-sidebar.open { display:flex !important; flex-direction:column; }
    .emp-main    { padding:1rem; }
}
.emp-mobile-bar {
    display:none; background:#064e3b; color:#fff;
    padding:.6rem 1rem; align-items:center; gap:.75rem;
    position:sticky; top:0; z-index:100;
}
@media (max-width:768px) { .emp-mobile-bar { display:flex; } }
</style>

<div class="emp-mobile-bar">
    <button id="empSidebarToggle" class="btn btn-sm btn-outline-light" style="border-color:rgba(255,255,255,.4);">
        <i class="bi bi-list"></i>
    </button>
    <span class="fw-bold"><i class="bi bi-train-front-fill me-1"></i>Employee Panel</span>
</div>

<div class="emp-wrap">

<!-- ── Sidebar ── -->
<aside class="emp-sidebar" id="empSidebar">
    <div class="sb-brand"><i class="bi bi-train-front-fill me-2"></i>Employee Panel</div>
    <div class="sb-section">Main</div>
    <a href="employee-dashboard.php" class="active"><i class="bi bi-speedometer2"></i>Dashboard</a>
    <div class="sb-section">Operations</div>
    <a href="my-trains.php"><i class="bi bi-train-front"></i>My Trains</a>
    <a href="check-passengers.php"><i class="bi bi-people"></i>Passengers</a>
    <a href="assign-seats.php"><i class="bi bi-grid-3x3-gap"></i>Seat Management</a>
    <div class="sb-section">Bookings</div>
    <a href="check-passengers.php?view=bookings"><i class="bi bi-journal-check"></i>Today's Bookings</a>
    <div class="sb-section">Account</div>
    <a href="notifications.php" style="justify-content:space-between;">
        <span><i class="bi bi-bell"></i>Notifications</span>
        <?php if (($kpi['pending_bookings'] ?? 0) > 0): ?>
        <span style="background:#ef4444;color:#fff;border-radius:999px;padding:.1em .5em;font-size:.68rem;font-weight:700;"><?= (int)$kpi['pending_bookings'] ?></span>
        <?php endif; ?>
    </a>
    <a href="profile.php"><i class="bi bi-person-circle"></i>My Profile</a>
    <a href="logout.php"><i class="bi bi-box-arrow-right"></i>Logout</a>
</aside>

<!-- ── Main Content ── -->
<main class="emp-main">

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-3">
        <div>
            <h4 class="fw-bold mb-0">
                <?= $greeting ?>, <?= htmlspecialchars($user['full_name'] ?? 'Employee') ?>! 👋
            </h4>
            <small class="text-muted">
                <i class="bi bi-calendar3 me-1"></i><?= date('l, d F Y') ?>
                &nbsp;·&nbsp;
                <i class="bi bi-clock me-1"></i><?= date('H:i') ?> PKT
            </small>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="check-passengers.php" class="btn btn-outline-success btn-sm">
                <i class="bi bi-people me-1"></i>Passengers
            </a>
            <a href="assign-seats.php" class="btn btn-success btn-sm">
                <i class="bi bi-grid-3x3-gap me-1"></i>Seat Management
            </a>
        </div>
    </div>

    <!-- Pending bookings alert banner -->
    <?php if (($kpi['pending_bookings'] ?? 0) > 0): ?>
    <div class="d-flex align-items-center gap-3 mb-3 p-3 rounded-3" style="background:#fef3c7;border:1px solid #fde68a;">
        <i class="bi bi-hourglass-split fs-5" style="color:#d97706;"></i>
        <div class="flex-grow-1">
            <span class="fw-semibold" style="color:#92400e;"><?= (int)$kpi['pending_bookings'] ?> pending booking<?= $kpi['pending_bookings'] != 1 ? 's' : '' ?> awaiting action.</span>
            <span class="text-muted small ms-1">Review and confirm or cancel them to keep the schedule accurate.</span>
        </div>
        <a href="check-passengers.php?view=bookings&status=pending" class="btn btn-sm btn-warning fw-semibold">Review Now</a>
    </div>
    <?php endif; ?>

    <!-- Quick Passenger Search -->
    <div class="mb-4">
        <form method="GET" action="check-passengers.php" class="d-flex gap-2">
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Search passenger by name, phone or booking ref…" style="max-width:360px;">
            <button type="submit" class="btn btn-success btn-sm px-3"><i class="bi bi-search me-1"></i>Search</button>
        </form>
    </div>

    <!-- Today Strip -->
    <div class="today-strip mb-4">
        <div class="row g-3 text-center">
            <?php foreach ([
                [$kpi['today_routes']     ?? 0, "Today's Routes",   'bi-map'],
                [$kpi['today_confirmed']  ?? 0, 'Confirmed Today',  'bi-check-circle'],
                [$kpi['today_passengers'] ?? 0, 'Passengers Today', 'bi-people-fill'],
                [$kpi['pending_bookings'] ?? 0, 'Pending Bookings', 'bi-hourglass-split'],
            ] as [$v, $l, $ic]): ?>
            <div class="col-6 col-md-3">
                <div class="fs-2 fw-bold"><?= number_format((int)$v) ?></div>
                <div style="font-size:.8rem; opacity:.85;"><i class="bi <?= $ic ?> me-1"></i><?= $l ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="row g-3 mb-4">
        <?php foreach ([
            ['Active Trains',    $kpi['active_trains']   ?? 0, 'bi-train-front',    'bg-success text-white', '#f0fdf4'],
            ['Upcoming Routes',  $kpi['upcoming_routes'] ?? 0, 'bi-calendar-event', 'bg-primary text-white', '#eff6ff'],
        ] as [$lbl, $val, $icon, $ibg, $bg]): ?>
        <div class="col-6">
            <div class="kpi-card card p-3" style="background:<?= $bg ?>;">
                <div class="d-flex align-items-center gap-3">
                    <div class="kpi-icon <?= $ibg ?>"><i class="bi <?= $icon ?>"></i></div>
                    <div>
                        <div class="fw-bold fs-4"><?= number_format((int)$val) ?></div>
                        <div class="text-muted small"><?= $lbl ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Quick Actions -->
    <div class="row g-3 mb-4">
        <?php foreach ([
            ['my-trains.php',        'bi-train-front',      '#064e3b', 'My Trains',         'View & manage trains'],
            ['check-passengers.php', 'bi-people-fill',      '#1d4ed8', 'Passenger Manifest', 'View today\'s passengers'],
            ['assign-seats.php',     'bi-grid-3x3-gap-fill','#7c3aed', 'Seat Management',   'Assign & manage seats'],
            ['route-details-emp.php?id='.($today_routes[0]['route_id'] ?? 0),'bi-map-fill','#0f766e','Route Details','First route of today'],
        ] as [$href, $icon, $color, $title, $sub]): ?>
        <div class="col-6 col-md-3">
            <a href="<?= $href ?>" class="qa-card card p-3 h-100 text-center">
                <div class="mb-2" style="font-size:2.2rem; color:<?= $color ?>;">
                    <i class="bi <?= $icon ?>"></i>
                </div>
                <div class="fw-bold small"><?= $title ?></div>
                <div class="text-muted" style="font-size:.72rem;"><?= $sub ?></div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4">
        <!-- Today's Schedule -->
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-calendar-day me-2 text-success"></i>Today's Schedule</h6>
                    <span class="badge bg-success"><?= date('d M Y') ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 emp-table">
                        <thead>
                            <tr>
                                <th>Train</th>
                                <th>Route</th>
                                <th>Departure</th>
                                <th>Arrival</th>
                                <th>Occupancy</th>
                                <th>Route Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($today_routes)): ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">
                                <i class="bi bi-calendar-x d-block fs-3 mb-2"></i>No routes scheduled for today.
                            </td></tr>
                        <?php else: ?>
                            <?php foreach ($today_routes as $r): ?>
                            <?php
                            $occ_pct = $r['available_seats'] > 0
                                ? round((1 - $r['available_seats'] / max(1, $r['available_seats'] + $r['confirmed_bookings'])) * 100)
                                : ($r['confirmed_bookings'] > 0 ? 100 : 0);
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold small"><?= htmlspecialchars($r['train_name']) ?></div>
                                    <div class="text-muted" style="font-size:.72rem;"><?= htmlspecialchars($r['train_number']) ?></div>
                                </td>
                                <td class="small">
                                    <?= htmlspecialchars($r['departure_city']) ?>
                                    <i class="bi bi-arrow-right text-muted mx-1"></i>
                                    <?= htmlspecialchars($r['arrival_city']) ?>
                                </td>
                                <td class="small fw-semibold"><?= date('H:i', strtotime($r['departure_time'])) ?></td>
                                <td class="small"><?= date('H:i', strtotime($r['arrival_time'])) ?></td>
                                <td style="min-width:120px;">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="occ-bar flex-grow-1">
                                            <div class="occ-fill" style="width:<?= $occ_pct ?>%;
                                                background:<?= $occ_pct > 80 ? 'linear-gradient(90deg,#ef4444,#dc2626)' : 'linear-gradient(90deg,#10b981,#059669)' ?>;"></div>
                                        </div>
                                        <span style="font-size:.75rem; color:#6b7280;"><?= $r['available_seats'] ?> free</span>
                                    </div>
                                </td>
                                <td>
                                    <select class="form-select form-select-sm status-select"
                                            data-route-id="<?= $r['route_id'] ?>"
                                            style="width:auto; font-size:.78rem;">
                                        <?php foreach (['scheduled','cancelled','completed'] as $st): ?>
                                        <option value="<?= $st ?>" <?= $r['status'] === $st ? 'selected' : '' ?>>
                                            <?= ucfirst($st) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <a href="route-details-emp.php?id=<?= $r['route_id'] ?>"
                                           class="btn btn-outline-success btn-sm py-0 px-2" title="View Details">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="check-passengers.php?route_id=<?= $r['route_id'] ?>"
                                           class="btn btn-outline-primary btn-sm py-0 px-2" title="Passengers">
                                            <i class="bi bi-people"></i>
                                        </a>
                                        <a href="assign-seats.php?route_id=<?= $r['route_id'] ?>"
                                           class="btn btn-outline-secondary btn-sm py-0 px-2" title="Seats">
                                            <i class="bi bi-grid-3x3-gap"></i>
                                        </a>
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

        <!-- Recent Bookings -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-journal-text me-2 text-primary"></i>Today's Bookings</h6>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 emp-table">
                        <thead>
                            <tr>
                                <th>Ref</th>
                                <th>Passenger</th>
                                <th>Route</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($recent_bookings)): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted">No bookings today.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recent_bookings as $bk): ?>
                            <tr>
                                <td>
                                    <a href="booking-emp.php?id=<?= $bk['booking_id'] ?>"
                                       class="fw-bold text-primary text-decoration-none small">
                                        <?= htmlspecialchars($bk['booking_reference']) ?>
                                    </a>
                                </td>
                                <td>
                                    <div class="small fw-semibold"><?= htmlspecialchars($bk['full_name']) ?></div>
                                    <div class="text-muted" style="font-size:.72rem;"><?= htmlspecialchars($bk['phone'] ?? '') ?></div>
                                </td>
                                <td class="small text-nowrap">
                                    <?= htmlspecialchars($bk['departure_city']) ?>
                                    <i class="bi bi-arrow-right text-muted mx-1"></i>
                                    <?= htmlspecialchars($bk['arrival_city']) ?>
                                </td>
                                <td><span class="sp sp-<?= $bk['booking_status'] ?>"><?= ucfirst($bk['booking_status']) ?></span></td>
                                <td>
                                    <a href="booking-emp.php?id=<?= $bk['booking_id'] ?>"
                                       class="btn btn-outline-primary btn-sm py-0 px-2">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Upcoming Routes -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-calendar-week me-2 text-warning"></i>Upcoming (7 Days)</h6>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($upcoming)): ?>
                    <div class="text-center py-4 text-muted small">No upcoming routes.</div>
                    <?php else: ?>
                    <?php foreach ($upcoming as $u): ?>
                    <a href="route-details-emp.php?id=<?= $u['route_id'] ?>"
                       class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom text-decoration-none text-dark"
                       style="transition:background .15s;" onmouseover="this.style.background='#f0fdf4'" onmouseout="this.style.background=''">
                        <div>
                            <div class="small fw-semibold"><?= htmlspecialchars($u['departure_city']) ?> → <?= htmlspecialchars($u['arrival_city']) ?></div>
                            <div class="text-muted" style="font-size:.72rem;">
                                <?= htmlspecialchars($u['train_name']) ?> &nbsp;·&nbsp;
                                <?= date('d M', strtotime($u['journey_date'])) ?>
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-success bg-opacity-10 text-success" style="font-size:.72rem;">
                                <?= (int)$u['total_bookings'] ?> booked
                            </span>
                        </div>
                    </a>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</main>
</div><!-- /emp-wrap -->

<!-- Mobile sidebar overlay -->
<div id="empOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1040;" onclick="closeSidebar()"></div>

<script src="public/js/employee-status.js" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Mobile sidebar toggle
    var toggle  = document.getElementById('empSidebarToggle');
    var sidebar = document.getElementById('empSidebar');
    var overlay = document.getElementById('empOverlay');

    function openSidebar() {
        sidebar.classList.add('open');
        overlay.style.display = 'block';
        document.body.style.overflow = 'hidden';
    }

    window.closeSidebar = function () {
        sidebar.classList.remove('open');
        overlay.style.display = 'none';
        document.body.style.overflow = '';
    };

    if (toggle) toggle.addEventListener('click', openSidebar);

    // Status-select toast feedback
    document.querySelectorAll('.status-select').forEach(function (sel) {
        sel.addEventListener('change', function () {
            var orig = this.dataset.original || this.value;
            this.dataset.original = orig;
        });
    });
});
</script>

<?php require_once 'inc/footer.php'; ?>

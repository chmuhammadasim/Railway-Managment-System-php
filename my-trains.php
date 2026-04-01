<?php
// my-trains.php – Employee: Train Overview

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';

if (!User::isLoggedIn() || $_SESSION['role'] !== 'employee') {
    header('Location: login.php');
    exit();
}

$db = new Database();
$db->connect();

// ── Filters ───────────────────────────────────────────────────────────────────
$filter_status = $_GET['status'] ?? 'all';
$search        = trim($_GET['q'] ?? '');
$conn          = $db->getConnection();

// ── Status update (inline) ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['train_status'])) {
    $tid = (int)($_POST['train_id'] ?? 0);
    $st  = in_array($_POST['train_status'], ['active', 'inactive', 'maintenance'])
           ? $_POST['train_status'] : null;
    if ($tid > 0 && $st) {
        $db->query("UPDATE trains SET status = '{$st}' WHERE train_id = {$tid}");
    }
    header('Location: my-trains.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit();
}

// ── Build WHERE ───────────────────────────────────────────────────────────────
$wheres = [];
if ($filter_status !== 'all') {
    $fs = $conn->real_escape_string($filter_status);
    $wheres[] = "t.status = '{$fs}'";
}
if ($search !== '') {
    $sq = $conn->real_escape_string($search);
    $wheres[] = "(t.train_name LIKE '%{$sq}%' OR t.train_number LIKE '%{$sq}%' OR t.train_type LIKE '%{$sq}%')";
}
$where_sql = $wheres ? 'WHERE ' . implode(' AND ', $wheres) : '';

// ── Trains with route / booking stats ────────────────────────────────────────
$trains = $db->select(
    "SELECT t.*,
            (SELECT COUNT(*) FROM routes r WHERE r.train_id = t.train_id) AS total_routes,
            (SELECT COUNT(*) FROM routes r WHERE r.train_id = t.train_id AND DATE(r.journey_date) = CURDATE()) AS today_routes,
            (SELECT COUNT(*) FROM routes r WHERE r.train_id = t.train_id AND r.journey_date > CURDATE() AND r.status = 'scheduled') AS upcoming_routes,
            (SELECT COUNT(*) FROM bookings b JOIN routes r ON b.route_id = r.route_id WHERE r.train_id = t.train_id AND b.booking_status = 'confirmed') AS total_confirmed,
            (SELECT COUNT(*) FROM bookings b JOIN routes r ON b.route_id = r.route_id WHERE r.train_id = t.train_id AND DATE(r.journey_date) = CURDATE() AND b.booking_status = 'confirmed') AS today_confirmed
     FROM trains t
     {$where_sql}
     ORDER BY t.status DESC, t.train_name ASC"
);
if (!$trains) $trains = [];

// KPI
$kpi = $db->selectRow(
    "SELECT
        COUNT(*)                              AS total,
        SUM(status = 'active')               AS active,
        SUM(status = 'inactive')             AS inactive,
        SUM(status = 'maintenance')          AS maintenance
     FROM trains"
);

$hideMainNavbar = true;
$pageTitle = 'My Trains – Employee Panel';
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

.kpi-card { border-radius:12px; border:none; box-shadow:0 2px 8px rgba(0,0,0,.07); }
.kpi-icon { width:46px; height:46px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; }
.sp { display:inline-block; padding:.25em .7em; border-radius:20px; font-size:.77rem; font-weight:600; white-space:nowrap; }
.sp-active      { background:#d1fae5; color:#065f46; }
.sp-inactive    { background:#f1f5f9; color:#475569; }
.sp-maintenance { background:#ffedd5; color:#9a3412; }
.sp-scheduled   { background:#dbeafe; color:#1d4ed8; }

/* Train card grid */
.train-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:1.25rem; }
.train-card { border-radius:14px; border:none; box-shadow:0 2px 10px rgba(0,0,0,.08); overflow:hidden; }
.train-card-top { padding:1.1rem 1.25rem 0; }
.train-card-mid { padding:.75rem 1.25rem; background:#fafafa; border-top:1px solid #f1f5f9; }
.train-stat { text-align:center; }
.train-stat strong { display:block; font-size:1.25rem; font-weight:800; }
.train-stat small  { font-size:.72rem; color:#9ca3af; }
.occ-bar { height:6px; border-radius:4px; background:#e5e7eb; overflow:hidden; margin-top:.3rem; }
.occ-fill { height:100%; border-radius:4px; background:linear-gradient(90deg,#10b981,#059669); }

/* Upcoming routes inside card */
.route-chip {
    display:inline-flex; align-items:center; gap:.3rem;
    background:#f0fdf4; color:#065f46; border-radius:6px;
    padding:.25rem .6rem; font-size:.74rem; margin:.15rem;
}

@media (max-width:768px) { .emp-sidebar { display:none; } .emp-main { padding:1rem; } }
</style>

<div class="emp-wrap">
<!-- Sidebar -->
<aside class="emp-sidebar">
    <div class="sb-brand"><i class="bi bi-train-front-fill me-2"></i>Employee Panel</div>
    <div class="sb-section">Main</div>
    <a href="employee-dashboard.php"><i class="bi bi-speedometer2"></i>Dashboard</a>
    <div class="sb-section">Operations</div>
    <a href="my-trains.php" class="active"><i class="bi bi-train-front"></i>My Trains</a>
    <a href="check-passengers.php"><i class="bi bi-people"></i>Passengers</a>
    <a href="assign-seats.php"><i class="bi bi-grid-3x3-gap"></i>Seat Management</a>
    <div class="sb-section">Bookings</div>
    <a href="check-passengers.php?view=bookings"><i class="bi bi-journal-check"></i>Today's Bookings</a>
    <div class="sb-section">Account</div>
    <a href="profile.php"><i class="bi bi-person-circle"></i>My Profile</a>
    <a href="logout.php"><i class="bi bi-box-arrow-right"></i>Logout</a>
</aside>

<main class="emp-main">

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-0"><i class="bi bi-train-front me-2 text-success"></i>My Trains</h4>
            <small class="text-muted"><?= count($trains) ?> train(s) found</small>
        </div>
        <a href="employee-dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Dashboard
        </a>
    </div>

    <!-- KPI strip -->
    <div class="row g-3 mb-4">
        <?php foreach ([
            ['Total Trains',   $kpi['total']       ?? 0, 'bi-train-front',   'bg-primary text-white', '#eff6ff'],
            ['Active',         $kpi['active']       ?? 0, 'bi-check-circle',  'bg-success text-white', '#f0fdf4'],
            ['Inactive',       $kpi['inactive']     ?? 0, 'bi-dash-circle',   'bg-secondary text-white','#f8fafc'],
            ['Maintenance',    $kpi['maintenance']  ?? 0, 'bi-tools',         'bg-warning text-dark',  '#fffbeb'],
        ] as [$lbl, $val, $icon, $ibg, $bg]): ?>
        <div class="col-6 col-md-3">
            <div class="kpi-card card p-3" style="background:<?= $bg ?>;">
                <div class="d-flex align-items-center gap-3">
                    <div class="kpi-icon <?= $ibg ?>"><i class="bi <?= $icon ?>"></i></div>
                    <div>
                        <div class="fw-bold fs-4"><?= (int)$val ?></div>
                        <div class="text-muted small"><?= $lbl ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filter bar -->
    <form method="GET" class="card border-0 shadow-sm p-3 mb-4">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-sm-5">
                <label class="form-label mb-1 small fw-semibold">Search</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" class="form-control"
                           placeholder="Name / Number / Type"
                           value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="col-6 col-sm-3">
                <label class="form-label mb-1 small fw-semibold">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <?php foreach (['all'=>'All Statuses','active'=>'Active','inactive'=>'Inactive','maintenance'=>'Maintenance'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= $filter_status === $v ? 'selected':'' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-sm-2 d-flex gap-1">
                <button type="submit" class="btn btn-success btn-sm flex-fill">
                    <i class="bi bi-funnel"></i> Filter
                </button>
                <a href="my-trains.php" class="btn btn-outline-secondary btn-sm" title="Reset">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </div>
    </form>

    <!-- Train cards grid -->
    <?php if (empty($trains)): ?>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-train-front d-block fs-1 mb-3"></i>
        No trains found matching your criteria.
    </div>
    <?php else: ?>
    <div class="train-grid">
        <?php foreach ($trains as $t): ?>
        <?php
        $occ_pct = $t['total_seats'] > 0
            ? round(($t['total_seats'] - $t['available_seats']) / $t['total_seats'] * 100) : 0;
        ?>
        <div class="train-card card">
            <div class="train-card-top">
                <!-- Top row -->
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <h6 class="fw-bold mb-0"><?= htmlspecialchars($t['train_name']) ?></h6>
                        <div class="text-muted small"><?= htmlspecialchars($t['train_number']) ?> &nbsp;·&nbsp; <?= htmlspecialchars($t['train_type']) ?></div>
                    </div>
                    <span class="sp sp-<?= $t['status'] ?>"><?= ucfirst($t['status']) ?></span>
                </div>

                <!-- Seat occupancy -->
                <div class="mb-3">
                    <div class="d-flex justify-content-between small text-muted mb-1">
                        <span>Seat Occupancy</span>
                        <span><?= $t['total_seats'] - $t['available_seats'] ?> / <?= $t['total_seats'] ?></span>
                    </div>
                    <div class="occ-bar">
                        <div class="occ-fill" style="width:<?= $occ_pct ?>%;
                            background:<?= $occ_pct > 80 ? 'linear-gradient(90deg,#ef4444,#dc2626)' : 'linear-gradient(90deg,#10b981,#059669)' ?>;"></div>
                    </div>
                </div>

                <!-- Stats row -->
                <div class="row g-0 text-center mb-3">
                    <?php foreach ([
                        [$t['total_routes'],    'Total Routes'],
                        [$t['today_routes'],    'Today'],
                        [$t['upcoming_routes'], 'Upcoming'],
                        [$t['today_confirmed'], 'Today Bkgs'],
                    ] as [$v, $l]): ?>
                    <div class="col-3 train-stat">
                        <strong><?= (int)$v ?></strong>
                        <small><?= $l ?></small>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Status update form -->
                <form method="POST" class="d-flex gap-2 align-items-center mb-3">
                    <input type="hidden" name="train_id" value="<?= $t['train_id'] ?>">
                    <select name="train_status" class="form-select form-select-sm"
                            onchange="this.form.submit()" title="Update train status">
                        <?php foreach (['active','inactive','maintenance'] as $st): ?>
                        <option value="<?= $st ?>" <?= $t['status'] === $st ? 'selected':'' ?>>
                            <?= ucfirst($st) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-secondary btn-sm text-nowrap" style="font-size:.77rem;">
                        <i class="bi bi-check2"></i> Save
                    </button>
                </form>
            </div>

            <div class="train-card-mid d-flex gap-2 flex-wrap">
                <!-- Today's route(s) links -->
                <?php
                $t_routes = $db->select(
                    "SELECT route_id, departure_city, arrival_city, departure_time, status
                     FROM routes WHERE train_id = {$t['train_id']}
                     AND journey_date >= CURDATE()
                     ORDER BY journey_date ASC, departure_time ASC
                     LIMIT 3"
                );
                if ($t_routes): ?>
                <?php foreach ($t_routes as $tr): ?>
                <a href="route-details-emp.php?id=<?= $tr['route_id'] ?>" class="route-chip">
                    <i class="bi bi-map"></i>
                    <?= htmlspecialchars($tr['departure_city']) ?> → <?= htmlspecialchars($tr['arrival_city']) ?>
                    (<?= date('H:i', strtotime($tr['departure_time'])) ?>)
                </a>
                <?php endforeach; ?>
                <?php else: ?>
                <span class="text-muted small">No upcoming routes</span>
                <?php endif; ?>
            </div>

            <div class="p-3 pt-0 d-flex gap-2 mt-2">
                <?php if ($t['today_routes'] > 0): ?>
                <a href="route-details-emp.php?train_id=<?= $t['train_id'] ?>"
                   class="btn btn-success btn-sm flex-fill">
                    <i class="bi bi-calendar-day me-1"></i>Today's Route
                </a>
                <?php endif; ?>
                <a href="check-passengers.php?train_id=<?= $t['train_id'] ?>"
                   class="btn btn-outline-primary btn-sm flex-fill">
                    <i class="bi bi-people me-1"></i>Passengers
                </a>
                <a href="assign-seats.php?train_id=<?= $t['train_id'] ?>"
                   class="btn btn-outline-secondary btn-sm px-2" title="Seat Map">
                    <i class="bi bi-grid-3x3-gap"></i>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</main>
</div>

<?php require_once 'inc/footer.php'; ?>

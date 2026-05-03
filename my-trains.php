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
.emp-sb-brand {
    padding:1.4rem 1.25rem 1.2rem;
    border-bottom:1px solid rgba(255,255,255,.1);
}
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
    transition:box-shadow .2s,transform .2s;
    height:100%;
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
.sp-active      { background:#dcfce7; color:#15803d; }
.sp-inactive    { background:#f1f5f9; color:#475569; }
.sp-maintenance { background:#ffedd5; color:#9a3412; }
.sp-scheduled   { background:#dbeafe; color:#1d4ed8; }

/* ─── Train cards ────────────────────────────────────── */
.train-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(310px,1fr)); gap:1.25rem; }
.train-card {
    background:#fff; border-radius:16px;
    border:1.5px solid #e2e8f0;
    box-shadow:0 2px 8px rgba(0,0,0,.06); overflow:hidden;
    transition:box-shadow .25s,border-color .25s,transform .25s;
    display:flex; flex-direction:column;
}
.train-card:hover { box-shadow:0 10px 32px rgba(0,0,0,.1); border-color:#10b981; transform:translateY(-3px); }
.tc-header { padding:1.2rem 1.25rem .8rem; }
.tc-stats  { padding:.8rem 1.25rem; background:#f8faf9; border-top:1px solid #f1f5f9; border-bottom:1px solid #f1f5f9; }
.tc-routes { padding:.75rem 1.25rem; background:#fafafa; flex:1; }
.tc-actions { padding:.85rem 1.25rem; display:flex; gap:.5rem; flex-wrap:wrap; border-top:1px solid #f1f5f9; }
.train-stat { text-align:center; padding:.3rem .5rem; }
.train-stat .ts-val { font-size:1.2rem; font-weight:800; color:#0f172a; line-height:1; display:block; }
.train-stat .ts-lbl { font-size:.65rem; color:#9ca3af; margin-top:.15rem; letter-spacing:.02em; display:block; }
.occ-bar { height:6px; border-radius:4px; background:#e5e7eb; overflow:hidden; }
.occ-fill { height:100%; border-radius:4px; }
.route-chip {
    display:inline-flex; align-items:center; gap:.35rem;
    background:#f0fdf4; color:#065f46; border:1px solid #bbf7d0;
    border-radius:8px; padding:.3rem .7rem; font-size:.72rem; font-weight:600;
    text-decoration:none; margin:.15rem; transition:background .2s;
}
.route-chip:hover { background:#dcfce7; color:#065f46; }

/* ─── Filter card ────────────────────────────────────── */
.filter-card {
    background:#fff; border-radius:14px;
    border:1px solid #e5e7eb;
    box-shadow:0 1px 4px rgba(0,0,0,.05);
    padding:1.1rem 1.25rem;
}
.filter-card label { font-size:.74rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#374151; }

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
        <a href="my-trains.php" class="active"><i class="bi bi-train-front"></i> My Trains</a>
        <a href="check-passengers.php"><i class="bi bi-people"></i> Passengers</a>
        <a href="assign-seats.php"><i class="bi bi-grid-3x3-gap"></i> Seat Management</a>
        <a href="operations-hub.php"><i class="bi bi-diagram-3"></i> Operations Hub</a>
        <div class="sb-sep">Bookings</div>
        <a href="check-passengers.php?view=bookings"><i class="bi bi-journal-check"></i> Today's Bookings</a>
        <div class="sb-sep">Account</div>
        <a href="notifications.php"><i class="bi bi-bell"></i> Notifications</a>
        <a href="profile.php"><i class="bi bi-person-circle"></i> My Profile</a>
        <a href="logout.php" style="color:rgba(252,165,165,.8)!important;"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </nav>
    <div class="emp-sb-footer">Railway Management System</div>
</aside>

<main class="emp-main">
    <!-- Page Header -->
    <div class="emp-page-header">
        <div>
            <p class="ph-title"><i class="bi bi-train-front me-2" style="color:#10b981;"></i>My Trains</p>
            <p class="ph-sub"><?= count($trains) ?> train(s) found &nbsp;·&nbsp; <?= date('D, d M Y') ?></p>
        </div>
        <a href="employee-dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Dashboard
        </a>
    </div>

    <div class="emp-content">
    <!-- KPI Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <div class="mc-ico" style="background:#dbeafe;"><i class="bi bi-train-front" style="color:#2563eb;font-size:1.3rem;"></i></div>
                <div class="mc-val"><?= (int)($kpi['total'] ?? 0) ?></div>
                <div class="mc-lbl">Total Trains</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <div class="mc-ico" style="background:#dcfce7;"><i class="bi bi-check-circle-fill" style="color:#16a34a;font-size:1.3rem;"></i></div>
                <div class="mc-val"><?= (int)($kpi['active'] ?? 0) ?></div>
                <div class="mc-lbl">Active</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <div class="mc-ico" style="background:#f1f5f9;"><i class="bi bi-dash-circle" style="color:#64748b;font-size:1.3rem;"></i></div>
                <div class="mc-val"><?= (int)($kpi['inactive'] ?? 0) ?></div>
                <div class="mc-lbl">Inactive</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <div class="mc-ico" style="background:#ffedd5;"><i class="bi bi-tools" style="color:#ea580c;font-size:1.3rem;"></i></div>
                <div class="mc-val"><?= (int)($kpi['maintenance'] ?? 0) ?></div>
                <div class="mc-lbl">Maintenance</div>
            </div>
        </div>
    </div>

    <!-- Filter bar -->
    <form method="GET" class="filter-card mb-4">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-sm-5">
                <label class="form-label mb-1"><i class="bi bi-search me-1"></i>Search</label>
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
        <i class="bi bi-train-front d-block fs-1 mb-3 opacity-25"></i>
        <strong>No trains found matching your criteria.</strong><br>
        <small>Try adjusting the filters above.</small>
    </div>
    <?php else: ?>
    <div class="train-grid">
        <?php foreach ($trains as $t): ?>
        <?php
        $occ_pct = $t['total_seats'] > 0
            ? round(($t['total_seats'] - $t['available_seats']) / $t['total_seats'] * 100) : 0;
        ?>
        <div class="train-card">
            <!-- Card header -->
            <div class="tc-header">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <h6 class="fw-bold mb-0"><?= htmlspecialchars($t['train_name']) ?></h6>
                        <div class="text-muted small mt-1">
                            <i class="bi bi-hash me-1"></i><?= htmlspecialchars($t['train_number']) ?>
                            &nbsp;·&nbsp;
                            <i class="bi bi-tag me-1"></i><?= htmlspecialchars($t['train_type']) ?>
                        </div>
                    </div>
                    <span class="sp sp-<?= $t['status'] ?>"><?= ucfirst($t['status']) ?></span>
                </div>
                <!-- Occupancy bar -->
                <div class="mt-2">
                    <div class="d-flex justify-content-between mb-1" style="font-size:.75rem;color:#6b7280;">
                        <span>Seat Occupancy</span>
                        <span class="fw-600"><?= $t['total_seats'] - $t['available_seats'] ?> / <?= $t['total_seats'] ?> &nbsp;<span style="color:<?= $occ_pct > 80 ? '#dc2626' : '#10b981' ?>"><?= $occ_pct ?>%</span></span>
                    </div>
                    <div class="occ-bar">
                        <div class="occ-fill" style="width:<?= $occ_pct ?>%;background:<?= $occ_pct > 80 ? 'linear-gradient(90deg,#ef4444,#dc2626)' : ($occ_pct > 60 ? 'linear-gradient(90deg,#f59e0b,#d97706)' : 'linear-gradient(90deg,#10b981,#059669)') ?>;"></div>
                    </div>
                </div>
            </div>

            <!-- Stat cells -->
            <div class="tc-stats">
                <div class="row g-0 text-center">
                    <div class="col-3 train-stat">
                        <span class="ts-val"><?= (int)$t['total_routes'] ?></span>
                        <span class="ts-lbl">Total Routes</span>
                    </div>
                    <div class="col-3 train-stat" style="border-left:1px solid #e5e7eb;">
                        <span class="ts-val text-success"><?= (int)$t['today_routes'] ?></span>
                        <span class="ts-lbl">Today</span>
                    </div>
                    <div class="col-3 train-stat" style="border-left:1px solid #e5e7eb;">
                        <span class="ts-val text-primary"><?= (int)$t['upcoming_routes'] ?></span>
                        <span class="ts-lbl">Upcoming</span>
                    </div>
                    <div class="col-3 train-stat" style="border-left:1px solid #e5e7eb;">
                        <span class="ts-val"><?= (int)$t['today_confirmed'] ?></span>
                        <span class="ts-lbl">Bkgs Today</span>
                    </div>
                </div>
            </div>

            <!-- Status form + upcoming routes -->
            <div class="tc-routes">
                <form method="POST" class="d-flex gap-2 align-items-center mb-2">
                    <input type="hidden" name="train_id" value="<?= $t['train_id'] ?>">
                    <select name="train_status" class="form-select form-select-sm"
                            onchange="this.form.submit()" title="Update train status" style="font-size:.82rem;">
                        <?php foreach (['active','inactive','maintenance'] as $st): ?>
                        <option value="<?= $st ?>" <?= $t['status'] === $st ? 'selected':'' ?>><?= ucfirst($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-outline-secondary btn-sm text-nowrap" style="font-size:.78rem;">
                        <i class="bi bi-check2"></i> Save
                    </button>
                </form>
                <?php
                $t_routes = $db->select(
                    "SELECT route_id, departure_city, arrival_city, departure_time
                     FROM routes WHERE train_id = {$t['train_id']}
                     AND journey_date >= CURDATE()
                     ORDER BY journey_date ASC, departure_time ASC
                     LIMIT 3"
                );
                if ($t_routes): ?>
                <div class="d-flex flex-wrap gap-1 mt-1">
                <?php foreach ($t_routes as $tr): ?>
                <a href="route-details-emp.php?id=<?= $tr['route_id'] ?>" class="route-chip">
                    <i class="bi bi-map"></i>
                    <?= htmlspecialchars($tr['departure_city']) ?> → <?= htmlspecialchars($tr['arrival_city']) ?>
                    <span style="color:#94a3b8;"><?= date('H:i', strtotime($tr['departure_time'])) ?></span>
                </a>
                <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="text-muted small mb-0"><i class="bi bi-calendar-x me-1"></i>No upcoming routes</p>
                <?php endif; ?>
            </div>

            <!-- Action buttons -->
            <div class="tc-actions">
                <?php if ($t['today_routes'] > 0): ?>
                <a href="route-details-emp.php?train_id=<?= $t['train_id'] ?>"
                   class="btn btn-success btn-sm flex-fill" style="font-size:.82rem;">
                    <i class="bi bi-calendar-day me-1"></i>Today's Route
                </a>
                <?php endif; ?>
                <a href="check-passengers.php?train_id=<?= $t['train_id'] ?>"
                   class="btn btn-outline-primary btn-sm flex-fill" style="font-size:.82rem;">
                    <i class="bi bi-people me-1"></i>Passengers
                </a>
                <a href="assign-seats.php?train_id=<?= $t['train_id'] ?>"
                   class="btn btn-outline-secondary btn-sm px-2" title="Seat Map" style="font-size:.82rem;">
                    <i class="bi bi-grid-3x3-gap"></i>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    </div><!-- /emp-content -->
</main>
</div>

<?php require_once 'inc/footer.php'; ?>

<?php
// train-seats-report.php - Train Seats Booking Report (Admin)

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/Train.php';

if (!User::isLoggedIn() || $_SESSION['role'] != 'admin') {
    header('Location: login.php');
    exit();
}

$db = new Database();
$db->connect();
$conn = $db->getConnection();

$train_obj = new Train($db);
$user_obj = new User($db);
$user = $user_obj->getUserById($_SESSION['user_id']);

// ── Filters ────────────────────────────────────────────────────────────────
$filter_status   = $_GET['status'] ?? 'all';
$filter_train_id = isset($_GET['train_id']) ? (int)$_GET['train_id'] : 0;
$search          = trim($_GET['q'] ?? '');

// ── Train list with seat stats ─────────────────────────────────────────────
$where_clauses = [];
if ($filter_status !== 'all') {
    $safe_status = $conn->real_escape_string($filter_status);
    $where_clauses[] = "t.status = '{$safe_status}'";
}
if ($search !== '') {
    $safe_search = $conn->real_escape_string($search);
    $where_clauses[] = "(t.train_name LIKE '%{$safe_search}%' OR t.train_number LIKE '%{$safe_search}%')";
}
$where_sql = $where_clauses ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$trains = $db->select(
    "SELECT t.train_id, t.train_name, t.train_number, t.train_type, t.total_seats, t.status,
            COALESCE(seat_stats.booked_seats, 0) AS booked_seats,
            COALESCE(seat_stats.confirmed_seats, 0) AS confirmed_seats,
            t.total_seats - COALESCE(seat_stats.booked_seats, 0) AS available_seats
     FROM trains t
     LEFT JOIN (
         SELECT s.train_id,
                COUNT(*) AS booked_seats,
                SUM(CASE WHEN b.booking_status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_seats
         FROM seats s
         JOIN booking_seats bs ON bs.seat_id = s.seat_id
         JOIN bookings b ON b.booking_id = bs.booking_id
         WHERE s.status = 'booked'
           AND b.booking_status != 'cancelled'
         GROUP BY s.train_id
     ) seat_stats ON seat_stats.train_id = t.train_id
     {$where_sql}
     ORDER BY t.status = 'active' DESC, seat_stats.booked_seats DESC, t.train_name ASC"
);
if (!$trains) $trains = [];

// ── Aggregates ─────────────────────────────────────────────────────────────
$total_trains_n       = count($trains);
$total_booked_seats   = array_sum(array_map('intval', array_column($trains, 'booked_seats')));
$total_capacity       = array_sum(array_map('intval', array_column($trains, 'total_seats')));
$overall_booking_pct  = $total_capacity > 0 ? round(($total_booked_seats / $total_capacity) * 100, 1) : 0;

// ── Selected train detail: which seats are booked ──────────────────────────
$selected_train = null;
$booked_seats_detail = [];
if ($filter_train_id > 0) {
    $selected_train = $train_obj->getTrainById($filter_train_id);

    $booked_seats_detail = $db->select(
        "SELECT s.seat_id, s.seat_number, s.seat_type,
                bs.passenger_name, bs.passenger_age, bs.passenger_gender,
                b.booking_reference, b.booking_status, b.payment_status,
                b.journey_date, b.total_fare,
                r.departure_city, r.arrival_city, r.departure_time, r.arrival_time,
                u.full_name AS booked_by
         FROM seats s
         JOIN booking_seats bs ON bs.seat_id = s.seat_id
         JOIN bookings b ON b.booking_id = bs.booking_id
         JOIN routes r ON r.route_id = s.route_id
         JOIN users u ON u.user_id = b.user_id
         WHERE s.train_id = {$filter_train_id}
           AND s.status = 'booked'
           AND b.booking_status != 'cancelled'
         ORDER BY b.journey_date DESC, s.seat_number ASC"
    );
    if (!$booked_seats_detail) $booked_seats_detail = [];
}

$hideMainNavbar = true;
$pageTitle = 'Train Seats Report – Admin';
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

/* Page header */
.adm-page-header {
    display:flex; justify-content:space-between; align-items:flex-start;
    gap:1rem; flex-wrap:wrap; margin-bottom:1.5rem;
}
.adm-page-header h2 { margin:0; font-size:1.6rem; font-weight:800; color:#0f172a; }
.adm-page-header p  { margin:.2rem 0 0; color:#64748b; font-size:.875rem; }

/* KPI strip */
.kpi-strip {
    display:grid; grid-template-columns:repeat(auto-fit, minmax(170px,1fr)); gap:1rem;
    margin-bottom:1.5rem;
}
.kpi-box {
    background:#fff; border-radius:12px; padding:1.1rem 1.3rem;
    box-shadow:0 1px 4px rgba(0,0,0,.07);
}
.kpi-box .kpi-icon {
    width:38px; height:38px; border-radius:9px;
    display:flex; align-items:center; justify-content:center;
    font-size:1.1rem; margin-bottom:.7rem;
}
.kpi-box .kpi-val { font-size:1.6rem; font-weight:800; color:#0f172a; line-height:1.1; }
.kpi-box .kpi-lbl { font-size:.75rem; color:#64748b; margin-top:.2rem; }
.kpi-blue   .kpi-icon { background:#dbeafe; color:#2563eb; }
.kpi-green  .kpi-icon { background:#dcfce7; color:#16a34a; }
.kpi-amber  .kpi-icon { background:#fef3c7; color:#d97706; }
.kpi-purple .kpi-icon { background:#ede9fe; color:#7c3aed; }

/* Filters */
.filter-bar {
    display:flex; gap:.75rem; align-items:center; flex-wrap:wrap;
    margin-bottom:1.25rem;
}
.filter-bar select, .filter-bar input {
    padding:.45rem .8rem; border:1px solid #e2e8f0; border-radius:8px;
    font-size:.8rem; background:#fff; color:#334155;
}
.filter-bar select:focus, .filter-bar input:focus {
    outline:none; border-color:#93c5fd; box-shadow:0 0 0 3px rgba(59,130,246,.12);
}
.filter-bar .btn-sm {
    padding:.45rem 1rem; font-size:.8rem; border-radius:8px;
}

/* Table card */
.surface-card {
    background:#fff; border-radius:14px; padding:1.25rem;
    box-shadow:0 1px 4px rgba(0,0,0,.07); margin-bottom:1.5rem;
}
.card-title-row {
    display:flex; justify-content:space-between; align-items:center;
    gap:1rem; margin-bottom:1rem;
}
.card-title-row h4 { margin:0; font-size:.95rem; font-weight:700; color:#0f172a; }

/* Train table */
.data-table { width:100%; border-collapse:collapse; font-size:.82rem; }
.data-table th {
    padding:.6rem .7rem; color:#64748b; font-weight:600; text-align:left;
    border-bottom:2px solid #f1f5f9; font-size:.73rem; text-transform:uppercase; letter-spacing:.5px;
    white-space:nowrap;
}
.data-table td {
    padding:.6rem .7rem; border-bottom:1px solid #f8fafc; color:#334155;
    vertical-align:middle;
}
.data-table tbody tr { cursor:pointer; transition:background .15s; }
.data-table tbody tr:hover { background:#f8fafc; }
.data-table tbody tr.row-selected { background:#eff6ff; }
.data-table .train-name { font-weight:700; color:#1e40af; }
.data-table .train-num  { font-family:monospace; font-size:.77rem; color:#6366f1; }

/* Progress bar */
.prog-bar { background:#f1f5f9; border-radius:99px; height:6px; overflow:hidden; min-width:80px; }
.prog-bar-fill { height:100%; border-radius:99px; transition:width .4s ease; }
.prog-fill-high  { background:linear-gradient(90deg,#16a34a,#22c55e); }
.prog-fill-mid   { background:linear-gradient(90deg,#d97706,#f59e0b); }
.prog-fill-low   { background:linear-gradient(90deg,#3b82f6,#6366f1); }

/* Status pill */
.pill { display:inline-block; padding:.2em .65em; border-radius:999px; font-size:.7rem; font-weight:600; }
.pill-active      { background:#dcfce7; color:#166534; }
.pill-maintenance { background:#fef3c7; color:#92400e; }
.pill-inactive    { background:#f1f5f9; color:#64748b; }
.pill-confirmed   { background:#dcfce7; color:#166534; }
.pill-pending     { background:#fef3c7; color:#92400e; }
.pill-cancelled   { background:#fee2e2; color:#991b1b; }
.pill-completed-p { background:#dbeafe; color:#1e40af; }

/* Detail panel */
.detail-panel {
    background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px;
    padding:1.4rem; margin-top:1rem; margin-bottom:1.5rem;
}
.detail-panel h5 { font-size:.95rem; font-weight:700; color:#0f172a; margin:0 0 .25rem; }
.detail-panel .meta { font-size:.78rem; color:#64748b; margin-bottom:1rem; }
.detail-grid {
    display:grid; grid-template-columns:repeat(auto-fill, minmax(280px,1fr));
    gap:.75rem;
}
.seat-card {
    background:#fff; border:1px solid #e2e8f0; border-radius:10px;
    padding:.85rem 1rem; display:flex; gap:.85rem; align-items:flex-start;
    transition:box-shadow .2s;
}
.seat-card:hover { box-shadow:0 2px 8px rgba(0,0,0,.08); }
.seat-card .sc-icon {
    width:40px; height:40px; border-radius:9px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    font-size:.85rem; font-weight:700;
}
.sc-icon-eco  { background:#dbeafe; color:#2563eb; }
.sc-icon-prem { background:#ede9fe; color:#7c3aed; }
.sc-icon-lux  { background:#fef3c7; color:#b45309; }
.seat-card .sc-body { flex:1; min-width:0; }
.seat-card .sc-body .sc-seat { font-weight:700; color:#0f172a; font-size:.85rem; }
.seat-card .sc-body .sc-passenger { font-size:.8rem; color:#334155; margin-top:.15rem; }
.seat-card .sc-body .sc-route { font-size:.73rem; color:#64748b; margin-top:.2rem; }
.seat-card .sc-body .sc-ref {
    font-family:monospace; font-size:.72rem; color:#6366f1; margin-top:.15rem;
}

/* Empty state */
.empty-state {
    text-align:center; padding:3rem 1.5rem; color:#94a3b8;
}
.empty-state i { font-size:3rem; display:block; margin-bottom:.75rem; opacity:.4; }
.empty-state p { font-size:.9rem; margin:0; }

/* Responsive */
@media (max-width:860px) {
    .adm-sidebar { display:none; }
    .adm-main { padding:1rem; }
}
</style>

<div class="adm-wrap">

    <!-- ══ SIDEBAR ══════════════════════════════════════════ -->
    <aside class="adm-sidebar">
        <div class="sb-brand">
            <span>Management Panel</span>
            <strong>🚂 Railway Admin</strong>
        </div>
        <nav>
            <div class="sb-sep">Main</div>
            <a href="admin-dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a href="reports.php"><i class="bi bi-bar-chart-line"></i> Reports</a>

            <div class="sb-sep">Operations</div>
            <a href="manage-trains.php"><i class="bi bi-train-front"></i> Trains</a>
            <a href="manage-routes.php"><i class="bi bi-map"></i> Routes</a>
            <a href="train-seats-report.php" class="active"><i class="bi bi-diagram-3"></i> Seat Report</a>
            <a href="booking-admin.php"><i class="bi bi-ticket-perforated"></i> Bookings</a>
            <a href="cargo-shipments.php"><i class="bi bi-box-seam"></i> Cargo</a>
            <a href="operations-hub.php"><i class="bi bi-diagram-3"></i> Operations Hub</a>

            <div class="sb-sep">Finance</div>
            <a href="manage-payments.php"><i class="bi bi-credit-card"></i> Payments</a>

            <div class="sb-sep">Users</div>
            <a href="manage-users.php"><i class="bi bi-people"></i> Users</a>

            <div class="sb-sep">System</div>
            <a href="audit-logs.php"><i class="bi bi-clock-history"></i> Audit Logs</a>
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

    <!-- ══ MAIN CONTENT ══════════════════════════════════════ -->
    <main class="adm-main">

        <!-- Page Header -->
        <div class="adm-page-header">
            <div>
                <h2><i class="bi bi-diagram-3 me-2"></i>Train Seats Report</h2>
                <p>View which trains have how many and which seats booked.</p>
            </div>
        </div>

        <!-- KPI Strip -->
        <div class="kpi-strip">
            <div class="kpi-box kpi-blue">
                <div class="kpi-icon"><i class="bi bi-train-front"></i></div>
                <div class="kpi-val"><?= $total_trains_n ?></div>
                <div class="kpi-lbl">Total Trains</div>
            </div>
            <div class="kpi-box kpi-green">
                <div class="kpi-icon"><i class="bi bi-check-circle"></i></div>
                <div class="kpi-val"><?= $total_booked_seats ?></div>
                <div class="kpi-lbl">Total Booked Seats</div>
            </div>
            <div class="kpi-box kpi-amber">
                <div class="kpi-icon"><i class="bi bi-pie-chart"></i></div>
                <div class="kpi-val"><?= $overall_booking_pct ?>%</div>
                <div class="kpi-lbl">Overall Occupancy</div>
            </div>
            <div class="kpi-box kpi-purple">
                <div class="kpi-icon"><i class="bi bi-people"></i></div>
                <div class="kpi-val"><?= $total_capacity ?></div>
                <div class="kpi-lbl">Total Capacity</div>
            </div>
        </div>

        <!-- Filters -->
        <form method="GET" class="filter-bar" id="filterForm">
            <select name="status" onchange="document.getElementById('filterForm').submit()">
                <option value="all"  <?= $filter_status === 'all' ? 'selected' : '' ?>>All Statuses</option>
                <option value="active"      <?= $filter_status === 'active'      ? 'selected' : '' ?>>Active</option>
                <option value="maintenance" <?= $filter_status === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                <option value="inactive"    <?= $filter_status === 'inactive'    ? 'selected' : '' ?>>Inactive</option>
            </select>
            <input type="text" name="q" placeholder="Search train name or number…"
                   value="<?= htmlspecialchars($search) ?>" style="min-width:220px;">
            <button type="submit" class="btn btn-sm btn-primary">Search</button>
            <?php if ($filter_train_id): ?>
                <input type="hidden" name="train_id" value="<?= $filter_train_id ?>">
            <?php endif; ?>
            <?php if ($search || $filter_status !== 'all'): ?>
                <a href="train-seats-report.php" class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>

        <!-- Trains Table -->
        <div class="surface-card">
            <div class="card-title-row">
                <h4><i class="bi bi-train-front me-2"></i>Train Seat Occupancy</h4>
                <span class="text-muted" style="font-size:.75rem;">Click a row to see booked seat details</span>
            </div>

            <?php if (empty($trains)): ?>
                <div class="empty-state">
                    <i class="bi bi-inbox"></i>
                    <p>No trains found matching your filters.</p>
                </div>
            <?php else: ?>
            <div style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Train</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Total Seats</th>
                        <th>Booked</th>
                        <th>Available</th>
                        <th>Occupancy</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($trains as $train):
                        $t_id       = (int)$train['train_id'];
                        $total      = (int)$train['total_seats'];
                        $booked     = (int)$train['booked_seats'];
                        $available  = (int)$train['available_seats'];
                        $pct        = $total > 0 ? round(($booked / $total) * 100, 1) : 0;
                        $isSelected = ($filter_train_id === $t_id);
                        $fillClass  = $pct >= 80 ? 'prog-fill-high' : ($pct >= 40 ? 'prog-fill-mid' : 'prog-fill-low');
                        $statusClass = match($train['status']) {
                            'active'      => 'pill-active',
                            'maintenance' => 'pill-maintenance',
                            default       => 'pill-inactive'
                        };
                        $rowUrl = "train-seats-report.php?train_id={$t_id}" .
                                  ($filter_status !== 'all' ? "&status={$filter_status}" : '') .
                                  ($search !== '' ? "&q=" . urlencode($search) : '');
                    ?>
                    <tr class="<?= $isSelected ? 'row-selected' : '' ?>"
                        onclick="window.location='<?= $rowUrl ?>'"
                        title="Click to see booked seats for <?= htmlspecialchars($train['train_name']) ?>">
                        <td>
                            <div class="train-name"><?= htmlspecialchars($train['train_name']) ?></div>
                            <div class="train-num"><?= htmlspecialchars($train['train_number']) ?></div>
                        </td>
                        <td><?= htmlspecialchars($train['train_type']) ?></td>
                        <td><span class="pill <?= $statusClass ?>"><?= ucfirst($train['status']) ?></span></td>
                        <td><strong><?= $total ?></strong></td>
                        <td><strong style="color:<?= $booked > 0 ? '#2563eb' : '#94a3b8' ?>"><?= $booked ?></strong></td>
                        <td><?= $available ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:.5rem;">
                                <div class="prog-bar" style="flex:1;">
                                    <div class="prog-bar-fill <?= $fillClass ?>" style="width:<?= $pct ?>%"></div>
                                </div>
                                <span style="font-size:.75rem;font-weight:600;white-space:nowrap;"><?= $pct ?>%</span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Selected Train Detail: Which Seats Are Booked -->
        <?php if ($selected_train): ?>
        <div class="detail-panel" id="seatDetail">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">
                <div>
                    <h5>
                        <i class="bi bi-train-front me-1"></i>
                        <?= htmlspecialchars($selected_train['train_name']) ?>
                        <span style="font-family:monospace;font-size:.8rem;color:#6366f1;font-weight:400;">
                            (<?= htmlspecialchars($selected_train['train_number']) ?>)
                        </span>
                    </h5>
                    <p class="meta">
                        Type: <?= htmlspecialchars($selected_train['train_type']) ?> &nbsp;·&nbsp;
                        Total Seats: <?= (int)$selected_train['total_seats'] ?> &nbsp;·&nbsp;
                        Booked: <?= count($booked_seats_detail) ?> &nbsp;·&nbsp;
                        Status: <span class="pill <?= $selected_train['status'] === 'active' ? 'pill-active' : ($selected_train['status'] === 'maintenance' ? 'pill-maintenance' : 'pill-inactive') ?>"><?= ucfirst($selected_train['status']) ?></span>
                    </p>
                </div>
                <a href="train-seats-report.php<?= $filter_status !== 'all' ? "?status={$filter_status}" : '' ?><?= $search ? ($filter_status !== 'all' ? '&' : '?') . 'q=' . urlencode($search) : '' ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-x-lg"></i> Close Detail
                </a>
            </div>

            <?php if (empty($booked_seats_detail)): ?>
                <div class="empty-state" style="padding:2rem 1rem;">
                    <i class="bi bi-check2-circle"></i>
                    <p>No seats are currently booked on this train.</p>
                </div>
            <?php else: ?>
                <?php
                // Group by route + journey date for better organization
                $grouped = [];
                foreach ($booked_seats_detail as $seat) {
                    $key = ($seat['departure_city'] ?? '?') . ' → ' . ($seat['arrival_city'] ?? '?') . ' | ' . ($seat['journey_date'] ?? '?');
                    $grouped[$key][] = $seat;
                }
                ?>
                <?php foreach ($grouped as $route_label => $seats): ?>
                <div style="margin-top:1rem;">
                    <h6 style="font-size:.82rem;font-weight:700;color:#475569;margin-bottom:.75rem;padding-bottom:.4rem;border-bottom:2px solid #e2e8f0;">
                        <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($route_label) ?>
                        <span style="font-weight:400;color:#94a3b8;">(<?= count($seats) ?> seat<?= count($seats) > 1 ? 's' : '' ?>)</span>
                    </h6>
                    <div class="detail-grid">
                        <?php foreach ($seats as $s):
                            $seatClass = match($s['seat_type']) {
                                'premium' => 'sc-icon-prem',
                                'luxury'  => 'sc-icon-lux',
                                default   => 'sc-icon-eco'
                            };
                            $statusPill = match($s['booking_status']) {
                                'confirmed' => 'pill-confirmed',
                                'pending'   => 'pill-pending',
                                'cancelled' => 'pill-cancelled',
                                default     => 'pill-pending'
                            };
                        ?>
                        <div class="seat-card">
                            <div class="sc-icon <?= $seatClass ?>">
                                <?= htmlspecialchars($s['seat_number']) ?>
                            </div>
                            <div class="sc-body">
                                <div class="sc-seat">
                                    Seat <?= htmlspecialchars($s['seat_number']) ?>
                                    <span style="font-weight:400;font-size:.72rem;color:#64748b;">(<?= ucfirst($s['seat_type']) ?>)</span>
                                    <span class="pill <?= $statusPill ?>" style="margin-left:.3rem;"><?= ucfirst($s['booking_status']) ?></span>
                                </div>
                                <div class="sc-passenger">
                                    <i class="bi bi-person"></i>
                                    <?= htmlspecialchars($s['passenger_name'] ?: 'N/A') ?>
                                    <?php if ($s['passenger_age']): ?>
                                        · Age: <?= (int)$s['passenger_age'] ?>
                                    <?php endif; ?>
                                    <?php if ($s['passenger_gender']): ?>
                                        · <?= $s['passenger_gender'] ?>
                                    <?php endif; ?>
                                </div>
                                <div class="sc-route">
                                    <i class="bi bi-geo-alt"></i>
                                    <?= htmlspecialchars($s['departure_city']) ?> → <?= htmlspecialchars($s['arrival_city']) ?>
                                    · <?= date('d M Y', strtotime($s['journey_date'])) ?>
                                    <?php if (!empty($s['departure_time'])): ?>
                                        · <?= date('H:i', strtotime($s['departure_time'])) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="sc-ref">
                                    <i class="bi bi-receipt"></i>
                                    Ref: <?= htmlspecialchars($s['booking_reference']) ?>
                                    · Booked by: <?= htmlspecialchars($s['booked_by']) ?>
                                    · Fare: Rs. <?= number_format((float)$s['total_fare'], 2) ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </main>
</div>

<!-- Mobile toggle script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('admSidebar');
    const toggle  = document.getElementById('admSidebarToggle');
    if (toggle && sidebar) {
        toggle.addEventListener('click', function() {
            sidebar.classList.toggle('open');
        });
        document.addEventListener('click', function(e) {
            if (!sidebar.contains(e.target) && e.target !== toggle && !toggle.contains(e.target)) {
                sidebar.classList.remove('open');
            }
        });
    }
});
</script>

<?php require_once 'inc/footer.php'; ?>

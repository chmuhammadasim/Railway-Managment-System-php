<?php
// manage-bookings.php – Admin: All Bookings List

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/Booking.php';

if (!User::isLoggedIn() || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$db = new Database();
$db->connect();
$bookingObj = new Booking($db);

// ── Filters from GET ──────────────────────────────────────────────────────────
$filter_status  = $_GET['status']     ?? 'all';
$filter_pay     = $_GET['payment']    ?? 'all';
$search         = trim($_GET['q']     ?? '');
$date_from      = $_GET['date_from']  ?? '';
$date_to        = $_GET['date_to']    ?? '';
$page           = max(1, (int)($_GET['page'] ?? 1));
$per_page       = 20;
$offset         = ($page - 1) * $per_page;

// ── Build WHERE clause ────────────────────────────────────────────────────────
$wheres = [];
$conn   = $db->getConnection();

if ($filter_status !== 'all') {
    $s = $conn->real_escape_string($filter_status);
    $wheres[] = "b.booking_status = '{$s}'";
}
if ($filter_pay !== 'all') {
    $p = $conn->real_escape_string($filter_pay);
    $wheres[] = "b.payment_status = '{$p}'";
}
if ($search !== '') {
    $sq = $conn->real_escape_string($search);
    $wheres[] = "(b.booking_reference LIKE '%{$sq}%' OR u.full_name LIKE '%{$sq}%' OR u.email LIKE '%{$sq}%')";
}
if ($date_from !== '') {
    $df = $conn->real_escape_string($date_from);
    $wheres[] = "b.journey_date >= '{$df}'";
}
if ($date_to !== '') {
    $dt = $conn->real_escape_string($date_to);
    $wheres[] = "b.journey_date <= '{$dt}'";
}

$where_sql = $wheres ? 'WHERE ' . implode(' AND ', $wheres) : '';

// ── KPI counts (unfiltered) ───────────────────────────────────────────────────
$kpi = $db->selectRow(
    "SELECT
        COUNT(*) AS total,
        SUM(booking_status = 'confirmed')  AS confirmed,
        SUM(booking_status = 'pending')    AS pending,
        SUM(booking_status = 'cancelled')  AS cancelled,
        SUM(payment_status = 'completed')  AS paid,
        SUM(total_fare)                    AS revenue
     FROM bookings"
);

// ── Total rows for pagination ─────────────────────────────────────────────────
$count_row = $db->selectRow(
    "SELECT COUNT(*) AS n
     FROM bookings b
     JOIN users  u ON b.user_id   = u.user_id
     JOIN routes r ON b.route_id  = r.route_id
     JOIN trains t ON r.train_id  = t.train_id
     {$where_sql}"
);
$total_rows = (int)($count_row['n'] ?? 0);
$total_pages = max(1, (int)ceil($total_rows / $per_page));

// ── Bookings query ────────────────────────────────────────────────────────────
$bookings = $db->select(
    "SELECT b.booking_id, b.booking_reference, b.booking_date, b.journey_date,
            b.number_of_seats, b.total_fare, b.booking_status, b.payment_status,
            u.full_name, u.email,
            r.departure_city, r.arrival_city,
            t.train_name
     FROM bookings b
     JOIN users  u ON b.user_id   = u.user_id
     JOIN routes r ON b.route_id  = r.route_id
     JOIN trains t ON r.train_id  = t.train_id
     {$where_sql}
     ORDER BY b.booking_date DESC
     LIMIT {$per_page} OFFSET {$offset}"
);
if (!$bookings) $bookings = [];

// ── Inline quick-status update ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $bid = (int)($_POST['booking_id'] ?? 0);
    if ($bid > 0) {
        if ($_POST['action'] === 'confirm') {
            $bookingObj->confirmBooking($bid);
        } elseif ($_POST['action'] === 'cancel') {
            $bookingObj->cancelBooking($bid, 'Admin queue cancellation');
        }
    }
    // Redirect to same page to avoid re-POST
    $qs = http_build_query(array_filter([
        'status'    => $filter_status !== 'all' ? $filter_status : null,
        'payment'   => $filter_pay    !== 'all' ? $filter_pay    : null,
        'q'         => $search        ?: null,
        'date_from' => $date_from     ?: null,
        'date_to'   => $date_to       ?: null,
        'page'      => $page > 1      ? $page  : null,
    ]));
    header('Location: manage-bookings.php' . ($qs ? "?{$qs}" : ''));
    exit();
}

// ── Helper: build URL preserving current filters ──────────────────────────────
function bUrl(array $overrides = []): string {
    global $filter_status, $filter_pay, $search, $date_from, $date_to, $page;
    $params = array_filter(array_merge([
        'status'    => $filter_status !== 'all' ? $filter_status : null,
        'payment'   => $filter_pay    !== 'all' ? $filter_pay    : null,
        'q'         => $search        ?: null,
        'date_from' => $date_from     ?: null,
        'date_to'   => $date_to       ?: null,
        'page'      => $page > 1      ? $page  : null,
    ], $overrides));
    $qs = http_build_query($params);
    return 'manage-bookings.php' . ($qs ? "?{$qs}" : '');
}

$hideMainNavbar = true;
$pageTitle = 'Manage Bookings – Admin';

// Load current admin user for sidebar avatar
$userObj_ = new User($db);
$adminUser_ = $userObj_->getUserById($_SESSION['user_id']);

require_once 'inc/header.php';
?>

<style>
/* ── Shared admin layout ── */
.adm-wrap { display:flex; min-height:100vh; }
.adm-sidebar {
    width:240px; flex-shrink:0;
    background:linear-gradient(180deg,#1a2e4a 0%,#0f1e32 100%);
    color:#c8d6e8; display:flex; flex-direction:column;
    position:sticky; top:0; height:100vh; overflow-y:auto;
}
.adm-sidebar .sb-brand { padding:1.4rem 1.5rem 1rem; border-bottom:1px solid rgba(255,255,255,.08); }
.adm-sidebar .sb-brand span { display:block; font-size:.7rem; text-transform:uppercase; letter-spacing:1.5px; opacity:.5; margin-bottom:.3rem; }
.adm-sidebar .sb-brand strong { font-size:1rem; color:#fff; }
.adm-sidebar nav { flex:1; padding:.75rem 0; }
.adm-sidebar nav a {
    display:flex; align-items:center; gap:.75rem; padding:.65rem 1.5rem;
    color:#c8d6e8; text-decoration:none; font-size:.875rem; font-weight:500;
    transition:all .2s; border-left:3px solid transparent;
}
.adm-sidebar nav a:hover,
.adm-sidebar nav a.active { background:rgba(255,255,255,.07); color:#fff; border-left-color:#3b82f6; }
.adm-sidebar nav a i { font-size:1rem; width:1.1rem; text-align:center; }
.adm-sidebar .sb-sep { padding:.5rem 1.5rem .25rem; font-size:.68rem; text-transform:uppercase; letter-spacing:1.5px; opacity:.4; margin-top:.5rem; }
.adm-sidebar .sb-user { padding:1rem 1.5rem; border-top:1px solid rgba(255,255,255,.08); display:flex; align-items:center; gap:.75rem; }
.adm-sidebar .sb-user .avatar {
    width:34px; height:34px; border-radius:50%;
    background:linear-gradient(135deg,#3b82f6,#6366f1);
    display:flex; align-items:center; justify-content:center;
    font-size:.875rem; font-weight:700; color:#fff; flex-shrink:0;
}
.adm-sidebar .sb-user .info small { display:block; font-size:.7rem; opacity:.5; }
.adm-sidebar .sb-user .info strong { font-size:.8rem; color:#fff; }

.adm-main { flex:1; padding:2rem; overflow-x:hidden; }

/* ── KPI cards ── */
.kpi-card { border-radius:14px; border:none; box-shadow:0 1px 4px rgba(0,0,0,.07); }
.kpi-icon { width:46px; height:46px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; }

/* ── Status pills ── */
.sp { display:inline-block; padding:.25em .75em; border-radius:999px; font-size:.77rem; font-weight:600; }
.sp-confirmed { background:#d1fae5; color:#065f46; }
.sp-pending   { background:#fef3c7; color:#92400e; }
.sp-cancelled { background:#fee2e2; color:#7f1d1d; }
.sp-completed { background:#d1fae5; color:#065f46; }
.sp-refunded  { background:#ede9fe; color:#4c1d95; }
.sp-failed    { background:#fee2e2; color:#7f1d1d; }

/* ── Table tweaks ── */
.bk-table thead th { background:#0f1e32; color:#fff; font-weight:600; font-size:.82rem; white-space:nowrap; }
.bk-table tbody tr:hover { background:#f8fafc; }
.bk-table td { font-size:.84rem; vertical-align:middle; }

/* ── Filter bar ── */
.filter-bar { background:#fff; border-radius:14px; box-shadow:0 1px 6px rgba(0,0,0,.07); padding:1.25rem; margin-bottom:1.25rem; }

/* ── Page header ── */
.adm-page-header { display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; flex-wrap:wrap; margin-bottom:1.5rem; }
.adm-page-header h2 { margin:0; font-size:1.6rem; font-weight:800; color:#0f172a; }
.adm-page-header p { margin:.2rem 0 0; color:#64748b; font-size:.9rem; }

@media (max-width:900px) {
    .adm-sidebar { display:none; }
    .adm-main    { padding:1rem; }
}
</style>

<div class="adm-wrap">
<!-- ── Sidebar ── -->
<aside class="adm-sidebar">
    <div class="sb-brand">
        <span>Management Panel</span>
        <strong><i class="bi bi-train-front-fill me-1"></i> Railway Admin</strong>
    </div>
    <nav>
        <div class="sb-sep">Main</div>
        <a href="admin-dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="reports.php"><i class="bi bi-bar-chart-line"></i> Reports</a>

        <div class="sb-sep">Operations</div>
        <a href="manage-trains.php"><i class="bi bi-train-front"></i> Trains</a>
        <a href="train-seats-report.php"><i class="bi bi-diagram-3"></i> Seat Report</a>
        <a href="manage-routes.php"><i class="bi bi-map"></i> Routes</a>
        <a href="operations-hub.php?tab=stations"><i class="bi bi-building"></i> Stations</a>
        <a href="manage-bookings.php" class="active"><i class="bi bi-ticket-perforated"></i> Bookings</a>
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
        <div class="avatar"><?= strtoupper(substr($adminUser_['full_name'] ?? 'A', 0, 1)) ?></div>
        <div class="info">
            <strong><?= htmlspecialchars($adminUser_['full_name'] ?? 'Admin') ?></strong>
            <small>Administrator</small>
        </div>
    </div>
</aside>

<!-- ── Main Content ── -->
<section class="adm-main">

    <!-- Page Header -->
    <div class="adm-page-header mb-4">
        <div>
            <h2><i class="bi bi-ticket-perforated me-2 text-primary"></i>Manage Bookings</h2>
            <p><?= number_format($total_rows) ?> bookings match current filters</p>
        </div>
        <a href="reports.php" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-bar-chart-line me-1"></i>View Reports
        </a>
    </div>

    <!-- KPI Strip -->
    <div class="row g-3 mb-4">
        <?php foreach ([
            ['Total',     $kpi['total'],     'bi-journal-text',  'bg-primary text-white',    '#f0f4ff'],
            ['Confirmed', $kpi['confirmed'], 'bi-check-circle',  'bg-success text-white',     '#f0fdf4'],
            ['Pending',   $kpi['pending'],   'bi-hourglass',     'bg-warning text-dark',      '#fffbeb'],
            ['Cancelled', $kpi['cancelled'], 'bi-x-circle',      'bg-danger text-white',      '#fef2f2'],
            ['Revenue',   'Rs. ' . number_format($kpi['revenue'] ?? 0, 0), 'bi-currency-dollar', 'bg-info text-white', '#f0f9ff'],
        ] as [$lbl, $val, $icon, $ibg, $bg]): ?>
        <div class="col-6 col-md-4 col-xl-2-4">
            <div class="kpi-card card p-3" style="background:<?= $bg ?>;">
                <div class="d-flex align-items-center gap-3">
                    <div class="kpi-icon <?= $ibg ?>"><i class="bi <?= $icon ?>"></i></div>
                    <div>
                        <div class="fw-bold fs-5"><?= $val ?></div>
                        <div class="text-muted small"><?= $lbl ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filter Bar -->
    <form method="GET" class="filter-bar">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-sm-6 col-lg-3">
                <label class="form-label mb-1 small fw-semibold">Search</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" class="form-control"
                           placeholder="Ref / Name / Email"
                           value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="col-6 col-sm-3 col-lg-2">
                <label class="form-label mb-1 small fw-semibold">Booking Status</label>
                <select name="status" class="form-select form-select-sm">
                    <?php foreach (['all'=>'All Statuses','confirmed'=>'Confirmed','pending'=>'Pending','cancelled'=>'Cancelled'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= $filter_status === $v ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-sm-3 col-lg-2">
                <label class="form-label mb-1 small fw-semibold">Payment Status</label>
                <select name="payment" class="form-select form-select-sm">
                    <?php foreach (['all'=>'All','completed'=>'Paid','pending'=>'Pending','refunded'=>'Refunded','failed'=>'Failed'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= $filter_pay === $v ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-sm-3 col-lg-2">
                <label class="form-label mb-1 small fw-semibold">Journey From</label>
                <input type="date" name="date_from" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($date_from) ?>">
            </div>
            <div class="col-6 col-sm-3 col-lg-2">
                <label class="form-label mb-1 small fw-semibold">Journey To</label>
                <input type="date" name="date_to" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($date_to) ?>">
            </div>
            <div class="col-12 col-lg-1 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm flex-fill">
                    <i class="bi bi-funnel"></i>
                </button>
                <a href="manage-bookings.php" class="btn btn-outline-secondary btn-sm flex-fill" title="Reset">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </div>
    </form>

    <!-- Table -->
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0 bk-table">
                <thead>
                    <tr>
                        <th>Ref</th>
                        <th>Passenger</th>
                        <th>Route</th>
                        <th>Train</th>
                        <th>Journey</th>
                        <th>Seats</th>
                        <th>Fare</th>
                        <th>Status</th>
                        <th>Payment</th>
                        <th>Booked On</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($bookings)): ?>
                    <tr><td colspan="11" class="text-center py-5 text-muted">
                        <i class="bi bi-inbox fs-2 d-block mb-2"></i>No bookings found.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($bookings as $bk): ?>
                    <tr>
                        <td>
                            <a href="booking-admin.php?id=<?= $bk['booking_id'] ?>"
                               class="fw-bold text-primary text-decoration-none small">
                                <?= htmlspecialchars($bk['booking_reference']) ?>
                            </a>
                        </td>
                        <td>
                            <div class="fw-semibold small"><?= htmlspecialchars($bk['full_name']) ?></div>
                            <div class="text-muted" style="font-size:.73rem;"><?= htmlspecialchars($bk['email']) ?></div>
                        </td>
                        <td class="small">
                            <span class="text-nowrap">
                                <?= htmlspecialchars($bk['departure_city']) ?>
                                <i class="bi bi-arrow-right text-muted mx-1"></i>
                                <?= htmlspecialchars($bk['arrival_city']) ?>
                            </span>
                        </td>
                        <td class="small text-nowrap"><?= htmlspecialchars($bk['train_name']) ?></td>
                        <td class="small text-nowrap"><?= date('d M Y', strtotime($bk['journey_date'])) ?></td>
                        <td class="text-center small"><?= (int)$bk['number_of_seats'] ?></td>
                        <td class="small text-nowrap">Rs. <?= number_format($bk['total_fare'], 0) ?></td>
                        <td><span class="sp sp-<?= $bk['booking_status'] ?>"><?= ucfirst($bk['booking_status']) ?></span></td>
                        <td><span class="sp sp-<?= $bk['payment_status'] ?>"><?= ucfirst($bk['payment_status']) ?></span></td>
                        <td class="small text-nowrap text-muted"><?= date('d M Y', strtotime($bk['booking_date'])) ?></td>
                        <td>
                            <div class="d-flex gap-1 flex-nowrap">
                                <a href="booking-admin.php?id=<?= $bk['booking_id'] ?>"
                                   class="btn btn-outline-primary btn-sm py-0 px-2" title="View Details">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="booking_details.php?id=<?= $bk['booking_id'] ?>"
                                   class="btn btn-outline-secondary btn-sm py-0 px-2" title="E-Ticket">
                                    <i class="bi bi-ticket-detailed"></i>
                                </a>
                                <?php if ($bk['booking_status'] === 'pending'): ?>
                                <form method="POST" class="d-inline"
                                      onsubmit="return confirm('Confirm this booking?')">
                                    <input type="hidden" name="action" value="confirm">
                                    <input type="hidden" name="booking_id" value="<?= $bk['booking_id'] ?>">
                                    <button type="submit" class="btn btn-success btn-sm py-0 px-2" title="Confirm">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <?php if ($bk['booking_status'] !== 'cancelled'): ?>
                                <form method="POST" class="d-inline"
                                      onsubmit="return confirm('Cancel this booking?')">
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="booking_id" value="<?= $bk['booking_id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm py-0 px-2" title="Cancel">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="card-footer bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <small class="text-muted">
                Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $per_page, $total_rows)) ?>
                of <?= number_format($total_rows) ?> bookings
            </small>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <!-- Prev -->
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(bUrl(['page' => $page - 1])) ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>
                    <?php
                    $start_p = max(1, $page - 2);
                    $end_p   = min($total_pages, $page + 2);
                    for ($pp = $start_p; $pp <= $end_p; $pp++): ?>
                    <li class="page-item <?= $pp === $page ? 'active' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(bUrl(['page' => $pp])) ?>"><?= $pp ?></a>
                    </li>
                    <?php endfor; ?>
                    <!-- Next -->
                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(bUrl(['page' => $page + 1])) ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>

</section>
</div><!-- /adm-wrap -->

<?php require_once 'inc/footer.php'; ?>

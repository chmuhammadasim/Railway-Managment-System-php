<?php
// cargo-shipments.php – Admin/Employee: Cargo Shipment Management

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/AuditLog.php';

if (!User::isLoggedIn() || !in_array($_SESSION['role'], ['admin', 'employee'])) {
    header('Location: login.php'); exit();
}

$db   = new Database();
$db->connect();
$conn = $db->getConnection();

$user_obj   = new User($db);
$authUser   = $user_obj->getUserById($_SESSION['user_id']);
$is_admin   = $_SESSION['role'] === 'admin';

$success_message = '';
$error_message   = '';

// ─── Shipping fee: Rs 50/kg base + type multipliers ─────────────────────────
function calcShippingFee(float $kg, string $type): float {
    $multipliers = ['general'=>1.0,'fragile'=>1.4,'perishable'=>1.6,'livestock'=>2.0,'hazardous'=>2.5];
    return round($kg * 50 * ($multipliers[$type] ?? 1.0), 2);
}

function genTracking(): string {
    return 'CGO-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
}

// ─── POST actions ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Create new shipment ──────────────────────────────────────────────────
    if ($action === 'create') {
        $shipment_type = in_array($_POST['shipment_type'] ?? '', ['cargo_delivery','travelling']) ? $_POST['shipment_type'] : 'cargo_delivery';
        $is_travelling = $shipment_type === 'travelling';

        // For travelling: passenger fields; For cargo: sender/receiver fields
        $passenger_name = trim($_POST['passenger_name'] ?? '');
        $passenger_cnic = trim($_POST['passenger_cnic'] ?? '');
        $linked_ref     = trim($_POST['linked_booking_ref'] ?? '');

        // Sender/Receiver — for travelling, passenger is the sender
        $sender_name   = $is_travelling ? $passenger_name : trim($_POST['sender_name']  ?? '');
        $sender_phone  = $is_travelling ? trim($_POST['passenger_phone'] ?? '') : trim($_POST['sender_phone']  ?? '');
        $sender_addr   = trim($_POST['sender_address']     ?? '');
        $recv_name     = trim($_POST['receiver_name']      ?? '');
        $recv_phone    = trim($_POST['receiver_phone']     ?? '');
        $recv_addr     = trim($_POST['receiver_address']   ?? '');
        $origin        = trim($_POST['origin_city']        ?? '');
        $dest          = trim($_POST['destination_city']   ?? '');
        $route_id      = (int)($_POST['route_id']          ?? 0) ?: null;
        $weight        = (float)($_POST['weight_kg']       ?? 0);
        $cargo_type    = $_POST['cargo_type']              ?? 'general';
        $decl_val      = (float)($_POST['declared_value']  ?? 0);
        $special       = trim($_POST['special_instructions'] ?? '');
        $est_delivery  = trim($_POST['estimated_delivery']  ?? '');
        $allowed_types = ['general','fragile','perishable','livestock','hazardous'];

        if (!$sender_name || (!$is_travelling && !$recv_name) || !$origin || !$dest || $weight <= 0) {
            $error_message = $is_travelling ? 'Passenger name, cities, and weight are required.' : 'Sender, receiver, cities, and weight are required.';
        } elseif (!in_array($cargo_type, $allowed_types, true)) {
            $error_message = 'Invalid cargo type.';
        } else {
            // Travelling with cargo gets a 20% luggage discount
            $fee     = $is_travelling ? round(calcShippingFee($weight, $cargo_type) * 0.80, 2) : calcShippingFee($weight, $cargo_type);
            $track   = genTracking();
            $booked  = (int)$_SESSION['user_id'];
            $st_e    = $conn->real_escape_string($shipment_type);
            $pn_e    = $conn->real_escape_string($passenger_name);
            $pc_e    = $conn->real_escape_string($passenger_cnic);
            $lr_e    = $conn->real_escape_string($linked_ref);
            $sn_e    = $conn->real_escape_string($sender_name);
            $sp_e    = $conn->real_escape_string($sender_phone);
            $sa_e    = $conn->real_escape_string($sender_addr);
            $rn_e    = $is_travelling ? $sn_e : $conn->real_escape_string($recv_name);
            $rp_e    = $is_travelling ? $sp_e : $conn->real_escape_string($recv_phone);
            $ra_e    = $conn->real_escape_string($recv_addr);
            $or_e    = $conn->real_escape_string($origin);
            $de_e    = $conn->real_escape_string($dest);
            $ct_e    = $conn->real_escape_string($cargo_type);
            $sp2_e   = $conn->real_escape_string($special);
            $rt_sql  = $route_id ? $route_id : 'NULL';
            $ed_sql  = $est_delivery ? "'{$conn->real_escape_string($est_delivery)}'" : 'NULL';
            $tr_e    = $conn->real_escape_string($track);
            $lr_sql  = $linked_ref ? "'{$lr_e}'" : 'NULL';

            $conn->query(
                "INSERT INTO cargo_shipments
                    (tracking_number,shipment_type,passenger_name,passenger_cnic,linked_booking_ref,
                     sender_name,sender_phone,sender_address,
                     receiver_name,receiver_phone,receiver_address,
                     origin_city,destination_city,route_id,weight_kg,cargo_type,
                     declared_value,shipping_fee,special_instructions,
                     booked_by,estimated_delivery)
                 VALUES
                    ('{$tr_e}','{$st_e}','{$pn_e}','{$pc_e}',{$lr_sql},
                     '{$sn_e}','{$sp_e}','{$sa_e}',
                     '{$rn_e}','{$rp_e}','{$ra_e}',
                     '{$or_e}','{$de_e}',{$rt_sql},{$weight},'{$ct_e}',
                     {$decl_val},{$fee},'{$sp2_e}',
                     {$booked},{$ed_sql})"
            );
            $new_id = $conn->insert_id;
            $type_label = $is_travelling ? 'passenger travelling with cargo' : 'cargo delivery';
            AuditLog::log($db, 'CREATE_SHIPMENT', 'cargo', "New {$type_label} shipment {$track} created ({$origin}→{$dest}, {$weight}kg {$cargo_type})", $new_id);
            $discount_note = $is_travelling ? ' (20% luggage discount applied)' : '';
            $success_message = "Shipment created! Tracking: <strong>{$track}</strong> — Fee: <strong>Rs " . number_format($fee, 2) . "</strong>{$discount_note}";
        }
    }

    // ── Update status ────────────────────────────────────────────────────────
    elseif ($action === 'update_status') {
        $sid      = (int)($_POST['shipment_id'] ?? 0);
        $status   = $_POST['shipment_status']   ?? '';
        $pay_stat = $_POST['payment_status']    ?? '';
        $allowed  = ['pending','in_transit','arrived','delivered','cancelled'];
        $pay_ok   = ['pending','paid','refunded'];
        if ($sid && in_array($status, $allowed, true) && in_array($pay_stat, $pay_ok, true)) {
            $old = $db->selectRow("SELECT shipment_status, payment_status FROM cargo_shipments WHERE shipment_id={$sid}");
            $del_sql = ($status === 'delivered') ? ", actual_delivery=NOW()" : '';
            $conn->query("UPDATE cargo_shipments SET shipment_status='{$conn->real_escape_string($status)}', payment_status='{$conn->real_escape_string($pay_stat)}' {$del_sql} WHERE shipment_id={$sid}");
            AuditLog::log($db, 'UPDATE_SHIPMENT', 'cargo',
                "Shipment #{$sid} status changed",
                $sid,
                json_encode($old),
                json_encode(['shipment_status'=>$status,'payment_status'=>$pay_stat])
            );
            $success_message = 'Shipment updated successfully.';
        }
    }

    // ── Cancel shipment ──────────────────────────────────────────────────────
    elseif ($action === 'cancel_shipment' && $is_admin) {
        $sid = (int)($_POST['shipment_id'] ?? 0);
        if ($sid) {
            $conn->query("UPDATE cargo_shipments SET shipment_status='cancelled', payment_status='refunded' WHERE shipment_id={$sid} AND shipment_status NOT IN ('delivered','cancelled')");
            AuditLog::log($db, 'CANCEL_SHIPMENT', 'cargo', "Cargo shipment #{$sid} cancelled", $sid);
            $success_message = 'Shipment cancelled.';
        }
    }

    header('Location: cargo-shipments.php?msg=' . urlencode($success_message ?: $error_message) . '&ok=' . ($success_message ? 1 : 0));
    exit();
}

// Flash messages from redirect
if (isset($_GET['msg'])) {
    if ((int)($_GET['ok'] ?? 0)) $success_message = $_GET['msg'];
    else                          $error_message   = $_GET['msg'];
}

// ─── GET filters ─────────────────────────────────────────────────────────────
$filter_status = $_GET['status'] ?? 'all';
$filter_pay    = $_GET['pay']    ?? 'all';
$search        = trim($_GET['q'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 20;
$offset        = ($page - 1) * $per_page;

$wheres = [];
if ($filter_status !== 'all') { $wheres[] = "cs.shipment_status='{$conn->real_escape_string($filter_status)}'"; }
if ($filter_pay    !== 'all') { $wheres[] = "cs.payment_status='{$conn->real_escape_string($filter_pay)}'"; }
if ($search !== '') {
    $sq = $conn->real_escape_string($search);
    $wheres[] = "(cs.tracking_number LIKE '%{$sq}%' OR cs.sender_name LIKE '%{$sq}%' OR cs.receiver_name LIKE '%{$sq}%' OR cs.origin_city LIKE '%{$sq}%' OR cs.destination_city LIKE '%{$sq}%')";
}
$where_sql = $wheres ? 'WHERE ' . implode(' AND ', $wheres) : '';

$total_rows  = (int)($db->selectRow("SELECT COUNT(*) AS n FROM cargo_shipments cs {$where_sql}")['n'] ?? 0);
$total_pages = max(1, (int)ceil($total_rows / $per_page));

$shipments = $db->select(
    "SELECT cs.*, u.full_name AS booked_by_name
     FROM cargo_shipments cs
     LEFT JOIN users u ON cs.booked_by = u.user_id
     {$where_sql}
     ORDER BY cs.booking_date DESC
     LIMIT {$per_page} OFFSET {$offset}"
) ?: [];

// KPIs
$kpi = $db->selectRow(
    "SELECT COUNT(*) AS total,
            SUM(shipment_status='pending')    AS pending_n,
            SUM(shipment_status='in_transit') AS transit_n,
            SUM(shipment_status='delivered')  AS delivered_n,
            SUM(shipment_status='cancelled')  AS cancelled_n,
            IFNULL(SUM(shipping_fee),0)       AS total_fees,
            IFNULL(SUM(weight_kg),0)          AS total_kg
     FROM cargo_shipments"
) ?? [];

// Routes for dropdown
$routes = $db->select(
    "SELECT r.route_id, r.departure_city, r.arrival_city, r.journey_date, t.train_name
     FROM routes r JOIN trains t ON r.train_id=t.train_id
     WHERE r.status='scheduled' AND r.journey_date>=CURDATE()
     ORDER BY r.journey_date ASC LIMIT 60"
) ?: [];

$hideMainNavbar = true;
$pageTitle = 'Cargo & Luggage Desk';
require_once 'inc/header.php';
?>

<style>
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
.adm-main { flex:1; padding:2rem; overflow-x:hidden; background:#f8fafc; }
.kpi-card { border-radius:14px; border:none; box-shadow:0 1px 4px rgba(0,0,0,.07); }
.filter-bar { background:#fff; border-radius:14px; box-shadow:0 1px 6px rgba(0,0,0,.07); padding:1.25rem; margin-bottom:1.25rem; }
.cargo-table thead th { background:#0f1e32; color:#fff; font-weight:600; font-size:.8rem; white-space:nowrap; }
.cargo-table td { font-size:.83rem; vertical-align:middle; }
.cargo-table tbody tr:hover { background:#f8fafc; }
.sp { display:inline-block; padding:.2em .65em; border-radius:999px; font-size:.75rem; font-weight:600; }
.sp-pending    { background:#fef3c7; color:#92400e; }
.sp-in_transit { background:#dbeafe; color:#1e40af; }
.sp-arrived    { background:#ede9fe; color:#4c1d95; }
.sp-delivered  { background:#d1fae5; color:#065f46; }
.sp-cancelled  { background:#fee2e2; color:#7f1d1d; }
.sp-paid       { background:#d1fae5; color:#065f46; }
.sp-refunded   { background:#ede9fe; color:#4c1d95; }
.type-pill { display:inline-block; padding:.15em .55em; border-radius:6px; font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; background:#f1f5f9; color:#475569; }
.mode-btn { padding:.8rem 1rem; text-align:left; border-radius:12px; transition:all .2s; }
.mode-btn.active-mode { box-shadow:0 0 0 2.5px #f59e0b; }
.type-fragile    { background:#fef3c7; color:#92400e; }
.type-perishable { background:#d1fae5; color:#065f46; }
.type-livestock  { background:#dcfce7; color:#15803d; }
.type-hazardous  { background:#fee2e2; color:#7f1d1d; }
@media(max-width:900px) { .adm-sidebar { display:none; } .adm-main { padding:1rem; } }
</style>

<div class="adm-wrap">
<aside class="adm-sidebar">
    <div class="sb-brand">
        <span>Management Panel</span>
        <strong><i class="bi bi-train-front-fill me-1"></i> Railway Admin</strong>
    </div>
    <nav>
        <div class="sb-sep">Main</div>
        <a href="admin-dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="reports.php"><i class="bi bi-bar-chart-line"></i> Reports</a>
        <?php if ($is_admin): ?>
        <a href="audit-logs.php"><i class="bi bi-journal-text"></i> Audit Logs</a>
        <?php endif; ?>

        <div class="sb-sep">Operations</div>
        <a href="manage-trains.php"><i class="bi bi-train-front"></i> Trains</a>
        <a href="manage-routes.php"><i class="bi bi-signpost-split"></i> Routes</a>
        <a href="operations-hub.php?tab=stations"><i class="bi bi-building"></i> Stations</a>
        <a href="manage-bookings.php"><i class="bi bi-ticket-perforated"></i> Bookings</a>
        <a href="manage-payments.php"><i class="bi bi-credit-card"></i> Payments</a>
        <a href="cargo-shipments.php" class="active"><i class="bi bi-box-seam"></i> Cargo &amp; Luggage</a>
        <a href="operations-hub.php"><i class="bi bi-diagram-3"></i> Operations Hub</a>

        <div class="sb-sep">People</div>
        <?php if ($is_admin): ?><a href="manage-users.php"><i class="bi bi-people"></i> Users</a><?php endif; ?>
        <a href="notifications.php"><i class="bi bi-bell"></i> Notifications</a>

        <div class="sb-sep">Account</div>
        <a href="profile.php"><i class="bi bi-person-gear"></i> Profile</a>
        <a href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </nav>
    <div class="sb-user">
        <div class="avatar"><?= strtoupper(substr($authUser['full_name'] ?? 'U', 0, 1)) ?></div>
        <div class="info">
            <strong><?= htmlspecialchars($authUser['full_name'] ?? '') ?></strong>
            <small><?= ucfirst($_SESSION['role']) ?></small>
        </div>
    </div>
</aside>

<main class="adm-main">
    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
        <div>
            <h2 class="fw-black mb-0" style="color:#0f172a;"><i class="bi bi-box-seam me-2 text-warning"></i>Cargo &amp; Luggage Desk</h2>
            <p class="text-muted mb-0" style="font-size:.9rem;">Manage freight, parcels, and passenger luggage on Pakistan Railways trains.</p>
        </div>
        <button class="btn btn-warning fw-bold" data-bs-toggle="modal" data-bs-target="#newShipmentModal">
            <i class="bi bi-plus-circle me-1"></i>New Shipment
        </button>
    </div>

    <?php if ($success_message): ?>
    <div class="alert alert-success border-0 rounded-3 d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-check-circle-fill fs-5"></i><span><?= $success_message ?></span>
    </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
    <div class="alert alert-danger border-0 rounded-3 d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-exclamation-circle-fill fs-5"></i><span><?= htmlspecialchars($error_message) ?></span>
    </div>
    <?php endif; ?>

    <!-- KPIs -->
    <div class="row g-3 mb-4">
        <?php
        $kpis = [
            ['Total Shipments', number_format($kpi['total'] ?? 0),    'bi-box-seam',        '#2563eb','#eff6ff'],
            ['In Transit',      number_format($kpi['transit_n'] ?? 0),'bi-truck',           '#0891b2','#ecfeff'],
            ['Delivered',       number_format($kpi['delivered_n']??0),'bi-check-circle',    '#059669','#f0fdf4'],
            ['Total Freight',   number_format($kpi['total_kg'] ?? 0, 1).' kg','bi-speedometer','#d97706','#fffbeb'],
            ['Revenue',         'Rs '.number_format($kpi['total_fees']??0,0),'bi-cash-stack','#7c3aed','#f5f3ff'],
        ];
        foreach ($kpis as [$lbl,$val,$ico,$clr,$bg]):
        ?>
        <div class="col-lg col-sm-6">
            <div class="card kpi-card p-3">
                <div class="d-flex align-items-center gap-3">
                    <div style="width:44px;height:44px;border-radius:10px;background:<?= $bg ?>;display:flex;align-items:center;justify-content:center;">
                        <i class="bi <?= $ico ?>" style="font-size:1.2rem;color:<?= $clr ?>;"></i>
                    </div>
                    <div>
                        <div class="fw-black" style="font-size:1.3rem;color:#0f172a;line-height:1;"><?= $val ?></div>
                        <div style="font-size:.75rem;color:#6b7280;"><?= $lbl ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filter -->
    <form method="GET" class="filter-bar">
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="all">All statuses</option>
                    <?php foreach (['pending','in_transit','arrived','delivered','cancelled'] as $s): ?>
                    <option value="<?= $s ?>" <?= $filter_status===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Payment</label>
                <select name="pay" class="form-select form-select-sm">
                    <option value="all">All</option>
                    <?php foreach (['pending','paid','refunded'] as $s): ?>
                    <option value="<?= $s ?>" <?= $filter_pay===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Search</label>
                <input type="text" name="q" class="form-control form-control-sm"
                       placeholder="Tracking, sender, receiver, city…" value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="cargo-shipments.php" class="btn btn-outline-secondary btn-sm">✕</a>
            </div>
        </div>
    </form>

    <!-- Table -->
    <div class="card shadow-sm" style="border-radius:14px;overflow:hidden;">
        <div class="card-header bg-white py-3 d-flex justify-content-between">
            <span class="fw-bold"><?= number_format($total_rows) ?> shipments</span>
            <span class="text-muted" style="font-size:.8rem;">Page <?= $page ?>/<?= $total_pages ?></span>
        </div>
        <div class="table-responsive">
        <table class="table table-hover mb-0 cargo-table">
            <thead>
                <tr>
                    <th>Tracking</th>
                    <th>Mode</th>
                    <th>From → To</th>
                    <th>Sender / Passenger</th>
                    <th>Receiver</th>
                    <th>Cargo Type</th>
                    <th>Weight</th>
                    <th>Fee</th>
                    <th>Status</th>
                    <th>Payment</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($shipments)): ?>
                <tr><td colspan="11" class="text-center py-5 text-muted">No shipments found.</td></tr>
            <?php else: foreach ($shipments as $s): ?>
                <?php $is_travel_row = ($s['shipment_type'] ?? 'cargo_delivery') === 'travelling'; ?>
                <tr>
                    <td><code style="font-size:.78rem;color:#1e40af;"><?= htmlspecialchars($s['tracking_number']) ?></code><br>
                        <span style="font-size:.7rem;color:#6b7280;"><?= date('d M Y', strtotime($s['booking_date'])) ?></span>
                    </td>
                    <td>
                        <?php if ($is_travel_row): ?>
                        <span class="badge" style="background:#7c3aed;font-size:.72rem;"><i class="bi bi-person-fill me-1"></i>Travelling</span>
                        <?php if ($s['linked_booking_ref']): ?>
                        <br><span style="font-size:.7rem;color:#7c3aed;"><?= htmlspecialchars($s['linked_booking_ref']) ?></span>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="badge bg-warning text-dark" style="font-size:.72rem;"><i class="bi bi-box-seam me-1"></i>Cargo</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="fw-semibold"><?= htmlspecialchars($s['origin_city']) ?></span>
                        <i class="bi bi-arrow-right text-muted mx-1" style="font-size:.7rem;"></i>
                        <span class="fw-semibold"><?= htmlspecialchars($s['destination_city']) ?></span>
                    </td>
                    <td>
                        <?= htmlspecialchars($s['sender_name']) ?>
                        <?php if ($is_travel_row && $s['passenger_cnic']): ?>
                        <br><span style="font-size:.7rem;color:#7c3aed;"><i class="bi bi-credit-card-2-front me-1"></i><?= htmlspecialchars($s['passenger_cnic']) ?></span>
                        <?php else: ?>
                        <br><span style="font-size:.72rem;color:#6b7280;"><?= htmlspecialchars($s['sender_phone'] ?? '') ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($is_travel_row): ?>
                        <span style="font-size:.78rem;color:#9ca3af;">— same person —</span>
                        <?php else: ?>
                        <?= htmlspecialchars($s['receiver_name']) ?><br>
                        <span style="font-size:.72rem;color:#6b7280;"><?= htmlspecialchars($s['receiver_phone'] ?? '') ?></span>
                        <?php endif; ?>
                    </td>
                    <td><span class="type-pill type-<?= $s['cargo_type'] ?>"><?= htmlspecialchars($s['cargo_type']) ?></span></td>
                    <td><?= number_format($s['weight_kg'], 1) ?> kg</td>
                    <td class="fw-bold">Rs <?= number_format($s['shipping_fee'], 0) ?>
                        <?php if ($is_travel_row): ?><br><span style="font-size:.68rem;color:#7c3aed;">−20% luggage</span><?php endif; ?>
                    </td>
                    <td><span class="sp sp-<?= $s['shipment_status'] ?>"><?= str_replace('_',' ',ucfirst($s['shipment_status'])) ?></span></td>
                    <td><span class="sp sp-<?= $s['payment_status'] ?>"><?= ucfirst($s['payment_status']) ?></span></td>
                    <td>
                        <button class="btn btn-outline-primary btn-sm py-0 px-2 me-1"
                                data-bs-toggle="modal" data-bs-target="#editModal"
                                data-id="<?= $s['shipment_id'] ?>"
                                data-status="<?= $s['shipment_status'] ?>"
                                data-pay="<?= $s['payment_status'] ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <?php if ($is_admin && !in_array($s['shipment_status'], ['delivered','cancelled'])): ?>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Cancel this shipment?')">
                            <input type="hidden" name="action" value="cancel_shipment">
                            <input type="hidden" name="shipment_id" value="<?= $s['shipment_id'] ?>">
                            <button class="btn btn-outline-danger btn-sm py-0 px-2"><i class="bi bi-x-circle"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
        <?php if ($total_pages > 1): ?>
        <div class="card-footer bg-white d-flex justify-content-between align-items-center py-2">
            <span class="text-muted" style="font-size:.82rem;">Showing <?= ($page-1)*$per_page+1 ?>–<?= min($page*$per_page,$total_rows) ?> of <?= $total_rows ?></span>
            <nav><ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="?page=<?= $page-1 ?>&status=<?= $filter_status ?>&pay=<?= $filter_pay ?>&q=<?= urlencode($search) ?>">‹</a></li>
                <?php for ($p=max(1,$page-2);$p<=min($total_pages,$page+2);$p++): ?>
                <li class="page-item <?= $p==$page?'active':'' ?>"><a class="page-link" href="?page=<?= $p ?>&status=<?= $filter_status ?>&pay=<?= $filter_pay ?>&q=<?= urlencode($search) ?>"><?= $p ?></a></li>
                <?php endfor; ?>
                <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>"><a class="page-link" href="?page=<?= $page+1 ?>&status=<?= $filter_status ?>&pay=<?= $filter_pay ?>&q=<?= urlencode($search) ?>">›</a></li>
            </ul></nav>
        </div>
        <?php endif; ?>
    </div>
</main>
</div>

<!-- ── New Shipment Modal ──────────────────────────────────────────────────── -->
<div class="modal fade" id="newShipmentModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" id="shipModalHeader" style="background:#f59e0b;">
                <h5 class="modal-title fw-bold" id="shipModalTitle"><i class="bi bi-box-seam me-2"></i>New Cargo Shipment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="shipment_type" id="hiddenShipType" value="cargo_delivery">
                <div class="modal-body">

                    <!-- ── Mode Toggle ──────────────────────────────────────── -->
                    <div class="d-flex gap-2 mb-4">
                        <button type="button" id="btnModeCargo" class="btn btn-warning fw-bold flex-fill mode-btn active-mode" onclick="setShipMode('cargo_delivery')">
                            <i class="bi bi-box-seam me-2"></i>Cargo Delivery
                            <div style="font-size:.72rem;font-weight:400;opacity:.8;">Goods shipped independently</div>
                        </button>
                        <button type="button" id="btnModeTravel" class="btn btn-outline-secondary fw-bold flex-fill mode-btn" onclick="setShipMode('travelling')">
                            <i class="bi bi-person-luggage me-2"></i>Travelling with Cargo
                            <div style="font-size:.72rem;font-weight:400;opacity:.8;">Passenger carrying luggage/goods &mdash; 20% discount</div>
                        </button>
                    </div>

                    <div class="row g-3">
                        <!-- ── CARGO DELIVERY: Sender ──────────────────────── -->
                        <div id="senderSection">
                            <div class="row g-3">
                                <div class="col-12"><h6 class="fw-bold text-primary border-bottom pb-1">Sender Details</h6></div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Sender Name *</label>
                                    <input type="text" name="sender_name" id="senderName" class="form-control">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Sender Phone</label>
                                    <input type="text" name="sender_phone" class="form-control" placeholder="+92-300-0000000">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Sender Address</label>
                                    <input type="text" name="sender_address" class="form-control">
                                </div>
                            </div>
                        </div>

                        <!-- ── TRAVELLING: Passenger ───────────────────────── -->
                        <div id="passengerSection" style="display:none;">
                            <div class="row g-3">
                                <div class="col-12">
                                    <h6 class="fw-bold border-bottom pb-1" style="color:#7c3aed;"><i class="bi bi-person-fill me-1"></i>Passenger Details</h6>
                                    <div class="alert alert-info py-2 mb-0" style="font-size:.83rem;">
                                        <i class="bi bi-info-circle me-1"></i>The passenger is travelling with their cargo. A <strong>20% luggage discount</strong> is applied to the shipping fee.
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Passenger Name *</label>
                                    <input type="text" name="passenger_name" id="passengerName" class="form-control">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Phone</label>
                                    <input type="text" name="passenger_phone" class="form-control" placeholder="+92-300-0000000">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">CNIC / Passport No.</label>
                                    <input type="text" name="passenger_cnic" class="form-control" placeholder="e.g. 42101-1234567-9">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Linked Booking Reference <span class="text-muted fw-normal">(optional)</span></label>
                                    <input type="text" name="linked_booking_ref" class="form-control" placeholder="e.g. RWY20250401120000001">
                                    <div class="form-text">Link to an existing passenger booking if available.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Cargo Description / Items</label>
                                    <input type="text" name="sender_address" class="form-control" placeholder="e.g. Personal luggage, merchandise">
                                </div>
                            </div>
                        </div>

                        <!-- ── Receiver (cargo only) ───────────────────────── -->
                        <div id="receiverSection">
                            <div class="row g-3">
                                <div class="col-12"><h6 class="fw-bold text-success border-bottom pb-1 mt-2">Receiver Details</h6></div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Receiver Name *</label>
                                    <input type="text" name="receiver_name" id="receiverName" class="form-control">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Receiver Phone</label>
                                    <input type="text" name="receiver_phone" class="form-control" placeholder="+92-300-0000000">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Receiver Address</label>
                                    <input type="text" name="receiver_address" class="form-control">
                                </div>
                            </div>
                        </div>

                        <!-- ── Shipment info (common) ──────────────────────── -->
                        <div class="col-12"><h6 class="fw-bold text-warning border-bottom pb-1 mt-2">Shipment Details</h6></div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Origin City *</label>
                            <input type="text" name="origin_city" class="form-control" list="city-list" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Destination City *</label>
                            <input type="text" name="destination_city" class="form-control" list="city-list" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Train Route (optional)</label>
                            <select name="route_id" class="form-select">
                                <option value="">— Select route —</option>
                                <?php foreach ($routes as $r): ?>
                                <option value="<?= $r['route_id'] ?>">
                                    <?= htmlspecialchars($r['departure_city'].' → '.$r['arrival_city'].' ('.date('d M',$r['journey_date']?strtotime($r['journey_date']):0).')') ?>
                                    [<?= htmlspecialchars($r['train_name']) ?>]
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Est. Delivery Date</label>
                            <input type="date" name="estimated_delivery" class="form-control" min="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Weight (kg) *</label>
                            <input type="number" name="weight_kg" id="weightInput" class="form-control" min="0.1" max="5000" step="0.1" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold" id="cargoTypeLabel">Cargo Type *</label>
                            <select name="cargo_type" id="cargoTypeSelect" class="form-select" required>
                                <option value="general">General (Rs 50/kg)</option>
                                <option value="fragile">Fragile (Rs 70/kg)</option>
                                <option value="perishable">Perishable (Rs 80/kg)</option>
                                <option value="livestock">Livestock (Rs 100/kg)</option>
                                <option value="hazardous">Hazardous (Rs 125/kg)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Declared Value (Rs)</label>
                            <input type="number" name="declared_value" class="form-control" min="0" step="1">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Estimated Fee</label>
                            <div id="feeDisplay" class="form-control bg-light fw-bold text-success">Rs 0</div>
                            <div id="feeDiscountNote" style="display:none;font-size:.72rem;color:#7c3aed;" class="mt-1"><i class="bi bi-tag-fill me-1"></i>20% luggage discount included</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Special Instructions</label>
                            <textarea name="special_instructions" class="form-control" rows="2" placeholder="Handling notes, temperature requirements, etc."></textarea>
                        </div>
                    </div>
                    <datalist id="city-list">
                        <?php
                        $cities = ['Karachi','Lahore','Islamabad','Rawalpindi','Peshawar','Quetta','Multan','Faisalabad','Hyderabad','Sukkur','Sialkot','Bahawalpur','Abbottabad'];
                        foreach ($cities as $c) echo "<option value=\"{$c}\">";
                        ?>
                    </datalist>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning fw-bold" id="shipSubmitBtn"><i class="bi bi-send me-1"></i>Create Shipment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Edit Status Modal ──────────────────────────────────────────────────── -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Update Shipment Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="shipment_id" id="editId">
                <div class="modal-body row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Shipment Status</label>
                        <select name="shipment_status" id="editStatus" class="form-select">
                            <?php foreach (['pending','in_transit','arrived','delivered','cancelled'] as $s): ?>
                            <option value="<?= $s ?>"><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Payment Status</label>
                        <select name="payment_status" id="editPay" class="form-select">
                            <?php foreach (['pending','paid','refunded'] as $s): ?>
                            <option value="<?= $s ?>"><?= ucfirst($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ── Shipment mode toggle ──────────────────────────────────────────────────────
var currentMode = 'cargo_delivery';
function setShipMode(mode) {
    currentMode = mode;
    document.getElementById('hiddenShipType').value = mode;
    var isTravelling = mode === 'travelling';

    // Toggle sections
    document.getElementById('senderSection').style.display   = isTravelling ? 'none' : '';
    document.getElementById('passengerSection').style.display = isTravelling ? '' : 'none';
    document.getElementById('receiverSection').style.display  = isTravelling ? 'none' : '';

    // Toggle button styles
    var btnCargo  = document.getElementById('btnModeCargo');
    var btnTravel = document.getElementById('btnModeTravel');
    if (isTravelling) {
        btnCargo.classList.remove('btn-warning','active-mode');
        btnCargo.classList.add('btn-outline-secondary');
        btnTravel.classList.remove('btn-outline-secondary');
        btnTravel.classList.add('btn-warning','active-mode');
        document.getElementById('shipModalHeader').style.background = '#7c3aed';
        document.getElementById('shipModalTitle').innerHTML = '<i class="bi bi-person-luggage me-2"></i>Travelling with Cargo';
    } else {
        btnTravel.classList.remove('btn-warning','active-mode');
        btnTravel.classList.add('btn-outline-secondary');
        btnCargo.classList.remove('btn-outline-secondary');
        btnCargo.classList.add('btn-warning','active-mode');
        document.getElementById('shipModalHeader').style.background = '#f59e0b';
        document.getElementById('shipModalTitle').innerHTML = '<i class="bi bi-box-seam me-2"></i>New Cargo Shipment';
    }

    // Toggle required attributes
    var senderName   = document.getElementById('senderName');
    var passengerName = document.getElementById('passengerName');
    var receiverName  = document.getElementById('receiverName');
    if (senderName)    senderName.required    = !isTravelling;
    if (passengerName) passengerName.required  = isTravelling;
    if (receiverName)  receiverName.required   = !isTravelling;

    // Discount note
    document.getElementById('feeDiscountNote').style.display = isTravelling ? '' : 'none';

    updateFee();
}

// Reset modal on open
document.getElementById('newShipmentModal').addEventListener('show.bs.modal', function () {
    setShipMode('cargo_delivery');
});

// ── Fee calculator ──────────────────────────────────────────────────────────
(function(){
    var rates = {general:50, fragile:70, perishable:80, livestock:100, hazardous:125};
    window.updateFee = function() {
        var w = parseFloat(document.getElementById('weightInput').value) || 0;
        var t = document.getElementById('cargoTypeSelect').value;
        var fee = Math.round(w * (rates[t] || 50) * 100) / 100;
        if (currentMode === 'travelling') fee = Math.round(fee * 0.80 * 100) / 100;
        document.getElementById('feeDisplay').textContent = 'Rs ' + fee.toLocaleString('en-PK', {minimumFractionDigits:2});
    };
    document.getElementById('weightInput').addEventListener('input', updateFee);
    document.getElementById('cargoTypeSelect').addEventListener('change', updateFee);
}());

// ── Edit modal ──────────────────────────────────────────────────────────────
document.getElementById('editModal').addEventListener('show.bs.modal', function(e) {
    var btn = e.relatedTarget;
    document.getElementById('editId').value     = btn.dataset.id;
    document.getElementById('editStatus').value = btn.dataset.status;
    document.getElementById('editPay').value     = btn.dataset.pay;
});
</script>

<?php require_once 'inc/footer.php'; ?>

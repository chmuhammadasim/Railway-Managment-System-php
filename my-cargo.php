<?php
// my-cargo.php — User: Book Cargo & Track Shipments

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/AuditLog.php';

if (!User::isLoggedIn() || $_SESSION['role'] !== 'user') {
    header('Location: login.php');
    exit();
}

$db   = new Database();
$db->connect();
$conn = $db->getConnection();

$user_id  = (int)$_SESSION['user_id'];
$user_obj = new User($db);
$user     = $user_obj->getUserById($user_id);

// ─── Shipping fee helper ─────────────────────────────────────────────────────
function calcFee(float $kg, string $type): float {
    $m = ['general'=>1.0,'fragile'=>1.4,'perishable'=>1.6,'livestock'=>2.0,'hazardous'=>2.5];
    return round($kg * 50 * ($m[$type] ?? 1.0), 2);
}
function genTrack(): string {
    return 'CGO-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
}

$success_msg = '';
$error_msg   = '';

// ─── POST: book new shipment ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'book') {
        // CSRF
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
            $error_msg = 'Invalid request. Please try again.';
        } else {
            $shipment_type = in_array($_POST['shipment_type'] ?? '', ['cargo_delivery','travelling'])
                ? $_POST['shipment_type'] : 'cargo_delivery';
            $is_travelling = $shipment_type === 'travelling';

            $passenger_name  = trim($_POST['passenger_name'] ?? '');
            $passenger_cnic  = trim($_POST['passenger_cnic'] ?? '');
            $linked_ref      = trim($_POST['linked_booking_ref'] ?? '');
            $passenger_phone = trim($_POST['passenger_phone'] ?? '');

            $sender_name  = $is_travelling ? $passenger_name : trim($_POST['sender_name'] ?? '');
            $sender_phone = $is_travelling ? $passenger_phone : trim($_POST['sender_phone'] ?? '');
            $sender_addr  = trim($_POST['sender_address'] ?? '');
            $recv_name    = $is_travelling ? $sender_name  : trim($_POST['receiver_name'] ?? '');
            $recv_phone   = $is_travelling ? $sender_phone : trim($_POST['receiver_phone'] ?? '');
            $recv_addr    = trim($_POST['receiver_address'] ?? '');
            $origin       = trim($_POST['origin_city'] ?? '');
            $dest         = trim($_POST['destination_city'] ?? '');
            $route_id     = (int)($_POST['route_id'] ?? 0) ?: null;
            $weight       = (float)($_POST['weight_kg'] ?? 0);
            $cargo_type   = $_POST['cargo_type'] ?? 'general';
            $decl_val     = (float)($_POST['declared_value'] ?? 0);
            $special      = trim($_POST['special_instructions'] ?? '');
            $est_del      = trim($_POST['estimated_delivery'] ?? '');
            $ok_types     = ['general','fragile','perishable','livestock','hazardous'];

            if (!$sender_name || !$origin || !$dest || $weight <= 0) {
                $error_msg = $is_travelling
                    ? 'Passenger name, cities, and weight are required.'
                    : 'Sender name, cities, and weight are required.';
            } elseif (!in_array($cargo_type, $ok_types, true)) {
                $error_msg = 'Invalid cargo type.';
            } elseif (!$is_travelling && !$recv_name) {
                $error_msg = 'Receiver name is required for cargo delivery.';
            } else {
                $fee   = $is_travelling
                    ? round(calcFee($weight, $cargo_type) * 0.80, 2)
                    : calcFee($weight, $cargo_type);
                $track = genTrack();

                $st_e   = $conn->real_escape_string($shipment_type);
                $pn_e   = $conn->real_escape_string($passenger_name);
                $pc_e   = $conn->real_escape_string($passenger_cnic);
                $lr_e   = $conn->real_escape_string($linked_ref);
                $sn_e   = $conn->real_escape_string($sender_name);
                $sp_e   = $conn->real_escape_string($sender_phone);
                $sa_e   = $conn->real_escape_string($sender_addr);
                $rn_e   = $conn->real_escape_string($recv_name);
                $rp_e   = $conn->real_escape_string($recv_phone);
                $ra_e   = $conn->real_escape_string($recv_addr);
                $or_e   = $conn->real_escape_string($origin);
                $de_e   = $conn->real_escape_string($dest);
                $ct_e   = $conn->real_escape_string($cargo_type);
                $sp2_e  = $conn->real_escape_string($special);
                $tr_e   = $conn->real_escape_string($track);
                $rt_sql = $route_id ? $route_id : 'NULL';
                $ed_sql = $est_del ? "'{$conn->real_escape_string($est_del)}'" : 'NULL';
                $lr_sql = $linked_ref ? "'{$lr_e}'" : 'NULL';

                $ok = $conn->query(
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
                         {$user_id},{$ed_sql})"
                );
                if ($ok) {
                    $new_id = $conn->insert_id;
                    $type_label = $is_travelling ? 'passenger travelling with cargo' : 'cargo delivery';
                    AuditLog::log($db, 'BOOK_CARGO', 'cargo',
                        "User #{$user_id} booked {$type_label} shipment {$track} ({$origin}→{$dest}, {$weight}kg {$cargo_type})",
                        $new_id);
                    $discount = $is_travelling ? ' (20% luggage discount applied)' : '';
                    $success_msg = "Shipment booked! Tracking: <strong>{$track}</strong> — Fee: <strong>Rs " . number_format($fee, 2) . "</strong>{$discount}";
                } else {
                    $error_msg = 'Failed to create shipment. Please try again.';
                }
            }
        }
    }

    // Flash via redirect so refresh doesn't resubmit
    if ($success_msg || $error_msg) {
        $_SESSION['cargo_flash'] = ['ok' => (bool)$success_msg, 'msg' => $success_msg ?: $error_msg];
        header('Location: my-cargo.php');
        exit();
    }
}

// Flash messages
if (isset($_SESSION['cargo_flash'])) {
    $flash = $_SESSION['cargo_flash'];
    if ($flash['ok']) $success_msg = $flash['msg'];
    else              $error_msg   = $flash['msg'];
    unset($_SESSION['cargo_flash']);
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ─── My shipments ────────────────────────────────────────────────────────────
$filter_status = $_GET['status'] ?? 'all';
$search        = trim($_GET['q'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 10;
$offset        = ($page - 1) * $per_page;

$wheres = ["cs.booked_by = {$user_id}"];
if ($filter_status !== 'all') {
    $wheres[] = "cs.shipment_status = '{$conn->real_escape_string($filter_status)}'";
}
if ($search !== '') {
    $sq = $conn->real_escape_string($search);
    $wheres[] = "(cs.tracking_number LIKE '%{$sq}%' OR cs.origin_city LIKE '%{$sq}%' OR cs.destination_city LIKE '%{$sq}%' OR cs.receiver_name LIKE '%{$sq}%')";
}
$where_sql = 'WHERE ' . implode(' AND ', $wheres);

$total_rows  = (int)($db->selectRow("SELECT COUNT(*) AS n FROM cargo_shipments cs {$where_sql}")['n'] ?? 0);
$total_pages = max(1, (int)ceil($total_rows / $per_page));

$my_shipments = $db->select(
    "SELECT cs.*
     FROM cargo_shipments cs
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
            IFNULL(SUM(shipping_fee),0)        AS total_fees
     FROM cargo_shipments WHERE booked_by = {$user_id}"
) ?? [];

// Routes for dropdown (scheduled future routes)
$routes = $db->select(
    "SELECT r.route_id, r.departure_city, r.arrival_city, r.journey_date, t.train_name
     FROM routes r JOIN trains t ON r.train_id = t.train_id
     WHERE r.status='scheduled' AND r.journey_date >= CURDATE()
     ORDER BY r.journey_date ASC LIMIT 60"
) ?: [];

// My booking refs for the "link a booking" field
$my_booking_refs = $db->select(
    "SELECT b.booking_reference, r.departure_city, r.arrival_city, r.journey_date
     FROM bookings b JOIN routes r ON b.route_id = r.route_id
     WHERE b.user_id = {$user_id} AND b.booking_status = 'confirmed'
     ORDER BY r.journey_date DESC LIMIT 20"
) ?: [];

$status_colors = [
    'pending'    => ['bg'=>'#fef3c7','col'=>'#92400e','icon'=>'bi-clock'],
    'in_transit' => ['bg'=>'#dbeafe','col'=>'#1e40af','icon'=>'bi-truck'],
    'arrived'    => ['bg'=>'#d1fae5','col'=>'#065f46','icon'=>'bi-geo-alt-fill'],
    'delivered'  => ['bg'=>'#dcfce7','col'=>'#15803d','icon'=>'bi-check-circle-fill'],
    'cancelled'  => ['bg'=>'#fee2e2','col'=>'#991b1b','icon'=>'bi-x-circle-fill'],
];
$cities = ['Karachi','Lahore','Islamabad','Rawalpindi','Peshawar','Quetta','Multan','Faisalabad','Hyderabad','Sukkur','Sialkot','Bahawalpur','Abbottabad'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cargo &amp; Luggage – Railway System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="public/css/style.css">
    <style>
        body { background: #0b1728; min-height: 100vh; color: #1e293b; }
        .site-nav { background: rgba(0,0,0,.35); backdrop-filter: blur(10px); border-bottom: 1px solid rgba(255,255,255,.08); }
        .hero-band { background: linear-gradient(135deg, #071428 0%, #0d1e3e 50%, #162d58 100%); padding: 3.5rem 0 5.5rem; position: relative; }
        .hero-band h1 { color: #fff; font-size: 2.1rem; font-weight: 900; letter-spacing: -.5px; }
        .hero-band h1 i { background: linear-gradient(135deg,#f59e0b,#fbbf24); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .hero-meta { color: rgba(255,255,255,.55); font-size: .88rem; }
        /* KPI Chips */
        .stat-chip { background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.15); border-radius: 14px; padding: .85rem 1rem; color: #fff; text-align: center; position: relative; overflow: hidden; transition: background .2s, transform .15s; cursor: default; }
        .stat-chip:hover { background: rgba(255,255,255,.14); transform: translateY(-2px); }
        .stat-chip .chip-icon { font-size: 2.4rem; opacity: .12; position: absolute; right: .5rem; top: 50%; transform: translateY(-50%); pointer-events: none; }
        .stat-chip .num { font-size: 1.6rem; font-weight: 900; line-height: 1; position: relative; }
        .stat-chip .lbl { font-size: .67rem; text-transform: uppercase; letter-spacing: .6px; opacity: .65; margin-top: .25rem; position: relative; }
        .wave-div { line-height: 0; background: linear-gradient(135deg, #071428 0%, #0d1e3e 50%, #162d58 100%); }
        .wave-div svg { display: block; }
        .content-area { background: #eef2f7; padding-bottom: 4rem; }
        /* Mode toggle */
        .mode-btn { padding: .9rem 1rem; text-align: left; border-radius: 12px; transition: all .2s; }
        .mode-btn.active-mode { box-shadow: 0 0 0 2.5px #f59e0b; }
        /* Filter card */
        .filter-card { background: #fff; border-radius: 14px; padding: .9rem 1.1rem; box-shadow: 0 2px 12px rgba(0,0,0,.07); margin-bottom: 1.25rem; }
        /* Shipment card */
        .ship-card { background: #fff; border-radius: 16px; box-shadow: 0 2px 16px rgba(0,0,0,.07); overflow: hidden; margin-bottom: 1rem; transition: box-shadow .2s, transform .2s; border-left: 4px solid #e5e7eb; }
        .ship-card:hover { box-shadow: 0 8px 32px rgba(0,0,0,.13); transform: translateY(-2px); }
        .ship-card-top { display: flex; justify-content: space-between; align-items: flex-start; padding: 1.1rem 1.4rem .75rem; border-bottom: 1px solid #f1f5f9; flex-wrap: wrap; gap: .6rem; }
        .ship-route { font-size: 1.1rem; font-weight: 800; color: #0b1728; display: flex; align-items: center; gap: .3rem; }
        .route-arrow { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; background: linear-gradient(135deg,#059669,#10b981); color: #fff; font-size: .65rem; flex-shrink: 0; margin: 0 .15rem; box-shadow: 0 2px 6px rgba(16,185,129,.35); }
        .ship-card-body { padding: .9rem 1.4rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: .75rem; }
        .s-cell .lbl { font-size: .64rem; text-transform: uppercase; letter-spacing: .4px; color: #94a3b8; display: flex; align-items: center; gap: .25rem; margin-bottom: .18rem; }
        .s-cell .val { font-size: .87rem; font-weight: 600; color: #1e293b; }
        .ship-card-footer { padding: .75rem 1.4rem; background: #f8fafc; border-top: 1px solid #f1f5f9; display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
        /* Tracking code */
        .track-code { font-family: 'Courier New', monospace; font-size: .82rem; font-weight: 700; color: #1e40af; background: #eff6ff; padding: .22em .7em; border-radius: 7px; border: 1px solid #bfdbfe; cursor: pointer; transition: background .15s; user-select: none; }
        .track-code:hover { background: #dbeafe; }
        /* Status badge */
        .s-pill { display: inline-flex; align-items: center; gap: .3rem; padding: .3em .95em; border-radius: 999px; font-weight: 700; font-size: .75rem; white-space: nowrap; }
        /* Progress tracker */
        .track-progress { display: flex; align-items: flex-start; padding: .8rem 1.4rem .65rem; gap: 0; overflow-x: auto; }
        .tp-step { display: flex; flex-direction: column; align-items: center; flex: 1; min-width: 64px; position: relative; }
        .tp-step:not(:last-child)::after { content: ''; position: absolute; top: 13px; left: 50%; width: 100%; height: 2.5px; background: #e5e7eb; z-index: 0; border-radius: 2px; }
        .tp-step.done::after { background: linear-gradient(90deg,#10b981,#34d399); }
        .tp-dot { width: 28px; height: 28px; border-radius: 50%; border: 2.5px solid #e5e7eb; background: #fff; display: flex; align-items: center; justify-content: center; font-size: .7rem; z-index: 1; position: relative; box-shadow: 0 2px 6px rgba(0,0,0,.08); }
        .tp-step.done .tp-dot { background: #10b981; border-color: #10b981; color: #fff; box-shadow: 0 2px 8px rgba(16,185,129,.3); }
        .tp-step.current .tp-dot { background: #3b82f6; border-color: #3b82f6; color: #fff; animation: pulse-dot .9s ease-in-out infinite; box-shadow: 0 2px 8px rgba(59,130,246,.3); }
        @keyframes pulse-dot { 0%,100%{box-shadow:0 0 0 0 rgba(59,130,246,.45)} 50%{box-shadow:0 0 0 7px rgba(59,130,246,0)} }
        .tp-label { font-size: .62rem; color: #9ca3af; margin-top: .35rem; text-align: center; white-space: nowrap; }
        .tp-step.done .tp-label, .tp-step.current .tp-label { color: #0f172a; font-weight: 700; }
        /* Type badge */
        .mode-badge-cargo { background: #fef3c7; color: #92400e; border-radius: 20px; padding: .22em .85em; font-size: .73rem; font-weight: 700; }
        .mode-badge-travel { background: #ede9fe; color: #5b21b6; border-radius: 20px; padding: .22em .85em; font-size: .73rem; font-weight: 700; }
        /* Filter tabs */
        .filter-tab { padding: .38rem 1rem; border-radius: 20px; font-size: .8rem; font-weight: 600; border: 1.5px solid #e2e8f0; background: #f8fafc; color: #64748b; cursor: pointer; text-decoration: none; transition: all .15s; }
        .filter-tab:hover { border-color: #1a3a6e; color: #1a3a6e; background: #fff; }
        .filter-tab.active { background: #1a3a6e; border-color: #1a3a6e; color: #fff; box-shadow: 0 2px 8px rgba(26,58,110,.25); }
        /* Empty state */
        .empty-state { text-align: center; padding: 4rem 2rem; background: #fff; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,.06); }
        .empty-icon-ring { width: 90px; height: 90px; border-radius: 50%; background: linear-gradient(135deg,#eff6ff,#dbeafe); display: inline-flex; align-items: center; justify-content: center; font-size: 2.6rem; color: #3b82f6; margin-bottom: 1.25rem; box-shadow: 0 4px 20px rgba(59,130,246,.15); }
        /* Quote box for fee */
        .quote-box { background: linear-gradient(135deg,#ecfdf5,#d1fae5); border: 1.5px solid #6ee7b7; border-radius: 12px; padding: .85rem 1rem; display: flex; flex-direction: column; justify-content: center; min-height: 72px; }
        .quote-box .fee-label { font-size: .65rem; text-transform: uppercase; letter-spacing: .5px; color: #065f46; font-weight: 700; margin-bottom: .2rem; }
        .quote-box .fee-big { font-size: 1.5rem; font-weight: 900; color: #059669; line-height: 1.1; }
        .quote-box .discount-tag { font-size: .7rem; color: #7c3aed; font-weight: 700; margin-top: .25rem; }
        /* Modal section headers */
        .section-head { display: flex; align-items: center; gap: .55rem; padding: .5rem .75rem; border-radius: 8px; margin-bottom: .75rem; font-weight: 700; font-size: .8rem; text-transform: uppercase; letter-spacing: .4px; }
        .section-head-blue   { background: #eff6ff; color: #1e40af; }
        .section-head-green  { background: #f0fdf4; color: #15803d; }
        .section-head-amber  { background: #fefce8; color: #92400e; }
        .section-head-purple { background: #faf5ff; color: #5b21b6; }
        /* Cargo type visual cards */
        .cargo-type-grid { display: grid; grid-template-columns: repeat(5,1fr); gap: .5rem; }
        @media(max-width:575px){ .cargo-type-grid { grid-template-columns: repeat(3,1fr); } }
        .cargo-card { cursor: pointer; position: relative; }
        .cargo-card input[type=radio] { position: absolute; opacity: 0; width: 0; height: 0; pointer-events: none; }
        .cargo-card-inner { border: 2px solid #e2e8f0; border-radius: 10px; padding: .8rem .5rem; text-align: center; transition: all .18s; background: #f8fafc; height: 100%; }
        .cargo-card:hover .cargo-card-inner { border-color: #94a3b8; background: #f1f5f9; }
        .cargo-card input:checked ~ .cargo-card-inner { border-color: #f59e0b; background: #fefce8; box-shadow: 0 0 0 3px rgba(245,158,11,.15); }
        .ct-icon { font-size: 1.55rem; display: block; margin-bottom: .3rem; }
        .ct-name { font-size: .73rem; font-weight: 700; color: #1e293b; }
        .ct-rate { font-size: .63rem; color: #64748b; margin-top: .1rem; }
        /* Booking summary box */
        .sum-box { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; overflow: hidden; }
        .sum-row { display: flex; justify-content: space-between; align-items: center; padding: .42rem .9rem; border-bottom: 1px solid #f1f5f9; font-size: .83rem; }
        .sum-row:last-child { border-bottom: none; padding: .6rem .9rem; background: linear-gradient(135deg,#ecfdf5,#d1fae5); }
        .sum-lbl { color: #64748b; }
        .sum-val { font-weight: 700; color: #1e293b; max-width: 60%; text-align: right; }
        .char-counter { font-size: .69rem; color: #94a3b8; }
        .char-counter.at-limit { color: #dc2626; font-weight: 700; }
        .fee-breakdown { font-size: .68rem; color: #065f46; margin-top: .2rem; font-weight: 600; }
        /* ── Booking Wizard ───────────────────────────────────────────── */
        .wiz-bar { display:flex; align-items:flex-start; padding:.3rem 0 1.25rem; gap:0; }
        .wiz-step { display:flex; flex-direction:column; align-items:center; flex:1; position:relative; }
        .wiz-step:not(:last-child)::after { content:''; position:absolute; top:17px; left:calc(50% + 18px); right:calc(-50% + 18px); height:2.5px; background:#e2e8f0; z-index:0; border-radius:2px; transition:background .3s; }
        .wiz-step.done::after { background:linear-gradient(90deg,#10b981,#34d399); }
        .wiz-dot { width:34px; height:34px; border-radius:50%; border:2.5px solid #e2e8f0; background:#f8fafc; display:flex; align-items:center; justify-content:center; font-size:.82rem; font-weight:800; color:#94a3b8; z-index:1; position:relative; transition:all .25s; flex-shrink:0; }
        .wiz-step.active .wiz-dot { background:#f59e0b; border-color:#f59e0b; color:#fff; box-shadow:0 0 0 4px rgba(245,158,11,.2); }
        .wiz-step.done .wiz-dot { background:#10b981; border-color:#10b981; color:#fff; }
        .wiz-step-label { font-size:.62rem; color:#94a3b8; margin-top:.35rem; text-align:center; font-weight:600; text-transform:uppercase; letter-spacing:.3px; }
        .wiz-step.active .wiz-step-label { color:#b45309; }
        .wiz-step.done .wiz-step-label { color:#10b981; }
        .wiz-panel { display:none; animation:wizIn .22s ease; }
        .wiz-panel.active { display:block; }
        @keyframes wizIn { from{opacity:0;transform:translateY(7px)} to{opacity:1;transform:translateY(0)} }
        /* Mode selection cards */
        .mode-sel-card { cursor:pointer; border:2.5px solid #e2e8f0; border-radius:16px; padding:1.5rem 1.1rem; text-align:center; transition:all .2s; background:#f8fafc; position:relative; height:100%; }
        .mode-sel-card:hover { border-color:#f59e0b; background:#fefce8; }
        .mode-sel-card.selected { border-color:#f59e0b; background:#fefce8; box-shadow:0 0 0 4px rgba(245,158,11,.15); }
        .mode-sel-card.selected-travel { border-color:#7c3aed; background:#faf5ff; box-shadow:0 0 0 4px rgba(124,58,237,.12); }
        .mode-sel-icon { font-size:2.8rem; margin-bottom:.55rem; display:block; }
        .mode-sel-title { font-size:1.05rem; font-weight:800; color:#1e293b; }
        .mode-sel-desc { font-size:.79rem; color:#6b7280; margin-top:.35rem; line-height:1.45; }
        .mode-sel-badge { position:absolute; top:.65rem; right:.75rem; font-size:.64rem; font-weight:700; padding:.22em .75em; border-radius:999px; background:#7c3aed; color:#fff; }
        /* Weight chips */
        .wt-chips { display:flex; gap:.4rem; flex-wrap:wrap; margin-bottom:.5rem; }
        .wt-chip { padding:.28rem .85rem; border-radius:20px; border:1.5px solid #e2e8f0; background:#f8fafc; font-size:.75rem; font-weight:700; cursor:pointer; transition:all .15s; color:#64748b; user-select:none; }
        .wt-chip:hover { border-color:#f59e0b; color:#92400e; background:#fefce8; }
        .wt-chip.active { background:#f59e0b; border-color:#f59e0b; color:#fff; }
        /* Cargo type description line */
        .ct-desc { font-size:.6rem; color:#6b7280; margin-top:.15rem; line-height:1.3; }
        /* Review table */
        .review-table { width:100%; border-collapse:separate; border-spacing:0; }
        .review-table td { padding:.42rem .75rem; font-size:.84rem; border-bottom:1px solid #f1f5f9; vertical-align:top; }
        .review-table tr:last-child td { border-bottom:none; }
        .rtl { color:#64748b; width:42%; white-space:nowrap; }
        .rtv { font-weight:700; color:#1e293b; }
        .review-total-row td { background:linear-gradient(135deg,#ecfdf5,#d1fae5) !important; }
        .review-total-row .rtv { color:#059669; font-size:1rem; }
        /* Field validation */
        .field-err-msg { color:#dc2626; font-size:.73rem; margin-top:.25rem; display:none; }
        .field-err-msg.show { display:block; }
        input.field-err, select.field-err, textarea.field-err { border-color:#ef4444 !important; }
    </style>
</head>
<body>

<!-- ── Navbar ──────────────────────────────────────────────────────────────── -->
<nav class="site-nav navbar navbar-expand-lg navbar-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="index.php">
            <i class="bi bi-train-front-fill me-2 text-warning"></i>Railway System
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav ms-auto align-items-center gap-1">
                <li class="nav-item"><a class="nav-link" href="index.php"><i class="bi bi-house me-1"></i>Home</a></li>
                <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a></li>
                <li class="nav-item"><a class="nav-link" href="bookings.php"><i class="bi bi-ticket-perforated me-1"></i>My Bookings</a></li>
                <li class="nav-item"><a class="nav-link active" href="my-cargo.php"><i class="bi bi-box-seam me-1"></i>Cargo &amp; Luggage</a></li>
                <li class="nav-item"><a class="nav-link" href="profile.php"><i class="bi bi-person me-1"></i>Profile</a></li>
                <li class="nav-item ms-1"><a class="btn btn-danger btn-sm" href="logout.php">Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<!-- ── Hero band ──────────────────────────────────────────────────────────── -->
<div class="hero-band">
    <div class="container">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
            <div>
                <h1><i class="bi bi-box-seam me-2"></i>My Cargo</h1>
                <p class="hero-meta mb-0">Book shipments, send luggage, and track your cargo in real-time</p>
            </div>
            <button class="btn btn-warning fw-bold align-self-start" data-bs-toggle="modal" data-bs-target="#bookModal">
                <i class="bi bi-plus-circle me-2"></i>Book New Shipment
            </button>
        </div>
        <!-- KPI chips -->
        <div class="row g-2">
            <div class="col-6 col-sm-4 col-md-2">
                <div class="stat-chip">
                    <i class="bi bi-boxes chip-icon"></i>
                    <div class="num"><?= (int)($kpi['total'] ?? 0) ?></div>
                    <div class="lbl">Total</div>
                </div>
            </div>
            <div class="col-6 col-sm-4 col-md-2">
                <div class="stat-chip" style="border-color:rgba(251,191,36,.4)">
                    <i class="bi bi-clock chip-icon" style="color:#fbbf24;opacity:.25"></i>
                    <div class="num" style="color:#fbbf24"><?= (int)($kpi['pending_n'] ?? 0) ?></div>
                    <div class="lbl">Pending</div>
                </div>
            </div>
            <div class="col-6 col-sm-4 col-md-2">
                <div class="stat-chip" style="border-color:rgba(96,165,250,.4)">
                    <i class="bi bi-truck chip-icon" style="color:#60a5fa;opacity:.25"></i>
                    <div class="num" style="color:#60a5fa"><?= (int)($kpi['transit_n'] ?? 0) ?></div>
                    <div class="lbl">In Transit</div>
                </div>
            </div>
            <div class="col-6 col-sm-4 col-md-2">
                <div class="stat-chip" style="border-color:rgba(52,211,153,.4)">
                    <i class="bi bi-check-circle chip-icon" style="color:#34d399;opacity:.25"></i>
                    <div class="num" style="color:#34d399"><?= (int)($kpi['delivered_n'] ?? 0) ?></div>
                    <div class="lbl">Delivered</div>
                </div>
            </div>
            <div class="col-6 col-sm-4 col-md-4">
                <div class="stat-chip" style="border-color:rgba(167,243,208,.35)">
                    <i class="bi bi-wallet2 chip-icon" style="color:#a7f3d0;opacity:.3"></i>
                    <div class="num" style="color:#a7f3d0">Rs&nbsp;<?= number_format((float)($kpi['total_fees'] ?? 0), 0) ?></div>
                    <div class="lbl">Total Fees</div>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="wave-div">
    <svg viewBox="0 0 1440 70" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none">
        <path d="M0,40 C360,80 1080,0 1440,40 L1440,70 L0,70 Z" fill="#eef2f7"/>
    </svg>
</div>

<!-- ── Main content ────────────────────────────────────────────────────────── -->
<div class="content-area">
    <div class="container py-4">

        <!-- Flash messages -->
        <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show mb-4 d-flex align-items-center" role="alert">
            <i class="bi bi-check-circle-fill me-2 fs-5 flex-shrink-0"></i>
            <div><?= $success_msg ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error_msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- ── Filters ──────────────────────────────────────────────────── -->
        <div class="filter-card">
            <form class="d-flex gap-2 flex-wrap align-items-center" method="GET">
                <div class="d-flex gap-1 flex-wrap">
                    <?php
                    $statuses = ['all'=>'All','pending'=>'Pending','in_transit'=>'In Transit','arrived'=>'Arrived','delivered'=>'Delivered','cancelled'=>'Cancelled'];
                    foreach ($statuses as $s => $lbl):
                    ?>
                    <a href="?status=<?= $s ?>&q=<?= urlencode($search) ?>"
                       class="filter-tab <?= $filter_status === $s ? 'active' : '' ?>"><?= $lbl ?></a>
                    <?php endforeach; ?>
                </div>
                <div class="position-relative">
                    <i class="bi bi-search position-absolute" style="left:.8rem;top:50%;transform:translateY(-50%);color:#9ca3af;"></i>
                    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                           class="form-control form-control-sm" style="padding-left:2.2rem;min-width:200px;"
                           placeholder="Search tracking, city…">
                    <input type="hidden" name="status" value="<?= $filter_status ?>">
                </div>
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Filter</button>
                <?php if ($search || $filter_status !== 'all'): ?>
                <a href="my-cargo.php" class="btn btn-outline-secondary btn-sm">✕ Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- ── Shipment cards ──────────────────────────────────────────── -->
        <?php if (empty($my_shipments)): ?>
        <div class="empty-state">
            <div class="empty-icon-ring"><i class="bi bi-box-seam"></i></div>
            <h5 class="fw-bold" style="color:#1e293b;">No shipments found</h5>
            <?php if ($filter_status === 'all' && !$search): ?>
            <p class="text-muted mb-3">You haven't booked any cargo shipments yet.</p>
            <button class="btn btn-warning fw-bold" data-bs-toggle="modal" data-bs-target="#bookModal">
                <i class="bi bi-plus-circle me-2"></i>Book Your First Shipment
            </button>
            <?php else: ?>
            <p class="text-muted mb-0">No shipments match your current filter.</p>
            <?php endif; ?>
        </div>
        <?php else: ?>

        <?php foreach ($my_shipments as $s):
            $is_travel = ($s['shipment_type'] ?? 'cargo_delivery') === 'travelling';
            $st        = $s['shipment_status'];
            $sc        = $status_colors[$st] ?? $status_colors['pending'];
            // Progress steps
            $steps      = ['pending','in_transit','arrived','delivered'];
            $step_index = array_search($st, $steps);
            $disc = ($s['shipping_fee'] > 0 && $is_travel) ? '' : '';
        ?>
        <div class="ship-card" style="border-left-color:<?= $sc['col'] ?>">
            <!-- Top band -->
            <div class="ship-card-top">
                <div>
                    <div class="ship-route">
                        <?= htmlspecialchars($s['origin_city']) ?>
                        <span class="route-arrow"><i class="bi bi-arrow-right"></i></span>
                        <?= htmlspecialchars($s['destination_city']) ?>
                    </div>
                    <div class="mt-1 d-flex flex-wrap gap-1">
                        <span class="track-code" onclick="copyTrack(this,'<?= htmlspecialchars($s['tracking_number']) ?>')" title="Click to copy tracking number"><i class="bi bi-clipboard me-1"></i><?= htmlspecialchars($s['tracking_number']) ?></span>
                        <?php if ($is_travel): ?>
                        <span class="mode-badge-travel"><i class="bi bi-person-fill me-1"></i>Travelling with Cargo</span>
                        <?php else: ?>
                        <span class="mode-badge-cargo"><i class="bi bi-box-seam me-1"></i>Cargo Delivery</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="text-end">
                    <span class="s-pill" style="background:<?= $sc['bg'] ?>;color:<?= $sc['col'] ?>;">
                        <i class="bi <?= $sc['icon'] ?>"></i>
                        <?= str_replace('_',' ',ucfirst($st)) ?>
                    </span>
                    <div style="font-size:.73rem;color:#9ca3af;margin-top:.3rem;"><?= date('d M Y', strtotime($s['booking_date'])) ?></div>
                </div>
            </div>

            <!-- Progress bar (only for non-cancelled) -->
            <?php if ($st !== 'cancelled'): ?>
            <div class="track-progress">
                    <?php
                    $step_labels = ['pending'=>'Pending','in_transit'=>'In Transit','arrived'=>'Arrived','delivered'=>'Delivered'];
                    $step_icons  = ['pending'=>'bi-clock','in_transit'=>'bi-truck','arrived'=>'bi-geo-alt-fill','delivered'=>'bi-check-circle-fill'];
                    foreach ($steps as $i => $sv):
                        $done    = ($step_index !== false && $i < $step_index);
                        $current = ($sv === $st);
                    ?>
                    <div class="tp-step <?= $done ? 'done' : ($current ? 'current' : '') ?>">
                        <div class="tp-dot">
                            <?php if ($done): ?><i class="bi bi-check-lg"></i>
                            <?php elseif ($current): ?><i class="bi <?= $step_icons[$sv] ?>"></i>
                            <?php else: ?><span style="width:8px;height:8px;border-radius:50%;background:#d1d5db;display:block;"></span>
                            <?php endif; ?>
                        </div>
                        <div class="tp-label"><?= $step_labels[$sv] ?></div>
                    </div>
                    <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Info cells -->
            <div class="ship-card-body">
                <?php if ($is_travel): ?>
                <div class="s-cell">
                    <span class="lbl"><i class="bi bi-person-fill"></i>Passenger</span>
                    <span class="val"><?= htmlspecialchars($s['passenger_name'] ?: $s['sender_name']) ?></span>
                </div>
                <?php if ($s['passenger_cnic']): ?>
                <div class="s-cell">
                    <span class="lbl"><i class="bi bi-credit-card"></i>CNIC / Passport</span>
                    <span class="val"><?= htmlspecialchars($s['passenger_cnic']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($s['linked_booking_ref']): ?>
                <div class="s-cell">
                    <span class="lbl"><i class="bi bi-ticket-perforated"></i>Linked Booking</span>
                    <span class="val" style="font-size:.8rem;"><?= htmlspecialchars($s['linked_booking_ref']) ?></span>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <div class="s-cell">
                    <span class="lbl"><i class="bi bi-person-lines-fill"></i>Sender</span>
                    <span class="val"><?= htmlspecialchars($s['sender_name']) ?></span>
                </div>
                <div class="s-cell">
                    <span class="lbl"><i class="bi bi-person-check"></i>Receiver</span>
                    <span class="val"><?= htmlspecialchars($s['receiver_name']) ?></span>
                </div>
                <?php endif; ?>
                <div class="s-cell">
                    <span class="lbl"><i class="bi bi-tag"></i>Cargo Type</span>
                    <span class="val" style="text-transform:capitalize;"><?= htmlspecialchars($s['cargo_type']) ?></span>
                </div>
                <div class="s-cell">
                    <span class="lbl"><i class="bi bi-speedometer2"></i>Weight</span>
                    <span class="val"><?= number_format($s['weight_kg'], 1) ?> kg</span>
                </div>
                <div class="s-cell">
                    <span class="lbl"><i class="bi bi-cash-stack"></i>Shipping Fee</span>
                    <span class="val text-success fw-bold">Rs <?= number_format($s['shipping_fee'], 0) ?>
                        <?php if ($is_travel): ?><span style="font-size:.67rem;color:#7c3aed;display:block;">20% discount</span><?php endif; ?>
                    </span>
                </div>
                <div class="s-cell">
                    <span class="lbl"><i class="bi bi-wallet2"></i>Payment</span>
                    <span class="val">
                        <span class="s-pill" style="background:<?= $s['payment_status']==='paid' ? '#dcfce7' : ($s['payment_status']==='refunded' ? '#ede9fe' : '#fef3c7') ?>;color:<?= $s['payment_status']==='paid' ? '#15803d' : ($s['payment_status']==='refunded' ? '#5b21b6' : '#92400e') ?>;">
                            <?= ucfirst($s['payment_status']) ?>
                        </span>
                    </span>
                </div>
                <?php if ($s['estimated_delivery']): ?>
                <div class="s-cell">
                    <span class="lbl"><i class="bi bi-calendar-check"></i>Est. Delivery</span>
                    <span class="val"><?= date('d M Y', strtotime($s['estimated_delivery'])) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($s['actual_delivery']): ?>
                <div class="s-cell">
                    <span class="lbl"><i class="bi bi-check2-circle"></i>Delivered On</span>
                    <span class="val text-success"><?= date('d M Y', strtotime($s['actual_delivery'])) ?></span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Footer -->
            <?php if ($s['special_instructions']): ?>
            <div class="ship-card-footer">
                <i class="bi bi-sticky me-1 text-warning"></i>
                <span style="font-size:.8rem;color:#6b7280;"><?= htmlspecialchars($s['special_instructions']) ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <nav class="d-flex justify-content-center mt-3">
            <ul class="pagination pagination-sm">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $page-1 ?>&status=<?= $filter_status ?>&q=<?= urlencode($search) ?>">‹</a>
                </li>
                <?php for ($p = max(1,$page-2); $p <= min($total_pages,$page+2); $p++): ?>
                <li class="page-item <?= $p == $page ? 'active' : '' ?>">
                    <a class="page-link" href="?page=<?= $p ?>&status=<?= $filter_status ?>&q=<?= urlencode($search) ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $page+1 ?>&status=<?= $filter_status ?>&q=<?= urlencode($search) ?>">›</a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
        <?php endif; // shipments empty ?>

    </div>
</div><!-- /content-area -->

<!-- ═══════════════════════════════════════════════
     BOOK NEW SHIPMENT MODAL — 4-Step Wizard
════════════════════════════════════════════════ -->
<div class="modal fade" id="bookModal" tabindex="-1">
    <div class="modal-dialog modal-xl ">
        <div class="modal-content border-0" style="border-radius:18px; ">

            <!-- Header -->
            <div class="modal-header" id="bookModalHeader" style="background:linear-gradient(135deg,#f59e0b,#fbbf24);border:none;padding:1.1rem 1.5rem;">
                <div>
                    <h5 class="modal-title fw-bold text-dark mb-0" id="bookModalTitle">
                        <i class="bi bi-box-seam me-2"></i>Book New Shipment
                    </h5>
                    <div id="wizStepLabel" style="font-size:.74rem;color:rgba(0,0,0,.55);margin-top:.15rem;">Step 1 of 4 — Choose Shipment Mode</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <form method="POST" id="bookForm">
                <input type="hidden" name="action" value="book">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="shipment_type" id="hiddenShipType" value="cargo_delivery">

                <div class="modal-body" style="padding:1.5rem 1.4rem;">

                    <!-- Wizard step bar -->
                    <div class="wiz-bar">
                        <div class="wiz-step active" id="ws1"><div class="wiz-dot">1</div><div class="wiz-step-label">Mode</div></div>
                        <div class="wiz-step" id="ws2"><div class="wiz-dot">2</div><div class="wiz-step-label">Details</div></div>
                        <div class="wiz-step" id="ws3"><div class="wiz-dot">3</div><div class="wiz-step-label">Cargo</div></div>
                        <div class="wiz-step" id="ws4"><div class="wiz-dot">4</div><div class="wiz-step-label">Review</div></div>
                    </div>

                    <!-- ─────────────────────────────────────────────────
                         STEP 1 — Choose Shipment Mode
                    ───────────────────────────────────────────────── -->
                    <div class="wiz-panel active" id="panel1">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <div class="mode-sel-card selected" id="modeCardCargo" onclick="selectMode('cargo_delivery')">
                                    <span class="mode-sel-icon"><i class="bi bi-box-seam text-warning"></i></span>
                                    <div class="mode-sel-title">Cargo Delivery</div>
                                    <div class="mode-sel-desc">Send goods independently. Specify sender &amp; receiver details for point-to-point delivery.</div>
                                    <div class="mt-2 d-flex justify-content-center gap-2 flex-wrap">
                                        <span style="font-size:.7rem;background:#fef3c7;color:#92400e;padding:.2em .7em;border-radius:20px;font-weight:700;">General · Fragile · Perishable</span>
                                        <span style="font-size:.7rem;background:#fee2e2;color:#991b1b;padding:.2em .7em;border-radius:20px;font-weight:700;">Livestock · Hazardous</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mode-sel-card" id="modeCardTravel" onclick="selectMode('travelling')">
                                    <span class="mode-sel-badge">20% OFF</span>
                                    <span class="mode-sel-icon"><i class="bi bi-person-luggage" style="color:#7c3aed;"></i></span>
                                    <div class="mode-sel-title">Travelling with Cargo</div>
                                    <div class="mode-sel-desc">You're the passenger carrying this cargo. A 20% luggage discount is applied automatically to your fee.</div>
                                    <div class="mt-2">
                                        <span style="font-size:.7rem;background:#ede9fe;color:#5b21b6;padding:.2em .7em;border-radius:20px;font-weight:700;"><i class="bi bi-tag-fill me-1"></i>20% Discount Applied</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Rate overview -->
                        <div class="p-3 rounded-3" style="background:#f8fafc;border:1px solid #e2e8f0;">
                            <div class="fw-bold text-muted mb-2" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;"><i class="bi bi-info-circle me-1"></i>Rate Overview</div>
                            <div class="d-flex flex-wrap gap-2">
                                <span style="font-size:.76rem;padding:.28rem .8rem;border-radius:8px;background:#fff;border:1px solid #e2e8f0;color:#1e293b;"><i class="bi bi-box-seam me-1 text-secondary"></i>General — Rs 50/kg</span>
                                <span style="font-size:.76rem;padding:.28rem .8rem;border-radius:8px;background:#fff;border:1px solid #e2e8f0;color:#0891b2;"><i class="bi bi-gem me-1"></i>Fragile — Rs 70/kg</span>
                                <span style="font-size:.76rem;padding:.28rem .8rem;border-radius:8px;background:#fff;border:1px solid #e2e8f0;color:#d97706;"><i class="bi bi-thermometer-half me-1"></i>Perishable — Rs 80/kg</span>
                                <span style="font-size:.76rem;padding:.28rem .8rem;border-radius:8px;background:#fff;border:1px solid #e2e8f0;color:#16a34a;"><i class="bi bi-tree me-1"></i>Livestock — Rs 100/kg</span>
                                <span style="font-size:.76rem;padding:.28rem .8rem;border-radius:8px;background:#fff;border:1px solid #e2e8f0;color:#dc2626;"><i class="bi bi-exclamation-triangle-fill me-1"></i>Hazardous — Rs 125/kg</span>
                            </div>
                        </div>
                    </div>

                    <!-- ─────────────────────────────────────────────────
                         STEP 2 — Contact Details
                    ───────────────────────────────────────────────── -->
                    <div class="wiz-panel" id="panel2">

                        <!-- Cargo delivery: Sender + Receiver -->
                        <div id="senderSection">
                            <div class="section-head section-head-blue"><i class="bi bi-person-fill"></i>Sender Details</div>
                            <div class="row g-3 mb-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Sender Name *</label>
                                    <input type="text" name="sender_name" id="senderName" class="form-control" placeholder="Full name" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>">
                                    <div class="field-err-msg" id="err-sender_name">Sender name is required.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Phone</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-phone"></i></span>
                                        <input type="text" name="sender_phone" class="form-control" placeholder="+92-300-0000000" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Sender Address</label>
                                    <input type="text" name="sender_address" class="form-control" placeholder="House/Street, City">
                                </div>
                            </div>
                            <div class="section-head section-head-green"><i class="bi bi-person-check-fill"></i>Receiver Details</div>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Receiver Name *</label>
                                    <input type="text" name="receiver_name" id="receiverName" class="form-control" placeholder="Full name">
                                    <div class="field-err-msg" id="err-receiver_name">Receiver name is required.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Receiver Phone</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-phone"></i></span>
                                        <input type="text" name="receiver_phone" class="form-control" placeholder="+92-300-0000000">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Receiver Address</label>
                                    <input type="text" name="receiver_address" class="form-control" placeholder="House/Street, City">
                                </div>
                            </div>
                        </div>

                        <!-- Travelling: Passenger -->
                        <div id="passengerSection" style="display:none;">
                            <div class="alert d-flex align-items-center gap-2 mb-3" style="background:#f5f3ff;border:1px solid #c4b5fd;font-size:.83rem;border-radius:10px;padding:.75rem 1rem;">
                                <i class="bi bi-tag-fill fs-5" style="color:#7c3aed;flex-shrink:0;"></i>
                                <div>You're travelling with this cargo — <strong>20% luggage discount</strong> is applied automatically!</div>
                            </div>
                            <div class="section-head section-head-purple"><i class="bi bi-person-fill"></i>Passenger Details</div>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Full Name *</label>
                                    <input type="text" name="passenger_name" id="passengerName" class="form-control" placeholder="Full name" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>">
                                    <div class="field-err-msg" id="err-passenger_name">Passenger name is required.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Phone</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-phone"></i></span>
                                        <input type="text" name="passenger_phone" class="form-control" placeholder="+92-300-0000000" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">CNIC / Passport No.</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-credit-card"></i></span>
                                        <input type="text" name="passenger_cnic" class="form-control" placeholder="42101-1234567-9">
                                    </div>
                                </div>
                                <?php if (!empty($my_booking_refs)): ?>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Link to a Booking <span class="text-muted fw-normal">(optional)</span></label>
                                    <select name="linked_booking_ref" class="form-select">
                                        <option value="">— Select your booking —</option>
                                        <?php foreach ($my_booking_refs as $br): ?>
                                        <option value="<?= htmlspecialchars($br['booking_reference']) ?>">
                                            <?= htmlspecialchars($br['booking_reference']) ?> —
                                            <?= htmlspecialchars($br['departure_city']) ?> → <?= htmlspecialchars($br['arrival_city']) ?>
                                            (<?= date('d M Y', strtotime($br['journey_date'])) ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php else: ?>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Booking Reference <span class="text-muted fw-normal">(optional)</span></label>
                                    <input type="text" name="linked_booking_ref" class="form-control" placeholder="e.g. RWY20250401120000001">
                                </div>
                                <?php endif; ?>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Cargo Description</label>
                                    <input type="text" name="sender_address" class="form-control" placeholder="e.g. Personal luggage, merchandise">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ─────────────────────────────────────────────────
                         STEP 3 — Cargo Information
                    ───────────────────────────────────────────────── -->
                    <div class="wiz-panel" id="panel3">
                        <div class="row g-3">

                            <!-- Route & schedule -->
                            <div class="col-12"><div class="section-head section-head-amber"><i class="bi bi-geo-alt-fill"></i>Route &amp; Schedule</div></div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Origin City *</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-geo-alt"></i></span>
                                    <input type="text" name="origin_city" id="originCity" class="form-control" list="city-list" placeholder="e.g. Karachi" oninput="updateFee()">
                                </div>
                                <div class="field-err-msg" id="err-origin_city">Origin city is required.</div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Destination City *</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-geo-alt-fill"></i></span>
                                    <input type="text" name="destination_city" id="destCity" class="form-control" list="city-list" placeholder="e.g. Lahore" oninput="updateFee()">
                                </div>
                                <div class="field-err-msg" id="err-dest_city">Destination city is required.</div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Train Route <span class="text-muted fw-normal">(optional)</span></label>
                                <select name="route_id" id="routeSelect" class="form-select" onchange="autoFillCities(this)">
                                    <option value="">— Auto-fill cities from route —</option>
                                    <?php foreach ($routes as $r): ?>
                                    <option value="<?= $r['route_id'] ?>"
                                        data-origin="<?= htmlspecialchars($r['departure_city']) ?>"
                                        data-dest="<?= htmlspecialchars($r['arrival_city']) ?>">
                                        <?= htmlspecialchars($r['departure_city'].' → '.$r['arrival_city'].' ('.date('d M', strtotime($r['journey_date'])).')') ?>
                                        [<?= htmlspecialchars($r['train_name']) ?>]
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Est. Delivery Date</label>
                                <input type="date" name="estimated_delivery" class="form-control" min="<?= date('Y-m-d') ?>">
                            </div>

                            <!-- Cargo type cards -->
                            <div class="col-12"><div class="section-head section-head-blue mt-1"><i class="bi bi-box-seam-fill"></i>Cargo Type &amp; Weight</div></div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Cargo Type *</label>
                                <div class="cargo-type-grid">
                                    <label class="cargo-card">
                                        <input type="radio" name="cargo_type" value="general" checked onchange="updateFee()">
                                        <div class="cargo-card-inner">
                                            <i class="bi bi-box-seam ct-icon text-secondary"></i>
                                            <div class="ct-name">General</div>
                                            <div class="ct-rate">Rs 50/kg</div>
                                            <div class="ct-desc">Standard goods, no special handling</div>
                                        </div>
                                    </label>
                                    <label class="cargo-card">
                                        <input type="radio" name="cargo_type" value="fragile" onchange="updateFee()">
                                        <div class="cargo-card-inner">
                                            <i class="bi bi-gem ct-icon" style="color:#06b6d4"></i>
                                            <div class="ct-name">Fragile</div>
                                            <div class="ct-rate">Rs 70/kg</div>
                                            <div class="ct-desc">Glass, electronics, antiques</div>
                                        </div>
                                    </label>
                                    <label class="cargo-card">
                                        <input type="radio" name="cargo_type" value="perishable" onchange="updateFee()">
                                        <div class="cargo-card-inner">
                                            <i class="bi bi-thermometer-half ct-icon text-warning"></i>
                                            <div class="ct-name">Perishable</div>
                                            <div class="ct-rate">Rs 80/kg</div>
                                            <div class="ct-desc">Food, medicine, cold chain</div>
                                        </div>
                                    </label>
                                    <label class="cargo-card">
                                        <input type="radio" name="cargo_type" value="livestock" onchange="updateFee()">
                                        <div class="cargo-card-inner">
                                            <i class="bi bi-tree ct-icon text-success"></i>
                                            <div class="ct-name">Livestock</div>
                                            <div class="ct-rate">Rs 100/kg</div>
                                            <div class="ct-desc">Animals, plants, seeds</div>
                                        </div>
                                    </label>
                                    <label class="cargo-card">
                                        <input type="radio" name="cargo_type" value="hazardous" onchange="updateFee()">
                                        <div class="cargo-card-inner">
                                            <i class="bi bi-exclamation-triangle-fill ct-icon text-danger"></i>
                                            <div class="ct-name">Hazardous</div>
                                            <div class="ct-rate">Rs 125/kg</div>
                                            <div class="ct-desc">Chemicals, flammables</div>
                                        </div>
                                    </label>
                                </div>
                            </div>

                            <!-- Weight with quick chips -->
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Weight (kg) *</label>
                                <div class="wt-chips">
                                    <span class="wt-chip" onclick="setWeight(5)">5 kg</span>
                                    <span class="wt-chip" onclick="setWeight(10)">10 kg</span>
                                    <span class="wt-chip" onclick="setWeight(25)">25 kg</span>
                                    <span class="wt-chip" onclick="setWeight(50)">50 kg</span>
                                    <span class="wt-chip" onclick="setWeight(100)">100 kg</span>
                                </div>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-speedometer2"></i></span>
                                    <input type="number" name="weight_kg" id="weightInput" class="form-control" min="0.1" max="5000" step="0.1" placeholder="e.g. 10.5" oninput="updateFee();updateWeightChips();">
                                    <span class="input-group-text">kg</span>
                                </div>
                                <div class="field-err-msg" id="err-weight">Please enter a valid weight (> 0).</div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Declared Value <span class="text-muted fw-normal">(Rs)</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">Rs</span>
                                    <input type="number" name="declared_value" class="form-control" min="0" step="1" placeholder="e.g. 50000">
                                </div>
                                <div style="font-size:.7rem;color:#6b7280;margin-top:.3rem;"><i class="bi bi-shield-check me-1 text-success"></i>Used for insurance estimation (optional)</div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Live Fee Estimate</label>
                                <div class="quote-box">
                                    <div class="fee-label"><i class="bi bi-calculator me-1"></i>Shipping Cost</div>
                                    <div class="fee-big" id="feeDisplay">Rs 0</div>
                                    <div class="fee-breakdown" id="feeBreakdown" style="display:none;"></div>
                                    <div class="discount-tag" id="discountNote" style="display:none;"><i class="bi bi-tag-fill me-1"></i>20% luggage discount applied</div>
                                </div>
                            </div>

                            <!-- Special instructions -->
                            <div class="col-12">
                                <div class="d-flex justify-content-between align-items-center">
                                    <label class="form-label fw-semibold mb-0">Special Instructions <span class="text-muted fw-normal">(optional)</span></label>
                                    <span class="char-counter" id="instrCharCount">200 chars left</span>
                                </div>
                                <textarea name="special_instructions" class="form-control mt-1" rows="2" maxlength="200" placeholder="Fragile handling, temperature requirements, security seal needed, etc."></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- ─────────────────────────────────────────────────
                         STEP 4 — Review & Confirm
                    ───────────────────────────────────────────────── -->
                    <div class="wiz-panel" id="panel4">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <div style="width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#10b981,#34d399);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.25rem;flex-shrink:0;">
                                <i class="bi bi-receipt"></i>
                            </div>
                            <div>
                                <div class="fw-bold" style="color:#1e293b;">Review your shipment</div>
                                <div style="font-size:.79rem;color:#6b7280;">Verify all details before confirming your booking</div>
                            </div>
                        </div>

                        <div id="reviewModeBadge" class="mb-3"></div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:12px; ">
                                    <div class="px-3 py-2" style="background:#f8fafc;border-bottom:1px solid #f1f5f9;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#64748b;">
                                        <i class="bi bi-person-lines-fill me-1"></i>Contact Details
                                    </div>
                                    <table class="review-table">
                                        <tr><td class="rtl">Sender / Passenger</td><td class="rtv" id="rev-sender">—</td></tr>
                                        <tr><td class="rtl">Receiver</td><td class="rtv" id="rev-receiver">—</td></tr>
                                        <tr><td class="rtl">Phone</td><td class="rtv" id="rev-phone">—</td></tr>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:12px; ">
                                    <div class="px-3 py-2" style="background:#f8fafc;border-bottom:1px solid #f1f5f9;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#64748b;">
                                        <i class="bi bi-geo-alt-fill me-1"></i>Route &amp; Schedule
                                    </div>
                                    <table class="review-table">
                                        <tr><td class="rtl">Route</td><td class="rtv" id="rev-route">—</td></tr>
                                        <tr><td class="rtl">Est. Delivery</td><td class="rtv" id="rev-delivery">—</td></tr>
                                        <tr><td class="rtl">Train Route</td><td class="rtv" id="rev-train">—</td></tr>
                                    </table>
                                </div>
                            </div>
                            <div class="col-12">
                                <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:12px; ">
                                    <div class="px-3 py-2" style="background:#f8fafc;border-bottom:1px solid #f1f5f9;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#64748b;">
                                        <i class="bi bi-box-seam me-1"></i>Cargo Details &amp; Pricing
                                    </div>
                                    <table class="review-table">
                                        <tr><td class="rtl">Cargo Type</td><td class="rtv" id="rev-cargo-type">—</td></tr>
                                        <tr><td class="rtl">Weight</td><td class="rtv" id="rev-weight">—</td></tr>
                                        <tr><td class="rtl">Base Amount</td><td class="rtv" id="rev-base">—</td></tr>
                                        <tr id="rev-disc-row" style="display:none;">
                                            <td class="rtl" style="color:#7c3aed;">Luggage Discount (20%)</td>
                                            <td class="rtv" style="color:#7c3aed;" id="rev-disc">—</td>
                                        </tr>
                                        <tr class="review-total-row">
                                            <td class="rtl fw-bold" style="color:#065f46;">Total Shipping Fee</td>
                                            <td class="rtv" id="rev-total" style="color:#059669;">—</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            <div class="col-12" id="rev-instructions-row" style="display:none;">
                                <div class="d-flex align-items-start gap-2 p-3 rounded-3" style="background:#fefce8;border:1px solid #fde68a;">
                                    <i class="bi bi-sticky-fill text-warning fs-5 flex-shrink-0"></i>
                                    <div>
                                        <div style="font-size:.72rem;font-weight:700;color:#92400e;text-transform:uppercase;margin-bottom:.2rem;">Special Instructions</div>
                                        <div style="font-size:.83rem;color:#78350f;" id="rev-instructions"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="alert alert-success mt-3 py-2 d-flex align-items-center gap-2" style="font-size:.82rem;">
                            <i class="bi bi-shield-fill-check flex-shrink-0 fs-5"></i>
                            <div>Your details are secure. A unique tracking number will be generated upon confirmation.</div>
                        </div>
                    </div>

                    <datalist id="city-list">
                        <?php foreach ($cities as $c) echo "<option value=\"{$c}\">"; ?>
                    </datalist>
                </div><!-- /modal-body -->

                <div class="modal-footer" style="border-top:1px solid #f1f5f9;padding:.9rem 1.4rem;">
                    <button type="button" class="btn btn-outline-secondary me-auto" id="wizBackBtn" style="display:none;" onclick="wizBack()">
                        <i class="bi bi-arrow-left me-1"></i>Back
                    </button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="wizCancelBtn">Cancel</button>
                    <button type="button" class="btn btn-warning fw-bold px-4" id="wizNextBtn" onclick="wizNext()">
                        Next <i class="bi bi-arrow-right ms-1"></i>
                    </button>
                    <button type="submit" class="btn btn-success fw-bold px-4" id="submitBtn" style="display:none;">
                        <i class="bi bi-send me-1"></i>Confirm &amp; Book
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ── Wizard state ─────────────────────────────────── */
var currentMode = 'cargo_delivery';
var currentStep = 1;
var rates = {general:50, fragile:70, perishable:80, livestock:100, hazardous:125};

function getCargoType() {
    var r = document.querySelector('input[name=cargo_type]:checked');
    return r ? r.value : 'general';
}

/* ── Mode selection ───────────────────────────────── */
function selectMode(mode) {
    currentMode = mode;
    document.getElementById('hiddenShipType').value = mode;
    var cc = document.getElementById('modeCardCargo');
    var ct = document.getElementById('modeCardTravel');
    cc.classList.remove('selected','selected-travel');
    ct.classList.remove('selected','selected-travel');
    if (mode === 'travelling') { ct.classList.add('selected-travel'); }
    else                       { cc.classList.add('selected'); }
}

/* ── Step navigation ──────────────────────────────── */
function wizGotoPanel(step) {
    for (var i = 1; i <= 4; i++) {
        var p = document.getElementById('panel' + i);
        if (p) p.classList.toggle('active', i === step);
    }
    for (var j = 1; j <= 4; j++) {
        var ws = document.getElementById('ws' + j);
        if (!ws) continue;
        ws.classList.remove('active','done');
        if (j < step)      ws.classList.add('done');
        else if (j === step) ws.classList.add('active');
    }
    var labels = {1:'Step 1 of 4 — Choose Shipment Mode',2:'Step 2 of 4 — Contact Details',3:'Step 3 of 4 — Cargo Information',4:'Step 4 of 4 — Review & Confirm'};
    var sl = document.getElementById('wizStepLabel');
    if (sl) sl.textContent = labels[step] || '';

    var mh = document.getElementById('bookModalHeader');
    if (mh) {
        if (step === 4)                            mh.style.background = 'linear-gradient(135deg,#10b981,#34d399)';
        else if (currentMode === 'travelling')     mh.style.background = 'linear-gradient(135deg,#7c3aed,#8b5cf6)';
        else                                       mh.style.background = 'linear-gradient(135deg,#f59e0b,#fbbf24)';
    }

    var backBtn   = document.getElementById('wizBackBtn');
    var cancelBtn = document.getElementById('wizCancelBtn');
    var nextBtn   = document.getElementById('wizNextBtn');
    var submitBtn = document.getElementById('submitBtn');
    if (backBtn)   backBtn.style.display   = step > 1   ? '' : 'none';
    if (cancelBtn) cancelBtn.style.display = step === 1 ? '' : 'none';
    if (nextBtn)   nextBtn.style.display   = step < 4   ? '' : 'none';
    if (submitBtn) submitBtn.style.display = step === 4 ? '' : 'none';

    if (step === 2) {
        var isTravelling = currentMode === 'travelling';
        document.getElementById('senderSection').style.display    = isTravelling ? 'none' : '';
        document.getElementById('passengerSection').style.display = isTravelling ? ''     : 'none';
        var sn = document.getElementById('senderName');
        var pn = document.getElementById('passengerName');
        var rn = document.getElementById('receiverName');
        if (sn) sn.required = !isTravelling;
        if (pn) pn.required =  isTravelling;
        if (rn) rn.required = !isTravelling;
    }
    if (step === 3) {
        var dn = document.getElementById('discountNote');
        if (dn) dn.style.display = currentMode === 'travelling' ? '' : 'none';
        updateFee();
    }
    if (step === 4) buildReview();
    currentStep = step;
}

function wizNext() { if (validateStep(currentStep) && currentStep < 4) wizGotoPanel(currentStep + 1); }
function wizBack() { if (currentStep > 1) wizGotoPanel(currentStep - 1); }

/* ── Per-step validation ──────────────────────────── */
function validateStep(step) {
    var ok = true;
    if (step === 2) {
        if (currentMode === 'cargo_delivery') {
            var sn = document.getElementById('senderName');
            var rn = document.getElementById('receiverName');
            if (sn && !sn.value.trim()) { showErr('err-sender_name',   sn); ok = false; } else if (sn) clearErr('err-sender_name',   sn);
            if (rn && !rn.value.trim()) { showErr('err-receiver_name', rn); ok = false; } else if (rn) clearErr('err-receiver_name', rn);
        } else {
            var pn = document.getElementById('passengerName');
            if (pn && !pn.value.trim()) { showErr('err-passenger_name', pn); ok = false; } else if (pn) clearErr('err-passenger_name', pn);
        }
    }
    if (step === 3) {
        var oc = document.getElementById('originCity');
        var dc = document.getElementById('destCity');
        var wi = document.getElementById('weightInput');
        if (oc && !oc.value.trim())           { showErr('err-origin_city', oc); ok = false; } else if (oc) clearErr('err-origin_city', oc);
        if (dc && !dc.value.trim())           { showErr('err-dest_city',   dc); ok = false; } else if (dc) clearErr('err-dest_city',   dc);
        if (wi && !(parseFloat(wi.value) > 0)){ showErr('err-weight',      wi); ok = false; } else if (wi) clearErr('err-weight',      wi);
    }
    return ok;
}

function showErr(id, field) {
    var el = document.getElementById(id);
    if (el) el.classList.add('show');
    if (field) field.classList.add('field-err');
}
function clearErr(id, field) {
    var el = document.getElementById(id);
    if (el) el.classList.remove('show');
    if (field) field.classList.remove('field-err');
}

/* ── Review summary builder ───────────────────────── */
function buildReview() {
    var isTravelling = currentMode === 'travelling';
    var senderEl  = isTravelling ? document.getElementById('passengerName') : document.getElementById('senderName');
    var senderVal = senderEl ? senderEl.value : '—';
    var recvEl    = document.querySelector('input[name=receiver_name]');
    var recvVal   = isTravelling ? '(Same as passenger)' : (recvEl ? recvEl.value || '—' : '—');
    var phoneEl   = isTravelling ? document.querySelector('input[name=passenger_phone]') : document.querySelector('input[name=sender_phone]');
    var phoneVal  = phoneEl ? phoneEl.value || '—' : '—';
    var origin    = (document.getElementById('originCity')  || {}).value || '—';
    var dest      = (document.getElementById('destCity')    || {}).value || '—';
    var delEl     = document.querySelector('input[name=estimated_delivery]');
    var delVal    = (delEl && delEl.value) ? new Date(delEl.value).toLocaleDateString('en-PK',{day:'2-digit',month:'short',year:'numeric'}) : 'Not specified';
    var routeSel  = document.getElementById('routeSelect');
    var trainTxt  = (routeSel && routeSel.selectedIndex > 0) ? routeSel.options[routeSel.selectedIndex].text : 'Not specified';
    var t         = getCargoType();
    var w         = parseFloat((document.getElementById('weightInput') || {}).value) || 0;
    var base      = Math.round(w * (rates[t] || 50) * 100) / 100;
    var fee       = isTravelling ? Math.round(base * 0.80 * 100) / 100 : base;
    var instrEl   = document.querySelector('textarea[name=special_instructions]');
    var instr     = instrEl ? instrEl.value : '';

    var mb = document.getElementById('reviewModeBadge');
    if (mb) mb.innerHTML = isTravelling
        ? '<span style="background:#ede9fe;color:#5b21b6;border-radius:20px;padding:.3em 1em;font-size:.78rem;font-weight:700;"><i class="bi bi-person-luggage me-1"></i>Travelling with Cargo — 20% Discount</span>'
        : '<span style="background:#fef3c7;color:#92400e;border-radius:20px;padding:.3em 1em;font-size:.78rem;font-weight:700;"><i class="bi bi-box-seam me-1"></i>Cargo Delivery</span>';

    setText('rev-sender',     senderVal || '—');
    setText('rev-receiver',   recvVal);
    setText('rev-phone',      phoneVal);
    setText('rev-route',      (origin !== '—' && dest !== '—') ? origin + ' → ' + dest : origin + dest);
    setText('rev-delivery',   delVal);
    setText('rev-train',      trainTxt);
    setText('rev-cargo-type', t.charAt(0).toUpperCase() + t.slice(1) + ' (Rs ' + (rates[t]||50) + '/kg)');
    setText('rev-weight',     w ? w + ' kg' : '—');
    setText('rev-base',       w ? 'Rs ' + base.toLocaleString('en-PK',{minimumFractionDigits:2}) : '—');
    setText('rev-total',      w ? 'Rs ' + fee.toLocaleString('en-PK',{minimumFractionDigits:2})  : '—');

    var discRow = document.getElementById('rev-disc-row');
    if (discRow) {
        discRow.style.display = (isTravelling && w > 0) ? '' : 'none';
        if (isTravelling && w > 0) setText('rev-disc', '− Rs ' + (base - fee).toLocaleString('en-PK',{minimumFractionDigits:2}));
    }
    var instrRow = document.getElementById('rev-instructions-row');
    var instrTxt = document.getElementById('rev-instructions');
    if (instrRow) instrRow.style.display = instr.trim() ? '' : 'none';
    if (instrTxt) instrTxt.textContent = instr;
}

function setText(id, val) {
    var el = document.getElementById(id);
    if (el) el.textContent = val || '—';
}

/* ── Auto-fill cities from route ──────────────────── */
function autoFillCities(sel) {
    var opt = sel.options[sel.selectedIndex];
    var oc  = document.getElementById('originCity');
    var dc  = document.getElementById('destCity');
    if (opt.getAttribute('data-origin') && oc) oc.value = opt.getAttribute('data-origin');
    if (opt.getAttribute('data-dest')   && dc) dc.value = opt.getAttribute('data-dest');
    updateFee();
}

/* ── Fee calculator ───────────────────────────────── */
function updateFee() {
    var wi   = document.getElementById('weightInput');
    var w    = parseFloat(wi ? wi.value : 0) || 0;
    var t    = getCargoType();
    var base = Math.round(w * (rates[t] || 50) * 100) / 100;
    var fee  = currentMode === 'travelling' ? Math.round(base * 0.80 * 100) / 100 : base;
    var fd   = document.getElementById('feeDisplay');
    if (fd) fd.textContent = 'Rs ' + fee.toLocaleString('en-PK',{minimumFractionDigits:2});
    var bd = document.getElementById('feeBreakdown');
    if (bd) {
        if (w > 0) {
            bd.textContent  = w + ' kg × Rs ' + (rates[t]||50) + '/kg' + (currentMode==='travelling' ? ' − 20%' : '');
            bd.style.display = '';
        } else {
            bd.style.display = 'none';
        }
    }
}

/* ── Weight quick-select chips ────────────────────── */
function setWeight(w) {
    var wi = document.getElementById('weightInput');
    if (wi) { wi.value = w; updateFee(); updateWeightChips(); }
}
function updateWeightChips() {
    var w = parseFloat((document.getElementById('weightInput') || {}).value);
    document.querySelectorAll('.wt-chip').forEach(function(c) {
        c.classList.toggle('active', parseFloat(c.textContent) === w);
    });
}

/* ── Special instructions counter ────────────────── */
(function() {
    var ta = document.querySelector('textarea[name=special_instructions]');
    var cc = document.getElementById('instrCharCount');
    if (ta && cc) {
        ta.addEventListener('input', function() {
            var left = 200 - this.value.length;
            cc.textContent = left + ' chars left';
            cc.className = 'char-counter' + (left < 20 ? ' at-limit' : '');
        });
    }
})();

/* ── Reset on modal open ──────────────────────────── */
document.getElementById('bookModal').addEventListener('show.bs.modal', function() {
    currentMode = 'cargo_delivery';
    document.getElementById('hiddenShipType').value = 'cargo_delivery';
    var mcc = document.getElementById('modeCardCargo');
    var mct = document.getElementById('modeCardTravel');
    if (mcc) { mcc.classList.add('selected');   mcc.classList.remove('selected-travel'); }
    if (mct) { mct.classList.remove('selected','selected-travel'); }
    wizGotoPanel(1);
    var wi = document.getElementById('weightInput');
    if (wi) wi.value = '';
    document.querySelectorAll('.wt-chip').forEach(function(c) { c.classList.remove('active'); });
    var fd = document.getElementById('feeDisplay');  if (fd) fd.textContent = 'Rs 0';
    var bd = document.getElementById('feeBreakdown'); if (bd) bd.style.display = 'none';
    var cc = document.getElementById('instrCharCount'); if (cc) { cc.textContent = '200 chars left'; cc.className = 'char-counter'; }
    document.querySelectorAll('.field-err-msg').forEach(function(e) { e.classList.remove('show'); });
    document.querySelectorAll('.field-err').forEach(function(e) { e.classList.remove('field-err'); });
    // Reset fields
    var f = document.getElementById('bookForm');
    if (f) {
        ['originCity','destCity'].forEach(function(id){ var el=f.querySelector('#'+id); if(el) el.value=''; });
        var rs=f.querySelector('#routeSelect');           if(rs) rs.value='';
        var de=f.querySelector('[name=estimated_delivery]'); if(de) de.value='';
        var dv=f.querySelector('[name=declared_value]');  if(dv) dv.value='';
        var si=f.querySelector('textarea[name=special_instructions]'); if(si) si.value='';
        var rn=f.querySelector('#receiverName');          if(rn) rn.value='';
        var rp=f.querySelector('[name=receiver_phone]');  if(rp) rp.value='';
        var ra=f.querySelector('[name=receiver_address]');if(ra) ra.value='';
        var cg=f.querySelector('input[name=cargo_type][value=general]'); if(cg) cg.checked=true;
    }
});

/* ── Tracking number copy ─────────────────────────── */
function copyTrack(el, code) {
    navigator.clipboard.writeText(code).then(function() {
        var orig = el.innerHTML;
        el.innerHTML = '<i class="bi bi-check-lg me-1"></i>' + code + ' ✔';
        el.style.background = '#dcfce7'; el.style.color = '#15803d'; el.style.borderColor = '#86efac';
        setTimeout(function() { el.innerHTML = orig; el.style.background = ''; el.style.color = ''; el.style.borderColor = ''; }, 1800);
    }).catch(function() {});
}

/* ── Prevent double-submit ────────────────────────── */
document.querySelector('#bookModal form').addEventListener('submit', function() {
    var btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Booking…';
});
</script>

<?php require_once 'inc/footer.php'; ?>>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Sender Address</label>
                                    <input type="text" name="sender_address" class="form-control">
                                </div>
                            </div>
                        </div>

                        <!-- Travelling: Passenger -->
                        <div id="passengerSection" style="display:none;">
                            <div class="row g-3">
                                <div class="col-12">
                                    <div class="section-head section-head-purple"><i class="bi bi-person-fill"></i>Your Details (Passenger)</div>
                                    <div class="alert alert-info py-2 mb-0" style="font-size:.83rem;">
                                        <i class="bi bi-info-circle me-1"></i>You're travelling with this cargo. A <strong>20% luggage discount</strong> is applied automatically.
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Your Full Name *</label>
                                    <input type="text" name="passenger_name" id="passengerName" class="form-control" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Phone</label>
                                    <input type="text" name="passenger_phone" class="form-control" placeholder="+92-300-0000000" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">CNIC / Passport No.</label>
                                    <input type="text" name="passenger_cnic" class="form-control" placeholder="e.g. 42101-1234567-9">
                                </div>
                                <?php if (!empty($my_booking_refs)): ?>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Link to a Booking <span class="text-muted fw-normal">(optional)</span></label>
                                    <select name="linked_booking_ref" class="form-select form-select-sm">
                                        <option value="">— Select your booking —</option>
                                        <?php foreach ($my_booking_refs as $br): ?>
                                        <option value="<?= htmlspecialchars($br['booking_reference']) ?>">
                                            <?= htmlspecialchars($br['booking_reference']) ?> —
                                            <?= htmlspecialchars($br['departure_city']) ?> → <?= htmlspecialchars($br['arrival_city']) ?>
                                            (<?= date('d M Y', strtotime($br['journey_date'])) ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php else: ?>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Booking Reference <span class="text-muted fw-normal">(optional)</span></label>
                                    <input type="text" name="linked_booking_ref" class="form-control" placeholder="e.g. RWY20250401120000001">
                                </div>
                                <?php endif; ?>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Cargo Description</label>
                                    <input type="text" name="sender_address" class="form-control" placeholder="e.g. Personal luggage, merchandise">
                                </div>
                            </div>
                        </div>

                        <!-- Receiver (cargo only) -->
                        <div id="receiverSection">
                            <div class="row g-3">
                                <div class="col-12"><div class="section-head section-head-green mt-2"><i class="bi bi-person-check-fill"></i>Receiver Details</div></div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Receiver Name *</label>
                                    <input type="text" name="receiver_name" id="receiverName" class="form-control" required>
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

                        <!-- Shipment details -->
                        <div class="col-12"><div class="section-head section-head-amber mt-2"><i class="bi bi-box-seam-fill"></i>Shipment Details</div></div>

                        <!-- Route row -->
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Origin City *</label>
                            <input type="text" name="origin_city" id="originCity" class="form-control" list="city-list" required placeholder="e.g. Karachi" oninput="updateSummary()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Destination City *</label>
                            <input type="text" name="destination_city" id="destCity" class="form-control" list="city-list" required placeholder="e.g. Lahore" oninput="updateSummary()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Train Route <span class="text-muted fw-normal">(optional)</span></label>
                            <select name="route_id" id="routeSelect" class="form-select" onchange="autoFillCities(this)">
                                <option value="">— Select route (auto-fills cities) —</option>
                                <?php foreach ($routes as $r): ?>
                                <option value="<?= $r['route_id'] ?>"
                                    data-origin="<?= htmlspecialchars($r['departure_city']) ?>"
                                    data-dest="<?= htmlspecialchars($r['arrival_city']) ?>">
                                    <?= htmlspecialchars($r['departure_city'].' → '.$r['arrival_city'].' ('.date('d M', strtotime($r['journey_date'])).')') ?>
                                    [<?= htmlspecialchars($r['train_name']) ?>]
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Est. Delivery Date</label>
                            <input type="date" name="estimated_delivery" class="form-control" min="<?= date('Y-m-d') ?>">
                        </div>

                        <!-- Cargo type as visual cards (full width) -->
                        <div class="col-12">
                            <label class="form-label fw-semibold">Cargo Type *</label>
                            <div class="cargo-type-grid">
                                <label class="cargo-card">
                                    <input type="radio" name="cargo_type" value="general" checked>
                                    <div class="cargo-card-inner">
                                        <i class="bi bi-box-seam ct-icon text-secondary"></i>
                                        <div class="ct-name">General</div>
                                        <div class="ct-rate">Rs 50/kg</div>
                                    </div>
                                </label>
                                <label class="cargo-card">
                                    <input type="radio" name="cargo_type" value="fragile">
                                    <div class="cargo-card-inner">
                                        <i class="bi bi-gem ct-icon" style="color:#06b6d4"></i>
                                        <div class="ct-name">Fragile</div>
                                        <div class="ct-rate">Rs 70/kg</div>
                                    </div>
                                </label>
                                <label class="cargo-card">
                                    <input type="radio" name="cargo_type" value="perishable">
                                    <div class="cargo-card-inner">
                                        <i class="bi bi-thermometer-half ct-icon text-warning"></i>
                                        <div class="ct-name">Perishable</div>
                                        <div class="ct-rate">Rs 80/kg</div>
                                    </div>
                                </label>
                                <label class="cargo-card">
                                    <input type="radio" name="cargo_type" value="livestock">
                                    <div class="cargo-card-inner">
                                        <i class="bi bi-tree ct-icon text-success"></i>
                                        <div class="ct-name">Livestock</div>
                                        <div class="ct-rate">Rs 100/kg</div>
                                    </div>
                                </label>
                                <label class="cargo-card">
                                    <input type="radio" name="cargo_type" value="hazardous">
                                    <div class="cargo-card-inner">
                                        <i class="bi bi-exclamation-triangle-fill ct-icon text-danger"></i>
                                        <div class="ct-name">Hazardous</div>
                                        <div class="ct-rate">Rs 125/kg</div>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- Weight + declared + fee -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Weight (kg) *</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-speedometer2"></i></span>
                                <input type="number" name="weight_kg" id="weightInput" class="form-control" min="0.1" max="5000" step="0.1" required placeholder="e.g. 10.5">
                                <span class="input-group-text">kg</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Declared Value <span class="text-muted fw-normal">(Rs, for insurance)</span></label>
                            <div class="input-group">
                                <span class="input-group-text">Rs</span>
                                <input type="number" name="declared_value" class="form-control" min="0" step="1" placeholder="e.g. 50000">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Estimated Fee</label>
                            <div class="quote-box">
                                <div class="fee-label"><i class="bi bi-calculator me-1"></i>Shipping Cost</div>
                                <div class="fee-big" id="feeDisplay">Rs 0</div>
                                <div class="fee-breakdown" id="feeBreakdown" style="display:none;"></div>
                                <div class="discount-tag" id="discountNote" style="display:none;"><i class="bi bi-tag-fill me-1"></i>20% luggage discount applied</div>
                            </div>
                        </div>

                        <!-- Special instructions with char counter -->
                        <div class="col-12">
                            <div class="d-flex justify-content-between align-items-center">
                                <label class="form-label fw-semibold mb-0">Special Instructions <span class="text-muted fw-normal">(optional)</span></label>
                                <span class="char-counter" id="instrCharCount">200 chars left</span>
                            </div>
                            <textarea name="special_instructions" class="form-control mt-1" rows="2" maxlength="200" placeholder="Fragile handling, temperature requirements, security seal needed, etc."></textarea>
                        </div>

                        <!-- Live booking summary -->
                        <div class="col-12">
                            <div id="shipSummaryBox" style="display:none;">
                                <div class="section-head" style="background:#f1f5f9;color:#374151;"><i class="bi bi-receipt"></i>Booking Preview</div>
                                <div class="sum-box">
                                    <div class="sum-row">
                                        <span class="sum-lbl"><i class="bi bi-geo-alt me-1"></i>Route</span>
                                        <span class="sum-val" id="sum-route">—</span>
                                    </div>
                                    <div class="sum-row">
                                        <span class="sum-lbl"><i class="bi bi-tag me-1"></i>Cargo Type</span>
                                        <span class="sum-val" id="sum-type">—</span>
                                    </div>
                                    <div class="sum-row">
                                        <span class="sum-lbl"><i class="bi bi-speedometer2 me-1"></i>Weight</span>
                                        <span class="sum-val" id="sum-weight">—</span>
                                    </div>
                                    <div class="sum-row">
                                        <span class="sum-lbl"><i class="bi bi-calculator me-1"></i>Base Amount</span>
                                        <span class="sum-val" id="sum-base">—</span>
                                    </div>
                                    <div class="sum-row" id="sum-disc-row" style="display:none;">
                                        <span class="sum-lbl"><i class="bi bi-tag-fill me-1 text-success"></i>Luggage Discount (20%)</span>
                                        <span class="sum-val text-success" id="sum-disc">—</span>
                                    </div>
                                    <div class="sum-row">
                                        <span class="sum-lbl fw-bold"><i class="bi bi-cash-stack me-1"></i>Total Fee</span>
                                        <span class="sum-val" style="color:#059669;font-size:.95rem;" id="sum-total">—</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <datalist id="city-list">
                        <?php foreach ($cities as $c) echo "<option value=\"{$c}\">"; ?>
                    </datalist>
                </div><!-- /modal-body -->

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" id="submitBtn" class="btn btn-warning fw-bold">
                        <i class="bi bi-send me-1"></i>Book Shipment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
var currentMode = 'cargo_delivery';
var rates = {general:50, fragile:70, perishable:80, livestock:100, hazardous:125};

function getCargoType() {
    var r = document.querySelector('input[name=cargo_type]:checked');
    return r ? r.value : 'general';
}

function setShipMode(mode) {
    currentMode = mode;
    document.getElementById('hiddenShipType').value = mode;
    var isTravelling = mode === 'travelling';

    document.getElementById('senderSection').style.display   = isTravelling ? 'none' : '';
    document.getElementById('passengerSection').style.display = isTravelling ? '' : 'none';
    document.getElementById('receiverSection').style.display  = isTravelling ? 'none' : '';

    var btnC = document.getElementById('btnModeCargo');
    var btnT = document.getElementById('btnModeTravel');
    if (isTravelling) {
        btnC.classList.remove('btn-warning','active-mode'); btnC.classList.add('btn-outline-secondary');
        btnT.classList.remove('btn-outline-secondary');     btnT.classList.add('btn-warning','active-mode');
        document.getElementById('bookModalHeader').style.background = '#7c3aed';
        document.getElementById('bookModalTitle').innerHTML = '<i class="bi bi-person-luggage me-2"></i>Travelling with Cargo – Book';
    } else {
        btnT.classList.remove('btn-warning','active-mode'); btnT.classList.add('btn-outline-secondary');
        btnC.classList.remove('btn-outline-secondary');     btnC.classList.add('btn-warning','active-mode');
        document.getElementById('bookModalHeader').style.background = '#f59e0b';
        document.getElementById('bookModalTitle').innerHTML = '<i class="bi bi-box-seam me-2"></i>Book New Cargo Shipment';
    }

    var sn = document.getElementById('senderName');
    var pn = document.getElementById('passengerName');
    var rn = document.getElementById('receiverName');
    if (sn) sn.required = !isTravelling;
    if (pn) pn.required = isTravelling;
    if (rn) rn.required = !isTravelling;

    document.getElementById('discountNote').style.display = isTravelling ? '' : 'none';
    updateFee();
    updateSummary();
}

// Auto-fill origin/destination from selected route
function autoFillCities(sel) {
    var opt    = sel.options[sel.selectedIndex];
    var origin = opt.getAttribute('data-origin');
    var dest   = opt.getAttribute('data-dest');
    var oc = document.getElementById('originCity');
    var dc = document.getElementById('destCity');
    if (origin && oc) oc.value = origin;
    if (dest   && dc) dc.value = dest;
    updateSummary();
}

// Fee calculator with breakdown
function updateFee() {
    var w    = parseFloat(document.getElementById('weightInput').value) || 0;
    var t    = getCargoType();
    var base = Math.round(w * (rates[t] || 50) * 100) / 100;
    var fee  = currentMode === 'travelling' ? Math.round(base * 0.80 * 100) / 100 : base;

    document.getElementById('feeDisplay').textContent = 'Rs ' + fee.toLocaleString('en-PK', {minimumFractionDigits:2});

    var bd = document.getElementById('feeBreakdown');
    if (bd) {
        if (w > 0) {
            var disc = currentMode === 'travelling' ? ' − 20%' : '';
            bd.textContent = w + ' kg × Rs ' + (rates[t]||50) + '/kg' + disc;
            bd.style.display = '';
        } else {
            bd.style.display = 'none';
        }
    }
    updateSummary();
}

// Live booking summary preview
function updateSummary() {
    var box = document.getElementById('shipSummaryBox');
    if (!box) return;
    var w    = parseFloat(document.getElementById('weightInput').value) || 0;
    var orig = (document.getElementById('originCity') || {}).value || '';
    var dest = (document.getElementById('destCity')   || {}).value || '';
    var t    = getCargoType();
    var base = Math.round(w * (rates[t] || 50) * 100) / 100;
    var fee  = currentMode === 'travelling' ? Math.round(base * 0.80 * 100) / 100 : base;

    if (!w && !orig && !dest) { box.style.display = 'none'; return; }
    box.style.display = '';

    document.getElementById('sum-route').textContent  = (orig && dest) ? orig + ' → ' + dest : (orig || dest || '—');
    document.getElementById('sum-type').textContent   = t.charAt(0).toUpperCase() + t.slice(1) + ' — Rs ' + (rates[t]||50) + '/kg';
    document.getElementById('sum-weight').textContent = w ? w + ' kg' : '—';
    document.getElementById('sum-base').textContent   = w ? 'Rs ' + base.toLocaleString('en-PK', {minimumFractionDigits:2}) : '—';

    var discRow = document.getElementById('sum-disc-row');
    if (currentMode === 'travelling' && w > 0) {
        discRow.style.display = '';
        document.getElementById('sum-disc').textContent = '− Rs ' + (base - fee).toLocaleString('en-PK', {minimumFractionDigits:2});
    } else {
        if (discRow) discRow.style.display = 'none';
    }
    document.getElementById('sum-total').textContent = w ? 'Rs ' + fee.toLocaleString('en-PK', {minimumFractionDigits:2}) : '—';
}

// Attach event listeners
document.getElementById('weightInput').addEventListener('input', updateFee);
document.querySelectorAll('input[name=cargo_type]').forEach(function(r) {
    r.addEventListener('change', updateFee);
});

// Character counter for special instructions
(function() {
    var ta = document.querySelector('textarea[name=special_instructions]');
    var cc = document.getElementById('instrCharCount');
    if (ta && cc) {
        ta.addEventListener('input', function() {
            var left = 200 - this.value.length;
            cc.textContent = left + ' chars left';
            cc.className = 'char-counter' + (left < 20 ? ' at-limit' : '');
        });
    }
})();

// Reset modal on open
document.getElementById('bookModal').addEventListener('show.bs.modal', function() {
    setShipMode('cargo_delivery');
    var box = document.getElementById('shipSummaryBox');
    if (box) box.style.display = 'none';
    var cc = document.getElementById('instrCharCount');
    if (cc) { cc.textContent = '200 chars left'; cc.className = 'char-counter'; }
});

// Copy tracking code to clipboard
function copyTrack(el, code) {
    navigator.clipboard.writeText(code).then(function() {
        var orig = el.innerHTML;
        el.innerHTML = '<i class="bi bi-check-lg me-1"></i>' + code + ' ✔';
        el.style.background = '#dcfce7';
        el.style.color = '#15803d';
        el.style.borderColor = '#86efac';
        setTimeout(function() {
            el.innerHTML = orig;
            el.style.background = '';
            el.style.color = '';
            el.style.borderColor = '';
        }, 1800);
    }).catch(function() {});
}

// Prevent double-submit
document.querySelector('#bookModal form').addEventListener('submit', function() {
    var btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Booking…';
});
</script>

<?php require_once 'inc/footer.php'; ?>

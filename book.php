<?php
// book.php – Book Train Ticket with Seat & Berth Selection

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/Train.php';
require_once 'src/classes/Booking.php';

if (!User::isLoggedIn()) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php');
    exit();
}

$db = new Database();
$db->connect();

$user_id  = (int)$_SESSION['user_id'];
$route_id = isset($_GET['route_id']) ? (int)$_GET['route_id'] : 0;

if (!$route_id) { header('Location: index.php'); exit(); }

$route = $db->selectRow(
    "SELECT r.*, t.train_name, t.train_number, t.train_type, t.train_id AS tid
     FROM routes r
     JOIN trains t ON r.train_id = t.train_id
     WHERE r.route_id = {$route_id}"
);
if (!$route) { header('Location: index.php'); exit(); }

// ── Auto-create seats if none exist yet ──────────────────────────────────
$seat_count_row  = $db->selectRow("SELECT COUNT(*) AS cnt FROM seats WHERE route_id = {$route_id}");
$existing_seats  = (is_array($seat_count_row) && isset($seat_count_row['cnt']))
                   ? (int)$seat_count_row['cnt'] : 0;

if ($existing_seats === 0) {
    // Use total_seats from the train (not available_seats which shrinks with bookings)
    $train_total = $db->selectRow("SELECT total_seats FROM trains WHERE train_id = {$route['tid']}");
    $total       = (is_array($train_total) && isset($train_total['total_seats']))
                   ? (int)$train_total['total_seats'] : (int)$route['available_seats'];

    $train_obj = new Train($db);
    $train_obj->createSeats((int)$route['tid'], $route_id, $total);
}

// Fetch all seats for this route
$all_seats = $db->select(
    "SELECT * FROM seats WHERE route_id = {$route_id} ORDER BY seat_type, seat_number ASC"
);
if (!$all_seats) $all_seats = [];

// Group by seat_type
$seats_by_type = ['economy' => [], 'premium' => [], 'luxury' => []];
foreach ($all_seats as $s) {
    $st = $s['seat_type'] ?? 'economy';
    $seats_by_type[$st][] = $s;
}
$overall_available_seats = count(array_filter($all_seats, fn($seat) => ($seat['status'] ?? '') === 'available'));

// Fare multipliers per class
$fare_mul   = ['economy' => 1.0, 'premium' => 1.5, 'luxury' => 2.5];
$class_info = [
    'economy' => ['label' => 'Economy',  'color' => '#2563eb', 'bg' => '#eff6ff', 'icon' => 'bi-person',        'desc' => 'Standard comfort · All berths · No AC'],
    'premium' => ['label' => 'Premium',  'color' => '#059669', 'bg' => '#f0fdf4', 'icon' => 'bi-star',          'desc' => 'Enhanced comfort · AC · Wider berths'],
    'luxury'  => ['label' => 'Luxury',   'color' => '#7c3aed', 'bg' => '#f5f3ff', 'icon' => 'bi-gem',           'desc' => 'First class · Full AC · Premium bedding'],
];

// Berth label from seat number digit
function getBerth(string $seat_num): string {
    $pos = (int)preg_replace('/[^0-9]/', '', $seat_num);
    return match($pos) { 1, 2 => 'Lower', 3, 4 => 'Middle', 5, 6 => 'Upper', default => 'Lower' };
}
function getBerthShort(string $seat_num): string {
    $pos = (int)preg_replace('/[^0-9]/', '', $seat_num);
    return match($pos) { 1, 2 => 'LB', 3, 4 => 'MB', 5, 6 => 'UB', default => 'LB' };
}

$error_message = '';

// ── POST: Create Booking ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_seats = $_POST['selected_seats'] ?? [];
    $names     = $_POST['passenger_name']   ?? [];
    $ages      = $_POST['passenger_age']    ?? [];
    $genders   = $_POST['passenger_gender'] ?? [];

    $sel_ids = array_map('intval', $raw_seats);
    $n       = count($sel_ids);

    if ($n < 1) {
        $error_message = 'Please select at least one seat from the map.';
    } elseif ($n > 6) {
        $error_message = 'You can book a maximum of 6 seats per transaction.';
    } elseif (count($names) !== $n) {
        $error_message = 'Please fill in passenger details for all selected seats.';
    } else {
        $ids_str = implode(',', $sel_ids);
        $avail   = $db->select(
            "SELECT seat_id, seat_type FROM seats
             WHERE seat_id IN ({$ids_str}) AND status = 'available' AND route_id = {$route_id}"
        );

        if (!$avail || count($avail) !== $n) {
            $error_message = 'One or more selected seats are no longer available. Please refresh and try again.';
        } else {
            $total_fare = 0;
            foreach ($avail as $av) {
                $total_fare += round($route['base_fare'] * ($fare_mul[$av['seat_type']] ?? 1.0), 2);
            }

            $seats_data = [];
            for ($i = 0; $i < $n; $i++) {
                $seats_data[] = [
                    'seat_id'          => $sel_ids[$i],
                    'passenger_name'   => trim($names[$i]),
                    'passenger_age'    => max(1, min(120, (int)$ages[$i])),
                    'passenger_gender' => in_array($genders[$i], ['M', 'F', 'Other']) ? $genders[$i] : 'M',
                ];
            }

            $booking_obj = new Booking($db);
            $result      = $booking_obj->createBooking($user_id, $route_id, $seats_data);

            if ($result['success']) {
                $bid = (int)$result['booking_id'];
                // Correct fare (createBooking uses flat base fare)
                $db->query("UPDATE bookings SET total_fare = {$total_fare} WHERE booking_id = {$bid}");
                header("Location: payment.php?booking_id={$bid}");
                exit();
            } else {
                $error_message = $result['message'];
            }
        }
    }
}

$user_obj = new User($db);
$user     = $user_obj->getUserById($user_id);

$pageTitle = 'Book Ticket – Railway Management System';
require_once 'inc/header.php';
?>

<style>
/* ══════════════════════════════════════════
   Booking page
══════════════════════════════════════════ */
.bk-wrap { background:#f1f5f9; min-height:calc(100vh - 60px); padding-bottom:60px; }

/* Hero */
.bk-hero {
    background: linear-gradient(135deg,#0b1728 0%,#0f2040 45%,#1a3a6e 100%);
    color:#fff; padding:2rem 0 3.5rem; position:relative; overflow:hidden;
}
.bk-hero::before {
    content:''; position:absolute; inset:0;
    background-image:radial-gradient(rgba(255,255,255,.05) 1px,transparent 1px);
    background-size:26px 26px; pointer-events:none;
}
.bk-hero-wave { position:absolute; bottom:-2px; left:0; right:0; line-height:0; }
.bk-hero-wave svg { display:block; width:100%; height:60px; }
.bk-hero-inner { max-width:1140px; margin:0 auto; padding:0 1.25rem; position:relative; z-index:2; }

/* Route bar */
.route-bar { display:flex; flex-wrap:wrap; align-items:center; gap:1.25rem; }
.rb-city   { text-align:center; }
.rb-name   { font-size:clamp(1.2rem,3vw,1.9rem); font-weight:900; letter-spacing:-.02em; }
.rb-time   { font-size:.85rem; color:rgba(255,255,255,.65); margin-top:.15rem; }
.rb-arrow  { font-size:2rem; color:#fbbf24; flex-shrink:0; }
.rb-badges { display:flex; flex-wrap:wrap; gap:.5rem; margin-left:auto; }
.rb-badge  {
    display:inline-flex; align-items:center; gap:.35rem;
    background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.22);
    border-radius:999px; padding:.3rem .85rem; font-size:.78rem; font-weight:600;
    backdrop-filter:blur(6px);
}

/* Content */
.bk-content { max-width:1140px; margin:-44px auto 0; padding:0 1.25rem; position:relative; z-index:10; }

/* Surface card */
.surface-card {
    background:#fff; border-radius:16px;
    border:1px solid #e5e7eb; box-shadow:0 2px 8px rgba(0,0,0,.06); overflow:hidden;
}
.sc-head {
    padding:.9rem 1.35rem; border-bottom:1px solid #f1f5f9;
    display:flex; align-items:center; justify-content:space-between; gap:.5rem;
}
.sc-head h2 { font-size:.92rem; font-weight:800; color:#0f172a; margin:0; }
.sc-body { padding:1.25rem 1.35rem; }

/* Class tabs */
.class-tabs { display:flex; gap:.5rem; flex-wrap:wrap; padding:1rem 1.35rem; border-bottom:1px solid #f1f5f9; }
.class-tab  {
    display:flex; flex-direction:column; align-items:center; gap:.3rem;
    padding:.65rem 1.25rem; border-radius:12px; cursor:pointer;
    border:2px solid #e5e7eb; background:#fafafa;
    transition:all .2s; flex:1; min-width:110px;
}
.class-tab:hover  { border-color:#2563eb; background:#eff6ff; }
.class-tab.active { border-color:var(--cls-color); background:var(--cls-bg); }
.ct-icon  { font-size:1.3rem; }
.ct-name  { font-size:.85rem; font-weight:800; }
.ct-price { font-size:.75rem; font-weight:600; color:#6b7280; }
.ct-avail { font-size:.68rem; color:#9ca3af; }
.class-tab.active .ct-name  { color:var(--cls-color); }
.class-tab.active .ct-avail { color:var(--cls-color); opacity:.75; }

/* Seat map */
.seat-map-panel { padding:1.25rem 1.35rem; }
.seat-legend    { display:flex; gap:1rem; flex-wrap:wrap; margin-bottom:1rem; font-size:.75rem; font-weight:600; }
.leg-item       { display:flex; align-items:center; gap:.4rem; }
.leg-dot        { width:16px; height:16px; border-radius:5px; border:2px solid; }
.compartments   { display:flex; flex-wrap:wrap; gap:1rem; }
.compartment    { background:#f8fafc; border:1.5px solid #e5e7eb; border-radius:12px; padding:.75rem; min-width:150px; }
.comp-label     { font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#9ca3af; margin-bottom:.6rem; }
.berth-row      { display:grid; grid-template-columns:1fr 1fr; gap:.4rem; margin-bottom:.35rem; }
.berth-label    { font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#cbd5e1; margin:.5rem 0 .25rem; display:block; }

.seat-btn {
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    width:100%; aspect-ratio:1; border-radius:8px; border:2px solid;
    cursor:pointer; transition:all .15s; gap:.1rem; padding:.25rem; font-family:inherit;
}
.seat-btn.avail    { border-color:#93c5fd; background:#dbeafe; color:#1d4ed8; }
.seat-btn.avail:hover { border-color:#2563eb; background:#bfdbfe; transform:scale(1.08); }
.seat-btn.booked   { border-color:#fca5a5; background:#fee2e2; color:#b91c1c; cursor:not-allowed; }
.seat-btn.selected { border-color:#10b981; background:#dcfce7; color:#065f46; transform:scale(1.08); box-shadow:0 0 0 3px rgba(16,185,129,.3); }
.seat-num  { font-size:.72rem; font-weight:800; }
.berth-tag { font-size:.55rem; font-weight:700; opacity:.75; }

/* Passenger forms */
.pax-card      { background:#f8fafc; border:1.5px solid #e5e7eb; border-radius:12px; padding:1rem 1.1rem; margin-bottom:.75rem; }
.pax-card-head { display:flex; align-items:center; gap:.75rem; margin-bottom:.85rem; }
.pax-num       { width:28px; height:28px; border-radius:50%; background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff; font-size:.75rem; font-weight:800; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.pax-seat-info { font-size:.8rem; font-weight:700; color:#0f172a; }
.pax-seat-info small { font-size:.7rem; color:#6b7280; font-weight:400; }

/* Fare sidebar */
.fare-box      { background:linear-gradient(135deg,#0f2040,#1e40af); border-radius:14px; padding:1.25rem; color:#fff; margin-bottom:1rem; }
.fare-box .fb-label { font-size:.7rem; text-transform:uppercase; letter-spacing:.08em; color:rgba(255,255,255,.55); margin-bottom:.85rem; font-weight:700; }
.fare-row      { display:flex; justify-content:space-between; font-size:.82rem; margin-bottom:.4rem; color:rgba(255,255,255,.8); }
.fare-row strong { color:#fff; }
.fare-total    { border-top:1px solid rgba(255,255,255,.2); margin-top:.75rem; padding-top:.75rem; display:flex; justify-content:space-between; }
.fare-total .ft-label { font-size:.88rem; font-weight:700; }
.fare-total .ft-val   { font-size:1.5rem; font-weight:900; color:#fbbf24; }

/* Berth guide */
.berth-guide { background:#fff; border:1.5px solid #e5e7eb; border-radius:12px; padding:.85rem 1.1rem; margin-bottom:1rem; font-size:.82rem; }
.berth-guide h6 { font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#6b7280; margin-bottom:.6rem; }
.bg-row { display:flex; align-items:center; gap:.6rem; margin-bottom:.35rem; }
.bg-pill { padding:.18em .65em; border-radius:999px; font-size:.68rem; font-weight:700; }
.bg-pill.lb { background:#dbeafe; color:#1d4ed8; }
.bg-pill.mb { background:#fef3c7; color:#92400e; }
.bg-pill.ub { background:#f3e8ff; color:#6d28d9; }

/* Steps */
.bk-steps { display:flex; gap:0; align-items:center; margin-bottom:1.25rem; }
.bk-step  { display:flex; flex-direction:column; align-items:center; gap:.3rem; flex-shrink:0; }
.bk-circle { width:36px; height:36px; border-radius:50%; border:2.5px solid #dee2e6; display:flex; align-items:center; justify-content:center; font-size:.88rem; background:#fff; color:#adb5bd; transition:all .3s; }
.bk-step.active .bk-circle { border-color:#2563eb; color:#2563eb; }
.bk-step.done   .bk-circle { background:#2563eb; border-color:#2563eb; color:#fff; }
.bk-step-label  { font-size:.7rem; font-weight:600; color:#9ca3af; white-space:nowrap; }
.bk-step.active .bk-step-label,
.bk-step.done   .bk-step-label { color:#2563eb; }
.bk-connector { flex:1; height:2.5px; background:#dee2e6; }
.bk-connector.done { background:#2563eb; }

/* Selection bar */
.sel-bar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.75rem; padding:.75rem 1.35rem; background:#f0fdf4; border-bottom:1px solid #bbf7d0; }
.sel-bar-info  { font-size:.83rem; font-weight:600; color:#065f46; }
.sel-bar-seats { display:flex; flex-wrap:wrap; gap:.4rem; }
.sel-chip      { display:inline-flex; align-items:center; gap:.3rem; background:#dcfce7; border:1.5px solid #86efac; border-radius:999px; padding:.2em .65em; font-size:.72rem; font-weight:700; color:#065f46; }
.sel-chip .rm-seat { cursor:pointer; opacity:.6; transition:opacity .15s; font-weight:900; }
.sel-chip .rm-seat:hover { opacity:1; }

.no-seats-notice { text-align:center; padding:3rem 1rem; color:#9ca3af; }
.no-seats-notice i { font-size:3rem; display:block; margin-bottom:.75rem; opacity:.3; }

@media(max-width:768px) {
    .d-flex.align-items-start { flex-direction:column !important; }
    #fareSidebar { width:100% !important; }
}
</style>

<div class="bk-wrap">

<!-- Hero -->
<div class="bk-hero">
    <div class="bk-hero-inner">
        <div class="route-bar">
            <div class="rb-city">
                <div class="rb-name"><?= htmlspecialchars($route['departure_city']) ?></div>
                <div class="rb-time"><?= date('H:i', strtotime($route['departure_time'])) ?> departure</div>
            </div>
            <div class="rb-arrow"><i class="bi bi-arrow-right"></i></div>
            <div class="rb-city">
                <div class="rb-name"><?= htmlspecialchars($route['arrival_city']) ?></div>
                <div class="rb-time"><?= date('H:i', strtotime($route['arrival_time'])) ?> arrival</div>
            </div>
            <div class="rb-badges">
                <span class="rb-badge"><i class="bi bi-train-front"></i> <?= htmlspecialchars($route['train_name']) ?> · <?= htmlspecialchars($route['train_number']) ?></span>
                <span class="rb-badge"><i class="bi bi-calendar3"></i> <?= date('D, d M Y', strtotime($route['journey_date'])) ?></span>
                <span class="rb-badge"><i class="bi bi-geo-alt"></i> <?= number_format($route['distance_km'], 0) ?> km</span>
                <span class="rb-badge"><i class="bi bi-ticket-perforated"></i> <?= (int)$route['available_seats'] ?> seats left</span>
            </div>
        </div>
    </div>
    <div class="bk-hero-wave">
        <svg viewBox="0 0 1440 60" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M0,30 C360,60 1080,0 1440,30 L1440,60 L0,60 Z" fill="#f1f5f9"/>
        </svg>
    </div>
</div>

<div class="bk-content">

    <?php if ($error_message): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 rounded-3 mb-3 border-0"
         style="background:#fee2e2;color:#7f1d1d;">
        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
        <?= htmlspecialchars($error_message) ?>
    </div>
    <?php endif; ?>

    <?php if ($overall_available_seats === 0): ?>
    <div class="alert alert-warning d-flex align-items-start gap-3 rounded-3 mb-3 border-0"
         style="background:#fffbeb;color:#92400e;">
        <i class="bi bi-hourglass-split fs-5 mt-1"></i>
        <div>
            <strong>No seats are currently available on this route.</strong>
            <div class="small mt-1">You can still request RAC or join the automatic waitlist and get confirmed when seats open up.</div>
            <a href="operations-hub.php?tab=waitlist" class="btn btn-sm btn-outline-warning mt-2 fw-semibold">
                <i class="bi bi-diagram-3 me-1"></i>Open Waitlist / RAC
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Steps -->
    <div class="bk-steps mb-3">
        <div class="bk-step active">
            <div class="bk-circle"><i class="bi bi-grid-1x2"></i></div>
            <span class="bk-step-label">Select Seats</span>
        </div>
        <div class="bk-connector"></div>
        <div class="bk-step">
            <div class="bk-circle"><i class="bi bi-people"></i></div>
            <span class="bk-step-label">Passengers</span>
        </div>
        <div class="bk-connector"></div>
        <div class="bk-step">
            <div class="bk-circle"><i class="bi bi-credit-card"></i></div>
            <span class="bk-step-label">Payment</span>
        </div>
    </div>

    <form method="POST" id="bookingForm" novalidate>
    <div class="d-flex gap-3 align-items-start">

        <!-- LEFT: Seat Map -->
        <div class="flex-grow-1" style="min-width:0;">

            <div class="surface-card mb-3">
                <div class="sc-head">
                    <h2><i class="bi bi-grid-1x2 me-2" style="color:#2563eb;"></i>Select Your Class &amp; Seat</h2>
                </div>

                <!-- Class tabs -->
                <div class="class-tabs">
                    <?php foreach ($class_info as $type => $cls):
                        $avail_n  = count(array_filter($seats_by_type[$type] ?? [], fn($s) => $s['status'] === 'available'));
                        $fare_val = round($route['base_fare'] * $fare_mul[$type], 2);
                    ?>
                    <button type="button" class="class-tab <?= $type === 'economy' ? 'active' : '' ?>"
                            data-class="<?= $type ?>"
                            style="--cls-color:<?= $cls['color'] ?>;--cls-bg:<?= $cls['bg'] ?>;"
                            onclick="switchClass('<?= $type ?>')">
                        <span class="ct-icon"><i class="bi <?= $cls['icon'] ?>" style="color:<?= $cls['color'] ?>;"></i></span>
                        <span class="ct-name"><?= $cls['label'] ?></span>
                        <span class="ct-price">Rs <?= number_format($fare_val, 0) ?>/seat</span>
                        <span class="ct-avail"><?= $avail_n ?> available</span>
                    </button>
                    <?php endforeach; ?>
                </div>

                <!-- Selection bar -->
                <div class="sel-bar" id="selBar">
                    <div class="sel-bar-info" id="selInfo">
                        <i class="bi bi-info-circle me-1"></i>Click seats to select (max 6)
                    </div>
                    <div class="sel-bar-seats" id="selChips"></div>
                </div>

                <!-- Per-class seat maps -->
                <?php foreach ($class_info as $type => $cls): ?>
                <div class="seat-map-panel" id="map_<?= $type ?>"
                     style="<?= $type !== 'economy' ? 'display:none;' : '' ?>">

                    <!-- Legend -->
                    <div class="seat-legend">
                        <div class="leg-item">
                            <div class="leg-dot" style="background:#dbeafe;border-color:#93c5fd;"></div>Available
                        </div>
                        <div class="leg-item">
                            <div class="leg-dot" style="background:#dcfce7;border-color:#86efac;"></div>Selected
                        </div>
                        <div class="leg-item">
                            <div class="leg-dot" style="background:#fee2e2;border-color:#fca5a5;"></div>Booked
                        </div>
                    </div>

                    <?php $type_seats = $seats_by_type[$type] ?? []; ?>
                    <?php if (empty($type_seats)): ?>
                    <div class="no-seats-notice">
                        <i class="bi bi-ticket-perforated"></i>
                        <p>No <?= $cls['label'] ?> class seats configured for this route.</p>
                    </div>
                    <?php else: ?>

                    <!-- Berth guide -->
                    <div class="berth-guide">
                        <h6>Berth Guide</h6>
                        <div class="d-flex gap-3 flex-wrap">
                            <div class="bg-row"><span class="bg-pill lb">LB</span> Lower Berth — easiest access</div>
                            <div class="bg-row"><span class="bg-pill mb">MB</span> Middle Berth — moderate height</div>
                            <div class="bg-row"><span class="bg-pill ub">UB</span> Upper Berth — top level, most privacy</div>
                        </div>
                    </div>

                    <!-- Compartments -->
                    <div class="compartments">
                        <?php foreach (array_chunk($type_seats, 6) as $ci => $comp): ?>
                        <div class="compartment">
                            <div class="comp-label">Comp <?= chr(65 + $ci) ?></div>
                            <?php
                            $lower = []; $middle = []; $upper = [];
                            foreach ($comp as $s) {
                                $pos = (int)preg_replace('/[^0-9]/', '', $s['seat_number']);
                                if ($pos <= 2) $lower[] = $s;
                                elseif ($pos <= 4) $middle[] = $s;
                                else $upper[] = $s;
                            }
                            foreach ([['Lower','lb',$lower],['Middle','mb',$middle],['Upper','ub',$upper]] as [$bl,$bc,$bs]):
                                if (empty($bs)) continue;
                            ?>
                            <span class="berth-label">
                                <span class="bg-pill <?= $bc ?>" style="font-size:.55rem;"><?= strtoupper(substr($bl,0,2)).'B' ?></span>
                                <?= $bl ?>
                            </span>
                            <div class="berth-row">
                                <?php foreach ($bs as $seat):
                                    $is_avail = ($seat['status'] ?? '') === 'available';
                                    $price    = round($route['base_fare'] * $fare_mul[$type], 2);
                                ?>
                                <button type="button"
                                        class="seat-btn <?= $is_avail ? 'avail' : 'booked' ?>"
                                        data-seat-id="<?= (int)$seat['seat_id'] ?>"
                                        data-seat-num="<?= htmlspecialchars($seat['seat_number']) ?>"
                                        data-berth="<?= getBerth($seat['seat_number']) ?>"
                                        data-class="<?= $type ?>"
                                        data-class-label="<?= htmlspecialchars($cls['label']) ?>"
                                        data-price="<?= $price ?>"
                                        <?= $is_avail ? '' : 'disabled' ?>
                                        onclick="toggleSeat(this)">
                                    <span class="seat-num"><?= htmlspecialchars($seat['seat_number']) ?></span>
                                    <span class="berth-tag"><?= getBerthShort($seat['seat_number']) ?></span>
                                </button>
                                <?php endforeach; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Passenger Details -->
            <div class="surface-card mb-3" id="paxSection" style="display:none;">
                <div class="sc-head">
                    <h2><i class="bi bi-people-fill me-2" style="color:#059669;"></i>Passenger Details</h2>
                </div>
                <div class="sc-body" id="paxForms"></div>
            </div>

        </div><!-- /left -->

        <!-- RIGHT: Fare Sidebar -->
        <div style="width:280px;flex-shrink:0;" id="fareSidebar">

            <div class="fare-box">
                <div class="fb-label">Fare Breakdown</div>
                <div id="fareRows">
                    <div class="fare-row"><span style="opacity:.6;">Select seats to see fare</span></div>
                </div>
                <div class="fare-total">
                    <span class="ft-label">Total</span>
                    <span class="ft-val">Rs <span id="totalFareVal">0</span></span>
                </div>
            </div>

            <div class="berth-guide mb-3">
                <h6>Class Fares</h6>
                <?php foreach ($class_info as $type => $cls): ?>
                <div class="bg-row" style="margin-bottom:.4rem;">
                    <i class="bi <?= $cls['icon'] ?>" style="color:<?= $cls['color'] ?>;font-size:.9rem;"></i>
                    <span style="font-size:.78rem;"><strong><?= $cls['label'] ?></strong> — Rs <?= number_format(round($route['base_fare'] * $fare_mul[$type], 2), 0) ?>/seat</span>
                </div>
                <?php endforeach; ?>
            </div>

            <div id="hiddenSeats"></div>

            <button type="submit" class="btn btn-primary w-100 py-2 fw-bold" id="submitBtn" disabled
                    style="border-radius:12px;font-size:.92rem;">
                <i class="bi bi-credit-card me-2"></i>Proceed to Payment
            </button>
            <p class="text-muted text-center mt-2" style="font-size:.71rem;">
                <i class="bi bi-shield-check me-1 text-success"></i>Seats reserved on payment completion.
            </p>
        </div>

    </div><!-- /flex -->
    </form>

</div><!-- /bk-content -->
</div><!-- /bk-wrap -->

<script>
const selectedSeats = [];
const MAX_SEATS     = 6;

function switchClass(type) {
    document.querySelectorAll('.class-tab').forEach(t => t.classList.toggle('active', t.dataset.class === type));
    document.querySelectorAll('[id^="map_"]').forEach(m => m.style.display = 'none');
    const panel = document.getElementById('map_' + type);
    if (panel) panel.style.display = '';
}

function toggleSeat(btn) {
    const id  = parseInt(btn.dataset.seatId);
    const idx = selectedSeats.findIndex(s => s.seatId === id);
    if (idx !== -1) {
        selectedSeats.splice(idx, 1);
        btn.classList.remove('selected');
        btn.classList.add('avail');
    } else {
        if (selectedSeats.length >= MAX_SEATS) { alert('Maximum 6 seats per booking.'); return; }
        selectedSeats.push({
            seatId: id, seatNum: btn.dataset.seatNum,
            berth: btn.dataset.berth, cls: btn.dataset.class,
            clsLabel: btn.dataset.classLabel, price: parseFloat(btn.dataset.price),
        });
        btn.classList.remove('avail');
        btn.classList.add('selected');
    }
    render();
}

function removeSeat(seatId) {
    const idx = selectedSeats.findIndex(s => s.seatId === seatId);
    if (idx !== -1) {
        const btn = document.querySelector(`.seat-btn[data-seat-id="${seatId}"]`);
        if (btn) { btn.classList.remove('selected'); btn.classList.add('avail'); }
        selectedSeats.splice(idx, 1);
        render();
    }
}

function render() {
    const n = selectedSeats.length;

    // Chips
    document.getElementById('selChips').innerHTML = selectedSeats.map(s =>
        `<span class="sel-chip">
            <i class="bi bi-grid-1x2-fill" style="font-size:.65rem;"></i>
            ${esc(s.seatNum)} (${esc(s.berth)})
            <span class="rm-seat" onclick="removeSeat(${s.seatId})">&times;</span>
        </span>`
    ).join('');
    document.getElementById('selInfo').innerHTML = n
        ? `<i class="bi bi-check-circle-fill text-success me-1"></i>${n} seat${n>1?'s':''} selected`
        : `<i class="bi bi-info-circle me-1"></i>Click seats to select (max 6)`;

    // Fare
    let total = 0, fareHTML = '';
    selectedSeats.forEach(s => {
        total += s.price;
        fareHTML += `<div class="fare-row"><span>${esc(s.seatNum)} · ${esc(s.berth)} · ${esc(s.clsLabel)}</span><strong>Rs ${fmt(s.price)}</strong></div>`;
    });
    document.getElementById('fareRows').innerHTML   = fareHTML || '<div class="fare-row"><span style="opacity:.6;">Select seats to see fare</span></div>';
    document.getElementById('totalFareVal').textContent = fmt(total);

    // Hidden inputs
    document.getElementById('hiddenSeats').innerHTML = selectedSeats.map(s =>
        `<input type="hidden" name="selected_seats[]" value="${s.seatId}">`
    ).join('');

    // Passenger forms
    const paxSec = document.getElementById('paxSection');
    const paxDiv = document.getElementById('paxForms');
    if (n > 0) {
        paxSec.style.display = '';
        paxDiv.innerHTML = selectedSeats.map((s, i) => `
            <div class="pax-card">
                <div class="pax-card-head">
                    <div class="pax-num">${i+1}</div>
                    <div class="pax-seat-info">
                        Seat ${esc(s.seatNum)}
                        <small class="ms-1">· ${esc(s.berth)} Berth · ${esc(s.clsLabel)}</small>
                    </div>
                </div>
                <div class="row g-2">
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold" style="font-size:.8rem;">Full Name *</label>
                        <input type="text" name="passenger_name[]" class="form-control form-control-sm"
                               placeholder="Passenger name" required>
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label fw-semibold" style="font-size:.8rem;">Age *</label>
                        <input type="number" name="passenger_age[]" class="form-control form-control-sm"
                               min="1" max="120" placeholder="Age" required>
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label fw-semibold" style="font-size:.8rem;">Gender *</label>
                        <select name="passenger_gender[]" class="form-select form-select-sm" required>
                            <option value="M">Male</option>
                            <option value="F">Female</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>
            </div>
        `).join('');
    } else {
        paxSec.style.display = 'none';
        paxDiv.innerHTML = '';
    }

    document.getElementById('submitBtn').disabled = n === 0;

    // Update progress bar steps
    updateSteps(n);
}

function updateSteps(seatCount) {
    const steps = document.querySelectorAll('.bk-step');
    const connectors = document.querySelectorAll('.bk-connector');
    // Step 1: always done/active depending on seats
    if (seatCount > 0) {
        steps[0].classList.remove('active'); steps[0].classList.add('done');
        steps[1].classList.remove('done');   steps[1].classList.add('active');
        steps[2].classList.remove('active', 'done');
        if (connectors[0]) connectors[0].classList.add('done');
        if (connectors[1]) connectors[1].classList.remove('done');
    } else {
        steps[0].classList.add('active');    steps[0].classList.remove('done');
        steps[1].classList.remove('active', 'done');
        steps[2].classList.remove('active', 'done');
        if (connectors[0]) connectors[0].classList.remove('done');
        if (connectors[1]) connectors[1].classList.remove('done');
    }
}

function fmt(n) { return Math.round(n).toLocaleString('en-PK'); }
function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

document.getElementById('bookingForm').addEventListener('submit', function(e) {
    if (selectedSeats.length === 0) { e.preventDefault(); alert('Please select at least one seat.'); return; }
    const inputs = this.querySelectorAll('input[required], select[required]');
    let ok = true;
    inputs.forEach(inp => {
        if (!inp.value.trim()) { inp.classList.add('is-invalid'); ok = false; }
        else inp.classList.remove('is-invalid');
    });
    if (!ok) { e.preventDefault(); alert('Please fill in all passenger details.'); return; }
    // Mark step 3 active on valid submit
    const steps = document.querySelectorAll('.bk-step');
    const connectors = document.querySelectorAll('.bk-connector');
    steps[1].classList.remove('active'); steps[1].classList.add('done');
    steps[2].classList.add('active');
    if (connectors[1]) connectors[1].classList.add('done');
});
</script>

<?php require_once 'inc/footer.php'; ?>

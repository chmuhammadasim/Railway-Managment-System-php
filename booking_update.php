<?php
// booking_update.php - Change or update a booked journey (flexible: any route, seat count, seat class)

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/Train.php';
require_once 'src/classes/Booking.php';

if (!User::isLoggedIn()) {
    header('Location: login.php');
    exit();
}

function format_time_remaining($hours_remaining) {
    if ($hours_remaining <= 0) return 'Departed';
    $total_minutes = (int)round($hours_remaining * 60);
    $days    = intdiv($total_minutes, 1440);
    $hours   = intdiv($total_minutes % 1440, 60);
    $minutes = $total_minutes % 60;
    $parts = [];
    if ($days  > 0) $parts[] = $days . 'd';
    if ($hours > 0) $parts[] = $hours . 'h';
    if ($minutes > 0 && $days === 0) $parts[] = $minutes . 'm';
    return implode(' ', $parts);
}

$db = new Database();
$db->connect();

$user_id    = (int)$_SESSION['user_id'];
$booking_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($booking_id <= 0) { header('Location: bookings.php'); exit(); }

$booking_obj = new Booking($db);
$booking     = $booking_obj->getBookingById($booking_id);

if (!$booking || (int)$booking['user_id'] !== $user_id) { header('Location: bookings.php'); exit(); }
if ($booking['booking_status'] === 'cancelled') { header('Location: bookings.php'); exit(); }

$error_message   = '';
$success_message = '';
$redirect_target = '';

$current_route = $db->selectRow(
    "SELECT r.*, t.train_name, t.train_number, t.total_seats
     FROM routes r JOIN trains t ON r.train_id = t.train_id
     WHERE r.route_id = {$booking['route_id']}"
);

$passengers = $db->select(
    "SELECT bs.booking_seat_id, bs.passenger_name, bs.passenger_age, bs.passenger_gender,
            s.seat_number, s.seat_type
     FROM booking_seats bs
     JOIN seats s ON bs.seat_id = s.seat_id
     WHERE bs.booking_id = {$booking_id}
     ORDER BY bs.booking_seat_id ASC"
) ?: [];

$departure_timestamp   = $current_route
    ? strtotime(($current_route['journey_date'] ?? $booking['journey_date']) . ' ' . ($current_route['departure_time'] ?? '00:00:00'))
    : strtotime($booking['journey_date'] . ' 00:00:00');
$hours_until_departure = ($departure_timestamp - time()) / 3600;

if (empty($_SESSION['booking_update_csrf'])) {
    $_SESSION['booking_update_csrf'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['booking_update_csrf'];

// ── Fetch all future scheduled routes for the route-change picker ─────────
$all_routes = $db->select(
    "SELECT r.route_id, r.departure_city, r.arrival_city, r.journey_date,
            r.departure_time, r.arrival_time, r.distance_km, r.base_fare, r.available_seats,
            t.train_name, t.train_number
     FROM routes r
     JOIN trains t ON r.train_id = t.train_id
     WHERE r.status = 'scheduled'
       AND r.route_id != {$booking['route_id']}
       AND (r.journey_date > CURDATE()
            OR (r.journey_date = CURDATE() AND r.departure_time > CURTIME()))
     ORDER BY r.journey_date ASC, r.departure_time ASC"
) ?: [];

// Group cities for filter dropdowns
$all_dep_cities = array_unique(array_column($all_routes, 'departure_city'));
$all_arr_cities = array_unique(array_column($all_routes, 'arrival_city'));
sort($all_dep_cities);
sort($all_arr_cities);

// ── POST handling ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrf_token, $submitted_token)) {
        $error_message = 'Invalid request. Please refresh and try again.';

    } elseif (isset($_POST['update_passengers'])) {
        // ── 1. Edit passenger names/ages/genders ──────────────────────────
        $mapped = [];
        foreach ($_POST['passengers'] ?? [] as $bsid => $pdata) {
            $mapped[] = [
                'booking_seat_id'  => (int)$bsid,
                'passenger_name'   => $pdata['name']   ?? '',
                'passenger_age'    => $pdata['age']    ?? '',
                'passenger_gender' => $pdata['gender'] ?? '',
            ];
        }
        $result = $booking_obj->updatePassengerDetails($booking_id, $user_id, $mapped);
        if ($result['success']) {
            $_SESSION['booking_update_flash'] = $result['message'];
            header('Location: booking_update.php?id=' . $booking_id);
            exit();
        }
        $error_message = $result['message'];
        $passengers = $db->select(
            "SELECT bs.booking_seat_id, bs.passenger_name, bs.passenger_age, bs.passenger_gender,
                    s.seat_number, s.seat_type
             FROM booking_seats bs JOIN seats s ON bs.seat_id = s.seat_id
             WHERE bs.booking_id = {$booking_id} ORDER BY bs.booking_seat_id ASC"
        ) ?: [];

    } elseif ($hours_until_departure < 4) {
        $error_message = 'Journey changes are only allowed up to 4 hours before departure.';

    } elseif (isset($_POST['change_journey'])) {
        // ── 2. Full flexible journey change ───────────────────────────────
        $new_route_id  = (int)($_POST['new_route_id'] ?? 0);
        $pax_names     = $_POST['pax_name']   ?? [];
        $pax_ages      = $_POST['pax_age']    ?? [];
        $pax_genders   = $_POST['pax_gender'] ?? [];
        $pax_types     = $_POST['pax_type']   ?? [];

        if ($new_route_id <= 0) {
            $error_message = 'Please select a destination route.';
        } elseif (empty($pax_names) || count(array_filter(array_map('trim', $pax_names))) === 0) {
            $error_message = 'Please add at least one passenger.';
        } else {
            $new_passengers = [];
            $allowed_types  = ['economy', 'premium', 'luxury'];
            $allowed_gender = ['M', 'F', 'Other'];
            foreach ($pax_names as $i => $name) {
                $name = trim($name);
                if ($name === '') continue;
                $new_passengers[] = [
                    'passenger_name'   => $name,
                    'passenger_age'    => ($pax_ages[$i] ?? '') !== '' ? (int)$pax_ages[$i] : null,
                    'passenger_gender' => in_array($pax_genders[$i] ?? '', $allowed_gender, true) ? $pax_genders[$i] : 'Other',
                    'seat_type'        => in_array($pax_types[$i] ?? '', $allowed_types, true) ? $pax_types[$i] : 'economy',
                ];
            }
            if (empty($new_passengers)) {
                $error_message = 'Please add at least one passenger.';
            } else {
                $result = $booking_obj->updateBookingJourneyFlex($booking_id, $new_route_id, $user_id, $new_passengers);
                if ($result['success']) {
                    $_SESSION['booking_update_flash'] = $result['message'];
                    unset($_SESSION['booking_update_csrf']);
                    $redirect_target = $result['requires_payment']
                        ? 'payment.php?booking_id=' . $booking_id
                        : 'booking_details.php?id=' . $booking_id;
                    header('Location: ' . $redirect_target);
                    exit();
                }
                $error_message = $result['message'];
            }
        }
    }
}

// PRG flash
$passenger_flash = '';
if (!empty($_SESSION['booking_update_flash'])) {
    $passenger_flash = $_SESSION['booking_update_flash'];
    unset($_SESSION['booking_update_flash']);
}

$pageTitle = 'Update Booking – Railway Management System';
require_once 'inc/header.php';
?>

<style>
body { background: #f0f2f5; }
.page-wrap    { max-width: 980px; margin: 2rem auto; padding: 0 1rem 4rem; }
.sec-card     { background: #fff; border-radius: 16px; box-shadow: 0 4px 24px rgba(0,0,0,.07); margin-bottom: 1.5rem; overflow: hidden; }
.sec-hdr      { padding: .9rem 1.4rem; font-weight: 700; font-size: 1rem; display: flex; align-items: center; gap: .6rem; }
.sec-body     { padding: 1.4rem; }
.cur-booking-band { background: linear-gradient(135deg,#0f2040,#1a3c6e); color:#fff; border-radius:12px; padding:1.1rem 1.4rem; margin-bottom:1.2rem; }
.cur-booking-band .cb-route { font-size:1.25rem; font-weight:800; }
.cur-booking-band .cb-meta  { font-size:.82rem; opacity:.75; margin-top:.25rem; }
.pill { display:inline-flex;align-items:center;gap:.3rem;border-radius:999px;padding:.2em .75em;font-size:.76rem;font-weight:700; }
.pill-eco { background:#dbeafe;color:#1d4ed8; }
.pill-pre { background:#dcfce7;color:#15803d; }
.pill-lux { background:#ede9fe;color:#6d28d9; }
.pill-seats { background:#ecfdf5;color:#047857; }
.pill-due   { background:#fff7ed;color:#c2410c; }
.pill-credit{ background:#f5f3ff;color:#6d28d9; }
.pill-ok    { background:#ecfdf5;color:#047857; }
.route-card { border:2px solid #e2e8f0;border-radius:12px;padding:1rem 1.2rem;cursor:pointer;transition:all .18s;background:#fff;margin-bottom:.75rem; }
.route-card:hover { border-color:#2563eb;background:#f0f7ff; }
.route-card.selected { border-color:#2563eb;background:#eff6ff;box-shadow:0 4px 16px rgba(37,99,235,.12); }
.route-card input[type=radio] { accent-color:#2563eb; }
.pax-row  { background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:.85rem 1rem;margin-bottom:.65rem;position:relative; }
.pax-row .pax-num { width:28px;height:28px;border-radius:50%;background:#1a3c6e;color:#fff;font-size:.72rem;font-weight:700;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0; }
.btn-remove-pax { position:absolute;top:.55rem;right:.75rem;background:none;border:none;color:#ef4444;font-size:1.1rem;cursor:pointer;line-height:1; }
.fare-preview { background:#f0fdf4;border:1.5px solid #86efac;border-radius:12px;padding:1rem 1.2rem; }
.fare-preview .fp-total { font-size:1.6rem;font-weight:800;color:#15803d; }
.class-selector { display:flex;gap:.4rem;flex-wrap:wrap; }
.class-btn { border:2px solid #e2e8f0;border-radius:8px;padding:.3rem .8rem;font-size:.75rem;font-weight:700;cursor:pointer;background:#fff;transition:all .15s; }
.class-btn.eco.active { border-color:#2563eb;background:#dbeafe;color:#1d4ed8; }
.class-btn.pre.active { border-color:#15803d;background:#dcfce7;color:#15803d; }
.class-btn.lux.active { border-color:#6d28d9;background:#ede9fe;color:#6d28d9; }
.filter-row { display:flex;flex-wrap:wrap;gap:.6rem;margin-bottom:1rem;align-items:center; }
.filter-row select,.filter-row input { border:1.5px solid #d1d5db;border-radius:8px;padding:.35rem .7rem;font-size:.82rem; }
.deadline-bar { background:#fff3cd;border:1px solid #ffc107;border-radius:10px;padding:.75rem 1rem;margin-bottom:1rem;font-size:.88rem; }
.helper-text { font-size:.82rem;color:#64748b; }
#farePreview { display:none; }
</style>

<div class="page-wrap">
    <a href="bookings.php" class="btn btn-outline-secondary btn-sm mb-3">
        <i class="bi bi-arrow-left me-1"></i>Back to My Bookings
    </a>

    <?php if ($passenger_flash): ?>
    <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($passenger_flash) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
    <div class="alert alert-danger mb-3"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <!-- ══ Current booking banner ═══════════════════════════════════════════ -->
    <div class="cur-booking-band">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <div class="cb-route">
                    <?= htmlspecialchars($current_route['departure_city'] ?? '?') ?>
                    <i class="bi bi-arrow-right mx-2" style="font-size:1rem;opacity:.7;"></i>
                    <?= htmlspecialchars($current_route['arrival_city'] ?? '?') ?>
                </div>
                <div class="cb-meta">
                    <?= htmlspecialchars($current_route['train_name'] ?? '') ?>
                    &nbsp;·&nbsp;
                    <?= date('D, d M Y H:i', $departure_timestamp) ?>
                    &nbsp;·&nbsp;
                    <?= (int)$booking['number_of_seats'] ?> seat<?= $booking['number_of_seats'] > 1 ? 's' : '' ?>
                    &nbsp;·&nbsp; Ref: <strong><?= htmlspecialchars($booking['booking_reference']) ?></strong>
                </div>
            </div>
            <div class="text-end">
                <div style="font-size:1.2rem;font-weight:800;">Rs <?= number_format((float)$booking['total_fare'], 2) ?></div>
                <div class="cb-meta">
                    Time left: <strong><?= format_time_remaining($hours_until_departure) ?></strong>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ Section 1: Edit passenger details ════════════════════════════════ -->
    <?php if (!empty($passengers)): ?>
    <div class="sec-card">
        <div class="sec-hdr" style="background:#155e2a;color:#fff;">
            <i class="bi bi-person-lines-fill"></i> Edit Passenger Details
        </div>
        <div class="sec-body">
            <p class="helper-text mb-3">Update names, ages, or genders at any time before departure. This does not change your seat or fare.</p>
            <form method="POST" id="passengerForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="update_passengers" value="1">
                <?php foreach ($passengers as $idx => $pax): ?>
                <div class="pax-row d-flex align-items-start gap-3">
                    <span class="pax-num mt-1"><?= $idx + 1 ?></span>
                    <input type="hidden" name="passengers[<?= (int)$pax['booking_seat_id'] ?>][booking_seat_id]" value="<?= (int)$pax['booking_seat_id'] ?>">
                    <div class="flex-grow-1">
                        <div class="mb-1">
                            <span class="pill pill-<?= htmlspecialchars($pax['seat_type'] ?? 'eco')[0] === 'e' ? 'eco' : ($pax['seat_type'] === 'premium' ? 'pre' : 'lux') ?>">
                                <?= htmlspecialchars($pax['seat_number'] ?? '') ?> · <?= ucfirst($pax['seat_type'] ?? 'economy') ?>
                            </span>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-5">
                                <input type="text" class="form-control form-control-sm"
                                    name="passengers[<?= (int)$pax['booking_seat_id'] ?>][name]"
                                    value="<?= htmlspecialchars($pax['passenger_name'] ?? '') ?>"
                                    placeholder="Full name" required maxlength="100">
                            </div>
                            <div class="col-md-3">
                                <input type="number" class="form-control form-control-sm"
                                    name="passengers[<?= (int)$pax['booking_seat_id'] ?>][age]"
                                    value="<?= htmlspecialchars($pax['passenger_age'] ?? '') ?>"
                                    placeholder="Age" min="1" max="120">
                            </div>
                            <div class="col-md-4">
                                <select class="form-select form-select-sm" name="passengers[<?= (int)$pax['booking_seat_id'] ?>][gender]">
                                    <option value="">Gender</option>
                                    <option value="M"     <?= ($pax['passenger_gender'] ?? '') === 'M'     ? 'selected' : '' ?>>Male</option>
                                    <option value="F"     <?= ($pax['passenger_gender'] ?? '') === 'F'     ? 'selected' : '' ?>>Female</option>
                                    <option value="Other" <?= ($pax['passenger_gender'] ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="mt-3 d-flex gap-2">
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="bi bi-person-check me-1"></i>Save Passenger Details
                    </button>
                    <a href="booking_details.php?id=<?= $booking_id ?>" class="btn btn-outline-secondary btn-sm">View Ticket</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══ Section 2: Change journey ════════════════════════════════════════ -->
    <div class="sec-card">
        <div class="sec-hdr" style="background:#1a3c6e;color:#fff;">
            <i class="bi bi-arrow-repeat"></i> Change Journey
            <span style="margin-left:auto;font-size:.78rem;font-weight:500;opacity:.8;">Any route · Any seat class · Add/remove passengers</span>
        </div>
        <div class="sec-body">

        <?php if ($hours_until_departure < 4): ?>
            <div class="alert alert-warning mb-0">
                <i class="bi bi-lock-fill me-2"></i>
                <strong>Journey changes are locked.</strong>
                Changes are allowed up to 4 hours before departure.
                Your train departs in <strong><?= format_time_remaining($hours_until_departure) ?></strong>.
            </div>

        <?php else: ?>
            <div class="deadline-bar">
                <i class="bi bi-clock me-1"></i>
                You have <strong><?= format_time_remaining($hours_until_departure) ?></strong> to request a journey change (deadline: 4 hrs before departure).
            </div>

            <form method="POST" id="journeyForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="change_journey" value="1">

                <!-- ── Step A: Choose route ────────────────────────────────── -->
                <h6 class="fw-bold mb-2"><span class="badge bg-primary me-2">1</span>Select a New Route</h6>

                <div class="filter-row">
                    <select id="filterDep" onchange="filterRoutes()">
                        <option value="">Any departure city</option>
                        <?php foreach ($all_dep_cities as $city): ?>
                        <option value="<?= htmlspecialchars($city) ?>"><?= htmlspecialchars($city) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="filterArr" onchange="filterRoutes()">
                        <option value="">Any arrival city</option>
                        <?php foreach ($all_arr_cities as $city): ?>
                        <option value="<?= htmlspecialchars($city) ?>"><?= htmlspecialchars($city) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" id="filterDate" onchange="filterRoutes()" placeholder="Filter by date" min="<?= date('Y-m-d') ?>">
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearFilters()">
                        <i class="bi bi-x"></i> Clear
                    </button>
                </div>

                <?php if (empty($all_routes)): ?>
                <div class="alert alert-info">No future routes available right now.</div>
                <?php else: ?>
                <div id="routeList" style="max-height:360px;overflow-y:auto;padding-right:4px;">
                    <?php foreach ($all_routes as $rt): ?>
                    <?php
                        $fare_eco = round((float)$rt['base_fare'] * 1.0, 2);
                        $fare_pre = round((float)$rt['base_fare'] * 1.5, 2);
                        $fare_lux = round((float)$rt['base_fare'] * 2.5, 2);
                    ?>
                    <label class="route-card d-block"
                        data-route-card
                        data-dep="<?= htmlspecialchars($rt['departure_city']) ?>"
                        data-arr="<?= htmlspecialchars($rt['arrival_city']) ?>"
                        data-date="<?= htmlspecialchars($rt['journey_date']) ?>"
                        data-base="<?= (float)$rt['base_fare'] ?>"
                        data-seats="<?= (int)$rt['available_seats'] ?>">
                        <div class="d-flex align-items-start gap-2 flex-wrap justify-content-between">
                            <div>
                                <input type="radio" name="new_route_id" value="<?= (int)$rt['route_id'] ?>"
                                    onclick="onRouteSelect(this)" required>
                                <strong class="ms-1"><?= htmlspecialchars($rt['departure_city']) ?></strong>
                                <i class="bi bi-arrow-right mx-1 text-muted"></i>
                                <strong><?= htmlspecialchars($rt['arrival_city']) ?></strong>
                                <span class="text-muted ms-2" style="font-size:.8rem;"><?= htmlspecialchars($rt['train_name']) ?> · <?= htmlspecialchars($rt['train_number']) ?></span>
                            </div>
                            <div class="d-flex flex-wrap gap-1">
                                <span class="pill pill-seats"><?= (int)$rt['available_seats'] ?> seats</span>
                            </div>
                        </div>
                        <div class="mt-1 ps-3 text-muted" style="font-size:.8rem;">
                            <?= date('D, d M Y', strtotime($rt['journey_date'])) ?>
                            &nbsp;·&nbsp;
                            <?= date('H:i', strtotime($rt['departure_time'])) ?> → <?= date('H:i', strtotime($rt['arrival_time'])) ?>
                            &nbsp;·&nbsp; <?= number_format((float)$rt['distance_km'], 0) ?> km
                        </div>
                        <div class="mt-1 ps-3 d-flex gap-2" style="font-size:.75rem;">
                            <span>Economy <strong>Rs <?= number_format($fare_eco, 0) ?></strong></span>
                            <span>Premium <strong>Rs <?= number_format($fare_pre, 0) ?></strong></span>
                            <span>Luxury <strong>Rs <?= number_format($fare_lux, 0) ?></strong></span>
                            <span class="text-muted">per seat</span>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div id="noRoutesMsg" class="alert alert-info mt-2 d-none">No routes match your filters.</div>
                <?php endif; ?>

                <hr class="my-3">

                <!-- ── Step B: Passengers ─────────────────────────────────── -->
                <h6 class="fw-bold mb-1"><span class="badge bg-primary me-2">2</span>Passengers &amp; Seat Classes</h6>
                <p class="helper-text mb-2">Add, remove, or change passengers. Choose a seat class for each — fare updates automatically.</p>

                <div id="paxList">
                    <?php
                    // Pre-populate from existing passengers
                    $init_pax = !empty($passengers) ? $passengers : [['passenger_name'=>'','passenger_age'=>'','passenger_gender'=>'','seat_type'=>'economy']];
                    foreach ($init_pax as $i => $ip):
                    ?>
                    <div class="pax-row" id="paxRow<?= $i ?>">
                        <span class="pax-num"><?= $i + 1 ?></span>
                        <?php if ($i > 0): ?><button type="button" class="btn-remove-pax" onclick="removePax(<?= $i ?>)" title="Remove"><i class="bi bi-x-circle-fill"></i></button><?php endif; ?>
                        <div class="row g-2 mt-1">
                            <div class="col-sm-4">
                                <input type="text" class="form-control form-control-sm" name="pax_name[]"
                                    placeholder="Full name *" required
                                    value="<?= htmlspecialchars($ip['passenger_name'] ?? '') ?>">
                            </div>
                            <div class="col-sm-2">
                                <input type="number" class="form-control form-control-sm" name="pax_age[]"
                                    placeholder="Age" min="1" max="120"
                                    value="<?= htmlspecialchars($ip['passenger_age'] ?? '') ?>">
                            </div>
                            <div class="col-sm-3">
                                <select class="form-select form-select-sm" name="pax_gender[]">
                                    <option value="M"     <?= ($ip['passenger_gender'] ?? 'M') === 'M'     ? 'selected' : '' ?>>Male</option>
                                    <option value="F"     <?= ($ip['passenger_gender'] ?? '') === 'F'     ? 'selected' : '' ?>>Female</option>
                                    <option value="Other" <?= ($ip['passenger_gender'] ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>
                            <div class="col-sm-3">
                                <select class="form-select form-select-sm pax-class" name="pax_type[]" onchange="updateFare()">
                                    <option value="economy" <?= ($ip['seat_type'] ?? 'economy') === 'economy' ? 'selected' : '' ?>>Economy (×1.0)</option>
                                    <option value="premium" <?= ($ip['seat_type'] ?? '') === 'premium' ? 'selected' : '' ?>>Premium (×1.5)</option>
                                    <option value="luxury"  <?= ($ip['seat_type'] ?? '') === 'luxury'  ? 'selected' : '' ?>>Luxury (×2.5)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <button type="button" class="btn btn-outline-secondary btn-sm mt-1" onclick="addPax()" id="addPaxBtn">
                    <i class="bi bi-person-plus me-1"></i>Add Passenger
                </button>

                <hr class="my-3">

                <!-- ── Step C: Fare preview ───────────────────────────────── -->
                <div id="farePreview" class="fare-preview mb-3">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <div class="text-muted" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;">New Total Fare</div>
                            <div class="fp-total" id="fareTotal">Rs 0</div>
                            <div id="fareBreakdown" style="font-size:.8rem;color:#374151;margin-top:.3rem;"></div>
                        </div>
                        <div class="text-end">
                            <div class="text-muted" style="font-size:.75rem;">Current paid</div>
                            <div style="font-weight:700;">Rs <?= number_format((float)$booking['total_fare'], 2) ?></div>
                            <div id="fareDiff" class="mt-1" style="font-size:.82rem;font-weight:700;"></div>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2 flex-wrap">
                    <button type="submit" class="btn btn-primary" id="submitJourneyBtn" disabled>
                        <i class="bi bi-check-circle me-1"></i>Confirm Journey Change
                    </button>
                    <a href="booking_details.php?id=<?= $booking_id ?>" class="btn btn-outline-secondary">
                        Keep Current Journey
                    </a>
                </div>

            </form>
        <?php endif; ?>
        </div>
    </div>
</div>

<script>
// ── Route data for JS ──────────────────────────────────────────────────────
const ROUTES_DATA = <?= json_encode(array_map(function($r) {
    return [
        'route_id'       => (int)$r['route_id'],
        'departure_city' => $r['departure_city'],
        'arrival_city'   => $r['arrival_city'],
        'journey_date'   => $r['journey_date'],
        'base_fare'      => (float)$r['base_fare'],
        'available_seats'=> (int)$r['available_seats'],
    ];
}, $all_routes), JSON_UNESCAPED_UNICODE) ?>;

const CURRENT_FARE  = <?= (float)$booking['total_fare'] ?>;
const MULTIPLIERS   = { economy: 1.0, premium: 1.5, luxury: 2.5 };
let   selectedBase  = 0;
let   paxCount      = <?= count($init_pax) ?>;

// ── Filter routes ──────────────────────────────────────────────────────────
function filterRoutes() {
    const dep  = document.getElementById('filterDep').value.toLowerCase();
    const arr  = document.getElementById('filterArr').value.toLowerCase();
    const date = document.getElementById('filterDate').value;
    const cards = document.querySelectorAll('[data-route-card]');
    let visible = 0;
    cards.forEach(card => {
        const match =
            (!dep  || card.dataset.dep.toLowerCase()  === dep)  &&
            (!arr  || card.dataset.arr.toLowerCase()  === arr)  &&
            (!date || card.dataset.date === date);
        card.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    document.getElementById('noRoutesMsg').classList.toggle('d-none', visible > 0);
}

function clearFilters() {
    document.getElementById('filterDep').value  = '';
    document.getElementById('filterArr').value  = '';
    document.getElementById('filterDate').value = '';
    filterRoutes();
}

// ── Route selection ────────────────────────────────────────────────────────
function onRouteSelect(radio) {
    document.querySelectorAll('[data-route-card]').forEach(c => c.classList.remove('selected'));
    const label = radio.closest('[data-route-card]');
    if (label) label.classList.add('selected');
    selectedBase = parseFloat(label ? label.dataset.base : 0);
    updateFare();
    document.getElementById('submitJourneyBtn').disabled = false;
}

// ── Fare calculation ───────────────────────────────────────────────────────
function fmt(n) { return 'Rs ' + Math.round(n).toLocaleString('en-PK'); }

function updateFare() {
    if (!selectedBase) return;
    const selects = document.querySelectorAll('.pax-class');
    let total = 0, breakdown = [];
    selects.forEach((sel, i) => {
        const mul   = MULTIPLIERS[sel.value] || 1.0;
        const fare  = Math.round(selectedBase * mul * 100) / 100;
        const label = sel.value.charAt(0).toUpperCase() + sel.value.slice(1);
        total += fare;
        breakdown.push(`<span class="me-2">Pax ${i+1}: ${label} ${fmt(fare)}</span>`);
    });
    document.getElementById('fareTotal').textContent       = fmt(total);
    document.getElementById('fareBreakdown').innerHTML     = breakdown.join('');
    const diff = total - CURRENT_FARE;
    const diffEl = document.getElementById('fareDiff');
    if (Math.abs(diff) < 0.01) {
        diffEl.innerHTML = '<span style="color:#059669;">No change in fare</span>';
    } else if (diff > 0) {
        diffEl.innerHTML = `<span style="color:#c2410c;">+${fmt(diff)} extra due</span>`;
    } else {
        diffEl.innerHTML = `<span style="color:#6d28d9;">${fmt(diff)} credit</span>`;
    }
    document.getElementById('farePreview').style.display = '';
}

// ── Passenger management ───────────────────────────────────────────────────
function addPax() {
    if (paxCount >= 6) { alert('Maximum 6 passengers per booking.'); return; }
    const idx = paxCount++;
    const div = document.createElement('div');
    div.className = 'pax-row';
    div.id = 'paxRow' + idx;
    div.innerHTML = `
        <span class="pax-num">${idx + 1}</span>
        <button type="button" class="btn-remove-pax" onclick="removePax(${idx})" title="Remove"><i class="bi bi-x-circle-fill"></i></button>
        <div class="row g-2 mt-1">
            <div class="col-sm-4"><input type="text" class="form-control form-control-sm" name="pax_name[]" placeholder="Full name *" required></div>
            <div class="col-sm-2"><input type="number" class="form-control form-control-sm" name="pax_age[]" placeholder="Age" min="1" max="120"></div>
            <div class="col-sm-3">
                <select class="form-select form-select-sm" name="pax_gender[]">
                    <option value="M">Male</option><option value="F">Female</option><option value="Other">Other</option>
                </select>
            </div>
            <div class="col-sm-3">
                <select class="form-select form-select-sm pax-class" name="pax_type[]" onchange="updateFare()">
                    <option value="economy">Economy (×1.0)</option>
                    <option value="premium">Premium (×1.5)</option>
                    <option value="luxury">Luxury (×2.5)</option>
                </select>
            </div>
        </div>`;
    document.getElementById('paxList').appendChild(div);
    document.getElementById('addPaxBtn').disabled = paxCount >= 6;
    updateFare();
}

function removePax(idx) {
    const row = document.getElementById('paxRow' + idx);
    if (row) row.remove();
    // Renumber visible rows
    const rows = document.querySelectorAll('#paxList .pax-row');
    rows.forEach((r, i) => {
        const numEl = r.querySelector('.pax-num');
        if (numEl) numEl.textContent = i + 1;
    });
    paxCount = rows.length;
    document.getElementById('addPaxBtn').disabled = false;
    updateFare();
}
</script>

<?php require_once 'inc/footer.php'; ?>


if (!User::isLoggedIn()) {
    header('Location: login.php');
    exit();
}

function format_time_remaining($hours_remaining) {
    if ($hours_remaining <= 0) {
        return 'Departed';
    }

    $total_minutes = (int)round($hours_remaining * 60);
    $days = intdiv($total_minutes, 1440);
    $hours = intdiv($total_minutes % 1440, 60);
    $minutes = $total_minutes % 60;

    $parts = [];
    if ($days > 0) {
        $parts[] = $days . 'd';
    }
    if ($hours > 0) {
        $parts[] = $hours . 'h';
    }
    if ($minutes > 0 && $days === 0) {
        $parts[] = $minutes . 'm';
    }

    return implode(' ', $parts);
}

$db = new Database();
$db->connect();

$user_id = (int)$_SESSION['user_id'];
$booking_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($booking_id <= 0) {
    header('Location: bookings.php');
    exit();
}

$booking_obj = new Booking($db);
$booking = $booking_obj->getBookingById($booking_id);

if (!$booking || (int)$booking['user_id'] !== $user_id) {
    header('Location: bookings.php');
    exit();
}

if ($booking['booking_status'] === 'cancelled') {
    header('Location: bookings.php');
    exit();
}

$error_message = '';
$success_message = '';
$selected_route_id = (int)($_POST['new_route_id'] ?? 0);

$current_route = $db->selectRow(
    "SELECT r.*, t.train_name, t.train_number, t.total_seats
     FROM routes r
     JOIN trains t ON r.train_id = t.train_id
     WHERE r.route_id = {$booking['route_id']}"
);

$passengers = $db->select(
    "SELECT bs.booking_seat_id, bs.passenger_name, bs.passenger_age, bs.passenger_gender, s.seat_number, s.seat_type
     FROM booking_seats bs
     JOIN seats s ON bs.seat_id = s.seat_id
     WHERE bs.booking_id = {$booking_id}
     ORDER BY bs.booking_seat_id ASC"
);
if (!$passengers) {
    $passengers = [];
}

$seat_mix = ['economy' => 0, 'premium' => 0, 'luxury' => 0];
foreach ($passengers as $passenger) {
    $seat_type = $passenger['seat_type'] ?? 'economy';
    if (!isset($seat_mix[$seat_type])) {
        $seat_type = 'economy';
    }
    $seat_mix[$seat_type]++;
}

$departure_timestamp = $current_route
    ? strtotime(($current_route['journey_date'] ?? $booking['journey_date']) . ' ' . ($current_route['departure_time'] ?? '00:00:00'))
    : strtotime($booking['journey_date'] . ' 00:00:00');
$hours_until_departure = ($departure_timestamp - time()) / 3600;

if (empty($_SESSION['booking_update_csrf'])) {
    $_SESSION['booking_update_csrf'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['booking_update_csrf'];

$compatible_routes = [];
$route_search_count = 0;

if ($current_route) {
    $dep = $db->getConnection()->real_escape_string($current_route['departure_city']);
    $arr = $db->getConnection()->real_escape_string($current_route['arrival_city']);

    $candidate_routes = $db->select(
        "SELECT r.*, t.train_name, t.train_number, t.total_seats
         FROM routes r
         JOIN trains t ON r.train_id = t.train_id
         WHERE r.departure_city = '{$dep}'
           AND r.arrival_city = '{$arr}'
           AND r.status = 'scheduled'
           AND (
                r.journey_date > CURDATE()
                OR (r.journey_date = CURDATE() AND r.departure_time > CURTIME())
           )
           AND r.route_id != {$booking['route_id']}
         ORDER BY r.journey_date ASC, r.departure_time ASC"
    );

    foreach ($candidate_routes ?: [] as $candidate_route) {
        $route_search_count++;
        $preview = $booking_obj->getJourneyChangePreview($booking_id, (int)$candidate_route['route_id'], $user_id);
        if (!$preview['success']) {
            continue;
        }

        $compatible_routes[] = [
            'route_id' => (int)$candidate_route['route_id'],
            'train_name' => $candidate_route['train_name'],
            'train_number' => $candidate_route['train_number'],
            'journey_date' => $candidate_route['journey_date'],
            'departure_time' => $candidate_route['departure_time'],
            'arrival_time' => $candidate_route['arrival_time'],
            'distance_km' => $candidate_route['distance_km'],
            'available_seats' => (int)$candidate_route['available_seats'],
            'estimated_fare' => $preview['new_fare'],
            'fare_delta' => $preview['fare_delta'],
            'amount_due' => $preview['amount_due'],
            'credit_amount' => $preview['credit_amount'],
            'requires_payment' => $preview['requires_payment'],
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrf_token, $submitted_token)) {
        $error_message = 'Invalid request. Please refresh the page and try again.';
    } elseif (isset($_POST['update_passengers'])) {
        // ── Passenger detail edit ──────────────────────────────────────────
        $passengers_input = $_POST['passengers'] ?? [];
        $mapped = [];
        foreach ($passengers_input as $bsid => $pdata) {
            $mapped[] = [
                'booking_seat_id'  => (int)$bsid,
                'passenger_name'   => $pdata['name']   ?? '',
                'passenger_age'    => $pdata['age']    ?? '',
                'passenger_gender' => $pdata['gender'] ?? '',
            ];
        }
        $result = $booking_obj->updatePassengerDetails($booking_id, $user_id, $mapped);
        if ($result['success']) {
            // PRG redirect → avoid re-submit on refresh
            $_SESSION['booking_update_flash'] = $result['message'];
            header('Location: booking_update.php?id=' . $booking_id);
            exit();
        } else {
            $error_message = $result['message'];
        }
        // re-fetch passengers after attempted update
        $passengers = $db->select(
            "SELECT bs.booking_seat_id, bs.passenger_name, bs.passenger_age, bs.passenger_gender,
                    s.seat_number, s.seat_type
             FROM booking_seats bs
             JOIN seats s ON bs.seat_id = s.seat_id
             WHERE bs.booking_id = {$booking_id}
             ORDER BY bs.booking_seat_id ASC"
        ) ?: [];
    } elseif ($hours_until_departure < 4) {
        $error_message = 'Journey changes are only allowed up to 4 hours before departure.';
    } else {
        $new_route_id = isset($_POST['new_route_id']) ? (int)$_POST['new_route_id'] : 0;
        if ($new_route_id <= 0) {
            $error_message = 'Please select a new journey option.';
        } else {
            $result = $booking_obj->updateBookingJourney($booking_id, $new_route_id, $user_id);
            if ($result['success']) {
                $success_message = $result['message'];
                $redirect_target = $result['requires_payment']
                    ? 'payment.php?booking_id=' . $booking_id
                    : 'booking_details.php?id=' . $booking_id;
                header('Refresh: 2; url=' . $redirect_target);
            } else {
                $error_message = $result['message'];
            }
        }
    }
}

// Pick up PRG flash from passenger-update redirect
$passenger_flash = '';
if (!empty($_SESSION['booking_update_flash'])) {
    $passenger_flash = $_SESSION['booking_update_flash'];
    unset($_SESSION['booking_update_flash']);
}
$pageTitle = 'Change Journey - Railway Management System';

require_once 'inc/header.php';

?>

<style>

    body { background: #f0f2f5; }

    .page-wrap { max-width: 940px; margin: 2rem auto; padding: 0 1rem; }

    .current-route-card { background: #e8f0fe; border-left: 4px solid #1a3c6e; border-radius: 10px; padding: 1.2rem 1.5rem; margin-bottom: 1rem; }

    .route-option { border: 2px solid #dee2e6; border-radius: 10px; padding: 1rem 1.2rem; margin-bottom: 0.85rem; cursor: pointer; transition: all 0.2s; background: #fff; }

    .route-option:hover { border-color: #2d6a9f; background: #f7fbff; }

    .route-option input[type=radio] { margin-right: 0.6rem; }

    .route-option.selected { border-color: #1a3c6e; background: #eef5ff; box-shadow: 0 6px 18px rgba(26,60,110,.08); }

    .fare-pill { background: #1a3c6e; color: #fff; padding: 0.25em 0.7em; border-radius: 20px; font-weight: 600; }

    .status-pill { padding: 0.25em 0.7em; border-radius: 20px; font-size: 0.82rem; font-weight: 700; }

    .status-pill.due { background: #fff7ed; color: #c2410c; }

    .status-pill.covered { background: #ecfdf5; color: #047857; }

    .status-pill.credit { background: #f5f3ff; color: #6d28d9; }

    .status-pill.seats { background: #ecfdf5; color: #047857; }

    .deadline-warn { background: #fff3cd; border: 1px solid #ffc107; border-radius: 10px; padding: 0.9rem 1rem; }

    .seat-chip { display: inline-flex; align-items: center; gap: .35rem; background: #fff; border: 1px solid #cbd5e1; border-radius: 999px; padding: .25rem .7rem; font-size: .76rem; font-weight: 700; color: #334155; }

    .mix-chip { display: inline-flex; align-items: center; gap: .35rem; border-radius: 999px; padding: .2rem .65rem; font-size: .74rem; font-weight: 700; }

    .mix-economy { background: #dbeafe; color: #1d4ed8; }

    .mix-premium { background: #dcfce7; color: #15803d; }

    .mix-luxury { background: #ede9fe; color: #6d28d9; }

    .passenger-box { background: #fff; border: 1px solid #dbe3ef; border-radius: 10px; padding: .9rem 1rem; }

    .helper-text { font-size: .82rem; color: #64748b; }

</style>

    <div class="page-wrap">
        <a href="bookings.php" class="btn btn-outline-secondary mb-3">
            <i class="bi bi-arrow-left"></i> Back to Bookings
        </a>

        <?php if ($passenger_flash): ?>
        <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?= htmlspecialchars($passenger_flash) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-danger mb-3"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="alert alert-success mb-3">
                <?= htmlspecialchars($success_message) ?><br>
                <small><?= strpos($success_message, 'Additional payment') !== false ? 'Redirecting to payment...' : 'Redirecting to your updated ticket...' ?></small>
            </div>
        <?php endif; ?>

        <!-- ── Section 1: Edit passenger details (always shown for non-cancelled) ── -->
        <?php if (!empty($passengers)): ?>
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header" style="background:#155e2a; color:#fff;">
                <h5 class="mb-0"><i class="bi bi-person-lines-fill me-2"></i>Edit Passenger Details</h5>
            </div>
            <div class="card-body p-4">
                <p class="helper-text mb-3">
                    You can update passenger names, ages, and genders at any time before departure,
                    regardless of whether a journey change is available.
                </p>
                <form method="POST" id="passengerForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="update_passengers" value="1">

                    <?php foreach ($passengers as $idx => $passenger): ?>
                    <div class="passenger-box mb-3">
                        <input type="hidden" name="passengers[<?= (int)$passenger['booking_seat_id'] ?>][booking_seat_id]"
                               value="<?= (int)$passenger['booking_seat_id'] ?>">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="mix-chip <?= 'mix-' . htmlspecialchars($passenger['seat_type'] ?? 'economy') ?>">
                                <?= htmlspecialchars($passenger['seat_number'] ?? '') ?>
                                &nbsp;|&nbsp;<?= ucfirst($passenger['seat_type'] ?? 'economy') ?>
                            </span>
                            <span class="helper-text">Passenger <?= $idx + 1 ?></span>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-5">
                                <label class="form-label fw-semibold" style="font-size:.83rem;">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm"
                                       name="passengers[<?= (int)$passenger['booking_seat_id'] ?>][name]"
                                       value="<?= htmlspecialchars($passenger['passenger_name'] ?? '') ?>"
                                       required maxlength="100" placeholder="Full name">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold" style="font-size:.83rem;">Age</label>
                                <input type="number" class="form-control form-control-sm"
                                       name="passengers[<?= (int)$passenger['booking_seat_id'] ?>][age]"
                                       value="<?= htmlspecialchars($passenger['passenger_age'] ?? '') ?>"
                                       min="1" max="120" placeholder="e.g. 30">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" style="font-size:.83rem;">Gender</label>
                                <select class="form-select form-select-sm"
                                        name="passengers[<?= (int)$passenger['booking_seat_id'] ?>][gender]">
                                    <option value="">— Select —</option>
                                    <option value="M"     <?= ($passenger['passenger_gender'] ?? '') === 'M'     ? 'selected' : '' ?>>Male</option>
                                    <option value="F"     <?= ($passenger['passenger_gender'] ?? '') === 'F'     ? 'selected' : '' ?>>Female</option>
                                    <option value="Other" <?= ($passenger['passenger_gender'] ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="mt-2 d-flex gap-2">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-person-check me-1"></i>Save Passenger Details
                        </button>
                        <a href="booking_details.php?id=<?= $booking_id ?>" class="btn btn-outline-secondary">View Booking</a>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Section 2: Change journey (only available > 4 hrs before departure) ── -->
        <div class="card shadow-sm border-0">
            <div class="card-header" style="background:#1a3c6e; color:#fff;">
                <h5 class="mb-0"><i class="bi bi-arrow-repeat me-2"></i>Change Journey</h5>
            </div>
            <div class="card-body p-4">

                <?php if (!$current_route): ?>
                    <div class="alert alert-danger">Current route details could not be loaded for this booking.</div>

                <?php elseif ($hours_until_departure < 4): ?>
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-lock-fill me-2"></i>
                        <strong>Journey changes are locked.</strong>
                        You can request a journey change only up to 4 hours before departure.
                        Your booking departs in <strong><?= htmlspecialchars(format_time_remaining($hours_until_departure)) ?></strong>.
                    </div>

                <?php else: ?>

                <div class="current-route-card">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                        <div>
                            <h6 class="mb-2" style="color:#1a3c6e;"><i class="bi bi-ticket-perforated me-1"></i>Current Booking</h6>
                            <div><strong>Booking Ref:</strong> <?= htmlspecialchars($booking['booking_reference']) ?></div>
                            <div><strong>Train:</strong> <?= htmlspecialchars($current_route['train_name']) ?> (<?= htmlspecialchars($current_route['train_number']) ?>)</div>
                            <div><strong>Route:</strong> <?= htmlspecialchars($current_route['departure_city']) ?> → <?= htmlspecialchars($current_route['arrival_city']) ?></div>
                            <div><strong>Departure:</strong> <?= date('D, d M Y H:i', $departure_timestamp) ?></div>
                        </div>
                        <div class="text-sm-end">
                            <div><strong>Current Fare:</strong> Rs <?= number_format((float)$booking['total_fare'], 2) ?></div>
                            <div><strong>Seats:</strong> <?= (int)$booking['number_of_seats'] ?></div>
                            <div class="helper-text mt-1">Time remaining: <strong><?= htmlspecialchars(format_time_remaining($hours_until_departure)) ?></strong></div>
                        </div>
                    </div>
                </div>

                <div class="deadline-warn mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    Journey updates stay available until <strong>4 hours before departure</strong>.
                    Your current booking departs in <strong><?= htmlspecialchars(format_time_remaining($hours_until_departure)) ?></strong>.
                </div>

                <?php if (!empty($compatible_routes)): ?>
                    <h6 class="mb-2">Choose a replacement journey</h6>
                    <p class="helper-text mb-3">Only routes that can preserve your current seat-class mix are shown below.</p>

                    <form method="POST" id="journeyUpdateForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                        <div id="route-list">
                            <?php foreach ($compatible_routes as $route): ?>
                                <?php
                                $is_selected = $selected_route_id === (int)$route['route_id'];
                                $fare_delta = (float)$route['fare_delta'];
                                ?>
                                <label class="route-option d-block <?= $is_selected ? 'selected' : '' ?>" data-route-option>
                                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                        <div>
                                            <input type="radio" name="new_route_id" value="<?= (int)$route['route_id'] ?>" <?= $is_selected ? 'checked' : '' ?> required>
                                            <strong><?= htmlspecialchars($route['train_name']) ?></strong>
                                            <span class="text-muted">(<?= htmlspecialchars($route['train_number']) ?>)</span>
                                        </div>
                                        <div class="d-flex flex-wrap gap-2 justify-content-end">
                                            <span class="fare-pill">Rs <?= number_format((float)$route['estimated_fare'], 2) ?></span>
                                            <span class="status-pill seats"><?= (int)$route['available_seats'] ?> seats free</span>
                                            <?php if ((float)$route['amount_due'] > 0.009): ?>
                                            <span class="status-pill due">Pay extra Rs <?= number_format((float)$route['amount_due'], 2) ?></span>
                                            <?php elseif ((float)$route['credit_amount'] > 0.009): ?>
                                            <span class="status-pill credit">Rs <?= number_format((float)$route['credit_amount'], 2) ?> lower</span>
                                            <?php else: ?>
                                            <span class="status-pill covered">No extra payment</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="mt-2 text-muted" style="padding-left:1.5rem;">
                                        <strong><?= date('D, d M Y', strtotime($route['journey_date'])) ?></strong>
                                        &nbsp;|&nbsp; Departs <?= date('H:i', strtotime($route['departure_time'])) ?>
                                        &nbsp;|&nbsp; Arrives <?= date('H:i', strtotime($route['arrival_time'])) ?>
                                        &nbsp;|&nbsp; <?= number_format((float)$route['distance_km'], 0) ?> km
                                    </div>
                                    <div class="mt-2 helper-text" style="padding-left:1.5rem;">
                                        <?php if ($fare_delta > 0.009): ?>
                                            This route costs Rs <?= number_format($fare_delta, 2) ?> more than your current booking.
                                        <?php elseif ($fare_delta < -0.009): ?>
                                            This route costs Rs <?= number_format(abs($fare_delta), 2) ?> less than your current booking.
                                        <?php else: ?>
                                            This route keeps the same total fare as your current booking.
                                        <?php endif; ?>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div class="mt-3 d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-1"></i>Confirm Journey Change
                            </button>
                            <a href="booking_details.php?id=<?= $booking_id ?>" class="btn btn-outline-secondary">Keep Current Journey</a>
                        </div>
                    </form>

                <?php else: ?>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        <?php if ($route_search_count > 0): ?>
                            No compatible alternative trains were found between
                            <strong><?= htmlspecialchars($current_route['departure_city']) ?></strong> and
                            <strong><?= htmlspecialchars($current_route['arrival_city']) ?></strong> that match your seat classes.
                            You can still edit passenger details above.
                        <?php else: ?>
                            No other scheduled trains are currently available for this route.
                            If you need to cancel your booking you can do so from
                            <a href="booking_cancel.php?id=<?= $booking_id ?>">here</a>.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php endif; ?>

            </div><!-- /card-body -->
        </div><!-- /card -->

    </div><!-- /page-wrap -->

    <script>
    (function () {
        var options = document.querySelectorAll('[data-route-option]');
        options.forEach(function (option) {
            option.addEventListener('click', function () {
                options.forEach(function (item) { item.classList.remove('selected'); });
                option.classList.add('selected');
                var radio = option.querySelector('input[type="radio"]');
                if (radio) { radio.checked = true; }
            });
        });
    }());
    </script>

<?php require_once 'inc/footer.php'; ?>

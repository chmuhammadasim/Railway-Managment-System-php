<?php
// booking_details.php – E-Ticket / Booking Receipt

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/Booking.php';

if (!User::isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$db = new Database();
$db->connect();

$user_id    = $_SESSION['user_id'];
$booking_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$booking_id) {
    header('Location: bookings.php');
    exit();
}

$booking = $db->selectRow(
    "SELECT b.*, r.departure_city, r.arrival_city, r.departure_time, r.arrival_time,
            r.distance_km, r.journey_date AS route_journey_date, r.base_fare,
            t.train_name, t.train_number, t.train_type,
            u.full_name AS booker_name, u.email AS booker_email, u.phone AS booker_phone
     FROM bookings b
     JOIN routes  r ON b.route_id  = r.route_id
     JOIN trains  t ON r.train_id  = t.train_id
     JOIN users   u ON b.user_id   = u.user_id
     WHERE b.booking_id = {$booking_id}"
);

if (!$booking || ($booking['user_id'] != $user_id && $_SESSION['role'] !== 'admin')) {
    header('Location: bookings.php');
    exit();
}

$passengers = $db->select(
    "SELECT bs.*, s.seat_number, s.seat_type
     FROM booking_seats bs
     JOIN seats s ON bs.seat_id = s.seat_id
     WHERE bs.booking_id = {$booking_id}"
);
if (!$passengers) $passengers = [];

$payment = $db->selectRow(
    "SELECT * FROM payments WHERE booking_id = {$booking_id} ORDER BY created_at DESC LIMIT 1"
);

// Compute modify eligibility
$journey_ts  = strtotime($booking['journey_date'] . ' 00:00:00');
$hours_left  = ($journey_ts - time()) / 3600;
$can_modify  = ($booking['booking_status'] !== 'cancelled') && $hours_left >= 24;
$is_admin    = ($_SESSION['role'] === 'admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-Ticket #<?= htmlspecialchars($booking['booking_reference']) ?> – Railway System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="public/css/style.css">
    <style>
        body { background: #eef2f7; }

        /* ── Ticket wrapper ── */
        .ticket-wrapper { max-width: 860px; margin: 0 auto; }
        .ticket  { background: #fff; border-radius: 16px; box-shadow: 0 6px 28px rgba(0,0,0,.13); overflow: hidden; }

        /* ── Header ── */
        .tkt-header {
            background: linear-gradient(135deg, #0f2d5c 0%, #1a5276 50%, #2980b9 100%);
            color: #fff; padding: 1.6rem 2rem;
        }
        .tkt-logo  { font-size: 1.7rem; font-weight: 800; letter-spacing: -.5px; }
        .tkt-ref   { font-size: .9rem; opacity: .8; letter-spacing: 1px; margin-top: .2rem; }
        .tkt-ref strong { font-size: 1.05rem; letter-spacing: 2px; opacity: 1; }

        /* ── Status pill ── */
        .s-pill { display:inline-block; padding:.3em .9em; border-radius:20px; font-weight:700; font-size:.82rem; }
        .s-confirmed { background:#d1fae5; color:#065f46; }
        .s-pending   { background:#fef3c7; color:#78350f; }
        .s-cancelled { background:#fee2e2; color:#7f1d1d; }
        .s-completed { background:#d1fae5; color:#065f46; }
        .s-refunded  { background:#ede9fe; color:#4c1d95; }
        .s-failed    { background:#fee2e2; color:#7f1d1d; }

        /* ── Journey timeline ── */
        .journey-bar {
            background: #f8f9ff; border-radius: 12px;
            padding: 1.4rem 1.6rem; margin-bottom: 1.5rem;
        }
        .jb-city     { flex: 1; text-align: center; }
        .jb-name     { font-size: 1.5rem; font-weight: 800; color: #0f2d5c; }
        .jb-time     { font-size: 1rem; font-weight: 600; color: #374151; }
        .jb-label    { font-size: .72rem; text-transform: uppercase; letter-spacing: .5px; color: #9ca3af; }
        .jb-mid      { flex: 1; text-align: center; position: relative; }
        .jb-line     { position: relative; height: 3px; background: linear-gradient(90deg, #0f2d5c, #2980b9); border-radius: 3px; margin: .6rem 0; }
        .jb-line::before, .jb-line::after {
            content: ''; position: absolute; top: 50%;
            width: 10px; height: 10px; border-radius: 50%;
            background: #0f2d5c; transform: translateY(-50%);
        }
        .jb-line::before { left: 0; }
        .jb-line::after  { right: 0; background: #2980b9; }
        .jb-train-icon { font-size: 1.5rem; color: #2980b9; }
        .jb-dist     { font-size: .75rem; color: #6b7280; }

        /* ── Dashed separator ── */
        .dashed-sep {
            position: relative; margin: 0;
            border: none; border-top: 2px dashed #dee2e6;
        }
        .dashed-sep::before,
        .dashed-sep::after {
            content: ''; position: absolute; top: 50%;
            width: 22px; height: 22px; border-radius: 50%;
            background: #eef2f7; transform: translateY(-50%);
            border: 2px dashed #dee2e6;
        }
        .dashed-sep::before { left: -12px; }
        .dashed-sep::after  { right: -12px; }

        /* ── Info grid ── */
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(185px, 1fr)); gap: .85rem; margin-bottom: 1.5rem; }
        .info-cell { background: #f8fafc; border-radius: 10px; padding: .75rem 1rem; }
        .info-cell .lbl { font-size: .72rem; text-transform: uppercase; letter-spacing: .4px; color: #9ca3af; display: block; margin-bottom: .15rem; }
        .info-cell .val { font-size: .93rem; font-weight: 600; color: #1e293b; }

        /* ── Passenger table ── */
        .pax-table thead th { background: #0f2d5c; color: #fff; font-weight: 600; }

        /* ── Barcode (SVG style) ── */
        .barcode-svg { display: flex; align-items: flex-end; gap: 2px; justify-content: center; height: 48px; }
        .barcode-svg span {
            display: inline-block; background: #0f2d5c;
            width: 3px; border-radius: 1px;
        }

        /* ── Fare box ── */
        .fare-box {
            background: linear-gradient(135deg, #0f2d5c, #2980b9);
            color: #fff; border-radius: 12px; padding: 1.1rem 1.4rem; text-align: right;
        }
        .fare-box .label  { font-size: .85rem; opacity: .8; }
        .fare-box .amount { font-size: 2rem; font-weight: 800; }
        .fare-box .txn    { font-size: .75rem; opacity: .7; margin-top: .2rem; }

        /* ── Ticket footer ── */
        .tkt-footer {
            padding: 1.1rem 2rem; background: #fafafa;
            border-top: 2px dashed #dee2e6; font-size: .83rem; color: #64748b;
        }

        /* ── Payment history ── */
        .pay-hist-item { border-left: 3px solid #2980b9; padding: .5rem .75rem .5rem 1rem; background: #f8f9ff; border-radius: 0 8px 8px 0; }

        @media print {
            body, html { background: white; }
            .no-print  { display: none !important; }
            .ticket    { box-shadow: none; border-radius: 0; }
            .ticket-wrapper { max-width: 100%; margin: 0; }
            .tkt-footer { break-inside: avoid; }
        }
        @media (max-width: 576px) {
            .jb-name  { font-size: 1.2rem; }
            .tkt-header { padding: 1rem 1.1rem; }
        }
    </style>
</head>
<body>

<!-- ── Navbar (hidden on print) ── -->
<nav class="navbar navbar-expand-lg navbar-dark no-print">
    <div class="container">
        <a class="navbar-brand fw-bold" href="index.php">🚂 Railway System</a>
        <div class="d-flex gap-2">
            <?php if ($is_admin): ?>
            <a href="manage-bookings.php" class="btn btn-outline-light btn-sm">
                <i class="bi bi-list-check me-1"></i>Manage Bookings
            </a>
            <?php else: ?>
            <a href="bookings.php" class="btn btn-outline-light btn-sm">
                <i class="bi bi-list-ul me-1"></i>My Bookings
            </a>
            <?php endif; ?>
            <a href="logout.php" class="btn btn-danger btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="container py-4">
<div class="ticket-wrapper">

    <!-- ── Action bar ── -->
    <div class="d-flex justify-content-between align-items-center mb-3 no-print flex-wrap gap-2">
        <a href="<?= $is_admin ? 'manage-bookings.php' : 'bookings.php' ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i><?= $is_admin ? 'Manage Bookings' : 'My Bookings' ?>
        </a>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($can_modify): ?>
            <a href="booking_update.php?id=<?= $booking_id ?>" class="btn btn-warning btn-sm">
                <i class="bi bi-pencil-square me-1"></i>Change Journey
            </a>
            <a href="booking_cancel.php?id=<?= $booking_id ?>" class="btn btn-danger btn-sm">
                <i class="bi bi-x-circle me-1"></i>Cancel
            </a>
            <?php elseif ($booking['booking_status'] !== 'cancelled' && !$is_admin && $hours_left < 24 && $hours_left > 0): ?>
            <span class="badge bg-secondary py-2 px-3">
                <i class="bi bi-lock-fill me-1"></i>Modifications locked (< 24 hrs)
            </span>
            <?php endif; ?>
            <?php if (!$payment && $booking['payment_status'] === 'pending' && $booking['booking_status'] !== 'cancelled'): ?>
            <a href="payment.php?booking_id=<?= $booking_id ?>" class="btn btn-success btn-sm">
                <i class="bi bi-credit-card me-1"></i>Pay Now
            </a>
            <?php endif; ?>
            <button onclick="window.print()" class="btn btn-primary btn-sm">
                <i class="bi bi-printer me-1"></i>Print
            </button>
        </div>
    </div>

    <!-- ── Ticket ── -->
    <div class="ticket">

        <!-- Header -->
        <div class="tkt-header d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <div class="tkt-logo">🚂 Pakistan Railways</div>
                <div class="tkt-ref">E-Ticket &nbsp;|&nbsp; Ref: <strong><?= htmlspecialchars($booking['booking_reference']) ?></strong></div>
                <div style="font-size:.78rem; opacity:.7; margin-top:.3rem;">
                    Issued: <?= date('d M Y H:i', strtotime($booking['booking_date'])) ?>
                </div>
            </div>
            <div class="text-end">
                <span class="s-pill s-<?= strtolower($booking['booking_status']) ?>">
                    <?= ucfirst($booking['booking_status']) ?>
                </span>
                <?php if ($payment): ?>
                <div class="mt-1">
                    <span class="s-pill s-<?= strtolower($payment['payment_status']) ?>" style="font-size:.73rem;">
                        Payment <?= ucfirst($payment['payment_status']) ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="p-3 p-md-4">

            <!-- Journey Timeline -->
            <div class="journey-bar d-flex align-items-center gap-3">
                <div class="jb-city">
                    <div class="jb-name"><?= htmlspecialchars($booking['departure_city']) ?></div>
                    <div class="jb-time"><?= date('H:i', strtotime($booking['departure_time'])) ?></div>
                    <div class="jb-label">Departure</div>
                </div>
                <div class="jb-mid flex-grow-1">
                    <div class="jb-train-icon mb-1">🚄</div>
                    <div class="jb-line"></div>
                    <div class="jb-dist"><?= number_format($booking['distance_km'], 0) ?> km &nbsp;·&nbsp; <?= date('D, d M Y', strtotime($booking['journey_date'])) ?></div>
                </div>
                <div class="jb-city">
                    <div class="jb-name"><?= htmlspecialchars($booking['arrival_city']) ?></div>
                    <div class="jb-time"><?= date('H:i', strtotime($booking['arrival_time'])) ?></div>
                    <div class="jb-label">Arrival</div>
                </div>
            </div>

            <!-- Info Grid -->
            <div class="info-grid">
                <?php foreach ([
                    ['Train',            htmlspecialchars($booking['train_name']) . ' (' . htmlspecialchars($booking['train_number']) . ')'],
                    ['Class / Type',     htmlspecialchars($booking['train_type'])],
                    ['Journey Date',     date('D, d M Y', strtotime($booking['journey_date']))],
                    ['Seats Booked',     (int)$booking['number_of_seats']],
                    ['Passenger / Booker', htmlspecialchars($booking['booker_name'])],
                    ['Contact',          htmlspecialchars($booking['booker_phone'] ?: $booking['booker_email'])],
                    ['Payment Method',   $payment ? ucwords(str_replace('_', ' ', $payment['payment_method'])) : '—'],
                    ['Base Fare / Seat', 'Rs. ' . number_format($booking['base_fare'], 2)],
                ] as [$lbl, $val]): ?>
                <div class="info-cell">
                    <span class="lbl"><?= $lbl ?></span>
                    <span class="val"><?= $val ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Dashed separator -->
            <hr class="dashed-sep my-4">

            <!-- Passenger table -->
            <?php if (!empty($passengers)): ?>
            <h6 class="fw-bold text-uppercase mb-3" style="letter-spacing:.5px; color:#1a3c6e;">
                <i class="bi bi-people-fill me-2"></i>Passenger Details
            </h6>
            <div class="table-responsive mb-4">
                <table class="table table-bordered pax-table mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Passenger Name</th>
                            <th>Age</th>
                            <th>Gender</th>
                            <th>Seat No.</th>
                            <th>Class</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($passengers as $i => $p): ?>
                        <tr>
                            <td class="text-center"><?= $i + 1 ?></td>
                            <td><?= htmlspecialchars($p['passenger_name']) ?></td>
                            <td class="text-center"><?= htmlspecialchars($p['passenger_age'] ?? '—') ?></td>
                            <td class="text-center"><?= $p['passenger_gender'] === 'M' ? 'Male' : ($p['passenger_gender'] === 'F' ? 'Female' : ($p['passenger_gender'] ?? '—')) ?></td>
                            <td class="text-center fw-bold"><?= htmlspecialchars($p['seat_number']) ?></td>
                            <td class="text-center"><?= ucfirst($p['seat_type']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="alert alert-info mb-4">
                <i class="bi bi-info-circle me-2"></i>No passenger seat details recorded for this booking.
            </div>
            <?php endif; ?>

            <!-- Barcode + Fare -->
            <div class="row align-items-center g-3 mb-3">
                <div class="col-md-6 text-center">
                    <?php
                    // Deterministic bar heights from booking reference string
                    $ref = $booking['booking_reference'];
                    $bars = str_split(preg_replace('/[^A-Z0-9]/', '', strtoupper($ref)));
                    ?>
                    <div class="barcode-svg mb-2" aria-label="Booking barcode">
                        <?php
                        $seed = array_map('ord', $bars);
                        $pattern = [];
                        for ($b = 0; $b < 42; $b++) {
                            $h = 20 + ($seed[$b % count($seed)] * 1.1 + $b * 3) % 30;
                            $pattern[] = round($h);
                        }
                        foreach ($pattern as $h): ?>
                        <span style="height:<?= $h ?>px;"></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="small text-muted" style="letter-spacing:2px; font-family:monospace;">
                        <?= htmlspecialchars($booking['booking_reference']) ?>
                    </div>
                    <div style="font-size:.7rem; color:#aaa; margin-top:.2rem;">Scan at entry gate</div>
                </div>
                <div class="col-md-6">
                    <div class="fare-box">
                        <div class="label">Total Fare</div>
                        <div class="amount">Rs. <?= number_format($booking['total_fare'], 2) ?></div>
                        <?php if ($payment && $payment['transaction_id']): ?>
                        <div class="txn">TXN: <?= htmlspecialchars($payment['transaction_id']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Payment history (if available) -->
            <?php if ($payment): ?>
            <div class="mb-3">
                <h6 class="fw-bold text-uppercase mb-2" style="letter-spacing:.5px; color:#1a3c6e; font-size:.8rem;">
                    <i class="bi bi-clock-history me-2"></i>Payment Record
                </h6>
                <div class="pay-hist-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <span class="fw-semibold"><?= ucwords(str_replace('_', ' ', $payment['payment_method'])) ?></span>
                        <span class="text-muted small ms-2"><?= $payment['payment_date'] ? date('d M Y H:i', strtotime($payment['payment_date'])) : '—' ?></span>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <span class="fw-bold">Rs. <?= number_format($payment['amount'], 2) ?></span>
                        <span class="s-pill s-<?= strtolower($payment['payment_status']) ?>" style="font-size:.75rem;">
                            <?= ucfirst($payment['payment_status']) ?>
                        </span>
                    </div>
                </div>
                <?php if ($payment['refund_date']): ?>
                <div class="pay-hist-item mt-2 d-flex flex-wrap justify-content-between align-items-center gap-2"
                     style="border-color:#9333ea;">
                    <div>
                        <span class="fw-semibold text-purple">Refund Processed</span>
                        <span class="text-muted small ms-2"><?= date('d M Y H:i', strtotime($payment['refund_date'])) ?></span>
                    </div>
                    <?php if ($payment['refund_reason']): ?>
                    <div class="text-muted small"><?= htmlspecialchars($payment['refund_reason']) ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div><!-- /p-4 -->

        <!-- Ticket Footer -->
        <div class="tkt-footer">
            <div class="row g-3">
                <div class="col-md-8">
                    <strong><i class="bi bi-info-circle me-1"></i>Important Notes:</strong>
                    <ul class="mb-0 mt-1 ps-3">
                        <li>Carry a valid CNIC / ID proof during travel.</li>
                        <li>Arrive at the station at least 30 minutes before departure.</li>
                        <li>Cancellations are only accepted up to 24 hours before journey.</li>
                        <li>This e-ticket is valid only for the specified date and route.</li>
                        <li>For assistance, call helpline: <strong>051-9201818</strong></li>
                    </ul>
                </div>
                <div class="col-md-4 text-md-end mt-md-0 mt-2">
                    <div class="fw-bold text-primary mb-1">Pakistan Railways</div>
                    <div class="small">📞 051-9201818</div>
                    <div class="small">🌐 www.pakrail.gov.pk</div>
                    <div class="small mt-1 text-muted">Printed: <?= date('d M Y H:i') ?></div>
                </div>
            </div>
        </div>

    </div><!-- /ticket -->
</div><!-- /ticket-wrapper -->
</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

$db = new Database();
$db->connect();

$user_id   = $_SESSION['user_id'];
$booking_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$booking_id) {
    header('Location: bookings.php');
    exit();
}

// Load full booking with route + train info
$booking = $db->selectRow(
    "SELECT b.*, r.departure_city, r.arrival_city, r.departure_time, r.arrival_time,
            r.distance_km, r.journey_date AS route_journey_date, r.base_fare,
            t.train_name, t.train_number, t.train_type,
            u.full_name AS booker_name, u.email AS booker_email, u.phone AS booker_phone
     FROM bookings b
     JOIN routes  r ON b.route_id = r.route_id
     JOIN trains  t ON r.train_id = t.train_id
     JOIN users   u ON b.user_id  = u.user_id
     WHERE b.booking_id = {$booking_id}"
);

// Only the ticket owner (or admin) may view
if (!$booking || ($booking['user_id'] != $user_id && $_SESSION['role'] !== 'admin')) {
    header('Location: bookings.php');
    exit();
}

// Load passenger list
$passengers = $db->select(
    "SELECT bs.*, s.seat_number, s.seat_type
     FROM booking_seats bs
     JOIN seats s ON bs.seat_id = s.seat_id
     WHERE bs.booking_id = {$booking_id}"
);
if (!$passengers) $passengers = array();

// Load payment info
$payment = $db->selectRow("SELECT * FROM payments WHERE booking_id = {$booking_id} ORDER BY created_at DESC LIMIT 1");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-Ticket #<?= htmlspecialchars($booking['booking_reference']) ?> - Railway Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="public/css/style.css">
    <style>
        body { background: #f0f2f5; }
        .ticket-wrapper { max-width: 820px; margin: 2rem auto; }
        .ticket { background: #fff; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.12); overflow: hidden; }
        .ticket-header { background: linear-gradient(135deg, #1a3c6e 0%, #2d6a9f 100%); color: #fff; padding: 1.5rem 2rem; }
        .ticket-header h2 { font-size: 1.6rem; margin: 0; }
        .ticket-header .ref { font-size: 1.1rem; opacity: 0.85; letter-spacing: 1px; }
        .ticket-body { padding: 1.5rem 2rem; }
        .route-strip { display: flex; align-items: center; gap: 1rem; background: #f8f9fa; border-radius: 8px; padding: 1.2rem 1.5rem; margin-bottom: 1.5rem; }
        .route-strip .city { text-align: center; flex: 1; }
        .route-strip .city strong { display: block; font-size: 1.4rem; font-weight: 700; color: #1a3c6e; }
        .route-strip .city span { font-size: 0.85rem; color: #666; }
        .route-strip .arrow { font-size: 2rem; color: #2d6a9f; flex-shrink: 0; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .info-item { background: #f8f9fa; border-radius: 8px; padding: 0.8rem 1rem; }
        .info-item label { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px; color: #888; display: block; margin-bottom: 0.2rem; }
        .info-item strong { font-size: 0.95rem; color: #222; }
        .passenger-table th { background: #1a3c6e; color: #fff; }
        .status-badge { display: inline-block; padding: 0.3em 0.9em; border-radius: 20px; font-weight: 600; font-size: 0.85rem; }
        .status-confirmed { background: #d4edda; color: #155724; }
        .status-pending   { background: #fff3cd; color: #856404; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        .fare-box { background: linear-gradient(135deg, #1a3c6e, #2d6a9f); color: #fff; border-radius: 8px; padding: 1rem 1.5rem; text-align: right; }
        .fare-box .label { font-size: 0.9rem; opacity: 0.85; }
        .fare-box .amount { font-size: 2rem; font-weight: 700; }
        .barcode { font-family: 'Courier New', monospace; font-size: 2.2rem; letter-spacing: 6px; color: #1a3c6e; }
        .ticket-footer { border-top: 2px dashed #dee2e6; padding: 1rem 2rem; background: #fafafa; font-size: 0.85rem; color: #666; }
        @media print {
            body { background: white; }
            .no-print { display: none !important; }
            .ticket { box-shadow: none; }
            .ticket-wrapper { margin: 0; max-width: 100%; }
        }
    </style>
</head>
<body>
    <!-- Navbar (hidden on print) -->
    <nav class="navbar navbar-expand-lg navbar-dark no-print">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php">🚂 Railway System</a>
            <div>
                <a href="bookings.php" class="btn btn-outline-light btn-sm me-2">My Bookings</a>
                <a href="logout.php" class="btn btn-danger btn-sm">Logout</a>
            </div>
        </div>
    </nav>

    <div class="ticket-wrapper">
        <!-- Action Buttons -->
        <div class="d-flex justify-content-between align-items-center mb-3 no-print">
            <a href="bookings.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Bookings
            </a>
            <div>
                <?php if ($booking['booking_status'] !== 'cancelled'): ?>
                    <?php
                    $journey_dt  = strtotime($booking['journey_date'] . ' 00:00:00');
                    $can_modify  = ($journey_dt - time()) / 3600 >= 24;
                    ?>
                    <?php if ($can_modify): ?>
                        <a href="booking_update.php?id=<?= $booking_id ?>" class="btn btn-warning me-2">
                            <i class="bi bi-pencil-square"></i> Change Journey
                        </a>
                        <a href="booking_cancel.php?id=<?= $booking_id ?>" class="btn btn-danger me-2">
                            <i class="bi bi-x-circle"></i> Cancel Booking
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
                <button onclick="window.print()" class="btn btn-primary">
                    <i class="bi bi-printer"></i> Print / Download
                </button>
            </div>
        </div>

        <!-- Ticket -->
        <div class="ticket">
            <!-- Header -->
            <div class="ticket-header d-flex justify-content-between align-items-start">
                <div>
                    <div style="font-size:1.5rem; margin-bottom:0.3rem;">🚂 Pakistan Railways – E-Ticket</div>
                    <div class="ref">Booking Reference: <strong><?= htmlspecialchars($booking['booking_reference']) ?></strong></div>
                </div>
                <div class="text-end">
                    <span class="status-badge status-<?= strtolower($booking['booking_status']) ?>">
                        <?= ucfirst($booking['booking_status']) ?>
                    </span>
                    <div style="font-size:0.8rem; opacity:0.8; margin-top:0.3rem;">
                        Issued: <?= date('d M Y, H:i', strtotime($booking['booking_date'])) ?>
                    </div>
                </div>
            </div>

            <div class="ticket-body">
                <!-- Route Strip -->
                <div class="route-strip">
                    <div class="city">
                        <strong><?= htmlspecialchars($booking['departure_city']) ?></strong>
                        <span><?= date('H:i', strtotime($booking['departure_time'])) ?></span>
                    </div>
                    <div class="arrow text-center flex-grow-1">
                        <div style="font-size:0.75rem; color:#666; margin-bottom:0.3rem;">
                            <?= number_format($booking['distance_km'], 0) ?> km
                        </div>
                        <div>➔</div>
                    </div>
                    <div class="city">
                        <strong><?= htmlspecialchars($booking['arrival_city']) ?></strong>
                        <span><?= date('H:i', strtotime($booking['arrival_time'])) ?></span>
                    </div>
                </div>

                <!-- Info Grid -->
                <div class="info-grid">
                    <div class="info-item">
                        <label>Train</label>
                        <strong><?= htmlspecialchars($booking['train_name']) ?> (<?= htmlspecialchars($booking['train_number']) ?>)</strong>
                    </div>
                    <div class="info-item">
                        <label>Train Type</label>
                        <strong><?= htmlspecialchars($booking['train_type']) ?></strong>
                    </div>
                    <div class="info-item">
                        <label>Journey Date</label>
                        <strong><?= date('D, d M Y', strtotime($booking['journey_date'])) ?></strong>
                    </div>
                    <div class="info-item">
                        <label>Number of Seats</label>
                        <strong><?= htmlspecialchars($booking['number_of_seats']) ?></strong>
                    </div>
                    <div class="info-item">
                        <label>Booked By</label>
                        <strong><?= htmlspecialchars($booking['booker_name']) ?></strong>
                    </div>
                    <div class="info-item">
                        <label>Contact</label>
                        <strong><?= htmlspecialchars($booking['booker_phone'] ?: $booking['booker_email']) ?></strong>
                    </div>
                    <div class="info-item">
                        <label>Payment Method</label>
                        <strong><?= $payment ? ucwords(str_replace('_', ' ', $payment['payment_method'])) : 'N/A' ?></strong>
                    </div>
                    <div class="info-item">
                        <label>Payment Status</label>
                        <strong>
                            <span class="status-badge status-<?= strtolower($booking['payment_status']) ?>">
                                <?= ucfirst($booking['payment_status']) ?>
                            </span>
                        </strong>
                    </div>
                </div>

                <!-- Passenger Details -->
                <?php if (!empty($passengers)): ?>
                <h5 class="mb-2">Passenger Details</h5>
                <table class="table table-bordered passenger-table mb-3">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Passenger Name</th>
                            <th>Age</th>
                            <th>Gender</th>
                            <th>Seat No.</th>
                            <th>Class</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($passengers as $i => $p): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= htmlspecialchars($p['passenger_name']) ?></td>
                            <td><?= htmlspecialchars($p['passenger_age'] ?? '—') ?></td>
                            <td><?= $p['passenger_gender'] === 'M' ? 'Male' : ($p['passenger_gender'] === 'F' ? 'Female' : ($p['passenger_gender'] ?? '—')) ?></td>
                            <td><strong><?= htmlspecialchars($p['seat_number']) ?></strong></td>
                            <td><?= ucfirst($p['seat_type']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="alert alert-info mb-3">No passenger seat details recorded for this booking.</div>
                <?php endif; ?>

                <!-- Fare + Barcode Row -->
                <div class="row align-items-center">
                    <div class="col-md-6 mb-3 mb-md-0">
                        <div style="text-align:center;">
                            <div class="barcode">||||| <?= htmlspecialchars($booking['booking_reference']) ?> |||||</div>
                            <div style="font-size:0.75rem; color:#888; margin-top:0.3rem;">Scan at entry gate</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="fare-box">
                            <div class="label">Total Fare Paid</div>
                            <div class="amount">Rs. <?= number_format($booking['total_fare'], 2) ?></div>
                            <?php if ($payment && $payment['transaction_id']): ?>
                            <div style="font-size:0.8rem; opacity:0.8; margin-top:0.3rem;">
                                TXN: <?= htmlspecialchars($payment['transaction_id']) ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ticket Footer -->
            <div class="ticket-footer">
                <div class="row">
                    <div class="col-md-8">
                        <strong>Important Notes:</strong>
                        <ul class="mb-0 mt-1">
                            <li>Please carry a valid ID proof during travel.</li>
                            <li>Arrive at the station at least 30 minutes before departure.</li>
                            <li>Cancellations are allowed up to 24 hours before the journey date.</li>
                            <li>This e-ticket is valid only for the specified date and route.</li>
                        </ul>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <strong>Pakistan Railways</strong><br>
                        Helpline: 051-9201818<br>
                        www.pakrail.gov.pk
                    </div>
                </div>
            </div>
        </div><!-- /ticket -->
    </div><!-- /ticket-wrapper -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

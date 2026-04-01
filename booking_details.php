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
     WHERE bs.booking_id = {$booking_id}
     ORDER BY bs.booking_seat_id ASC"
);
if (!$passengers) $passengers = [];

$payment = $db->selectRow(
    "SELECT * FROM payments WHERE booking_id = {$booking_id} ORDER BY created_at DESC LIMIT 1"
);

$journey_ts = strtotime($booking['journey_date'] . ' 00:00:00');
$hours_left = ($journey_ts - time()) / 3600;
$can_modify = ($booking['booking_status'] !== 'cancelled') && $hours_left >= 24;
$is_admin   = ($_SESSION['role'] === 'admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-Ticket #<?= htmlspecialchars($booking['booking_reference']) ?> – Pakistan Railways</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="public/css/style.css">
    <style>
        body { background: #0b1728; min-height: 100vh; color: #1e293b; }
        .site-nav { background: rgba(0,0,0,.35); backdrop-filter: blur(10px); border-bottom: 1px solid rgba(255,255,255,.08); }
        .hero-band { background: linear-gradient(135deg, #0b1728 0%, #0f2040 55%, #1a3a6e 100%); padding: 3rem 0 5rem; }
        .hero-band h1 { color: #fff; font-size: 2rem; font-weight: 800; margin-bottom: .5rem; }
        .hero-meta { color: rgba(255,255,255,.65); font-size: .88rem; }
        .hero-ref-badge { display: inline-block; background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.2); color: #fff; border-radius: 8px; padding: .45rem 1.1rem; font-size: .8rem; letter-spacing: 2px; font-family: monospace; margin-bottom: .8rem; }
        .s-pill { display: inline-flex; align-items: center; gap: .35rem; padding: .35em 1em; border-radius: 20px; font-weight: 700; font-size: .82rem; }
        .s-confirmed, .s-completed { background: #d1fae5; color: #065f46; }
        .s-pending   { background: #fef3c7; color: #78350f; }
        .s-cancelled { background: #fee2e2; color: #7f1d1d; }
        .s-refunded  { background: #ede9fe; color: #4c1d95; }
        .s-failed    { background: #fee2e2; color: #7f1d1d; }
        .wave-div { line-height: 0; background: linear-gradient(135deg, #0b1728 0%, #0f2040 55%, #1a3a6e 100%); }
        .wave-div svg { display: block; }
        .content-area { background: #eef2f7; padding-bottom: 4rem; }
        .ticket-wrap { max-width: 900px; margin: 0 auto; }
        .action-bar { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; }
        .tkt-card { background: #fff; border-radius: 18px; box-shadow: 0 8px 32px rgba(0,0,0,.12); overflow: hidden; margin-bottom: 1.25rem; }
        .tkt-top { background: linear-gradient(135deg, #0b1728 0%, #1a3a6e 100%); padding: 1.6rem 2rem; color: #fff; }
        .tkt-logo { font-size: 1.45rem; font-weight: 800; }
        .tkt-ref-line { font-size: .82rem; opacity: .7; margin-top: .25rem; font-family: monospace; letter-spacing: 1px; }
        .journey-bar { display: flex; align-items: center; gap: 1.5rem; padding: 1.5rem 2rem; background: #f0f7ff; border-bottom: 1px solid #e2e8f0; }
        .jb-city { flex: 1; text-align: center; }
        .jb-city-name { font-size: 1.7rem; font-weight: 800; color: #0b1728; letter-spacing: -.5px; }
        .jb-city-time { font-size: 1rem; font-weight: 600; color: #374151; margin-top: .1rem; }
        .jb-city-lbl  { font-size: .7rem; text-transform: uppercase; letter-spacing: .5px; color: #94a3b8; }
        .jb-center { flex: 1; text-align: center; }
        .jb-track { position: relative; height: 4px; background: linear-gradient(90deg, #1a3a6e, #10b981); border-radius: 4px; margin: .5rem 0; }
        .jb-track::before, .jb-track::after { content: ''; position: absolute; top: 50%; transform: translateY(-50%); width: 10px; height: 10px; border-radius: 50%; }
        .jb-track::before { left: 0; background: #1a3a6e; }
        .jb-track::after  { right: 0; background: #10b981; }
        .jb-dist { font-size: .76rem; color: #94a3b8; margin-top: .2rem; }
        .tear-line { position: relative; height: 28px; display: flex; align-items: center; }
        .tear-line::before { content: ''; position: absolute; left: 0; right: 0; top: 50%; border-top: 2px dashed #d1d5db; }
        .tear-circ { position: absolute; top: 50%; transform: translateY(-50%); width: 24px; height: 24px; border-radius: 50%; background: #eef2f7; z-index: 1; border: 1px solid #d1d5db; }
        .tear-circ-l { left: -12px; }
        .tear-circ-r { right: -12px; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(195px, 1fr)); gap: .85rem; padding: 1.4rem 2rem; }
        .info-cell { background: #f8fafc; border-radius: 10px; padding: .7rem 1rem; }
        .info-cell .lbl { font-size: .7rem; text-transform: uppercase; letter-spacing: .4px; color: #9ca3af; display: block; margin-bottom: .2rem; }
        .info-cell .val { font-size: .9rem; font-weight: 600; color: #1e293b; }
        .section-hdr { display: flex; align-items: center; gap: .6rem; padding: .7rem 2rem; background: #f8fafc; border-top: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #1a3a6e; }
        .pax-table { margin: 0; }
        .pax-table thead th { background: #0b1728; color: #fff; font-weight: 600; font-size: .8rem; padding: .65rem 1rem; border: none; }
        .pax-table tbody td { padding: .65rem 1rem; vertical-align: middle; font-size: .88rem; border-color: #f1f5f9; }
        .pax-table tbody tr:hover { background: #f8fafc; }
        .seat-chip { display: inline-block; background: #0b1728; color: #fff; border-radius: 6px; padding: .2em .65em; font-size: .84rem; font-weight: 700; font-family: monospace; }
        .berth-lower { color: #2563eb; font-weight: 600; }
        .berth-mid   { color: #d97706; font-weight: 600; }
        .berth-upper { color: #7c3aed; font-weight: 600; }
        .class-chip { display: inline-block; padding: .2em .75em; border-radius: 12px; font-size: .76rem; font-weight: 600; }
        .class-economy { background: #dcfce7; color: #15803d; }
        .class-premium { background: #fef3c7; color: #92400e; }
        .class-luxury  { background: #ede9fe; color: #6d28d9; }
        .bottom-row { padding: 1.4rem 2rem; }
        .barcode-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1.3rem; text-align: center; height: 100%; display: flex; flex-direction: column; justify-content: center; align-items: center; }
        .barcode-svg { display: flex; align-items: flex-end; gap: 2px; height: 54px; margin-bottom: .65rem; }
        .barcode-svg span { display: inline-block; background: #0b1728; width: 3px; border-radius: 1px; }
        .barcode-ref { font-size: .72rem; color: #6b7280; letter-spacing: 2px; font-family: monospace; }
        .barcode-hint { font-size: .65rem; color: #aaa; margin-top: .25rem; }
        .fare-hero { background: linear-gradient(135deg, #0b1728 0%, #1a3a6e 100%); border-radius: 12px; padding: 1.5rem 1.6rem; color: #fff; text-align: right; height: 100%; display: flex; flex-direction: column; justify-content: center; }
        .fare-hero-lbl { font-size: .8rem; opacity: .7; text-transform: uppercase; letter-spacing: .5px; }
        .fare-hero-amt { font-size: 2.5rem; font-weight: 800; color: #fbbf24; line-height: 1.1; margin: .15rem 0; }
        .fare-hero-sub { font-size: .78rem; opacity: .55; }
        .fare-hero-txn { font-size: .72rem; opacity: .5; margin-top: .5rem; font-family: monospace; }
        .pay-section { padding: 1rem 2rem 1.2rem; }
        .pay-row { border-left: 3px solid #10b981; background: #f8fafc; border-radius: 0 10px 10px 0; padding: .7rem 1rem; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem; margin-bottom: .6rem; }
        .pay-row.refund-row { border-color: #9333ea; }
        .tkt-footer { background: #fafafa; border-top: 2px dashed #d1d5db; padding: 1.25rem 2rem; font-size: .82rem; color: #64748b; }
        @media print {
            body, html { background: white !important; }
            .no-print { display: none !important; }
            .content-area { background: white !important; padding: 0 !important; }
            .hero-band, .wave-div, .site-nav, .action-bar { display: none !important; }
            .tkt-card { box-shadow: none !important; border: 1px solid #ccc !important; border-radius: 0 !important; }
            .ticket-wrap { max-width: 100% !important; }
        }
        @media (max-width: 576px) {
            .jb-city-name { font-size: 1.25rem; }
            .hero-band h1  { font-size: 1.5rem; }
            .fare-hero-amt { font-size: 1.9rem; }
            .info-grid, .bottom-row, .pay-section, .tkt-footer { padding-left: 1rem; padding-right: 1rem; }
            .journey-bar { padding: 1rem; gap: .75rem; }
            .tkt-top { padding: 1.2rem; }
        }
    </style>
</head>
<body>

<nav class="site-nav navbar navbar-expand-lg navbar-dark no-print">
    <div class="container">
        <a class="navbar-brand fw-bold" href="index.php">
            <i class="bi bi-train-front-fill me-2 text-warning"></i>Railway System
        </a>
        <div class="d-flex gap-2">
            <?php if ($is_admin): ?>
            <a href="manage-bookings.php" class="btn btn-outline-light btn-sm"><i class="bi bi-list-check me-1"></i>Manage Bookings</a>
            <?php else: ?>
            <a href="bookings.php" class="btn btn-outline-light btn-sm"><i class="bi bi-list-ul me-1"></i>My Bookings</a>
            <?php endif; ?>
            <a href="logout.php" class="btn btn-danger btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="hero-band no-print">
    <div class="container text-center">
        <div class="hero-ref-badge"><i class="bi bi-ticket-perforated me-1"></i><?= htmlspecialchars($booking['booking_reference']) ?></div>
        <h1><i class="bi bi-receipt me-2"></i>E-Ticket</h1>
        <p class="hero-meta mb-3">
            <i class="bi bi-calendar3 me-1"></i>Issued <?= date('d M Y, H:i', strtotime($booking['booking_date'])) ?>
            &nbsp;&bull;&nbsp;
            <i class="bi bi-person me-1"></i><?= htmlspecialchars($booking['booker_name']) ?>
        </p>
        <span class="s-pill s-<?= strtolower($booking['booking_status']) ?>">
            <i class="bi bi-circle-fill" style="font-size:.55rem;"></i><?= ucfirst($booking['booking_status']) ?>
        </span>
        <?php if ($payment): ?>
        &nbsp;<span class="s-pill s-<?= strtolower($payment['payment_status']) ?>" style="font-size:.76rem;">Payment <?= ucfirst($payment['payment_status']) ?></span>
        <?php endif; ?>
    </div>
</div>

<div class="wave-div no-print">
    <svg viewBox="0 0 1440 60" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none" style="height:60px;width:100%;">
        <path d="M0,0 C360,60 1080,0 1440,60 L1440,0 Z" fill="#eef2f7"/>
    </svg>
</div>

<div class="content-area">
<div class="container py-4">
<div class="ticket-wrap">

    <div class="action-bar no-print">
        <a href="<?= $is_admin ? 'manage-bookings.php' : 'bookings.php' ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i><?= $is_admin ? 'Manage Bookings' : 'My Bookings' ?>
        </a>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($can_modify): ?>
            <a href="booking_update.php?id=<?= $booking_id ?>" class="btn btn-warning btn-sm">
                <i class="bi bi-pencil-square me-1"></i>Change Journey
            </a>
            <a href="booking_cancel.php?id=<?= $booking_id ?>" class="btn btn-danger btn-sm">
                <i class="bi bi-x-circle me-1"></i>Cancel
            </a>
            <?php elseif ($booking['booking_status'] !== 'cancelled' && !$is_admin && $hours_left < 24 && $hours_left > 0): ?>
            <span class="badge bg-secondary py-2 px-3" style="font-size:.75rem;">
                <i class="bi bi-lock-fill me-1"></i>Modifications locked (&lt; 24 hrs)
            </span>
            <?php endif; ?>
            <?php if (!$payment && $booking['payment_status'] === 'pending' && $booking['booking_status'] !== 'cancelled'): ?>
            <a href="payment.php?booking_id=<?= $booking_id ?>" class="btn btn-success btn-sm">
                <i class="bi bi-credit-card me-1"></i>Pay Now
            </a>
            <?php endif; ?>
            <button onclick="window.print()" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-printer me-1"></i>Print
            </button>
            <button type="button" id="emailTicketBtn" class="btn btn-success btn-sm"
                    onclick="sendTicketEmail(<?= $booking_id ?>)">
                <i class="bi bi-envelope-fill me-1"></i>Email Ticket
            </button>
        </div>
    </div>

    <div class="tkt-card">

        <!-- Header strip -->
        <div class="tkt-top d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <div class="tkt-logo"><i class="bi bi-train-front-fill me-2 text-warning"></i>Pakistan Railways</div>
                <div class="tkt-ref-line mt-1">E-Ticket &nbsp;|&nbsp; <?= htmlspecialchars($booking['booking_reference']) ?></div>
                <div style="font-size:.73rem;opacity:.55;margin-top:.3rem;"><i class="bi bi-calendar3 me-1"></i>Issued: <?= date('d M Y H:i', strtotime($booking['booking_date'])) ?></div>
            </div>
            <div class="text-end">
                <span class="s-pill s-<?= strtolower($booking['booking_status']) ?>">
                    <i class="bi bi-circle-fill" style="font-size:.55rem;"></i><?= ucfirst($booking['booking_status']) ?>
                </span>
                <?php if ($payment): ?>
                <div class="mt-1">
                    <span class="s-pill s-<?= strtolower($payment['payment_status']) ?>" style="font-size:.72rem;padding:.25em .8em;">
                        Payment <?= ucfirst($payment['payment_status']) ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Journey bar -->
        <div class="journey-bar">
            <div class="jb-city">
                <div class="jb-city-name"><?= htmlspecialchars($booking['departure_city']) ?></div>
                <div class="jb-city-time"><?= date('H:i', strtotime($booking['departure_time'])) ?></div>
                <div class="jb-city-lbl">Departure</div>
            </div>
            <div class="jb-center flex-grow-1">
                <div style="font-size:1.4rem;color:#1a3a6e;margin-bottom:.3rem;"><i class="bi bi-train-front-fill"></i></div>
                <div class="jb-track"></div>
                <div class="jb-dist"><?= number_format($booking['distance_km'], 0) ?> km &nbsp;&bull;&nbsp; <?= date('D, d M Y', strtotime($booking['journey_date'])) ?></div>
            </div>
            <div class="jb-city">
                <div class="jb-city-name"><?= htmlspecialchars($booking['arrival_city']) ?></div>
                <div class="jb-city-time"><?= date('H:i', strtotime($booking['arrival_time'])) ?></div>
                <div class="jb-city-lbl">Arrival</div>
            </div>
        </div>

        <div class="tear-line mx-4"><div class="tear-circ tear-circ-l"></div><div class="tear-circ tear-circ-r"></div></div>

        <!-- Info grid -->
        <div class="info-grid">
            <?php foreach ([
                ['Train',            htmlspecialchars($booking['train_name']).' ('.htmlspecialchars($booking['train_number']).')'],
                ['Train Type',       ucfirst(htmlspecialchars($booking['train_type']))],
                ['Journey Date',     date('D, d M Y', strtotime($booking['journey_date']))],
                ['Seats Booked',     (int)$booking['number_of_seats']],
                ['Booked By',        htmlspecialchars($booking['booker_name'])],
                ['Contact',          htmlspecialchars($booking['booker_phone'] ?: $booking['booker_email'])],
                ['Payment Method',   $payment ? ucwords(str_replace('_',' ',$payment['payment_method'])) : '—'],
                ['Base Fare / Seat', 'Rs '.number_format($booking['base_fare'],2)],
            ] as [$lbl,$val]): ?>
            <div class="info-cell"><span class="lbl"><?= $lbl ?></span><span class="val"><?= $val ?></span></div>
            <?php endforeach; ?>
        </div>

        <div class="tear-line mx-4"><div class="tear-circ tear-circ-l"></div><div class="tear-circ tear-circ-r"></div></div>

        <!-- Passenger Details -->
        <?php if (!empty($passengers)): ?>
        <div class="section-hdr"><i class="bi bi-people-fill text-primary"></i>Passenger Details</div>
        <div class="table-responsive">
            <table class="table pax-table mb-0">
                <thead>
                    <tr>
                        <th>#</th><th>Passenger Name</th><th>Age</th><th>Gender</th><th>Seat</th><th>Berth</th><th>Class</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($passengers as $i => $p):
                        $snum = $p['seat_number'];
                        $pos  = (int)preg_replace('/[^0-9]/','', $snum);
                        if ($pos <= 2)     { $berth = 'Lower'; $bcls = 'berth-lower'; }
                        elseif ($pos <= 4) { $berth = 'Middle'; $bcls = 'berth-mid'; }
                        else               { $berth = 'Upper';  $bcls = 'berth-upper'; }
                        $st   = strtolower($p['seat_type']);
                        $ccls = 'class-'.($st==='economy'?'economy':($st==='premium'?'premium':'luxury'));
                    ?>
                    <tr>
                        <td class="text-center text-muted"><?= $i+1 ?></td>
                        <td><strong><?= htmlspecialchars($p['passenger_name']) ?></strong></td>
                        <td class="text-center"><?= htmlspecialchars($p['passenger_age'] ?? '—') ?></td>
                        <td class="text-center">
                            <?php $g=$p['passenger_gender']??'—';
                            if($g==='M') echo '<span class="text-primary fw-semibold">Male</span>';
                            elseif($g==='F') echo '<span class="text-danger fw-semibold">Female</span>';
                            else echo htmlspecialchars($g); ?>
                        </td>
                        <td class="text-center"><span class="seat-chip"><?= htmlspecialchars($snum) ?></span></td>
                        <td class="text-center"><span class="<?= $bcls ?>"><?= $berth ?></span></td>
                        <td class="text-center"><span class="class-chip <?= $ccls ?>"><?= ucfirst($p['seat_type']) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="p-4"><div class="alert alert-info mb-0"><i class="bi bi-info-circle me-2"></i>No passenger seat details recorded for this booking.</div></div>
        <?php endif; ?>

        <div class="tear-line mx-4"><div class="tear-circ tear-circ-l"></div><div class="tear-circ tear-circ-r"></div></div>

        <!-- Barcode + Fare -->
        <div class="bottom-row">
            <div class="row g-3">
                <div class="col-md-5">
                    <div class="barcode-box">
                        <?php
                        $ref   = $booking['booking_reference'];
                        $chars = str_split(preg_replace('/[^A-Z0-9]/','',strtoupper($ref)));
                        $seed  = array_map('ord',$chars);
                        $bars  = [];
                        for ($b=0;$b<46;$b++) $bars[]=(int)round(18+(int)($seed[$b%count($seed)]*1.1+$b*3)%36);
                        ?>
                        <div class="barcode-svg" aria-label="Booking barcode">
                            <?php foreach($bars as $h): ?><span style="height:<?= $h ?>px;"></span><?php endforeach; ?>
                        </div>
                        <div class="barcode-ref"><?= htmlspecialchars($booking['booking_reference']) ?></div>
                        <div class="barcode-hint"><i class="bi bi-upc-scan me-1"></i>Scan at entry gate</div>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="fare-hero">
                        <div class="fare-hero-lbl">Total Fare</div>
                        <div class="fare-hero-amt">Rs <?= number_format($booking['total_fare'],2) ?></div>
                        <div class="fare-hero-sub"><?= (int)$booking['number_of_seats'] ?> seat<?= $booking['number_of_seats']>1?'s':'' ?> &bull; <?= ucfirst($booking['train_type']) ?></div>
                        <?php if ($payment && $payment['transaction_id']): ?>
                        <div class="fare-hero-txn"><i class="bi bi-hash"></i><?= htmlspecialchars($payment['transaction_id']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment Record -->
        <?php if ($payment): ?>
        <div class="section-hdr"><i class="bi bi-clock-history text-primary"></i>Payment Record</div>
        <div class="pay-section">
            <div class="pay-row">
                <div>
                    <i class="bi bi-credit-card me-2 text-muted"></i>
                    <span class="fw-semibold"><?= ucwords(str_replace('_',' ',$payment['payment_method'])) ?></span>
                    <span class="text-muted small ms-2"><?= $payment['payment_date'] ? date('d M Y H:i',strtotime($payment['payment_date'])) : '—' ?></span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="fw-bold">Rs <?= number_format($payment['amount'],2) ?></span>
                    <span class="s-pill s-<?= strtolower($payment['payment_status']) ?>" style="font-size:.72rem;padding:.25em .8em;"><?= ucfirst($payment['payment_status']) ?></span>
                </div>
            </div>
            <?php if ($payment['refund_date']): ?>
            <div class="pay-row refund-row">
                <div>
                    <i class="bi bi-arrow-counterclockwise me-2" style="color:#9333ea;"></i>
                    <span class="fw-semibold" style="color:#9333ea;">Refund Processed</span>
                    <span class="text-muted small ms-2"><?= date('d M Y H:i',strtotime($payment['refund_date'])) ?></span>
                </div>
                <?php if ($payment['refund_reason']): ?>
                <div class="text-muted small"><?= htmlspecialchars($payment['refund_reason']) ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Ticket Footer -->
        <div class="tkt-footer">
            <div class="row g-3 align-items-start">
                <div class="col-md-8">
                    <strong><i class="bi bi-info-circle me-2 text-primary"></i>Important Notes:</strong>
                    <ul class="mb-0 mt-1 ps-3">
                        <li>Carry a valid CNIC / ID proof during travel.</li>
                        <li>Arrive at the station at least 30 minutes before departure.</li>
                        <li>Cancellations are accepted up to 24 hours before journey.</li>
                        <li>This e-ticket is valid only for the specified date and route.</li>
                        <li>For assistance: <strong>051-9201818</strong></li>
                    </ul>
                </div>
                <div class="col-md-4 text-md-end">
                    <div class="fw-bold text-primary mb-1"><i class="bi bi-train-front-fill me-1"></i>Pakistan Railways</div>
                    <div class="small"><i class="bi bi-telephone me-1"></i>051-9201818</div>
                    <div class="small"><i class="bi bi-globe me-1"></i>www.pakrail.gov.pk</div>
                    <div class="small text-muted mt-1">Printed: <?= date('d M Y H:i') ?></div>
                </div>
            </div>
        </div>

    </div><!-- /tkt-card -->
</div><!-- /ticket-wrap -->
</div><!-- /container -->
</div><!-- /content-area -->

<div class="position-fixed bottom-0 end-0 p-3 no-print" style="z-index:9999;">
    <div id="emailToast" class="toast align-items-center border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body fw-semibold" id="toastMsg">Sending…</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function sendTicketEmail(bookingId) {
    const btn  = document.getElementById('emailTicketBtn');
    if (!btn) return;
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sending…';
    fetch('send_ticket_email.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'booking_id=' + encodeURIComponent(bookingId),
    })
    .then(r => r.json())
    .then(data => {
        const toastEl  = document.getElementById('emailToast');
        const toastMsg = document.getElementById('toastMsg');
        toastMsg.innerHTML = (data.success
            ? '<i class="bi bi-check-circle me-1"></i>'
            : '<i class="bi bi-exclamation-circle me-1"></i>') + data.message;
        toastEl.classList.remove('bg-danger','bg-success','text-white');
        toastEl.classList.add(data.success ? 'bg-success' : 'bg-danger','text-white');
        new bootstrap.Toast(toastEl, {delay:6000}).show();
    })
    .catch(() => alert('Network error. Please try again.'))
    .finally(() => { btn.disabled = false; btn.innerHTML = orig; });
}
</script>
</body>
</html>
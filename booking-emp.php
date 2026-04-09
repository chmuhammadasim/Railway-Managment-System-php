<?php
// booking-emp.php – Employee: Booking Detail View

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/Booking.php';

if (!User::isLoggedIn() || $_SESSION['role'] !== 'employee') {
    header('Location: login.php');
    exit();
}

$db   = new Database();
$db->connect();
$bookingObj = new Booking($db);

$booking_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$booking_id) {
    header('Location: employee-dashboard.php');
    exit();
}

// ── Inline actions ────────────────────────────────────────────────────────────
$success_message = '';
$error_message   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if ($action === 'confirm') {
        $bookingObj->confirmBooking($booking_id);
        $success_message = 'Booking confirmed successfully.';
    } elseif ($action === 'cancel') {
        $result = $bookingObj->cancelBooking($booking_id, 'Employee cancellation');
        if ($result['success']) {
            $success_message = $result['message'];
        } else {
            $error_message = $result['message'];
        }
    } elseif ($action === 'checkin') {
        $db->query("UPDATE bookings SET booking_status='completed' WHERE booking_id = {$booking_id}");
        $success_message = 'Passenger checked in and booking marked as completed.';
    }
}

// ── Booking details ────────────────────────────────────────────────────────────
$booking = $db->selectRow(
    "SELECT b.*,
            r.departure_city, r.arrival_city, r.departure_time, r.arrival_time,
            r.distance_km, r.journey_date AS route_journey_date, r.base_fare, r.route_id,
            t.train_name, t.train_number, t.train_type,
            u.full_name AS booker_name, u.email AS booker_email, u.phone AS booker_phone
     FROM bookings b
     JOIN routes r ON b.route_id = r.route_id
     JOIN trains t ON r.train_id  = t.train_id
     JOIN users  u ON b.user_id   = u.user_id
     WHERE b.booking_id = {$booking_id}"
);

if (!$booking) {
    header('Location: employee-dashboard.php');
    exit();
}

// ── Passengers ─────────────────────────────────────────────────────────────────
$passengers = $db->select(
    "SELECT bs.*, s.seat_number, s.seat_type
     FROM booking_seats bs
     JOIN seats s ON bs.seat_id = s.seat_id
     WHERE bs.booking_id = {$booking_id}
     ORDER BY s.seat_number ASC"
);
if (!$passengers) $passengers = [];

// ── Payment ────────────────────────────────────────────────────────────────────
$payment = $db->selectRow(
    "SELECT * FROM payments WHERE booking_id = {$booking_id} ORDER BY created_at DESC LIMIT 1"
);

// ── Helpers ────────────────────────────────────────────────────────────────────
$journey_ts = strtotime($booking['journey_date'] . ' 00:00:00');
$hours_left = ($journey_ts - time()) / 3600;
$is_future  = $journey_ts > time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking <?= htmlspecialchars($booking['booking_reference']) ?> – Employee View</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="public/css/style.css">
    <style>
        /* ── Layout ── */
        body { background: #0f172a; min-height: 100vh; color: #1e293b; }
        .emp-layout { display: flex; min-height: 100vh; }

        /* ── Sidebar ── */
        .emp-sidebar {
            width: 240px; flex-shrink: 0;
            background: linear-gradient(180deg, #052e16 0%, #064e3b 60%, #065f46 100%);
            padding: 0; position: sticky; top: 0;
            height: 100vh; overflow-y: auto;
            display: flex; flex-direction: column;
            box-shadow: 4px 0 20px rgba(0,0,0,.3);
        }
        .sb-brand {
            padding: 1.3rem 1.4rem 1rem;
            font-weight: 800; font-size: 1.05rem; color: #d1fae5;
            border-bottom: 1px solid rgba(255,255,255,.12);
            display: flex; align-items: center; gap: .6rem;
        }
        .sb-brand .brand-sub { font-size: .7rem; font-weight: 400; opacity: .6; display: block; margin-top: .1rem; }
        .sb-section {
            font-size: .68rem; text-transform: uppercase; letter-spacing: .1em;
            color: rgba(255,255,255,.38); padding: 1rem 1.4rem .3rem; font-weight: 700;
        }
        .sb-link {
            display: flex; align-items: center; gap: .65rem;
            padding: .6rem 1.4rem; color: rgba(255,255,255,.75);
            text-decoration: none; font-size: .86rem; transition: all .15s;
            border-left: 3px solid transparent;
        }
        .sb-link:hover { background: rgba(255,255,255,.1); color: #fff; border-left-color: rgba(255,255,255,.3); }
        .sb-link.active { background: rgba(255,255,255,.15); color: #fff; border-left-color: #34d399; font-weight: 600; }
        .sb-link i { font-size: 1rem; width: 18px; text-align: center; }
        .sb-footer { margin-top: auto; padding: 1rem 1.4rem; border-top: 1px solid rgba(255,255,255,.1); }
        .sb-user { display: flex; align-items: center; gap: .6rem; color: rgba(255,255,255,.7); font-size: .8rem; }
        .sb-user-avatar {
            width: 32px; height: 32px; border-radius: 50%;
            background: rgba(255,255,255,.2); display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: .9rem; color: #fff; flex-shrink: 0;
        }

        /* ── Main area ── */
        .emp-main { flex: 1; overflow-x: hidden; }

        /* ── Top bar ── */
        .emp-topbar {
            background: rgba(0,0,0,.3); backdrop-filter: blur(8px);
            border-bottom: 1px solid rgba(255,255,255,.08);
            padding: .75rem 1.75rem; display: flex;
            align-items: center; justify-content: space-between; gap: 1rem;
            position: sticky; top: 0; z-index: 100;
        }
        .topbar-title { color: #fff; font-weight: 700; font-size: .95rem; }
        .topbar-back { color: rgba(255,255,255,.7); text-decoration: none; font-size: .84rem; display: flex; align-items: center; gap: .4rem; transition: color .15s; }
        .topbar-back:hover { color: #fff; }

        /* ── Hero band ── */
        .bk-hero {
            background: linear-gradient(135deg, #052e16 0%, #064e3b 50%, #065f46 100%);
            padding: 2rem 1.75rem 4.5rem;
        }
        .bk-ref-badge {
            display: inline-flex; align-items: center; gap: .5rem;
            background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.2);
            color: #d1fae5; border-radius: 8px;
            padding: .4rem 1rem; font-size: .78rem; letter-spacing: 1.5px;
            font-family: monospace; margin-bottom: .75rem;
        }
        .bk-hero h2 { color: #fff; font-size: 1.7rem; font-weight: 800; margin-bottom: .4rem; }
        .bk-hero-meta { color: rgba(255,255,255,.6); font-size: .85rem; }

        /* ── Status pills ── */
        .s-pill {
            display: inline-flex; align-items: center; gap: .35rem;
            padding: .3em .9em; border-radius: 20px; font-weight: 700; font-size: .8rem;
        }
        .s-confirmed, .s-completed { background: #d1fae5; color: #065f46; }
        .s-pending   { background: #fef3c7; color: #78350f; }
        .s-cancelled { background: #fee2e2; color: #7f1d1d; }
        .s-refunded  { background: #ede9fe; color: #4c1d95; }
        .s-failed    { background: #fee2e2; color: #7f1d1d; }

        /* ── Wave ── */
        .wave-div { line-height: 0; background: linear-gradient(135deg, #052e16 0%, #064e3b 50%, #065f46 100%); }
        .wave-div svg { display: block; }

        /* ── Content area ── */
        .content-area { background: #eef2f7; padding: 0 1.75rem 3rem; margin-top: -3rem; }

        /* ── Cards ── */
        .surface-card {
            background: #fff; border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,.07);
            overflow: hidden; margin-bottom: 1.25rem;
        }
        .card-header-bar {
            padding: .75rem 1.25rem;
            background: #f8fafc; border-bottom: 1px solid #e2e8f0;
            display: flex; align-items: center; gap: .6rem;
            font-size: .8rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .5px; color: #064e3b;
        }

        /* ── Journey bar ── */
        .journey-bar {
            display: flex; align-items: center; gap: 1.5rem;
            padding: 1.4rem 1.5rem; background: #f0fdf4;
            border-bottom: 1px solid #d1fae5;
        }
        .jb-city { flex: 1; text-align: center; }
        .jb-name { font-size: 1.5rem; font-weight: 800; color: #052e16; letter-spacing: -.5px; }
        .jb-time { font-size: .95rem; font-weight: 600; color: #374151; margin-top: .1rem; }
        .jb-lbl  { font-size: .68rem; text-transform: uppercase; letter-spacing: .5px; color: #94a3b8; }
        .jb-mid  { flex: 1; text-align: center; }
        .jb-track {
            position: relative; height: 4px;
            background: linear-gradient(90deg, #064e3b, #34d399);
            border-radius: 4px; margin: .4rem 0;
        }
        .jb-track::before, .jb-track::after {
            content: ''; position: absolute; top: 50%;
            transform: translateY(-50%); width: 10px; height: 10px; border-radius: 50%;
        }
        .jb-track::before { left: 0; background: #064e3b; }
        .jb-track::after  { right: 0; background: #34d399; }
        .jb-dist { font-size: .74rem; color: #94a3b8; }

        /* ── Info grid ── */
        .info-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: .8rem; padding: 1.25rem 1.5rem;
        }
        .info-cell { background: #f8fafc; border-radius: 10px; padding: .65rem .9rem; }
        .info-cell .lbl { font-size: .68rem; text-transform: uppercase; letter-spacing: .4px; color: #9ca3af; display: block; margin-bottom: .15rem; }
        .info-cell .val { font-size: .88rem; font-weight: 600; color: #1e293b; }

        /* ── Tear line ── */
        .tear-line { position: relative; height: 26px; display: flex; align-items: center; margin: 0 1.5rem; }
        .tear-line::before { content: ''; position: absolute; left: 0; right: 0; top: 50%; border-top: 2px dashed #d1d5db; }
        .tear-circ { position: absolute; top: 50%; transform: translateY(-50%); width: 22px; height: 22px; border-radius: 50%; background: #eef2f7; z-index: 1; border: 1px solid #d1d5db; }
        .tear-circ-l { left: -22px; }
        .tear-circ-r { right: -22px; }

        /* ── Passenger table ── */
        .pax-table { margin: 0; }
        .pax-table thead th { background: #052e16; color: #fff; font-weight: 600; font-size: .78rem; padding: .6rem 1rem; border: none; white-space: nowrap; }
        .pax-table tbody td { padding: .6rem 1rem; vertical-align: middle; font-size: .85rem; border-color: #f1f5f9; }
        .pax-table tbody tr:hover { background: #f0fdf4; }
        .seat-chip { display: inline-block; background: #052e16; color: #fff; border-radius: 6px; padding: .2em .65em; font-size: .82rem; font-weight: 700; font-family: monospace; }
        .berth-lower { color: #2563eb; font-weight: 600; font-size: .82rem; }
        .berth-mid   { color: #d97706; font-weight: 600; font-size: .82rem; }
        .berth-upper { color: #7c3aed; font-weight: 600; font-size: .82rem; }
        .class-chip { display: inline-block; padding: .18em .7em; border-radius: 12px; font-size: .74rem; font-weight: 600; }
        .class-economy { background: #dcfce7; color: #15803d; }
        .class-premium { background: #fef3c7; color: #92400e; }
        .class-luxury  { background: #ede9fe; color: #6d28d9; }

        /* ── Fare box ── */
        .fare-box {
            background: linear-gradient(135deg, #052e16 0%, #065f46 100%);
            border-radius: 12px; padding: 1.25rem 1.4rem;
            color: #fff; text-align: right;
            display: flex; flex-direction: column; justify-content: center;
        }
        .fare-box-lbl { font-size: .78rem; opacity: .7; text-transform: uppercase; letter-spacing: .5px; }
        .fare-box-amt { font-size: 2.2rem; font-weight: 800; color: #6ee7b7; line-height: 1.1; }
        .fare-box-sub { font-size: .75rem; opacity: .55; }

        /* ── Payment row ── */
        .pay-row { border-left: 3px solid #34d399; background: #f0fdf4; border-radius: 0 10px 10px 0; padding: .7rem 1rem; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem; }

        /* ── Timeline ── */
        .tl-dot { width: 14px; height: 14px; border-radius: 50%; border: 3px solid rgba(255,255,255,.3); flex-shrink: 0; }
        .tl-line { width: 2px; background: rgba(255,255,255,.15); margin: 2px auto; flex-grow: 1; min-height: 32px; }

        /* ── Action card ── */
        .action-card { background: #fff; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,.07); padding: 1.25rem; margin-bottom: 1.25rem; }
        .action-card h6 { font-size: .82rem; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #374151; margin-bottom: .9rem; display: flex; align-items: center; gap: .5rem; }

        /* ── Countdown badge ── */
        .countdown-badge { display: inline-flex; align-items: center; gap: .4rem; background: #fff7ed; color: #c2410c; border: 1.5px solid #fed7aa; border-radius: 20px; padding: .3em .9em; font-size: .78rem; font-weight: 700; }

        /* ── Toast ── */
        @media print {
            body, html { background: white !important; }
            .no-print { display: none !important; }
            .emp-sidebar, .emp-topbar { display: none !important; }
            .emp-main, .content-area { background: white !important; padding: 0 !important; margin: 0 !important; }
            .surface-card { box-shadow: none !important; border: 1px solid #ccc !important; border-radius: 0 !important; }
            .bk-hero { background: #064e3b !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        @media (max-width: 900px) { .emp-sidebar { display: none; } .content-area { padding: 0 1rem 2rem; } }
        @media (max-width: 576px) { .jb-name { font-size: 1.15rem; } .journey-bar { padding: 1rem; gap: .75rem; } .bk-hero h2 { font-size: 1.35rem; } }
    </style>
</head>
<body>

<div class="emp-layout">

<!-- ── Sidebar ──────────────────────────────────────────────────────────────── -->
<aside class="emp-sidebar no-print">
    <div class="sb-brand">
        <i class="bi bi-train-front-fill text-success"></i>
        <div>
            <div>Employee Panel</div>
            <span class="brand-sub">Pakistan Railways</span>
        </div>
    </div>

    <div class="sb-section">Main</div>
    <a href="employee-dashboard.php" class="sb-link"><i class="bi bi-speedometer2"></i>Dashboard</a>

    <div class="sb-section">Operations</div>
    <a href="my-trains.php" class="sb-link"><i class="bi bi-train-front"></i>My Trains</a>
    <a href="check-passengers.php" class="sb-link active"><i class="bi bi-people"></i>Passengers</a>
    <a href="assign-seats.php" class="sb-link"><i class="bi bi-grid-3x3-gap"></i>Seat Management</a>

    <div class="sb-section">Account</div>
    <a href="profile.php" class="sb-link"><i class="bi bi-person-circle"></i>My Profile</a>
    <a href="logout.php" class="sb-link"><i class="bi bi-box-arrow-right"></i>Logout</a>

    <div class="sb-footer">
        <div class="sb-user">
            <div class="sb-user-avatar"><?= strtoupper(substr($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'E', 0, 1)) ?></div>
            <div>
                <div style="color:#d1fae5;font-weight:600;"><?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Employee') ?></div>
                <div>Employee</div>
            </div>
        </div>
    </div>
</aside>

<!-- ── Main ─────────────────────────────────────────────────────────────────── -->
<main class="emp-main">

    <!-- Top bar -->
    <div class="emp-topbar no-print">
        <a href="check-passengers.php" class="topbar-back">
            <i class="bi bi-arrow-left"></i>Passengers
        </a>
        <div class="topbar-title">
            Booking #<?= htmlspecialchars($booking['booking_reference']) ?>
        </div>
        <div class="d-flex gap-2">
            <a href="route-details-emp.php?id=<?= $booking['route_id'] ?>" class="btn btn-outline-light btn-sm">
                <i class="bi bi-map me-1"></i>Route
            </a>
            <button onclick="window.print()" class="btn btn-outline-light btn-sm">
                <i class="bi bi-printer me-1"></i>Print
            </button>
        </div>
    </div>

    <!-- Hero -->
    <div class="bk-hero">
        <div class="bk-ref-badge">
            <i class="bi bi-ticket-perforated"></i><?= htmlspecialchars($booking['booking_reference']) ?>
        </div>
        <h2><?= htmlspecialchars($booking['departure_city']) ?> → <?= htmlspecialchars($booking['arrival_city']) ?></h2>
        <p class="bk-hero-meta mb-3">
            <i class="bi bi-train-front me-1"></i><?= htmlspecialchars($booking['train_name']) ?> (<?= htmlspecialchars($booking['train_number']) ?>)
            &nbsp;&bull;&nbsp;
            <i class="bi bi-calendar3 me-1"></i><?= date('D, d M Y', strtotime($booking['journey_date'])) ?>
            &nbsp;&bull;&nbsp;
            <i class="bi bi-person me-1"></i><?= htmlspecialchars($booking['booker_name']) ?>
        </p>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="s-pill s-<?= strtolower($booking['booking_status']) ?>">
                <i class="bi bi-circle-fill" style="font-size:.5rem;"></i>
                <?= ucfirst($booking['booking_status']) ?>
            </span>
            <?php if ($payment): ?>
            <span class="s-pill s-<?= strtolower($payment['payment_status']) ?>" style="font-size:.74rem; padding:.25em .8em;">
                Payment <?= ucfirst($payment['payment_status']) ?>
            </span>
            <?php endif; ?>
            <?php if ($is_future && $booking['booking_status'] !== 'cancelled' && $hours_left < 72 && $hours_left > 0): ?>
            <span class="countdown-badge">
                <i class="bi bi-alarm"></i>
                <?php if ($hours_left < 1): ?>Departure imminent!
                <?php elseif ($hours_left < 24): ?><?= round($hours_left) ?> hrs to departure
                <?php else: ?><?= round($hours_left / 24, 1) ?> days to departure<?php endif; ?>
            </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Wave -->
    <div class="wave-div">
        <svg viewBox="0 0 1440 60" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none" style="height:60px;width:100%;">
            <path d="M0,0 C360,60 1080,0 1440,60 L1440,0 Z" fill="#eef2f7"/>
        </svg>
    </div>

    <!-- Content -->
    <div class="content-area">

        <?php if ($success_message): ?>
        <div class="alert alert-success border-0 rounded-3 py-2 mb-3 d-flex align-items-center gap-2 no-print" style="margin-top:1rem;">
            <i class="bi bi-check-circle-fill text-success fs-5"></i>
            <span><?= htmlspecialchars($success_message) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($error_message): ?>
        <div class="alert alert-danger border-0 rounded-3 py-2 mb-3 d-flex align-items-center gap-2 no-print" style="margin-top:1rem;">
            <i class="bi bi-exclamation-triangle-fill text-danger fs-5"></i>
            <span><?= htmlspecialchars($error_message) ?></span>
        </div>
        <?php endif; ?>

        <div class="row g-4 align-items-start pt-3">

            <!-- ── Left column ── -->
            <div class="col-lg-8">

                <!-- Main ticket card -->
                <div class="surface-card">
                    <!-- Journey bar -->
                    <div class="journey-bar">
                        <div class="jb-city">
                            <div class="jb-name"><?= htmlspecialchars($booking['departure_city']) ?></div>
                            <div class="jb-time"><?= date('H:i', strtotime($booking['departure_time'])) ?></div>
                            <div class="jb-lbl">Departure</div>
                        </div>
                        <div class="jb-mid flex-grow-1">
                            <div style="font-size:1.3rem;color:#065f46;margin-bottom:.3rem;"><i class="bi bi-train-front-fill"></i></div>
                            <div class="jb-track"></div>
                            <div class="jb-dist"><?= number_format($booking['distance_km'], 0) ?> km</div>
                        </div>
                        <div class="jb-city">
                            <div class="jb-name"><?= htmlspecialchars($booking['arrival_city']) ?></div>
                            <div class="jb-time"><?= date('H:i', strtotime($booking['arrival_time'])) ?></div>
                            <div class="jb-lbl">Arrival</div>
                        </div>
                    </div>

                    <!-- Info grid -->
                    <div class="info-grid">
                        <?php foreach ([
                            ['Train',            htmlspecialchars($booking['train_name']).' ('.htmlspecialchars($booking['train_number']).')'],
                            ['Train Type',       ucfirst(htmlspecialchars($booking['train_type']))],
                            ['Journey Date',     date('D, d M Y', strtotime($booking['journey_date']))],
                            ['Dep / Arr Times',  date('H:i',strtotime($booking['departure_time'])).' → '.date('H:i',strtotime($booking['arrival_time']))],
                            ['Seats Booked',     (int)$booking['number_of_seats']],
                            ['Passenger',        htmlspecialchars($booking['booker_name'])],
                            ['Email',            htmlspecialchars($booking['booker_email'])],
                            ['Phone',            htmlspecialchars($booking['booker_phone'] ?? '—')],
                            ['Base Fare/Seat',   'Rs '.number_format($booking['base_fare'],2)],
                            ['Booking Ref',      htmlspecialchars($booking['booking_reference'])],
                        ] as [$lbl,$val]): ?>
                        <div class="info-cell"><span class="lbl"><?= $lbl ?></span><span class="val"><?= $val ?></span></div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Tear line -->
                    <div class="tear-line"><div class="tear-circ tear-circ-l"></div><div class="tear-circ tear-circ-r"></div></div>

                    <!-- Passenger details -->
                    <div class="card-header-bar" style="border-radius:0;">
                        <i class="bi bi-people-fill" style="color:#059669;"></i>Passenger Details
                        <span class="ms-auto badge bg-success"><?= count($passengers) ?> passenger<?= count($passengers)!==1?'s':'' ?></span>
                    </div>
                    <?php if (!empty($passengers)): ?>
                    <div class="table-responsive">
                        <table class="table pax-table mb-0">
                            <thead>
                                <tr>
                                    <th>#</th><th>Name</th><th>Age</th><th>Gender</th><th>Seat</th><th>Berth</th><th>Class</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($passengers as $i => $p):
                                    $snum = $p['seat_number'];
                                    $pos  = (int)preg_replace('/[^0-9]/', '', $snum);
                                    if ($pos <= 2)     { $berth = 'Lower'; $bcls = 'berth-lower'; }
                                    elseif ($pos <= 4) { $berth = 'Middle'; $bcls = 'berth-mid'; }
                                    else               { $berth = 'Upper'; $bcls = 'berth-upper'; }
                                    $st   = strtolower($p['seat_type']);
                                    $ccls = 'class-'.($st==='economy'?'economy':($st==='premium'?'premium':'luxury'));
                                    $g    = $p['passenger_gender'] ?? '—';
                                ?>
                                <tr>
                                    <td class="text-center text-muted"><?= $i+1 ?></td>
                                    <td><strong><?= htmlspecialchars($p['passenger_name']) ?></strong></td>
                                    <td class="text-center"><?= htmlspecialchars($p['passenger_age'] ?? '—') ?></td>
                                    <td class="text-center">
                                        <?php if($g==='M') echo '<span class="text-primary fw-semibold">Male</span>';
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
                    <div class="p-4"><div class="alert alert-info mb-0 py-2 small border-0"><i class="bi bi-info-circle me-2"></i>No passenger seat details recorded for this booking.</div></div>
                    <?php endif; ?>

                    <!-- Tear line -->
                    <div class="tear-line"><div class="tear-circ tear-circ-l"></div><div class="tear-circ tear-circ-r"></div></div>

                    <!-- Fare box -->
                    <div class="p-3">
                        <div class="row g-3 align-items-stretch">
                            <div class="col-md-6">
                                <div class="fare-box" style="border-radius:12px;">
                                    <div class="fare-box-lbl">Total Fare</div>
                                    <div class="fare-box-amt">Rs <?= number_format($booking['total_fare'],2) ?></div>
                                    <div class="fare-box-sub"><?= (int)$booking['number_of_seats'] ?> seat<?= $booking['number_of_seats']>1?'s':'' ?> &bull; <?= ucfirst($booking['train_type']) ?></div>
                                </div>
                            </div>
                            <?php if ($payment): ?>
                            <div class="col-md-6">
                                <div class="pay-row h-100" style="align-items:flex-start;flex-direction:column;gap:.5rem;">
                                    <div class="fw-bold small text-muted text-uppercase" style="font-size:.68rem;letter-spacing:.5px;">Payment Record</div>
                                    <div><i class="bi bi-credit-card me-2 text-success"></i><span class="fw-semibold"><?= ucwords(str_replace('_',' ',$payment['payment_method'])) ?></span></div>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="fw-bold">Rs <?= number_format($payment['amount'],2) ?></span>
                                        <span class="s-pill s-<?= strtolower($payment['payment_status']) ?>" style="font-size:.7rem;padding:.2em .7em;"><?= ucfirst($payment['payment_status']) ?></span>
                                    </div>
                                    <?php if ($payment['transaction_id']): ?>
                                    <div class="text-muted small" style="font-family:monospace;font-size:.72rem;">#<?= htmlspecialchars($payment['transaction_id']) ?></div>
                                    <?php endif; ?>
                                    <div class="text-muted small"><?= $payment['payment_date'] ? date('d M Y H:i',strtotime($payment['payment_date'])) : '—' ?></div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div><!-- /surface-card -->
            </div><!-- /col-lg-8 -->

            <!-- ── Right column ── -->
            <div class="col-lg-4 no-print">

                <!-- Actions card -->
                <div class="action-card">
                    <h6><i class="bi bi-lightning-charge text-warning"></i>Quick Actions</h6>
                    <div class="d-grid gap-2">

                        <?php if ($booking['booking_status'] === 'pending'): ?>
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#confirmModal">
                            <i class="bi bi-check-circle-fill me-2"></i>Confirm Booking
                        </button>
                        <?php endif; ?>

                        <?php if ($booking['booking_status'] === 'confirmed' && $is_future): ?>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#checkinModal">
                            <i class="bi bi-person-check-fill me-2"></i>Check In Passenger
                        </button>
                        <?php endif; ?>

                        <?php if ($booking['booking_status'] !== 'cancelled' && $booking['booking_status'] !== 'completed'): ?>
                        <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#cancelModal">
                            <i class="bi bi-x-circle-fill me-2"></i>Cancel &amp; Release Seats
                        </button>
                        <?php endif; ?>

                        <hr class="my-1">

                        <a href="booking_details.php?id=<?= $booking_id ?>" class="btn btn-outline-primary">
                            <i class="bi bi-ticket-detailed me-2"></i>View E-Ticket
                        </a>
                        <button type="button" class="btn btn-outline-success" id="emailBtn"
                                onclick="sendEmail(<?= $booking_id ?>)">
                            <i class="bi bi-envelope-fill me-2"></i>Email Ticket to Passenger
                        </button>
                        <a href="route-details-emp.php?id=<?= $booking['route_id'] ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-map me-2"></i>Route Details
                        </a>
                        <button onclick="window.print()" class="btn btn-outline-secondary">
                            <i class="bi bi-printer me-2"></i>Print Booking
                        </button>
                    </div>
                </div>

                <!-- Quick stats card -->
                <div class="action-card">
                    <h6><i class="bi bi-bar-chart text-info"></i>Booking Summary</h6>
                    <div class="d-flex flex-column gap-2">
                        <?php
                        $rows = [
                            ['bi-hash',              'Reference',    htmlspecialchars($booking['booking_reference'])],
                            ['bi-calendar3',         'Journey',      date('d M Y', strtotime($booking['journey_date']))],
                            ['bi-people',            'Passengers',   count($passengers)],
                            ['bi-cash-stack',        'Total Fare',   'Rs '.number_format($booking['total_fare'],2)],
                            ['bi-credit-card',       'Payment',      $payment ? ucfirst($payment['payment_status']) : 'No payment'],
                            ['bi-calendar-plus',     'Booked On',    date('d M Y', strtotime($booking['booking_date']))],
                        ];
                        foreach ($rows as [$icon,$label,$val]):
                        ?>
                        <div class="d-flex align-items-center justify-content-between py-1" style="border-bottom:1px solid #f1f5f9;">
                            <span class="text-muted small"><i class="bi <?= $icon ?> me-2"></i><?= $label ?></span>
                            <span class="fw-semibold small"><?= $val ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Timeline card -->
                <div class="action-card" style="background:linear-gradient(135deg,#052e16,#064e3b);color:#fff;">
                    <h6 style="color:#d1fae5;"><i class="bi bi-clock-history"></i>Booking Timeline</h6>
                    <?php
                    $tl = [
                        ['Booking Created',             date('d M Y H:i', strtotime($booking['booking_date'])),        '#34d399', true],
                        ['Status: '.ucfirst($booking['booking_status']), date('d M Y', strtotime($booking['updated_at'] ?? $booking['booking_date'])), $booking['booking_status']==='confirmed'?'#34d399':($booking['booking_status']==='cancelled'?'#f87171':'#fbbf24'), true],
                    ];
                    if ($payment) {
                        $tl[] = ['Payment: '.ucfirst($payment['payment_status']), $payment['payment_date']?date('d M Y H:i',strtotime($payment['payment_date'])):'—', $payment['payment_status']==='completed'?'#34d399':'#fbbf24', true];
                    }
                    $tl[] = ['Journey: '.date('d M Y', strtotime($booking['journey_date'])), date('H:i', strtotime($booking['departure_time'])).' departure', $is_future?'#60a5fa':'#94a3b8', true];
                    ?>
                    <?php foreach ($tl as $idx => [$label,$sub,$color,$show]): ?>
                    <div class="d-flex gap-3 <?= $idx < count($tl)-1?'mb-0':'' ?>">
                        <div class="d-flex flex-column align-items-center">
                            <div class="tl-dot" style="background:<?= $color ?>;border-color:<?= $color ?>33;"></div>
                            <?php if ($idx < count($tl)-1): ?>
                            <div class="tl-line"></div>
                            <?php endif; ?>
                        </div>
                        <div class="pb-3">
                            <div class="fw-semibold" style="font-size:.84rem;color:#f0fdf4;"><?= $label ?></div>
                            <div style="font-size:.73rem;color:rgba(255,255,255,.5);"><?= $sub ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

            </div><!-- /col-lg-4 -->
        </div><!-- /row -->
    </div><!-- /content-area -->
</main>
</div><!-- /emp-layout -->

<!-- ── Confirm Modal ─────────────────────────────────────────────────────────── -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-success text-white border-0">
                <h5 class="modal-title"><i class="bi bi-check-circle-fill me-2"></i>Confirm Booking</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Confirm booking <strong><?= htmlspecialchars($booking['booking_reference']) ?></strong> for:</p>
                <ul class="mb-0">
                    <li><strong><?= htmlspecialchars($booking['booker_name']) ?></strong></li>
                    <li><?= htmlspecialchars($booking['departure_city']) ?> → <?= htmlspecialchars($booking['arrival_city']) ?></li>
                    <li><?= date('D, d M Y', strtotime($booking['journey_date'])) ?></li>
                </ul>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="confirm">
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="bi bi-check-circle-fill me-1"></i>Confirm Booking
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ── Check-in Modal ────────────────────────────────────────────────────────── -->
<div class="modal fade" id="checkinModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white border-0">
                <h5 class="modal-title"><i class="bi bi-person-check-fill me-2"></i>Check In Passenger</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Mark passenger as checked in and complete booking <strong><?= htmlspecialchars($booking['booking_reference']) ?></strong>?</p>
                <?php if (!empty($passengers)): ?>
                <ul class="mb-0 small">
                    <?php foreach ($passengers as $p): ?>
                    <li><?= htmlspecialchars($p['passenger_name']) ?> — Seat <strong><?= htmlspecialchars($p['seat_number']) ?></strong></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="checkin">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-person-check-fill me-1"></i>Confirm Check-in
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ── Cancel Modal ──────────────────────────────────────────────────────────── -->
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-danger text-white border-0">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill me-2"></i>Cancel Booking</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to cancel booking <strong><?= htmlspecialchars($booking['booking_reference']) ?></strong>?</p>
                <div class="alert alert-warning border-0 py-2 small mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    This will release <?= (int)$booking['number_of_seats'] ?> seat(s) back to the route.
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Keep Booking</button>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="cancel">
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="bi bi-x-circle-fill me-1"></i>Cancel &amp; Release
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3 no-print" style="z-index:9999;">
    <div id="empToast" class="toast align-items-center border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body fw-semibold" id="empToastMsg"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function sendEmail(bookingId) {
    const btn = document.getElementById('emailBtn');
    if (!btn) return;
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending…';
    fetch('send_ticket_email.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'booking_id=' + encodeURIComponent(bookingId),
    })
    .then(r => r.json())
    .then(data => {
        const el  = document.getElementById('empToast');
        const msg = document.getElementById('empToastMsg');
        msg.innerHTML = (data.success
            ? '<i class="bi bi-check-circle me-2"></i>'
            : '<i class="bi bi-exclamation-circle me-2"></i>') + data.message;
        el.classList.remove('bg-success','bg-danger','text-white');
        el.classList.add(data.success ? 'bg-success' : 'bg-danger','text-white');
        new bootstrap.Toast(el,{delay:6000}).show();
    })
    .catch(() => alert('Network error. Please try again.'))
    .finally(() => { btn.disabled = false; btn.innerHTML = orig; });
}
</script>
</body>
</html>
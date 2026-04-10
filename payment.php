<?php
// payment.php – Payment Page

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/Payment.php';

if (!User::isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$db = new Database();
$db->connect();

$user_id    = $_SESSION['user_id'];
$booking_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;

if (!$booking_id) {
    header('Location: bookings.php');
    exit();
}

$booking = $db->selectRow(
    "SELECT b.*, r.departure_city, r.arrival_city, r.journey_date,
            r.departure_time, r.arrival_time,
            t.train_name, t.train_number, t.train_type
     FROM bookings b
     JOIN routes r ON b.route_id = r.route_id
     JOIN trains t ON r.train_id  = t.train_id
     WHERE b.booking_id = {$booking_id} AND b.user_id = {$user_id}"
);

if (!$booking) {
    header('Location: bookings.php');
    exit();
}

if ($booking['booking_status'] === 'cancelled') {
    header('Location: bookings.php');
    exit();
}

$payment_summary = $db->selectRow(
    "SELECT COALESCE(SUM(CASE WHEN payment_status = 'completed' THEN amount ELSE 0 END), 0) AS completed_total
     FROM payments
     WHERE booking_id = {$booking_id}"
);

$settled_amount = (float)($payment_summary['completed_total'] ?? 0);
$amount_due = max((float)$booking['total_fare'] - $settled_amount, 0);
$credit_amount = max($settled_amount - (float)$booking['total_fare'], 0);
$payment_required = $amount_due > 0.009;
$success_heading = 'Payment Successful!';
$success_detail = 'Your booking is confirmed. Your e-ticket is ready.';

$error_message   = '';
$success_message = '';
$payment_done    = false;

if (!$payment_required) {
    if ($booking['booking_status'] !== 'confirmed' || $booking['payment_status'] !== 'completed') {
        $db->query("UPDATE bookings SET booking_status='confirmed', payment_status='completed' WHERE booking_id = {$booking_id}");
    }

    $payment_done = true;
    $success_message = $credit_amount > 0.009
        ? 'No additional payment is required. Your previous payment already covers this booking.'
        : 'No additional payment is required for this booking.';
    $success_heading = 'No Payment Needed';
    $success_detail = $credit_amount > 0.009
        ? 'Your earlier payment is higher than the new fare, so no extra charge is needed.'
        : 'This booking is already fully paid.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $payment_required) {
    $payment_method  = $_POST['payment_method'] ?? '';
    $allowed_methods = ['credit_card', 'debit_card', 'easypaisa', 'jazzcash', 'bank_transfer', 'cash'];

    if (empty($payment_method) || !in_array($payment_method, $allowed_methods, true)) {
        $error_message = 'Please select a valid payment method.';
    } else {
        $transaction_id = 'TXN-' . strtoupper(bin2hex(random_bytes(6)));

        $payment_data = [
            'booking_id'     => $booking_id,
            'user_id'        => $user_id,
            'amount'         => $amount_due,
            'payment_method' => $payment_method,
            'transaction_id' => $transaction_id,
            'payment_status' => 'completed',
            'payment_date'   => date('Y-m-d H:i:s'),
        ];

        $payment_id = $db->insert('payments', $payment_data);

        if ($payment_id) {
            $db->query("UPDATE bookings SET booking_status='confirmed', payment_status='completed' WHERE booking_id = {$booking_id}");
            $success_message = $settled_amount > 0.009
                ? 'Additional payment successful! Your updated booking is confirmed.'
                : 'Payment successful! Your booking is confirmed.';
            $success_heading = $settled_amount > 0.009 ? 'Balance Received!' : 'Payment Successful!';
            $success_detail = 'Your booking is confirmed. Your e-ticket is ready.';
            $payment_done    = true;
        } else {
            $error_message = 'Payment processing failed. Please try again.';
        }
    }
}

$pageTitle = 'Complete Payment – Railway Management System';
require_once 'inc/header.php';
?>

<style>
/* ═══════════════════════════════════════════
   Payment page
═══════════════════════════════════════════ */
.pay-wrap { background:#f1f5f9; min-height:calc(100vh - 60px); padding-bottom:60px; }

/* ── Hero band ──────────────────────────── */
.pay-hero {
    background: linear-gradient(135deg,#0b1728 0%,#0f2040 45%,#1a3a6e 100%);
    color:#fff; padding:2rem 0 4rem; position:relative; overflow:hidden;
}
.pay-hero::before {
    content:''; position:absolute; inset:0;
    background-image:radial-gradient(rgba(255,255,255,.05) 1px,transparent 1px);
    background-size:26px 26px; pointer-events:none;
}
.pay-hero-wave { position:absolute; bottom:-2px; left:0; right:0; line-height:0; }
.pay-hero-wave svg { display:block; width:100%; height:60px; }
.pay-hero-inner { max-width:960px; margin:0 auto; padding:0 1.25rem; position:relative; z-index:2; }

/* Step bar */
.pay-steps { display:flex; align-items:center; justify-content:center; margin-bottom:2rem; }
.step-item  { display:flex; flex-direction:column; align-items:center; gap:.3rem; flex-shrink:0; }
.step-circle {
    width:42px; height:42px; border-radius:50%;
    border:2.5px solid rgba(255,255,255,.3); display:flex; align-items:center;
    justify-content:center; font-size:1rem;
    background:rgba(255,255,255,.08); color:rgba(255,255,255,.5);
    backdrop-filter:blur(4px); transition:all .3s;
}
.step-item.done   .step-circle { background:#10b981; border-color:#10b981; color:#fff; }
.step-item.active .step-circle { background:#2563eb; border-color:#60a5fa; color:#fff;
    box-shadow:0 0 0 4px rgba(96,165,250,.25); }
.step-label { font-size:.72rem; font-weight:700; color:rgba(255,255,255,.45); white-space:nowrap; letter-spacing:.04em; text-transform:uppercase; }
.step-item.done .step-label, .step-item.active .step-label { color:rgba(255,255,255,.9); }
.step-connector { flex:0 0 80px; height:2px; background:rgba(255,255,255,.15); margin:0 .25rem; position:relative; top:-10px; transition:background .3s; }
.step-connector.done { background:#10b981; }

/* Route pill */
.route-pill {
    display:inline-flex; align-items:center; gap:.6rem;
    background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.2);
    border-radius:999px; padding:.55rem 1.25rem; font-weight:700;
    font-size:clamp(.9rem,2.5vw,1.15rem); backdrop-filter:blur(6px);
    letter-spacing:-.01em;
}
.route-pill .arr { color:#fbbf24; font-size:1.1rem; }
.pay-meta { display:flex; flex-wrap:wrap; justify-content:center; gap:.5rem; margin-top:.85rem; }
.pay-meta-badge {
    display:inline-flex; align-items:center; gap:.35rem;
    background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.18);
    border-radius:999px; padding:.3rem .85rem; font-size:.78rem; font-weight:600;
    backdrop-filter:blur(4px);
}

/* ── Content area ───────────────────────── */
.pay-content { max-width:960px; margin:-50px auto 0; padding:0 1.25rem; position:relative; z-index:10; }

/* ── Surface card ───────────────────────── */
.surface-card {
    background:#fff; border-radius:16px;
    border:1px solid #e5e7eb; box-shadow:0 4px 16px rgba(0,0,0,.07);
    overflow:hidden;
}
.sc-header {
    padding:1rem 1.35rem; border-bottom:1px solid #f1f5f9;
    display:flex; align-items:center; gap:.5rem;
}
.sc-header h2 { font-size:.9rem; font-weight:800; color:#0f172a; margin:0; }

/* ── Booking summary ────────────────────── */
.bk-detail-row {
    display:flex; justify-content:space-between; align-items:center;
    padding:.65rem 0; border-bottom:1px solid #f8fafc;
    font-size:.84rem;
}
.bk-detail-row:last-child { border-bottom:none; }
.bk-detail-row .lbl { color:#6b7280; display:flex; align-items:center; gap:.4rem; }
.bk-detail-row .val { font-weight:700; color:#0f172a; text-align:right; }

/* ── Fare box ───────────────────────────── */
.fare-hero {
    background:linear-gradient(135deg,#0f2040,#1e40af);
    border-radius:14px; padding:1.25rem 1.5rem; color:#fff; margin-top:1rem;
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.5rem;
}
.fare-hero .fh-label { font-size:.72rem; text-transform:uppercase; letter-spacing:.08em; color:rgba(255,255,255,.55); margin-bottom:.2rem; font-weight:700; }
.fare-hero .fh-amount { font-size:2rem; font-weight:900; color:#fbbf24; line-height:1; }
.fare-hero .fh-ref    { font-size:.75rem; color:rgba(255,255,255,.5); margin-top:.25rem; }

/* ── Trust badges ───────────────────────── */
.trust-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:.5rem; margin-top:1rem; }
.trust-item { text-align:center; padding:.6rem .25rem; }
.trust-item i { font-size:1.25rem; display:block; margin-bottom:.3rem; }
.trust-item span { font-size:.65rem; color:#6b7280; font-weight:600; }

/* ── Payment method tiles ───────────────── */
.methods-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:.65rem; }
@media(max-width:500px) { .methods-grid { grid-template-columns:repeat(2,1fr); } }

.method-tile {
    position:relative; cursor:pointer; border-radius:14px;
    border:2px solid #e5e7eb; background:#fafafa;
    transition:border-color .2s, background .2s, box-shadow .2s, transform .15s;
    overflow:hidden;
}
.method-tile:hover {
    border-color:#93c5fd; background:#fff;
    box-shadow:0 4px 14px rgba(37,99,235,.1); transform:translateY(-2px);
}
.method-tile.selected {
    border-color:#2563eb; background:#eff6ff;
    box-shadow:0 4px 20px rgba(37,99,235,.2);
}
.method-tile .mt-check {
    position:absolute; top:.5rem; right:.5rem;
    width:20px; height:20px; border-radius:50%; border:2px solid #cbd5e1;
    background:#fff; display:flex; align-items:center; justify-content:center;
    font-size:.6rem; color:transparent; transition:all .2s;
}
.method-tile.selected .mt-check { background:#2563eb; border-color:#2563eb; color:#fff; }
.method-tile-inner { padding:.9rem .75rem; text-align:center; }
.mt-icon { font-size:1.6rem; margin-bottom:.35rem; display:block; }
.mt-name { font-size:.8rem; font-weight:800; color:#0f172a; display:block; }
.mt-desc { font-size:.67rem; color:#9ca3af; display:block; margin-top:.15rem; }

/* ── Hint box ───────────────────────────── */
.hint-box {
    background:#eff6ff; border:1.5px solid #bfdbfe; border-radius:10px;
    padding:.75rem 1rem; font-size:.82rem; color:#1d4ed8;
    display:flex; align-items:flex-start; gap:.6rem;
    margin-top:.85rem; transition:all .3s;
}
.hint-box i { flex-shrink:0; font-size:1rem; margin-top:.05rem; }

/* ── Pay button ─────────────────────────── */
.btn-pay {
    width:100%; padding:1rem; font-size:1rem; font-weight:800;
    border-radius:14px; border:none; cursor:pointer;
    background:linear-gradient(135deg,#2563eb,#1d4ed8);
    color:#fff; display:flex; align-items:center; justify-content:center; gap:.6rem;
    transition:opacity .2s, transform .15s, box-shadow .2s;
    box-shadow:0 6px 20px rgba(37,99,235,.35);
    margin-top:1rem;
}
.btn-pay:hover:not(:disabled) { transform:translateY(-1px); box-shadow:0 10px 28px rgba(37,99,235,.45); }
.btn-pay:disabled { opacity:.5; cursor:not-allowed; transform:none; box-shadow:none; }
.btn-pay .lock-icon { font-size:1rem; }

/* ── Success screen ─────────────────────── */
.success-wrap {
    text-align:center; padding:3.5rem 1rem 2.5rem;
}
.success-ring {
    width:110px; height:110px; border-radius:50%; margin:0 auto 1.5rem;
    background:linear-gradient(135deg,#10b981,#059669);
    display:flex; align-items:center; justify-content:center;
    animation:popIn .55s cubic-bezier(.175,.885,.32,1.275) forwards;
    box-shadow:0 0 0 16px rgba(16,185,129,.12);
}
.success-ring i { font-size:3rem; color:#fff; }
@keyframes popIn { from{transform:scale(0);opacity:0} to{transform:scale(1);opacity:1} }

.ref-badge {
    display:inline-block; background:#f0fdf4; border:1.5px solid #86efac;
    border-radius:10px; padding:.6rem 1.5rem; font-size:1rem; font-weight:800;
    color:#065f46; letter-spacing:.08em; margin:1rem auto;
}

.confetti-line {
    height:4px; border-radius:2px; margin:.35rem auto; width:60px;
    background:linear-gradient(90deg,#10b981,#2563eb,#f59e0b);
}

.success-actions { display:flex; gap:.75rem; justify-content:center; flex-wrap:wrap; margin-top:1.5rem; }
.success-actions a {
    display:inline-flex; align-items:center; gap:.5rem;
    padding:.75rem 1.5rem; border-radius:12px; font-weight:700;
    font-size:.88rem; text-decoration:none; transition:transform .15s, box-shadow .15s;
}
.success-actions a:hover { transform:translateY(-2px); }
.sa-primary { background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff; box-shadow:0 6px 18px rgba(37,99,235,.3); }
.sa-secondary { background:#f1f5f9; border:1.5px solid #e5e7eb; color:#374151; }

/* ── Seat chip preview ──────────────────── */
.seat-chips { display:flex; flex-wrap:wrap; gap:.35rem; margin-top:.5rem; }
.seat-chip {
    display:inline-flex; align-items:center; gap:.3rem;
    background:#eff6ff; border:1px solid #bfdbfe; border-radius:999px;
    padding:.2em .65em; font-size:.72rem; font-weight:700; color:#1d4ed8;
}

/* countdown bar */
.countdown-bar { height:4px; background:#e5e7eb; border-radius:2px; overflow:hidden; margin-top:.5rem; }
.countdown-fill { height:100%; background:linear-gradient(90deg,#10b981,#2563eb); border-radius:2px;
    animation:countdown 4.5s linear forwards; }
@keyframes countdown { from{width:100%} to{width:0%} }

/* ── Payment detail panels ───────────────  */
.pay-panel { display:none; margin-top:.9rem; }
.pay-panel.active { display:block; animation:slideDown .22s ease; }
@keyframes slideDown { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:translateY(0)} }
.pay-panel-inner {
    background:#f8fafc; border:1.5px solid #e2e8f0;
    border-radius:14px; padding:1.1rem 1.25rem;
}
.panel-title {
    font-size:.73rem; font-weight:800; text-transform:uppercase;
    letter-spacing:.08em; color:#64748b; margin-bottom:.9rem;
    display:flex; align-items:center; gap:.4rem;
}
.form-label-sm { font-size:.78rem; font-weight:700; color:#374151; margin-bottom:.28rem; display:block; }
.card-input { font-family:'Courier New',monospace; letter-spacing:.06em; }
.input-icon-r { position:relative; }
.input-icon-r .ii { position:absolute; top:50%; right:.75rem; transform:translateY(-50%); color:#9ca3af; font-size:.9rem; pointer-events:none; }
.card-type-label { position:absolute; top:50%; right:.75rem; transform:translateY(-50%);
    font-size:.62rem; font-weight:800; padding:.15em .4em; border-radius:4px;
    background:#1d4ed8; color:#fff; letter-spacing:.04em; display:none; }
.cvv-help { font-size:.69rem; color:#9ca3af; margin-top:.2rem; }
.test-fill-btn {
    font-size:.71rem; padding:.18rem .55rem; border-radius:6px;
    background:#eff6ff; border:1px solid #bfdbfe; color:#2563eb;
    cursor:pointer; font-weight:700; white-space:nowrap; line-height:1.5;
}
.test-fill-btn:hover { background:#dbeafe; }
.iban-addon { background:#f1f5f9; border-right:0; color:#374151; font-weight:800;
    font-size:.82rem; padding:.4rem .65rem; }
.mpin-field { letter-spacing:.3em; font-size:1.05rem; text-align:center; }
.cash-amount-box { background:#f1f5f9; border-radius:10px; padding:1rem 1.25rem; text-align:center; }
.cash-amount-box .ca-lbl { font-size:.72rem; color:#6b7280; font-weight:600; }
.cash-amount-box .ca-num { font-size:1.8rem; font-weight:900; color:#0f172a; line-height:1.1; }
.panel-field-error { font-size:.73rem; color:#dc2626; margin-top:.2rem; display:none; }
.panel-field-error.show { display:block; }
.field-invalid input, .field-invalid select { border-color:#ef4444 !important; background:#fff5f5 !important; }
.wallet-logo { font-size:1rem; font-weight:900; padding:.1em .45em; border-radius:6px; margin-left:.3rem; }
.ep-logo { background:#0ba360; color:#fff; }
.jc-logo { background:#e07b00; color:#fff; }
</style>

<div class="pay-wrap">

<!-- ── Hero ─────────────────────────────────────────────── -->
<div class="pay-hero">
    <div class="pay-hero-inner">

        <!-- Steps -->
        <div class="pay-steps">
            <div class="step-item done">
                <div class="step-circle"><i class="bi bi-check-lg"></i></div>
                <span class="step-label">Booked</span>
            </div>
            <div class="step-connector done"></div>
            <div class="step-item <?= $payment_done ? 'done' : 'active' ?>">
                <div class="step-circle"><i class="bi bi-credit-card<?= $payment_done ? '' : '' ?>"></i></div>
                <span class="step-label">Payment</span>
            </div>
            <div class="step-connector <?= $payment_done ? 'done' : '' ?>"></div>
            <div class="step-item <?= $payment_done ? 'done' : '' ?>">
                <div class="step-circle"><i class="bi bi-patch-check<?= $payment_done ? '-fill' : '' ?>"></i></div>
                <span class="step-label">Confirmed</span>
            </div>
        </div>

        <!-- Route display -->
        <div class="text-center">
            <div class="route-pill">
                <?= htmlspecialchars($booking['departure_city']) ?>
                <span class="arr"><i class="bi bi-arrow-right"></i></span>
                <?= htmlspecialchars($booking['arrival_city']) ?>
            </div>
            <div class="pay-meta">
                <span class="pay-meta-badge"><i class="bi bi-train-front"></i> <?= htmlspecialchars($booking['train_name']) ?></span>
                <span class="pay-meta-badge"><i class="bi bi-calendar3"></i> <?= date('D, d M Y', strtotime($booking['journey_date'])) ?></span>
                <span class="pay-meta-badge"><i class="bi bi-clock"></i> <?= date('H:i', strtotime($booking['departure_time'])) ?> → <?= date('H:i', strtotime($booking['arrival_time'])) ?></span>
                <span class="pay-meta-badge"><i class="bi bi-ticket-perforated"></i> <?= (int)$booking['number_of_seats'] ?> seat<?= $booking['number_of_seats'] > 1 ? 's' : '' ?></span>
            </div>
        </div>

    </div>
    <div class="pay-hero-wave">
        <svg viewBox="0 0 1440 60" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M0,30 C360,60 1080,0 1440,30 L1440,60 L0,60 Z" fill="#f1f5f9"/>
        </svg>
    </div>
</div>

<!-- ── Content ───────────────────────────────────────────── -->
<div class="pay-content">

    <?php if ($payment_done): ?>
    <!-- ══ SUCCESS ══════════════════════════════════════════ -->
    <div class="surface-card">
        <div class="success-wrap">
            <div class="success-ring"><i class="bi bi-check-lg"></i></div>

            <h2 class="fw-black" style="font-size:1.8rem;color:#065f46;"><?= htmlspecialchars($success_heading) ?></h2>
            <p class="text-muted mb-1" style="font-size:.95rem;"><?= htmlspecialchars($success_detail) ?></p>
            <div class="confetti-line mx-auto"></div>

            <div class="ref-badge">
                <i class="bi bi-hash"></i> <?= htmlspecialchars($booking['booking_reference']) ?>
            </div>

            <p class="text-muted" style="font-size:.82rem;">Redirecting to your ticket in a moment...</p>
            <div class="countdown-bar" style="max-width:220px;margin:0 auto;">
                <div class="countdown-fill"></div>
            </div>

            <div class="success-actions">
                <a href="booking_details.php?id=<?= $booking_id ?>" class="sa-primary">
                    <i class="bi bi-ticket-detailed"></i> View E-Ticket &amp; Print
                </a>
                <a href="bookings.php" class="sa-secondary">
                    <i class="bi bi-list-ul"></i> My Bookings
                </a>
            </div>
        </div>
    </div>
    <script>setTimeout(function(){ window.location='booking_details.php?id=<?= $booking_id ?>'; }, 4500);</script>

    <?php else: ?>
    <!-- ══ PAYMENT FORM ═════════════════════════════════════ -->

    <?php if ($error_message): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 rounded-3 mb-3 border-0"
         style="background:#fee2e2;color:#7f1d1d;">
        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
        <div><?= htmlspecialchars($error_message) ?></div>
    </div>
    <?php endif; ?>

    <div class="row g-3 align-items-start">

        <!-- ── Left: Summary ─────────────────────────── -->
        <div class="col-lg-5">
            <div class="surface-card">
                <div class="sc-header">
                    <i class="bi bi-receipt" style="color:#2563eb;font-size:1rem;"></i>
                    <h2>Booking Summary</h2>
                </div>
                <div style="padding:1rem 1.35rem;">

                    <?php
                    $seats = $db->select(
                        "SELECT s.seat_number, s.seat_type, bs.passenger_name
                         FROM booking_seats bs
                         JOIN seats s ON bs.seat_id = s.seat_id
                         WHERE bs.booking_id = {$booking_id}
                         ORDER BY bs.booking_seat_id ASC"
                    );
                    ?>

                    <!-- Details -->
                    <div class="bk-detail-row">
                        <span class="lbl"><i class="bi bi-train-front"></i>Train</span>
                        <span class="val"><?= htmlspecialchars($booking['train_name']) ?> · <?= htmlspecialchars($booking['train_number']) ?></span>
                    </div>
                    <div class="bk-detail-row">
                        <span class="lbl"><i class="bi bi-calendar3"></i>Journey Date</span>
                        <span class="val"><?= date('d M Y, D', strtotime($booking['journey_date'])) ?></span>
                    </div>
                    <div class="bk-detail-row">
                        <span class="lbl"><i class="bi bi-clock"></i>Departure</span>
                        <span class="val"><?= date('H:i', strtotime($booking['departure_time'])) ?></span>
                    </div>
                    <div class="bk-detail-row">
                        <span class="lbl"><i class="bi bi-people"></i>Seats</span>
                        <span class="val"><?= (int)$booking['number_of_seats'] ?></span>
                    </div>
                    <div class="bk-detail-row">
                        <span class="lbl"><i class="bi bi-bookmark"></i>Booking Ref</span>
                        <span class="val" style="color:#2563eb;"><?= htmlspecialchars($booking['booking_reference']) ?></span>
                    </div>
                    <?php if ($settled_amount > 0.009): ?>
                    <div class="bk-detail-row">
                        <span class="lbl"><i class="bi bi-wallet2"></i>Already Paid</span>
                        <span class="val">Rs <?= number_format($settled_amount, 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($credit_amount > 0.009): ?>
                    <div class="bk-detail-row">
                        <span class="lbl"><i class="bi bi-arrow-down-circle"></i>Fare Reduced</span>
                        <span class="val text-success">Rs <?= number_format($credit_amount, 2) ?> lower</span>
                    </div>
                    <?php endif; ?>

                    <?php if ($seats): ?>
                    <div style="margin-top:.5rem;">
                        <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin-bottom:.4rem;">Selected Seats</div>
                        <div class="seat-chips">
                            <?php foreach ($seats as $s): ?>
                            <span class="seat-chip">
                                <i class="bi bi-grid-1x2-fill" style="font-size:.6rem;"></i>
                                <?= htmlspecialchars($s['seat_number']) ?> · <?= ucfirst($s['seat_type']) ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Fare -->
                    <div class="fare-hero">
                        <div>
                            <div class="fh-label">Amount Due Now</div>
                            <div class="fh-amount">Rs <?= number_format($amount_due, 0) ?></div>
                            <div class="fh-ref">Ref: <?= htmlspecialchars($booking['booking_reference']) ?></div>
                        </div>
                        <i class="bi bi-shield-check" style="font-size:2.5rem;color:rgba(255,255,255,.2);"></i>
                    </div>

                    <?php if ($settled_amount > 0.009): ?>
                    <div class="hint-box" style="display:flex;background:#f8fafc;border-color:#e2e8f0;color:#334155;">
                        <i class="bi bi-info-circle-fill"></i>
                        <span>
                            Original booking total: <strong>Rs <?= number_format((float)$booking['total_fare'], 2) ?></strong>.
                            You have already paid <strong>Rs <?= number_format($settled_amount, 2) ?></strong>.
                            <?php if ($amount_due > 0.009): ?>
                                Only the remaining balance is being charged now.
                            <?php else: ?>
                                No additional payment is required.
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endif; ?>

                    <!-- Trust grid -->
                    <div class="trust-grid">
                        <?php foreach ([
                            ['bi-shield-lock-fill','text-success','Secure'],
                            ['bi-credit-card-fill','text-success','Encrypted'],
                            ['bi-patch-check-fill','text-success','Verified'],
                            ['bi-arrow-counterclockwise','text-primary','Refundable'],
                        ] as [$ic,$col,$lbl]): ?>
                        <div class="trust-item">
                            <i class="bi <?= $ic ?> <?= $col ?>"></i>
                            <span><?= $lbl ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>

                </div>
            </div>
        </div>

        <!-- ── Right: Payment Form ───────────────────── -->
        <div class="col-lg-7">
            <div class="surface-card">
                <div class="sc-header">
                    <i class="bi bi-credit-card-2-front" style="color:#2563eb;font-size:1rem;"></i>
                    <h2>Select Payment Method</h2>
                </div>
                <div style="padding:1.25rem 1.35rem;">

                    <form method="POST" id="paymentForm" novalidate autocomplete="off">

                        <!-- Method tiles -->
                        <div class="methods-grid" id="methodsGrid">
                            <?php
                            $methods = [
                                ['credit_card',   'bi-credit-card-2-front', '#2563eb', '#eff6ff', 'Credit Card',    'Visa / Mastercard'],
                                ['debit_card',    'bi-bank',                '#0891b2', '#ecfeff', 'Debit Card',     'ATM / Bank Card'],
                                ['easypaisa',     'bi-phone-fill',          '#059669', '#f0fdf4', 'EasyPaisa',      'Mobile Wallet'],
                                ['jazzcash',      'bi-phone-vibrate-fill',  '#d97706', '#fffbeb', 'JazzCash',       'Mobile Wallet'],
                                ['bank_transfer', 'bi-building-fill',       '#7c3aed', '#f5f3ff', 'Bank Transfer',  'IBFT / Online'],
                                ['cash',          'bi-cash-coin',           '#0f172a', '#f8fafc', 'Cash at Counter','Pay at Station'],
                            ];
                            foreach ($methods as [$val, $icon, $clr, $bg, $name, $desc]):
                            ?>
                            <label class="method-tile" data-method="<?= $val ?>"
                                   data-color="<?= $clr ?>" data-bg="<?= $bg ?>">
                                <input type="radio" name="payment_method" value="<?= $val ?>" class="d-none">
                                <div class="mt-check"><i class="bi bi-check-lg"></i></div>
                                <div class="method-tile-inner">
                                    <span class="mt-icon"><i class="bi <?= $icon ?>" style="color:<?= $clr ?>;"></i></span>
                                    <span class="mt-name"><?= $name ?></span>
                                    <span class="mt-desc"><?= $desc ?></span>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>

                        <!-- ══ Panel: Credit / Debit Card ══════════════════ -->
                        <div class="pay-panel" id="panel-card">
                            <div class="pay-panel-inner">
                                <div class="panel-title">
                                    <i class="bi bi-credit-card-2-front" style="color:#2563eb;"></i>
                                    <span id="cardPanelTitle">Card Details</span>
                                    <button type="button" class="test-fill-btn ms-auto" onclick="PAY.fillCard()">
                                        <i class="bi bi-magic me-1"></i>Use test card
                                    </button>
                                </div>

                                <!-- Card number -->
                                <div class="mb-3">
                                    <label class="form-label-sm">Card Number <span class="text-danger">*</span></label>
                                    <div class="input-icon-r">
                                        <input type="text" id="cardNumber" name="card_number"
                                               class="form-control form-control-sm card-input"
                                               maxlength="19" placeholder="1234  5678  9012  3456"
                                               inputmode="numeric" autocomplete="cc-number">
                                        <span class="card-type-label" id="cardTypeLabel"></span>
                                    </div>
                                    <div class="panel-field-error" id="err-cardNumber">Please enter a valid 16-digit card number.</div>
                                </div>

                                <!-- Cardholder name -->
                                <div class="mb-3">
                                    <label class="form-label-sm">Cardholder Name <span class="text-danger">*</span></label>
                                    <input type="text" id="cardName" name="card_name"
                                           class="form-control form-control-sm"
                                           placeholder="Name as printed on card"
                                           autocomplete="cc-name">
                                    <div class="panel-field-error" id="err-cardName">Please enter the cardholder name.</div>
                                </div>

                                <!-- Expiry + CVV -->
                                <div class="row g-2">
                                    <div class="col-6">
                                        <label class="form-label-sm">Expiry <span class="text-danger">*</span></label>
                                        <input type="text" id="cardExpiry" name="card_expiry"
                                               class="form-control form-control-sm card-input"
                                               maxlength="5" placeholder="MM/YY"
                                               autocomplete="cc-exp">
                                        <div class="panel-field-error" id="err-cardExpiry">Invalid expiry date.</div>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label-sm">
                                            CVV <span class="text-danger">*</span>
                                            <i class="bi bi-question-circle text-muted ms-1"
                                               style="font-size:.7rem;cursor:help;"
                                               title="3 digits on back of card (Amex: 4 on front)"></i>
                                        </label>
                                        <div class="input-icon-r">
                                            <input type="password" id="cardCvv" name="card_cvv"
                                                   class="form-control form-control-sm"
                                                   maxlength="4" placeholder="•••"
                                                   inputmode="numeric" autocomplete="cc-csc">
                                            <span class="ii" id="cvvEyeWrap" style="cursor:pointer;">
                                                <i class="bi bi-eye-slash" id="cvvEye"></i>
                                            </span>
                                        </div>
                                        <div class="cvv-help">3 digits on back of card</div>
                                        <div class="panel-field-error" id="err-cardCvv">Enter the 3-digit CVV.</div>
                                    </div>
                                </div>

                                <!-- Test card hint -->
                                <div class="hint-box mt-3" style="font-size:.76rem;">
                                    <i class="bi bi-info-circle-fill"></i>
                                    <span>This is a test environment. Use any dummy card number above or click <strong>Use test card</strong> to auto-fill valid test data.</span>
                                </div>
                            </div>
                        </div>

                        <!-- ══ Panel: EasyPaisa / JazzCash ═════════════════ -->
                        <div class="pay-panel" id="panel-mobile">
                            <div class="pay-panel-inner">
                                <div class="panel-title">
                                    <i class="bi bi-phone-fill" id="mobileIcon" style="color:#059669;"></i>
                                    <span id="mobilePanelTitle">Wallet Details</span>
                                    <button type="button" class="test-fill-btn ms-auto" onclick="PAY.fillMobile()">
                                        <i class="bi bi-magic me-1"></i>Autofill test
                                    </button>
                                </div>

                                <!-- Phone number -->
                                <div class="mb-3">
                                    <label class="form-label-sm">Registered Mobile Number <span class="text-danger">*</span></label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text" style="background:#f1f5f9;font-size:.82rem;font-weight:700;">+92</span>
                                        <input type="tel" id="mobileNumber" name="mobile_number"
                                               class="form-control form-control-sm"
                                               placeholder="3XX-XXXXXXX" maxlength="10"
                                               inputmode="numeric">
                                    </div>
                                    <div class="panel-field-error" id="err-mobileNumber">Enter a valid 10-digit number starting with 3 (e.g. 3001234567).</div>
                                </div>

                                <!-- MPIN -->
                                <div class="mb-1">
                                    <label class="form-label-sm">4-Digit MPIN <span class="text-danger">*</span></label>
                                    <div class="input-icon-r">
                                        <input type="password" id="mpinField" name="mpin"
                                               class="form-control form-control-sm mpin-field"
                                               maxlength="4" placeholder="••••"
                                               inputmode="numeric" pattern="[0-9]{4}">
                                        <span class="ii" id="mpinEyeWrap" style="cursor:pointer;">
                                            <i class="bi bi-eye-slash" id="mpinEye"></i>
                                        </span>
                                    </div>
                                    <div class="panel-field-error" id="err-mpin">Enter your 4-digit MPIN.</div>
                                </div>

                                <div class="hint-box mt-3" style="font-size:.76rem;" id="mobileHint">
                                    <i class="bi bi-info-circle-fill"></i>
                                    <span id="mobileHintText">A payment confirmation request will be sent to your registered number.</span>
                                </div>
                            </div>
                        </div>

                        <!-- ══ Panel: Bank Transfer ════════════════════════ -->
                        <div class="pay-panel" id="panel-bank">
                            <div class="pay-panel-inner">
                                <div class="panel-title">
                                    <i class="bi bi-building-fill" style="color:#7c3aed;"></i>
                                    Bank Transfer Details
                                    <button type="button" class="test-fill-btn ms-auto" onclick="PAY.fillBank()">
                                        <i class="bi bi-magic me-1"></i>Autofill test
                                    </button>
                                </div>

                                <!-- Bank select -->
                                <div class="mb-3">
                                    <label class="form-label-sm">Your Bank <span class="text-danger">*</span></label>
                                    <select id="bankName" name="bank_name" class="form-select form-select-sm">
                                        <option value="">— Select your bank —</option>
                                        <option>HBL (Habib Bank Limited)</option>
                                        <option>UBL (United Bank Limited)</option>
                                        <option>MCB Bank</option>
                                        <option>Allied Bank</option>
                                        <option>Bank Alfalah</option>
                                        <option>Standard Chartered Pakistan</option>
                                        <option>Meezan Bank</option>
                                        <option>Faysal Bank</option>
                                        <option>Askari Bank</option>
                                        <option>Bank Al-Habib</option>
                                        <option>NBP (National Bank of Pakistan)</option>
                                        <option>JS Bank</option>
                                        <option>Habib Metropolitan Bank</option>
                                        <option>Silk Bank</option>
                                    </select>
                                    <div class="panel-field-error" id="err-bankName">Please select your bank.</div>
                                </div>

                                <!-- Account title -->
                                <div class="mb-3">
                                    <label class="form-label-sm">Account Title <span class="text-danger">*</span></label>
                                    <input type="text" id="accountTitle" name="account_title"
                                           class="form-control form-control-sm"
                                           placeholder="As registered with your bank">
                                    <div class="panel-field-error" id="err-accountTitle">Please enter your account title.</div>
                                </div>

                                <!-- IBAN -->
                                <div class="mb-3">
                                    <label class="form-label-sm">IBAN <span class="text-danger">*</span></label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text iban-addon">PK</span>
                                        <input type="text" id="ibanField" name="iban"
                                               class="form-control form-control-sm card-input"
                                               maxlength="22" placeholder="XX XXXX XXXX XXXX XXXX XXXX">
                                    </div>
                                    <div class="panel-field-error" id="err-iban">Enter a valid IBAN (PK + 22 characters).</div>
                                </div>

                                <!-- Payment reference reminder -->
                                <div style="background:#f5f3ff;border:1.5px solid #ddd6fe;border-radius:10px;padding:.75rem 1rem;font-size:.78rem;color:#4c1d95;">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Transfer <strong>Rs <?= number_format($amount_due, 2) ?></strong> and use
                                    <strong><?= htmlspecialchars($booking['booking_reference']) ?></strong>
                                    as the payment reference / narration.
                                </div>
                            </div>
                        </div>

                        <!-- ══ Panel: Cash at Counter ══════════════════════ -->
                        <div class="pay-panel" id="panel-cash">
                            <div class="pay-panel-inner">
                                <div class="panel-title">
                                    <i class="bi bi-cash-stack" style="color:#0f172a;"></i>
                                    Cash at Station Counter
                                </div>
                                <div class="cash-amount-box mb-3">
                                    <div class="ca-lbl">Amount to Pay at Counter</div>
                                    <div class="ca-num">Rs <?= number_format($amount_due, 0) ?></div>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-center mb-3">
                                    <span style="background:#dbeafe;color:#1d4ed8;border-radius:999px;padding:.3em .9em;font-size:.76rem;font-weight:700;">
                                        <i class="bi bi-hash"></i> <?= htmlspecialchars($booking['booking_reference']) ?>
                                    </span>
                                    <span style="background:#dcfce7;color:#15803d;border-radius:999px;padding:.3em .9em;font-size:.76rem;font-weight:700;">
                                        <i class="bi bi-clock"></i> Hold: 2 Hours
                                    </span>
                                </div>
                                <div style="background:#fffbeb;border:1.5px solid #fde68a;border-radius:10px;padding:.7rem 1rem;font-size:.78rem;color:#78350f;">
                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                    Your seats are reserved for <strong>2 hours</strong>. Visit the nearest ticket counter
                                    with your booking reference before they are released.
                                </div>
                            </div>
                        </div>

                        <!-- Validation error strip -->
                        <div class="alert alert-danger py-2 mt-2 d-none" id="panelError"
                             style="font-size:.82rem;border-radius:10px;"></div>

                        <!-- Pay button -->
                        <button type="submit" class="btn-pay" id="payBtn" disabled>
                            <span class="lock-icon"><i class="bi bi-lock-fill"></i></span>
                            <span id="payBtnLabel">Pay Rs <?= number_format($amount_due, 0) ?> Securely</span>
                        </button>

                        <div class="text-center mt-2" style="font-size:.73rem;color:#9ca3af;">
                            <i class="bi bi-shield-check me-1 text-success"></i>
                            Protected by 256-bit SSL encryption &nbsp;·&nbsp;
                            <i class="bi bi-lock me-1"></i>PCI DSS Compliant
                        </div>

                    </form>

                    <div class="text-center mt-3 pt-3" style="border-top:1px solid #f1f5f9;">
                        <a href="bookings.php" class="text-secondary text-decoration-none"
                           style="font-size:.82rem;font-weight:600;">
                            <i class="bi bi-arrow-left me-1"></i>Cancel &amp; go back
                        </a>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /row -->
    <?php endif; ?>

</div><!-- /pay-content -->
</div><!-- /pay-wrap -->

<script>
var PAY = (function () {

    /* ── State ───────────────────────────────────────────── */
    var currentMethod = null;

    /* ── DOM refs ────────────────────────────────────────── */
    var tiles       = document.querySelectorAll('.method-tile');
    var payBtn      = document.getElementById('payBtn');
    var payLabel    = document.getElementById('payBtnLabel');
    var panelError  = document.getElementById('panelError');
    var form        = document.getElementById('paymentForm');

    /* panel map */
    var panelMap = {
        credit_card:   'panel-card',
        debit_card:    'panel-card',
        easypaisa:     'panel-mobile',
        jazzcash:      'panel-mobile',
        bank_transfer: 'panel-bank',
        cash:          'panel-cash',
    };

    /* ── Utility ─────────────────────────────────────────── */
    function hideAllPanels() {
        document.querySelectorAll('.pay-panel').forEach(function(p){ p.classList.remove('active'); });
    }
    function showPanel(id) {
        var el = document.getElementById(id);
        if (el) el.classList.add('active');
    }
    function setErr(id, show) {
        var el = document.getElementById('err-' + id);
        if (!el) return;
        el.classList.toggle('show', show);
        var field = el.previousElementSibling || el.parentElement;
        if (field) field.classList.toggle('field-invalid', show);
    }
    function clearAllErrs() {
        document.querySelectorAll('.panel-field-error').forEach(function(e){ e.classList.remove('show'); });
        document.querySelectorAll('.field-invalid').forEach(function(e){ e.classList.remove('field-invalid'); });
        panelError.classList.add('d-none');
    }
    function showGlobalErr(msg) {
        panelError.textContent = msg;
        panelError.classList.remove('d-none');
        panelError.scrollIntoView({ behavior:'smooth', block:'nearest' });
    }

    /* ── Method selection ────────────────────────────────── */
    tiles.forEach(function (tile) {
        tile.addEventListener('click', function () {
            var method = tile.dataset.method;
            currentMethod = method;

            // Update tile UI
            tiles.forEach(function (t) {
                t.classList.remove('selected');
                t.style.borderColor = '';
                t.style.background  = '';
            });
            tile.classList.add('selected');
            tile.style.borderColor = tile.dataset.color;
            tile.style.background  = tile.dataset.bg;
            tile.querySelector('input').checked = true;

            // Show correct panel
            hideAllPanels();
            var panelId = panelMap[method];
            if (panelId) showPanel(panelId);

            // Customise per wallet
            if (method === 'easypaisa') {
                document.getElementById('mobilePanelTitle').innerHTML =
                    'EasyPaisa Wallet <span class="wallet-logo ep-logo">EP</span>';
                document.getElementById('mobileIcon').style.color = '#059669';
                document.getElementById('mobileHintText').textContent =
                    'A payment request will be pushed to your EasyPaisa number. Confirm it in the app or USSD.';
            } else if (method === 'jazzcash') {
                document.getElementById('mobilePanelTitle').innerHTML =
                    'JazzCash Wallet <span class="wallet-logo jc-logo">JC</span>';
                document.getElementById('mobileIcon').style.color = '#d97706';
                document.getElementById('mobileHintText').textContent =
                    'A JazzCash payment request will be sent. Approve via the app or by replying to *786#.';
            }

            // Customise card panel title
            if (method === 'credit_card') {
                document.getElementById('cardPanelTitle').textContent = 'Credit Card Details';
            } else if (method === 'debit_card') {
                document.getElementById('cardPanelTitle').textContent = 'Debit / ATM Card Details';
            }

            clearAllErrs();
            updatePayBtn();
        });
    });

    /* ── Enable/disable pay button ───────────────────────── */
    function updatePayBtn() {
        payBtn.disabled = !currentMethod;
    }

    /* ── Card number formatter ───────────────────────────── */
    var cardNumberEl = document.getElementById('cardNumber');
    if (cardNumberEl) {
        cardNumberEl.addEventListener('input', function () {
            var raw = this.value.replace(/\D/g, '').substring(0, 16);
            this.value = raw.replace(/(.{4})/g, '$1 ').trim();

            // detect card type
            var label = document.getElementById('cardTypeLabel');
            if (/^4/.test(raw)) {
                label.textContent = 'VISA'; label.style.background = '#1a56db'; label.style.display = 'block';
            } else if (/^5[1-5]/.test(raw) || /^2[2-7]/.test(raw)) {
                label.textContent = 'MC'; label.style.background = '#e3342f'; label.style.display = 'block';
            } else if (/^3[47]/.test(raw)) {
                label.textContent = 'AMEX'; label.style.background = '#0070ba'; label.style.display = 'block';
            } else {
                label.style.display = 'none';
            }
        });
    }

    /* ── Expiry formatter ────────────────────────────────── */
    var cardExpiryEl = document.getElementById('cardExpiry');
    if (cardExpiryEl) {
        cardExpiryEl.addEventListener('input', function (e) {
            var raw = this.value.replace(/\D/g, '').substring(0, 4);
            if (raw.length > 2) raw = raw.substring(0,2) + '/' + raw.substring(2);
            this.value = raw;
        });
    }

    /* ── CVV toggle ──────────────────────────────────────── */
    var cvvWrap = document.getElementById('cvvEyeWrap');
    if (cvvWrap) {
        cvvWrap.addEventListener('click', function () {
            var inp = document.getElementById('cardCvv');
            var eye = document.getElementById('cvvEye');
            var show = inp.type === 'password';
            inp.type = show ? 'text' : 'password';
            eye.className = show ? 'bi bi-eye' : 'bi bi-eye-slash';
        });
    }

    /* ── MPIN toggle ─────────────────────────────────────── */
    var mpinWrap = document.getElementById('mpinEyeWrap');
    if (mpinWrap) {
        mpinWrap.addEventListener('click', function () {
            var inp = document.getElementById('mpinField');
            var eye = document.getElementById('mpinEye');
            var show = inp.type === 'password';
            inp.type = show ? 'text' : 'password';
            eye.className = show ? 'bi bi-eye' : 'bi bi-eye-slash';
        });
    }

    /* ── IBAN formatter ──────────────────────────────────── */
    var ibanEl = document.getElementById('ibanField');
    if (ibanEl) {
        ibanEl.addEventListener('input', function () {
            var raw = this.value.replace(/[^A-Z0-9]/gi,'').toUpperCase().substring(0, 22);
            // space every 4 chars
            this.value = raw.replace(/(.{4})/g,'$1 ').trim();
        });
    }

    /* ── Mobile number filter ────────────────────────────── */
    var mobEl = document.getElementById('mobileNumber');
    if (mobEl) {
        mobEl.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g,'').substring(0,10);
        });
    }

    /* ── MPIN filter ─────────────────────────────────────── */
    var mpinEl = document.getElementById('mpinField');
    if (mpinEl) {
        mpinEl.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g,'').substring(0,4);
        });
    }

    /* ── CVV filter ──────────────────────────────────────── */
    var cvvEl = document.getElementById('cardCvv');
    if (cvvEl) {
        cvvEl.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g,'').substring(0,4);
        });
    }

    /* ── Validation ──────────────────────────────────────── */
    function validateCard() {
        var ok = true;
        var num  = (document.getElementById('cardNumber').value || '').replace(/\s/g,'');
        var name = (document.getElementById('cardName').value || '').trim();
        var exp  = (document.getElementById('cardExpiry').value || '').trim();
        var cvv  = (document.getElementById('cardCvv').value || '').trim();

        var numOk = /^\d{16}$/.test(num);
        setErr('cardNumber', !numOk); if(!numOk) ok=false;

        var nameOk = name.length >= 2;
        setErr('cardName', !nameOk); if(!nameOk) ok=false;

        var expOk = false;
        if (/^\d{2}\/\d{2}$/.test(exp)) {
            var mm = parseInt(exp.split('/')[0],10);
            var yy = parseInt(exp.split('/')[1],10) + 2000;
            var now = new Date();
            expOk = mm >= 1 && mm <= 12 && (yy > now.getFullYear() || (yy === now.getFullYear() && mm >= now.getMonth()+1));
        }
        setErr('cardExpiry', !expOk); if(!expOk) ok=false;

        var cvvOk = /^\d{3,4}$/.test(cvv);
        setErr('cardCvv', !cvvOk); if(!cvvOk) ok=false;

        return ok;
    }

    function validateMobile() {
        var ok = true;
        var mob  = (document.getElementById('mobileNumber').value || '').replace(/\D/g,'');
        var mpin = (document.getElementById('mpinField').value || '').trim();

        var mobOk = /^3\d{9}$/.test(mob);
        setErr('mobileNumber', !mobOk); if(!mobOk) ok=false;

        var mpinOk = /^\d{4}$/.test(mpin);
        setErr('mpin', !mpinOk); if(!mpinOk) ok=false;

        return ok;
    }

    function validateBank() {
        var ok = true;
        var bank  = (document.getElementById('bankName').value || '').trim();
        var title = (document.getElementById('accountTitle').value || '').trim();
        var iban  = (document.getElementById('ibanField').value || '').replace(/\s/g,'');

        var bankOk = bank.length > 0;
        setErr('bankName', !bankOk); if(!bankOk) ok=false;

        var titleOk = title.length >= 3;
        setErr('accountTitle', !titleOk); if(!titleOk) ok=false;

        var ibanOk = /^[A-Z0-9]{22}$/.test(iban);
        setErr('iban', !ibanOk); if(!ibanOk) ok=false;

        return ok;
    }

    /* ── Form submit ─────────────────────────────────────── */
    form.addEventListener('submit', function (e) {
        clearAllErrs();

        if (!currentMethod) {
            e.preventDefault();
            showGlobalErr('Please select a payment method.');
            return;
        }

        var valid = true;
        if (currentMethod === 'credit_card' || currentMethod === 'debit_card') {
            valid = validateCard();
        } else if (currentMethod === 'easypaisa' || currentMethod === 'jazzcash') {
            valid = validateMobile();
        } else if (currentMethod === 'bank_transfer') {
            valid = validateBank();
        }
        // cash: always valid

        if (!valid) {
            e.preventDefault();
            showGlobalErr('Please fix the highlighted fields before continuing.');
            return;
        }

        payBtn.disabled = true;
        payBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing…';
    });

    /* ── Autofill helpers (public) ───────────────────────── */
    function fillCard() {
        document.getElementById('cardNumber').value  = '4532 1234 5678 9012';
        document.getElementById('cardName').value    = 'ALI KHAN';
        document.getElementById('cardExpiry').value  = '12/28';
        document.getElementById('cardCvv').value     = '123';
        // Trigger card-type detection
        document.getElementById('cardNumber').dispatchEvent(new Event('input'));
        clearAllErrs();
    }

    function fillMobile() {
        document.getElementById('mobileNumber').value = '3001234567';
        document.getElementById('mpinField').value    = '1234';
        clearAllErrs();
    }

    function fillBank() {
        document.getElementById('bankName').value     = 'HBL (Habib Bank Limited)';
        document.getElementById('accountTitle').value = 'Ali Khan';
        document.getElementById('ibanField').value    = 'PK36 HABB 0000 1234 5678 0100'.replace('PK','').trim();
        clearAllErrs();
    }

    return { fillCard: fillCard, fillMobile: fillMobile, fillBank: fillBank };
}());
</script>

<?php require_once 'inc/footer.php'; ?>

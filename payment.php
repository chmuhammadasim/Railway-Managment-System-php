<?php
// payment.php – Payment Page

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/Payment.php';
require_once 'src/classes/Otp.php';

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

$error_message   = '';
$success_message = '';
$payment_done    = false;

// Load OTP helper + fetch user's email
$otp     = new Otp($db);
$userRow = $db->selectRow("SELECT email, full_name FROM users WHERE user_id={$user_id}");

// Auto-send booking OTP when user first lands on this page (GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $otp->send($user_id, 'booking_confirm', $userRow['email'], $userRow['full_name']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'pay';

    // Resend OTP request
    if ($action === 'resend_otp') {
        $sent = $otp->send($user_id, 'booking_confirm', $userRow['email'], $userRow['full_name']);
        $success_message = $sent['success'] ? "OTP resent to {$userRow['email']}." : $sent['message'];
        if (!$sent['success']) $error_message = $sent['message'];
    } else {
        // Normal payment submit — verify OTP first
        $otp_code        = trim($_POST['otp_code'] ?? '');
        $payment_method  = $_POST['payment_method'] ?? '';
        $allowed_methods = ['credit_card', 'debit_card', 'easypaisa', 'jazzcash', 'bank_transfer', 'cash'];

        if (empty($otp_code)) {
            $error_message = 'Please enter the OTP sent to your email to confirm payment.';
        } elseif (empty($payment_method) || !in_array($payment_method, $allowed_methods, true)) {
            $error_message = 'Please select a valid payment method.';
        } else {
            $otp_res = $otp->verify((string)$user_id, 'booking_confirm', $otp_code);
            if (!$otp_res['success']) {
                $error_message = $otp_res['message'];
            } else {
                $transaction_id = 'TXN-' . strtoupper(bin2hex(random_bytes(6)));

                $payment_data = [
                    'booking_id'     => $booking_id,
                    'user_id'        => $user_id,
                    'amount'         => $booking['total_fare'],
                    'payment_method' => $payment_method,
                    'transaction_id' => $transaction_id,
                    'payment_status' => 'completed',
                    'payment_date'   => date('Y-m-d H:i:s'),
                ];

                $payment_id = $db->insert('payments', $payment_data);

                if ($payment_id) {
                    $db->query("UPDATE bookings SET booking_status='confirmed', payment_status='completed' WHERE booking_id = {$booking_id}");
                    $success_message = 'Payment successful! Your booking is confirmed.';
                    $payment_done    = true;
                } else {
                    $error_message = 'Payment processing failed. Please try again.';
                }
            }
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

            <h2 class="fw-black" style="font-size:1.8rem;color:#065f46;">Payment Successful!</h2>
            <p class="text-muted mb-1" style="font-size:.95rem;">Your booking is confirmed. Your e-ticket is ready.</p>
            <div class="confetti-line mx-auto"></div>

            <div class="ref-badge">
                <i class="bi bi-hash"></i> <?= htmlspecialchars($booking['booking_reference']) ?>
            </div>

            <p class="text-muted" style="font-size:.82rem;">Redirecting to your bookings in a moment…</p>
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
    <script>setTimeout(function(){ window.location='bookings.php'; }, 4500);</script>

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
                            <div class="fh-label">Total Amount Due</div>
                            <div class="fh-amount">Rs <?= number_format($booking['total_fare'], 0) ?></div>
                            <div class="fh-ref">Ref: <?= htmlspecialchars($booking['booking_reference']) ?></div>
                        </div>
                        <i class="bi bi-shield-check" style="font-size:2.5rem;color:rgba(255,255,255,.2);"></i>
                    </div>

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

                    <form method="POST" id="paymentForm" novalidate>

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
                                <input type="radio" name="payment_method" value="<?= $val ?>" class="d-none" required>
                                <div class="mt-check"><i class="bi bi-check-lg"></i></div>
                                <div class="method-tile-inner">
                                    <span class="mt-icon">
                                        <i class="bi <?= $icon ?>" style="color:<?= $clr ?>;"></i>
                                    </span>
                                    <span class="mt-name"><?= $name ?></span>
                                    <span class="mt-desc"><?= $desc ?></span>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>

                        <!-- Hint box -->
                        <div class="hint-box" id="hintBox" style="display:none;">
                            <i class="bi bi-info-circle-fill"></i>
                            <span id="hintText"></span>
                        </div>

                        <!-- OTP Verification field -->
                        <div class="hint-box" style="background:#fff9e6;border-color:#fbbf24;color:#92400e;margin-top:.85rem;">
                            <i class="bi bi-shield-lock-fill" style="color:#d97706;"></i>
                            <div>
                                <strong>OTP Confirmation</strong> — An OTP was sent to
                                <strong><?= htmlspecialchars($userRow['email']) ?></strong>.
                                Enter it below to authorise payment.
                            </div>
                        </div>
                        <div class="mt-2" style="display:flex;gap:.65rem;align-items:stretch;">
                            <input type="text" name="otp_code" id="otpCodeInput"
                                   placeholder="6-digit OTP" maxlength="6" inputmode="numeric"
                                   style="flex:1;padding:.725rem 1rem;border:1.5px solid #d1d5db;border-radius:10px;font-size:1.3rem;font-weight:800;letter-spacing:.5rem;text-align:center;background:#f9fafb;"
                                   autocomplete="one-time-code" required>
                            <button type="button" id="resendOtpBtn"
                                    style="padding:.7rem .9rem;background:#f1f5f9;border:1.5px solid #d1d5db;border-radius:10px;font-size:.8rem;font-weight:700;color:#374151;cursor:pointer;white-space:nowrap;">
                                <i class="bi bi-arrow-repeat"></i> Resend
                            </button>
                        </div>
                        <div id="resendMsg" style="font-size:.8rem;margin-top:.3rem;min-height:1.1em;"></div>
                        <input type="hidden" name="action" value="pay">

                        <!-- Pay button -->
                        <button type="submit" class="btn-pay" id="payBtn" disabled>
                            <span class="lock-icon"><i class="bi bi-lock-fill"></i></span>
                            <span id="payBtnLabel">Pay Rs <?= number_format($booking['total_fare'], 0) ?> Securely</span>
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
(function () {
    var hints = {
        credit_card:   'Enter your Visa or Mastercard card number and CVV at the next step.',
        debit_card:    'Use your ATM or bank debit card. You will be redirected to your bank\'s 3D-Secure page.',
        easypaisa:     'You will receive a payment request on your EasyPaisa-registered mobile number.',
        jazzcash:      'Pay instantly via your JazzCash mobile account. Ensure you have sufficient balance.',
        bank_transfer: 'Transfer the exact amount via IBFT and mention your booking reference as the note.',
        cash:          'Your booking is held for 2 hours. Pay at the station ticket counter before departure.'
    };

    var tiles   = document.querySelectorAll('.method-tile');
    var payBtn  = document.getElementById('payBtn');
    var hintBox = document.getElementById('hintBox');
    var hintTxt = document.getElementById('hintText');

    tiles.forEach(function (tile) {
        tile.addEventListener('click', function () {
            tiles.forEach(function (t) {
                t.classList.remove('selected');
                t.style.borderColor = '';
                t.style.background  = '';
            });

            tile.classList.add('selected');
            tile.style.borderColor = tile.dataset.color;
            tile.style.background  = tile.dataset.bg;

            tile.querySelector('input').checked = true;

            hintTxt.textContent = hints[tile.dataset.method] || '';
            hintBox.style.display = 'flex';

            selected = true;
            updatePayBtn();
        });
    });

    // Enable Pay button only when a method is selected AND OTP has 6 digits
    var otpInput = document.getElementById('otpCodeInput');
    var selected = false;

    function updatePayBtn() {
        var hasOtp = otpInput && otpInput.value.replace(/\D/g,'').length === 6;
        payBtn.disabled = !(selected && hasOtp);
    }

    if (otpInput) {
        otpInput.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 6);
            updatePayBtn();
        });
    }

    document.getElementById('paymentForm').addEventListener('submit', function () {
        payBtn.disabled = true;
        payBtn.innerHTML =
            '<span class="spinner-border spinner-border-sm me-2"></span>Processing…';
    });

    // Resend OTP via fetch (avoids nested-form bug)
    var resendBtn = document.getElementById('resendOtpBtn');
    var resendMsg = document.getElementById('resendMsg');
    if (resendBtn) {
        resendBtn.addEventListener('click', function () {
            resendBtn.disabled = true;
            resendMsg.textContent = 'Sending…';
            resendMsg.style.color = '#6b7280';
            var fd = new FormData();
            fd.append('action', 'resend_otp');
            fetch(window.location.href, { method: 'POST', body: fd })
                .then(function (r) { return r.text(); })
                .then(function () {
                    resendMsg.textContent = 'OTP resent! Check your email.';
                    resendMsg.style.color = '#15803d';
                    setTimeout(function () { resendBtn.disabled = false; resendMsg.textContent = ''; }, 30000);
                })
                .catch(function () {
                    resendMsg.textContent = 'Failed to resend. Try again.';
                    resendMsg.style.color = '#b91c1c';
                    resendBtn.disabled = false;
                });
        });
    }
}());
</script>

<?php require_once 'inc/footer.php'; ?>

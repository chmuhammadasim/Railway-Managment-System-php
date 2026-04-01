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

$error_message   = '';
$success_message = '';
$payment_done    = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payment_method  = $_POST['payment_method'] ?? '';
    $allowed_methods = ['credit_card', 'debit_card', 'easypaisa', 'jazzcash', 'bank_transfer', 'cash'];

    if (empty($payment_method) || !in_array($payment_method, $allowed_methods, true)) {
        $error_message = 'Please select a valid payment method.';
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

$pageTitle = 'Complete Payment – Railway Management System';
//require_once 'inc/header.php';
?>

<style>
/* ── Step Indicator ── */
.pay-steps { display:flex; align-items:center; justify-content:center; flex-wrap:nowrap; gap:0; }
.step-item  { display:flex; flex-direction:column; align-items:center; gap:.35rem; flex-shrink:0; }
.step-circle {
    width:46px; height:46px; border-radius:50%;
    border:3px solid #dee2e6; display:flex; align-items:center;
    justify-content:center; font-size:1.1rem;
    background:#fff; color:#adb5bd; transition:all .3s;
}
.step-item.active .step-circle { border-color:#2563eb; color:#2563eb; }
.step-item.done   .step-circle { background:#2563eb; border-color:#2563eb; color:#fff; }
.step-label { font-size:.77rem; font-weight:600; color:#9ca3af; white-space:nowrap; }
.step-item.active .step-label,
.step-item.done   .step-label  { color:#2563eb; }
.step-connector { flex:0 0 70px; height:3px; background:#dee2e6; transition:background .3s; }
.step-connector.done { background:#2563eb; }

/* ── Payment Method Cards ── */
.pay-card {
    display:block; cursor:pointer;
    border:2px solid #e2e8f0; border-radius:12px;
    transition:border-color .2s, box-shadow .2s, background .2s;
    background:#fff; height:100%;
}
.pay-card:hover    { border-color:#2563eb; box-shadow:0 4px 14px rgba(37,99,235,.12); }
.pay-card.selected { border-color:#2563eb; background:#eff6ff; box-shadow:0 4px 18px rgba(37,99,235,.18); }
.pay-card-inner {
    display:flex; flex-direction:column; align-items:center;
    gap:.3rem; padding:1.25rem .75rem; text-align:center;
}
.pay-icon { font-size:2rem; line-height:1; }
.pay-name { font-weight:700; font-size:.88rem; color:#1e293b; }
.pay-desc { font-size:.72rem; color:#64748b; }

/* ── Success animation ── */
.success-check { animation:popIn .5s cubic-bezier(.175,.885,.32,1.275) forwards; }
@keyframes popIn { from{transform:scale(0);opacity:0} to{transform:scale(1);opacity:1} }
</style>

<div class="container py-4" style="max-width:960px;">

    <!-- Step Indicator -->
    <div class="pay-steps mb-4">
        <div class="step-item done">
            <div class="step-circle"><i class="bi bi-check-lg"></i></div>
            <span class="step-label">Booked</span>
        </div>
        <div class="step-connector <?= $payment_done ? 'done' : '' ?>"></div>
        <div class="step-item <?= $payment_done ? 'done' : 'active' ?>">
            <div class="step-circle"><i class="bi bi-credit-card"></i></div>
            <span class="step-label">Payment</span>
        </div>
        <div class="step-connector <?= $payment_done ? 'done' : '' ?>"></div>
        <div class="step-item <?= $payment_done ? 'done' : '' ?>">
            <div class="step-circle"><i class="bi bi-patch-check<?= $payment_done ? '-fill' : '' ?>"></i></div>
            <span class="step-label">Confirmed</span>
        </div>
    </div>

    <?php if ($payment_done): ?>
    <!-- ── Success Screen ── -->
    <div class="text-center py-5">
        <div class="success-check d-inline-block mb-3">
            <i class="bi bi-check-circle-fill text-success" style="font-size:5.5rem;"></i>
        </div>
        <h2 class="fw-bold text-success">Payment Successful!</h2>
        <p class="text-muted fs-5 mb-4">Your booking is confirmed. Redirecting in a moment…</p>
        <a href="booking_details.php?id=<?= $booking_id ?>" class="btn btn-primary btn-lg me-2">
            <i class="bi bi-ticket-detailed me-1"></i> View E-Ticket
        </a>
        <a href="bookings.php" class="btn btn-outline-secondary btn-lg">
            <i class="bi bi-list-ul me-1"></i> My Bookings
        </a>
    </div>
    <script>setTimeout(function(){ window.location='bookings.php'; }, 4500);</script>

    <?php else: ?>
    <!-- ── Payment Layout ── -->
    <div class="row g-4 align-items-start">

        <!-- Left: Booking Summary -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm overflow-hidden">
                <div class="card-header border-0 text-white py-3"
                     style="background:linear-gradient(135deg,#1a3c6e 0%,#2d6a9f 100%);">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-receipt me-2"></i>Booking Summary</h6>
                </div>
                <div class="card-body">
                    <!-- Route display -->
                    <div class="d-flex align-items-center justify-content-between p-3 rounded-3 mb-3"
                         style="background:#f0f4ff;">
                        <div class="text-center">
                            <div class="fw-bold fs-5 text-primary"><?= htmlspecialchars($booking['departure_city']) ?></div>
                            <small class="text-muted"><?= date('H:i', strtotime($booking['departure_time'])) ?></small>
                        </div>
                        <div class="text-center px-2">
                            <i class="bi bi-arrow-right fs-3 text-secondary"></i>
                            <div class="text-muted" style="font-size:.72rem;">Direct</div>
                        </div>
                        <div class="text-center">
                            <div class="fw-bold fs-5 text-primary"><?= htmlspecialchars($booking['arrival_city']) ?></div>
                            <small class="text-muted"><?= date('H:i', strtotime($booking['arrival_time'])) ?></small>
                        </div>
                    </div>

                    <ul class="list-group list-group-flush mb-3">
                        <li class="list-group-item px-0 d-flex justify-content-between py-2">
                            <span class="text-muted small"><i class="bi bi-train-front me-1"></i>Train</span>
                            <strong class="small text-end"><?= htmlspecialchars($booking['train_name']) ?></strong>
                        </li>
                        <li class="list-group-item px-0 d-flex justify-content-between py-2">
                            <span class="text-muted small"><i class="bi bi-calendar3 me-1"></i>Journey Date</span>
                            <strong class="small"><?= date('d M Y, D', strtotime($booking['journey_date'])) ?></strong>
                        </li>
                        <li class="list-group-item px-0 d-flex justify-content-between py-2">
                            <span class="text-muted small"><i class="bi bi-people me-1"></i>Seats</span>
                            <strong class="small"><?= (int)$booking['number_of_seats'] ?></strong>
                        </li>
                        <li class="list-group-item px-0 d-flex justify-content-between py-2">
                            <span class="text-muted small"><i class="bi bi-bookmark me-1"></i>Booking Ref</span>
                            <strong class="small text-primary"><?= htmlspecialchars($booking['booking_reference']) ?></strong>
                        </li>
                    </ul>

                    <!-- Amount -->
                    <div class="text-center text-white p-3 rounded-3"
                         style="background:linear-gradient(135deg,#1a3c6e,#2d6a9f);">
                        <div class="small opacity-75">Total Amount Due</div>
                        <div class="fs-2 fw-bold">Rs. <?= number_format($booking['total_fare'], 2) ?></div>
                    </div>

                    <!-- Trust badges -->
                    <div class="row row-cols-4 g-0 mt-3 pt-3 border-top text-center">
                        <?php foreach ([
                            ['bi-shield-lock','success','Secure'],
                            ['bi-credit-card','success','Encrypted'],
                            ['bi-patch-check','success','Verified'],
                            ['bi-arrow-counterclockwise','primary','Refundable'],
                        ] as [$ic, $col, $lbl]): ?>
                        <div class="col">
                            <i class="bi <?= $ic ?> text-<?= $col ?> fs-5"></i>
                            <div style="font-size:.68rem;color:#6c757d;"><?= $lbl ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Payment Form -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header border-0 bg-white border-bottom">
                    <h6 class="mb-0 fw-bold text-primary">
                        <i class="bi bi-credit-card me-2"></i>Select Payment Method
                    </h6>
                </div>
                <div class="card-body">
                    <?php if ($error_message): ?>
                    <div class="alert alert-danger border-0 rounded-3 py-2">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error_message) ?>
                    </div>
                    <?php endif; ?>

                    <form method="POST" id="paymentForm">
                        <div class="row g-3">
                            <?php foreach ([
                                ['credit_card',   '💳', 'Credit Card',       'Visa / Mastercard'],
                                ['debit_card',    '💳', 'Debit Card',        'ATM / Bank Card'],
                                ['easypaisa',     '📱', 'EasyPaisa',         'Mobile Wallet'],
                                ['jazzcash',      '📲', 'JazzCash',          'Mobile Wallet'],
                                ['bank_transfer', '🏦', 'Bank Transfer',     'IBFT / Online'],
                                ['cash',          '💵', 'Cash at Station',   'Pay on Arrival'],
                            ] as [$val, $icon, $name, $desc]): ?>
                            <div class="col-6">
                                <label class="pay-card w-100" data-method="<?= $val ?>">
                                    <input type="radio" name="payment_method" value="<?= $val ?>" class="d-none" required>
                                    <div class="pay-card-inner">
                                        <span class="pay-icon"><?= $icon ?></span>
                                        <span class="pay-name"><?= $name ?></span>
                                        <span class="pay-desc"><?= $desc ?></span>
                                    </div>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <div id="methodHintBox" class="alert alert-info border-0 rounded-3 py-2 mt-3 d-none">
                            <i class="bi bi-info-circle me-2"></i><span id="methodHintText"></span>
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg w-100 mt-3 py-3" id="payBtn" disabled>
                            <i class="bi bi-lock-fill me-2"></i>
                            Pay Rs. <?= number_format($booking['total_fare'], 2) ?> Securely
                        </button>

                        <p class="text-muted text-center mt-2 mb-0" style="font-size:.78rem;">
                            <i class="bi bi-shield-check me-1 text-success"></i>
                            Your payment is secured with 256-bit SSL encryption
                        </p>
                    </form>

                    <div class="mt-3 pt-3 border-top text-center">
                        <a href="bookings.php" class="text-secondary text-decoration-none small">
                            <i class="bi bi-arrow-left me-1"></i>Back to My Bookings
                        </a>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /row -->
    <?php endif; ?>

</div><!-- /container -->

<script>
(function () {
    var hints = {
        credit_card:   'Enter your Visa or Mastercard details to complete the payment.',
        debit_card:    'Use your ATM or bank debit card for payment.',
        easypaisa:     'Pay via EasyPaisa mobile account or any authorized retailer.',
        jazzcash:      'Pay via JazzCash mobile account or any JazzCash retailer.',
        bank_transfer: 'Transfer the exact amount via IBFT and include your booking reference as the note.',
        cash:          'Your booking will be held. Pay at the station ticket counter before departure.'
    };

    document.querySelectorAll('.pay-card').forEach(function (card) {
        card.addEventListener('click', function () {
            document.querySelectorAll('.pay-card').forEach(function (c) { c.classList.remove('selected'); });
            card.classList.add('selected');
            card.querySelector('input[type=radio]').checked = true;
            var method = card.dataset.method;
            document.getElementById('methodHintText').textContent = hints[method] || '';
            document.getElementById('methodHintBox').classList.remove('d-none');
            document.getElementById('payBtn').disabled = false;
        });
    });

    document.getElementById('paymentForm').addEventListener('submit', function () {
        var btn = document.getElementById('payBtn');
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Processing Payment…';
        btn.disabled = true;
    });
}());
</script>

<?php require_once 'inc/footer.php'; ?>

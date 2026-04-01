<?php
// booking-emp.php – Employee: Booking Detail View

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';

if (!User::isLoggedIn() || $_SESSION['role'] !== 'employee') {
    header('Location: login.php');
    exit();
}

$db   = new Database();
$db->connect();
$conn = $db->getConnection();

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
        $db->query("UPDATE bookings SET booking_status='confirmed' WHERE booking_id = {$booking_id}");
        $success_message = 'Booking confirmed.';
    } elseif ($action === 'cancel') {
        // Release seats
        $seats = $db->select("SELECT seat_id FROM booking_seats WHERE booking_id = {$booking_id}");
        if ($seats) {
            foreach ($seats as $s) {
                $db->query("UPDATE seats SET status='available' WHERE seat_id = {$s['seat_id']}");
            }
        }
        // Restore route seats
        $bk = $db->selectRow("SELECT route_id, number_of_seats FROM bookings WHERE booking_id = {$booking_id}");
        if ($bk) {
            $db->query("UPDATE routes SET available_seats = available_seats + {$bk['number_of_seats']} WHERE route_id = {$bk['route_id']}");
        }
        $db->query("UPDATE bookings SET booking_status='cancelled' WHERE booking_id = {$booking_id}");
        $success_message = 'Booking cancelled and seats released.';
    } elseif ($action === 'mark_present') {
        $db->query("UPDATE bookings SET booking_status='confirmed' WHERE booking_id = {$booking_id}");
        $success_message = 'Passenger marked as checked-in.';
    }
}

// ── Booking details ───────────────────────────────────────────────────────────
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

// ── Passengers ────────────────────────────────────────────────────────────────
$passengers = $db->select(
    "SELECT bs.*, s.seat_number, s.seat_type
     FROM booking_seats bs
     JOIN seats s ON bs.seat_id = s.seat_id
     WHERE bs.booking_id = {$booking_id}
     ORDER BY s.seat_number ASC"
);
if (!$passengers) $passengers = [];

// ── Payment info ──────────────────────────────────────────────────────────────
$payment = $db->selectRow(
    "SELECT * FROM payments WHERE booking_id = {$booking_id} ORDER BY created_at DESC LIMIT 1"
);

$hideMainNavbar = true;
$pageTitle = 'Booking ' . htmlspecialchars($booking['booking_reference']) . ' – Employee';
//require_once 'inc/header.php';
?>

<style>
.emp-wrap { display:flex; min-height:calc(100vh - 60px); }
.emp-sidebar {
    width:230px; flex-shrink:0;
    background:linear-gradient(180deg,#064e3b 0%,#065f46 100%);
    padding:1.5rem 0; position:sticky; top:60px;
    height:calc(100vh - 60px); overflow-y:auto;
}
.emp-sidebar .sb-brand { padding:.5rem 1.25rem 1.25rem; font-weight:800; font-size:1.05rem; color:#d1fae5; border-bottom:1px solid rgba(255,255,255,.15); margin-bottom:.75rem; }
.emp-sidebar a { display:flex; align-items:center; gap:.65rem; padding:.55rem 1.25rem; color:rgba(255,255,255,.82); text-decoration:none; font-size:.88rem; transition:background .2s,color .2s; }
.emp-sidebar a:hover, .emp-sidebar a.active { background:rgba(255,255,255,.16); color:#fff; }
.emp-sidebar .sb-section { font-size:.7rem; text-transform:uppercase; letter-spacing:.08em; color:rgba(255,255,255,.42); padding:.9rem 1.25rem .3rem; font-weight:700; }
.emp-main { flex:1; padding:1.75rem; overflow:hidden; }

.sp { display:inline-block; padding:.3em .9em; border-radius:20px; font-size:.8rem; font-weight:700; }
.sp-confirmed { background:#d1fae5; color:#065f46; }
.sp-pending   { background:#fef3c7; color:#92400e; }
.sp-cancelled { background:#fee2e2; color:#7f1d1d; }
.sp-completed { background:#d1fae5; color:#065f46; }
.sp-refunded  { background:#ede9fe; color:#4c1d95; }
.sp-failed    { background:#fee2e2; color:#7f1d1d; }

/* Booking detail card */
.bk-header {
    background:linear-gradient(135deg,#064e3b 0%,#059669 100%);
    color:#fff; border-radius:14px 14px 0 0; padding:1.4rem 1.8rem;
}
.info-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:.8rem; }
.info-cell { background:#f8fafc; border-radius:10px; padding:.7rem 1rem; }
.info-cell .lbl { font-size:.72rem; text-transform:uppercase; letter-spacing:.4px; color:#9ca3af; display:block; margin-bottom:.1rem; }
.info-cell .val { font-size:.9rem; font-weight:600; color:#1e293b; }

.pax-table thead th { background:#064e3b; color:#fff; font-weight:600; font-size:.82rem; white-space:nowrap; }
.pax-table tbody tr:hover { background:#f0fdf4; }
.pax-table td { font-size:.84rem; vertical-align:middle; }

.timeline-dot {
    width:14px; height:14px; border-radius:50%;
    background:#059669; border:3px solid #d1fae5; flex-shrink:0;
}
.timeline-line { flex:1; width:2px; background:#e2e8f0; margin:2px auto; }

@media print {
    .no-print { display:none !important; }
    .bk-header { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
}
@media (max-width:768px) { .emp-sidebar { display:none; } .emp-main { padding:1rem; } }
</style>

<div class="emp-wrap">
<!-- Sidebar -->
<aside class="emp-sidebar no-print">
    <div class="sb-brand"><i class="bi bi-train-front-fill me-2"></i>Employee Panel</div>
    <div class="sb-section">Main</div>
    <a href="employee-dashboard.php"><i class="bi bi-speedometer2"></i>Dashboard</a>
    <div class="sb-section">Operations</div>
    <a href="my-trains.php"><i class="bi bi-train-front"></i>My Trains</a>
    <a href="check-passengers.php"><i class="bi bi-people"></i>Passengers</a>
    <a href="assign-seats.php"><i class="bi bi-grid-3x3-gap"></i>Seat Management</a>
    <div class="sb-section">Account</div>
    <a href="profile.php"><i class="bi bi-person-circle"></i>My Profile</a>
    <a href="logout.php"><i class="bi bi-box-arrow-right"></i>Logout</a>
</aside>

<main class="emp-main">

    <!-- Top bar -->
    <div class="d-flex justify-content-between align-items-center mb-3 no-print flex-wrap gap-2">
        <a href="employee-dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Dashboard
        </a>
        <div class="d-flex gap-2 flex-wrap">
            <a href="route-details-emp.php?id=<?= $booking['route_id'] ?>" class="btn btn-outline-success btn-sm">
                <i class="bi bi-map me-1"></i>Route Details
            </a>
            <button onclick="window.print()" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-printer me-1"></i>Print
            </button>
        </div>
    </div>

    <?php if ($success_message): ?>
    <div class="alert alert-success border-0 rounded-3 py-2 mb-3">
        <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success_message) ?>
    </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
    <div class="alert alert-danger border-0 rounded-3 py-2 mb-3">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error_message) ?>
    </div>
    <?php endif; ?>

    <div class="row g-4 align-items-start">
        <!-- Main booking card -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm overflow-hidden">
                <!-- Header -->
                <div class="bk-header d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <div style="font-size:1.3rem; font-weight:800;">🎫 <?= htmlspecialchars($booking['booking_reference']) ?></div>
                        <div style="font-size:.82rem; opacity:.8; margin-top:.2rem;">
                            Booked: <?= date('d M Y H:i', strtotime($booking['booking_date'])) ?>
                        </div>
                        <div class="mt-2 d-flex gap-2 flex-wrap">
                            <span class="sp sp-<?= $booking['booking_status'] ?>"><?= ucfirst($booking['booking_status']) ?></span>
                            <?php if ($payment): ?>
                            <span class="sp sp-<?= $payment['payment_status'] ?>">Payment: <?= ucfirst($payment['payment_status']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="text-end">
                        <div style="font-size:1.7rem; font-weight:900;">Rs. <?= number_format($booking['total_fare'], 2) ?></div>
                        <div style="font-size:.78rem; opacity:.7;"><?= $booking['number_of_seats'] ?> seat(s)</div>
                    </div>
                </div>

                <div class="p-3 p-md-4">
                    <!-- Journey strip -->
                    <div class="d-flex align-items-center gap-3 p-3 rounded-3 mb-4" style="background:#f0fdf4;">
                        <div class="text-center">
                            <div class="fw-bold fs-4 text-success"><?= htmlspecialchars($booking['departure_city']) ?></div>
                            <div class="small"><?= date('H:i', strtotime($booking['departure_time'])) ?></div>
                        </div>
                        <div class="flex-grow-1 text-center">
                            <i class="bi bi-arrow-right fs-3 text-muted"></i>
                            <div class="text-muted" style="font-size:.72rem;"><?= number_format($booking['distance_km'], 0) ?> km</div>
                        </div>
                        <div class="text-center">
                            <div class="fw-bold fs-4 text-success"><?= htmlspecialchars($booking['arrival_city']) ?></div>
                            <div class="small"><?= date('H:i', strtotime($booking['arrival_time'])) ?></div>
                        </div>
                        <div class="vr mx-2"></div>
                        <div class="text-center">
                            <div class="fw-semibold small"><?= date('D, d M Y', strtotime($booking['journey_date'])) ?></div>
                            <div class="text-muted" style="font-size:.72rem;"><?= htmlspecialchars($booking['train_name']) ?></div>
                        </div>
                    </div>

                    <!-- Info grid -->
                    <div class="info-grid mb-4">
                        <?php foreach ([
                            ['Train',         htmlspecialchars($booking['train_name']) . ' (' . htmlspecialchars($booking['train_number']) . ')'],
                            ['Train Type',    htmlspecialchars($booking['train_type'])],
                            ['Journey Date',  date('D, d M Y', strtotime($booking['journey_date']))],
                            ['Seats Booked',  (int)$booking['number_of_seats']],
                            ['Passenger',     htmlspecialchars($booking['booker_name'])],
                            ['Email',         htmlspecialchars($booking['booker_email'])],
                            ['Phone',         htmlspecialchars($booking['booker_phone'] ?? '—')],
                            ['Base Fare/Seat','Rs. ' . number_format($booking['base_fare'], 2)],
                        ] as [$lbl, $val]): ?>
                        <div class="info-cell">
                            <span class="lbl"><?= $lbl ?></span>
                            <span class="val"><?= $val ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Passenger table -->
                    <?php if (!empty($passengers)): ?>
                    <h6 class="fw-bold mb-3"><i class="bi bi-people-fill me-2 text-success"></i>Passenger Details</h6>
                    <div class="table-responsive mb-3">
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
                                    <td class="fw-semibold small"><?= htmlspecialchars($p['passenger_name']) ?></td>
                                    <td class="text-center small"><?= htmlspecialchars($p['passenger_age'] ?? '—') ?></td>
                                    <td class="text-center small"><?= $p['passenger_gender'] === 'M' ? 'Male' : ($p['passenger_gender'] === 'F' ? 'Female' : (htmlspecialchars($p['passenger_gender'] ?? '—'))) ?></td>
                                    <td class="text-center fw-bold"><?= htmlspecialchars($p['seat_number']) ?></td>
                                    <td class="text-center small"><?= ucfirst($p['seat_type']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info mb-3 py-2 small border-0">
                        <i class="bi bi-info-circle me-2"></i>No passenger seat details recorded.
                    </div>
                    <?php endif; ?>

                    <!-- Payment record -->
                    <?php if ($payment): ?>
                    <h6 class="fw-bold mb-2"><i class="bi bi-credit-card me-2 text-primary"></i>Payment Details</h6>
                    <div class="row g-2">
                        <?php foreach ([
                            ['Method',      ucwords(str_replace('_',' ',$payment['payment_method']))],
                            ['Amount',      'Rs. ' . number_format($payment['amount'], 2)],
                            ['Status',      ucfirst($payment['payment_status'])],
                            ['Transaction', htmlspecialchars($payment['transaction_id'] ?? '—')],
                            ['Date',        $payment['payment_date'] ? date('d M Y H:i', strtotime($payment['payment_date'])) : '—'],
                        ] as [$lbl, $val]): ?>
                        <div class="col-6 col-md-4">
                            <div class="info-cell">
                                <span class="lbl"><?= $lbl ?></span>
                                <span class="val" style="font-size:.82rem;"><?= $val ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right: Actions panel -->
        <div class="col-lg-4 no-print">
            <div class="card border-0 shadow-sm p-3 mb-3">
                <h6 class="fw-bold mb-3"><i class="bi bi-lightning-charge me-2 text-warning"></i>Quick Actions</h6>
                <div class="d-grid gap-2">
                    <?php if ($booking['booking_status'] === 'pending'): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="confirm">
                        <button type="submit" class="btn btn-success w-100"
                                onclick="return confirm('Confirm this booking?')">
                            <i class="bi bi-check-circle me-2"></i>Confirm Booking
                        </button>
                    </form>
                    <?php endif; ?>

                    <?php if ($booking['booking_status'] !== 'cancelled'): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="cancel">
                        <button type="submit" class="btn btn-danger w-100"
                                onclick="return confirm('Cancel this booking and release seats?')">
                            <i class="bi bi-x-circle me-2"></i>Cancel & Release Seats
                        </button>
                    </form>
                    <?php endif; ?>

                    <a href="booking_details.php?id=<?= $booking_id ?>"
                       class="btn btn-outline-primary">
                        <i class="bi bi-ticket-detailed me-2"></i>View E-Ticket
                    </a>
                    <a href="route-details-emp.php?id=<?= $booking['route_id'] ?>"
                       class="btn btn-outline-success">
                        <i class="bi bi-map me-2"></i>Route Details
                    </a>
                    <button onclick="window.print()" class="btn btn-outline-secondary">
                        <i class="bi bi-printer me-2"></i>Print Booking
                    </button>
                </div>
            </div>

            <!-- Booking timeline -->
            <div class="card border-0 shadow-sm p-3">
                <h6 class="fw-bold mb-3"><i class="bi bi-clock-history me-2 text-info"></i>Booking Timeline</h6>
                <div class="d-flex gap-2">
                    <div class="d-flex flex-column align-items-center">
                        <div class="timeline-dot"></div>
                        <div class="timeline-line" style="height:40px;"></div>
                        <div class="timeline-dot" style="background:<?= $booking['booking_status'] === 'confirmed' ? '#059669' : ($booking['booking_status'] === 'cancelled' ? '#ef4444' : '#f59e0b') ?>;"></div>
                        <?php if ($payment): ?>
                        <div class="timeline-line" style="height:40px;"></div>
                        <div class="timeline-dot" style="background:<?= $payment['payment_status'] === 'completed' ? '#059669' : '#f59e0b' ?>;"></div>
                        <?php endif; ?>
                    </div>
                    <div class="flex-grow-1">
                        <div class="mb-3">
                            <div class="small fw-semibold">Booking Created</div>
                            <div class="text-muted" style="font-size:.75rem;"><?= date('d M Y H:i', strtotime($booking['booking_date'])) ?></div>
                        </div>
                        <div class="mb-3">
                            <div class="small fw-semibold">Status: <?= ucfirst($booking['booking_status']) ?></div>
                            <div class="text-muted" style="font-size:.75rem;"><?= date('d M Y', strtotime($booking['updated_at'] ?? $booking['booking_date'])) ?></div>
                        </div>
                        <?php if ($payment): ?>
                        <div>
                            <div class="small fw-semibold">Payment: <?= ucfirst($payment['payment_status']) ?></div>
                            <div class="text-muted" style="font-size:.75rem;"><?= $payment['payment_date'] ? date('d M Y H:i', strtotime($payment['payment_date'])) : '—' ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

</main>
</div>

<?php require_once 'inc/footer.php'; ?>

<?php
// booking_details.php - View E-Ticket / Booking Receipt

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

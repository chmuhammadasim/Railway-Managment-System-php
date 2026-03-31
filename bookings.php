<?php
// bookings.php - View User Bookings

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

$user_id     = $_SESSION['user_id'];
$booking_obj = new Booking($db);
$bookings    = $booking_obj->getUserBookings($user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings - Railway Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="public/css/style.css">
    <style>
        body { background: #f0f2f5; }
        .booking-card { background:#fff; border-radius:10px; padding:1.5rem; margin-bottom:1.2rem; box-shadow:0 2px 8px rgba(0,0,0,0.08); border-left:5px solid #1a3c6e; }
        .booking-card.cancelled { border-left-color:#dc3545; opacity:0.85; }
        .booking-card.pending   { border-left-color:#ffc107; }
        .booking-card.confirmed { border-left-color:#28a745; }
        .booking-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1rem; padding-bottom:0.75rem; border-bottom:1px solid #eee; }
        .status-badge { padding:0.3em 0.9em; border-radius:20px; font-size:0.85rem; font-weight:600; }
        .status-confirmed { background:#d4edda; color:#155724; }
        .status-pending   { background:#fff3cd; color:#856404; }
        .status-cancelled { background:#f8d7da; color:#721c24; }
        .status-refunded  { background:#d1ecf1; color:#0c5460; }
        .route-display { font-size:1.1rem; font-weight:600; color:#1a3c6e; }
        .info-pill { background:#f0f2f5; padding:0.2em 0.7em; border-radius:12px; font-size:0.82rem; color:#555; }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php">🚂 Railway System</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item"><a class="nav-link" href="index.php">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link active" href="bookings.php">My Bookings</a></li>
                    <li class="nav-item"><a class="nav-link" href="profile.php">Profile</a></li>
                    <li class="nav-item"><a class="nav-link text-danger" href="logout.php">Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container" style="max-width:900px; margin-top:2rem;">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="mb-0"><i class="bi bi-ticket-perforated me-2"></i>My Bookings</h3>
            <a href="index.php" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-circle me-1"></i>New Booking
            </a>
        </div>

        <?php if ($bookings && count($bookings) > 0): ?>
            <?php foreach ($bookings as $booking): ?>
                <?php
                $status  = strtolower($booking['booking_status']);
                $jdate   = strtotime($booking['journey_date'] . ' 00:00:00');
                $hours   = ($jdate - time()) / 3600;
                $modifiable = $hours >= 24 && $status !== 'cancelled';
                ?>
                <div class="booking-card <?= $status ?>">
                    <div class="booking-header">
                        <div>
                            <div class="route-display">
                                <?= htmlspecialchars($booking['departure_city'] ?? 'N/A') ?>
                                <i class="bi bi-arrow-right mx-2"></i>
                                <?= htmlspecialchars($booking['arrival_city'] ?? 'N/A') ?>
                            </div>
                            <div class="mt-1">
                                <span class="info-pill"><i class="bi bi-train-front me-1"></i><?= htmlspecialchars($booking['train_name'] ?? '') ?></span>
                                <span class="info-pill ms-1"><i class="bi bi-hash me-1"></i><?= htmlspecialchars($booking['booking_reference']) ?></span>
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="status-badge status-<?= $status ?>">
                                <?= ucfirst($booking['booking_status']) ?>
                            </span>
                            <div class="mt-1">
                                <span class="status-badge status-<?= strtolower($booking['payment_status']) ?>">
                                    <?= ucfirst($booking['payment_status']) ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-sm-4">
                            <small class="text-muted d-block">Journey Date</small>
                            <strong><?= date('D, d M Y', strtotime($booking['journey_date'])) ?></strong>
                        </div>
                        <div class="col-sm-4">
                            <small class="text-muted d-block">Departure</small>
                            <strong><?= date('H:i', strtotime($booking['departure_time'])) ?></strong>
                        </div>
                        <div class="col-sm-4">
                            <small class="text-muted d-block">Seats</small>
                            <strong><?= htmlspecialchars($booking['number_of_seats']) ?></strong>
                        </div>
                        <div class="col-sm-4">
                            <small class="text-muted d-block">Total Fare</small>
                            <strong>Rs. <?= number_format($booking['total_fare'], 2) ?></strong>
                        </div>
                        <div class="col-sm-8">
                            <small class="text-muted d-block">Booked On</small>
                            <strong><?= date('d M Y', strtotime($booking['booking_date'])) ?></strong>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="d-flex flex-wrap gap-2">
                        <!-- E-Ticket / View Details (always visible) -->
                        <a href="booking_details.php?id=<?= $booking['booking_id'] ?>" class="btn btn-primary btn-sm">
                            <i class="bi bi-ticket-perforated me-1"></i> E-Ticket
                        </a>

                        <?php if ($booking['booking_status'] === 'pending' && $booking['payment_status'] !== 'completed'): ?>
                            <a href="payment.php?booking_id=<?= $booking['booking_id'] ?>" class="btn btn-success btn-sm">
                                <i class="bi bi-credit-card me-1"></i> Pay Now
                            </a>
                        <?php endif; ?>

                        <?php if ($modifiable): ?>
                            <a href="booking_update.php?id=<?= $booking['booking_id'] ?>" class="btn btn-warning btn-sm">
                                <i class="bi bi-pencil-square me-1"></i> Change Journey
                            </a>
                            <a href="booking_cancel.php?id=<?= $booking['booking_id'] ?>" class="btn btn-outline-danger btn-sm">
                                <i class="bi bi-x-circle me-1"></i> Cancel
                            </a>
                        <?php elseif ($status !== 'cancelled' && $hours < 24 && $hours > 0): ?>
                            <span class="text-muted small align-self-center">
                                <i class="bi bi-lock me-1"></i>Changes locked (< 24 hrs to journey)
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="card text-center p-5">
                <i class="bi bi-ticket-perforated display-3 text-muted mb-3"></i>
                <h4>No bookings found</h4>
                <p class="text-muted">You haven't made any bookings yet.</p>
                <a href="index.php" class="btn btn-primary">Book a Train</a>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings - Railway Management System</title>
    <link rel="stylesheet" href="public/css/style.css">
    <style>
        .booking-card { background: #fff; border-radius: 8px; padding: 1.5em; margin-bottom: 1em; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .booking-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1em; border-bottom: 2px solid #f0f0f0; padding-bottom: 0.5em; }
        .status-badge { padding: 0.3em 0.8em; border-radius: 20px; font-size: 0.9em; font-weight: 600; }
        .status-confirmed { background: #4caf50; color: white; }
        .status-pending { background: #ff9800; color: white; }
        .status-cancelled { background: #f44336; color: white; }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container">
            <div class="logo">
                <h1>🚂 Railway System</h1>
            </div>
            <ul class="nav-links">
                <li><a href="index.php">Home</a></li>
                <li><a href="dashboard.php">Dashboard</a></li>
                <li><a href="bookings.php">My Bookings</a></li>
                <li><a href="profile.php">Profile</a></li>
                <li><a href="logout.php" class="btn-logout">Logout</a></li>
            </ul>
        </div>
    </nav>

    <div class="container" style="margin-top: 2em;">
        <h2>My Bookings</h2>
        
        <?php if ($bookings && count($bookings) > 0): ?>
            <?php foreach ($bookings as $booking): ?>
                <div class="booking-card">
                    <div class="booking-header">
                        <div>
                            <h3><?= htmlspecialchars($booking['train_name'] ?? 'Train') ?></h3>
                            <p style="color: #666;">Booking Ref: <strong><?= htmlspecialchars($booking['booking_reference']) ?></strong></p>
                        </div>
                        <span class="status-badge status-<?= strtolower($booking['booking_status']) ?>">
                            <?= ucfirst($booking['booking_status']) ?>
                        </span>
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1em;">
                        <div>
                            <p><strong>From:</strong> <?= htmlspecialchars($booking['departure_city'] ?? 'N/A') ?></p>
                            <p><strong>To:</strong> <?= htmlspecialchars($booking['arrival_city'] ?? 'N/A') ?></p>
                        </div>
                        <div>
                            <p><strong>Journey Date:</strong> <?= htmlspecialchars($booking['journey_date']) ?></p>
                            <p><strong>Seats:</strong> <?= htmlspecialchars($booking['number_of_seats']) ?></p>
                        </div>
                        <div>
                            <p><strong>Total Fare:</strong> Rs. <?= number_format($booking['total_fare'], 2) ?></p>
                            <p><strong>Payment:</strong> <span class="status-badge status-<?= strtolower($booking['payment_status']) ?>"><?= ucfirst($booking['payment_status']) ?></span></p>
                        </div>
                    </div>
                    <div style="margin-top: 1em;">
                        <?php if ($booking['booking_status'] == 'pending'): ?>
                            <a href="payment.php?booking_id=<?= $booking['booking_id'] ?>" class="btn" style="margin-right: 0.5em;">Pay Now</a>
                            <a href="booking_cancel.php?id=<?= $booking['booking_id'] ?>" class="btn" style="background: var(--danger-color);">Cancel</a>
                        <?php elseif ($booking['booking_status'] == 'confirmed'): ?>
                            <a href="booking_details.php?id=<?= $booking['booking_id'] ?>" class="btn">View Details</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="card" style="text-align: center; padding: 3em;">
                <h3>No bookings found</h3>
                <p>You haven't made any bookings yet.</p>
                <a href="index.php" class="btn">Book a Train</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

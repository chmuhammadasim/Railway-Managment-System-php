<?php
// booking_cancel.php - Booking Cancellation Request
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

$booking_obj = new Booking($db);
$booking     = $booking_obj->getBookingById($booking_id);

if (!$booking || $booking['user_id'] != $user_id) {
    header('Location: bookings.php');
    exit();
}

// Load route info for display
$route = $db->selectRow(
    "SELECT r.*, t.train_name FROM routes r JOIN trains t ON r.train_id = t.train_id WHERE r.route_id = {$booking['route_id']}"
);

// 24-hour window check
$journey_datetime    = strtotime($booking['journey_date'] . ' 00:00:00');
$hours_until_journey = ($journey_datetime - time()) / 3600;
$can_cancel          = $hours_until_journey >= 24 && $booking['booking_status'] !== 'cancelled';

$error_message   = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_cancel) {
        $error_message = 'Cancellation is no longer allowed (less than 24 hours to journey, or already cancelled).';
    } else {
        $result = $booking_obj->requestCancellation($booking_id, $user_id);
        if ($result['success']) {
            $success_message = $result['message'];
            header('Refresh: 3; url=bookings.php');
        } else {
            $error_message = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cancel Booking - Railway Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="public/css/style.css">
    <style> body { background: #f0f2f5; } </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php">🚂 Railway System</a>
            <div>
                <a href="bookings.php" class="btn btn-outline-light btn-sm me-2">My Bookings</a>
                <a href="logout.php" class="btn btn-danger btn-sm">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container" style="max-width: 580px; margin-top: 2rem;">
        <a href="bookings.php" class="btn btn-outline-secondary mb-3">
            <i class="bi bi-arrow-left"></i> Back to Bookings
        </a>

        <div class="card shadow-sm">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0"><i class="bi bi-x-circle me-2"></i>Cancel Booking</h5>
            </div>
            <div class="card-body">

                <?php if ($error_message): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
                <?php endif; ?>

                <?php if ($success_message): ?>
                    <div class="alert alert-success">
                        <?= htmlspecialchars($success_message) ?>
                        <br><small>Redirecting to your bookings...</small>
                    </div>
                <?php else: ?>

                <!-- Booking Summary -->
                <div class="border rounded p-3 mb-3 bg-light">
                    <div class="row">
                        <div class="col-sm-6">
                            <p class="mb-1"><strong>Booking Ref:</strong> <?= htmlspecialchars($booking['booking_reference']) ?></p>
                            <p class="mb-1"><strong>Train:</strong> <?= htmlspecialchars($route['train_name'] ?? 'N/A') ?></p>
                            <p class="mb-1"><strong>Route:</strong>
                                <?= htmlspecialchars($route['departure_city'] ?? '') ?>
                                → <?= htmlspecialchars($route['arrival_city'] ?? '') ?>
                            </p>
                        </div>
                        <div class="col-sm-6">
                            <p class="mb-1"><strong>Journey Date:</strong> <?= date('D, d M Y', strtotime($booking['journey_date'])) ?></p>
                            <p class="mb-1"><strong>Seats:</strong> <?= htmlspecialchars($booking['number_of_seats']) ?></p>
                            <p class="mb-1"><strong>Fare:</strong> Rs. <?= number_format($booking['total_fare'], 2) ?></p>
                        </div>
                    </div>
                </div>

                <?php if (!$can_cancel): ?>
                    <?php if ($booking['booking_status'] === 'cancelled'): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-info-circle me-1"></i> This booking has already been cancelled.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <strong>Cancellation window has closed.</strong><br>
                            Bookings can only be cancelled up to <strong>24 hours</strong> before the journey date.
                            Your journey is in <strong><?= number_format($hours_until_journey, 1) ?> hours</strong>.
                        </div>
                    <?php endif; ?>
                    <a href="bookings.php" class="btn btn-secondary">Back to Bookings</a>
                <?php else: ?>
                    <!-- Cancellation Details -->
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Are you sure you want to cancel this booking?</strong><br>
                        <ul class="mb-0 mt-2">
                            <li>Your seats will be released immediately.</li>
                            <li>If payment was completed, a <strong>full refund</strong> will be initiated.</li>
                            <li>This action <strong>cannot be undone</strong>.</li>
                        </ul>
                    </div>

                    <div class="d-flex gap-2">
                        <form method="POST" style="display:inline;">
                            <button type="submit" class="btn btn-danger">
                                <i class="bi bi-x-circle me-1"></i> Yes, Cancel Booking
                            </button>
                        </form>
                        <a href="bookings.php" class="btn btn-outline-secondary">No, Keep My Booking</a>
                    </div>
                <?php endif; ?>

                <?php endif; // $success_message else ?>
            </div><!-- /card-body -->
        </div><!-- /card -->
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


if (!$booking_id) {
    header('Location: bookings.php');
    exit();
}

$booking_obj = new Booking($db);
$booking = $booking_obj->getBookingById($booking_id);

if (!$booking || $booking['user_id'] != $user_id) {
    header('Location: bookings.php');
    exit();
}

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Mark booking as cancellation requested
    $result = $booking_obj->requestCancellation($booking_id);
    if ($result['success']) {
        $success_message = 'Cancellation request submitted!';
    } else {
        $error_message = $result['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cancel Booking - Railway Management System</title>
    <link rel="stylesheet" href="public/css/style.css">
</head>
<body>
    <nav class="navbar">
        <div class="container">
            <div class="logo">
                <h1>🚂 Railway System</h1>
            </div>
            <ul class="nav-links">
                <li><a href="dashboard.php">Dashboard</a></li>
                <li><a href="bookings.php">My Bookings</a></li>
                <li><a href="profile.php">Profile</a></li>
                <li><a href="logout.php" class="btn">Logout</a></li>
            </ul>
        </div>
    </nav>
    <div class="container" style="max-width: 500px; margin-top: 2em;">
        <div class="card">
            <h2>Cancel Booking</h2>
            <?php if ($error_message): ?>
                <div class="alert alert-danger" style="color: var(--danger-color); margin-bottom: 1em;">
                    <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>
            <?php if ($success_message): ?>
                <div class="alert alert-success" style="color: var(--success-color); margin-bottom: 1em;">
                    <?= htmlspecialchars($success_message) ?>
                </div>
            <?php else: ?>
                <form method="post">
                    <p>Are you sure you want to request cancellation for booking #<?= htmlspecialchars($booking_id) ?>?</p>
                    <button type="submit" class="btn" style="background:var(--danger-color);">Request Cancellation</button>
                    <a href="bookings.php" class="btn">Back</a>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

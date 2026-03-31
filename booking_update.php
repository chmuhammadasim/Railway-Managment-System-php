<?php
// booking_update.php - Change/Update a Booked Journey

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/Booking.php';
require_once 'src/classes/Train.php';

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

if ($booking['booking_status'] === 'cancelled') {
    header('Location: bookings.php');
    exit();
}

// Enforce 24-hour update window
$journey_datetime   = strtotime($booking['journey_date'] . ' 00:00:00');
$hours_until_journey = ($journey_datetime - time()) / 3600;

$error_message   = '';
$success_message = '';

// Load current route info
$current_route = $db->selectRow(
    "SELECT r.*, t.train_name, t.train_number
     FROM routes r JOIN trains t ON r.train_id = t.train_id
     WHERE r.route_id = {$booking['route_id']}"
);

// Available alternative routes (same departure & arrival city, different route_id, future dates, enough seats)
$alt_routes = array();
if ($current_route) {
    $dep = $db->getConnection()->real_escape_string($current_route['departure_city']);
    $arr = $db->getConnection()->real_escape_string($current_route['arrival_city']);
    $num = (int)$booking['number_of_seats'];
    $alt_routes = $db->select(
        "SELECT r.*, t.train_name, t.train_number
         FROM routes r JOIN trains t ON r.train_id = t.train_id
         WHERE r.departure_city = '{$dep}'
           AND r.arrival_city   = '{$arr}'
           AND r.status = 'scheduled'
           AND r.journey_date   >= CURDATE()
           AND r.available_seats >= {$num}
           AND r.route_id != {$booking['route_id']}
         ORDER BY r.journey_date ASC, r.departure_time ASC"
    );
    if (!$alt_routes) $alt_routes = array();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($hours_until_journey < 24) {
        $error_message = 'Updates are only allowed up to 24 hours before the journey date.';
    } else {
        $new_route_id = isset($_POST['new_route_id']) ? (int)$_POST['new_route_id'] : 0;
        if (!$new_route_id) {
            $error_message = 'Please select a new route.';
        } else {
            $result = $booking_obj->updateBookingJourney($booking_id, $new_route_id, $user_id);
            if ($result['success']) {
                $success_message = $result['message'];
                // Redirect to payment if fare changes
                header('Refresh: 2; url=payment.php?booking_id=' . $booking_id);
            } else {
                $error_message = $result['message'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Journey - Railway Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="public/css/style.css">
    <style>
        body { background: #f0f2f5; }
        .page-wrap { max-width: 820px; margin: 2rem auto; padding: 0 1rem; }
        .current-route-card { background: #e8f0fe; border-left: 4px solid #1a3c6e; border-radius: 8px; padding: 1.2rem 1.5rem; margin-bottom: 1.5rem; }
        .route-option { border: 2px solid #dee2e6; border-radius: 8px; padding: 1rem 1.2rem; margin-bottom: 0.8rem; cursor: pointer; transition: all 0.2s; }
        .route-option:hover { border-color: #2d6a9f; background: #f0f5ff; }
        .route-option input[type=radio] { margin-right: 0.6rem; }
        .route-option.selected { border-color: #1a3c6e; background: #e8f0fe; }
        .fare-pill { background: #1a3c6e; color: #fff; padding: 0.25em 0.7em; border-radius: 20px; font-weight: 600; }
        .seats-pill { background: #28a745; color: #fff; padding: 0.25em 0.7em; border-radius: 20px; font-size: 0.85rem; }
        .deadline-warn { background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 0.8rem 1rem; }
    </style>
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

    <div class="page-wrap">
        <a href="bookings.php" class="btn btn-outline-secondary mb-3">
            <i class="bi bi-arrow-left"></i> Back to Bookings
        </a>

        <div class="card shadow-sm">
            <div class="card-header" style="background:#1a3c6e; color:#fff;">
                <h4 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Change / Update Journey</h4>
            </div>
            <div class="card-body">

                <?php if ($error_message): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
                <?php endif; ?>

                <?php if ($success_message): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($success_message) ?>
                        <br><small>Redirecting to payment...</small>
                    </div>
                <?php endif; ?>

                <?php if ($hours_until_journey < 24): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Update not available.</strong> Journey changes are only allowed up to 24 hours before the departure date.
                    </div>
                <?php else: ?>

                <!-- Current Booking -->
                <div class="current-route-card">
                    <h6 class="mb-2" style="color:#1a3c6e;"><i class="bi bi-ticket-perforated me-1"></i> Current Booking</h6>
                    <div class="row">
                        <div class="col-sm-6">
                            <strong>Booking Ref:</strong> <?= htmlspecialchars($booking['booking_reference']) ?><br>
                            <strong>Train:</strong> <?= htmlspecialchars($current_route['train_name'] ?? 'N/A') ?>
                                (<?= htmlspecialchars($current_route['train_number'] ?? '') ?>)<br>
                            <strong>Route:</strong>
                                <?= htmlspecialchars($current_route['departure_city'] ?? '') ?>
                                → <?= htmlspecialchars($current_route['arrival_city'] ?? '') ?>
                        </div>
                        <div class="col-sm-6">
                            <strong>Date:</strong> <?= date('D, d M Y', strtotime($booking['journey_date'])) ?><br>
                            <strong>Departure:</strong> <?= date('H:i', strtotime($current_route['departure_time'] ?? '00:00:00')) ?><br>
                            <strong>Seats:</strong> <?= htmlspecialchars($booking['number_of_seats']) ?>
                            &nbsp;<strong>Fare:</strong> Rs. <?= number_format($booking['total_fare'], 2) ?>
                        </div>
                    </div>
                </div>

                <!-- Deadline notice -->
                <div class="deadline-warn mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    You can change your journey up to <strong>24 hours before departure</strong>.
                    Time remaining: <strong>
                        <?php
                        $h = floor($hours_until_journey);
                        $m = floor(($hours_until_journey - $h) * 60);
                        echo "{$h}h {$m}m";
                        ?>
                    </strong>
                </div>

                <!-- Alternative Routes -->
                <?php if (!empty($alt_routes)): ?>
                    <h6 class="mb-3">Select a New Journey Date / Train</h6>
                    <form method="POST">
                        <div id="route-list">
                            <?php foreach ($alt_routes as $route): ?>
                                <label class="route-option d-block" onclick="this.classList.toggle('selected')">
                                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                        <div>
                                            <input type="radio" name="new_route_id" value="<?= $route['route_id'] ?>" required>
                                            <strong><?= htmlspecialchars($route['train_name']) ?></strong>
                                            <span class="text-muted">(<?= htmlspecialchars($route['train_number']) ?>)</span>
                                        </div>
                                        <div>
                                            <span class="fare-pill">Rs. <?= number_format($route['base_fare'] * $booking['number_of_seats'], 0) ?></span>
                                            <span class="seats-pill ms-1"><?= $route['available_seats'] ?> seats available</span>
                                        </div>
                                    </div>
                                    <div class="mt-2 text-muted" style="padding-left:1.5rem;">
                                        <strong><?= date('D, d M Y', strtotime($route['journey_date'])) ?></strong> &nbsp;|&nbsp;
                                        Departs: <?= date('H:i', strtotime($route['departure_time'])) ?> &nbsp;→&nbsp;
                                        Arrives: <?= date('H:i', strtotime($route['arrival_time'])) ?> &nbsp;|&nbsp;
                                        <?= number_format($route['distance_km'], 0) ?> km
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div class="mt-3 d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-1"></i> Confirm Change
                            </button>
                            <a href="bookings.php" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-circle me-1"></i>
                        No alternative routes found for
                        <strong><?= htmlspecialchars($current_route['departure_city'] ?? '') ?>
                        → <?= htmlspecialchars($current_route['arrival_city'] ?? '') ?></strong>
                        with <?= (int)$booking['number_of_seats'] ?> seat(s) available.
                    </div>
                <?php endif; ?>
                <?php endif; // hours_until_journey >= 24 ?>

            </div><!-- /card-body -->
        </div><!-- /card -->
    </div><!-- /page-wrap -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

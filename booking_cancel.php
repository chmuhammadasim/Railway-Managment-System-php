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
$user_id = $_SESSION['user_id'];
$booking_id = $_GET['id'] ?? null;

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

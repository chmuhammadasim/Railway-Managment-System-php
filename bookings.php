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

$user_id = $_SESSION['user_id'];
$booking_obj = new Booking($db);
$bookings = $booking_obj->getUserBookings($user_id);
?>
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

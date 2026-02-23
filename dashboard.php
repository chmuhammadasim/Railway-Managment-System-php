<?php
// dashboard.php - User Dashboard

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/Booking.php';
require_once 'src/classes/Payment.php';

// Check if user is logged in
if (!User::isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$db = new Database();
$db->connect();

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Redirect admin and employee to their dashboards
if ($role == 'admin') {
    header('Location: admin-dashboard.php');
    exit();
} elseif ($role == 'employee') {
    header('Location: employee-dashboard.php');
    exit();
}

$user_obj = new User($db);
$user = $user_obj->getUserById($user_id);

$booking_obj = new Booking($db);
$bookings = $booking_obj->getUserBookings($user_id);
if (!$bookings) $bookings = array();

$payment_obj = new Payment($db);
$payments = $payment_obj->getUserPayments($user_id);
if (!$payments) $payments = array();

// Calculate stats
$total_bookings = count($bookings);
$completed_bookings = 0;
$upcoming_journeys = 0;

if ($bookings) {
    foreach ($bookings as $booking) {
        if ($booking['booking_status'] == 'confirmed') {
            $completed_bookings++;
            if (strtotime($booking['journey_date']) > time()) {
                $upcoming_journeys++;
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
    <title>User Dashboard - Railway Management System</title>
    <link rel="stylesheet" href="public/css/style.css">
</head>
<body>
    <!-- Navigation Bar -->
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
                            <li style="position:relative;">
                                <a href="notifications.php" style="position:relative;">
                                    <span style="font-size:1.3em;">🔔</span>
                                    <?php
                                    // Notification count (notifications table not yet implemented)
                                    $notif_count = 0;
                                    if ($notif_count > 0): ?>
                                    <span style="position:absolute;top:-6px;right:-8px;background:var(--danger-color);color:#fff;border-radius:50%;padding:2px 7px;font-size:0.8em;"> <?= $notif_count ?> </span>
                                    <?php endif; ?>
                                </a>
                            </li>
                            <li><a href="logout.php" class="btn-logout">Logout</a></li>
            </ul>
        </div>
    </nav>

    <!-- Dashboard Section -->
    <section class="dashboard-section">
        <div class="container">
            <div class="dashboard-header">
                <h2>Welcome, <?php echo $user['full_name']; ?>!</h2>
                <p>Here's your travel dashboard</p>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h3><?php echo $total_bookings; ?></h3>
                    <p>Total Bookings</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo $upcoming_journeys; ?></h3>
                    <p>Upcoming Journeys</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo $completed_bookings; ?></h3>
                    <p>Completed Journeys</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo count($payments) ?? 0; ?></h3>
                    <p>Payments Made</p>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <h3>Quick Actions</h3>
                <div class="action-buttons">
                    <button class="btn-action" onclick="location.href='search.php'">
                        🔍 Search Trains
                    </button>
                    <button class="btn-action" onclick="location.href='bookings.php'">
                        📋 View Bookings
                    </button>
                    <button class="btn-action" onclick="location.href='profile.php'">
                        👤 Edit Profile
                    </button>
                </div>
            </div>

            <!-- Recent Bookings -->
            <div class="recent-bookings">
                <h3>Recent Bookings</h3>
                <?php if ($bookings): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Booking Reference</th>
                                <th>Train</th>
                                <th>Route</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Fare</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($bookings, 0, 5) as $booking): ?>
                                <tr>
                                    <td><?php echo $booking['booking_reference']; ?></td>
                                    <td><?php echo $booking['train_name']; ?></td>
                                    <td><?php echo $booking['departure_city'] . ' → ' . $booking['arrival_city']; ?></td>
                                    <td><?php echo $booking['journey_date']; ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo str_replace(' ', '-', strtolower($booking['booking_status'])); ?>">
                                            <?php echo ucfirst($booking['booking_status']); ?>
                                        </span>
                                    </td>
                                    <td>₹<?php echo number_format($booking['total_fare'], 2); ?></td>
                                    <td>
                                        <a href="booking-details.php?id=<?php echo $booking['booking_id']; ?>" class="btn-small">
                                            View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No bookings yet. <a href="search.php">Book a train now!</a></p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2024 Railway Management System. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>

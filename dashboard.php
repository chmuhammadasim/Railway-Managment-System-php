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

<?php
$pageTitle = 'User Dashboard - Railway Management System';
//require_once 'inc/header.php';
?>

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

<?php require_once 'inc/footer.php'; ?>

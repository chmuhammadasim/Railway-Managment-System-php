<?php
// admin-dashboard.php - Admin Dashboard

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/Booking.php';
require_once 'src/classes/Payment.php';
require_once 'src/classes/Train.php';

// Check if user is logged in and is admin
if (!User::isLoggedIn() || $_SESSION['role'] != 'admin') {
    header('Location: login.php');
    exit();
}

$db = new Database();
$db->connect();

$user_id = $_SESSION['user_id'];
$user_obj = new User($db);
$user = $user_obj->getUserById($user_id);

$booking_obj = new Booking($db);
$payment_obj = new Payment($db);
$train_obj = new Train($db);

$all_bookings = $booking_obj->getAllBookings();
if (!$all_bookings) $all_bookings = array();
$all_payments = $payment_obj->getAllPayments();
if (!$all_payments) $all_payments = array();
$all_trains = $train_obj->getAllTrains();
if (!$all_trains) $all_trains = array();
$all_users = $db->select("SELECT * FROM users WHERE role != 'admin' ORDER BY created_at DESC");
if (!$all_users) $all_users = array();

// Calculate stats
$pending_bookings = $booking_obj->getAllBookings(['booking_status' => 'pending']);
if (!$pending_bookings) $pending_bookings = array();
$pending_payments = $payment_obj->getAllPayments(['payment_status' => 'pending']);
if (!$pending_payments) $pending_payments = array();
?>

<?php
$pageTitle = 'Admin Dashboard - Railway Management System';
$extraScripts = [
    'https://cdn.jsdelivr.net/npm/chart.js',
    'public/js/admin-charts.js'
];
require_once 'inc/header.php';

?>

    <!-- Admin Dashboard Section -->
    <section class="admin-dashboard">
        <div class="container">
            <h2>Admin Dashboard</h2>

                <!-- Analytics Charts -->
                <?php
                // Bookings per month (last 6 months)
                $bookings_per_month = $db->select("SELECT DATE_FORMAT(journey_date, '%Y-%m') as month, COUNT(*) as cnt FROM bookings GROUP BY month ORDER BY month DESC LIMIT 6");
                $bookings_per_month = array_reverse($bookings_per_month);
                $bpm_labels = array_map(fn($row) => $row['month'], $bookings_per_month);
                $bpm_data = array_map(fn($row) => (int)$row['cnt'], $bookings_per_month);

                // Revenue per month (last 6 months)
                $revenue_per_month = $db->select("SELECT DATE_FORMAT(payment_date, '%Y-%m') as month, SUM(amount) as total FROM payments WHERE payment_status='completed' GROUP BY month ORDER BY month DESC LIMIT 6");
                $revenue_per_month = array_reverse($revenue_per_month);
                $rpm_labels = array_map(fn($row) => $row['month'], $revenue_per_month);
                $rpm_data = array_map(fn($row) => (float)$row['total'], $revenue_per_month);
                ?>
                <div class="dashboard-grid">
                    <div class="card">
                        <h3>Bookings Per Month</h3>
                        <canvas id="bookingsPerMonthChart" height="120"></canvas>
                        <script type="application/json" id="bookingsPerMonthData">
                            {"labels":<?=json_encode($bpm_labels)?>,"data":<?=json_encode($bpm_data)?>}
                        </script>
                    </div>
                    <div class="card">
                        <h3>Revenue Per Month</h3>
                        <canvas id="revenuePerMonthChart" height="120"></canvas>
                        <script type="application/json" id="revenuePerMonthData">
                            {"labels":<?=json_encode($rpm_labels)?>,"data":<?=json_encode($rpm_data)?>}
                        </script>
                    </div>
                </div>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card admin-stat">
                    <h3><?php echo count($all_users) ?? 0; ?></h3>
                    <p>Total Users</p>
                    <a href="manage-users.php">View Users →</a>
                </div>
                <div class="stat-card admin-stat">
                    <h3><?php echo count($all_trains) ?? 0; ?></h3>
                    <p>Active Trains</p>
                    <a href="manage-trains.php">Manage Trains →</a>
                </div>
                <div class="stat-card admin-stat">
                    <h3><?php echo count($all_bookings) ?? 0; ?></h3>
                    <p>Total Bookings</p>
                    <a href="manage-bookings.php">View Bookings →</a>
                </div>
                <div class="stat-card admin-stat">
                    <h3><?php echo count($all_payments) ?? 0; ?></h3>
                    <p>Total Payments</p>
                    <a href="manage-payments.php">View Payments →</a>
                </div>
                <div class="stat-card admin-stat" style="border-top: 4px solid #28a745;">
                    <h3><i class="bi bi-bar-chart-line" style="font-size:1.8rem; color:#28a745;"></i></h3>
                    <p>Reports</p>
                    <a href="reports.php">View Reports →</a>
                </div>
            </div>

            <!-- Pending Items -->
            <div class="pending-items">
                <div class="pending-card">
                    <h3>Pending Bookings: <?php echo count($pending_bookings) ?? 0; ?></h3>
                    <p>Bookings waiting for confirmation</p>
                    <a href="manage-bookings.php?status=pending" class="btn-action">Review</a>
                </div>
                <div class="pending-card">
                    <h3>Pending Payments: <?php echo count($pending_payments) ?? 0; ?></h3>
                    <p>Payments waiting for processing</p>
                    <a href="manage-payments.php?status=pending" class="btn-action">Review</a>
                </div>
            </div>

            <!-- Management Sections -->
            <div class="admin-section">
                <h3>Users Overview</h3>
                <?php if ($all_users): ?>
                        <div class="searchable-table-container">
                            <input type="text" class="search-input" placeholder="Search users..." style="margin-bottom:1em;width:100%;padding:0.5em;">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Username</th>
                                        <th>Full Name</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Created At</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_users as $u): ?>
                                        <tr>
                                            <td><?php echo $u['username']; ?></td>
                                            <td><?php echo $u['full_name']; ?></td>
                                            <td><?php echo $u['email']; ?></td>
                                            <td><?php echo ucfirst($u['role']); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                <?php else: ?>
                    <p>No users found</p>
                <?php endif; ?>
            </div>

            <div class="admin-section">
                <h3>Recent Bookings</h3>
                <?php if ($all_bookings): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Booking Ref</th>
                                <th>User</th>
                                <th>Train</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Fare</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($all_bookings, 0, 10) as $booking): ?>
                                <tr>
                                    <td><?php echo $booking['booking_reference']; ?></td>
                                    <td><?php echo $booking['full_name']; ?></td>
                                    <td><?php echo $booking['train_name']; ?></td>
                                    <td><?php echo $booking['journey_date']; ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo str_replace(' ', '-', strtolower($booking['booking_status'])); ?>">
                                            <?php echo ucfirst($booking['booking_status']); ?>
                                        </span>
                                    </td>
                                    <td>₹<?php echo number_format($booking['total_fare'], 2); ?></td>
                                    <td>
                                        <a href="booking-admin.php?id=<?php echo $booking['booking_id']; ?>" class="btn-small">
                                            View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No bookings found</p>
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

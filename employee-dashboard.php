<?php
// employee-dashboard.php - Employee Dashboard

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/Booking.php';
require_once 'src/classes/Train.php';

// Check if user is logged in and is employee
if (!User::isLoggedIn() || $_SESSION['role'] != 'employee') {
    header('Location: login.php');
    exit();
}

$db = new Database();
$db->connect();

$user_id = $_SESSION['user_id'];
$user_obj = new User($db);
$user = $user_obj->getUserById($user_id);

$booking_obj = new Booking($db);
$train_obj = new Train($db);

$all_bookings = $booking_obj->getAllBookings();
if (!$all_bookings) $all_bookings = array();
$all_trains = $train_obj->getAllTrains();
if (!$all_trains) $all_trains = array();

// Get today's routes
$today_routes = $db->select("SELECT r.*, t.train_name, t.train_number 
                            FROM routes r 
                            JOIN trains t ON r.train_id = t.train_id 
                            WHERE DATE(r.journey_date) = CURDATE() 
                            AND r.status = 'scheduled'
                            ORDER BY r.departure_time ASC");
if (!$today_routes) $today_routes = array();

// Get today's bookings
$today_bookings = $db->select("SELECT b.*, u.full_name, r.train_id, t.train_name 
                              FROM bookings b 
                              JOIN users u ON b.user_id = u.user_id 
                              JOIN routes r ON b.route_id = r.route_id 
                              JOIN trains t ON r.train_id = t.train_id 
                              WHERE DATE(r.journey_date) = CURDATE() 
                              AND b.booking_status = 'confirmed'
                              ORDER BY r.departure_time ASC");
if (!$today_bookings) $today_bookings = array();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Dashboard - Railway Management System</title>
    <link rel="stylesheet" href="public/css/style.css">
    <script defer src="public/js/employee-status.js"></script>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar employee-navbar">
        <div class="container">
            <div class="logo">
                <h1>🚂 Railway System - Employee</h1>
            </div>
            <ul class="nav-links">
                <li><a href="index.php">Home</a></li>
                <li><a href="employee-dashboard.php">Dashboard</a></li>
                <li><a href="assign-seats.php">Assign Seats</a></li>
                <li><a href="check-passengers.php">Passengers</a></li>
                <li><a href="my-trains.php">My Trains</a></li>
                <li><a href="logout.php" class="btn-logout">Logout</a></li>
            </ul>
        </div>
    </nav>

    <!-- Employee Dashboard Section -->
    <section class="employee-dashboard">
        <div class="container">
            <h2>Employee Dashboard - Welcome <?php echo $user['full_name']; ?>!</h2>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card emp-stat">
                    <h3><?php echo count($today_routes) ?? 0; ?></h3>
                    <p>Today's Routes</p>
                </div>
                <div class="stat-card emp-stat">
                    <h3><?php echo count($today_bookings) ?? 0; ?></h3>
                    <p>Today's Bookings</p>
                </div>
                <div class="stat-card emp-stat">
                    <h3><?php echo count($all_bookings) ?? 0; ?></h3>
                    <p>Total Bookings</p>
                </div>
                <div class="stat-card emp-stat">
                    <h3><?php echo count($all_trains) ?? 0; ?></h3>
                    <p>Active Trains</p>
                </div>
            </div>

            <!-- Today's Routes -->
            <div class="employee-section">
                <h3>Today's Routes</h3>
                <?php if ($today_routes): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Train</th>
                                <th>Route</th>
                                <th>Departure</th>
                                <th>Arrival</th>
                                <th>Bookings</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($today_routes as $route): ?>
                                <tr>
                                    <td><?php echo $route['train_name']; ?></td>
                                    <td><?php echo $route['departure_city'] . ' → ' . $route['arrival_city']; ?></td>
                                    <td><?php echo $route['departure_time']; ?></td>
                                    <td><?php echo $route['arrival_time']; ?></td>
                                    <td>
                                        <?php 
                                        $route_bookings = array_filter($today_bookings, function($b) use ($route) {
                                            return $b['train_id'] == $route['train_id'];
                                        });
                                        echo count($route_bookings);
                                        ?>
                                    </td>
                                    <td>
                                <th>Status</th>
                                        <a href="route-details-emp.php?id=<?php echo $route['route_id']; ?>" class="btn-small">
                                            View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No routes scheduled for today</p>
                <?php endif; ?>
            </div>

            <!-- Today's Bookings -->
            <div class="employee-section">
                <h3>Today's Confirmed Bookings</h3>
                <?php if ($today_bookings): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Booking Ref</th>
                                <th>Passenger</th>
                                <th>Train</th>
                                <th>Seats</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($today_bookings as $booking): ?>
                                <tr>
                                    <td><?php echo $booking['booking_reference']; ?></td>
                                    <td><?php echo $booking['full_name']; ?></td>
                                    <td><?php echo $booking['train_name']; ?></td>
                                    <td><?php echo $booking['number_of_seats']; ?></td>
                                    <td>
                                        <span class="badge badge-confirmed">
                                            <?php echo ucfirst($booking['booking_status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="booking-emp.php?id=<?php echo $booking['booking_id']; ?>" class="btn-small">
                                            View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No bookings for today</p>
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

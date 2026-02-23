<?php
// book.php - Book Train Ticket

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/Train.php';
require_once 'src/classes/Booking.php';

// Check if user is logged in
if (!User::isLoggedIn()) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php');
    exit();
}

$db = new Database();
$db->connect();

$user_id = $_SESSION['user_id'];
$route_id = $_GET['route_id'] ?? null;

if (!$route_id) {
    header('Location: index.php');
    exit();
}

// Get route details
$route_id_escaped = $db->getConnection()->real_escape_string($route_id);
$route = $db->selectRow("SELECT r.*, t.train_name, t.train_number, t.train_type 
                         FROM routes r 
                         JOIN trains t ON r.train_id = t.train_id 
                         WHERE r.route_id = '{$route_id_escaped}'");

if (!$route) {
    header('Location: index.php');
    exit();
}

$error_message = '';
$success_message = '';

// Handle booking submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $number_of_seats = (int)$_POST['number_of_seats'] ?? 1;
    $passenger_names = $_POST['passenger_name'] ?? [];
    $passenger_ages = $_POST['passenger_age'] ?? [];
    $passenger_genders = $_POST['passenger_gender'] ?? [];
    
    if ($number_of_seats < 1 || $number_of_seats > 10) {
        $error_message = 'Number of seats must be between 1 and 10.';
    } elseif ($number_of_seats > $route['available_seats']) {
        $error_message = 'Not enough seats available!';
    } elseif (count($passenger_names) != $number_of_seats) {
        $error_message = 'Please provide details for all passengers.';
    } else {
        $booking_obj = new Booking($db);
        $total_fare = $route['base_fare'] * $number_of_seats;
        $booking_reference = 'BKG-' . strtoupper(substr(md5(uniqid()), 0, 10));
        
        // Create booking
        $booking_data = [
            'user_id' => $user_id,
            'route_id' => $route_id,
            'booking_reference' => $booking_reference,
            'number_of_seats' => $number_of_seats,
            'total_fare' => $total_fare,
            'booking_status' => 'pending',
            'payment_status' => 'pending',
            'journey_date' => $route['journey_date']
        ];
        
        $booking_id = $db->insert('bookings', $booking_data);
        
        if ($booking_id) {
            // Update available seats
            $new_available_seats = $route['available_seats'] - $number_of_seats;
            $db->query("UPDATE routes SET available_seats = {$new_available_seats} WHERE route_id = {$route_id}");
            
            $success_message = "Booking successful! Your booking reference is: <strong>{$booking_reference}</strong>";
            header("Refresh: 2; url=payment.php?booking_id={$booking_id}");
        } else {
            $error_message = 'Booking failed. Please try again.';
        }
    }
}

$user_obj = new User($db);
$user = $user_obj->getUserById($user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Ticket - Railway Management System</title>
    <link rel="stylesheet" href="public/css/style.css">
    <style>
        .booking-container { max-width: 800px; margin: 2em auto; }
        .route-info { background: #f5f5f5; padding: 1.5em; border-radius: 8px; margin-bottom: 2em; }
        .passenger-form { background: #fff; padding: 1.5em; border-radius: 8px; margin-bottom: 1em; border: 1px solid #ddd; }
        .form-row { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 1em; margin-bottom: 1em; }
        .fare-summary { background: var(--primary-color); color: white; padding: 1.5em; border-radius: 8px; margin-top: 1em; }
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

    <div class="booking-container">
        <div class="card">
            <h2>Book Your Ticket</h2>
            
            <?php if ($error_message): ?>
                <div class="alert alert-danger" style="background:#ffebee;color:#c62828;padding:1em;border-radius:5px;margin-bottom:1em;">
                    <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success_message): ?>
                <div class="alert alert-success" style="background:#e8f5e9;color:#2e7d32;padding:1em;border-radius:5px;margin-bottom:1em;">
                    <?= $success_message ?>
                </div>
            <?php endif; ?>

            <div class="route-info">
                <h3><?= htmlspecialchars($route['train_name']) ?> (<?= htmlspecialchars($route['train_number']) ?>)</h3>
                <p><strong>From:</strong> <?= htmlspecialchars($route['departure_city']) ?> → <strong>To:</strong> <?= htmlspecialchars($route['arrival_city']) ?></p>
                <p><strong>Date:</strong> <?= htmlspecialchars($route['journey_date']) ?></p>
                <p><strong>Departure:</strong> <?= htmlspecialchars($route['departure_time']) ?> | <strong>Arrival:</strong> <?= htmlspecialchars($route['arrival_time']) ?></p>
                <p><strong>Distance:</strong> <?= htmlspecialchars($route['distance_km']) ?> km | <strong>Base Fare:</strong> Rs. <?= number_format($route['base_fare'], 2) ?></p>
                <p><strong>Available Seats:</strong> <?= htmlspecialchars($route['available_seats']) ?></p>
            </div>

            <form method="POST" id="bookingForm">
                <div class="form-group" style="margin-bottom:1.5em;">
                    <label>Number of Seats</label>
                    <input type="number" name="number_of_seats" id="number_of_seats" min="1" max="10" value="1" required class="form-control" style="width:100%;padding:0.5em;">
                </div>

                <div id="passengers-container">
                    <div class="passenger-form">
                        <h4>Passenger 1</h4>
                        <div class="form-row">
                            <div>
                                <label>Name</label>
                                <input type="text" name="passenger_name[]" required class="form-control" style="width:100%;padding:0.5em;">
                            </div>
                            <div>
                                <label>Age</label>
                                <input type="number" name="passenger_age[]" min="1" max="120" required class="form-control" style="width:100%;padding:0.5em;">
                            </div>
                            <div>
                                <label>Gender</label>
                                <select name="passenger_gender[]" required class="form-control" style="width:100%;padding:0.5em;">
                                    <option value="M">Male</option>
                                    <option value="F">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="fare-summary">
                    <h3>Fare Summary</h3>
                    <p><strong>Base Fare per Seat:</strong> Rs. <span id="base-fare"><?= number_format($route['base_fare'], 2) ?></span></p>
                    <p><strong>Number of Seats:</strong> <span id="seat-count">1</span></p>
                    <p style="font-size:1.5em;"><strong>Total Fare:</strong> Rs. <span id="total-fare"><?= number_format($route['base_fare'], 2) ?></span></p>
                </div>

                <button type="submit" class="btn" style="width:100%;margin-top:1em;padding:1em;font-size:1.1em;">Proceed to Payment</button>
            </form>
        </div>
    </div>

    <script>
        const baseFare = <?= $route['base_fare'] ?>;
        const seatsInput = document.getElementById('number_of_seats');
        const passengersContainer = document.getElementById('passengers-container');
        
        seatsInput.addEventListener('change', function() {
            const numSeats = parseInt(this.value) || 1;
            updatePassengerForms(numSeats);
            updateFareSummary(numSeats);
        });
        
        function updatePassengerForms(numSeats) {
            passengersContainer.innerHTML = '';
            for (let i = 0; i < numSeats; i++) {
                const passengerForm = `
                    <div class="passenger-form">
                        <h4>Passenger ${i + 1}</h4>
                        <div class="form-row">
                            <div>
                                <label>Name</label>
                                <input type="text" name="passenger_name[]" required class="form-control" style="width:100%;padding:0.5em;">
                            </div>
                            <div>
                                <label>Age</label>
                                <input type="number" name="passenger_age[]" min="1" max="120" required class="form-control" style="width:100%;padding:0.5em;">
                            </div>
                            <div>
                                <label>Gender</label>
                                <select name="passenger_gender[]" required class="form-control" style="width:100%;padding:0.5em;">
                                    <option value="M">Male</option>
                                    <option value="F">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                    </div>
                `;
                passengersContainer.innerHTML += passengerForm;
            }
        }
        
        function updateFareSummary(numSeats) {
            const totalFare = baseFare * numSeats;
            document.getElementById('seat-count').textContent = numSeats;
            document.getElementById('total-fare').textContent = totalFare.toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }
    </script>
</body>
</html>

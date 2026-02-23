<?php
// payment.php - Payment Page

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/Payment.php';

if (!User::isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$db = new Database();
$db->connect();

$user_id = $_SESSION['user_id'];
$booking_id = $_GET['booking_id'] ?? null;

if (!$booking_id) {
    header('Location: bookings.php');
    exit();
}

$booking_id_escaped = $db->getConnection()->real_escape_string($booking_id);
$user_id_escaped = $db->getConnection()->real_escape_string($user_id);
$booking = $db->selectRow("SELECT b.*, r.departure_city, r.arrival_city, r.journey_date, t.train_name 
                           FROM bookings b 
                           JOIN routes r ON b.route_id = r.route_id 
                           JOIN trains t ON r.train_id = t.train_id 
                           WHERE b.booking_id = '{$booking_id_escaped}' AND b.user_id = '{$user_id_escaped}'");

if (!$booking) {
    header('Location: bookings.php');
    exit();
}

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $payment_method = $_POST['payment_method'] ?? '';
    
    if (empty($payment_method)) {
        $error_message = 'Please select a payment method.';
    } else {
        $payment_obj = new Payment($db);
        $transaction_id = 'TXN-' . strtoupper(substr(md5(uniqid()), 0, 12));
        
        $payment_data = [
            'booking_id' => $booking_id,
            'user_id' => $user_id,
            'amount' => $booking['total_fare'],
            'payment_method' => $payment_method,
            'transaction_id' => $transaction_id,
            'payment_status' => 'completed',
            'payment_date' => date('Y-m-d H:i:s')
        ];
        
        $payment_id = $db->insert('payments', $payment_data);
        
        if ($payment_id) {
            $db->query("UPDATE bookings SET booking_status = 'confirmed', payment_status = 'completed' WHERE booking_id = {$booking_id}");
            $success_message = 'Payment successful! Your booking is confirmed.';
            header('Refresh: 2; url=bookings.php');
        } else {
            $error_message = 'Payment failed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment - Railway Management System</title>
    <link rel="stylesheet" href="public/css/style.css">
    <style>
        .payment-container { max-width: 600px; margin: 2em auto; }
        .payment-method { border: 2px solid #ddd; padding: 1em; margin-bottom: 1em; border-radius: 8px; cursor: pointer; transition: all 0.3s; }
        .payment-method:hover { border-color: var(--primary-color); background: #f5f5f5; }
        .payment-method input[type="radio"] { margin-right: 1em; }
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
                <li><a href="logout.php" class="btn-logout">Logout</a></li>
            </ul>
        </div>
    </nav>

    <div class="payment-container">
        <div class="card">
            <h2>Complete Payment</h2>
            
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

            <div style="background: #f5f5f5; padding: 1.5em; border-radius: 8px; margin-bottom: 1.5em;">
                <h3>Booking Details</h3>
                <p><strong>Booking Ref:</strong> <?= htmlspecialchars($booking['booking_reference']) ?></p>
                <p><strong>Train:</strong> <?= htmlspecialchars($booking['train_name']) ?></p>
                <p><strong>Route:</strong> <?= htmlspecialchars($booking['departure_city']) ?> → <?= htmlspecialchars($booking['arrival_city']) ?></p>
                <p><strong>Journey Date:</strong> <?= htmlspecialchars($booking['journey_date']) ?></p>
                <p><strong>Seats:</strong> <?= htmlspecialchars($booking['number_of_seats']) ?></p>
                <p style="font-size: 1.2em; margin-top: 1em;"><strong>Total Amount:</strong> Rs. <?= number_format($booking['total_fare'], 2) ?></p>
            </div>

            <form method="POST">
                <h3>Select Payment Method</h3>
                
                <label class="payment-method">
                    <input type="radio" name="payment_method" value="credit_card" required>
                    <strong>Credit Card</strong>
                </label>
                
                <label class="payment-method">
                    <input type="radio" name="payment_method" value="debit_card" required>
                    <strong>Debit Card</strong>
                </label>
                
                <label class="payment-method">
                    <input type="radio" name="payment_method" value="easypaisa" required>
                    <strong>EasyPaisa</strong>
                </label>
                
                <label class="payment-method">
                    <input type="radio" name="payment_method" value="jazzcash" required>
                    <strong>JazzCash</strong>
                </label>
                
                <label class="payment-method">
                    <input type="radio" name="payment_method" value="bank_transfer" required>
                    <strong>Bank Transfer</strong>
                </label>
                
                <label class="payment-method">
                    <input type="radio" name="payment_method" value="cash" required>
                    <strong>Cash (Pay at Station)</strong>
                </label>

                <button type="submit" class="btn" style="width: 100%; margin-top: 1em; padding: 1em; font-size: 1.1em;">Complete Payment</button>
            </form>
        </div>
    </div>
</body>
</html>

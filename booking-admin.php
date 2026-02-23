<?php
require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/Booking.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$database = new Database();
$database->connect();
$db = $database->getConnection();
$bookingObj = new Booking($database);

// Get booking ID from URL
if (!isset($_GET['id'])) {
    header('Location: admin-dashboard.php');
    exit();
}

$booking_id = intval($_GET['id']);

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'confirm') {
            $result = $bookingObj->confirmBooking($booking_id);
            $message = $result ? 'Booking confirmed successfully!' : 'Failed to confirm booking.';
            $message_type = $result ? 'success' : 'error';
        } elseif ($action === 'cancel') {
            $result = $bookingObj->cancelBooking($booking_id);
            $message = $result['message'];
            $message_type = $result['success'] ? 'success' : 'error';
        }
        
        // Redirect to refresh data
        header("Location: booking-admin.php?id={$booking_id}&msg=" . urlencode($message) . "&type={$message_type}");
        exit();
    }
}

// Get detailed booking information
$query = "SELECT b.*, 
          u.username, u.full_name, u.email, u.phone,
          r.departure_city, r.arrival_city, r.departure_time, r.arrival_time, r.journey_date,
          t.train_name, t.train_number,
          p.payment_id, p.payment_method, p.transaction_id, p.payment_date
          FROM bookings b 
          JOIN users u ON b.user_id = u.user_id 
          JOIN routes r ON b.route_id = r.route_id 
          JOIN trains t ON r.train_id = t.train_id 
          LEFT JOIN payments p ON b.booking_id = p.booking_id
          WHERE b.booking_id = {$booking_id}";

$booking = $database->selectRow($query);

if (!$booking) {
    header('Location: admin-dashboard.php');
    exit();
}

// Get passenger details
$seats_query = "SELECT bs.*, s.seat_number, s.seat_type
                FROM booking_seats bs 
                JOIN seats s ON bs.seat_id = s.seat_id 
                WHERE bs.booking_id = {$booking_id}";
$passengers = $database->select($seats_query);

if (!$passengers) {
    $passengers = array();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Details - Admin</title>
    <link rel="stylesheet" href="public/css/style.css">
    <style>
        .detail-section {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-md);
            border-left: 4px solid var(--primary-color);
        }
        
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 1rem;
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .detail-label {
            font-size: 0.875rem;
            color: var(--text-light);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .detail-value {
            font-size: 1.125rem;
            color: var(--text-dark);
            font-weight: 500;
        }
        
        .section-title {
            font-size: 1.5rem;
            color: var(--text-dark);
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--light-color);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .section-title::before {
            content: '';
            width: 4px;
            height: 1.5rem;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            border-radius: 4px;
        }
        
        .passengers-table {
            width: 100%;
            margin-top: 1rem;
        }
        
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            flex-wrap: wrap;
        }
        
        .btn-confirm {
            background: linear-gradient(135deg, var(--success-color), #059669);
            color: white;
            padding: 0.875rem 2rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-sm);
        }
        
        .btn-confirm:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        
        .btn-cancel {
            background: linear-gradient(135deg, var(--danger-color), #dc2626);
            color: white;
            padding: 0.875rem 2rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-sm);
        }
        
        .btn-cancel:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        
        .btn-back {
            background: white;
            color: var(--primary-color);
            padding: 0.875rem 2rem;
            border: 2px solid var(--primary-color);
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
        }
        
        .btn-back:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
        }
        
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-weight: 500;
            animation: slideDown 0.3s ease;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid var(--success-color);
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid var(--danger-color);
        }
        
        .journey-timeline {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 2rem;
            background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
            border-radius: 12px;
            margin: 1.5rem 0;
            position: relative;
        }
        
        .journey-timeline::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 15%;
            right: 15%;
            height: 2px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            z-index: 0;
        }
        
        .journey-point {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            z-index: 1;
            background: white;
            padding: 1rem 2rem;
            border-radius: 8px;
            box-shadow: var(--shadow-md);
        }
        
        .journey-city {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .journey-time {
            font-size: 1rem;
            color: var(--text-light);
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @media (max-width: 768px) {
            .journey-timeline {
                flex-direction: column;
                gap: 1rem;
            }
            
            .journey-timeline::before {
                display: none;
            }
            
            .detail-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn-confirm, .btn-cancel, .btn-back {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="container">
            <div class="logo">
                <h1>🚂 PakRail Admin</h1>
            </div>
            <ul class="nav-links">
                <li><a href="admin-dashboard.php">Dashboard</a></li>
                <li><a href="manage-trains.php">Trains</a></li>
                <li><a href="manage-routes.php">Routes</a></li>
                <li><a href="manage-users.php">Users</a></li>
                <li><a href="logout.php" class="btn-logout">Logout</a></li>
            </ul>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <div class="container" style="max-width: 1200px; padding: 2rem 1rem;">
            <?php if (isset($_GET['msg'])): ?>
                <div class="alert alert-<?php echo htmlspecialchars($_GET['type']); ?>">
                    <?php echo htmlspecialchars($_GET['msg']); ?>
                </div>
            <?php endif; ?>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
                <h2 style="font-size: 2rem; color: var(--text-dark);">Booking Details</h2>
                <a href="admin-dashboard.php" class="btn-back">← Back to Dashboard</a>
            </div>

            <!-- Booking Reference -->
            <div class="detail-section">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                    <div>
                        <h3 style="font-size: 1.75rem; color: var(--text-dark); margin-bottom: 0.5rem;">
                            <?php echo $booking['booking_reference']; ?>
                        </h3>
                        <p style="color: var(--text-light);">
                            Booked on <?php echo date('F d, Y \a\t g:i A', strtotime($booking['booking_date'])); ?>
                        </p>
                    </div>
                    <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                        <span class="badge badge-<?php echo str_replace(' ', '-', strtolower($booking['booking_status'])); ?>" 
                              style="font-size: 1rem; padding: 0.5rem 1rem;">
                            <?php echo ucfirst($booking['booking_status']); ?>
                        </span>
                        <span class="badge badge-<?php echo str_replace(' ', '-', strtolower($booking['payment_status'])); ?>" 
                              style="font-size: 1rem; padding: 0.5rem 1rem;">
                            <?php echo ucfirst($booking['payment_status']); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Journey Information -->
            <div class="detail-section">
                <h3 class="section-title">Journey Information</h3>
                
                <div class="journey-timeline">
                    <div class="journey-point">
                        <div class="journey-city"><?php echo $booking['departure_city']; ?></div>
                        <div class="journey-time"><?php echo date('g:i A', strtotime($booking['departure_time'])); ?></div>
                        <div style="font-size: 0.875rem; color: var(--text-light);">Departure</div>
                    </div>
                    <div class="journey-point">
                        <div class="journey-city"><?php echo $booking['arrival_city']; ?></div>
                        <div class="journey-time"><?php echo date('g:i A', strtotime($booking['arrival_time'])); ?></div>
                        <div style="font-size: 0.875rem; color: var(--text-light);">Arrival</div>
                    </div>
                </div>
                
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-label">Train</span>
                        <span class="detail-value"><?php echo $booking['train_name']; ?> (<?php echo $booking['train_number']; ?>)</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Journey Date</span>
                        <span class="detail-value"><?php echo date('F d, Y', strtotime($booking['journey_date'])); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Number of Seats</span>
                        <span class="detail-value"><?php echo $booking['number_of_seats']; ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Total Fare</span>
                        <span class="detail-value" style="color: var(--success-color); font-size: 1.5rem;">
                            ₹<?php echo number_format($booking['total_fare'], 2); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Passenger Information -->
            <div class="detail-section">
                <h3 class="section-title">Passenger Details</h3>
                <div class="detail-grid" style="margin-bottom: 1.5rem;">
                    <div class="detail-item">
                        <span class="detail-label">Booked By</span>
                        <span class="detail-value"><?php echo $booking['full_name']; ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Username</span>
                        <span class="detail-value"><?php echo $booking['username']; ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Email</span>
                        <span class="detail-value"><?php echo $booking['email']; ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Phone</span>
                        <span class="detail-value"><?php echo $booking['phone'] ?: 'N/A'; ?></span>
                    </div>
                </div>

                <?php if (!empty($passengers)): ?>
                    <h4 style="margin-top: 2rem; margin-bottom: 1rem; color: var(--text-dark);">Seat Assignments</h4>
                    <table class="table passengers-table">
                        <thead>
                            <tr>
                                <th>Seat Number</th>
                                <th>Type</th>
                                <th>Passenger Name</th>
                                <th>Age</th>
                                <th>Gender</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($passengers as $passenger): ?>
                                <tr>
                                    <td><strong><?php echo $passenger['seat_number']; ?></strong></td>
                                    <td>
                                        <span class="badge badge-info">
                                            <?php echo ucfirst($passenger['seat_type']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $passenger['passenger_name']; ?></td>
                                    <td><?php echo $passenger['passenger_age'] ?: 'N/A'; ?></td>
                                    <td><?php echo $passenger['passenger_gender'] ? ucfirst($passenger['passenger_gender']) : 'N/A'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Payment Information -->
            <?php if ($booking['payment_id']): ?>
                <div class="detail-section">
                    <h3 class="section-title">Payment Information</h3>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <span class="detail-label">Payment Method</span>
                            <span class="detail-value"><?php echo ucfirst($booking['payment_method']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Transaction ID</span>
                            <span class="detail-value"><?php echo $booking['transaction_id']; ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Payment Date</span>
                            <span class="detail-value"><?php echo date('F d, Y g:i A', strtotime($booking['payment_date'])); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Amount Paid</span>
                            <span class="detail-value" style="color: var(--success-color);">
                                ₹<?php echo number_format($booking['total_fare'], 2); ?>
                            </span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Admin Actions -->
            <div class="detail-section">
                <h3 class="section-title">Admin Actions</h3>
                <div class="action-buttons">
                    <?php if ($booking['booking_status'] === BOOKING_PENDING): ?>
                        <form method="POST" style="margin: 0;" onsubmit="return confirm('Are you sure you want to confirm this booking?');">
                            <input type="hidden" name="action" value="confirm">
                            <button type="submit" class="btn-confirm">✓ Confirm Booking</button>
                        </form>
                    <?php endif; ?>
                    
                    <?php if ($booking['booking_status'] !== BOOKING_CANCELLED): ?>
                        <form method="POST" style="margin: 0;" onsubmit="return confirm('Are you sure you want to cancel this booking? This action will release the seats.');">
                            <input type="hidden" name="action" value="cancel">
                            <button type="submit" class="btn-cancel">✕ Cancel Booking</button>
                        </form>
                    <?php endif; ?>
                    
                    <a href="admin-dashboard.php" class="btn-back">← Back to Dashboard</a>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2024 Railway Management System. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>

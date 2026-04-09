<?php
// booking_update.php - Change or update a booked journey

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/Booking.php';

if (!User::isLoggedIn()) {
    header('Location: login.php');
    exit();
}

function format_time_remaining($hours_remaining) {
    if ($hours_remaining <= 0) {
        return 'Departed';
    }

    $total_minutes = (int)round($hours_remaining * 60);
    $days = intdiv($total_minutes, 1440);
    $hours = intdiv($total_minutes % 1440, 60);
    $minutes = $total_minutes % 60;

    $parts = [];
    if ($days > 0) {
        $parts[] = $days . 'd';
    }
    if ($hours > 0) {
        $parts[] = $hours . 'h';
    }
    if ($minutes > 0 && $days === 0) {
        $parts[] = $minutes . 'm';
    }

    return implode(' ', $parts);
}

$db = new Database();
$db->connect();

$user_id = (int)$_SESSION['user_id'];
$booking_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($booking_id <= 0) {
    header('Location: bookings.php');
    exit();
}

$booking_obj = new Booking($db);
$booking = $booking_obj->getBookingById($booking_id);

if (!$booking || (int)$booking['user_id'] !== $user_id) {
    header('Location: bookings.php');
    exit();
}

if ($booking['booking_status'] === 'cancelled') {
    header('Location: bookings.php');
    exit();
}

$error_message = '';
$success_message = '';
$selected_route_id = (int)($_POST['new_route_id'] ?? 0);

$current_route = $db->selectRow(
    "SELECT r.*, t.train_name, t.train_number, t.total_seats
     FROM routes r
     JOIN trains t ON r.train_id = t.train_id
     WHERE r.route_id = {$booking['route_id']}"
);

$passengers = $db->select(
    "SELECT bs.passenger_name, bs.passenger_age, bs.passenger_gender, s.seat_number, s.seat_type
     FROM booking_seats bs
     JOIN seats s ON bs.seat_id = s.seat_id
     WHERE bs.booking_id = {$booking_id}
     ORDER BY bs.booking_seat_id ASC"
);
if (!$passengers) {
    $passengers = [];
}

$seat_mix = ['economy' => 0, 'premium' => 0, 'luxury' => 0];
foreach ($passengers as $passenger) {
    $seat_type = $passenger['seat_type'] ?? 'economy';
    if (!isset($seat_mix[$seat_type])) {
        $seat_type = 'economy';
    }
    $seat_mix[$seat_type]++;
}

$departure_timestamp = $current_route
    ? strtotime(($current_route['journey_date'] ?? $booking['journey_date']) . ' ' . ($current_route['departure_time'] ?? '00:00:00'))
    : strtotime($booking['journey_date'] . ' 00:00:00');
$hours_until_departure = ($departure_timestamp - time()) / 3600;

if (empty($_SESSION['booking_update_csrf'])) {
    $_SESSION['booking_update_csrf'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['booking_update_csrf'];

$compatible_routes = [];
$route_search_count = 0;

if ($current_route) {
    $dep = $db->getConnection()->real_escape_string($current_route['departure_city']);
    $arr = $db->getConnection()->real_escape_string($current_route['arrival_city']);

    $candidate_routes = $db->select(
        "SELECT r.*, t.train_name, t.train_number, t.total_seats
         FROM routes r
         JOIN trains t ON r.train_id = t.train_id
         WHERE r.departure_city = '{$dep}'
           AND r.arrival_city = '{$arr}'
           AND r.status = 'scheduled'
           AND (
                r.journey_date > CURDATE()
                OR (r.journey_date = CURDATE() AND r.departure_time > CURTIME())
           )
           AND r.route_id != {$booking['route_id']}
         ORDER BY r.journey_date ASC, r.departure_time ASC"
    );

    foreach ($candidate_routes ?: [] as $candidate_route) {
        $route_search_count++;
        $preview = $booking_obj->getJourneyChangePreview($booking_id, (int)$candidate_route['route_id'], $user_id);
        if (!$preview['success']) {
            continue;
        }

        $compatible_routes[] = [
            'route_id' => (int)$candidate_route['route_id'],
            'train_name' => $candidate_route['train_name'],
            'train_number' => $candidate_route['train_number'],
            'journey_date' => $candidate_route['journey_date'],
            'departure_time' => $candidate_route['departure_time'],
            'arrival_time' => $candidate_route['arrival_time'],
            'distance_km' => $candidate_route['distance_km'],
            'available_seats' => (int)$candidate_route['available_seats'],
            'estimated_fare' => $preview['new_fare'],
            'fare_delta' => $preview['fare_delta'],
            'amount_due' => $preview['amount_due'],
            'credit_amount' => $preview['credit_amount'],
            'requires_payment' => $preview['requires_payment'],
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrf_token, $submitted_token)) {
        $error_message = 'Invalid request. Please refresh the page and try again.';
    } elseif ($hours_until_departure < 24) {
        $error_message = 'Journey changes are only allowed up to 24 hours before departure.';
    } else {
        $new_route_id = isset($_POST['new_route_id']) ? (int)$_POST['new_route_id'] : 0;
        if ($new_route_id <= 0) {
            $error_message = 'Please select a new journey option.';
        } else {
            $result = $booking_obj->updateBookingJourney($booking_id, $new_route_id, $user_id);
            if ($result['success']) {
                $success_message = $result['message'];
                $redirect_target = $result['requires_payment']
                    ? 'payment.php?booking_id=' . $booking_id
                    : 'booking_details.php?id=' . $booking_id;
                header('Refresh: 2; url=' . $redirect_target);
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
        .page-wrap { max-width: 940px; margin: 2rem auto; padding: 0 1rem; }
        .current-route-card { background: #e8f0fe; border-left: 4px solid #1a3c6e; border-radius: 10px; padding: 1.2rem 1.5rem; margin-bottom: 1rem; }
        .route-option { border: 2px solid #dee2e6; border-radius: 10px; padding: 1rem 1.2rem; margin-bottom: 0.85rem; cursor: pointer; transition: all 0.2s; background: #fff; }
        .route-option:hover { border-color: #2d6a9f; background: #f7fbff; }
        .route-option input[type=radio] { margin-right: 0.6rem; }
        .route-option.selected { border-color: #1a3c6e; background: #eef5ff; box-shadow: 0 6px 18px rgba(26,60,110,.08); }
        .fare-pill { background: #1a3c6e; color: #fff; padding: 0.25em 0.7em; border-radius: 20px; font-weight: 600; }
        .status-pill { padding: 0.25em 0.7em; border-radius: 20px; font-size: 0.82rem; font-weight: 700; }
        .status-pill.due { background: #fff7ed; color: #c2410c; }
        .status-pill.covered { background: #ecfdf5; color: #047857; }
        .status-pill.credit { background: #f5f3ff; color: #6d28d9; }
        .status-pill.seats { background: #ecfdf5; color: #047857; }
        .deadline-warn { background: #fff3cd; border: 1px solid #ffc107; border-radius: 10px; padding: 0.9rem 1rem; }
        .seat-chip { display: inline-flex; align-items: center; gap: .35rem; background: #fff; border: 1px solid #cbd5e1; border-radius: 999px; padding: .25rem .7rem; font-size: .76rem; font-weight: 700; color: #334155; }
        .mix-chip { display: inline-flex; align-items: center; gap: .35rem; border-radius: 999px; padding: .2rem .65rem; font-size: .74rem; font-weight: 700; }
        .mix-economy { background: #dbeafe; color: #1d4ed8; }
        .mix-premium { background: #dcfce7; color: #15803d; }
        .mix-luxury { background: #ede9fe; color: #6d28d9; }
        .passenger-box { background: #fff; border: 1px solid #dbe3ef; border-radius: 10px; padding: .9rem 1rem; }
        .helper-text { font-size: .82rem; color: #64748b; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php">Railway System</a>
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

        <div class="card shadow-sm border-0">
            <div class="card-header" style="background:#1a3c6e; color:#fff;">
                <h4 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Change Journey</h4>
            </div>
            <div class="card-body p-4">

                <?php if ($error_message): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
                <?php endif; ?>

                <?php if ($success_message): ?>
                    <div class="alert alert-success">
                        <?= htmlspecialchars($success_message) ?><br>
                        <small>
                            <?= strpos($success_message, 'Additional payment') !== false ? 'Redirecting to payment...' : 'Redirecting to your updated ticket...' ?>
                        </small>
                    </div>
                <?php endif; ?>

                <?php if (!$current_route): ?>
                    <div class="alert alert-danger">Current route details could not be loaded for this booking.</div>
                <?php elseif ($hours_until_departure < 24): ?>
                    <div class="alert alert-danger mb-0">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Journey changes are locked.</strong>
                        You can update your booking only until 24 hours before departure.
                    </div>
                <?php else: ?>

                <div class="current-route-card">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                        <div>
                            <h6 class="mb-2" style="color:#1a3c6e;"><i class="bi bi-ticket-perforated me-1"></i>Current Booking</h6>
                            <div><strong>Booking Ref:</strong> <?= htmlspecialchars($booking['booking_reference']) ?></div>
                            <div><strong>Train:</strong> <?= htmlspecialchars($current_route['train_name']) ?> (<?= htmlspecialchars($current_route['train_number']) ?>)</div>
                            <div><strong>Route:</strong> <?= htmlspecialchars($current_route['departure_city']) ?> to <?= htmlspecialchars($current_route['arrival_city']) ?></div>
                            <div><strong>Departure:</strong> <?= date('D, d M Y H:i', $departure_timestamp) ?></div>
                        </div>
                        <div class="text-sm-end">
                            <div><strong>Current Fare:</strong> Rs <?= number_format((float)$booking['total_fare'], 2) ?></div>
                            <div><strong>Seats:</strong> <?= (int)$booking['number_of_seats'] ?></div>
                            <div class="helper-text mt-1">Time remaining: <strong><?= htmlspecialchars(format_time_remaining($hours_until_departure)) ?></strong></div>
                        </div>
                    </div>

                    <?php if (!empty($passengers)): ?>
                    <hr>
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <?php foreach ($seat_mix as $seat_type => $count): ?>
                            <?php if ($count > 0): ?>
                            <span class="mix-chip mix-<?= htmlspecialchars($seat_type) ?>"><?= ucfirst($seat_type) ?> x <?= $count ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <div class="passenger-box">
                        <div class="helper-text mb-2">Your passenger names stay the same and matching seat classes will be reassigned automatically on the new journey.</div>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($passengers as $passenger): ?>
                            <span class="seat-chip">
                                <?= htmlspecialchars($passenger['seat_number']) ?>
                                <span style="color:#94a3b8;">/</span>
                                <?= htmlspecialchars($passenger['passenger_name']) ?>
                                <span style="color:#94a3b8;">(<?= ucfirst($passenger['seat_type']) ?>)</span>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="deadline-warn mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    Journey updates stay available until <strong>24 hours before departure</strong>.
                    Your current booking departs in <strong><?= htmlspecialchars(format_time_remaining($hours_until_departure)) ?></strong>.
                </div>

                <?php if (!empty($compatible_routes)): ?>
                    <h6 class="mb-2">Choose a compatible replacement journey</h6>
                    <p class="helper-text mb-3">Only routes that can preserve your current seat-class mix are shown below.</p>

                    <form method="POST" id="journeyUpdateForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                        <div id="route-list">
                            <?php foreach ($compatible_routes as $route): ?>
                                <?php
                                $is_selected = $selected_route_id === (int)$route['route_id'];
                                $fare_delta = (float)$route['fare_delta'];
                                ?>
                                <label class="route-option d-block <?= $is_selected ? 'selected' : '' ?>" data-route-option>
                                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                        <div>
                                            <input type="radio" name="new_route_id" value="<?= (int)$route['route_id'] ?>" <?= $is_selected ? 'checked' : '' ?> required>
                                            <strong><?= htmlspecialchars($route['train_name']) ?></strong>
                                            <span class="text-muted">(<?= htmlspecialchars($route['train_number']) ?>)</span>
                                        </div>
                                        <div class="d-flex flex-wrap gap-2 justify-content-end">
                                            <span class="fare-pill">Rs <?= number_format((float)$route['estimated_fare'], 2) ?></span>
                                            <span class="status-pill seats"><?= (int)$route['available_seats'] ?> seats free</span>
                                            <?php if ((float)$route['amount_due'] > 0.009): ?>
                                            <span class="status-pill due">Pay extra Rs <?= number_format((float)$route['amount_due'], 2) ?></span>
                                            <?php elseif ((float)$route['credit_amount'] > 0.009): ?>
                                            <span class="status-pill credit">Rs <?= number_format((float)$route['credit_amount'], 2) ?> lower</span>
                                            <?php else: ?>
                                            <span class="status-pill covered">No extra payment</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="mt-2 text-muted" style="padding-left:1.5rem;">
                                        <strong><?= date('D, d M Y', strtotime($route['journey_date'])) ?></strong>
                                        &nbsp;|&nbsp; Departs <?= date('H:i', strtotime($route['departure_time'])) ?>
                                        &nbsp;|&nbsp; Arrives <?= date('H:i', strtotime($route['arrival_time'])) ?>
                                        &nbsp;|&nbsp; <?= number_format((float)$route['distance_km'], 0) ?> km
                                    </div>
                                    <div class="mt-2 helper-text" style="padding-left:1.5rem;">
                                        <?php if ($fare_delta > 0.009): ?>
                                            This route costs Rs <?= number_format($fare_delta, 2) ?> more than your current booking.
                                        <?php elseif ($fare_delta < -0.009): ?>
                                            This route costs Rs <?= number_format(abs($fare_delta), 2) ?> less than your current booking.
                                        <?php else: ?>
                                            This route keeps the same total fare as your current booking.
                                        <?php endif; ?>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div class="mt-3 d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-1"></i>Confirm Journey Change
                            </button>
                            <a href="booking_details.php?id=<?= $booking_id ?>" class="btn btn-outline-secondary">Keep Current Journey</a>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-exclamation-circle me-1"></i>
                        <?php if ($route_search_count > 0): ?>
                            No compatible alternatives were found that can preserve your current seat classes between
                            <strong><?= htmlspecialchars($current_route['departure_city']) ?></strong> and
                            <strong><?= htmlspecialchars($current_route['arrival_city']) ?></strong>.
                        <?php else: ?>
                            No future journeys are currently available for this route.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    (function () {
        var options = document.querySelectorAll('[data-route-option]');
        options.forEach(function (option) {
            option.addEventListener('click', function () {
                options.forEach(function (item) { item.classList.remove('selected'); });
                option.classList.add('selected');
                var radio = option.querySelector('input[type="radio"]');
                if (radio) {
                    radio.checked = true;
                }
            });
        });
    }());
    </script>
</body>
</html>

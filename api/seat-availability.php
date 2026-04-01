<?php
/**
 * api/seat-availability.php
 * Returns live seat inventory for a route as JSON.
 * Used by assign-seats.php and book.php for real-time updates.
 *
 * GET  ?route_id=N           → full seat map
 * GET  ?route_id=N&seat_id=N → single seat status
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/classes/Database.php';
require_once __DIR__ . '/../src/classes/User.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!User::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthenticated']);
    exit();
}

$db = new Database();
$db->connect();
$conn = $db->getConnection();

$route_id = isset($_GET['route_id']) ? (int)$_GET['route_id'] : 0;
$seat_id  = isset($_GET['seat_id'])  ? (int)$_GET['seat_id']  : 0;

if (!$route_id) {
    http_response_code(400);
    echo json_encode(['error' => 'route_id required']);
    exit();
}

// Route summary
$route = $db->selectRow(
    "SELECT r.route_id, r.departure_city, r.arrival_city, r.available_seats,
            r.status, r.journey_date, r.departure_time, r.arrival_time,
            t.train_name, t.total_seats
     FROM routes r
     JOIN trains t ON r.train_id = t.train_id
     WHERE r.route_id = {$route_id}"
);

if (!$route) {
    http_response_code(404);
    echo json_encode(['error' => 'Route not found']);
    exit();
}

// Single-seat query
if ($seat_id) {
    $seat = $db->selectRow(
        "SELECT s.seat_id, s.seat_number, s.seat_type, s.status,
                bs.passenger_name, b.booking_reference
         FROM seats s
         LEFT JOIN booking_seats bs ON s.seat_id = bs.seat_id
         LEFT JOIN bookings b       ON bs.booking_id = b.booking_id
         WHERE s.seat_id = {$seat_id} AND s.route_id = {$route_id}"
    );
    echo json_encode(['seat' => $seat, 'ts' => time()]);
    exit();
}

// Full seat map
$seats = $db->select(
    "SELECT s.seat_id, s.seat_number, s.seat_type, s.status,
            bs.passenger_name, b.booking_reference
     FROM seats s
     LEFT JOIN booking_seats bs ON s.seat_id = bs.seat_id
     LEFT JOIN bookings b       ON bs.booking_id = b.booking_id
     WHERE s.route_id = {$route_id}
     ORDER BY s.seat_type, s.seat_number"
) ?: [];

// Aggregate stats
$stats = ['available' => 0, 'booked' => 0, 'reserved' => 0, 'total' => count($seats)];
$by_class = ['economy' => ['available'=>0,'booked'=>0,'reserved'=>0,'total'=>0],
             'premium' => ['available'=>0,'booked'=>0,'reserved'=>0,'total'=>0],
             'luxury'  => ['available'=>0,'booked'=>0,'reserved'=>0,'total'=>0]];
foreach ($seats as $s) {
    $stats[$s['status']] = ($stats[$s['status']] ?? 0) + 1;
    $by_class[$s['seat_type']]['total']++;
    $by_class[$s['seat_type']][$s['status']] = ($by_class[$s['seat_type']][$s['status']] ?? 0) + 1;
}

echo json_encode([
    'route'    => $route,
    'seats'    => $seats,
    'stats'    => $stats,
    'by_class' => $by_class,
    'ts'       => time(),
]);

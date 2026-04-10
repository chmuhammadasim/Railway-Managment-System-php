<?php
require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/Booking.php';

$db = new Database();
$db->connect();
$conn = $db->getConnection();

// Check booking 27 (or the last few bookings)
echo "=== BOOKINGS (last 5) ===\n";
$res = $conn->query("SELECT b.booking_id, b.user_id, b.booking_reference, b.booking_status, b.payment_status, b.total_fare, b.journey_date, r.departure_time, r.departure_city, r.arrival_city FROM bookings b JOIN routes r ON b.route_id = r.route_id ORDER BY b.booking_id DESC LIMIT 5");
while ($row = $res->fetch_assoc()) {
    $dept_ts = strtotime($row['journey_date'] . ' ' . $row['departure_time']);
    $hours = ($dept_ts - time()) / 3600;
    echo "  ID={$row['booking_id']} Ref={$row['booking_reference']} Status={$row['booking_status']} Pay={$row['payment_status']}\n";
    echo "    Route: {$row['departure_city']}→{$row['arrival_city']} on {$row['journey_date']} at {$row['departure_time']}\n";
    echo "    Hours until departure: " . round($hours, 1) . "\n";
}

echo "\n=== DIRECT CANCEL TEST on booking 27 (if exists) ===\n";
$bo = new Booking($db);
$b27 = $bo->getBookingById(27);
if (!$b27) {
    echo "Booking 27 not found. Trying most recent non-cancelled booking...\n";
    $b27row = $conn->query("SELECT booking_id FROM bookings WHERE booking_status != 'cancelled' ORDER BY booking_id DESC LIMIT 1");
    if ($b27row && $b27row->num_rows > 0) {
        $b27r = $b27row->fetch_assoc();
        $b27 = $bo->getBookingById($b27r['booking_id']);
    }
}

if ($b27) {
    $bid = $b27['booking_id'];
    echo "Testing booking ID=$bid, status={$b27['booking_status']}, payment={$b27['payment_status']}\n";
    $preview = $bo->getRefundPreview($bid, $b27['user_id']);
    echo "Preview allowed=" . ($preview['allowed'] ? 'YES' : 'NO') . " msg={$preview['message']}\n";
    if ($preview['allowed']) {
        echo "Simulating cancel (will rollback)...\n";
        $conn->begin_transaction();
        $result = $bo->requestCancellation($bid, $b27['user_id'], 'test cancel');
        echo "Result: success=" . ($result['success'] ? 'YES' : 'NO') . " msg={$result['message']}\n";
        if ($result['success']) {
            $after = $bo->getBookingById($bid);
            echo "Status AFTER cancel (before rollback): " . $after['booking_status'] . "\n";
        }
        $conn->rollback();
        $after2 = $bo->getBookingById($bid);
        echo "Status AFTER rollback: " . $after2['booking_status'] . "\n";
    }
} else {
    echo "No bookings found to test\n";
}

echo "\n=== PHP timezone / time info ===\n";
echo "PHP date.timezone = " . ini_get('date.timezone') . "\n";
echo "PHP time() = " . time() . "\n";
echo "MySQL NOW() = ";
$r = $conn->query("SELECT NOW() as n, CURDATE() as d"); $rr = $r->fetch_assoc();
echo $rr['n'] . "  CURDATE=" . $rr['d'] . "\n";

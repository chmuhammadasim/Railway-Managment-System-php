<?php
// Diagnostic: check DB columns and simulate cancel flow
require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/Booking.php';

$db = new Database();
$db->connect();
$conn = $db->getConnection();

echo "=== COLUMN CHECK ===\n";
$res = $conn->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'railway_system' AND TABLE_NAME = 'bookings' ORDER BY ORDINAL_POSITION");
$cols = [];
while ($row = $res->fetch_assoc()) { $cols[] = $row['COLUMN_NAME']; echo "  " . $row['COLUMN_NAME'] . "\n"; }

$needed = ['cancellation_reason','cancellation_fee','refund_amount','cancelled_at'];
foreach ($needed as $n) {
    echo ($in_array_check = in_array($n, $cols)) ? "  [OK] $n exists\n" : "  [MISSING] $n\n";
}

echo "\n=== FIRST BOOKING TEST ===\n";
$booking = $db->selectRow("SELECT * FROM bookings WHERE booking_status != 'cancelled' LIMIT 1");
if (!$booking) { echo "No active booking found\n"; exit(); }
echo "Booking ID: {$booking['booking_id']}, Status: {$booking['booking_status']}, Payment: {$booking['payment_status']}\n";

echo "\n=== SIMULATE CANCEL (NO TRANSACTION) ===\n";
$bid = (int)$booking['booking_id'];

// Test step 1
$r1 = $conn->query("UPDATE bookings SET booking_status = 'cancelled', payment_status = 'pending' WHERE booking_id = $bid");
echo "Step1 (status update): " . ($r1 ? "OK, rows=" . $conn->affected_rows : "FAIL: " . $conn->error) . "\n";

// Revert
$conn->query("UPDATE bookings SET booking_status = '{$booking['booking_status']}', payment_status = '{$booking['payment_status']}' WHERE booking_id = $bid");
echo "Reverted to original\n";

// Test step 2
$r2 = $conn->query("UPDATE bookings SET cancellation_reason = 'test', cancellation_fee = 0, refund_amount = 0, cancelled_at = NOW() WHERE booking_id = $bid");
echo "Step2 (cancel detail): " . ($r2 ? "OK, rows=" . $conn->affected_rows : "FAIL: " . $conn->error) . "\n";
// Revert step 2
$conn->query("UPDATE bookings SET cancellation_reason = NULL, cancellation_fee = 0, refund_amount = 0, cancelled_at = NULL WHERE booking_id = $bid");

echo "\n=== TRANSACTION TEST ===\n";
$conn->begin_transaction();
$r1 = $conn->query("UPDATE bookings SET booking_status = 'cancelled', payment_status = 'pending' WHERE booking_id = $bid");
echo "Tx Step1: " . ($r1 ? "OK" : "FAIL: " . $conn->error) . "\n";
$r2 = $conn->query("UPDATE bookings SET cancellation_reason = 'tx_test', cancellation_fee = 0, refund_amount = 0, cancelled_at = NOW() WHERE booking_id = $bid");
echo "Tx Step2: " . ($r2 ? "OK" : "FAIL: " . $conn->error) . "\n";
$conn->rollback();
echo "Rolled back Tx test\n";

echo "\n=== REFUND PREVIEW TEST ===\n";
$bo = new Booking($db);
$preview = $bo->getRefundPreview($booking['booking_id']);
echo "Allowed: " . ($preview['allowed'] ? 'YES' : 'NO: ' . $preview['message']) . "\n";
if ($preview['allowed']) {
    echo "Tier: " . $preview['tier_label'] . "\n";
    echo "Refund: " . $preview['refund_amount'] . "  Fee: " . $preview['cancel_fee'] . "\n";
    echo "Is unpaid: " . ($preview['is_unpaid'] ? 'YES' : 'NO') . "\n";
}

echo "\nDone.\n";

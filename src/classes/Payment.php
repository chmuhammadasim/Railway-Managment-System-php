<?php
// Payment Class

class Payment {
    private $db;

    public function __construct($database) {
        $this->db = $database;
    }

    // ── Notification helpers ───────────────────────────────────────────────

    private function pushNotif(int $user_id, string $message, string $type = 'info', int $related_id = 0): void {
        $conn = $this->db->getConnection();
        $msg  = $conn->real_escape_string($message);
        $t    = $conn->real_escape_string($type);
        $conn->query(
            "INSERT INTO notifications (user_id, message, type, related_id, is_read)
             VALUES ({$user_id}, '{$msg}', '{$t}', {$related_id}, 0)"
        );
    }

    private function pushNotifToStaff(string $message, string $type = 'info', int $related_id = 0): void {
        $staff = $this->db->select("SELECT user_id FROM users WHERE role = 'admin' AND is_active = 1");
        foreach ($staff ?: [] as $s) {
            $this->pushNotif((int)$s['user_id'], $message, $type, $related_id);
        }
    }

    private function pushNotifToAdmins(string $message, string $type = 'info', int $related_id = 0): void {
        $admins = $this->db->select("SELECT user_id FROM users WHERE role = 'admin' AND is_active = 1");
        foreach ($admins ?: [] as $a) {
            $this->pushNotif((int)$a['user_id'], $message, $type, $related_id);
        }
    }

    // ──────────────────────────────────────────────────────────────────────

    // Process payment
    public function processPayment($booking_id, $user_id, $amount, $payment_method, $transaction_id = '') {
        $booking = $this->db->selectRow("SELECT * FROM bookings WHERE booking_id = {$booking_id}");
        
        if (!$booking) {
            return array('success' => false, 'message' => 'Booking not found!');
        }

        // Insert payment record
        $payment_data = array(
            'booking_id' => $booking_id,
            'user_id' => $user_id,
            'amount' => $amount,
            'payment_method' => $payment_method,
            'transaction_id' => $transaction_id,
            'payment_status' => PAYMENT_COMPLETED,
            'payment_date' => date('Y-m-d H:i:s')
        );

        $payment_id = $this->db->insert('payments', $payment_data);

        if ($payment_id) {
            // Update booking payment status
            $this->db->update('bookings', 'booking_id', $booking_id, 
                            array('payment_status' => PAYMENT_COMPLETED, 
                                  'booking_status' => BOOKING_CONFIRMED));

            // ── Notifications ─────────────────────────────────────────────
            $route = $this->db->selectRow(
                "SELECT r.departure_city, r.arrival_city, r.journey_date, b.booking_reference, b.number_of_seats
                 FROM bookings b JOIN routes r ON b.route_id = r.route_id
                 WHERE b.booking_id = {$booking_id}"
            );
            if ($route) {
                $bref  = $route['booking_reference'];
                $dep   = $route['departure_city'];
                $arr   = $route['arrival_city'];
                $jdt   = date('d M Y', strtotime($route['journey_date']));
                $amtf  = number_format((float)$amount, 2);
                $pmeth = ucfirst($payment_method);

                $this->pushNotif((int)$user_id,
                    "Payment of Rs {$amtf} received via {$pmeth} for booking {$bref}. Your booking {$dep} → {$arr} on {$jdt} is now CONFIRMED!",
                    'payment', $booking_id
                );
                $user_row = $this->db->selectRow("SELECT full_name FROM users WHERE user_id = {$user_id}");
                $uname    = $user_row['full_name'] ?? "User #{$user_id}";
                $this->pushNotifToAdmins(
                    "Payment Rs {$amtf} via {$pmeth} received · Booking {$bref} by {$uname} · {$dep} → {$arr} · {$jdt} — Confirmed",
                    'payment', $booking_id
                );
            }
            // ─────────────────────────────────────────────────────────────

            return array('success' => true, 'message' => 'Payment processed successfully!', 'payment_id' => $payment_id);
        } else {
            return array('success' => false, 'message' => 'Failed to process payment!');
        }
    }

    // Get payment by ID
    public function getPaymentById($payment_id) {
        $query = "SELECT * FROM payments WHERE payment_id = {$payment_id}";
        return $this->db->selectRow($query);
    }

    // Get booking payment
    public function getBookingPayment($booking_id) {
        $query = "SELECT * FROM payments WHERE booking_id = {$booking_id}";
        return $this->db->selectRow($query);
    }

    // Process refund
    public function processRefund($payment_id, $reason = '') {
        $payment = $this->getPaymentById($payment_id);
        
        if (!$payment) {
            return array('success' => false, 'message' => 'Payment not found!');
        }

        $refund_data = array(
            'payment_status' => PAYMENT_REFUNDED,
            'refund_date' => date('Y-m-d H:i:s'),
            'refund_reason' => $reason
        );

        if ($this->db->update('payments', 'payment_id', $payment_id, $refund_data)) {
            // Update booking status
            $this->db->update('bookings', 'booking_id', $payment['booking_id'], 
                            array('payment_status' => PAYMENT_REFUNDED));

            // ── Notifications ─────────────────────────────────────────────
            $bid   = (int)$payment['booking_id'];
            $route = $this->db->selectRow(
                "SELECT r.departure_city, r.arrival_city, r.journey_date, b.booking_reference, b.user_id
                 FROM bookings b JOIN routes r ON b.route_id = r.route_id
                 WHERE b.booking_id = {$bid}"
            );
            if ($route) {
                $bref = $route['booking_reference'];
                $dep  = $route['departure_city'];
                $arr  = $route['arrival_city'];
                $amtf = number_format((float)$payment['amount'], 2);
                $this->pushNotif((int)$route['user_id'],
                    "Refund of Rs {$amtf} processed for booking {$bref} ({$dep} → {$arr}). Allow 3-5 business days to reflect in your account.",
                    'refund', $bid
                );
                $this->pushNotifToAdmins(
                    "Refund Rs {$amtf} issued · Booking {$bref} · {$dep} → {$arr}" .
                    ($reason ? " · Reason: {$reason}" : ''),
                    'refund', $bid
                );
            }
            // ─────────────────────────────────────────────────────────────

            return array('success' => true, 'message' => 'Refund processed successfully!');
        } else {
            return array('success' => false, 'message' => 'Failed to process refund!');
        }
    }

    // Get all payments (Admin)
    public function getAllPayments($filter = array()) {
        $query = "SELECT p.*, u.username, u.full_name, b.booking_reference 
                  FROM payments p 
                  JOIN users u ON p.user_id = u.user_id 
                  JOIN bookings b ON p.booking_id = b.booking_id 
                  WHERE 1=1";

        if (isset($filter['payment_status'])) {
            $query .= " AND p.payment_status = '{$filter['payment_status']}'";
        }

        $query .= " ORDER BY p.created_at DESC";
        
        return $this->db->select($query);
    }

    // Get user payments
    public function getUserPayments($user_id) {
        $query = "SELECT p.*, b.booking_reference, r.departure_city, r.arrival_city 
                  FROM payments p 
                  JOIN bookings b ON p.booking_id = b.booking_id 
                  JOIN routes r ON b.route_id = r.route_id 
                  WHERE p.user_id = {$user_id} 
                  ORDER BY p.created_at DESC";
        return $this->db->select($query);
    }
}
?>

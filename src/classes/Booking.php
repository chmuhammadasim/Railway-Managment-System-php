<?php
// Booking Class

class Booking {
    private const FARE_MULTIPLIERS = [
        'economy' => 1.0,
        'premium' => 1.5,
        'luxury' => 2.5,
    ];

    private $db;

    public function __construct($database) {
        $this->db = $database;
    }

    // ── Notification helpers ───────────────────────────────────────────────

    /** Insert one notification row (safe – ignores failure). */
    private function pushNotif(int $user_id, string $message, string $type = 'info', int $related_id = 0): void {
        $conn = $this->db->getConnection();
        $msg  = $conn->real_escape_string($message);
        $t    = $conn->real_escape_string($type);
        $conn->query(
            "INSERT INTO notifications (user_id, message, type, related_id, is_read)
             VALUES ({$user_id}, '{$msg}', '{$t}', {$related_id}, 0)"
        );
    }

    /** Notify all admin users. */
    private function pushNotifToAdmins(string $message, string $type = 'info', int $related_id = 0): void {
        $admins = $this->db->select("SELECT user_id FROM users WHERE role = 'admin' AND is_active = 1");
        foreach ($admins ?: [] as $a) {
            $this->pushNotif((int)$a['user_id'], $message, $type, $related_id);
        }
    }

    /** Notify all staff (admins). */
    private function pushNotifToStaff(string $message, string $type = 'info', int $related_id = 0): void {
        $staff = $this->db->select("SELECT user_id FROM users WHERE role = 'admin' AND is_active = 1");
        foreach ($staff ?: [] as $s) {
            $this->pushNotif((int)$s['user_id'], $message, $type, $related_id);
        }
    }

    // ──────────────────────────────────────────────────────────────────────

    // Create booking reference
    public function generateBookingReference() {
        return 'RWY' . date('YmdHis') . rand(1000, 9999);
    }

    // Create new booking
    public function createBooking($user_id, $route_id, $seats_data) {
        $route_id = (int)$route_id;
        $user_id = (int)$user_id;
        $route = $this->db->selectRow("SELECT * FROM routes WHERE route_id = {$route_id}");

        if (!$route) {
            return array('success' => false, 'message' => 'Route not found!');
        }

        $num_seats = count($seats_data);
        if ($num_seats < 1) {
            return array('success' => false, 'message' => 'Select at least one seat to continue.');
        }

        $total_fare = $route['base_fare'] * $num_seats;
        $booking_ref = $this->generateBookingReference();
        $conn = $this->db->getConnection();

        $conn->begin_transaction();

        try {
            $route_row = $this->db->selectRow(
                "SELECT available_seats FROM routes WHERE route_id = {$route_id} FOR UPDATE"
            );
            $route_available = (int)($route_row['available_seats'] ?? 0);
            $route_available = $this->syncRouteAvailableSeats($route_id) ?: $route_available;
            if ($route_available < $num_seats) {
                throw new \RuntimeException('Not enough seats are available on this route.');
            }

            $booking_data = array(
                'user_id' => $user_id,
                'route_id' => $route_id,
                'booking_reference' => $booking_ref,
                'number_of_seats' => $num_seats,
                'total_fare' => $total_fare,
                'journey_date' => $route['journey_date'],
                'booking_status' => BOOKING_PENDING,
                'payment_status' => PAYMENT_PENDING
            );

            $booking_id = $this->db->insert('bookings', $booking_data);
            if (!$booking_id) {
                throw new \RuntimeException('Failed to create booking!');
            }

            foreach ($seats_data as $seat) {
                $seat_id = (int)($seat['seat_id'] ?? 0);
                if ($seat_id <= 0) {
                    throw new \RuntimeException('Invalid seat selected.');
                }

                if (!$conn->query(
                    "UPDATE seats SET status = 'booked' WHERE seat_id = {$seat_id} AND route_id = {$route_id} AND status = 'available'"
                )) {
                    throw new \RuntimeException('Unable to reserve the selected seat.');
                }

                if ((int)$conn->affected_rows !== 1) {
                    throw new \RuntimeException('One or more selected seats are no longer available.');
                }

                $seat_data = array(
                    'booking_id' => $booking_id,
                    'seat_id' => $seat_id,
                    'passenger_name' => trim((string)($seat['passenger_name'] ?? 'Passenger')),
                    'passenger_age' => isset($seat['passenger_age']) ? $seat['passenger_age'] : NULL,
                    'passenger_gender' => isset($seat['passenger_gender']) ? $seat['passenger_gender'] : NULL
                );

                if (!$this->db->insert('booking_seats', $seat_data)) {
                    throw new \RuntimeException('Failed to attach passenger details to the booking.');
                }
            }

            if (!$conn->query(
                "UPDATE routes SET available_seats = " . max($route_available - $num_seats, 0) . " WHERE route_id = {$route_id}"
            )) {
                throw new \RuntimeException('Failed to update route availability.');
            }

            $this->syncRouteAvailableSeats($route_id);

            $conn->commit();
        } catch (\Throwable $throwable) {
            $conn->rollback();
            return array('success' => false, 'message' => $throwable->getMessage());
        }

        // ── Notifications ─────────────────────────────────────────────────
        $dep  = htmlspecialchars_decode($route['departure_city'] ?? '');
        $arr  = htmlspecialchars_decode($route['arrival_city']   ?? '');
        $jdt  = isset($route['journey_date']) ? date('d M Y', strtotime($route['journey_date'])) : '';
        $fare = number_format((float)$total_fare, 2);

        $this->pushNotif(
            $user_id,
            "Booking #{$booking_ref} placed! {$num_seats} seat(s) · {$dep} → {$arr} · {$jdt} · Rs {$fare}. Complete payment to confirm.",
            'booking', $booking_id
        );
        $user_row = $this->db->selectRow("SELECT full_name FROM users WHERE user_id = {$user_id}");
        $uname    = $user_row['full_name'] ?? "User #{$user_id}";
        $this->pushNotifToAdmins(
            "New booking #{$booking_ref} by {$uname} · {$dep} → {$arr} · {$jdt} · {$num_seats} seat(s) · Rs {$fare}",
            'booking', $booking_id
        );
        // ─────────────────────────────────────────────────────────────────

        return array('success' => true, 'message' => 'Booking created!', 'booking_id' => $booking_id);
    }

    // Get booking by ID
    public function getBookingById($booking_id) {
        $query = "SELECT * FROM bookings WHERE booking_id = {$booking_id}";
        return $this->db->selectRow($query);
    }

    // Get user bookings
    public function getUserBookings($user_id) {
        $query = "SELECT b.*, r.departure_city, r.arrival_city, r.departure_time, r.journey_date, t.train_name 
                  FROM bookings b 
                  JOIN routes r ON b.route_id = r.route_id 
                  JOIN trains t ON r.train_id = t.train_id 
                  WHERE b.user_id = {$user_id} 
                  ORDER BY b.booking_date DESC";
        return $this->db->select($query);
    }

    // Get booking details with seats
    public function getBookingDetails($booking_id) {
        $booking = $this->getBookingById($booking_id);
        
        if ($booking) {
            $query = "SELECT bs.*, s.seat_number, s.seat_type 
                      FROM booking_seats bs 
                      JOIN seats s ON bs.seat_id = s.seat_id 
                      WHERE bs.booking_id = {$booking_id}";
            $booking['seats'] = $this->db->select($query);
            
            return $booking;
        }
        
        return false;
    }

    private function getRouteContext($route_id) {
        $route_id = (int)$route_id;

        return $this->db->selectRow(
            "SELECT r.*, t.train_id, t.train_name, t.train_number, t.total_seats
             FROM routes r
             JOIN trains t ON r.train_id = t.train_id
             WHERE r.route_id = {$route_id}"
        );
    }

    private function getRouteDepartureTimestamp(array $route) {
        $journey_date = $route['journey_date'] ?? date('Y-m-d');
        $departure_time = $route['departure_time'] ?? '00:00:00';

        return strtotime($journey_date . ' ' . $departure_time);
    }

    private function getBookingPassengerManifest($booking_id) {
        $booking_id = (int)$booking_id;
        $passengers = $this->db->select(
            "SELECT bs.booking_seat_id, bs.seat_id, bs.passenger_name, bs.passenger_age,
                    bs.passenger_gender, s.seat_number, s.seat_type
             FROM booking_seats bs
             JOIN seats s ON bs.seat_id = s.seat_id
             WHERE bs.booking_id = {$booking_id}
             ORDER BY bs.booking_seat_id ASC"
        );

        return $passengers ?: [];
    }

    private function getSeatMix(array $passengers) {
        $counts = ['economy' => 0, 'premium' => 0, 'luxury' => 0];
        $multiplier_total = 0.0;

        foreach ($passengers as $passenger) {
            $seat_type = $passenger['seat_type'] ?? 'economy';
            if (!isset($counts[$seat_type])) {
                $seat_type = 'economy';
            }

            $counts[$seat_type]++;
            $multiplier_total += self::FARE_MULTIPLIERS[$seat_type];
        }

        return [
            'counts' => $counts,
            'multiplier_total' => $multiplier_total,
        ];
    }

    private function getCompletedPaymentsTotal($booking_id) {
        $booking_id = (int)$booking_id;
        $row = $this->db->selectRow(
            "SELECT COALESCE(SUM(amount), 0) AS total
             FROM payments
             WHERE booking_id = {$booking_id} AND payment_status = 'completed'"
        );

        return round((float)($row['total'] ?? 0), 2);
    }

    private function ensureRouteSeatsExist(array $route) {
        $route_id = (int)($route['route_id'] ?? 0);
        if ($route_id <= 0) {
            return;
        }

        $count_row = $this->db->selectRow("SELECT COUNT(*) AS cnt FROM seats WHERE route_id = {$route_id}");
        if ((int)($count_row['cnt'] ?? 0) > 0) {
            return;
        }

        $train = new Train($this->db);
        $train->createSeats(
            (int)$route['train_id'],
            $route_id,
            (int)$route['total_seats']
        );
    }

    private function syncRouteAvailableSeats($route_id) {
        $route_id = (int)$route_id;
        if ($route_id <= 0) {
            return 0;
        }

        $seat_count_row = $this->db->selectRow(
            "SELECT COUNT(*) AS cnt FROM seats WHERE route_id = {$route_id}"
        );
        $seat_count = (int)($seat_count_row['cnt'] ?? 0);
        if ($seat_count <= 0) {
            $route = $this->db->selectRow("SELECT available_seats FROM routes WHERE route_id = {$route_id}");
            return (int)($route['available_seats'] ?? 0);
        }

        $available_row = $this->db->selectRow(
            "SELECT COUNT(*) AS cnt FROM seats WHERE route_id = {$route_id} AND status = 'available'"
        );
        $available_count = (int)($available_row['cnt'] ?? 0);
        $this->db->query(
            "UPDATE routes SET available_seats = {$available_count} WHERE route_id = {$route_id}"
        );

        return $available_count;
    }

    private function buildSeatAllocationMessage(array $required_counts, array $available_counts) {
        $parts = [];

        foreach ($required_counts as $seat_type => $required) {
            $available = $available_counts[$seat_type] ?? 0;
            if ($required > $available) {
                $parts[] = ucfirst($seat_type) . ' ' . $available . '/' . $required;
            }
        }

        if (empty($parts)) {
            return 'Selected route does not have enough seats to preserve your current seat mix.';
        }

        return 'Selected route cannot preserve your current seat classes. Available: ' . implode(', ', $parts) . '.';
    }

    private function selectReplacementSeats($route_id, array $passengers) {
        $route_id = (int)$route_id;
        $available_seats = $this->db->select(
            "SELECT seat_id, seat_type, seat_number
             FROM seats
             WHERE route_id = {$route_id} AND status = 'available'
             ORDER BY seat_type ASC, seat_number ASC"
        );

        $available_by_type = ['economy' => [], 'premium' => [], 'luxury' => []];
        foreach ($available_seats ?: [] as $seat) {
            $seat_type = $seat['seat_type'] ?? 'economy';
            if (!isset($available_by_type[$seat_type])) {
                $seat_type = 'economy';
            }
            $available_by_type[$seat_type][] = $seat;
        }

        $required_counts = $this->getSeatMix($passengers)['counts'];
        $available_counts = [
            'economy' => count($available_by_type['economy']),
            'premium' => count($available_by_type['premium']),
            'luxury' => count($available_by_type['luxury']),
        ];

        foreach ($required_counts as $seat_type => $required) {
            if ($required > ($available_counts[$seat_type] ?? 0)) {
                return [
                    'success' => false,
                    'message' => $this->buildSeatAllocationMessage($required_counts, $available_counts),
                ];
            }
        }

        $assignments = [];
        foreach ($passengers as $passenger) {
            $seat_type = $passenger['seat_type'] ?? 'economy';
            if (!isset($available_by_type[$seat_type])) {
                $seat_type = 'economy';
            }

            $seat = array_shift($available_by_type[$seat_type]);
            if (!$seat) {
                return [
                    'success' => false,
                    'message' => 'Unable to assign matching seats on the selected route. Please try another option.',
                ];
            }

            $assignments[] = [
                'booking_seat_id' => (int)$passenger['booking_seat_id'],
                'seat_id' => (int)$seat['seat_id'],
                'seat_number' => $seat['seat_number'],
                'seat_type' => $seat['seat_type'],
            ];
        }

        return [
            'success' => true,
            'assignments' => $assignments,
            'available_counts' => $available_counts,
            'required_counts' => $required_counts,
        ];
    }

    private function triggerWaitlistProcessing($route_id) {
        $route_id = (int)$route_id;
        if ($route_id <= 0 || !file_exists(__DIR__ . '/Operations.php')) {
            return;
        }

        require_once __DIR__ . '/Operations.php';
        if (!class_exists('Operations')) {
            return;
        }

        try {
            $operations = new Operations($this->db);
            $operations->ensureSchema();
            $operations->processWaitlist($route_id);
        } catch (\Throwable $throwable) {
            return;
        }
    }

    // Cancel booking immediately (Admin use or internal)
    public function cancelBooking($booking_id, $reason = '') {
        $booking = $this->getBookingById($booking_id);

        if (!$booking) {
            return array('success' => false, 'message' => 'Booking not found!');
        }

        if ($booking['booking_status'] === BOOKING_CANCELLED) {
            return array('success' => false, 'message' => 'Booking is already cancelled.');
        }

        $conn = $this->db->getConnection();
        $reason_text = trim($reason) !== '' ? trim($reason) : 'Admin cancellation';
        $reason_sql = $conn->real_escape_string($reason_text);

        $conn->begin_transaction();

        try {
            if (!$conn->query(
                "UPDATE bookings SET
                    booking_status = '" . BOOKING_CANCELLED . "',
                    cancellation_reason = '{$reason_sql}',
                    cancellation_fee = 0,
                    refund_amount = 0,
                    cancelled_at = NOW()
                 WHERE booking_id = " . (int)$booking_id
            )) {
                throw new \RuntimeException('Failed to cancel booking.');
            }

            $query = "SELECT seat_id FROM booking_seats WHERE booking_id = {$booking_id}";
            $seats = $this->db->select($query);

            if ($seats) {
                foreach ($seats as $seat) {
                    if (!$this->db->update('seats', 'seat_id', $seat['seat_id'], array('status' => 'available'))) {
                        throw new \RuntimeException('Failed to release booked seats.');
                    }
                }
            }

            if (!$conn->query(
                "UPDATE routes SET available_seats = available_seats + {$booking['number_of_seats']}
                 WHERE route_id = {$booking['route_id']}"
            )) {
                throw new \RuntimeException('Failed to restore route availability.');
            }

            $this->syncRouteAvailableSeats((int)$booking['route_id']);

            $conn->commit();
        } catch (\Throwable $throwable) {
            $conn->rollback();
            return array('success' => false, 'message' => $throwable->getMessage());
        }

        $this->triggerWaitlistProcessing((int)$booking['route_id']);

        return array('success' => true, 'message' => 'Booking cancelled successfully!');
    }

    public function getJourneyChangePreview($booking_id, $new_route_id, $user_id = null) {
        $booking = $this->getBookingById($booking_id);

        if (!$booking) {
            return array('success' => false, 'message' => 'Booking not found.');
        }

        if ($user_id !== null && (int)$booking['user_id'] !== (int)$user_id) {
            return array('success' => false, 'message' => 'Unauthorised.');
        }

        if ($booking['booking_status'] === BOOKING_CANCELLED) {
            return array('success' => false, 'message' => 'Cancelled bookings cannot be changed.');
        }

        $current_route = $this->getRouteContext($booking['route_id']);
        if (!$current_route) {
            return array('success' => false, 'message' => 'Current route could not be loaded.');
        }

        $departure_ts = $this->getRouteDepartureTimestamp($current_route);
        $hours_until_departure = ($departure_ts - time()) / 3600;
        if ($hours_until_departure < 4) {
            return array('success' => false, 'message' => 'Journey changes are only allowed up to 4 hours before departure.');
        }

        if ((int)$new_route_id === (int)$booking['route_id']) {
            return array('success' => false, 'message' => 'Please choose a different journey option.');
        }

        $new_route = $this->getRouteContext($new_route_id);
        if (!$new_route || $new_route['status'] !== 'scheduled') {
            return array('success' => false, 'message' => 'Selected route is not available.');
        }

        if (
            ($new_route['departure_city'] ?? '') !== ($current_route['departure_city'] ?? '') ||
            ($new_route['arrival_city'] ?? '') !== ($current_route['arrival_city'] ?? '')
        ) {
            return array('success' => false, 'message' => 'You can only change to the same origin and destination.');
        }

        if ($this->getRouteDepartureTimestamp($new_route) <= time()) {
            return array('success' => false, 'message' => 'Selected route has already departed.');
        }

        $passengers = $this->getBookingPassengerManifest($booking_id);
        if (count($passengers) !== (int)$booking['number_of_seats']) {
            // Booking has no seat records – build a synthetic economy-class list so
            // the journey-change preview still works for legacy / seeded bookings.
            $passengers = [];
            for ($i = 0; $i < (int)$booking['number_of_seats']; $i++) {
                $passengers[] = [
                    'booking_seat_id' => 0,
                    'seat_id'         => 0,
                    'passenger_name'  => 'Passenger ' . ($i + 1),
                    'passenger_age'   => null,
                    'passenger_gender'=> 'Other',
                    'seat_number'     => '',
                    'seat_type'       => 'economy',
                ];
            }
        }

        $this->ensureRouteSeatsExist($new_route);
        $allocation = $this->selectReplacementSeats((int)$new_route_id, $passengers);
        if (!$allocation['success']) {
            return array('success' => false, 'message' => $allocation['message']);
        }

        $seat_mix = $this->getSeatMix($passengers);
        $new_fare = round((float)$new_route['base_fare'] * $seat_mix['multiplier_total'], 2);
        $current_fare = round((float)$booking['total_fare'], 2);
        $settled_amount = $this->getCompletedPaymentsTotal($booking_id);
        $amount_due = round(max($new_fare - $settled_amount, 0), 2);
        $credit_amount = round(max($settled_amount - $new_fare, 0), 2);

        return array(
            'success' => true,
            'booking' => $booking,
            'current_route' => $current_route,
            'new_route' => $new_route,
            'passengers' => $passengers,
            'seat_mix' => $seat_mix['counts'],
            'current_fare' => $current_fare,
            'new_fare' => $new_fare,
            'fare_delta' => round($new_fare - $current_fare, 2),
            'settled_amount' => $settled_amount,
            'amount_due' => $amount_due,
            'credit_amount' => $credit_amount,
            'requires_payment' => $amount_due > 0.009,
            'hours_until_departure' => $hours_until_departure,
        );
    }

    /**
     * Calculate refund tier based on hours until journey.
     * Returns array:
     *   allowed        bool    – whether cancellation is permitted
     *   hours          float   – hours until journey
     *   refund_pct     int     – percentage of fare refunded (0–100)
     *   fee_pct        int     – percentage charged as cancellation fee
     *   refund_amount  float   – Rs amount to refund
     *   cancel_fee     float   – Rs cancellation fee
     *   tier_label     string  – human-readable tier name
     *   message        string  – user-facing description
     */
    public function getRefundPreview($booking_id, $user_id = null) {
        $booking = $this->getBookingById($booking_id);

        if (!$booking) {
            return array('allowed' => false, 'message' => 'Booking not found.');
        }
        if ($user_id !== null && $booking['user_id'] != $user_id) {
            return array('allowed' => false, 'message' => 'Unauthorised.');
        }
        if ($booking['booking_status'] === BOOKING_CANCELLED) {
            return array('allowed' => false, 'message' => 'This booking is already cancelled.');
        }
        if (!in_array($booking['booking_status'], [BOOKING_CONFIRMED, BOOKING_PENDING])) {
            return array('allowed' => false, 'message' => 'Only confirmed or pending bookings can be cancelled.');
        }

        $fare = (float)$booking['total_fare'];
        $route = $this->getRouteContext($booking['route_id']);
        $journey_dt = $route ? $this->getRouteDepartureTimestamp($route) : strtotime($booking['journey_date'] . ' 00:00:00');
        $hours = ($journey_dt - time()) / 3600;

        if ($hours <= 0) {
            return array(
                'allowed' => false,
                'hours' => $hours,
                'refund_pct' => 0,
                'fee_pct' => 100,
                'refund_amount' => 0,
                'cancel_fee' => $fare,
                'tier_label' => 'Journey Departed',
                'message' => 'This journey has already departed and can no longer be cancelled.',
                'is_unpaid' => false,
            );
        }

        if (($booking['payment_status'] ?? '') !== PAYMENT_COMPLETED) {
            return array(
                'allowed' => true,
                'hours' => $hours,
                'refund_pct' => 0,
                'fee_pct' => 0,
                'refund_amount' => 0,
                'cancel_fee' => 0,
                'tier_label' => 'Unpaid reservation',
                'message' => 'This unpaid booking will be cancelled without any refund or cancellation fee.',
                'booking' => $booking,
                'is_unpaid' => true,
            );
        }

        if ($hours < 24) {
            $cancel_fee    = round($fare, 2);
            $refund_amount = 0.0;
            return array(
                'allowed'       => true,
                'hours'         => $hours,
                'refund_pct'    => 0,
                'fee_pct'       => 100,
                'refund_amount' => $refund_amount,
                'cancel_fee'    => $cancel_fee,
                'tier_label'    => 'No Refund (< 24 hrs)',
                'message'       => 'Cancellation within 24 hours applies a 100% cancellation fee. No refund will be issued.',
                'booking'       => $booking,
                'is_unpaid'     => false,
            );
        } elseif ($hours < 48) {
            $fee_pct = 50; $refund_pct = 50;
            $tier_label = '24–48 hrs (50% refund)';
        } elseif ($hours < 72) {
            $fee_pct = 25; $refund_pct = 75;
            $tier_label = '48–72 hrs (75% refund)';
        } else {
            $fee_pct = 0; $refund_pct = 100;
            $tier_label = 'More than 72 hrs (100% refund)';
        }

        $cancel_fee    = round($fare * $fee_pct / 100, 2);
        $refund_amount = round($fare - $cancel_fee, 2);

        return array(
            'allowed'       => true,
            'hours'         => $hours,
            'refund_pct'    => $refund_pct,
            'fee_pct'       => $fee_pct,
            'refund_amount' => $refund_amount,
            'cancel_fee'    => $cancel_fee,
            'tier_label'    => $tier_label,
            'message'       => "You will receive a {$refund_pct}% refund (Rs " . number_format($refund_amount, 2) . "). Cancellation fee: Rs " . number_format($cancel_fee, 2) . ".",
            'booking'       => $booking,
            'is_unpaid'     => false,
        );
    }

    /**
     * Cancel a booking and apply time-based refund rules.
     * Stores cancellation_fee, refund_amount, cancellation_reason, cancelled_at
     * in the bookings row and marks payment as refunded.
     */
    public function requestCancellation($booking_id, $user_id = null, $reason = '') {
        $preview = $this->getRefundPreview($booking_id, $user_id);

        if (!$preview['allowed']) {
            return array('success' => false, 'message' => $preview['message']);
        }

        $booking       = $preview['booking'];
        $refund_amount = $preview['refund_amount'];
        $cancel_fee    = $preview['cancel_fee'];

        $conn = $this->db->getConnection();
        $reason_text = trim($reason) !== ''
            ? trim($reason)
            : (($preview['is_unpaid'] ?? false) ? 'Passenger cancellation (unpaid booking)' : 'Passenger cancellation');
        $reason_sql = $conn->real_escape_string($reason_text);
        $refund_note_sql = $conn->real_escape_string('Passenger cancellation - ' . $preview['tier_label']);
        $booking_payment_status = ($preview['is_unpaid'] ?? false) ? ($booking['payment_status'] ?? PAYMENT_PENDING) : PAYMENT_REFUNDED;

        $conn->begin_transaction();

        try {
            // Step 1: update the two guaranteed columns that always exist in the base schema
            if (!$conn->query(
                "UPDATE bookings SET
                    booking_status    = '" . BOOKING_CANCELLED . "',
                    payment_status    = '{$booking_payment_status}'
                 WHERE booking_id = {$booking_id}"
            )) {
                throw new \RuntimeException('Failed to cancel this booking: ' . $conn->error);
            }

            // Step 2: attempt to store extra cancellation detail columns.
            // These columns are added via ALTER TABLE in the schema and may be absent
            // on MySQL 8 installations (ADD COLUMN IF NOT EXISTS is MariaDB-only).
            // We run this as a best-effort query and ignore failure so cancellation
            // always succeeds even on a minimal schema.
            $conn->query(
                "UPDATE bookings SET
                    cancellation_reason = '{$reason_sql}',
                    cancellation_fee    = {$cancel_fee},
                    refund_amount       = {$refund_amount},
                    cancelled_at        = NOW()
                 WHERE booking_id = {$booking_id}"
            );

            $seats = $this->db->select("SELECT seat_id FROM booking_seats WHERE booking_id = {$booking_id}");
            if ($seats) {
                foreach ($seats as $seat) {
                    if (!$this->db->update('seats', 'seat_id', $seat['seat_id'], array('status' => 'available'))) {
                        throw new \RuntimeException('Failed to release the booked seats.');
                    }
                }
            }

            if (!$conn->query(
                "UPDATE routes SET available_seats = available_seats + {$booking['number_of_seats']}
                 WHERE route_id = {$booking['route_id']}"
            )) {
                throw new \RuntimeException('Failed to restore route availability.');
            }

            $this->syncRouteAvailableSeats((int)$booking['route_id']);

            if (!($preview['is_unpaid'] ?? false) && $refund_amount > 0) {
                // Attempt to mark the payment as refunded; ignore failure if
                // refund_date / refund_reason columns don't exist on this schema.
                $paid_update = $conn->query(
                    "UPDATE payments SET
                        payment_status = 'refunded',
                        refund_date    = NOW(),
                        refund_reason  = '{$refund_note_sql}'
                     WHERE booking_id = {$booking_id} AND payment_status = 'completed'"
                );
                if (!$paid_update) {
                    // Fallback: update only payment_status
                    $conn->query(
                        "UPDATE payments SET payment_status = 'refunded'
                         WHERE booking_id = {$booking_id} AND payment_status = 'completed'"
                    );
                }
            }

            $conn->commit();
        } catch (\Throwable $throwable) {
            $conn->rollback();
            return array('success' => false, 'message' => $throwable->getMessage());
        }

        $this->triggerWaitlistProcessing((int)$booking['route_id']);

        // ── Notifications ─────────────────────────────────────────────────
        $bref      = $booking['booking_reference'] ?? "#{$booking_id}";
        $route_row = $this->db->selectRow(
            "SELECT r.departure_city, r.arrival_city, r.journey_date
             FROM routes r WHERE r.route_id = {$booking['route_id']}"
        );
        $dep  = $route_row['departure_city'] ?? '';
        $arr  = $route_row['arrival_city']   ?? '';
        $jdt  = isset($route_row['journey_date']) ? date('d M Y', strtotime($route_row['journey_date'])) : '';
        $uid  = (int)$booking['user_id'];

        if ($preview['is_unpaid'] ?? false) {
            $this->pushNotif($uid,
                "Booking {$bref} cancelled · {$dep} → {$arr} · {$jdt}. Unpaid reservation released.",
                'cancel', $booking_id
            );
        } else {
            $rfmt = number_format((float)$refund_amount, 2);
            $cfmt = number_format((float)$cancel_fee,    2);
            $this->pushNotif($uid,
                "Booking {$bref} cancelled · {$dep} → {$arr} · {$jdt}. Refund: Rs {$rfmt}" .
                ($cancel_fee > 0 ? " (fee Rs {$cfmt})" : "") . ". Allow 3-5 business days.",
                'cancel', $booking_id
            );
        }
        $user_row = $this->db->selectRow("SELECT full_name FROM users WHERE user_id = {$uid}");
        $uname    = $user_row['full_name'] ?? "User #{$uid}";
        $rfmt2    = number_format((float)$refund_amount, 2);
        $this->pushNotifToAdmins(
            "Booking {$bref} cancelled by {$uname} · {$dep} → {$arr} · {$jdt} · Refund: Rs {$rfmt2}",
            'cancel', $booking_id
        );
        // ─────────────────────────────────────────────────────────────────

        return array(
            'success'       => true,
            'message'       => ($preview['is_unpaid'] ?? false)
                ? 'Booking cancelled successfully. Your unpaid reservation has been released.'
                : 'Booking cancelled. ' . $preview['message'],
            'refund_amount' => $refund_amount,
            'cancel_fee'    => $cancel_fee,
            'refund_pct'    => $preview['refund_pct'],
            'tier_label'    => $preview['tier_label'],
            'is_unpaid'     => ($preview['is_unpaid'] ?? false),
        );
    }

    // Update booking journey date (change journey to another available route for the same origin/destination)
    public function updateBookingJourney($booking_id, $new_route_id, $user_id = null) {
        $preview = $this->getJourneyChangePreview($booking_id, $new_route_id, $user_id);
        if (!$preview['success']) {
            return array('success' => false, 'message' => $preview['message']);
        }

        $booking = $preview['booking'];
        $current_route = $preview['current_route'];
        $new_route = $preview['new_route'];
        $passengers = $preview['passengers'];
        $allocation = $this->selectReplacementSeats((int)$new_route_id, $passengers);
        if (!$allocation['success']) {
            return array('success' => false, 'message' => $allocation['message']);
        }

        $num_seats = (int)$booking['number_of_seats'];
        $old_seat_ids = array_map('intval', array_column($passengers, 'seat_id'));
        $new_seat_ids = array_map('intval', array_column($allocation['assignments'], 'seat_id'));
        $conn = $this->db->getConnection();

        $booking_status = $preview['requires_payment'] ? BOOKING_PENDING : BOOKING_CONFIRMED;
        $payment_status = $preview['requires_payment'] ? PAYMENT_PENDING : PAYMENT_COMPLETED;
        $old_route_id = (int)$current_route['route_id'];
        $new_route_id = (int)$new_route['route_id'];
        $new_fare = (float)$preview['new_fare'];

        $conn->begin_transaction();

        try {
            if (!empty($old_seat_ids)) {
                if (!$conn->query(
                    'UPDATE seats SET status = \'available\' WHERE seat_id IN (' . implode(',', $old_seat_ids) . ')'
                )) {
                    throw new \RuntimeException('Failed to release current seats.');
                }
            }

            if (!$conn->query(
                "UPDATE routes SET available_seats = available_seats + {$num_seats} WHERE route_id = {$old_route_id}"
            )) {
                throw new \RuntimeException('Failed to restore availability on the current route.');
            }

            $this->syncRouteAvailableSeats($old_route_id);

            if (!empty($new_seat_ids)) {
                if (!$conn->query(
                    'UPDATE seats SET status = \'booked\' WHERE seat_id IN (' . implode(',', $new_seat_ids) . ') AND status = \'available\''
                )) {
                    throw new \RuntimeException('Failed to reserve replacement seats.');
                }

                if ((int)$conn->affected_rows !== count($new_seat_ids)) {
                    throw new \RuntimeException('Some replacement seats were just taken. Please choose another journey option.');
                }
            }

            foreach ($allocation['assignments'] as $assignment) {
                if (!$conn->query(
                    'UPDATE booking_seats SET seat_id = ' . (int)$assignment['seat_id'] .
                    ' WHERE booking_seat_id = ' . (int)$assignment['booking_seat_id']
                )) {
                    throw new \RuntimeException('Failed to move passenger seat assignments.');
                }
            }

            if (!$conn->query(
                "UPDATE routes SET available_seats = available_seats - {$num_seats} WHERE route_id = {$new_route_id}"
            )) {
                throw new \RuntimeException('Failed to update availability on the new route.');
            }

            $this->syncRouteAvailableSeats($new_route_id);

            if (!$conn->query(
                "UPDATE bookings SET
                    route_id = {$new_route_id},
                    journey_date = '" . $conn->real_escape_string($new_route['journey_date']) . "',
                    total_fare = {$new_fare},
                    booking_status = '{$booking_status}',
                    payment_status = '{$payment_status}'
                 WHERE booking_id = {$booking_id}"
            )) {
                throw new \RuntimeException('Failed to update the booking record.');
            }

            $conn->commit();
        } catch (\Throwable $throwable) {
            $conn->rollback();
            return array('success' => false, 'message' => $throwable->getMessage());
        }

        $this->triggerWaitlistProcessing($old_route_id);

        if ($preview['requires_payment']) {
            $message = 'Journey updated successfully. Additional payment of Rs ' . number_format($preview['amount_due'], 2) . ' is required to confirm the new itinerary.';
        } elseif ($preview['credit_amount'] > 0.009) {
            $message = 'Journey updated successfully. No extra payment is required. The new route costs Rs ' . number_format($preview['credit_amount'], 2) . ' less than what you already paid.';
        } elseif ($preview['settled_amount'] > 0) {
            $message = 'Journey updated successfully. Your existing payment still covers this booking.';
        } else {
            $message = 'Journey updated successfully. Please complete payment to confirm the new itinerary.';
        }

        return array(
            'success' => true,
            'message' => $message,
            'new_fare' => $new_fare,
            'amount_due' => $preview['amount_due'],
            'credit_amount' => $preview['credit_amount'],
            'requires_payment' => $preview['requires_payment'],
        );
    }

    // Confirm booking (after payment)
    public function confirmBooking($booking_id) {
        $result = $this->db->update('bookings', 'booking_id', $booking_id,
                                    array('booking_status' => BOOKING_CONFIRMED,
                                          'payment_status' => PAYMENT_COMPLETED));
        if ($result) {
            $b = $this->db->selectRow(
                "SELECT b.user_id, b.booking_reference, b.total_fare, b.number_of_seats,
                        r.departure_city, r.arrival_city, r.journey_date
                 FROM bookings b JOIN routes r ON b.route_id = r.route_id
                 WHERE b.booking_id = {$booking_id}"
            );
            if ($b) {
                $bref = $b['booking_reference'];
                $dep  = $b['departure_city'];
                $arr  = $b['arrival_city'];
                $jdt  = date('d M Y', strtotime($b['journey_date']));
                $fare = number_format((float)$b['total_fare'], 2);
                $uid  = (int)$b['user_id'];
                $this->pushNotif($uid,
                    "Booking {$bref} confirmed! {$dep} → {$arr} · {$jdt} · {$b['number_of_seats']} seat(s) · Rs {$fare}. Have a great journey!",
                    'confirm', $booking_id
                );
            }
        }
        return $result;
    }

    /**
     * Update the passenger details (name, age, gender) for one or more seats
     * on a booking. Only allowed on non-cancelled bookings.
     *
     * $passengers_data  array of:
     *   ['booking_seat_id' => int, 'passenger_name' => string,
     *    'passenger_age' => int|null, 'passenger_gender' => string|null]
     *
     * Returns ['success' => bool, 'message' => string]
     */
    public function updatePassengerDetails($booking_id, $user_id, array $passengers_data) {
        $booking_id = (int)$booking_id;
        $booking = $this->getBookingById($booking_id);

        if (!$booking) {
            return ['success' => false, 'message' => 'Booking not found.'];
        }
        if ((int)$booking['user_id'] !== (int)$user_id) {
            return ['success' => false, 'message' => 'Unauthorised.'];
        }
        if ($booking['booking_status'] === BOOKING_CANCELLED) {
            return ['success' => false, 'message' => 'Cannot edit a cancelled booking.'];
        }

        if (empty($passengers_data)) {
            return ['success' => false, 'message' => 'No passenger data provided.'];
        }

        $conn = $this->db->getConnection();
        $conn->begin_transaction();

        try {
            foreach ($passengers_data as $passenger) {
                $bsid = (int)($passenger['booking_seat_id'] ?? 0);
                if ($bsid <= 0) continue;

                $name = trim($passenger['passenger_name'] ?? '');
                if ($name === '') {
                    throw new \RuntimeException('Passenger name cannot be blank.');
                }

                $name_e   = $conn->real_escape_string($name);
                $age_sql  = isset($passenger['passenger_age']) && $passenger['passenger_age'] !== ''
                    ? (int)$passenger['passenger_age']
                    : 'NULL';
                $gender_allowed = ['M', 'F', 'Other'];
                $gender_raw = $passenger['passenger_gender'] ?? '';
                $gender_sql = in_array($gender_raw, $gender_allowed, true)
                    ? "'" . $conn->real_escape_string($gender_raw) . "'"
                    : 'NULL';

                if (!$conn->query(
                    "UPDATE booking_seats SET
                        passenger_name   = '{$name_e}',
                        passenger_age    = {$age_sql},
                        passenger_gender = {$gender_sql}
                     WHERE booking_seat_id = {$bsid}
                       AND booking_id = {$booking_id}"
                )) {
                    throw new \RuntimeException('Failed to update passenger details.');
                }
            }

            $conn->commit();
        } catch (\Throwable $throwable) {
            $conn->rollback();
            return ['success' => false, 'message' => $throwable->getMessage()];
        }

        return ['success' => true, 'message' => 'Passenger details updated successfully.'];
    }

    /**
     * Flexible journey-change preview — allows different cities, different seat count,
     * and per-passenger seat-class selection.
     *
     * $new_passengers  array of:
     *   ['passenger_name'=>string, 'passenger_age'=>int|null,
     *    'passenger_gender'=>string, 'seat_type'=>'economy'|'premium'|'luxury']
     *
     * Returns same shape as getJourneyChangePreview() plus 'new_passengers'.
     */
    public function getFlexibleJourneyPreview(int $booking_id, int $new_route_id, int $user_id, array $new_passengers): array {
        $booking = $this->getBookingById($booking_id);
        if (!$booking) {
            return ['success' => false, 'message' => 'Booking not found.'];
        }
        if ((int)$booking['user_id'] !== $user_id) {
            return ['success' => false, 'message' => 'Unauthorised.'];
        }
        if ($booking['booking_status'] === BOOKING_CANCELLED) {
            return ['success' => false, 'message' => 'Cancelled bookings cannot be changed.'];
        }

        $current_route = $this->getRouteContext($booking['route_id']);
        if (!$current_route) {
            return ['success' => false, 'message' => 'Current route could not be loaded.'];
        }

        $hours_until_departure = ($this->getRouteDepartureTimestamp($current_route) - time()) / 3600;
        if ($hours_until_departure < 4) {
            return ['success' => false, 'message' => 'Journey changes are only allowed up to 4 hours before departure.'];
        }

        if ($new_route_id === (int)$booking['route_id']) {
            return ['success' => false, 'message' => 'Please choose a different journey option.'];
        }

        $new_route = $this->getRouteContext($new_route_id);
        if (!$new_route || $new_route['status'] !== 'scheduled') {
            return ['success' => false, 'message' => 'Selected route is not available.'];
        }
        if ($this->getRouteDepartureTimestamp($new_route) <= time()) {
            return ['success' => false, 'message' => 'Selected route has already departed.'];
        }

        $num_seats = count($new_passengers);
        if ($num_seats < 1) {
            return ['success' => false, 'message' => 'At least one passenger is required.'];
        }
        if ($num_seats > 6) {
            return ['success' => false, 'message' => 'Maximum 6 passengers per booking.'];
        }

        // Validate seat types
        $allowed_types = ['economy', 'premium', 'luxury'];
        foreach ($new_passengers as $i => $np) {
            if (!in_array($np['seat_type'] ?? 'economy', $allowed_types, true)) {
                $new_passengers[$i]['seat_type'] = 'economy';
            }
        }

        // Build synthetic passenger list for seat selection (each row needs seat_type)
        $synthetic = [];
        foreach ($new_passengers as $i => $np) {
            $synthetic[] = [
                'booking_seat_id' => 0,
                'seat_id'         => 0,
                'passenger_name'  => $np['passenger_name'] ?? ('Passenger ' . ($i + 1)),
                'passenger_age'   => $np['passenger_age'] ?? null,
                'passenger_gender'=> $np['passenger_gender'] ?? 'Other',
                'seat_number'     => '',
                'seat_type'       => $np['seat_type'] ?? 'economy',
            ];
        }

        $this->ensureRouteSeatsExist($new_route);
        $allocation = $this->selectReplacementSeats($new_route_id, $synthetic);
        if (!$allocation['success']) {
            return ['success' => false, 'message' => $allocation['message']];
        }

        $seat_mix     = $this->getSeatMix($synthetic);
        $new_fare     = round((float)$new_route['base_fare'] * $seat_mix['multiplier_total'], 2);
        $current_fare = round((float)$booking['total_fare'], 2);
        $settled      = $this->getCompletedPaymentsTotal($booking_id);
        $amount_due   = round(max($new_fare - $settled, 0), 2);
        $credit       = round(max($settled - $new_fare, 0), 2);

        return [
            'success'            => true,
            'booking'            => $booking,
            'current_route'      => $current_route,
            'new_route'          => $new_route,
            'new_passengers'     => $synthetic,
            'seat_mix'           => $seat_mix['counts'],
            'current_fare'       => $current_fare,
            'new_fare'           => $new_fare,
            'fare_delta'         => round($new_fare - $current_fare, 2),
            'settled_amount'     => $settled,
            'amount_due'         => $amount_due,
            'credit_amount'      => $credit,
            'requires_payment'   => $amount_due > 0.009,
            'hours_until_departure' => $hours_until_departure,
            'allocation'         => $allocation,
        ];
    }

    /**
     * Execute a flexible journey change: different route (any city), different seat count,
     * different seat classes, different passengers.
     */
    public function updateBookingJourneyFlex(int $booking_id, int $new_route_id, int $user_id, array $new_passengers): array {
        $preview = $this->getFlexibleJourneyPreview($booking_id, $new_route_id, $user_id, $new_passengers);
        if (!$preview['success']) {
            return ['success' => false, 'message' => $preview['message']];
        }

        $booking      = $preview['booking'];
        $current_route = $preview['current_route'];
        $new_route    = $preview['new_route'];
        $synthetic    = $preview['new_passengers'];
        $allocation   = $this->selectReplacementSeats($new_route_id, $synthetic);
        if (!$allocation['success']) {
            return ['success' => false, 'message' => $allocation['message']];
        }

        $num_seats_new  = count($synthetic);
        $num_seats_old  = (int)$booking['number_of_seats'];
        $old_route_id   = (int)$current_route['route_id'];
        $new_fare       = (float)$preview['new_fare'];

        // Get old seat IDs
        $old_seat_rows = $this->db->select("SELECT seat_id FROM booking_seats WHERE booking_id = {$booking_id}");
        $old_seat_ids  = array_map('intval', array_column($old_seat_rows ?: [], 'seat_id'));
        $new_seat_ids  = array_map('intval', array_column($allocation['assignments'], 'seat_id'));

        $booking_status = $preview['requires_payment'] ? BOOKING_PENDING : BOOKING_CONFIRMED;
        $payment_status = $preview['requires_payment'] ? PAYMENT_PENDING : PAYMENT_COMPLETED;

        $conn = $this->db->getConnection();
        $conn->begin_transaction();

        try {
            // Release old seats
            if (!empty($old_seat_ids)) {
                $conn->query('UPDATE seats SET status = \'available\' WHERE seat_id IN (' . implode(',', $old_seat_ids) . ')');
            }
            $conn->query("UPDATE routes SET available_seats = available_seats + {$num_seats_old} WHERE route_id = {$old_route_id}");
            $this->syncRouteAvailableSeats($old_route_id);

            // Reserve new seats
            if (!empty($new_seat_ids)) {
                if (!$conn->query('UPDATE seats SET status = \'booked\' WHERE seat_id IN (' . implode(',', $new_seat_ids) . ') AND status = \'available\'')) {
                    throw new \RuntimeException('Failed to reserve new seats.');
                }
                if ((int)$conn->affected_rows !== count($new_seat_ids)) {
                    throw new \RuntimeException('Some new seats were just taken. Please try again.');
                }
            }
            $conn->query("UPDATE routes SET available_seats = available_seats - {$num_seats_new} WHERE route_id = {$new_route_id}");
            $this->syncRouteAvailableSeats($new_route_id);

            // Delete old booking_seats rows and insert new ones
            $conn->query("DELETE FROM booking_seats WHERE booking_id = {$booking_id}");

            foreach ($allocation['assignments'] as $i => $asgn) {
                $np       = $synthetic[$i];
                $name_e   = $conn->real_escape_string(trim($np['passenger_name'] ?? 'Passenger'));
                $age_sql  = ($np['passenger_age'] !== null && $np['passenger_age'] !== '') ? (int)$np['passenger_age'] : 'NULL';
                $genders  = ['M', 'F', 'Other'];
                $gender   = in_array($np['passenger_gender'] ?? '', $genders, true) ? $np['passenger_gender'] : 'Other';
                $gender_e = $conn->real_escape_string($gender);
                if (!$conn->query(
                    "INSERT INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender)
                     VALUES ({$booking_id}, {$asgn['seat_id']}, '{$name_e}', {$age_sql}, '{$gender_e}')"
                )) {
                    throw new \RuntimeException('Failed to create passenger seat records.');
                }
            }

            // Update booking header
            if (!$conn->query(
                "UPDATE bookings SET
                    route_id        = {$new_route_id},
                    journey_date    = '" . $conn->real_escape_string($new_route['journey_date']) . "',
                    number_of_seats = {$num_seats_new},
                    total_fare      = {$new_fare},
                    booking_status  = '{$booking_status}',
                    payment_status  = '{$payment_status}'
                 WHERE booking_id   = {$booking_id}"
            )) {
                throw new \RuntimeException('Failed to update booking record.');
            }

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $this->triggerWaitlistProcessing($old_route_id);

        if ($preview['requires_payment']) {
            $msg = 'Booking updated. Additional payment of Rs ' . number_format($preview['amount_due'], 2) . ' is required to confirm.';
        } elseif ($preview['credit_amount'] > 0.009) {
            $msg = 'Booking updated. New route costs Rs ' . number_format($preview['credit_amount'], 2) . ' less — no extra payment needed.';
        } else {
            $msg = 'Booking updated successfully.';
        }

        // ── Notifications ─────────────────────────────────────────────────
        $b_row  = $this->db->selectRow("SELECT booking_reference, user_id FROM bookings WHERE booking_id = {$booking_id}");
        $bref   = $b_row['booking_reference'] ?? "#{$booking_id}";
        $uid    = (int)($b_row['user_id'] ?? $user_id);
        $ndep   = $new_route['departure_city'] ?? '';
        $narr   = $new_route['arrival_city']   ?? '';
        $njdt   = isset($new_route['journey_date']) ? date('d M Y', strtotime($new_route['journey_date'])) : '';
        $nfmt   = number_format((float)$new_fare, 2);

        $user_notif = "Booking {$bref} journey changed to {$ndep} → {$narr} · {$njdt} · {$num_seats_new} seat(s) · Rs {$nfmt}.";
        if ($preview['requires_payment']) {
            $user_notif .= ' Extra payment of Rs ' . number_format($preview['amount_due'], 2) . ' required.';
        } elseif (($preview['credit_amount'] ?? 0) > 0.009) {
            $user_notif .= ' No extra payment needed.';
        }
        $this->pushNotif($uid, $user_notif, 'update', $booking_id);

        $user_row = $this->db->selectRow("SELECT full_name FROM users WHERE user_id = {$uid}");
        $uname    = $user_row['full_name'] ?? "User #{$uid}";
        $this->pushNotifToAdmins(
            "Booking {$bref} journey changed by {$uname} → {$ndep} → {$narr} · {$njdt} · Rs {$nfmt}",
            'update', $booking_id
        );
        // ─────────────────────────────────────────────────────────────────

        return [
            'success'          => true,
            'message'          => $msg,
            'new_fare'         => $new_fare,
            'amount_due'       => $preview['amount_due'],
            'credit_amount'    => $preview['credit_amount'],
            'requires_payment' => $preview['requires_payment'],
        ];
    }

    // Get all bookings (Admin)
    public function getAllBookings($filter = array()) {
        $query = "SELECT b.*, u.username, u.full_name, r.departure_city, r.arrival_city, t.train_name 
                  FROM bookings b 
                  JOIN users u ON b.user_id = u.user_id 
                  JOIN routes r ON b.route_id = r.route_id 
                  JOIN trains t ON r.train_id = t.train_id 
                  WHERE 1=1";

        if (isset($filter['booking_status'])) {
            $query .= " AND b.booking_status = '{$filter['booking_status']}'";
        }
        if (isset($filter['payment_status'])) {
            $query .= " AND b.payment_status = '{$filter['payment_status']}'";
        }

        $query .= " ORDER BY b.booking_date DESC";
        
        return $this->db->select($query);
    }
}
?>

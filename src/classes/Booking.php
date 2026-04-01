<?php
// Booking Class

class Booking {
    private $db;

    public function __construct($database) {
        $this->db = $database;
    }

    // Create booking reference
    public function generateBookingReference() {
        return 'RWY' . date('YmdHis') . rand(1000, 9999);
    }

    // Create new booking
    public function createBooking($user_id, $route_id, $seats_data) {
        $route = $this->db->selectRow("SELECT * FROM routes WHERE route_id = {$route_id}");
        
        if (!$route) {
            return array('success' => false, 'message' => 'Route not found!');
        }

        $num_seats = count($seats_data);
        $total_fare = $route['base_fare'] * $num_seats;
        $booking_ref = $this->generateBookingReference();

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

        if ($booking_id) {
            // Add seats to booking
            foreach ($seats_data as $seat) {
                $seat_data = array(
                    'booking_id' => $booking_id,
                    'seat_id' => $seat['seat_id'],
                    'passenger_name' => $seat['passenger_name'],
                    'passenger_age' => isset($seat['passenger_age']) ? $seat['passenger_age'] : NULL,
                    'passenger_gender' => isset($seat['passenger_gender']) ? $seat['passenger_gender'] : NULL
                );
                $this->db->insert('booking_seats', $seat_data);
                
                // Update seat status
                $this->db->update('seats', 'seat_id', $seat['seat_id'], array('status' => 'booked'));
            }

            return array('success' => true, 'message' => 'Booking created!', 'booking_id' => $booking_id);
        } else {
            return array('success' => false, 'message' => 'Failed to create booking!');
        }
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

    // Cancel booking immediately (Admin use or internal)
    public function cancelBooking($booking_id, $reason = '') {
        $booking = $this->getBookingById($booking_id);

        if (!$booking) {
            return array('success' => false, 'message' => 'Booking not found!');
        }

        $this->db->update('bookings', 'booking_id', $booking_id,
                          array('booking_status' => BOOKING_CANCELLED));

        $query = "SELECT seat_id FROM booking_seats WHERE booking_id = {$booking_id}";
        $seats = $this->db->select($query);

        if ($seats) {
            foreach ($seats as $seat) {
                $this->db->update('seats', 'seat_id', $seat['seat_id'],
                                  array('status' => 'available'));
            }
        }

        // Restore available_seats on the route
        $this->db->query("UPDATE routes SET available_seats = available_seats + {$booking['number_of_seats']} WHERE route_id = {$booking['route_id']}");

        return array('success' => true, 'message' => 'Booking cancelled successfully!');
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

        $fare         = (float)$booking['total_fare'];
        $journey_dt   = strtotime($booking['journey_date'] . ' 00:00:00');
        $hours        = ($journey_dt - time()) / 3600;

        if ($hours < 24) {
            return array(
                'allowed'       => false,
                'hours'         => $hours,
                'refund_pct'    => 0,
                'fee_pct'       => 100,
                'refund_amount' => 0,
                'cancel_fee'    => $fare,
                'tier_label'    => 'No Refund',
                'message'       => 'Cancellations are not allowed within 24 hours of the journey.',
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
        $safe_reason   = addslashes($reason ?: 'Passenger cancellation');

        // Update booking row with cancellation details
        $this->db->query(
            "UPDATE bookings SET
                booking_status      = '" . BOOKING_CANCELLED . "',
                cancellation_reason = '{$safe_reason}',
                cancellation_fee    = {$cancel_fee},
                refund_amount       = {$refund_amount},
                cancelled_at        = NOW()
            WHERE booking_id = {$booking_id}"
        );

        // Release seats
        $seats = $this->db->select("SELECT seat_id FROM booking_seats WHERE booking_id = {$booking_id}");
        if ($seats) {
            foreach ($seats as $seat) {
                $this->db->update('seats', 'seat_id', $seat['seat_id'], array('status' => 'available'));
            }
        }

        // Restore route availability
        $this->db->query("UPDATE routes SET available_seats = available_seats + {$booking['number_of_seats']} WHERE route_id = {$booking['route_id']}");

        // Mark payment as refunded (partial or full based on refund_amount)
        $refund_note   = addslashes("Passenger cancellation – {$preview['tier_label']}");
        $payment_status = $refund_amount > 0 ? 'refunded' : 'forfeited';
        $this->db->query("UPDATE payments SET payment_status = '{$payment_status}', refund_date = NOW(), refund_reason = '{$refund_note}' WHERE booking_id = {$booking_id} AND payment_status = 'completed'");
        if ($refund_amount > 0) {
            $this->db->query("UPDATE bookings SET payment_status = 'refunded' WHERE booking_id = {$booking_id} AND payment_status IN ('completed','paid')");
        }

        return array(
            'success'       => true,
            'message'       => 'Booking cancelled. ' . $preview['message'],
            'refund_amount' => $refund_amount,
            'cancel_fee'    => $cancel_fee,
            'refund_pct'    => $preview['refund_pct'],
            'tier_label'    => $preview['tier_label'],
        );
    }

    // Update booking journey date (change journey to another available route for the same origin/destination)
    public function updateBookingJourney($booking_id, $new_route_id, $user_id = null) {
        $booking = $this->getBookingById($booking_id);

        if (!$booking) {
            return array('success' => false, 'message' => 'Booking not found!');
        }

        if ($user_id !== null && $booking['user_id'] != $user_id) {
            return array('success' => false, 'message' => 'Unauthorised.');
        }

        if ($booking['booking_status'] === BOOKING_CANCELLED) {
            return array('success' => false, 'message' => 'Cannot update a cancelled booking.');
        }

        // Enforce 24-hour update window
        $journey_datetime = strtotime($booking['journey_date'] . ' 00:00:00');
        $hours_until = ($journey_datetime - time()) / 3600;
        if ($hours_until < 24) {
            return array('success' => false, 'message' => 'Updates are only allowed up to 24 hours before the journey date.');
        }

        // Validate new route
        $new_route = $this->db->selectRow("SELECT * FROM routes WHERE route_id = {$new_route_id} AND status = 'scheduled'");
        if (!$new_route) {
            return array('success' => false, 'message' => 'Selected route is not available.');
        }

        if ($new_route['available_seats'] < $booking['number_of_seats']) {
            return array('success' => false, 'message' => 'Not enough seats available on the selected route.');
        }

        $old_route_id = $booking['route_id'];
        $num_seats    = $booking['number_of_seats'];
        $new_fare     = $new_route['base_fare'] * $num_seats;

        // Release old seats
        $old_seats = $this->db->select("SELECT seat_id FROM booking_seats WHERE booking_id = {$booking_id}");
        if ($old_seats) {
            foreach ($old_seats as $s) {
                $this->db->update('seats', 'seat_id', $s['seat_id'], array('status' => 'available'));
            }
        }
        // Restore old route available seats count
        $this->db->query("UPDATE routes SET available_seats = available_seats + {$num_seats} WHERE route_id = {$old_route_id}");

        // Remove old booking_seats rows
        $this->db->query("DELETE FROM booking_seats WHERE booking_id = {$booking_id}");

        // Update booking record
        $this->db->update('bookings', 'booking_id', $booking_id, array(
            'route_id'     => $new_route_id,
            'journey_date' => $new_route['journey_date'],
            'total_fare'   => $new_fare,
            'booking_status' => BOOKING_PENDING,
            'payment_status' => PAYMENT_PENDING,
        ));

        // Decrease new route seats
        $this->db->query("UPDATE routes SET available_seats = available_seats - {$num_seats} WHERE route_id = {$new_route_id}");

        return array('success' => true, 'message' => 'Booking updated successfully! Please complete payment for the new fare.', 'new_fare' => $new_fare);
    }

    // Confirm booking (after payment)
    public function confirmBooking($booking_id) {
        return $this->db->update('bookings', 'booking_id', $booking_id, 
                                 array('booking_status' => BOOKING_CONFIRMED, 
                                       'payment_status' => PAYMENT_COMPLETED));
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

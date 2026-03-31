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

    // Request cancellation with 24-hour time restriction (Passenger use)
    public function requestCancellation($booking_id, $user_id = null) {
        $booking = $this->getBookingById($booking_id);

        if (!$booking) {
            return array('success' => false, 'message' => 'Booking not found!');
        }

        if ($user_id !== null && $booking['user_id'] != $user_id) {
            return array('success' => false, 'message' => 'Unauthorised.');
        }

        if ($booking['booking_status'] === BOOKING_CANCELLED) {
            return array('success' => false, 'message' => 'Booking is already cancelled.');
        }

        // Enforce 24-hour cancellation window
        $journey_datetime = strtotime($booking['journey_date'] . ' 00:00:00');
        $hours_until_journey = ($journey_datetime - time()) / 3600;

        if ($hours_until_journey < 24) {
            return array('success' => false, 'message' => 'Cancellations are only allowed up to 24 hours before the journey date.');
        }

        // Cancel booking and release seats
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

        // Mark payment as refunded if it was completed
        $this->db->query("UPDATE payments SET payment_status = 'refunded', refund_date = NOW(), refund_reason = 'Passenger cancellation' WHERE booking_id = {$booking_id} AND payment_status = 'completed'");
        $this->db->query("UPDATE bookings SET payment_status = 'refunded' WHERE booking_id = {$booking_id} AND payment_status = 'completed'");

        return array('success' => true, 'message' => 'Booking cancelled successfully. A refund will be processed if applicable.');
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

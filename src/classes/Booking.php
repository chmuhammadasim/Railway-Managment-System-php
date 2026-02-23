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

    // Cancel booking
    public function cancelBooking($booking_id, $reason = '') {
        $booking = $this->getBookingById($booking_id);
        
        if (!$booking) {
            return array('success' => false, 'message' => 'Booking not found!');
        }

        // Update booking status
        $this->db->update('bookings', 'booking_id', $booking_id, 
                          array('booking_status' => BOOKING_CANCELLED));

        // Release seats
        $query = "SELECT seat_id FROM booking_seats WHERE booking_id = {$booking_id}";
        $seats = $this->db->select($query);
        
        if ($seats) {
            foreach ($seats as $seat) {
                $this->db->update('seats', 'seat_id', $seat['seat_id'], 
                                  array('status' => 'available'));
            }
        }

        return array('success' => true, 'message' => 'Booking cancelled successfully!');
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

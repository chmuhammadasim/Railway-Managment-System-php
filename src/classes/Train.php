<?php
// Train Class

class Train {
    private $db;

    public function __construct($database) {
        $this->db = $database;
    }

    // Get all trains
    public function getAllTrains() {
        $query = "SELECT * FROM trains WHERE status = 'active'";
        return $this->db->select($query);
    }

    // Get train by ID
    public function getTrainById($train_id) {
        $query = "SELECT * FROM trains WHERE train_id = {$train_id}";
        return $this->db->selectRow($query);
    }

    // Add new train (Admin)
    public function addTrain($train_name, $train_number, $train_type, $total_seats) {
        $data = array(
            'train_name' => $train_name,
            'train_number' => $train_number,
            'train_type' => $train_type,
            'total_seats' => $total_seats,
            'available_seats' => $total_seats
        );

        return $this->db->insert('trains', $data);
    }

    // Create new train (Admin) - alternative method accepting array
    public function createTrain($data) {
        return $this->db->insert('trains', $data);
    }

    // Update train
    public function updateTrain($train_id, $data) {
        return $this->db->update('trains', 'train_id', $train_id, $data);
    }

    // Get all routes
    public function getAllRoutes($filter = array()) {
        $query = "SELECT r.*, t.train_name, t.train_number 
                  FROM routes r 
                  JOIN trains t ON r.train_id = t.train_id 
                  WHERE r.status = 'scheduled'";

        if (isset($filter['departure_city'])) {
            $query .= " AND r.departure_city = '{$filter['departure_city']}'";
        }
        if (isset($filter['arrival_city'])) {
            $query .= " AND r.arrival_city = '{$filter['arrival_city']}'";
        }
        if (isset($filter['journey_date'])) {
            $query .= " AND r.journey_date = '{$filter['journey_date']}'";
        }
        
        $query .= " ORDER BY r.departure_time ASC";
        
        return $this->db->select($query);
    }

    // Get route by ID
    public function getRouteById($route_id) {
        $query = "SELECT r.*, t.train_name, t.train_number, t.total_seats 
                  FROM routes r 
                  JOIN trains t ON r.train_id = t.train_id 
                  WHERE r.route_id = {$route_id}";
        return $this->db->selectRow($query);
    }

    // Add new route
    public function addRoute($train_id, $departure_city, $arrival_city, $departure_time, 
                             $arrival_time, $distance_km, $base_fare, $journey_date) {
        $train = $this->getTrainById($train_id);
        
        $data = array(
            'train_id' => $train_id,
            'departure_city' => $departure_city,
            'arrival_city' => $arrival_city,
            'departure_time' => $departure_time,
            'arrival_time' => $arrival_time,
            'distance_km' => $distance_km,
            'base_fare' => $base_fare,
            'journey_date' => $journey_date,
            'available_seats' => $train['total_seats']
        );

        return $this->db->insert('routes', $data);
    }

    // Get available seats for a route
    public function getAvailableSeats($route_id) {
        $query = "SELECT * FROM seats WHERE route_id = {$route_id} AND status = 'available'";
        return $this->db->select($query);
    }

    // Get seat by ID
    public function getSeatById($seat_id) {
        $query = "SELECT * FROM seats WHERE seat_id = {$seat_id}";
        return $this->db->selectRow($query);
    }

    // Create seats for a route
    public function createSeats($train_id, $route_id, $total_seats) {
        $seatTypes = array('economy', 'economy', 'premium', 'luxury'); // Ratio of seat types
        
        for ($i = 1; $i <= $total_seats; $i++) {
            $seatType = $seatTypes[($i - 1) % count($seatTypes)];
            $seatNumber = chr(65 + floor(($i - 1) / 6)) . (($i - 1) % 6 + 1);
            
            $data = array(
                'train_id' => $train_id,
                'route_id' => $route_id,
                'seat_number' => $seatNumber,
                'seat_type' => $seatType,
                'status' => 'available'
            );
            
            $this->db->insert('seats', $data);
        }
        
        return true;
    }
}
?>

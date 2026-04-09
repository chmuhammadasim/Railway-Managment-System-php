<?php

class Operations {
    private const FARE_MULTIPLIERS = [
        'economy' => 1.0,
        'premium' => 1.5,
        'luxury' => 2.5,
    ];

    private static $schemaReady = false;

    private $db;

    public function __construct($database) {
        $this->db = $database;
    }

    public function ensureSchema(): void {
        if (self::$schemaReady) {
            return;
        }

        $conn = $this->db->getConnection();

        $statements = [
            "CREATE TABLE IF NOT EXISTS stations (
                station_id INT PRIMARY KEY AUTO_INCREMENT,
                station_name VARCHAR(100) NOT NULL,
                station_code VARCHAR(10) NOT NULL UNIQUE,
                city VARCHAR(100) NOT NULL,
                province VARCHAR(100) DEFAULT '',
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_station_city (city, is_active)
            )",
            "CREATE TABLE IF NOT EXISTS live_train_status (
                status_id INT PRIMARY KEY AUTO_INCREMENT,
                route_id INT NOT NULL,
                service_state ENUM('scheduled','boarding','running','delayed','arrived','cancelled','maintenance') DEFAULT 'scheduled',
                current_station VARCHAR(100) DEFAULT NULL,
                next_station VARCHAR(100) DEFAULT NULL,
                delay_minutes INT DEFAULT 0,
                expected_arrival DATETIME DEFAULT NULL,
                status_note VARCHAR(255) DEFAULT NULL,
                updated_by INT DEFAULT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_live_route (route_id),
                CONSTRAINT fk_live_status_route FOREIGN KEY (route_id) REFERENCES routes(route_id) ON DELETE CASCADE,
                CONSTRAINT fk_live_status_user FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
            )",
            "CREATE TABLE IF NOT EXISTS lost_found_items (
                item_id INT PRIMARY KEY AUTO_INCREMENT,
                record_type ENUM('lost','found') DEFAULT 'lost',
                route_id INT DEFAULT NULL,
                reported_by INT DEFAULT NULL,
                assigned_to INT DEFAULT NULL,
                claimed_by INT DEFAULT NULL,
                item_name VARCHAR(120) NOT NULL,
                category VARCHAR(60) DEFAULT 'general',
                description TEXT,
                location_hint VARCHAR(255) DEFAULT NULL,
                contact_phone VARCHAR(20) DEFAULT NULL,
                status ENUM('reported','under_review','matched','claimed','closed') DEFAULT 'reported',
                resolution_note VARCHAR(255) DEFAULT NULL,
                resolved_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_lost_found_status (status, created_at),
                CONSTRAINT fk_lost_found_route FOREIGN KEY (route_id) REFERENCES routes(route_id) ON DELETE SET NULL,
                CONSTRAINT fk_lost_found_reporter FOREIGN KEY (reported_by) REFERENCES users(user_id) ON DELETE SET NULL,
                CONSTRAINT fk_lost_found_assignee FOREIGN KEY (assigned_to) REFERENCES users(user_id) ON DELETE SET NULL,
                CONSTRAINT fk_lost_found_claimed_by FOREIGN KEY (claimed_by) REFERENCES users(user_id) ON DELETE SET NULL
            )",
            "CREATE TABLE IF NOT EXISTS train_maintenance (
                maintenance_id INT PRIMARY KEY AUTO_INCREMENT,
                train_id INT NOT NULL,
                maintenance_type ENUM('inspection','repair','cleaning','overhaul') DEFAULT 'inspection',
                scheduled_date DATE NOT NULL,
                status ENUM('scheduled','in_progress','completed','cancelled') DEFAULT 'scheduled',
                assigned_employee_id INT DEFAULT NULL,
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_maintenance_schedule (scheduled_date, status),
                CONSTRAINT fk_maintenance_train FOREIGN KEY (train_id) REFERENCES trains(train_id) ON DELETE CASCADE,
                CONSTRAINT fk_maintenance_employee FOREIGN KEY (assigned_employee_id) REFERENCES users(user_id) ON DELETE SET NULL
            )",
            "CREATE TABLE IF NOT EXISTS crew_assignments (
                assignment_id INT PRIMARY KEY AUTO_INCREMENT,
                route_id INT NOT NULL,
                employee_id INT NOT NULL,
                role_title VARCHAR(80) NOT NULL,
                shift_start DATETIME DEFAULT NULL,
                shift_end DATETIME DEFAULT NULL,
                assignment_status ENUM('assigned','checked_in','completed','cancelled') DEFAULT 'assigned',
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_crew_route (route_id, assignment_status),
                CONSTRAINT fk_crew_route FOREIGN KEY (route_id) REFERENCES routes(route_id) ON DELETE CASCADE,
                CONSTRAINT fk_crew_employee FOREIGN KEY (employee_id) REFERENCES users(user_id) ON DELETE CASCADE
            )",
            "CREATE TABLE IF NOT EXISTS waitlist_entries (
                waitlist_id INT PRIMARY KEY AUTO_INCREMENT,
                route_id INT NOT NULL,
                user_id INT NOT NULL,
                passenger_manifest TEXT NOT NULL,
                passenger_count INT NOT NULL,
                preferred_class ENUM('economy','premium','luxury') DEFAULT 'economy',
                queue_status ENUM('waitlist','rac','confirmed','cancelled') DEFAULT 'waitlist',
                queue_position INT DEFAULT NULL,
                note VARCHAR(255) DEFAULT NULL,
                linked_booking_id INT DEFAULT NULL,
                auto_promoted_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_waitlist_route (route_id, queue_status, created_at),
                INDEX idx_waitlist_user (user_id, queue_status, created_at),
                CONSTRAINT fk_waitlist_route FOREIGN KEY (route_id) REFERENCES routes(route_id) ON DELETE CASCADE,
                CONSTRAINT fk_waitlist_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
                CONSTRAINT fk_waitlist_booking FOREIGN KEY (linked_booking_id) REFERENCES bookings(booking_id) ON DELETE SET NULL
            )",
        ];

        foreach ($statements as $statement) {
            $conn->query($statement);
        }

        $this->seedStationsFromRoutes();
        self::$schemaReady = true;
    }

    public function getStations(bool $activeOnly = false): array {
        $this->ensureSchema();

        $query = 'SELECT * FROM stations';
        if ($activeOnly) {
            $query .= ' WHERE is_active = 1';
        }
        $query .= ' ORDER BY is_active DESC, city ASC, station_name ASC';

        return $this->db->select($query) ?: [];
    }

    public function getWaitlistEntries(string $role, int $userId = 0): array {
        $this->ensureSchema();

        $where = '';
        if ($role === ROLE_USER) {
            $where = 'WHERE we.user_id = ' . $userId;
        }

        $query =
            "SELECT we.*, u.full_name, u.email,
                    r.departure_city, r.arrival_city, r.journey_date, r.departure_time,
                    t.train_name, t.train_number,
                    b.booking_reference
             FROM waitlist_entries we
             JOIN users u ON we.user_id = u.user_id
             JOIN routes r ON we.route_id = r.route_id
             JOIN trains t ON r.train_id = t.train_id
             LEFT JOIN bookings b ON we.linked_booking_id = b.booking_id
             {$where}
             ORDER BY CASE we.queue_status
                        WHEN 'rac' THEN 0
                        WHEN 'waitlist' THEN 1
                        WHEN 'confirmed' THEN 2
                        ELSE 3
                      END,
                      COALESCE(we.queue_position, 999999) ASC,
                      we.created_at DESC";

        $entries = $this->db->select($query) ?: [];
        foreach ($entries as &$entry) {
            $entry['passengers'] = json_decode($entry['passenger_manifest'] ?? '[]', true) ?: [];
        }

        return $entries;
    }

    public function joinWaitlist(int $userId, int $routeId, array $passengers, string $preferredClass = 'economy', string $note = ''): array {
        $this->ensureSchema();

        $routeId = (int)$routeId;
        $userId = (int)$userId;

        if ($routeId <= 0 || $userId <= 0) {
            return ['success' => false, 'message' => 'Select a valid route.'];
        }

        if (!isset(self::FARE_MULTIPLIERS[$preferredClass])) {
            $preferredClass = 'economy';
        }

        $route = $this->db->selectRow(
            "SELECT r.*, t.train_name
             FROM routes r
             JOIN trains t ON r.train_id = t.train_id
             WHERE r.route_id = {$routeId}"
        );

        if (!$route || ($route['status'] ?? '') !== 'scheduled') {
            return ['success' => false, 'message' => 'This route is not currently open for waitlisting.'];
        }

        $departureTs = strtotime(($route['journey_date'] ?? date('Y-m-d')) . ' ' . ($route['departure_time'] ?? '00:00:00'));
        if ($departureTs <= time()) {
            return ['success' => false, 'message' => 'This train has already departed.'];
        }

        $activeEntry = $this->db->selectRow(
            "SELECT waitlist_id
             FROM waitlist_entries
             WHERE route_id = {$routeId}
               AND user_id = {$userId}
               AND queue_status IN ('waitlist', 'rac')
             LIMIT 1"
        );
        if ($activeEntry) {
            return ['success' => false, 'message' => 'You already have an active waitlist request for this route.'];
        }

        $cleanPassengers = [];
        foreach ($passengers as $index => $passenger) {
            $name = trim((string)($passenger['passenger_name'] ?? ''));
            if ($name === '') {
                return ['success' => false, 'message' => 'Passenger names are required for all waitlist entries.'];
            }

            $cleanPassengers[] = [
                'passenger_name' => $name,
                'passenger_age' => isset($passenger['passenger_age']) && $passenger['passenger_age'] !== ''
                    ? max(1, min(120, (int)$passenger['passenger_age']))
                    : null,
                'passenger_gender' => in_array(($passenger['passenger_gender'] ?? ''), ['M', 'F', 'Other'], true)
                    ? $passenger['passenger_gender']
                    : 'Other',
            ];
        }

        $passengerCount = count($cleanPassengers);
        if ($passengerCount < 1 || $passengerCount > 6) {
            return ['success' => false, 'message' => 'You can request between 1 and 6 passengers on the waitlist.'];
        }

        if ($this->countAvailableSeats($routeId) >= $passengerCount) {
            return ['success' => false, 'message' => 'Seats are still available on this route. Use the seat selection booking flow instead.'];
        }

        $conn = $this->db->getConnection();
        $manifest = $conn->real_escape_string(json_encode($cleanPassengers));
        $noteValue = trim($note);
        $noteSql = $noteValue !== '' ? "'" . $conn->real_escape_string($noteValue) . "'" : 'NULL';
        $preferredClassEscaped = $conn->real_escape_string($preferredClass);

        $inserted = $conn->query(
            "INSERT INTO waitlist_entries
                (route_id, user_id, passenger_manifest, passenger_count, preferred_class, queue_status, queue_position, note)
             VALUES
                ({$routeId}, {$userId}, '{$manifest}', {$passengerCount}, '{$preferredClassEscaped}', 'waitlist', NULL, {$noteSql})"
        );

        if (!$inserted) {
            return ['success' => false, 'message' => 'Unable to add this request to the waitlist right now.'];
        }

        $waitlistId = (int)$conn->insert_id;
        $this->recomputeWaitlistPositions($routeId);
        $this->updateRacStatuses($routeId);

        $entry = $this->db->selectRow("SELECT queue_position, queue_status FROM waitlist_entries WHERE waitlist_id = {$waitlistId}");
        $position = (int)($entry['queue_position'] ?? 0);
        $queueStatus = $entry['queue_status'] ?? 'waitlist';

        $routeLabel = trim(($route['departure_city'] ?? '') . ' to ' . ($route['arrival_city'] ?? ''));
        $this->insertNotification(
            $userId,
            'Waitlist request received for ' . $routeLabel . '. Current status: ' . strtoupper($queueStatus) . ($position > 0 ? ' | Position ' . $position : '') . '.'
        );
        $this->log(
            'JOIN_WAITLIST',
            'waitlist',
            'Added waitlist request for route #' . $routeId . ' with ' . $passengerCount . ' passenger(s).',
            $waitlistId,
            '',
            json_encode(['route_id' => $routeId, 'passenger_count' => $passengerCount, 'preferred_class' => $preferredClass, 'queue_status' => $queueStatus])
        );

        return [
            'success' => true,
            'message' => $queueStatus === 'rac'
                ? 'Request added to RAC. You are next in line for confirmation when more seats open.'
                : 'Request added to the waitlist successfully.',
            'position' => $position,
            'queue_status' => $queueStatus,
        ];
    }

    public function cancelWaitlistEntry(int $waitlistId, int $userId, string $role): array {
        $this->ensureSchema();

        $waitlistId = (int)$waitlistId;
        $userId = (int)$userId;

        $entry = $this->db->selectRow("SELECT * FROM waitlist_entries WHERE waitlist_id = {$waitlistId}");
        if (!$entry) {
            return ['success' => false, 'message' => 'Waitlist entry not found.'];
        }

        if ($role === ROLE_USER && (int)$entry['user_id'] !== $userId) {
            return ['success' => false, 'message' => 'You are not allowed to cancel this waitlist entry.'];
        }

        if (in_array($entry['queue_status'], ['confirmed', 'cancelled'], true)) {
            return ['success' => false, 'message' => 'This waitlist entry is no longer active.'];
        }

        $updated = $this->db->query(
            "UPDATE waitlist_entries
             SET queue_status = 'cancelled', queue_position = NULL, updated_at = NOW()
             WHERE waitlist_id = {$waitlistId}"
        );

        if (!$updated) {
            return ['success' => false, 'message' => 'Unable to cancel this waitlist entry right now.'];
        }

        $routeId = (int)$entry['route_id'];
        $this->recomputeWaitlistPositions($routeId);
        $this->updateRacStatuses($routeId);

        $this->insertNotification((int)$entry['user_id'], 'Your waitlist request for route #' . $routeId . ' has been cancelled.');
        $this->log(
            'CANCEL_WAITLIST',
            'waitlist',
            'Cancelled waitlist entry #' . $waitlistId . '.',
            $waitlistId,
            json_encode(['queue_status' => $entry['queue_status']]),
            json_encode(['queue_status' => 'cancelled'])
        );

        return ['success' => true, 'message' => 'Waitlist entry cancelled successfully.'];
    }

    public function processWaitlist(int $routeId): array {
        $this->ensureSchema();

        $routeId = (int)$routeId;
        if ($routeId <= 0) {
            return ['success' => false, 'message' => 'Invalid route selected.'];
        }

        $route = $this->db->selectRow(
            "SELECT r.*, t.train_name
             FROM routes r
             JOIN trains t ON r.train_id = t.train_id
             WHERE r.route_id = {$routeId}"
        );
        if (!$route) {
            return ['success' => false, 'message' => 'Route not found.'];
        }

        $entries = $this->db->select(
            "SELECT *
             FROM waitlist_entries
             WHERE route_id = {$routeId}
               AND queue_status IN ('waitlist', 'rac')
             ORDER BY COALESCE(queue_position, 999999) ASC, created_at ASC"
        ) ?: [];

        if (empty($entries)) {
            $this->recomputeWaitlistPositions($routeId);
            $this->updateRacStatuses($routeId);
            return ['success' => true, 'promoted' => 0, 'message' => 'No active waitlist entries for this route.'];
        }

        require_once __DIR__ . '/Booking.php';

        $conn = $this->db->getConnection();
        $promoted = 0;

        foreach ($entries as $entry) {
            $availableSeats = $this->countAvailableSeats($routeId);
            $passengerCount = (int)($entry['passenger_count'] ?? 0);
            if ($availableSeats <= 0 || $availableSeats < $passengerCount) {
                break;
            }

            $manifest = json_decode($entry['passenger_manifest'] ?? '[]', true) ?: [];
            if (count($manifest) !== $passengerCount) {
                continue;
            }

            $seatPool = $this->fetchCandidateSeats($routeId, (string)($entry['preferred_class'] ?? 'economy'), $passengerCount);
            if (count($seatPool) < $passengerCount) {
                break;
            }

            $seatsData = [];
            foreach ($manifest as $index => $passenger) {
                $seat = $seatPool[$index];
                $seatsData[] = [
                    'seat_id' => (int)$seat['seat_id'],
                    'passenger_name' => trim((string)($passenger['passenger_name'] ?? ('Passenger ' . ($index + 1)))),
                    'passenger_age' => isset($passenger['passenger_age']) && $passenger['passenger_age'] !== null
                        ? max(1, min(120, (int)$passenger['passenger_age']))
                        : null,
                    'passenger_gender' => in_array(($passenger['passenger_gender'] ?? ''), ['M', 'F', 'Other'], true)
                        ? $passenger['passenger_gender']
                        : 'Other',
                ];
            }

            $bookingObj = new Booking($this->db);
            $result = $bookingObj->createBooking((int)$entry['user_id'], $routeId, $seatsData);
            if (!($result['success'] ?? false)) {
                break;
            }

            $bookingId = (int)($result['booking_id'] ?? 0);
            $fare = $this->calculateSeatFare($routeId, $seatPool);
            $conn->query(
                "UPDATE bookings
                 SET total_fare = {$fare}, booking_status = 'confirmed'
                 WHERE booking_id = {$bookingId}"
            );
            $conn->query(
                "UPDATE waitlist_entries
                 SET queue_status = 'confirmed',
                     queue_position = NULL,
                     linked_booking_id = {$bookingId},
                     auto_promoted_at = NOW()
                 WHERE waitlist_id = " . (int)$entry['waitlist_id']
            );

            $booking = $this->db->selectRow("SELECT booking_reference FROM bookings WHERE booking_id = {$bookingId}");
            $routeLabel = trim(($route['departure_city'] ?? '') . ' to ' . ($route['arrival_city'] ?? ''));
            $this->insertNotification(
                (int)$entry['user_id'],
                'Seats are now available. Your waitlist request for ' . $routeLabel . ' has been auto-confirmed with booking ' . ($booking['booking_reference'] ?? ('#' . $bookingId)) . '.'
            );
            $this->log(
                'AUTO_CONFIRM_WAITLIST',
                'waitlist',
                'Auto-confirmed waitlist entry #' . (int)$entry['waitlist_id'] . ' into booking #' . $bookingId . '.',
                (int)$entry['waitlist_id'],
                json_encode(['queue_status' => $entry['queue_status']]),
                json_encode(['queue_status' => 'confirmed', 'linked_booking_id' => $bookingId])
            );

            $promoted++;
        }

        $this->recomputeWaitlistPositions($routeId);
        $this->updateRacStatuses($routeId);

        return [
            'success' => true,
            'promoted' => $promoted,
            'message' => $promoted > 0
                ? $promoted . ' waitlist entr' . ($promoted === 1 ? 'y was' : 'ies were') . ' auto-confirmed.'
                : 'No waitlist entries could be auto-confirmed yet.',
        ];
    }

    private function seedStationsFromRoutes(): void {
        $cities = $this->db->select(
            "SELECT DISTINCT city_name
             FROM (
                SELECT TRIM(departure_city) AS city_name FROM routes
                UNION
                SELECT TRIM(arrival_city) AS city_name FROM routes
             ) city_index
             WHERE city_name <> ''
             ORDER BY city_name ASC"
        ) ?: [];

        if (empty($cities)) {
            return;
        }

        $existingStations = $this->db->select('SELECT city, station_code FROM stations') ?: [];
        $existingCities = [];
        $usedCodes = [];
        foreach ($existingStations as $station) {
            $existingCities[strtolower($station['city'])] = true;
            $usedCodes[strtoupper($station['station_code'])] = true;
        }

        $conn = $this->db->getConnection();
        foreach ($cities as $row) {
            $city = trim((string)($row['city_name'] ?? ''));
            if ($city === '' || isset($existingCities[strtolower($city)])) {
                continue;
            }

            $code = $this->generateStationCode($city, $usedCodes);
            $cityEscaped = $conn->real_escape_string($city);
            $codeEscaped = $conn->real_escape_string($code);
            $conn->query(
                "INSERT INTO stations (station_name, station_code, city, province, is_active)
                 VALUES ('{$cityEscaped}', '{$codeEscaped}', '{$cityEscaped}', '', 1)"
            );
            $existingCities[strtolower($city)] = true;
        }
    }

    private function generateStationCode(string $city, array &$usedCodes): string {
        $presets = [
            'karachi' => 'KHI',
            'lahore' => 'LHE',
            'islamabad' => 'ISB',
            'rawalpindi' => 'RWP',
            'peshawar' => 'PEW',
            'quetta' => 'QTA',
            'multan' => 'MUX',
            'faisalabad' => 'LYP',
            'hyderabad' => 'HDD',
            'sukkur' => 'SKZ',
            'sialkot' => 'SKT',
            'bahawalpur' => 'BHV',
            'abbottabad' => 'ABT',
        ];

        $cityKey = strtolower($city);
        $baseCode = $presets[$cityKey] ?? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $city), 0, 3));
        if ($baseCode === '') {
            $baseCode = 'STN';
        }

        $candidate = $baseCode;
        $suffix = 1;
        while (isset($usedCodes[$candidate])) {
            $candidate = substr($baseCode, 0, 8) . $suffix;
            $suffix++;
        }

        $usedCodes[$candidate] = true;
        return $candidate;
    }

    private function countAvailableSeats(int $routeId): int {
        $seatRows = $this->db->selectRow("SELECT COUNT(*) AS cnt FROM seats WHERE route_id = {$routeId}");
        $seatCount = (int)($seatRows['cnt'] ?? 0);
        if ($seatCount > 0) {
            $available = $this->db->selectRow(
                "SELECT COUNT(*) AS cnt
                 FROM seats
                 WHERE route_id = {$routeId} AND status = 'available'"
            );
            return (int)($available['cnt'] ?? 0);
        }

        $route = $this->db->selectRow("SELECT available_seats FROM routes WHERE route_id = {$routeId}");
        return (int)($route['available_seats'] ?? 0);
    }

    private function fetchCandidateSeats(int $routeId, string $preferredClass, int $limit): array {
        $conn = $this->db->getConnection();
        $preferredClass = isset(self::FARE_MULTIPLIERS[$preferredClass]) ? $preferredClass : 'economy';
        $preferredEscaped = $conn->real_escape_string($preferredClass);
        $limit = max(1, $limit);

        $query =
            "SELECT seat_id, seat_type, seat_number
             FROM seats
             WHERE route_id = {$routeId} AND status = 'available'
             ORDER BY CASE WHEN seat_type = '{$preferredEscaped}' THEN 0 ELSE 1 END,
                      CASE seat_type
                        WHEN 'economy' THEN 0
                        WHEN 'premium' THEN 1
                        ELSE 2
                      END,
                      seat_number ASC
             LIMIT {$limit}";

        return $this->db->select($query) ?: [];
    }

    private function calculateSeatFare(int $routeId, array $seatPool): float {
        $route = $this->db->selectRow("SELECT base_fare FROM routes WHERE route_id = {$routeId}");
        $baseFare = (float)($route['base_fare'] ?? 0);

        $multiplierTotal = 0.0;
        foreach ($seatPool as $seat) {
            $seatType = $seat['seat_type'] ?? 'economy';
            $multiplierTotal += self::FARE_MULTIPLIERS[$seatType] ?? self::FARE_MULTIPLIERS['economy'];
        }

        return round($baseFare * $multiplierTotal, 2);
    }

    private function recomputeWaitlistPositions(int $routeId): void {
        $routeId = (int)$routeId;
        $this->db->query(
            "UPDATE waitlist_entries
             SET queue_position = NULL
             WHERE route_id = {$routeId} AND queue_status IN ('confirmed', 'cancelled')"
        );

        $entries = $this->db->select(
            "SELECT waitlist_id
             FROM waitlist_entries
             WHERE route_id = {$routeId} AND queue_status IN ('waitlist', 'rac')
             ORDER BY created_at ASC, waitlist_id ASC"
        ) ?: [];

        $position = 1;
        foreach ($entries as $entry) {
            $this->db->query(
                "UPDATE waitlist_entries
                 SET queue_position = {$position}
                 WHERE waitlist_id = " . (int)$entry['waitlist_id']
            );
            $position++;
        }
    }

    private function updateRacStatuses(int $routeId): void {
        $routeId = (int)$routeId;
        $this->db->query(
            "UPDATE waitlist_entries
             SET queue_status = 'waitlist'
             WHERE route_id = {$routeId} AND queue_status = 'rac'"
        );

        $availableSeats = $this->countAvailableSeats($routeId);
        if ($availableSeats <= 0) {
            return;
        }

        $candidate = $this->db->selectRow(
            "SELECT waitlist_id, passenger_count
             FROM waitlist_entries
             WHERE route_id = {$routeId} AND queue_status = 'waitlist'
             ORDER BY queue_position ASC, created_at ASC
             LIMIT 1"
        );

        if ($candidate && $availableSeats < (int)$candidate['passenger_count']) {
            $this->db->query(
                "UPDATE waitlist_entries
                 SET queue_status = 'rac'
                 WHERE waitlist_id = " . (int)$candidate['waitlist_id']
            );
        }
    }

    private function insertNotification(int $userId, string $message): void {
        $userId = (int)$userId;
        if ($userId <= 0 || trim($message) === '') {
            return;
        }

        $conn = $this->db->getConnection();
        $messageEscaped = $conn->real_escape_string($message);
        $conn->query(
            "INSERT INTO notifications (user_id, message, is_read)
             VALUES ({$userId}, '{$messageEscaped}', 0)"
        );
    }

    private function log(string $action, string $module, string $description, ?int $recordId = null, string $oldValue = '', string $newValue = ''): void {
        if (!class_exists('AuditLog') && file_exists(__DIR__ . '/AuditLog.php')) {
            require_once __DIR__ . '/AuditLog.php';
        }

        if (class_exists('AuditLog')) {
            AuditLog::log($this->db, $action, $module, $description, $recordId, $oldValue, $newValue);
        }
    }
}

?>
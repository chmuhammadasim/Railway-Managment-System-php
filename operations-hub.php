<?php

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/AuditLog.php';
require_once 'src/classes/Operations.php';

if (!User::isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$db = new Database();
$db->connect();
$conn = $db->getConnection();

$role = $_SESSION['role'] ?? ROLE_USER;

// Operations Hub is for admin only
if ($role !== ROLE_ADMIN) {
    header('Location: dashboard.php');
    exit();
}
$userId = (int)($_SESSION['user_id'] ?? 0);
$userObj = new User($db);
$user = $userObj->getUserById($userId);
$operations = new Operations($db);
$operations->ensureSchema();

$flashKey = 'operations_hub_flash';
$csrfSessionKey = 'operations_hub_csrf';

if (empty($_SESSION[$csrfSessionKey])) {
    $_SESSION[$csrfSessionKey] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION[$csrfSessionKey];

function operationsHubRedirect(string $tab, string $type, string $message, string $flashKey): void {
    $_SESSION[$flashKey] = [
        'tab' => $tab,
        'type' => $type,
        'message' => $message,
    ];
    header('Location: operations-hub.php?tab=' . urlencode($tab));
    exit();
}

function operationsHubPassengersFromPost(int $count): array {
    $names = $_POST['passenger_name'] ?? [];
    $ages = $_POST['passenger_age'] ?? [];
    $genders = $_POST['passenger_gender'] ?? [];
    $passengers = [];

    for ($index = 0; $index < $count; $index++) {
        $passengers[] = [
            'passenger_name' => trim((string)($names[$index] ?? '')),
            'passenger_age' => $ages[$index] ?? null,
            'passenger_gender' => $genders[$index] ?? 'Other',
        ];
    }

    return $passengers;
}

function operationsHubBadgeClass(string $status, array $map): string {
    return $map[$status] ?? 'bg-secondary-subtle text-secondary';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tab = $_POST['return_tab'] ?? 'overview';

    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        operationsHubRedirect($tab, 'danger', 'Your session expired. Please try again.', $flashKey);
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add_station') {
        if ($role !== ROLE_ADMIN) {
            operationsHubRedirect('stations', 'danger', 'Only administrators can manage stations.', $flashKey);
        }

        $stationName = trim($_POST['station_name'] ?? '');
        $stationCode = strtoupper(trim($_POST['station_code'] ?? ''));
        $city = trim($_POST['city'] ?? '');
        $province = trim($_POST['province'] ?? '');

        if ($stationName === '' || $stationCode === '' || $city === '') {
            operationsHubRedirect('stations', 'danger', 'Station name, code, and city are required.', $flashKey);
        }

        $stationCodeEscaped = $conn->real_escape_string($stationCode);
        $cityEscaped = $conn->real_escape_string($city);
        $existingStation = $db->selectRow(
            "SELECT station_id
             FROM stations
             WHERE station_code = '{$stationCodeEscaped}' OR LOWER(city) = LOWER('{$cityEscaped}')"
        );
        if ($existingStation) {
            operationsHubRedirect('stations', 'danger', 'A station with this code or city already exists.', $flashKey);
        }

        $stationNameEscaped = $conn->real_escape_string($stationName);
        $provinceEscaped = $conn->real_escape_string($province);
        $created = $conn->query(
            "INSERT INTO stations (station_name, station_code, city, province, is_active)
             VALUES ('{$stationNameEscaped}', '{$stationCodeEscaped}', '{$cityEscaped}', '{$provinceEscaped}', 1)"
        );

        if (!$created) {
            operationsHubRedirect('stations', 'danger', 'Unable to add the station right now.', $flashKey);
        }

        AuditLog::log($db, 'CREATE_STATION', 'stations', 'Added station ' . $stationName . ' (' . $stationCode . ').', (int)$conn->insert_id);
        operationsHubRedirect('stations', 'success', 'Station added successfully.', $flashKey);
    }

    if ($action === 'toggle_station') {
        if ($role !== ROLE_ADMIN) {
            operationsHubRedirect('stations', 'danger', 'Only administrators can manage stations.', $flashKey);
        }

        $stationId = (int)($_POST['station_id'] ?? 0);
        $currentActive = (int)($_POST['current_active'] ?? 0);
        $newActive = $currentActive ? 0 : 1;

        $station = $db->selectRow("SELECT station_name, station_code FROM stations WHERE station_id = {$stationId}");
        if (!$station) {
            operationsHubRedirect('stations', 'danger', 'Station not found.', $flashKey);
        }

        $db->query("UPDATE stations SET is_active = {$newActive} WHERE station_id = {$stationId}");
        AuditLog::log(
            $db,
            'TOGGLE_STATION',
            'stations',
            'Updated station visibility for ' . ($station['station_name'] ?? 'station') . '.',
            $stationId,
            json_encode(['is_active' => $currentActive]),
            json_encode(['is_active' => $newActive])
        );
        operationsHubRedirect('stations', 'success', 'Station status updated.', $flashKey);
    }

    if ($action === 'delete_station') {
        if ($role !== ROLE_ADMIN) {
            operationsHubRedirect('stations', 'danger', 'Only administrators can manage stations.', $flashKey);
        }

        $stationId = (int)($_POST['station_id'] ?? 0);
        $station = $db->selectRow("SELECT station_name, city FROM stations WHERE station_id = {$stationId}");
        if (!$station) {
            operationsHubRedirect('stations', 'danger', 'Station not found.', $flashKey);
        }

        $cityEscaped = $conn->real_escape_string($station['city']);
        $usage = $db->selectRow(
            "SELECT COUNT(*) AS cnt
             FROM routes
             WHERE departure_city = '{$cityEscaped}' OR arrival_city = '{$cityEscaped}'"
        );
        if ((int)($usage['cnt'] ?? 0) > 0) {
            operationsHubRedirect('stations', 'danger', 'This station is still referenced by one or more routes. Deactivate it instead.', $flashKey);
        }

        $db->delete('stations', 'station_id', $stationId);
        AuditLog::log($db, 'DELETE_STATION', 'stations', 'Deleted station ' . ($station['station_name'] ?? ''), $stationId);
        operationsHubRedirect('stations', 'success', 'Station deleted successfully.', $flashKey);
    }

    if ($action === 'upsert_live_status') {
        if ($role !== ROLE_ADMIN) {
            operationsHubRedirect('live', 'danger', 'Only admins can update live train status.', $flashKey);
        }

        $routeId = (int)($_POST['route_id'] ?? 0);
        $serviceState = $_POST['service_state'] ?? 'scheduled';
        $currentStation = trim($_POST['current_station'] ?? '');
        $nextStation = trim($_POST['next_station'] ?? '');
        $delayMinutes = max(0, (int)($_POST['delay_minutes'] ?? 0));
        $statusNote = trim($_POST['status_note'] ?? '');

        $allowedStates = ['scheduled', 'boarding', 'running', 'delayed', 'arrived', 'cancelled', 'maintenance'];
        if ($routeId <= 0 || !in_array($serviceState, $allowedStates, true)) {
            operationsHubRedirect('live', 'danger', 'Select a valid route and live status.', $flashKey);
        }

        $route = $db->selectRow(
            "SELECT departure_city, arrival_city
             FROM routes
             WHERE route_id = {$routeId}"
        );
        if (!$route) {
            operationsHubRedirect('live', 'danger', 'Route not found.', $flashKey);
        }

        $serviceStateEscaped = $conn->real_escape_string($serviceState);
        $currentStationEscaped = $conn->real_escape_string($currentStation);
        $nextStationEscaped = $conn->real_escape_string($nextStation);
        $statusNoteEscaped = $conn->real_escape_string($statusNote);
        $conn->query(
            "INSERT INTO live_train_status
                (route_id, service_state, current_station, next_station, delay_minutes, status_note, updated_by)
             VALUES
                ({$routeId}, '{$serviceStateEscaped}', " . ($currentStation !== '' ? "'{$currentStationEscaped}'" : 'NULL') . ', ' .
                ($nextStation !== '' ? "'{$nextStationEscaped}'" : 'NULL') . ", {$delayMinutes}, " . ($statusNote !== '' ? "'{$statusNoteEscaped}'" : 'NULL') . ", {$userId})
             ON DUPLICATE KEY UPDATE
                service_state = VALUES(service_state),
                current_station = VALUES(current_station),
                next_station = VALUES(next_station),
                delay_minutes = VALUES(delay_minutes),
                status_note = VALUES(status_note),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP"
        );

        if ($serviceState === 'cancelled') {
            $db->query("UPDATE routes SET status = 'cancelled' WHERE route_id = {$routeId}");
        } elseif ($serviceState === 'arrived') {
            $db->query("UPDATE routes SET status = 'completed' WHERE route_id = {$routeId}");
        } else {
            $db->query("UPDATE routes SET status = 'scheduled' WHERE route_id = {$routeId} AND status <> 'cancelled'");
        }

        if ($serviceState === 'delayed' || $serviceState === 'cancelled' || $delayMinutes > 0) {
            $affectedUsers = $db->select(
                "SELECT DISTINCT user_id
                 FROM bookings
                 WHERE route_id = {$routeId} AND booking_status <> 'cancelled'"
            ) ?: [];
            $routeLabel = ($route['departure_city'] ?? '') . ' to ' . ($route['arrival_city'] ?? '');
            foreach ($affectedUsers as $affectedUser) {
                $note = 'Live update for ' . $routeLabel . ': ' . strtoupper($serviceState);
                if ($delayMinutes > 0) {
                    $note .= ' | Delay ' . $delayMinutes . ' min';
                }
                if ($statusNote !== '') {
                    $note .= ' | ' . $statusNote;
                }
                $conn->query(
                    "INSERT INTO notifications (user_id, message, is_read)
                     VALUES (" . (int)$affectedUser['user_id'] . ", '" . $conn->real_escape_string($note) . "', 0)"
                );
            }
        }

        AuditLog::log($db, 'UPDATE_LIVE_STATUS', 'operations', 'Updated live status for route #' . $routeId . '.', $routeId);
        operationsHubRedirect('live', 'success', 'Live train status updated successfully.', $flashKey);
    }

    if ($action === 'add_lost_found') {
        $recordType = $_POST['record_type'] ?? 'lost';
        if (!in_array($recordType, ['lost', 'found'], true)) {
            $recordType = 'lost';
        }
        if ($role === ROLE_USER) {
            $recordType = 'lost';
        }

        $routeId = (int)($_POST['route_id'] ?? 0);
        $routeSql = $routeId > 0 ? (string)$routeId : 'NULL';
        $itemName = trim($_POST['item_name'] ?? '');
        $category = trim($_POST['category'] ?? 'general');
        $locationHint = trim($_POST['location_hint'] ?? '');
        $contactPhone = trim($_POST['contact_phone'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($itemName === '' || $description === '') {
            operationsHubRedirect('lost-found', 'danger', 'Item name and description are required.', $flashKey);
        }

        $recordTypeEscaped = $conn->real_escape_string($recordType);
        $itemNameEscaped = $conn->real_escape_string($itemName);
        $categoryEscaped = $conn->real_escape_string($category);
        $locationEscaped = $conn->real_escape_string($locationHint);
        $phoneEscaped = $conn->real_escape_string($contactPhone);
        $descriptionEscaped = $conn->real_escape_string($description);
        $created = $conn->query(
            "INSERT INTO lost_found_items
                (record_type, route_id, reported_by, item_name, category, description, location_hint, contact_phone, status)
             VALUES
                ('{$recordTypeEscaped}', {$routeSql}, {$userId}, '{$itemNameEscaped}', '{$categoryEscaped}', '{$descriptionEscaped}', '{$locationEscaped}', '{$phoneEscaped}', 'reported')"
        );

        if (!$created) {
            operationsHubRedirect('lost-found', 'danger', 'Unable to save this lost and found report.', $flashKey);
        }

        $itemId = (int)$conn->insert_id;
        AuditLog::log($db, 'REPORT_LOST_FOUND', 'lost_found', 'Submitted a ' . $recordType . ' item report.', $itemId);
        operationsHubRedirect('lost-found', 'success', 'Lost and found report submitted successfully.', $flashKey);
    }

    if ($action === 'update_lost_found') {
        if ($role !== ROLE_ADMIN) {
            operationsHubRedirect('lost-found', 'danger', 'Only admins can update lost and found cases.', $flashKey);
        }

        $itemId = (int)($_POST['item_id'] ?? 0);
        $status = $_POST['status'] ?? 'reported';
        $resolutionNote = trim($_POST['resolution_note'] ?? '');
        $allowedStatuses = ['reported', 'under_review', 'matched', 'claimed', 'closed'];
        if ($itemId <= 0 || !in_array($status, $allowedStatuses, true)) {
            operationsHubRedirect('lost-found', 'danger', 'Choose a valid item and status.', $flashKey);
        }

        $existingItem = $db->selectRow("SELECT reported_by, status FROM lost_found_items WHERE item_id = {$itemId}");
        if (!$existingItem) {
            operationsHubRedirect('lost-found', 'danger', 'Lost and found case not found.', $flashKey);
        }

        $statusEscaped = $conn->real_escape_string($status);
        $resolutionEscaped = $conn->real_escape_string($resolutionNote);
        $db->query(
            "UPDATE lost_found_items
             SET status = '{$statusEscaped}',
                 assigned_to = {$userId},
                 resolution_note = " . ($resolutionNote !== '' ? "'{$resolutionEscaped}'" : 'NULL') . ',
                 resolved_at = ' . (in_array($status, ['claimed', 'closed'], true) ? 'NOW()' : 'NULL') . "
             WHERE item_id = {$itemId}"
        );

        if ((int)($existingItem['reported_by'] ?? 0) > 0) {
            $note = 'Your lost and found case #' . $itemId . ' is now marked as ' . strtoupper($status) . '.';
            if ($resolutionNote !== '') {
                $note .= ' ' . $resolutionNote;
            }
            $conn->query(
                "INSERT INTO notifications (user_id, message, is_read)
                 VALUES (" . (int)$existingItem['reported_by'] . ", '" . $conn->real_escape_string($note) . "', 0)"
            );
        }

        AuditLog::log(
            $db,
            'UPDATE_LOST_FOUND',
            'lost_found',
            'Updated lost and found case #' . $itemId . '.',
            $itemId,
            json_encode(['status' => $existingItem['status'] ?? 'reported']),
            json_encode(['status' => $status, 'resolution_note' => $resolutionNote])
        );
        operationsHubRedirect('lost-found', 'success', 'Lost and found case updated.', $flashKey);
    }

    if ($action === 'add_maintenance') {
        if ($role !== ROLE_ADMIN) {
            operationsHubRedirect('crew', 'danger', 'Only admins can manage maintenance schedules.', $flashKey);
        }

        $trainId = (int)($_POST['train_id'] ?? 0);
        $maintenanceType = $_POST['maintenance_type'] ?? 'inspection';
        $scheduledDate = trim($_POST['scheduled_date'] ?? '');
        $maintenanceStatus = $_POST['maintenance_status'] ?? 'scheduled';
        $assignedEmployeeId = (int)($_POST['assigned_employee_id'] ?? 0);
        $notes = trim($_POST['maintenance_notes'] ?? '');
        $allowedTypes = ['inspection', 'repair', 'cleaning', 'overhaul'];
        $allowedStatuses = ['scheduled', 'in_progress', 'completed', 'cancelled'];

        if ($trainId <= 0 || $scheduledDate === '' || !in_array($maintenanceType, $allowedTypes, true) || !in_array($maintenanceStatus, $allowedStatuses, true)) {
            operationsHubRedirect('crew', 'danger', 'Provide a valid train, maintenance type, date, and status.', $flashKey);
        }

        $maintenanceTypeEscaped = $conn->real_escape_string($maintenanceType);
        $scheduledDateEscaped = $conn->real_escape_string($scheduledDate);
        $maintenanceStatusEscaped = $conn->real_escape_string($maintenanceStatus);
        $notesEscaped = $conn->real_escape_string($notes);
        $employeeSql = $assignedEmployeeId > 0 ? (string)$assignedEmployeeId : 'NULL';

        $created = $conn->query(
            "INSERT INTO train_maintenance
                (train_id, maintenance_type, scheduled_date, status, assigned_employee_id, notes)
             VALUES
                ({$trainId}, '{$maintenanceTypeEscaped}', '{$scheduledDateEscaped}', '{$maintenanceStatusEscaped}', {$employeeSql}, " . ($notes !== '' ? "'{$notesEscaped}'" : 'NULL') . ")"
        );

        if (!$created) {
            operationsHubRedirect('crew', 'danger', 'Unable to save the maintenance entry.', $flashKey);
        }

        AuditLog::log($db, 'CREATE_MAINTENANCE', 'maintenance', 'Added maintenance schedule for train #' . $trainId . '.', (int)$conn->insert_id);
        operationsHubRedirect('crew', 'success', 'Maintenance schedule saved.', $flashKey);
    }

    if ($action === 'update_maintenance_status') {
        if ($role !== ROLE_ADMIN) {
            operationsHubRedirect('crew', 'danger', 'Only admins can manage maintenance schedules.', $flashKey);
        }

        $maintenanceId = (int)($_POST['maintenance_id'] ?? 0);
        $maintenanceStatus = $_POST['maintenance_status'] ?? 'scheduled';
        $allowedStatuses = ['scheduled', 'in_progress', 'completed', 'cancelled'];
        if ($maintenanceId <= 0 || !in_array($maintenanceStatus, $allowedStatuses, true)) {
            operationsHubRedirect('crew', 'danger', 'Select a valid maintenance status.', $flashKey);
        }

        $existingMaintenance = $db->selectRow("SELECT status FROM train_maintenance WHERE maintenance_id = {$maintenanceId}");
        if (!$existingMaintenance) {
            operationsHubRedirect('crew', 'danger', 'Maintenance record not found.', $flashKey);
        }

        $maintenanceStatusEscaped = $conn->real_escape_string($maintenanceStatus);
        $db->query("UPDATE train_maintenance SET status = '{$maintenanceStatusEscaped}' WHERE maintenance_id = {$maintenanceId}");
        AuditLog::log(
            $db,
            'UPDATE_MAINTENANCE',
            'maintenance',
            'Updated maintenance record #' . $maintenanceId . '.',
            $maintenanceId,
            json_encode(['status' => $existingMaintenance['status'] ?? 'scheduled']),
            json_encode(['status' => $maintenanceStatus])
        );
        operationsHubRedirect('crew', 'success', 'Maintenance status updated.', $flashKey);
    }

    if ($action === 'add_crew_assignment') {
        if ($role !== ROLE_ADMIN) {
            operationsHubRedirect('crew', 'danger', 'Only admins can assign crews.', $flashKey);
        }

        $routeId = (int)($_POST['route_id'] ?? 0);
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $roleTitle = trim($_POST['role_title'] ?? '');
        $shiftStart = trim($_POST['shift_start'] ?? '');
        $shiftEnd = trim($_POST['shift_end'] ?? '');
        $assignmentStatus = $_POST['assignment_status'] ?? 'assigned';
        $notes = trim($_POST['assignment_notes'] ?? '');
        $allowedStatuses = ['assigned', 'checked_in', 'completed', 'cancelled'];

        if ($routeId <= 0 || $employeeId <= 0 || $roleTitle === '' || !in_array($assignmentStatus, $allowedStatuses, true)) {
            operationsHubRedirect('crew', 'danger', 'Provide a valid route, employee, role, and status.', $flashKey);
        }

        $roleTitleEscaped = $conn->real_escape_string($roleTitle);
        $assignmentStatusEscaped = $conn->real_escape_string($assignmentStatus);
        $notesEscaped = $conn->real_escape_string($notes);
        $shiftStartSql = $shiftStart !== '' ? "'" . $conn->real_escape_string($shiftStart) . "'" : 'NULL';
        $shiftEndSql = $shiftEnd !== '' ? "'" . $conn->real_escape_string($shiftEnd) . "'" : 'NULL';

        $created = $conn->query(
            "INSERT INTO crew_assignments
                (route_id, employee_id, role_title, shift_start, shift_end, assignment_status, notes)
             VALUES
                ({$routeId}, {$employeeId}, '{$roleTitleEscaped}', {$shiftStartSql}, {$shiftEndSql}, '{$assignmentStatusEscaped}', " . ($notes !== '' ? "'{$notesEscaped}'" : 'NULL') . ")"
        );

        if (!$created) {
            operationsHubRedirect('crew', 'danger', 'Unable to save the crew assignment.', $flashKey);
        }

        AuditLog::log($db, 'CREATE_CREW_ASSIGNMENT', 'crew', 'Added crew assignment for route #' . $routeId . '.', (int)$conn->insert_id);
        operationsHubRedirect('crew', 'success', 'Crew assignment saved successfully.', $flashKey);
    }

    if ($action === 'update_crew_status') {
        if ($role !== ROLE_ADMIN) {
            operationsHubRedirect('crew', 'danger', 'Only admins can update crew assignments.', $flashKey);
        }

        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $assignmentStatus = $_POST['assignment_status'] ?? 'assigned';
        $allowedStatuses = ['assigned', 'checked_in', 'completed', 'cancelled'];
        if ($assignmentId <= 0 || !in_array($assignmentStatus, $allowedStatuses, true)) {
            operationsHubRedirect('crew', 'danger', 'Select a valid crew assignment status.', $flashKey);
        }

        $existingAssignment = $db->selectRow("SELECT assignment_status FROM crew_assignments WHERE assignment_id = {$assignmentId}");
        if (!$existingAssignment) {
            operationsHubRedirect('crew', 'danger', 'Crew assignment not found.', $flashKey);
        }

        $assignmentStatusEscaped = $conn->real_escape_string($assignmentStatus);
        $db->query("UPDATE crew_assignments SET assignment_status = '{$assignmentStatusEscaped}' WHERE assignment_id = {$assignmentId}");
        AuditLog::log(
            $db,
            'UPDATE_CREW_ASSIGNMENT',
            'crew',
            'Updated crew assignment #' . $assignmentId . '.',
            $assignmentId,
            json_encode(['assignment_status' => $existingAssignment['assignment_status'] ?? 'assigned']),
            json_encode(['assignment_status' => $assignmentStatus])
        );
        operationsHubRedirect('crew', 'success', 'Crew assignment status updated.', $flashKey);
    }

    if ($action === 'join_waitlist') {
        if ($role !== ROLE_USER) {
            operationsHubRedirect('waitlist', 'danger', 'Staff cannot join the passenger waitlist from this form.', $flashKey);
        }

        $routeId = (int)($_POST['route_id'] ?? 0);
        $preferredClass = $_POST['preferred_class'] ?? 'economy';
        $passengerCount = max(1, min(6, (int)($_POST['passenger_count'] ?? 1)));
        $note = trim($_POST['waitlist_note'] ?? '');
        $passengers = operationsHubPassengersFromPost($passengerCount);

        $result = $operations->joinWaitlist($userId, $routeId, $passengers, $preferredClass, $note);
        operationsHubRedirect('waitlist', $result['success'] ? 'success' : 'danger', $result['message'], $flashKey);
    }

    if ($action === 'cancel_waitlist') {
        $waitlistId = (int)($_POST['waitlist_id'] ?? 0);
        $result = $operations->cancelWaitlistEntry($waitlistId, $userId, $role);
        operationsHubRedirect('waitlist', $result['success'] ? 'success' : 'danger', $result['message'], $flashKey);
    }

    if ($action === 'process_waitlist') {
        if ($role !== ROLE_ADMIN) {
            operationsHubRedirect('waitlist', 'danger', 'Only admins can process the waitlist queue.', $flashKey);
        }

        $routeId = (int)($_POST['route_id'] ?? 0);
        $result = $operations->processWaitlist($routeId);
        operationsHubRedirect('waitlist', $result['success'] ? 'success' : 'danger', $result['message'], $flashKey);
    }
}

$flash = $_SESSION[$flashKey] ?? null;
if ($flash) {
    unset($_SESSION[$flashKey]);
}

$activeTab = $_GET['tab'] ?? ($flash['tab'] ?? 'overview');

$stations = $operations->getStations();
$uniqueStationCities = [];
foreach ($stations as $station) {
    if (!isset($uniqueStationCities[$station['city']])) {
        $uniqueStationCities[$station['city']] = $station;
    }
}

$upcomingRoutes = $db->select(
    "SELECT r.route_id, r.departure_city, r.arrival_city, r.journey_date, r.departure_time, r.arrival_time, r.status,
            t.train_name, t.train_number, t.train_id
     FROM routes r
     JOIN trains t ON r.train_id = t.train_id
     WHERE r.journey_date >= CURDATE()
     ORDER BY r.journey_date ASC, r.departure_time ASC
     LIMIT 80"
) ?: [];

$trainList = $role === ROLE_USER
    ? []
    : ($db->select(
        "SELECT train_id, train_name, train_number, status
         FROM trains
         ORDER BY train_name ASC"
    ) ?: []);

$employeeList = $role === ROLE_USER
    ? []
    : ($db->select(
        "SELECT user_id, full_name
         FROM users
         WHERE role = 'employee' AND is_active = 1
         ORDER BY full_name ASC"
    ) ?: []);

$userRouteIds = [];
$userTrainIds = [];
if ($role === ROLE_USER) {
    $bookedRoutes = $db->select(
        "SELECT DISTINCT r.route_id, r.train_id
         FROM bookings b
         JOIN routes r ON b.route_id = r.route_id
         WHERE b.user_id = {$userId}
           AND b.booking_status <> 'cancelled'
           AND r.journey_date >= CURDATE()"
    ) ?: [];
    $userRouteIds = array_map('intval', array_column($bookedRoutes, 'route_id'));
    $userTrainIds = array_map('intval', array_column($bookedRoutes, 'train_id'));
}

$liveWhere = "WHERE r.journey_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
if ($role === ROLE_USER && !empty($userRouteIds)) {
    $liveWhere .= ' AND (r.route_id IN (' . implode(',', $userRouteIds) . ') OR r.journey_date = CURDATE())';
}

$liveBoard = $db->select(
    "SELECT r.route_id, r.departure_city, r.arrival_city, r.journey_date, r.departure_time, r.arrival_time,
            t.train_name, t.train_number,
            COALESCE(l.service_state,
                CASE
                    WHEN r.status = 'cancelled' THEN 'cancelled'
                    WHEN r.status = 'completed' THEN 'arrived'
                    ELSE 'scheduled'
                END
            ) AS service_state,
            COALESCE(l.current_station, r.departure_city) AS current_station,
            COALESCE(l.next_station, r.arrival_city) AS next_station,
            COALESCE(l.delay_minutes, 0) AS delay_minutes,
            l.status_note,
            l.updated_at,
            updater.full_name AS updated_by_name
     FROM routes r
     JOIN trains t ON r.train_id = t.train_id
     LEFT JOIN live_train_status l ON l.route_id = r.route_id
     LEFT JOIN users updater ON l.updated_by = updater.user_id
     {$liveWhere}
     ORDER BY r.journey_date ASC, r.departure_time ASC
     LIMIT 16"
) ?: [];

$waitlistEntries = $operations->getWaitlistEntries($role, $userId);

$lostWhere = $role === ROLE_USER ? 'WHERE lf.reported_by = ' . $userId : '';
$lostFoundItems = $db->select(
    "SELECT lf.*, r.departure_city, r.arrival_city, r.journey_date,
            t.train_name,
            reporter.full_name AS reported_by_name,
            assignee.full_name AS assigned_to_name
     FROM lost_found_items lf
     LEFT JOIN routes r ON lf.route_id = r.route_id
     LEFT JOIN trains t ON r.train_id = t.train_id
     LEFT JOIN users reporter ON lf.reported_by = reporter.user_id
     LEFT JOIN users assignee ON lf.assigned_to = assignee.user_id
     {$lostWhere}
     ORDER BY CASE lf.status
                WHEN 'reported' THEN 0
                WHEN 'under_review' THEN 1
                WHEN 'matched' THEN 2
                ELSE 3
              END,
              lf.created_at DESC
     LIMIT 20"
) ?: [];

$maintenanceWhere = '';
if ($role === ROLE_USER && !empty($userTrainIds)) {
    $maintenanceWhere = 'WHERE tm.train_id IN (' . implode(',', $userTrainIds) . ')';
}

$maintenanceRows = $db->select(
    "SELECT tm.*, t.train_name, t.train_number, assignee.full_name AS assigned_employee_name
     FROM train_maintenance tm
     JOIN trains t ON tm.train_id = t.train_id
     LEFT JOIN users assignee ON tm.assigned_employee_id = assignee.user_id
     {$maintenanceWhere}
     ORDER BY tm.scheduled_date ASC, tm.created_at DESC
     LIMIT 18"
) ?: [];

$crewWhere = '';
if ($role === ROLE_USER && !empty($userRouteIds)) {
    $crewWhere = 'WHERE ca.route_id IN (' . implode(',', $userRouteIds) . ')';
}

$crewAssignments = $db->select(
    "SELECT ca.*, r.departure_city, r.arrival_city, r.journey_date, r.departure_time,
            t.train_name, t.train_number,
            staff.full_name AS employee_name
     FROM crew_assignments ca
     JOIN routes r ON ca.route_id = r.route_id
     JOIN trains t ON r.train_id = t.train_id
     JOIN users staff ON ca.employee_id = staff.user_id
     {$crewWhere}
     ORDER BY r.journey_date ASC, r.departure_time ASC, ca.created_at DESC
     LIMIT 18"
) ?: [];

$stationsRows = $role === ROLE_ADMIN
    ? ($db->select(
        "SELECT s.*, COALESCE(route_counts.total_routes, 0) AS total_routes
         FROM stations s
         LEFT JOIN (
            SELECT city_name, COUNT(*) AS total_routes
            FROM (
                SELECT departure_city AS city_name FROM routes
                UNION ALL
                SELECT arrival_city AS city_name FROM routes
            ) city_routes
            GROUP BY city_name
         ) route_counts ON route_counts.city_name = s.city
         ORDER BY s.is_active DESC, s.city ASC, s.station_name ASC"
    ) ?: [])
    : [];

$activityWhere = $role === ROLE_ADMIN ? '' : 'WHERE al.user_id = ' . $userId;
$activityLogs = $db->select(
    "SELECT al.*, u.full_name
     FROM audit_logs al
     LEFT JOIN users u ON al.user_id = u.user_id
     {$activityWhere}
     ORDER BY al.created_at DESC
     LIMIT 25"
) ?: [];

$activeWaitlistCount = 0;
foreach ($waitlistEntries as $entry) {
    if (in_array($entry['queue_status'], ['waitlist', 'rac'], true)) {
        $activeWaitlistCount++;
    }
}

$delayedRouteCount = 0;
foreach ($liveBoard as $route) {
    if (($route['service_state'] ?? '') === 'delayed' || (int)($route['delay_minutes'] ?? 0) > 0) {
        $delayedRouteCount++;
    }
}

$openLostCount = 0;
foreach ($lostFoundItems as $item) {
    if (!in_array($item['status'], ['claimed', 'closed'], true)) {
        $openLostCount++;
    }
}

$maintenanceDueCount = 0;
foreach ($maintenanceRows as $maintenance) {
    if (in_array($maintenance['status'], ['scheduled', 'in_progress'], true)) {
        $maintenanceDueCount++;
    }
}

$quickLinks = [];
if ($role === ROLE_USER) {
    $quickLinks = [
        ['href' => 'bookings.php', 'icon' => 'bi-journal-text', 'title' => 'Booking History', 'text' => 'Review your upcoming and past journeys.'],
        ['href' => 'my-cargo.php', 'icon' => 'bi-box-seam', 'title' => 'Cargo & Luggage', 'text' => 'Book luggage, parcels, and track shipments.'],
        ['href' => 'index.php', 'icon' => 'bi-grid-3x3-gap-fill', 'title' => 'Seat Map Booking', 'text' => 'Use the visual coach layout when booking tickets.'],
        ['href' => 'operations-hub.php?tab=live', 'icon' => 'bi-broadcast-pin', 'title' => 'Live Status', 'text' => 'Track platform updates, delays, and route progress.'],
    ];
} else {
    $quickLinks = [
        ['href' => 'manage-routes.php', 'icon' => 'bi-signpost-split-fill', 'title' => 'Route Control', 'text' => 'Manage schedules and routes between stations.'],
        ['href' => 'manage-trains.php', 'icon' => 'bi-train-front-fill', 'title' => 'Fleet Control', 'text' => 'Track fleet status, maintenance, and availability.'],
        ['href' => 'operations-hub.php?tab=stations', 'icon' => 'bi-building', 'title' => 'Stations', 'text' => 'Add stations with codes and keep the network consistent.'],
        ['href' => 'audit-logs.php', 'icon' => 'bi-clock-history', 'title' => 'Audit Trail', 'text' => 'Review who changed what and when.'],
    ];
}

$liveBadgeMap = [
    'scheduled' => 'bg-secondary-subtle text-secondary',
    'boarding' => 'bg-info-subtle text-info-emphasis',
    'running' => 'bg-success-subtle text-success-emphasis',
    'delayed' => 'bg-warning-subtle text-warning-emphasis',
    'arrived' => 'bg-primary-subtle text-primary-emphasis',
    'cancelled' => 'bg-danger-subtle text-danger-emphasis',
    'maintenance' => 'bg-dark-subtle text-dark-emphasis',
];
$waitBadgeMap = [
    'waitlist' => 'bg-secondary-subtle text-secondary',
    'rac' => 'bg-warning-subtle text-warning-emphasis',
    'confirmed' => 'bg-success-subtle text-success-emphasis',
    'cancelled' => 'bg-danger-subtle text-danger-emphasis',
];
$lostBadgeMap = [
    'reported' => 'bg-secondary-subtle text-secondary',
    'under_review' => 'bg-warning-subtle text-warning-emphasis',
    'matched' => 'bg-info-subtle text-info-emphasis',
    'claimed' => 'bg-success-subtle text-success-emphasis',
    'closed' => 'bg-dark-subtle text-dark-emphasis',
];
$maintenanceBadgeMap = [
    'scheduled' => 'bg-secondary-subtle text-secondary',
    'in_progress' => 'bg-warning-subtle text-warning-emphasis',
    'completed' => 'bg-success-subtle text-success-emphasis',
    'cancelled' => 'bg-danger-subtle text-danger-emphasis',
    'assigned' => 'bg-secondary-subtle text-secondary',
    'checked_in' => 'bg-info-subtle text-info-emphasis',
];

$pageTitle = 'Operations Hub';
$hideMainNavbar = true;
require_once 'inc/header.php';
?>

<style>
.ops-page {
    background: linear-gradient(180deg, #f8fafc 0%, #eef2ff 22%, #f8fafc 100%);
    min-height: calc(100vh - 64px);
    padding-bottom: 3rem;
}
.ops-hero {
    background: linear-gradient(135deg, #10203d 0%, #16335f 55%, #2753a6 100%);
    color: #fff;
    padding: 2.4rem 0 4rem;
    overflow: hidden;
}
.ops-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background-image: radial-gradient(rgba(255,255,255,.07) 1px, transparent 1px);
    background-size: 22px 22px;
    pointer-events: none;
}
.ops-hero .container {
    position: relative;
    z-index: 1;
}
.ops-hero h1 {
    font-size: clamp(1.6rem, 3vw, 2.4rem);
    font-weight: 800;
    margin-bottom: .35rem;
}
.ops-hero p {
    color: rgba(255,255,255,.72);
    max-width: 760px;
    margin-bottom: 0;
}
.ops-summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
    margin-top: 1.5rem;
}
.ops-summary-card {
    background: rgba(255,255,255,.12);
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 18px;
    padding: 1rem 1.1rem;
    backdrop-filter: blur(10px);
    box-shadow: 0 12px 30px rgba(0,0,0,.16);
}
.ops-summary-card .label {
    font-size: .75rem;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: rgba(255,255,255,.62);
}
.ops-summary-card .value {
    font-size: 2rem;
    font-weight: 800;
    line-height: 1;
    margin-top: .45rem;
}
.ops-summary-card .sub {
    font-size: .78rem;
    color: rgba(255,255,255,.72);
    margin-top: .35rem;
}
.ops-content {
    margin-top: -2.2rem;
}
.ops-shell {
    background: #fff;
    border-radius: 24px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 18px 40px rgba(15, 23, 42, .08);
    overflow: hidden;
}
.ops-tabbar {
    display: flex;
    gap: .75rem;
    flex-wrap: wrap;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #eef2f7;
    background: #f8fafc;
}
.ops-tabbar a {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    padding: .55rem 1rem;
    border-radius: 999px;
    text-decoration: none;
    color: #475569;
    background: #fff;
    border: 1px solid #dbe1ea;
    font-weight: 600;
    font-size: .82rem;
}
.ops-tabbar a.active {
    color: #0f172a;
    background: #e0f2fe;
    border-color: #bae6fd;
}
.ops-panel {
    padding: 1.4rem;
}
.ops-grid {
    display: grid;
    grid-template-columns: 1.15fr .95fr;
    gap: 1rem;
    margin-bottom: 1rem;
}
.ops-grid.single {
    grid-template-columns: 1fr;
}
.ops-card {
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1.1rem 1.15rem;
    background: #fff;
    box-shadow: 0 10px 25px rgba(15, 23, 42, .04);
}
.ops-card h3 {
    font-size: .98rem;
    font-weight: 700;
    margin-bottom: .85rem;
    color: #0f172a;
}
.ops-card h4 {
    font-size: .88rem;
    font-weight: 700;
    margin-bottom: .8rem;
    color: #0f172a;
}
.ops-muted {
    color: #64748b;
    font-size: .84rem;
}
.ops-quick-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: .9rem;
}
.ops-quick-link {
    display: block;
    text-decoration: none;
    color: inherit;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1rem;
    background: #fff;
    transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
}
.ops-quick-link:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 28px rgba(37, 99, 235, .12);
    border-color: #bfdbfe;
    color: inherit;
}
.ops-quick-link .icon {
    width: 44px;
    height: 44px;
    border-radius: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #eff6ff;
    color: #2563eb;
    font-size: 1.2rem;
    margin-bottom: .8rem;
}
.ops-quick-link .title {
    font-weight: 700;
    color: #0f172a;
    margin-bottom: .3rem;
}
.ops-quick-link .text {
    font-size: .8rem;
    color: #64748b;
}
.ops-table {
    width: 100%;
    border-collapse: collapse;
}
.ops-table th {
    font-size: .72rem;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: #64748b;
    padding: .75rem .65rem;
    border-bottom: 1px solid #e5e7eb;
    text-align: left;
    white-space: nowrap;
}
.ops-table td {
    padding: .85rem .65rem;
    border-bottom: 1px solid #f1f5f9;
    font-size: .84rem;
    vertical-align: top;
}
.ops-table tbody tr:hover td {
    background: #fafcff;
}
.ops-pill {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    padding: .24rem .72rem;
    border-radius: 999px;
    font-size: .74rem;
    font-weight: 700;
}
.ops-route {
    font-weight: 700;
    color: #0f172a;
}
.ops-subline {
    color: #64748b;
    font-size: .76rem;
    margin-top: .15rem;
}
.ops-list {
    display: flex;
    flex-direction: column;
    gap: .85rem;
}
.ops-list-item {
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: .9rem 1rem;
    background: #fff;
}
.ops-list-item .title {
    font-weight: 700;
    color: #0f172a;
}
.ops-list-item .meta {
    font-size: .78rem;
    color: #64748b;
    margin-top: .25rem;
}
.ops-form-note {
    font-size: .76rem;
    color: #64748b;
}
.ops-empty {
    padding: 2rem 1rem;
    text-align: center;
    border: 1px dashed #cbd5e1;
    border-radius: 18px;
    color: #64748b;
    background: #f8fafc;
}
.ops-activity-row {
    padding: .85rem 0;
    border-bottom: 1px solid #eef2f7;
}
.ops-activity-row:last-child {
    border-bottom: none;
}
.ops-activity-row .head {
    display: flex;
    justify-content: space-between;
    gap: .75rem;
    font-size: .82rem;
    color: #0f172a;
    font-weight: 700;
}
.ops-activity-row .body {
    margin-top: .28rem;
    font-size: .8rem;
    color: #64748b;
}
@media (max-width: 1100px) {
    .ops-summary-grid,
    .ops-quick-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .ops-grid {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 768px) {
    .ops-summary-grid,
    .ops-quick-grid {
        grid-template-columns: 1fr;
    }
    .ops-panel {
        padding: 1rem;
    }
}

/* ── Sidebar shell ─────────────────────────────── */
.ops-wrap { display:flex; min-height:calc(100vh - 64px); }
.ops-sidebar {
    width:230px; flex-shrink:0;
    background:linear-gradient(180deg,#1a2e4a 0%,#0f1e32 100%);
    color:#c8d6e8; display:flex; flex-direction:column;
    position:sticky; overflow-y:auto;
    z-index:10;
}
.ops-sidebar .sb-brand { padding:1.2rem 1.4rem .9rem; border-bottom:1px solid rgba(255,255,255,.08); }
.ops-sidebar .sb-brand span { font-size:.68rem; text-transform:uppercase; letter-spacing:1.5px; opacity:.5; display:block; margin-bottom:.25rem; }
.ops-sidebar .sb-brand strong { font-size:.95rem; color:#fff; }
.ops-sidebar nav { flex:1; padding:.6rem 0; }
.ops-sidebar nav a {
    display:flex; align-items:center; gap:.7rem;
    padding:.6rem 1.4rem; color:#c8d6e8; text-decoration:none;
    font-size:.85rem; font-weight:500; transition:all .2s;
    border-left:3px solid transparent;
}
.ops-sidebar nav a:hover, .ops-sidebar nav a.active {
    background:rgba(255,255,255,.07); color:#fff; border-left-color:#3b82f6;
}
.ops-sidebar nav a i { font-size:.95rem; width:1rem; text-align:center; }
.ops-sidebar .sb-sep { padding:.45rem 1.4rem .2rem; font-size:.65rem; text-transform:uppercase; letter-spacing:1.5px; opacity:.4; margin-top:.4rem; }
.ops-sidebar .sb-user { padding:.9rem 1.4rem; border-top:1px solid rgba(255,255,255,.08); display:flex; align-items:center; gap:.65rem; }
.ops-sidebar .sb-user .avatar { width:32px; height:32px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#6366f1); display:flex; align-items:center; justify-content:center; font-size:.8rem; font-weight:700; color:#fff; flex-shrink:0; }
.ops-sidebar .sb-user .info small { display:block; font-size:.68rem; opacity:.5; }
.ops-sidebar .sb-user .info strong { font-size:.78rem; color:#fff; }
/* User sidebar accent */
.ops-sidebar.usr { background:linear-gradient(180deg,#1e1b4b 0%,#312e81 100%); }
.ops-sidebar.usr nav a:hover, .ops-sidebar.usr nav a.active { border-left-color:#818cf8; }
.ops-main { flex:1; overflow-x:hidden; min-width:0; }
@media(max-width:900px) { .ops-sidebar { display:none; } }
</style>

<div class="ops-wrap">

<?php if ($role === ROLE_ADMIN): ?>
<!-- ══ ADMIN SIDEBAR ══════════════════════════════ -->
<aside class="ops-sidebar">
    <div class="sb-brand">
        <span>Management Panel</span>
        <strong><i class="bi bi-train-front-fill me-1"></i> Railway Admin</strong>
    </div>
    <nav>
        <div class="sb-sep">Main</div>
        <a href="admin-dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="reports.php"><i class="bi bi-bar-chart-line"></i> Reports</a>
        <a href="audit-logs.php"><i class="bi bi-journal-text"></i> Audit Logs</a>
        <div class="sb-sep">Operations</div>
        <a href="manage-trains.php"><i class="bi bi-train-front"></i> Trains</a>
        <a href="train-seats-report.php"><i class="bi bi-diagram-3"></i> Seat Report</a>
        <a href="manage-routes.php"><i class="bi bi-signpost-split"></i> Routes</a>
        <a href="manage-bookings.php"><i class="bi bi-ticket-perforated"></i> Bookings</a>
        <a href="manage-payments.php"><i class="bi bi-credit-card"></i> Payments</a>
        <a href="cargo-shipments.php"><i class="bi bi-box-seam"></i> Cargo</a>
        <a href="operations-hub.php" class="active"><i class="bi bi-diagram-3"></i> Operations Hub</a>
        <div class="sb-sep">People</div>
        <a href="manage-users.php"><i class="bi bi-people"></i> Users</a>
        <a href="notifications.php"><i class="bi bi-bell"></i> Notifications</a>
        <div class="sb-sep">Account</div>
        <a href="profile.php"><i class="bi bi-person-gear"></i> Profile</a>
        <a href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </nav>
    <div class="sb-user">
        <div class="avatar"><?= strtoupper(substr($user['full_name'] ?? 'A', 0, 1)) ?></div>
        <div class="info">
            <strong><?= htmlspecialchars($user['full_name'] ?? 'Admin') ?></strong>
            <small>Administrator</small>
        </div>
    </div>
</aside>

<?php else: ?>
<!-- ══ USER SIDEBAR ═══════════════════════════════ -->
<aside class="ops-sidebar usr">
    <div class="sb-brand">
        <span>Passenger Portal</span>
        <strong><i class="bi bi-train-front-fill me-1"></i> My Travel</strong>
    </div>
    <nav>
        <div class="sb-sep">Main</div>
        <a href="dashboard.php"><i class="bi bi-house"></i> Dashboard</a>
        <a href="index.php"><i class="bi bi-search"></i> Search Trains</a>
        <div class="sb-sep">My Trips</div>
        <a href="bookings.php"><i class="bi bi-ticket-perforated"></i> My Bookings</a>
        <a href="my-cargo.php"><i class="bi bi-box-seam"></i> My Cargo</a>
        <a href="operations-hub.php" class="active"><i class="bi bi-diagram-3"></i> Operations Hub</a>
        <div class="sb-sep">Account</div>
        <a href="notifications.php"><i class="bi bi-bell"></i> Notifications</a>
        <a href="profile.php"><i class="bi bi-person-circle"></i> Profile</a>
        <a href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </nav>
    <div class="sb-user">
        <div class="avatar"><?= strtoupper(substr($user['full_name'] ?? 'U', 0, 1)) ?></div>
        <div class="info">
            <strong><?= htmlspecialchars($user['full_name'] ?? 'Passenger') ?></strong>
            <small>Passenger</small>
        </div>
    </div>
</aside>
<?php endif; ?>

<div class="ops-main">
    <section class="ops-hero">
        <div class="container">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <h1>Operations Hub</h1>
                    <p>
                        <?php if ($role === ROLE_USER): ?>
                        Track live trains, waitlist and RAC requests, lost items, cargo and your travel operations from one place.
                        <?php else: ?>
                        Centralize station setup, live operations, crew planning, lost and found, and passenger queue management across the railway network.
                        <?php endif; ?>
                    </p>
                </div>
                <div class="text-end text-white-50 small">
                    <div><?= htmlspecialchars($user['full_name'] ?? 'Operator') ?></div>
                    <div><?= ucfirst($role) ?> workspace</div>
                </div>
            </div>

            <div class="ops-summary-grid">
                <div class="ops-summary-card">
                    <div class="label">Live Routes Watched</div>
                    <div class="value"><?= number_format(count($liveBoard)) ?></div>
                    <div class="sub"><?= number_format($delayedRouteCount) ?> delayed or impacted</div>
                </div>
                <div class="ops-summary-card">
                    <div class="label">Waitlist / RAC</div>
                    <div class="value"><?= number_format($activeWaitlistCount) ?></div>
                    <div class="sub"><?= $role === ROLE_USER ? 'Your active queue requests' : 'Active queue entries to manage' ?></div>
                </div>
                <div class="ops-summary-card">
                    <div class="label">Lost & Found</div>
                    <div class="value"><?= number_format($openLostCount) ?></div>
                    <div class="sub"><?= $role === ROLE_USER ? 'Open cases you reported' : 'Open cases awaiting attention' ?></div>
                </div>
                <div class="ops-summary-card">
                    <div class="label">Crew & Maintenance</div>
                    <div class="value"><?= number_format($maintenanceDueCount) ?></div>
                    <div class="sub"><?= $role === ROLE_USER ? 'Relevant service tasks for your trips' : 'Scheduled or active service tasks' ?></div>
                </div>
            </div>
        </div>
    </section>

    <div class="container ops-content">
        <div class="ops-shell">
            <div class="ops-tabbar">
                <?php
                $tabs = [
                    'overview' => ['bi-grid-1x2-fill', 'Overview'],
                    'live' => ['bi-broadcast-pin', 'Live Status'],
                    'waitlist' => ['bi-hourglass-split', 'Waitlist / RAC'],
                    'lost-found' => ['bi-briefcase', 'Lost & Found'],
                    'crew' => ['bi-tools', 'Crew & Maintenance'],
                    'activity' => ['bi-clock-history', 'Activity'],
                ];
                if ($role === ROLE_ADMIN) {
                    $tabs['stations'] = ['bi-building', 'Stations'];
                }
                foreach ($tabs as $tabKey => $tabMeta):
                ?>
                <a href="operations-hub.php?tab=<?= urlencode($tabKey) ?>" class="<?= $activeTab === $tabKey ? 'active' : '' ?>">
                    <i class="bi <?= $tabMeta[0] ?>"></i><?= htmlspecialchars($tabMeta[1]) ?>
                </a>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($flash['message'])): ?>
            <div class="px-4 pt-4">
                <div class="alert alert-<?= htmlspecialchars($flash['type'] ?? 'info') ?> mb-0 rounded-4 border-0 shadow-sm">
                    <?= htmlspecialchars($flash['message']) ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="ops-panel">
                <?php if ($activeTab === 'overview'): ?>
                    <div class="ops-card mb-3">
                        <h3><i class="bi bi-compass me-2 text-primary"></i>Quick Access</h3>
                        <div class="ops-quick-grid">
                            <?php foreach ($quickLinks as $link): ?>
                            <a href="<?= htmlspecialchars($link['href']) ?>" class="ops-quick-link">
                                <div class="icon"><i class="bi <?= htmlspecialchars($link['icon']) ?>"></i></div>
                                <div class="title"><?= htmlspecialchars($link['title']) ?></div>
                                <div class="text"><?= htmlspecialchars($link['text']) ?></div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="ops-grid single">
                        <div class="ops-card">
                            <h3><i class="bi bi-lightning-charge-fill me-2 text-warning"></i>What Is Included</h3>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="ops-list-item h-100">
                                        <div class="title">Live Train Status</div>
                                        <div class="meta">Manual live updates with current station, next stop, service state, and delay tracking.</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="ops-list-item h-100">
                                        <div class="title">Waitlist and RAC</div>
                                        <div class="meta">Passengers can join the queue and active entries are auto-confirmed whenever seats are released.</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="ops-list-item h-100">
                                        <div class="title">Lost & Found</div>
                                        <div class="meta">Report missing items, track case updates, and let staff mark matched or closed cases.</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="ops-list-item h-100">
                                        <div class="title">Crew and Maintenance</div>
                                        <div class="meta">Schedule service work, assign crew to routes, and monitor readiness from one place.</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php elseif ($activeTab === 'live'): ?>
                    <div class="ops-grid">
                        <?php if ($role !== ROLE_USER): ?>
                        <div class="ops-card">
                            <h3><i class="bi bi-pencil-square me-2 text-primary"></i>Update Live Status</h3>
                            <form method="POST" class="row g-3">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="return_tab" value="live">
                                <input type="hidden" name="action" value="upsert_live_status">

                                <div class="col-md-12">
                                    <label class="form-label small fw-semibold">Route</label>
                                    <select name="route_id" class="form-select" required>
                                        <option value="">Select route</option>
                                        <?php foreach ($upcomingRoutes as $route): ?>
                                        <option value="<?= (int)$route['route_id'] ?>">
                                            <?= htmlspecialchars($route['train_name'] . ' | ' . $route['departure_city'] . ' to ' . $route['arrival_city'] . ' | ' . date('d M', strtotime($route['journey_date']))) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Service State</label>
                                    <select name="service_state" class="form-select" required>
                                        <?php foreach (['scheduled', 'boarding', 'running', 'delayed', 'arrived', 'cancelled', 'maintenance'] as $state): ?>
                                        <option value="<?= htmlspecialchars($state) ?>"><?= ucwords(str_replace('_', ' ', $state)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Delay (minutes)</label>
                                    <input type="number" name="delay_minutes" min="0" value="0" class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Current Station</label>
                                    <input type="text" name="current_station" class="form-control" list="stationCityList" placeholder="Current location">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Next Station</label>
                                    <input type="text" name="next_station" class="form-control" list="stationCityList" placeholder="Upcoming stop">
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label small fw-semibold">Service Note</label>
                                    <textarea name="status_note" class="form-control" rows="3" placeholder="Platform change, locomotive swap, congestion, or service note"></textarea>
                                </div>
                                <div class="col-md-12 d-flex justify-content-end">
                                    <button type="submit" class="btn btn-primary rounded-pill px-4">Save Live Update</button>
                                </div>
                            </form>
                        </div>
                        <?php else: ?>
                        <div class="ops-card">
                            <h3><i class="bi bi-broadcast-pin me-2 text-primary"></i>Tracking Guidance</h3>
                            <p class="ops-muted mb-2">Live train status is updated by railway staff. When delays or route changes happen, affected bookings also receive notifications.</p>
                            <div class="ops-list-item">
                                <div class="title">Included live fields</div>
                                <div class="meta">Current station, next stop, service state, delay minutes, and route notes.</div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="ops-card">
                            <h3><i class="bi bi-display me-2 text-success"></i>Live Board</h3>
                            <?php if ($liveBoard): ?>
                            <div class="table-responsive">
                                <table class="ops-table">
                                    <thead>
                                        <tr>
                                            <th>Train</th>
                                            <th>Route</th>
                                            <th>Location</th>
                                            <th>Status</th>
                                            <th>Delay</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($liveBoard as $route): ?>
                                        <tr>
                                            <td>
                                                <div class="ops-route"><?= htmlspecialchars($route['train_name']) ?></div>
                                                <div class="ops-subline"><?= htmlspecialchars($route['train_number']) ?></div>
                                            </td>
                                            <td>
                                                <div class="ops-route"><?= htmlspecialchars($route['departure_city']) ?> to <?= htmlspecialchars($route['arrival_city']) ?></div>
                                                <div class="ops-subline"><?= date('d M Y, H:i', strtotime($route['journey_date'] . ' ' . $route['departure_time'])) ?></div>
                                            </td>
                                            <td>
                                                <div><?= htmlspecialchars($route['current_station'] ?? $route['departure_city']) ?></div>
                                                <div class="ops-subline">Next: <?= htmlspecialchars($route['next_station'] ?? $route['arrival_city']) ?></div>
                                                <?php if (!empty($route['status_note'])): ?><div class="ops-subline"><?= htmlspecialchars($route['status_note']) ?></div><?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="ops-pill <?= operationsHubBadgeClass((string)$route['service_state'], $liveBadgeMap) ?>">
                                                    <?= strtoupper(str_replace('_', ' ', $route['service_state'])) ?>
                                                </span>
                                                <?php if (!empty($route['updated_at'])): ?><div class="ops-subline mt-1">Updated <?= date('d M H:i', strtotime($route['updated_at'])) ?></div><?php endif; ?>
                                            </td>
                                            <td>
                                                <?= (int)($route['delay_minutes'] ?? 0) ?> min
                                                <?php if (!empty($route['updated_by_name'])): ?><div class="ops-subline">By <?= htmlspecialchars($route['updated_by_name']) ?></div><?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <div class="ops-empty">No live train records are available right now.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php elseif ($activeTab === 'waitlist'): ?>
                    <div class="ops-grid">
                        <div class="ops-card">
                            <h3><i class="bi bi-hourglass-split me-2 text-warning"></i><?= $role === ROLE_USER ? 'Join Waitlist / RAC' : 'Queue Controls' ?></h3>
                            <?php if ($role === ROLE_USER): ?>
                            <form method="POST" class="row g-3">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="return_tab" value="waitlist">
                                <input type="hidden" name="action" value="join_waitlist">

                                <div class="col-md-12">
                                    <label class="form-label small fw-semibold">Route</label>
                                    <select name="route_id" class="form-select" required>
                                        <option value="">Select route</option>
                                        <?php foreach ($upcomingRoutes as $route): ?>
                                        <option value="<?= (int)$route['route_id'] ?>">
                                            <?= htmlspecialchars($route['train_name'] . ' | ' . $route['departure_city'] . ' to ' . $route['arrival_city'] . ' | ' . date('d M', strtotime($route['journey_date']))) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Preferred Class</label>
                                    <select name="preferred_class" class="form-select">
                                        <option value="economy">Economy</option>
                                        <option value="premium">Premium</option>
                                        <option value="luxury">Luxury</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Passenger Count</label>
                                    <select name="passenger_count" class="form-select">
                                        <?php for ($count = 1; $count <= 6; $count++): ?>
                                        <option value="<?= $count ?>"><?= $count ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label small fw-semibold">Passenger Details</label>
                                    <div class="ops-form-note mb-2">Only the first selected number of passengers will be used for the request.</div>
                                    <?php for ($row = 0; $row < 6; $row++): ?>
                                    <div class="row g-2 mb-2">
                                        <div class="col-md-6">
                                            <input type="text" name="passenger_name[]" class="form-control" placeholder="Passenger <?= $row + 1 ?> name<?= $row === 0 ? ' *' : '' ?>">
                                        </div>
                                        <div class="col-md-3">
                                            <input type="number" name="passenger_age[]" min="1" max="120" class="form-control" placeholder="Age">
                                        </div>
                                        <div class="col-md-3">
                                            <select name="passenger_gender[]" class="form-select">
                                                <option value="Other">Other</option>
                                                <option value="M">Male</option>
                                                <option value="F">Female</option>
                                            </select>
                                        </div>
                                    </div>
                                    <?php endfor; ?>
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label small fw-semibold">Note</label>
                                    <textarea name="waitlist_note" class="form-control" rows="3" placeholder="Optional note for staff, special assistance, or preferred coach area"></textarea>
                                </div>
                                <div class="col-md-12 d-flex justify-content-end">
                                    <button type="submit" class="btn btn-warning rounded-pill px-4 fw-semibold">Request Waitlist Slot</button>
                                </div>
                            </form>
                            <?php else: ?>
                            <form method="POST" class="row g-3">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="return_tab" value="waitlist">
                                <input type="hidden" name="action" value="process_waitlist">
                                <div class="col-md-12">
                                    <label class="form-label small fw-semibold">Route</label>
                                    <select name="route_id" class="form-select" required>
                                        <option value="">Select route to process</option>
                                        <?php foreach ($upcomingRoutes as $route): ?>
                                        <option value="<?= (int)$route['route_id'] ?>">
                                            <?= htmlspecialchars($route['train_name'] . ' | ' . $route['departure_city'] . ' to ' . $route['arrival_city'] . ' | ' . date('d M', strtotime($route['journey_date']))) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-12">
                                    <div class="ops-form-note">The system will automatically confirm the next matching waitlist entries when seats are available.</div>
                                </div>
                                <div class="col-md-12 d-flex justify-content-end">
                                    <button type="submit" class="btn btn-warning rounded-pill px-4 fw-semibold">Run Auto-Confirmation</button>
                                </div>
                            </form>
                            <?php endif; ?>
                        </div>

                        <div class="ops-card">
                            <h3><i class="bi bi-list-check me-2 text-primary"></i><?= $role === ROLE_USER ? 'My Queue Entries' : 'Active Queue' ?></h3>
                            <?php if ($waitlistEntries): ?>
                            <div class="table-responsive">
                                <table class="ops-table">
                                    <thead>
                                        <tr>
                                            <?php if ($role !== ROLE_USER): ?><th>Passenger</th><?php endif; ?>
                                            <th>Route</th>
                                            <th>Queue</th>
                                            <th>Booking</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($waitlistEntries as $entry): ?>
                                        <tr>
                                            <?php if ($role !== ROLE_USER): ?>
                                            <td>
                                                <div class="ops-route"><?= htmlspecialchars($entry['full_name']) ?></div>
                                                <div class="ops-subline"><?= (int)$entry['passenger_count'] ?> passenger(s)</div>
                                            </td>
                                            <?php endif; ?>
                                            <td>
                                                <div class="ops-route"><?= htmlspecialchars($entry['departure_city']) ?> to <?= htmlspecialchars($entry['arrival_city']) ?></div>
                                                <div class="ops-subline"><?= htmlspecialchars($entry['train_name']) ?> | <?= date('d M Y, H:i', strtotime($entry['journey_date'] . ' ' . $entry['departure_time'])) ?></div>
                                                <div class="ops-subline">Class: <?= ucfirst(htmlspecialchars($entry['preferred_class'])) ?></div>
                                            </td>
                                            <td>
                                                <span class="ops-pill <?= operationsHubBadgeClass((string)$entry['queue_status'], $waitBadgeMap) ?>">
                                                    <?= strtoupper($entry['queue_status']) ?>
                                                </span>
                                                <?php if (!empty($entry['queue_position'])): ?><div class="ops-subline mt-1">Position <?= (int)$entry['queue_position'] ?></div><?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($entry['booking_reference'])): ?>
                                                <a href="booking_details.php?id=<?= (int)$entry['linked_booking_id'] ?>" class="text-decoration-none fw-semibold">
                                                    <?= htmlspecialchars($entry['booking_reference']) ?>
                                                </a>
                                                <?php else: ?>
                                                <span class="ops-muted">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (in_array($entry['queue_status'], ['waitlist', 'rac'], true)): ?>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="return_tab" value="waitlist">
                                                    <input type="hidden" name="action" value="cancel_waitlist">
                                                    <input type="hidden" name="waitlist_id" value="<?= (int)$entry['waitlist_id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill">Cancel</button>
                                                </form>
                                                <?php else: ?>
                                                <span class="ops-muted">No action</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <div class="ops-empty">No waitlist or RAC entries are available right now.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php elseif ($activeTab === 'lost-found'): ?>
                    <div class="ops-grid">
                        <div class="ops-card">
                            <h3><i class="bi bi-briefcase me-2 text-danger"></i>Report Item</h3>
                            <form method="POST" class="row g-3">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="return_tab" value="lost-found">
                                <input type="hidden" name="action" value="add_lost_found">

                                <?php if ($role !== ROLE_USER): ?>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Record Type</label>
                                    <select name="record_type" class="form-select">
                                        <option value="lost">Lost</option>
                                        <option value="found">Found</option>
                                    </select>
                                </div>
                                <?php endif; ?>
                                <div class="col-md-<?= $role === ROLE_USER ? '12' : '6' ?>">
                                    <label class="form-label small fw-semibold">Related Route</label>
                                    <select name="route_id" class="form-select">
                                        <option value="0">General station case</option>
                                        <?php foreach ($upcomingRoutes as $route): ?>
                                        <option value="<?= (int)$route['route_id'] ?>">
                                            <?= htmlspecialchars($route['train_name'] . ' | ' . $route['departure_city'] . ' to ' . $route['arrival_city']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Item Name</label>
                                    <input type="text" name="item_name" class="form-control" placeholder="Backpack, wallet, document folder" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Category</label>
                                    <input type="text" name="category" class="form-control" placeholder="electronics, luggage, documents">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Last Seen / Found At</label>
                                    <input type="text" name="location_hint" class="form-control" list="stationCityList" placeholder="Coach B2, Lahore platform, waiting hall">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Contact Phone</label>
                                    <input type="text" name="contact_phone" class="form-control" placeholder="Phone for follow-up">
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label small fw-semibold">Description</label>
                                    <textarea name="description" class="form-control" rows="4" placeholder="Describe the item and identifying details" required></textarea>
                                </div>
                                <div class="col-md-12 d-flex justify-content-end">
                                    <button type="submit" class="btn btn-danger rounded-pill px-4">Submit Case</button>
                                </div>
                            </form>
                        </div>

                        <div class="ops-card">
                            <h3><i class="bi bi-inboxes me-2 text-primary"></i><?= $role === ROLE_USER ? 'My Cases' : 'Open Cases' ?></h3>
                            <?php if ($lostFoundItems): ?>
                            <div class="ops-list">
                                <?php foreach ($lostFoundItems as $item): ?>
                                <div class="ops-list-item">
                                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                                        <div>
                                            <div class="title"><?= htmlspecialchars($item['item_name']) ?></div>
                                            <div class="meta">
                                                <?= strtoupper(htmlspecialchars($item['record_type'])) ?>
                                                <?php if (!empty($item['train_name'])): ?> | <?= htmlspecialchars($item['train_name']) ?><?php endif; ?>
                                                <?php if (!empty($item['departure_city']) && !empty($item['arrival_city'])): ?> | <?= htmlspecialchars($item['departure_city']) ?> to <?= htmlspecialchars($item['arrival_city']) ?><?php endif; ?>
                                            </div>
                                        </div>
                                        <span class="ops-pill <?= operationsHubBadgeClass((string)$item['status'], $lostBadgeMap) ?>">
                                            <?= strtoupper(str_replace('_', ' ', $item['status'])) ?>
                                        </span>
                                    </div>
                                    <div class="meta mt-2"><?= htmlspecialchars($item['description']) ?></div>
                                    <?php if (!empty($item['location_hint'])): ?><div class="meta">Location: <?= htmlspecialchars($item['location_hint']) ?></div><?php endif; ?>
                                    <?php if (!empty($item['contact_phone'])): ?><div class="meta">Contact: <?= htmlspecialchars($item['contact_phone']) ?></div><?php endif; ?>
                                    <?php if ($role !== ROLE_USER): ?>
                                    <form method="POST" class="row g-2 mt-2">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="return_tab" value="lost-found">
                                        <input type="hidden" name="action" value="update_lost_found">
                                        <input type="hidden" name="item_id" value="<?= (int)$item['item_id'] ?>">
                                        <div class="col-md-4">
                                            <select name="status" class="form-select form-select-sm">
                                                <?php foreach (['reported', 'under_review', 'matched', 'claimed', 'closed'] as $status): ?>
                                                <option value="<?= htmlspecialchars($status) ?>" <?= ($item['status'] === $status) ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $status)) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <input type="text" name="resolution_note" class="form-control form-control-sm" placeholder="Status note or handover detail">
                                        </div>
                                        <div class="col-md-2 d-grid">
                                            <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                                        </div>
                                    </form>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div class="ops-empty">No lost and found cases are available for this view.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php elseif ($activeTab === 'crew'): ?>
                    <div class="ops-grid">
                        <div class="ops-card">
                            <h3><i class="bi bi-tools me-2 text-warning"></i><?= $role === ROLE_USER ? 'Train Maintenance' : 'Schedule Maintenance' ?></h3>
                            <?php if ($role !== ROLE_USER): ?>
                            <form method="POST" class="row g-3 mb-4">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="return_tab" value="crew">
                                <input type="hidden" name="action" value="add_maintenance">

                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Train</label>
                                    <select name="train_id" class="form-select" required>
                                        <option value="">Select train</option>
                                        <?php foreach ($trainList as $train): ?>
                                        <option value="<?= (int)$train['train_id'] ?>"><?= htmlspecialchars($train['train_name'] . ' (' . $train['train_number'] . ')') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Type</label>
                                    <select name="maintenance_type" class="form-select">
                                        <option value="inspection">Inspection</option>
                                        <option value="repair">Repair</option>
                                        <option value="cleaning">Cleaning</option>
                                        <option value="overhaul">Overhaul</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Scheduled Date</label>
                                    <input type="date" name="scheduled_date" class="form-control" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Status</label>
                                    <select name="maintenance_status" class="form-select">
                                        <option value="scheduled">Scheduled</option>
                                        <option value="in_progress">In Progress</option>
                                        <option value="completed">Completed</option>
                                        <option value="cancelled">Cancelled</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Assign Employee</label>
                                    <select name="assigned_employee_id" class="form-select">
                                        <option value="0">Unassigned</option>
                                        <?php foreach ($employeeList as $employee): ?>
                                        <option value="<?= (int)$employee['user_id'] ?>"><?= htmlspecialchars($employee['full_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label small fw-semibold">Notes</label>
                                    <textarea name="maintenance_notes" class="form-control" rows="3" placeholder="Inspection scope, spare parts, or constraints"></textarea>
                                </div>
                                <div class="col-md-12 d-flex justify-content-end">
                                    <button type="submit" class="btn btn-warning rounded-pill px-4 fw-semibold">Save Maintenance</button>
                                </div>
                            </form>
                            <?php endif; ?>

                            <?php if ($maintenanceRows): ?>
                            <div class="table-responsive">
                                <table class="ops-table">
                                    <thead>
                                        <tr>
                                            <th>Train</th>
                                            <th>Task</th>
                                            <th>Date</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($maintenanceRows as $maintenance): ?>
                                        <tr>
                                            <td>
                                                <div class="ops-route"><?= htmlspecialchars($maintenance['train_name']) ?></div>
                                                <div class="ops-subline"><?= htmlspecialchars($maintenance['train_number']) ?></div>
                                            </td>
                                            <td>
                                                <?= ucwords(str_replace('_', ' ', $maintenance['maintenance_type'])) ?>
                                                <?php if (!empty($maintenance['assigned_employee_name'])): ?><div class="ops-subline">Assigned: <?= htmlspecialchars($maintenance['assigned_employee_name']) ?></div><?php endif; ?>
                                                <?php if (!empty($maintenance['notes'])): ?><div class="ops-subline"><?= htmlspecialchars($maintenance['notes']) ?></div><?php endif; ?>
                                            </td>
                                            <td><?= date('d M Y', strtotime($maintenance['scheduled_date'])) ?></td>
                                            <td>
                                                <span class="ops-pill <?= operationsHubBadgeClass((string)$maintenance['status'], $maintenanceBadgeMap) ?>">
                                                    <?= strtoupper(str_replace('_', ' ', $maintenance['status'])) ?>
                                                </span>
                                                <?php if ($role !== ROLE_USER): ?>
                                                <form method="POST" class="mt-2">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="return_tab" value="crew">
                                                    <input type="hidden" name="action" value="update_maintenance_status">
                                                    <input type="hidden" name="maintenance_id" value="<?= (int)$maintenance['maintenance_id'] ?>">
                                                    <div class="d-flex gap-2">
                                                        <select name="maintenance_status" class="form-select form-select-sm">
                                                            <?php foreach (['scheduled', 'in_progress', 'completed', 'cancelled'] as $status): ?>
                                                            <option value="<?= htmlspecialchars($status) ?>" <?= ($maintenance['status'] === $status) ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $status)) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                                                    </div>
                                                </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <div class="ops-empty">No maintenance tasks are scheduled yet.</div>
                            <?php endif; ?>
                        </div>

                        <div class="ops-card">
                            <h3><i class="bi bi-people-fill me-2 text-primary"></i><?= $role === ROLE_USER ? 'Crew Assignments' : 'Assign Crew' ?></h3>
                            <?php if ($role !== ROLE_USER): ?>
                            <form method="POST" class="row g-3 mb-4">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="return_tab" value="crew">
                                <input type="hidden" name="action" value="add_crew_assignment">

                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Route</label>
                                    <select name="route_id" class="form-select" required>
                                        <option value="">Select route</option>
                                        <?php foreach ($upcomingRoutes as $route): ?>
                                        <option value="<?= (int)$route['route_id'] ?>">
                                            <?= htmlspecialchars($route['train_name'] . ' | ' . $route['departure_city'] . ' to ' . $route['arrival_city'] . ' | ' . date('d M', strtotime($route['journey_date']))) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Employee</label>
                                    <select name="employee_id" class="form-select" required>
                                        <option value="">Select employee</option>
                                        <?php foreach ($employeeList as $employee): ?>
                                        <option value="<?= (int)$employee['user_id'] ?>"><?= htmlspecialchars($employee['full_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Role Title</label>
                                    <input type="text" name="role_title" class="form-control" placeholder="Driver, Ticket Examiner, Attendant" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Assignment Status</label>
                                    <select name="assignment_status" class="form-select">
                                        <option value="assigned">Assigned</option>
                                        <option value="checked_in">Checked In</option>
                                        <option value="completed">Completed</option>
                                        <option value="cancelled">Cancelled</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Shift Start</label>
                                    <input type="datetime-local" name="shift_start" class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Shift End</label>
                                    <input type="datetime-local" name="shift_end" class="form-control">
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label small fw-semibold">Notes</label>
                                    <textarea name="assignment_notes" class="form-control" rows="3" placeholder="Duty note, reporting point, or coverage note"></textarea>
                                </div>
                                <div class="col-md-12 d-flex justify-content-end">
                                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-semibold">Save Crew Assignment</button>
                                </div>
                            </form>
                            <?php endif; ?>

                            <?php if ($crewAssignments): ?>
                            <div class="table-responsive">
                                <table class="ops-table">
                                    <thead>
                                        <tr>
                                            <th>Employee</th>
                                            <th>Route</th>
                                            <th>Duty</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($crewAssignments as $assignment): ?>
                                        <tr>
                                            <td>
                                                <div class="ops-route"><?= htmlspecialchars($assignment['employee_name']) ?></div>
                                                <div class="ops-subline"><?= htmlspecialchars($assignment['role_title']) ?></div>
                                            </td>
                                            <td>
                                                <div class="ops-route"><?= htmlspecialchars($assignment['departure_city']) ?> to <?= htmlspecialchars($assignment['arrival_city']) ?></div>
                                                <div class="ops-subline"><?= htmlspecialchars($assignment['train_name']) ?> | <?= date('d M Y, H:i', strtotime($assignment['journey_date'] . ' ' . $assignment['departure_time'])) ?></div>
                                            </td>
                                            <td>
                                                <?php if (!empty($assignment['shift_start'])): ?>Start: <?= date('d M H:i', strtotime($assignment['shift_start'])) ?><br><?php endif; ?>
                                                <?php if (!empty($assignment['shift_end'])): ?>End: <?= date('d M H:i', strtotime($assignment['shift_end'])) ?><?php endif; ?>
                                                <?php if (!empty($assignment['notes'])): ?><div class="ops-subline"><?= htmlspecialchars($assignment['notes']) ?></div><?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="ops-pill <?= operationsHubBadgeClass((string)$assignment['assignment_status'], $maintenanceBadgeMap) ?>">
                                                    <?= strtoupper(str_replace('_', ' ', $assignment['assignment_status'])) ?>
                                                </span>
                                                <?php if ($role !== ROLE_USER): ?>
                                                <form method="POST" class="mt-2">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="return_tab" value="crew">
                                                    <input type="hidden" name="action" value="update_crew_status">
                                                    <input type="hidden" name="assignment_id" value="<?= (int)$assignment['assignment_id'] ?>">
                                                    <div class="d-flex gap-2">
                                                        <select name="assignment_status" class="form-select form-select-sm">
                                                            <?php foreach (['assigned', 'checked_in', 'completed', 'cancelled'] as $status): ?>
                                                            <option value="<?= htmlspecialchars($status) ?>" <?= ($assignment['assignment_status'] === $status) ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $status)) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                                                    </div>
                                                </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <div class="ops-empty">No crew assignments are available for this view.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php elseif ($activeTab === 'activity'): ?>
                    <div class="ops-grid single">
                        <div class="ops-card">
                            <h3><i class="bi bi-clock-history me-2 text-primary"></i><?= $role === ROLE_ADMIN ? 'Full Activity Feed' : 'My Activity Feed' ?></h3>
                            <?php if ($activityLogs): ?>
                            <?php foreach ($activityLogs as $log): ?>
                            <div class="ops-activity-row">
                                <div class="head">
                                    <span>
                                        <?= htmlspecialchars($log['action']) ?>
                                        <span class="text-body-secondary fw-normal">in <?= htmlspecialchars($log['module']) ?></span>
                                    </span>
                                    <span class="text-body-secondary fw-normal"><?= date('d M Y, H:i', strtotime($log['created_at'])) ?></span>
                                </div>
                                <div class="body">
                                    <?php if ($role === ROLE_ADMIN): ?>
                                    <strong><?= htmlspecialchars($log['full_name'] ?? 'System') ?></strong>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($log['description'] ?? 'No description available.') ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <div class="ops-empty">No activity has been recorded for this view yet.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php elseif ($activeTab === 'stations' && $role === ROLE_ADMIN): ?>
                    <div class="ops-grid">
                        <div class="ops-card">
                            <h3><i class="bi bi-building-add me-2 text-primary"></i>Add Station</h3>
                            <form method="POST" class="row g-3">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="return_tab" value="stations">
                                <input type="hidden" name="action" value="add_station">
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Station Name</label>
                                    <input type="text" name="station_name" class="form-control" placeholder="Lahore Junction" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Station Code</label>
                                    <input type="text" name="station_code" class="form-control" placeholder="LHE" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">City</label>
                                    <input type="text" name="city" class="form-control" placeholder="Lahore" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Province / Region</label>
                                    <input type="text" name="province" class="form-control" placeholder="Punjab">
                                </div>
                                <div class="col-md-12 d-flex justify-content-end">
                                    <button type="submit" class="btn btn-primary rounded-pill px-4">Save Station</button>
                                </div>
                            </form>
                        </div>

                        <div class="ops-card">
                            <h3><i class="bi bi-signpost-split me-2 text-success"></i>Station Directory</h3>
                            <?php if ($stationsRows): ?>
                            <div class="table-responsive">
                                <table class="ops-table">
                                    <thead>
                                        <tr>
                                            <th>Station</th>
                                            <th>Code</th>
                                            <th>Routes</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($stationsRows as $station): ?>
                                        <tr>
                                            <td>
                                                <div class="ops-route"><?= htmlspecialchars($station['station_name']) ?></div>
                                                <div class="ops-subline"><?= htmlspecialchars($station['city']) ?><?= !empty($station['province']) ? ' | ' . htmlspecialchars($station['province']) : '' ?></div>
                                            </td>
                                            <td><span class="ops-pill bg-info-subtle text-info-emphasis"><?= htmlspecialchars($station['station_code']) ?></span></td>
                                            <td><?= (int)$station['total_routes'] ?></td>
                                            <td>
                                                <span class="ops-pill <?= (int)$station['is_active'] === 1 ? 'bg-success-subtle text-success-emphasis' : 'bg-secondary-subtle text-secondary' ?>">
                                                    <?= (int)$station['is_active'] === 1 ? 'ACTIVE' : 'INACTIVE' ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="d-flex gap-2 flex-wrap">
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                        <input type="hidden" name="return_tab" value="stations">
                                                        <input type="hidden" name="action" value="toggle_station">
                                                        <input type="hidden" name="station_id" value="<?= (int)$station['station_id'] ?>">
                                                        <input type="hidden" name="current_active" value="<?= (int)$station['is_active'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-primary rounded-pill">
                                                            <?= (int)$station['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>
                                                        </button>
                                                    </form>
                                                    <?php if ((int)$station['total_routes'] === 0): ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                        <input type="hidden" name="return_tab" value="stations">
                                                        <input type="hidden" name="action" value="delete_station">
                                                        <input type="hidden" name="station_id" value="<?= (int)$station['station_id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill">Delete</button>
                                                    </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <div class="ops-empty">No stations are configured yet.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="ops-empty">This tab is not available in your current role.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <datalist id="stationCityList">
        <?php foreach ($uniqueStationCities as $station): ?>
        <option value="<?= htmlspecialchars($station['city']) ?>"><?= htmlspecialchars($station['station_code'] . ' | ' . $station['station_name']) ?></option>
        <?php endforeach; ?>
    </datalist>
</div><!-- /.ops-main -->
</div><!-- /.ops-wrap -->

<?php require_once 'inc/footer.php'; ?>
<?php
// update_train_status.php - AJAX endpoint for updating train status
require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/AuditLog.php';
require_once 'src/classes/Operations.php';

header('Content-Type: application/json');

if (!User::isLoggedIn() || $_SESSION['role'] != 'employee') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$db = new Database();
$db->connect();
$operations = new Operations($db);
$operations->ensureSchema();

$route_id = $_POST['route_id'] ?? null;
$status = $_POST['status'] ?? null;

if (!$route_id || !$status) {
    echo json_encode(['success' => false, 'message' => 'Missing data']);
    exit();
}

$route_id = (int)$route_id;
$allowed_statuses = ['scheduled', 'cancelled', 'completed'];
if (!in_array($status, $allowed_statuses, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit();
}

// Update status in routes table
$statusEscaped = $db->getConnection()->real_escape_string($status);
$updated = $db->query("UPDATE routes SET status = '{$statusEscaped}' WHERE route_id = {$route_id}");
if ($updated) {
    $live_state = $status === 'completed' ? 'arrived' : $status;
    $liveEscaped = $db->getConnection()->real_escape_string($live_state);
    $db->query(
        "INSERT INTO live_train_status (route_id, service_state, updated_by)
         VALUES ({$route_id}, '{$liveEscaped}', " . (int)$_SESSION['user_id'] . ")
         ON DUPLICATE KEY UPDATE service_state = VALUES(service_state), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP"
    );
    AuditLog::log($db, 'UPDATE_ROUTE_STATUS', 'operations', 'Employee updated route status to ' . $status . '.', $route_id);
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Update failed']);
}

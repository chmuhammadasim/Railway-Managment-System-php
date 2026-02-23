<?php
// update_train_status.php - AJAX endpoint for updating train status
require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';

header('Content-Type: application/json');

if (!User::isLoggedIn() || $_SESSION['role'] != 'employee') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$db = new Database();
$db->connect();

$route_id = $_POST['route_id'] ?? null;
$status = $_POST['status'] ?? null;

if (!$route_id || !$status) {
    echo json_encode(['success' => false, 'message' => 'Missing data']);
    exit();
}

// Update status in routes table
$updated = $db->update('routes', ['status' => $status], ['route_id' => $route_id]);
if ($updated) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Update failed']);
}

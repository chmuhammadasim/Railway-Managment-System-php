<?php
// Test login with actual database

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';

$db = new Database();
$db->connect();

$user = new User($db);
$result = $user->login('admin', 'password123');

if ($result['success']) {
    echo "✓ LOGIN SUCCESSFUL!\n";
    echo "User: " . $result['user']['username'] . "\n";
    echo "Role: " . $result['user']['role'] . "\n";
} else {
    echo "✗ LOGIN FAILED!\n";
    echo "Message: " . $result['message'] . "\n";
}

$db->closeConnection();
?>

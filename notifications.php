<?php
// notifications.php - User Notifications
require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';

if (!User::isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$db = new Database();
$db->connect();
$user_id = $_SESSION['user_id'];

// Example: Fetch notifications from a notifications table (implement as needed)
$notifications = $db->select("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC", [$user_id]);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Railway Management System</title>
    <link rel="stylesheet" href="public/css/style.css">
    <style>
        .notification-list { list-style: none; padding: 0; }
        .notification-item { background: #fff; border-radius: 8px; margin-bottom: 1em; padding: 1em; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
        .notification-item.unread { background: #e3f2fd; }
        .notification-date { font-size: 0.9em; color: #888; }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container">
            <div class="logo">
                <h1>🚂 Railway System</h1>
            </div>
            <ul class="nav-links">
                <li><a href="dashboard.php">Dashboard</a></li>
                <li><a href="profile.php">Profile</a></li>
                <li><a href="notifications.php" class="btn">Notifications</a></li>
                <li><a href="logout.php" class="btn">Logout</a></li>
            </ul>
        </div>
    </nav>
    <div class="container" style="max-width: 600px; margin-top: 2em;">
        <div class="card">
            <h2 style="margin-bottom: 1em;">Notifications</h2>
            <?php if (empty($notifications)): ?>
                <p>No notifications yet.</p>
            <?php else: ?>
                <ul class="notification-list">
                    <?php foreach ($notifications as $notif): ?>
                        <li class="notification-item<?= $notif['is_read'] ? '' : ' unread' ?>">
                            <div><?= htmlspecialchars($notif['message']) ?></div>
                            <div class="notification-date"><?= htmlspecialchars($notif['created_at']) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

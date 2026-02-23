<?php
// profile.php - User Profile Management

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
$user_obj = new User($db);
$user = $user_obj->getUserById($user_id);

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $full_name = $_POST['full_name'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $address = $_POST['address'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($full_name) || empty($email)) {
        $error_message = 'Full name and email are required.';
    } elseif (!empty($password) && $password !== $confirm_password) {
        $error_message = 'Passwords do not match.';
    } else {
        $update = $user_obj->updateProfile($user_id, $full_name, $email, $phone, $address, $password);
        if ($update['success']) {
            $success_message = 'Profile updated successfully!';
            $user = $user_obj->getUserById($user_id);
        } else {
            $error_message = $update['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Profile - Railway Management System</title>
    <link rel="stylesheet" href="public/css/style.css">
</head>
<body>
    <nav class="navbar">
        <div class="container">
            <div class="logo">
                <h1>🚂 Railway System</h1>
            </div>
            <ul class="nav-links">
                <li><a href="dashboard.php">Dashboard</a></li>
                <li><a href="profile.php" class="btn">Profile</a></li>
                <li><a href="logout.php" class="btn">Logout</a></li>
            </ul>
        </div>
    </nav>
    <div class="container" style="max-width: 500px; margin-top: 2em;">
        <div class="card">
            <h2 style="margin-bottom: 1em;">User Profile</h2>
            <?php if ($error_message): ?>
                <div class="alert alert-danger" style="color: var(--danger-color); margin-bottom: 1em;">
                    <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>
            <?php if ($success_message): ?>
                <div class="alert alert-success" style="color: var(--success-color); margin-bottom: 1em;">
                    <?= htmlspecialchars($success_message) ?>
                </div>
            <?php endif; ?>
            <form method="post" autocomplete="off">
                <label>Full Name</label>
                <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name']) ?>" required class="form-control" style="width:100%;margin-bottom:1em;">
                <label>Email</label>
                <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required class="form-control" style="width:100%;margin-bottom:1em;">
                <label>Phone</label>
                <input type="text" name="phone" value="<?= htmlspecialchars($user['phone']) ?>" class="form-control" style="width:100%;margin-bottom:1em;">
                <label>Address</label>
                <input type="text" name="address" value="<?= htmlspecialchars($user['address']) ?>" class="form-control" style="width:100%;margin-bottom:1em;">
                <label>New Password <span style="font-weight:normal; color:#888;">(leave blank to keep current)</span></label>
                <input type="password" name="password" class="form-control" style="width:100%;margin-bottom:1em;">
                <label>Confirm Password</label>
                <input type="password" name="confirm_password" class="form-control" style="width:100%;margin-bottom:1em;">
                <button type="submit" class="btn">Update Profile</button>
            </form>
        </div>
    </div>
</body>
</html>

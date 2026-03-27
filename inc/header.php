<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($pageTitle)) {
    $pageTitle = 'Railway Management System';
}

// Ensure User class is available for nav state
if (!class_exists('User') && file_exists(__DIR__ . '/../src/classes/User.php')) {
    require_once __DIR__ . '/../src/classes/User.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-" crossorigin="anonymous">
    <link rel="stylesheet" href="public/css/style.css">
    <style>
        /* small helper to hide native list bullets when using our nav */
        .nav-links { list-style: none; margin: 0; padding: 0; }
        .nav-toggle { background: transparent; border: none; color: white; font-size: 1.25rem; display: none; }
        @media (max-width: 768px) { .nav-toggle { display: inline-block; } }
    </style>
</head>
<body class="page-transition">
    <nav class="navbar">
        <div class="container">
            <div class="logo">
                <h1>🚂 Railway System</h1>
            </div>
            <button id="navToggle" class="nav-toggle" aria-expanded="false" aria-label="Toggle navigation">☰</button>
            <ul class="nav-links d-flex align-items-center" id="mainNav">
                <li><a href="index.php">Home</a></li>
                <?php if (class_exists('User') ? User::isLoggedIn() : (isset($_SESSION['user_id']) && $_SESSION['user_id'])): ?>
                    <li><a href="dashboard.php">Dashboard</a></li>
                    <li><a href="bookings.php">My Bookings</a></li>
                    <li><a href="profile.php">Profile</a></li>
                    <li><a href="logout.php" class="btn-logout">Logout</a></li>
                <?php else: ?>
                    <li><a href="login.php" class="btn-login">Login</a></li>
                    <li><a href="signup.php" class="btn-signup">Sign Up</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>

    <main>

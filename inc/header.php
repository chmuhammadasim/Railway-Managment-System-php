<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($pageTitle)) {
    $pageTitle = 'Railway Management System';
}

if (!isset($hideMainNavbar)) {
    $hideMainNavbar = false;
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
    <link rel="icon" href="public/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="public/css/style.css">
    <style>
        /* small helper to hide native list bullets when using our nav */
        .nav-links { list-style: none; margin: 0; padding: 0; }
        .nav-toggle { background: transparent; border: none; color: white; font-size: 1.25rem; display: none; }
        @media (max-width: 768px) { .nav-toggle { display: inline-block; } }
        body.panel-layout > main { padding-top: 0; }
        body.panel-layout .adm-wrap,
        body.panel-layout .emp-wrap { min-height: 100vh !important; }
        body.panel-layout .adm-sidebar,
        body.panel-layout .emp-sidebar { top: 0 !important; height: 100vh !important; }
    </style>
</head>
<body class="page-transition<?php echo $hideMainNavbar ? ' panel-layout' : ''; ?>">
    <?php if (!$hideMainNavbar): ?>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
                <span class="fs-4">🚂</span>
                <span class="fw-bold">Railway System</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="mainNav">
                <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-center">
                    <li class="nav-item"><a class="nav-link" href="index.php">Home</a></li>
                    <?php if (class_exists('User') ? User::isLoggedIn() : (isset($_SESSION['user_id']) && $_SESSION['user_id'])): ?>
                        <?php $navRole = $_SESSION['role'] ?? ROLE_USER; ?>
                        <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link" href="operations-hub.php">Operations Hub</a></li>
                        <?php if ($navRole === ROLE_USER): ?>
                        <li class="nav-item"><a class="nav-link" href="bookings.php">Booking History</a></li>
                        <li class="nav-item"><a class="nav-link" href="my-cargo.php">Cargo &amp; Luggage</a></li>
                        <?php elseif ($navRole === ROLE_EMPLOYEE): ?>
                        <li class="nav-item"><a class="nav-link" href="check-passengers.php">Passenger Desk</a></li>
                        <li class="nav-item"><a class="nav-link" href="my-trains.php">My Trains</a></li>
                        <?php elseif ($navRole === ROLE_ADMIN): ?>
                        <li class="nav-item"><a class="nav-link" href="manage-routes.php">Routes</a></li>
                        <li class="nav-item"><a class="nav-link" href="manage-trains.php">Trains</a></li>
                        <?php endif; ?>
                        <li class="nav-item"><a class="nav-link" href="profile.php">Profile</a></li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-person-circle fs-4 me-1"></i>
                                <?php echo isset(
                                    
                                    $_SESSION['full_name']) ? htmlspecialchars($_SESSION['full_name']) : 'Account'; ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userMenu">
                                <li><a class="dropdown-item" href="profile.php">Profile</a></li>
                                <li><a class="dropdown-item" href="operations-hub.php">Operations Hub</a></li>
                                <?php if ($navRole === ROLE_USER): ?>
                                <li><a class="dropdown-item" href="bookings.php">Booking History</a></li>
                                <li><a class="dropdown-item" href="my-cargo.php">Cargo &amp; Luggage</a></li>
                                <?php elseif ($navRole === ROLE_EMPLOYEE): ?>
                                <li><a class="dropdown-item" href="check-passengers.php">Passenger Desk</a></li>
                                <li><a class="dropdown-item" href="my-trains.php">My Trains</a></li>
                                <?php elseif ($navRole === ROLE_ADMIN): ?>
                                <li><a class="dropdown-item" href="manage-routes.php">Route Control</a></li>
                                <li><a class="dropdown-item" href="audit-logs.php">Audit Logs</a></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="logout.php">Logout</a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link btn btn-outline-light btn-login mx-1" href="login.php">Login</a></li>
                        <li class="nav-item"><a class="nav-link btn btn-warning btn-signup mx-1" href="signup.php">Sign Up</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
    <?php endif; ?>

    <main>

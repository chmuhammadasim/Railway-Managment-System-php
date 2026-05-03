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

// Current page for active nav link detection
$current_page = basename($_SERVER['PHP_SELF'] ?? 'index.php');

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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="public/css/style.css">
    <style>
        /* ── Panel layout (admin / employee dashboards hide the main navbar) ── */
        body.panel-layout > main { padding-top: 0; }
        body.panel-layout .adm-wrap,
        body.panel-layout .emp-wrap { min-height: 100vh !important; }
        body.panel-layout .adm-sidebar,
        body.panel-layout .emp-sidebar { top: 0 !important; height: 100vh !important; }

        /* ── Navbar base ── */
        .site-navbar {
            background: linear-gradient(135deg, #1e40af 0%, #1d4ed8 50%, #2563eb 100%);
            box-shadow: 0 2px 16px rgba(30,64,175,.35);
            padding: .65rem 0;
            position: sticky;
            top: 0;
            z-index: 1030;
        }
        .site-navbar .navbar-brand {
            display: flex; align-items: center; gap: .55rem;
            font-size: 1.15rem; font-weight: 800; color: #fff;
            letter-spacing: -.3px; text-decoration: none;
        }
        .site-navbar .navbar-brand:hover { color: #fff; opacity: .92; }
        .site-navbar .brand-icon {
            width: 34px; height: 34px; border-radius: 8px;
            background: rgba(255,255,255,.18);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.05rem; color: #fbbf24; flex-shrink: 0;
        }
        .site-navbar .brand-text { color: #fff; }
        .site-navbar .brand-sub { font-size: .65rem; font-weight: 500; color: rgba(255,255,255,.6); letter-spacing: .5px; display: block; line-height: 1; margin-top: 1px; text-transform: uppercase; }

        /* ── Nav links ── */
        .site-navbar .nav-link {
            color: rgba(255,255,255,.82) !important;
            font-size: .875rem; font-weight: 500;
            padding: .4rem .65rem !important;
            border-radius: 7px;
            transition: background .18s, color .18s;
            display: flex; align-items: center; gap: .3rem;
        }
        .site-navbar .nav-link:hover {
            color: #fff !important;
            background: rgba(255,255,255,.12);
        }
        .site-navbar .nav-link.active {
            color: #fff !important;
            background: rgba(255,255,255,.18);
            font-weight: 600;
        }
        .site-navbar .nav-link i { font-size: .95rem; }

        /* ── Notification bell ── */
        .notif-wrap { position: relative; display: inline-flex; }
        .notif-wrap .notif-badge {
            position: absolute; top: -4px; right: -6px;
            min-width: 16px; height: 16px; border-radius: 10px;
            background: #ef4444; color: #fff;
            font-size: .58rem; font-weight: 800;
            display: flex; align-items: center; justify-content: center;
            border: 2px solid #1d4ed8; line-height: 1;
        }

        /* ── User dropdown trigger ── */
        .site-navbar .user-trigger {
            display: flex; align-items: center; gap: .45rem;
            background: rgba(255,255,255,.13);
            border: 1px solid rgba(255,255,255,.2);
            border-radius: 9px;
            padding: .3rem .75rem .3rem .45rem !important;
            cursor: pointer;
        }
        .site-navbar .user-trigger:hover { background: rgba(255,255,255,.2); }
        .site-navbar .user-trigger .u-avatar {
            width: 28px; height: 28px; border-radius: 50%;
            background: linear-gradient(135deg,#fbbf24,#f59e0b);
            display: flex; align-items: center; justify-content: center;
            font-size: .78rem; font-weight: 800; color: #1e3a5f; flex-shrink: 0;
        }
        .site-navbar .user-trigger .u-name { font-size: .82rem; font-weight: 600; color: #fff; max-width: 130px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .site-navbar .user-trigger .u-role {
            font-size: .58rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .5px; padding: .1em .4em; border-radius: 4px;
        }
        .u-role-user     { background: #dbeafe; color: #1e40af; }
        .u-role-admin    { background: #fef3c7; color: #92400e; }
        .u-role-employee { background: #d1fae5; color: #065f46; }

        /* ── Dropdown menu ── */
        .site-navbar .dropdown-menu {
            border: none; border-radius: 12px; margin-top: .4rem;
            box-shadow: 0 8px 30px rgba(0,0,0,.15);
            padding: .5rem;
            min-width: 210px;
        }
        .site-navbar .dropdown-item {
            border-radius: 7px; padding: .5rem .85rem;
            font-size: .875rem; font-weight: 500; color: #374151;
            display: flex; align-items: center; gap: .55rem;
            transition: background .15s;
        }
        .site-navbar .dropdown-item:hover { background: #f1f5f9; color: #111827; }
        .site-navbar .dropdown-item.text-danger:hover { background: #fee2e2; }
        .site-navbar .dropdown-divider { margin: .35rem .4rem; border-color: #e5e7eb; }

        /* ── Login / Sign up buttons ── */
        .btn-nav-login {
            border: 1.5px solid rgba(255,255,255,.45) !important;
            border-radius: 8px !important;
            color: rgba(255,255,255,.9) !important;
            font-weight: 600 !important;
            font-size: .875rem !important;
            background: transparent !important;
        }
        .btn-nav-login:hover { background: rgba(255,255,255,.12) !important; border-color: rgba(255,255,255,.75) !important; color: #fff !important; }
        .btn-nav-signup {
            background: #f59e0b !important;
            border-color: transparent !important;
            border-radius: 8px !important;
            color: #fff !important;
            font-weight: 700 !important;
            font-size: .875rem !important;
            box-shadow: 0 2px 8px rgba(245,158,11,.4) !important;
        }
        .btn-nav-signup:hover { background: #d97706 !important; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(245,158,11,.5) !important; }

        /* ── Mobile toggler ── */
        .site-navbar .navbar-toggler { border: 1.5px solid rgba(255,255,255,.35); padding: .3rem .55rem; }
        .site-navbar .navbar-toggler:focus { box-shadow: none; }
    </style>
</head>
<body class="page-transition<?php echo $hideMainNavbar ? ' panel-layout' : ''; ?>">

<?php if (!$hideMainNavbar): ?>
<nav class="navbar navbar-expand-lg site-navbar">
    <div class="container">

        <!-- Brand -->
        <a class="navbar-brand" href="index.php">
            <span class="brand-icon"><i class="bi bi-train-front-fill"></i></span>
            <span class="brand-text">Railway System<span class="brand-sub">Pakistan Railways</span></span>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
                aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-center gap-1">

                <!-- Home -->
                <li class="nav-item">
                    <a class="nav-link<?= ($current_page === 'index.php') ? ' active' : '' ?>" href="index.php">
                        <i class="bi bi-house"></i> Home
                    </a>
                </li>

                <?php if (class_exists('User') ? User::isLoggedIn() : (isset($_SESSION['user_id']) && $_SESSION['user_id'])): ?>
                    <?php $navRole = $_SESSION['role'] ?? ROLE_USER; ?>

                    <!-- Dashboard -->
                    <li class="nav-item">
                        <a class="nav-link<?= ($current_page === 'dashboard.php') ? ' active' : '' ?>" href="dashboard.php">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>

                    <?php if ($navRole === ROLE_USER): ?>
                        <li class="nav-item">
                            <a class="nav-link<?= ($current_page === 'bookings.php') ? ' active' : '' ?>" href="bookings.php">
                                <i class="bi bi-ticket-perforated"></i> My Bookings
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link<?= ($current_page === 'my-cargo.php') ? ' active' : '' ?>" href="my-cargo.php">
                                <i class="bi bi-box-seam"></i> Cargo
                            </a>
                        </li>
                    <?php elseif ($navRole === ROLE_EMPLOYEE): ?>
                        <li class="nav-item">
                            <a class="nav-link<?= ($current_page === 'check-passengers.php') ? ' active' : '' ?>" href="check-passengers.php">
                                <i class="bi bi-people"></i> Passengers
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link<?= ($current_page === 'my-trains.php') ? ' active' : '' ?>" href="my-trains.php">
                                <i class="bi bi-train-front"></i> My Trains
                            </a>
                        </li>
                    <?php elseif ($navRole === ROLE_ADMIN): ?>
                        <li class="nav-item">
                            <a class="nav-link<?= ($current_page === 'manage-routes.php') ? ' active' : '' ?>" href="manage-routes.php">
                                <i class="bi bi-map"></i> Routes
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link<?= ($current_page === 'manage-trains.php') ? ' active' : '' ?>" href="manage-trains.php">
                                <i class="bi bi-train-front"></i> Trains
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php
                    // Notification unread count
                    $notif_unread = 0;
                    if (isset($db) && $navRole === ROLE_USER) {
                        $notif_row = $db->selectRow("SELECT COUNT(*) AS cnt FROM notifications WHERE user_id=".(int)$_SESSION['user_id']." AND is_read=0");
                        $notif_unread = (int)($notif_row['cnt'] ?? 0);
                    }
                    ?>

                    <!-- Notification bell -->
                    <li class="nav-item ms-1">
                        <a class="nav-link<?= ($current_page === 'notifications.php') ? ' active' : '' ?>" href="notifications.php" title="Notifications">
                            <span class="notif-wrap">
                                <i class="bi bi-bell-fill"></i>
                                <?php if ($notif_unread > 0): ?>
                                <span class="notif-badge"><?= $notif_unread ?></span>
                                <?php endif; ?>
                            </span>
                        </a>
                    </li>

                    <!-- User dropdown -->
                    <?php
                    $navFullName = htmlspecialchars($_SESSION['full_name'] ?? 'Account');
                    $navInitial  = strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1));
                    ?>
                    <li class="nav-item dropdown ms-1">
                        <a class="nav-link dropdown-toggle user-trigger" href="#" id="userMenu"
                           role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="u-avatar"><?= $navInitial ?></span>
                            <span class="u-name"><?= $navFullName ?></span>
                            <span class="u-role u-role-<?= htmlspecialchars($navRole) ?>"><?= strtoupper($navRole) ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userMenu">
                            <li>
                                <a class="dropdown-item" href="profile.php">
                                    <i class="bi bi-person text-muted"></i> Profile
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="operations-hub.php">
                                    <i class="bi bi-grid text-muted"></i> Operations Hub
                                </a>
                            </li>
                            <?php if ($navRole === ROLE_USER): ?>
                            <li>
                                <a class="dropdown-item" href="bookings.php">
                                    <i class="bi bi-ticket-perforated text-muted"></i> Booking History
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="my-cargo.php">
                                    <i class="bi bi-box-seam text-muted"></i> Cargo &amp; Luggage
                                </a>
                            </li>
                            <?php elseif ($navRole === ROLE_EMPLOYEE): ?>
                            <li>
                                <a class="dropdown-item" href="check-passengers.php">
                                    <i class="bi bi-people text-muted"></i> Passenger Desk
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="my-trains.php">
                                    <i class="bi bi-train-front text-muted"></i> My Trains
                                </a>
                            </li>
                            <?php elseif ($navRole === ROLE_ADMIN): ?>
                            <li>
                                <a class="dropdown-item" href="manage-routes.php">
                                    <i class="bi bi-map text-muted"></i> Route Control
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="audit-logs.php">
                                    <i class="bi bi-journal-text text-muted"></i> Audit Logs
                                </a>
                            </li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item text-danger" href="logout.php">
                                    <i class="bi bi-box-arrow-right"></i> Logout
                                </a>
                            </li>
                        </ul>
                    </li>

                <?php else: ?>
                    <li class="nav-item ms-2">
                        <a class="nav-link btn-nav-login" href="login.php">
                            <i class="bi bi-box-arrow-in-right me-1"></i>Login
                        </a>
                    </li>
                    <li class="nav-item ms-1">
                        <a class="nav-link btn-nav-signup" href="signup.php">
                            <i class="bi bi-person-plus me-1"></i>Sign Up
                        </a>
                    </li>
                <?php endif; ?>

            </ul>
        </div>
    </div>
</nav>
<?php endif; ?>

<main>

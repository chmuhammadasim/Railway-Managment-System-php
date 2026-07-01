<?php
// Database Configuration File

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'railway_system');
define('DB_PORT', 3306);

// Site Configuration
define('SITE_URL', 'http://localhost/railway/');
define('SITE_NAME', 'Railway Management System');

// Session Configuration
define('SESSION_TIMEOUT', 1800); // 30 minutes

// Payment Gateway (Example - Update with your actual gateway)
define('PAYMENT_GATEWAY', 'stripe');
define('PAYMENT_API_KEY', 'your_api_key_here');

// Email Configuration
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_USER', 'your_email@gmail.com');
define('MAIL_PASS', 'your_password');
define('MAIL_FROM', 'noreply@railwaysystem.com');

// Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
// ini_set('error_log', __DIR__ . '/../logs/error.log');

// Start Session
session_start();

// Define User Roles
define('ROLE_USER', 'user');
define('ROLE_ADMIN', 'admin');

// Booking Status Constants
define('BOOKING_PENDING', 'pending');
define('BOOKING_CONFIRMED', 'confirmed');
define('BOOKING_CANCELLED', 'cancelled');

// Payment Status Constants
define('PAYMENT_PENDING', 'pending');
define('PAYMENT_COMPLETED', 'completed');
define('PAYMENT_FAILED', 'failed');
define('PAYMENT_REFUNDED', 'refunded');
?>

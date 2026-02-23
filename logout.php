<?php
// logout.php - Logout User

require_once 'config/database.php';

// Destroy session and redirect to home
session_unset();
session_destroy();

header('Location: index.php');
exit();

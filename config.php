<?php
// config.php - Improved version with security settings

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'jsistem_ap');
define('DB_USER', 'jsistem_apuser');
define('DB_PASS', 'pAP3779');

// User level constants
define('USER_LEVEL_ADMIN', 1);
define('USER_LEVEL_USER', 2);

// Application settings
define('ITEMS_PER_PAGE', 20);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes in seconds

// Session security configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Strict');
// Uncomment if using HTTPS:
// ini_set('session.cookie_secure', 1);

session_start();

// TEMPORARY: Disable session regeneration for debugging
// Regenerate session ID periodically
// if (!isset($_SESSION['created'])) {
//     $_SESSION['created'] = time();
// } else if (time() - $_SESSION['created'] > 1800) {
//     // Session started more than 30 minutes ago
//     session_regenerate_id(true);
//     $_SESSION['created'] = time();
// }

// Database connection
try {
	$pdo = new PDO(
		"mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", 
		DB_USER, 
		DB_PASS,
		[
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES => false,
		]
	);
} catch (PDOException $e) {
	error_log("Database connection failed: " . $e->getMessage());
	die("Database connection failed. Please contact administrator.");
}
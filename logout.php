<?php
// logout.php - Improved version with proper cleanup
require_once 'bootstrap.php';
require_once 'functions.php';

// Check if user is logged in
if (isset($_SESSION['user_id'])) {
	$user_id = $_SESSION['user_id'];
	$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
	
	// Log the logout action
	log_action($user_id, 'logout', $ip);
	
	// Clear all session variables
	$_SESSION = array();
	
	// Destroy the session cookie
	if (isset($_COOKIE[session_name()])) {
		setcookie(session_name(), '', time() - 3600, '/');
	}
	
	// Destroy the session
	session_destroy();
}

// Redirect to login page
header('Location: login.php');
exit;
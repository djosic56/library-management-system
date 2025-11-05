<?php
// functions.php - Improved version with security enhancements
require_once 'config.php';

/**
 * Generate CSRF token
 */
function generate_csrf_token() {
	if (empty($_SESSION['csrf_token'])) {
		$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
	}
	return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 */
function validate_csrf_token($token) {
	if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
		return false;
	}
	return true;
}

/**
 * Get CSRF input field
 */
function csrf_field() {
	$token = generate_csrf_token();
	return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Verify CSRF token from POST
 */
function verify_csrf() {
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		$token = $_POST['csrf_token'] ?? '';
		if (!validate_csrf_token($token)) {
			http_response_code(403);
			error_log("CSRF validation failed for user: " . ($_SESSION['user_id'] ?? 'unknown'));
			die('CSRF token validation failed');
		}
	}
}

/**
 * Check login attempts and apply rate limiting
 */
function check_login_attempts($username, $ip) {
	global $pdo;
	
	$time_window = date('Y-m-d H:i:s', time() - LOGIN_LOCKOUT_TIME);
	
	// Check failed attempts by username
	$stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempt 
						   WHERE username = ? AND attempted_at > ? AND success = 0");
	$stmt->execute([$username, $time_window]);
	$username_attempts = $stmt->fetchColumn();
	
	// Check failed attempts by IP
	$stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempt 
						   WHERE ip_address = ? AND attempted_at > ? AND success = 0");
	$stmt->execute([$ip, $time_window]);
	$ip_attempts = $stmt->fetchColumn();
	
	return ($username_attempts >= MAX_LOGIN_ATTEMPTS || $ip_attempts >= MAX_LOGIN_ATTEMPTS);
}

/**
 * Log login attempt
 */
function log_login_attempt($username, $user_id, $ip, $success) {
	global $pdo;
	
	$stmt = $pdo->prepare("INSERT INTO login_attempt (username, user_id, ip_address, success) 
						   VALUES (?, ?, ?, ?)");
	$stmt->execute([$username, $user_id, $ip, $success ? 1 : 0]);
}

/**
 * Require user to be logged in
 */
function require_login() {
	if (!isset($_SESSION['user_id'])) {
		// Store the intended destination
		$redirect = $_SERVER['REQUEST_URI'];

		// Redirect to login page with return URL
		header('Location: login.php?redirect=' . urlencode($redirect));
		exit;
	}
}
/**
 * Require change password modal
 */
function require_change_password() {
	require_login();
	
	// Redirect to change password page
	header('Location: change_password.php');
	exit;
}

/**
 * Check if user is admin
 */
function is_admin() {
	return isset($_SESSION['level']) && (int)$_SESSION['level'] === USER_LEVEL_ADMIN;
}

/**
 * Require admin privileges
 */
function require_admin() {
	require_login();
	if (!is_admin()) {
		http_response_code(403);
		header('Location: index.php');
		exit;
	}
}

/**
 * Log user action
 */
function log_action($user_id, $action, $ip, $details = null) {
	global $pdo;
	
	try {
		$stmt = $pdo->prepare("INSERT INTO user_log (user_id, action, ip, details) VALUES (?, ?, ?, ?)");
		$stmt->execute([$user_id, $action, $ip, $details]);
	} catch (PDOException $e) {
		error_log("Failed to log action: " . $e->getMessage());
	}
}

/**
 * Validate email format
 */
function validate_email($email) {
	return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Sanitize string input
 */
function sanitize_string($input) {
	return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Get books with filters
 */
function get_books($search_title, $search_author, $filter_status, $filter_invoice, $page = 1, $sort_by = 'id', $sort_order = 'DESC') {
	global $pdo;
	
	$offset = ($page - 1) * ITEMS_PER_PAGE;
	$params = [];
	
	$sql = "SELECT b.id, b.title, b.pages, b.date_start, b.date_finish, b.id_status, b.id_formating, b.invoice, 
			s.name as status_name, f.shortname as format_shortname, 
			GROUP_CONCAT(CONCAT(a.fname, ' ', a.name) SEPARATOR ', ') as authors
			FROM book b
			LEFT JOIN status s ON b.id_status = s.id
			LEFT JOIN formating f ON b.id_formating = f.id
			LEFT JOIN book_author ba ON b.id = ba.id_book
			LEFT JOIN author a ON ba.id_author = a.id
			WHERE 1=1";
	
	if ($search_title) {
		$sql .= " AND b.title LIKE ?";
		$params[] = "%$search_title%";
	}
	
	if ($search_author) {
		$sql .= " AND (a.fname LIKE ? OR a.name LIKE ?)";
		$params[] = "%$search_author%";
		$params[] = "%$search_author%";
	}
	
	if ($filter_status !== '') {
		$sql .= " AND b.id_status = ?";
		$params[] = (int)$filter_status;
	}
	
	if ($filter_invoice !== '') {
		$sql .= " AND b.invoice = ?";
		$params[] = (int)$filter_invoice;
	}
	
	$sql .= " GROUP BY b.id";
	
	// Validate sort parameters
	$valid_sort = ['id', 'title', 'date_start', 'date_finish'];
	$sort_by = in_array($sort_by, $valid_sort) ? $sort_by : 'id';
	$sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';
	
	$sql .= " ORDER BY b.$sort_by $sort_order LIMIT ? OFFSET ?";
	
	$stmt = $pdo->prepare($sql);
	$param_index = 1;
	foreach ($params as $value) {
		$stmt->bindValue($param_index++, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
	}
	$stmt->bindValue($param_index++, ITEMS_PER_PAGE, PDO::PARAM_INT);
	$stmt->bindValue($param_index++, $offset, PDO::PARAM_INT);
	
	$stmt->execute();
	return $stmt->fetchAll();
}

/**
 * Get total books count with filters
 */
function get_books_count($search_title, $search_author, $filter_status, $filter_invoice) {
	global $pdo;
	
	$params = [];
	$sql = "SELECT COUNT(DISTINCT b.id) as total
			FROM book b
			LEFT JOIN book_author ba ON b.id = ba.id_book
			LEFT JOIN author a ON ba.id_author = a.id
			WHERE 1=1";
	
	if ($search_title) {
		$sql .= " AND b.title LIKE ?";
		$params[] = "%$search_title%";
	}
	
	if ($search_author) {
		$sql .= " AND (a.fname LIKE ? OR a.name LIKE ?)";
		$params[] = "%$search_author%";
		$params[] = "%$search_author%";
	}
	
	if ($filter_status !== '') {
		$sql .= " AND b.id_status = ?";
		$params[] = (int)$filter_status;
	}
	
	if ($filter_invoice !== '') {
		$sql .= " AND b.invoice = ?";
		$params[] = (int)$filter_invoice;
	}
	
	$stmt = $pdo->prepare($sql);
	$stmt->execute($params);
	return $stmt->fetchColumn();
}

/**
 * Get all statuses
 */
function get_statuses() {
	global $pdo;
	$stmt = $pdo->query("SELECT * FROM status ORDER BY name");
	return $stmt->fetchAll();
}

/**
 * Get all formatings
 */
function get_formatings() {
	global $pdo;
	$stmt = $pdo->query("SELECT * FROM formating ORDER BY name");
	return $stmt->fetchAll();
}
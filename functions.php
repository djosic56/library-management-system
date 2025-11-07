<?php
// functions.php - Legacy wrapper functions (refactored to use Helper classes)
require_once 'config.php';

use App\Helpers\SecurityHelper;
use App\Helpers\AuthHelper;
use App\Helpers\ValidationHelper;
use App\Helpers\LogHelper;

// ========== CSRF Protection (delegated to SecurityHelper) ==========

/**
 * Generate CSRF token
 * @deprecated Use SecurityHelper::generateCsrfToken() instead
 */
function generate_csrf_token() {
	return SecurityHelper::generateCsrfToken();
}

/**
 * Validate CSRF token
 * @deprecated Use SecurityHelper::validateCsrfToken() instead
 */
function validate_csrf_token($token) {
	return SecurityHelper::validateCsrfToken($token);
}

/**
 * Get CSRF input field
 * @deprecated Use SecurityHelper::csrfField() instead
 */
function csrf_field() {
	return SecurityHelper::csrfField();
}

/**
 * Verify CSRF token from POST
 * @deprecated Use SecurityHelper::verifyCsrf() instead
 */
function verify_csrf() {
	SecurityHelper::verifyCsrf();
}

// ========== Authentication & Authorization (delegated to AuthHelper) ==========

/**
 * Check login attempts and apply rate limiting
 * @deprecated Use AuthHelper::checkLoginAttempts() instead
 */
function check_login_attempts($username, $ip) {
	return AuthHelper::checkLoginAttempts($username, $ip);
}

/**
 * Log login attempt
 * @deprecated Use AuthHelper::logLoginAttempt() instead
 */
function log_login_attempt($username, $user_id, $ip, $success) {
	AuthHelper::logLoginAttempt($username, $user_id, $ip, $success);
}

/**
 * Require user to be logged in
 * @deprecated Use AuthHelper::requireLogin() instead
 */
function require_login() {
	AuthHelper::requireLogin();
}

/**
 * Require change password modal
 * @deprecated Use AuthHelper::requireChangePassword() instead
 */
function require_change_password() {
	AuthHelper::requireChangePassword();
}

/**
 * Check if user is admin
 * @deprecated Use AuthHelper::isAdmin() instead
 */
function is_admin() {
	return AuthHelper::isAdmin();
}

/**
 * Require admin privileges
 * @deprecated Use AuthHelper::requireAdmin() instead
 */
function require_admin() {
	AuthHelper::requireAdmin();
}

// ========== Logging (delegated to LogHelper) ==========

/**
 * Log user action
 * @deprecated Use LogHelper::logAction() instead
 */
function log_action($user_id, $action, $ip, $details = null) {
	LogHelper::logAction($user_id, $action, $ip, $details);
}

// ========== Validation (delegated to ValidationHelper) ==========

/**
 * Validate email format
 * @deprecated Use ValidationHelper::validateEmail() instead
 */
function validate_email($email) {
	return ValidationHelper::validateEmail($email);
}

/**
 * Sanitize string input
 * @deprecated Use ValidationHelper::sanitizeString() instead
 */
function sanitize_string($input) {
	return ValidationHelper::sanitizeString($input);
}

// ========== Legacy Database Functions (for backward compatibility) ==========
// NOTE: These should be replaced with BookService/AuthorService methods
// Kept for backward compatibility with older pages

/**
 * Get books with filters
 * @deprecated Use BookService::getBooks() instead
 */
function get_books($search_title, $search_author, $filter_status, $filter_invoice, $page = 1, $sort_by = 'id', $sort_order = 'DESC') {
	$pdo = \App\Database\Database::getInstance()->getConnection();
	
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
	$pdo = \App\Database\Database::getInstance()->getConnection();
	
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
	$pdo = \App\Database\Database::getInstance()->getConnection();
	$stmt = $pdo->query("SELECT * FROM status ORDER BY name");
	return $stmt->fetchAll();
}

/**
 * Get all formatings
 */
function get_formatings() {
	$pdo = \App\Database\Database::getInstance()->getConnection();
	$stmt = $pdo->query("SELECT * FROM formating ORDER BY name");
	return $stmt->fetchAll();
}
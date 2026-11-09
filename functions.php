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
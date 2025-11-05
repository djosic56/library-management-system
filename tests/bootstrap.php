<?php
/**
 * PHPUnit Bootstrap File
 * Sets up the test environment
 */

// Start output buffering to prevent "headers already sent" errors
ob_start();

// Define test environment
define('TEST_ENV', true);

// Load Composer autoloader if available
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// Mock session functions for testing
if (!function_exists('session_status')) {
    function session_status() {
        return PHP_SESSION_ACTIVE;
    }
}

// Initialize $_SESSION if not already set
if (!isset($_SESSION)) {
    $_SESSION = [];
}

// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Load test helpers
require_once __DIR__ . '/TestHelper.php';

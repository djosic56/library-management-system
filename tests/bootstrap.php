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

// Helpers and legacy functions take their connection from the Database singleton,
// so point it at the test database before any test can initialize it with
// something else. Tests delete rows, so refuse any database not named *_test.
$testDbName = getenv('DB_NAME') ?: 'jsistem_ap_test';
if (!str_ends_with($testDbName, '_test')) {
    fwrite(STDERR, "Refusing to run tests against database '$testDbName' (name must end in _test).\n");
    exit(1);
}
try {
    \App\Database\Database::getInstance([
        'host' => getenv('DB_HOST') ?: 'localhost',
        'database' => $testDbName,
        'username' => getenv('DB_USER') ?: 'jsistem_apuser',
        'password' => getenv('DB_PASS') ?: '',
    ]);
} catch (\RuntimeException $e) {
    // No test database: tests that need it skip themselves (TestHelper::database()).
}

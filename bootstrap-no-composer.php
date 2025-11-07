<?php

/**
 * Bootstrap file (WITHOUT Composer)
 * Initializes the application and provides access to core services
 */

// Load custom autoloader (instead of Composer)
require_once __DIR__ . '/autoload.php';

// Load legacy config (for backward compatibility)
require_once __DIR__ . '/config.php';

use App\Database\Database;
use App\Services\BookService;
use App\Services\AuthorService;

/**
 * Initialize Database connection
 */
function getDatabase(): Database
{
    static $database = null;

    if ($database === null) {
        $config = [
            'host' => DB_HOST,
            'database' => DB_NAME,
            'username' => DB_USER,
            'password' => DB_PASS,
        ];

        $database = Database::getInstance($config);
    }

    return $database;
}

/**
 * Get BookService instance
 */
function getBookService(): BookService
{
    static $bookService = null;

    if ($bookService === null) {
        $bookService = new BookService(getDatabase());
    }

    return $bookService;
}

/**
 * Get AuthorService instance
 */
function getAuthorService(): AuthorService
{
    static $authorService = null;

    if ($authorService === null) {
        $authorService = new AuthorService(getDatabase());
    }

    return $authorService;
}

/**
 * Helper function to log actions
 */
function logAction(int $userId, string $action, string $ip, ?string $details = null): void
{
    $pdo = \App\Database\Database::getInstance()->getConnection();

    try {
        $stmt = $pdo->prepare("INSERT INTO user_log (user_id, action, ip, details) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, $action, $ip, $details]);
    } catch (PDOException $e) {
        error_log("Failed to log action: " . $e->getMessage());
    }
}

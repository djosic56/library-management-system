<?php
/**
 * Test Helper Class
 * Provides utility methods for tests
 */

class TestHelper
{
    /**
     * Create a test database connection
     */
    public static function createTestDatabase()
    {
        $host = getenv('DB_HOST') ?: 'localhost';
        $dbname = getenv('DB_NAME') ?: 'jsistem_ap_test';
        $user = getenv('DB_USER') ?: 'jsistem_apuser';
        $pass = getenv('DB_PASS') ?: 'pAP3779';

        try {
            $pdo = new PDO(
                "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            return $pdo;
        } catch (PDOException $e) {
            throw new Exception("Test database connection failed: " . $e->getMessage());
        }
    }

    /**
     * Clean up test data
     */
    public static function cleanupTestData($pdo)
    {
        $tables = ['login_attempt', 'user_log', 'book_author', 'book', 'author', 'users'];

        foreach ($tables as $table) {
            try {
                $pdo->exec("DELETE FROM $table WHERE id > 0");
            } catch (PDOException $e) {
                // Table might not exist, continue
            }
        }
    }

    /**
     * Create test user
     */
    public static function createTestUser($pdo, $username, $password, $level = 2)
    {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, password, level) VALUES (?, ?, ?)");
        $stmt->execute([$username, $hashed, $level]);
        return $pdo->lastInsertId();
    }

    /**
     * Create test book
     */
    public static function createTestBook($pdo, $title, $pages = 100, $status = 1, $invoice = 0)
    {
        $stmt = $pdo->prepare("INSERT INTO book (title, pages, id_status, invoice) VALUES (?, ?, ?, ?)");
        $stmt->execute([$title, $pages, $status, $invoice]);
        return $pdo->lastInsertId();
    }

    /**
     * Create test author
     */
    public static function createTestAuthor($pdo, $fname, $name, $email = null)
    {
        $stmt = $pdo->prepare("INSERT INTO author (fname, name, email) VALUES (?, ?, ?)");
        $stmt->execute([$fname, $name, $email]);
        return $pdo->lastInsertId();
    }

    /**
     * Reset session for testing
     */
    public static function resetSession()
    {
        $_SESSION = [];
    }

    /**
     * Set session as logged in user
     */
    public static function loginAsUser($userId, $level = 2)
    {
        $_SESSION['user_id'] = $userId;
        $_SESSION['level'] = $level;
        $_SESSION['username'] = 'testuser';
    }

    /**
     * Set session as admin
     */
    public static function loginAsAdmin($userId = 1)
    {
        self::loginAsUser($userId, 1);
    }
}

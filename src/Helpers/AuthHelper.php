<?php

namespace App\Helpers;

use App\Database\Database;
use PDOException;

/**
 * Auth Helper
 * Authentication and authorization utilities
 */
class AuthHelper
{
    private const SESSION_IDLE_TIMEOUT = 1800;

    /**
     * Check login attempts and apply rate limiting
     */
    public static function checkLoginAttempts(string $username, string $ip): bool
    {
        $pdo = Database::getInstance()->getConnection();

        $timeWindow = date('Y-m-d H:i:s', time() - LOGIN_LOCKOUT_TIME);

        // Check failed attempts by username
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempt
                               WHERE username = ? AND attempted_at > ? AND success = 0");
        $stmt->execute([$username, $timeWindow]);
        $usernameAttempts = $stmt->fetchColumn();

        // Check failed attempts by IP
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempt
                               WHERE ip_address = ? AND attempted_at > ? AND success = 0");
        $stmt->execute([$ip, $timeWindow]);
        $ipAttempts = $stmt->fetchColumn();

        return ($usernameAttempts >= MAX_LOGIN_ATTEMPTS || $ipAttempts >= MAX_LOGIN_ATTEMPTS);
    }

    /**
     * Log login attempt
     */
    public static function logLoginAttempt(string $username, ?int $userId, string $ip, bool $success): void
    {
        $pdo = Database::getInstance()->getConnection();

        $stmt = $pdo->prepare("INSERT INTO login_attempt (username, user_id, ip_address, success)
                               VALUES (?, ?, ?, ?)");
        $stmt->execute([$username, $userId, $ip, $success ? 1 : 0]);
    }

    /**
     * Keep the session in line with the database on every request: end it when the
     * user was deleted or idle too long, and apply a changed user level at once.
     */
    public static function validateSession(): void
    {
        if (!isset($_SESSION['user_id'])) {
            return;
        }

        $lastActivity = $_SESSION['last_activity'] ?? time();
        if (time() - $lastActivity > self::SESSION_IDLE_TIMEOUT) {
            $_SESSION = [];
            return;
        }

        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare("SELECT level FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $level = $stmt->fetchColumn();

        if ($level === false) {
            $_SESSION = [];
            return;
        }

        $_SESSION['level'] = (int)$level;
        $_SESSION['last_activity'] = time();
    }

    /**
     * Require user to be logged in
     * Redirects to login page if not authenticated
     */
    public static function requireLogin(): void
    {
        if (!isset($_SESSION['user_id'])) {
            // Store the intended destination
            $redirect = $_SERVER['REQUEST_URI'];

            // Redirect to login page with return URL
            header('Location: login.php?redirect=' . urlencode($redirect));
            exit;
        }
    }

    /**
     * Check if user is admin
     */
    public static function isAdmin(): bool
    {
        return isset($_SESSION['level']) && (int)$_SESSION['level'] === USER_LEVEL_ADMIN;
    }

    /**
     * Require admin privileges
     * Redirects if not admin
     */
    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            http_response_code(403);
            header('Location: index.php');
            exit;
        }
    }

    /**
     * Require change password redirect
     */
    public static function requireChangePassword(): void
    {
        self::requireLogin();

        // Redirect to change password page
        header('Location: change_password.php');
        exit;
    }
}

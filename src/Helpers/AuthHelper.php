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

<?php

namespace App\Helpers;

/**
 * Security Helper
 * CSRF protection and security utilities
 */
class SecurityHelper
{
    /**
     * Generate CSRF token
     */
    public static function generateCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Validate CSRF token
     */
    public static function validateCsrfToken(string $token): bool
    {
        if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            return false;
        }
        return true;
    }

    /**
     * Get CSRF input field HTML
     */
    public static function csrfField(): string
    {
        $token = self::generateCsrfToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Verify CSRF token from POST request
     * Dies with 403 if validation fails
     */
    public static function verifyCsrf(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = $_POST['csrf_token'] ?? '';
            if (!self::validateCsrfToken($token)) {
                http_response_code(403);
                error_log("CSRF validation failed for user: " . ($_SESSION['user_id'] ?? 'unknown'));
                die('CSRF token validation failed');
            }
        }
    }
}

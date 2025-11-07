<?php

namespace App\Helpers;

/**
 * Validation Helper
 * Input validation and sanitization
 */
class ValidationHelper
{
    /**
     * Validate email format
     */
    public static function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Sanitize string input
     * Trims whitespace and escapes HTML
     */
    public static function sanitizeString(string $input): string
    {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

<?php

namespace App\Validators;

/**
 * Base Validator class
 * Provides validation methods for user input
 */
class Validator
{
    protected array $errors = [];

    /**
     * Validate required field
     */
    public function required($value, string $fieldName): bool
    {
        if (empty($value) && $value !== '0' && $value !== 0) {
            $this->errors[$fieldName] = "$fieldName is required.";
            return false;
        }
        return true;
    }

    /**
     * Validate email format
     */
    public function email(?string $email, string $fieldName = 'Email'): bool
    {
        if (empty($email)) {
            return true; // Allow empty if not required
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$fieldName] = "Invalid email format.";
            return false;
        }

        return true;
    }

    /**
     * Validate string length
     */
    public function length(string $value, int $min, int $max, string $fieldName): bool
    {
        $length = strlen($value);

        if ($length < $min || $length > $max) {
            $this->errors[$fieldName] = "$fieldName must be between $min and $max characters.";
            return false;
        }

        return true;
    }

    /**
     * Validate minimum value
     */
    public function min($value, $min, string $fieldName): bool
    {
        if ($value < $min) {
            $this->errors[$fieldName] = "$fieldName must be at least $min.";
            return false;
        }

        return true;
    }

    /**
     * Validate maximum value
     */
    public function max($value, $max, string $fieldName): bool
    {
        if ($value > $max) {
            $this->errors[$fieldName] = "$fieldName must not exceed $max.";
            return false;
        }

        return true;
    }

    /**
     * Validate numeric value
     */
    public function numeric($value, string $fieldName): bool
    {
        if (!is_numeric($value)) {
            $this->errors[$fieldName] = "$fieldName must be a number.";
            return false;
        }

        return true;
    }

    /**
     * Validate integer value
     */
    public function integer($value, string $fieldName): bool
    {
        if (!filter_var($value, FILTER_VALIDATE_INT) && $value !== 0 && $value !== '0') {
            $this->errors[$fieldName] = "$fieldName must be an integer.";
            return false;
        }

        return true;
    }

    /**
     * Validate date format
     */
    public function date(string $date, string $format = 'Y-m-d', string $fieldName = 'Date'): bool
    {
        $d = \DateTime::createFromFormat($format, $date);

        if (!$d || $d->format($format) !== $date) {
            $this->errors[$fieldName] = "Invalid date format for $fieldName.";
            return false;
        }

        return true;
    }

    /**
     * Validate date range (start date before end date)
     */
    public function dateRange(?string $startDate, ?string $endDate, string $fieldName = 'Date range'): bool
    {
        if (empty($startDate) || empty($endDate)) {
            return true; // Allow empty if not required
        }

        if ($startDate > $endDate) {
            $this->errors[$fieldName] = "Start date cannot be after finish date.";
            return false;
        }

        return true;
    }

    /**
     * Validate that value is in array
     */
    public function in($value, array $allowed, string $fieldName): bool
    {
        if (!in_array($value, $allowed, true)) {
            $this->errors[$fieldName] = "Invalid value for $fieldName.";
            return false;
        }

        return true;
    }

    /**
     * Sanitize string
     */
    public function sanitize(string $input): string
    {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Get all validation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get first error
     */
    public function getFirstError(): ?string
    {
        return !empty($this->errors) ? reset($this->errors) : null;
    }

    /**
     * Check if validation passed
     */
    public function passes(): bool
    {
        return empty($this->errors);
    }

    /**
     * Check if validation failed
     */
    public function fails(): bool
    {
        return !$this->passes();
    }

    /**
     * Clear errors
     */
    public function clearErrors(): void
    {
        $this->errors = [];
    }
}

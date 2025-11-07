<?php

namespace App\Exceptions;

/**
 * Database operation exceptions
 */
class DatabaseException extends AppException
{
    public function __construct(
        string $developerMessage,
        string $userMessage = "Database error. Please contact support.",
        int $code = 500,
        ?\Exception $previous = null
    ) {
        parent::__construct($developerMessage, $userMessage, $code, $previous);
    }
}

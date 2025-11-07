<?php

namespace App\Exceptions;

/**
 * Authentication exceptions
 */
class AuthenticationException extends AppException
{
    public function __construct(
        string $developerMessage = "Authentication failed",
        string $userMessage = "Invalid username or password.",
        int $code = 401
    ) {
        parent::__construct($developerMessage, $userMessage, $code);
    }
}

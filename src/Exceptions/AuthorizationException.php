<?php

namespace App\Exceptions;

/**
 * Authorization exceptions (access denied)
 */
class AuthorizationException extends AppException
{
    public function __construct(
        string $developerMessage = "Access denied",
        string $userMessage = "You don't have permission to access this resource.",
        int $code = 403
    ) {
        parent::__construct($developerMessage, $userMessage, $code);
    }
}

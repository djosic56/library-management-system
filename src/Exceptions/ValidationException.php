<?php

namespace App\Exceptions;

/**
 * Input validation exceptions
 */
class ValidationException extends AppException
{
    public function __construct(
        string $field,
        string $userMessage,
        int $code = 400
    ) {
        parent::__construct(
            "Validation failed for field: {$field}",
            $userMessage,
            $code
        );
    }
}

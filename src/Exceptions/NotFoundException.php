<?php

namespace App\Exceptions;

/**
 * Resource not found exceptions
 */
class NotFoundException extends AppException
{
    public function __construct(
        string $resource,
        int $id,
        int $code = 404
    ) {
        parent::__construct(
            "{$resource} with ID {$id} not found",
            "The requested item was not found.",
            $code
        );
    }
}

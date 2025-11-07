<?php

namespace App\Exceptions;

use Exception;

/**
 * Base application exception
 * All custom exceptions extend this
 */
class AppException extends Exception
{
    protected string $userMessage;

    public function __construct(
        string $developerMessage,
        string $userMessage = "An error occurred. Please try again.",
        int $code = 0,
        ?Exception $previous = null
    ) {
        parent::__construct($developerMessage, $code, $previous);
        $this->userMessage = $userMessage;
    }

    /**
     * Get user-friendly message
     */
    public function getUserMessage(): string
    {
        return $this->userMessage;
    }

    /**
     * Get developer message (same as getMessage())
     */
    public function getDeveloperMessage(): string
    {
        return $this->getMessage();
    }
}

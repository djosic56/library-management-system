<?php

namespace App\Exceptions;

use Exception;
use Throwable;

/**
 * Centralized error handler
 */
class ErrorHandler
{
    private static bool $registered = false;

    /**
     * Register error and exception handlers
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        set_exception_handler([self::class, 'handleException']);
        set_error_handler([self::class, 'handleError']);
        register_shutdown_function([self::class, 'handleShutdown']);

        self::$registered = true;
    }

    /**
     * Handle uncaught exceptions
     */
    public static function handleException(Throwable $exception): void
    {
        self::logException($exception);

        if ($exception instanceof AppException) {
            $userMessage = $exception->getUserMessage();
            $statusCode = $exception->getCode() ?: 500;
        } else {
            $userMessage = "An unexpected error occurred. Please contact support.";
            $statusCode = 500;
        }

        http_response_code($statusCode);

        if (self::isAjaxRequest()) {
            self::sendJsonError($userMessage, $statusCode);
        } else {
            self::displayErrorPage($userMessage, $statusCode);
        }
    }

    /**
     * Handle PHP errors
     */
    public static function handleError(
        int $severity,
        string $message,
        string $file,
        int $line
    ): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    /**
     * Handle fatal errors on shutdown
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            self::handleException(
                new \ErrorException(
                    $error['message'],
                    0,
                    $error['type'],
                    $error['file'],
                    $error['line']
                )
            );
        }
    }

    /**
     * Log exception
     */
    private static function logException(Throwable $exception): void
    {
        $message = sprintf(
            "[%s] %s in %s:%d\nStack trace:\n%s",
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        );

        error_log($message);
    }

    /**
     * Check if request is AJAX
     */
    private static function isAjaxRequest(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    /**
     * Send JSON error response
     */
    private static function sendJsonError(string $message, int $statusCode): void
    {
        header('Content-Type: application/json');
        echo json_encode([
            'error' => true,
            'message' => $message,
            'code' => $statusCode
        ]);
        exit;
    }

    /**
     * Display error page
     */
    private static function displayErrorPage(string $message, int $statusCode): void
    {
        // Simple error page
        echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Error</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 50px; background: #f5f5f5; }
        .error-box { background: white; padding: 30px; border-radius: 5px; max-width: 600px; margin: 0 auto; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #d9534f; }
        .code { color: #999; font-size: 14px; }
        a { color: #337ab7; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class='error-box'>
        <h1>Error {$statusCode}</h1>
        <p>{$message}</p>
        <p><a href='index.php'>← Return to homepage</a></p>
    </div>
</body>
</html>";
        exit;
    }
}

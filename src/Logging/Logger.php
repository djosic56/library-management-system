<?php

namespace App\Logging;

/**
 * Custom Logger Class
 * Simple PSR-3 inspired logger for application logging
 */
class Logger
{
    const EMERGENCY = 'EMERGENCY';
    const ALERT = 'ALERT';
    const CRITICAL = 'CRITICAL';
    const ERROR = 'ERROR';
    const WARNING = 'WARNING';
    const NOTICE = 'NOTICE';
    const INFO = 'INFO';
    const DEBUG = 'DEBUG';

    private static ?Logger $instance = null;
    private string $logFile;
    private string $minLevel;

    private array $levels = [
        self::EMERGENCY => 0,
        self::ALERT => 1,
        self::CRITICAL => 2,
        self::ERROR => 3,
        self::WARNING => 4,
        self::NOTICE => 5,
        self::INFO => 6,
        self::DEBUG => 7,
    ];

    /**
     * Private constructor (Singleton)
     */
    private function __construct(string $logFile = null, string $minLevel = self::INFO)
    {
        $this->logFile = $logFile ?? __DIR__ . '/../../logs/app.log';
        $this->minLevel = $minLevel;
        $this->ensureLogDirectory();
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(string $logFile = null, string $minLevel = self::INFO): self
    {
        if (self::$instance === null) {
            self::$instance = new self($logFile, $minLevel);
        }
        return self::$instance;
    }

    /**
     * Ensure log directory exists
     */
    private function ensureLogDirectory(): void
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * Check if level should be logged
     */
    private function shouldLog(string $level): bool
    {
        return $this->levels[$level] <= $this->levels[$this->minLevel];
    }

    /**
     * Log message with context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!$this->shouldLog($level)) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' | Context: ' . json_encode($context) : '';
        $logMessage = "[{$timestamp}] [{$level}] {$message}{$contextStr}\n";

        // Write to file
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);

        // Also write to error_log for critical levels
        if ($this->levels[$level] <= $this->levels[self::ERROR]) {
            error_log($logMessage);
        }
    }

    /**
     * System is unusable
     */
    public static function emergency(string $message, array $context = []): void
    {
        self::getInstance()->log(self::EMERGENCY, $message, $context);
    }

    /**
     * Action must be taken immediately
     */
    public static function alert(string $message, array $context = []): void
    {
        self::getInstance()->log(self::ALERT, $message, $context);
    }

    /**
     * Critical conditions
     */
    public static function critical(string $message, array $context = []): void
    {
        self::getInstance()->log(self::CRITICAL, $message, $context);
    }

    /**
     * Runtime errors
     */
    public static function error(string $message, array $context = []): void
    {
        self::getInstance()->log(self::ERROR, $message, $context);
    }

    /**
     * Exceptional occurrences that are not errors
     */
    public static function warning(string $message, array $context = []): void
    {
        self::getInstance()->log(self::WARNING, $message, $context);
    }

    /**
     * Normal but significant events
     */
    public static function notice(string $message, array $context = []): void
    {
        self::getInstance()->log(self::NOTICE, $message, $context);
    }

    /**
     * Interesting events
     */
    public static function info(string $message, array $context = []): void
    {
        self::getInstance()->log(self::INFO, $message, $context);
    }

    /**
     * Detailed debug information
     */
    public static function debug(string $message, array $context = []): void
    {
        self::getInstance()->log(self::DEBUG, $message, $context);
    }

    /**
     * Clear log file
     */
    public static function clear(): void
    {
        $instance = self::getInstance();
        if (file_exists($instance->logFile)) {
            file_put_contents($instance->logFile, '');
        }
    }

    /**
     * Get recent log entries
     */
    public static function getRecent(int $lines = 100): array
    {
        $instance = self::getInstance();
        if (!file_exists($instance->logFile)) {
            return [];
        }

        $file = file($instance->logFile);
        return array_slice($file, -$lines);
    }
}

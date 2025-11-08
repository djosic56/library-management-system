<?php

namespace App\Helpers;

use App\Database\Database;
use App\Logging\Logger;
use PDOException;

/**
 * Log Helper
 * User action logging
 */
class LogHelper
{
    /**
     * Log user action to database
     */
    public static function logAction(int $userId, string $action, string $ip, ?string $details = null): void
    {
        $pdo = Database::getInstance()->getConnection();

        try {
            $stmt = $pdo->prepare("INSERT INTO user_log (user_id, action, ip, details) VALUES (?, ?, ?, ?)");
            $stmt->execute([$userId, $action, $ip, $details]);

            // Also log to application log
            Logger::info("User action: {$action}", [
                'user_id' => $userId,
                'ip' => $ip,
                'details' => $details
            ]);
        } catch (PDOException $e) {
            Logger::error("Failed to log action to database: " . $e->getMessage(), [
                'user_id' => $userId,
                'action' => $action
            ]);
        }
    }
}

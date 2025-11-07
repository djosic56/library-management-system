<?php

namespace App\Helpers;

use App\Database\Database;
use PDOException;

/**
 * Log Helper
 * User action logging
 */
class LogHelper
{
    /**
     * Log user action
     */
    public static function logAction(int $userId, string $action, string $ip, ?string $details = null): void
    {
        $pdo = Database::getInstance()->getConnection();

        try {
            $stmt = $pdo->prepare("INSERT INTO user_log (user_id, action, ip, details) VALUES (?, ?, ?, ?)");
            $stmt->execute([$userId, $action, $ip, $details]);
        } catch (PDOException $e) {
            error_log("Failed to log action: " . $e->getMessage());
        }
    }
}

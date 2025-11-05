<?php

namespace App\Repositories;

use PDO;

/**
 * User Repository
 * Handles database operations for users
 */
class UserRepository extends Repository
{
    protected string $table = 'users';

    /**
     * Find user by username
     */
    public function findByUsername(string $username): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE username = ? LIMIT 1";
        return $this->queryOne($sql, [$username]);
    }

    /**
     * Find user by email
     */
    public function findByEmail(string $email): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE email = ? LIMIT 1";
        return $this->queryOne($sql, [$email]);
    }

    /**
     * Check if username exists
     */
    public function usernameExists(string $username, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE username = ?";
        $params = [$username];

        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn() > 0;
    }

    /**
     * Check if email exists
     */
    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE email = ?";
        $params = [$email];

        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn() > 0;
    }

    /**
     * Update password
     */
    public function updatePassword(int $userId, string $hashedPassword): bool
    {
        $sql = "UPDATE {$this->table} SET password = ? WHERE id = ?";
        return $this->execute($sql, [$hashedPassword, $userId]);
    }

    /**
     * Get all users with pagination
     */
    public function getUsers(int $page = 1, int $limit = 20): array
    {
        $offset = ($page - 1) * $limit;

        $sql = "SELECT id, username, email, level
                FROM {$this->table}
                ORDER BY id DESC
                LIMIT ? OFFSET ?";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Log user action
     */
    public function logAction(int $userId, string $action, string $ip, ?string $details = null): bool
    {
        $sql = "INSERT INTO user_log (user_id, action, ip, details) VALUES (?, ?, ?, ?)";
        return $this->execute($sql, [$userId, $action, $ip, $details]);
    }

    /**
     * Get user logs with pagination
     */
    public function getUserLogs(int $page = 1, int $limit = 50): array
    {
        $offset = ($page - 1) * $limit;

        $sql = "SELECT ul.*, u.username
                FROM user_log ul
                LEFT JOIN users u ON ul.user_id = u.id
                ORDER BY ul.timestamp DESC
                LIMIT ? OFFSET ?";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Check login attempts (rate limiting)
     */
    public function checkLoginAttempts(string $username, string $ip, int $maxAttempts = 5, int $timeWindow = 900): bool
    {
        $timeWindowStart = date('Y-m-d H:i:s', time() - $timeWindow);

        // Check failed attempts by username
        $sql = "SELECT COUNT(*) FROM login_attempt
                WHERE username = ? AND attempted_at > ? AND success = 0";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$username, $timeWindowStart]);
        $usernameAttempts = $stmt->fetchColumn();

        // Check failed attempts by IP
        $sql = "SELECT COUNT(*) FROM login_attempt
                WHERE ip_address = ? AND attempted_at > ? AND success = 0";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$ip, $timeWindowStart]);
        $ipAttempts = $stmt->fetchColumn();

        return ($usernameAttempts >= $maxAttempts || $ipAttempts >= $maxAttempts);
    }

    /**
     * Log login attempt
     */
    public function logLoginAttempt(string $username, ?int $userId, string $ip, bool $success): bool
    {
        $sql = "INSERT INTO login_attempt (username, user_id, ip_address, success)
                VALUES (?, ?, ?, ?)";
        return $this->execute($sql, [$username, $userId, $ip, $success ? 1 : 0]);
    }
}

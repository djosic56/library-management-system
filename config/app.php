<?php

/**
 * Application Configuration
 */

return [
    /**
     * Database Configuration
     */
    'database' => [
        'host' => 'localhost',
        'database' => 'jsistem_ap',
        'username' => 'jsistem_apuser',
        'password' => 'pAP3779',
        'charset' => 'utf8mb4',
    ],

    /**
     * Application Settings
     */
    'app' => [
        'name' => 'Library Management System',
        'items_per_page' => 20,
        'timezone' => 'Europe/Belgrade',
    ],

    /**
     * User Levels
     */
    'user_levels' => [
        'admin' => 1,
        'user' => 2,
    ],

    /**
     * Security Settings
     */
    'security' => [
        'max_login_attempts' => 5,
        'login_lockout_time' => 900, // 15 minutes in seconds
        'session_lifetime' => 1800,  // 30 minutes
        'csrf_token_name' => 'csrf_token',
    ],

    /**
     * Session Configuration
     */
    'session' => [
        'cookie_httponly' => true,
        'use_strict_mode' => true,
        'cookie_samesite' => 'Strict',
        'cookie_secure' => false, // Set to true when using HTTPS
    ],

    /**
     * Paths
     */
    'paths' => [
        'views' => __DIR__ . '/../templates',
        'logs' => __DIR__ . '/../logs',
    ],
];

<?php
/**
 * PDO connection factory. Prepared statements only; no SQL string building
 * with untrusted input.
 */

declare(strict_types=1);

if (!function_exists('gt_config')) {
    /**
     * Load configuration once per request.
     *
     * @return array<string,mixed>
     */
    function gt_config(): array
    {
        static $config = null;
        if ($config === null) {
            $config = require __DIR__ . '/config.php';
        }
        return $config;
    }
}

if (!function_exists('gt_db')) {
    function gt_db(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }

        $db = gt_config()['database'];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'],
            (int) $db['port'],
            $db['name'],
            $db['charset']
        );

        $pdo = new PDO($dsn, (string) $db['user'], (string) $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00', NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]);

        return $pdo;
    }
}

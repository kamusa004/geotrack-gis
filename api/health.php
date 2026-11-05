<?php

require_once dirname(__DIR__) . '/includes/api.php';

gt_require_method('GET');

$health = [
    'status' => 'ok',
    'app' => gt_config()['app']['name'],
    'version' => gt_config()['app']['version'],
    'php_version' => PHP_VERSION,
    'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
    'database' => ['connected' => false, 'schema_loaded' => false],
    'python' => ['available' => false],
    'checks' => [],
];

try {
    $pdo = gt_db();
    $health['database']['connected'] = true;
    $tables = $pdo->query("SHOW TABLES LIKE 'tracked_entities'")->fetchAll();
    $health['database']['schema_loaded'] = count($tables) > 0;
    if ($health['database']['schema_loaded']) {
        $health['database']['entities'] = (int) $pdo->query('SELECT COUNT(*) FROM tracked_entities')->fetchColumn();
        $health['database']['locations'] = (int) $pdo->query('SELECT COUNT(*) FROM location_history')->fetchColumn();
    }
} catch (Throwable $e) {
    $health['status'] = 'degraded';
    $health['database']['error'] = 'Database connection or schema check failed.';
    error_log('Health DB check: ' . $e->getMessage());
}

try {
    $result = gt_python_selftest();
    $health['python'] = [
        'available' => true,
        'self_test_passed' => ($result['ok'] ?? false) === true,
        'tests_passed' => (int) ($result['passed'] ?? 0),
        'tests_total' => (int) ($result['total'] ?? 0),
        'python_version' => $result['python_version'] ?? null,
        'geopy_available' => (bool) ($result['geopy_available'] ?? false),
    ];
    if (($result['ok'] ?? false) !== true) {
        $health['status'] = 'degraded';
    }
} catch (Throwable $e) {
    $health['status'] = 'degraded';
    $health['python'] = [
        'available' => false,
        'error' => 'Python analysis is not available.',
        'hint' => 'Install Python 3.8+ and set python.bin in config/config.local.php.',
    ];
    error_log('Health Python check: ' . $e->getMessage());
}

gt_api_success($health);

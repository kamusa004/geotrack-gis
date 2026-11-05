<?php
/**
 * GeoTrack GIS — application configuration.
 *
 * Do NOT put real production passwords in this file. Copy
 * config/config.local.example.php to config/config.local.php and override
 * values there. config.local.php is denied by Apache and ignored by Git.
 */

declare(strict_types=1);

if (!function_exists('geotrack_env')) {
    /**
     * Read an environment variable, with a default fallback.
     */
    function geotrack_env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        if ($value === false && isset($_SERVER[$key])) {
            $value = $_SERVER[$key];
        }
        if ($value === false || $value === '') {
            return $default;
        }
        return $value;
    }
}

$isWindows = DIRECTORY_SEPARATOR === '\\';

$config = [
    'app' => [
        'name' => 'GeoTrack GIS',
        'version' => '1.0.0',
        'timezone' => 'UTC', // Storage is UTC; the browser renders local time.
        'debug' => filter_var(geotrack_env('GEOTRACK_DEBUG', '0'), FILTER_VALIDATE_BOOLEAN),
        'show_demo_credentials' => filter_var(geotrack_env('GEOTRACK_SHOW_DEMO_HINT', '1'), FILTER_VALIDATE_BOOLEAN),
        'session_name' => 'geotrack_sid',
        'session_idle_timeout' => 7200, // 2 hours of inactivity
        'retention_days' => 90,
    ],

    'database' => [
        'host' => geotrack_env('GEOTRACK_DB_HOST', '127.0.0.1'),
        'port' => (int) geotrack_env('GEOTRACK_DB_PORT', 3306),
        'name' => geotrack_env('GEOTRACK_DB_NAME', 'geotrack_gis'),
        'user' => geotrack_env('GEOTRACK_DB_USER', 'root'),
        'password' => geotrack_env('GEOTRACK_DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
    ],

    'python' => [
        // Set GEOTRACK_PYTHON to an absolute path if "python" is not on PATH.
        'bin' => geotrack_env('GEOTRACK_PYTHON', $isWindows ? 'python' : 'python3'),
        'fallbacks' => $isWindows ? ['py', 'python3'] : ['python'],
        'script' => dirname(__DIR__) . '/python/gis_analysis.py',
        'timeout_seconds' => 12,
    ],

    'map' => [
        'center' => ['lat' => -0.61170, 'lng' => 30.65810], // MUST main campus, Mbarara
        'zoom' => 14,
        'tile_url' => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        'tile_attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors',
    ],

    'analysis' => [
        'max_points' => 5000,
        'max_network_points' => 12000,
        'default_radius_m' => 500,
        'max_radius_m' => 50000,
        'network_days' => 7,
    ],

    'simulation' => [
        'interval_ms' => 2500,
        'max_local_points' => 800,
    ],

    'ip_geolocation' => [
        // ipwho.is is a free, keyless HTTPS provider. Disable if you do not
        // want the server to make an external request, or set your own URL.
        'enabled' => filter_var(geotrack_env('GEOTRACK_IP_GEO_ENABLED', '1'), FILTER_VALIDATE_BOOLEAN),
        'provider' => 'ipwho.is',
        'endpoint' => geotrack_env('GEOTRACK_IP_GEO_ENDPOINT', 'https://ipwho.is/{ip}'),
        'timeout_seconds' => 6,
        'cache_seconds' => 600,
        'sample' => [
            'country' => 'Uganda',
            'region' => 'Western Region',
            'city' => 'Mbarara (sample)',
            'latitude' => -0.61170,
            'longitude' => 30.65810,
            'source' => 'Sample data bundled with GeoTrack GIS (not a live lookup)',
        ],
    ],

    'security' => [
        'trust_proxy' => filter_var(geotrack_env('GEOTRACK_TRUST_PROXY', '0'), FILTER_VALIDATE_BOOLEAN),
        'max_request_bytes' => 16384,
        'login_max_attempts' => 8,
        'login_window_seconds' => 900,
        'write_rate_per_minute' => 240,
        'entity_create_per_hour' => 30,
    ],

    /**
     * The account is created automatically on the first successful login when
     * the users table is empty. CHANGE THIS PASSWORD after installation.
     */
    'bootstrap_admin' => [
        'enabled' => filter_var(geotrack_env('GEOTRACK_BOOTSTRAP_ADMIN', '1'), FILTER_VALIDATE_BOOLEAN),
        'username' => geotrack_env('GEOTRACK_ADMIN_USER', 'admin'),
        'password' => geotrack_env('GEOTRACK_ADMIN_PASSWORD', 'GeoTrack@2026'),
        'display_name' => 'GIS Administrator',
        'role' => 'admin',
    ],
];

$localFile = __DIR__ . '/config.local.php';
if (is_file($localFile)) {
    $local = require $localFile;
    if (is_array($local)) {
        $config = array_replace_recursive($config, $local);
    }
}

return $config;

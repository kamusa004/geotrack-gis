<?php
/**
 * Copy this file to config/config.local.php and edit it.
 * config.local.php is ignored by Git and blocked by Apache (.htaccess).
 */

declare(strict_types=1);

return [
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'geotrack_gis',
        'user' => 'root',
        'password' => '', // Set the MySQL/XAMPP password here, never commit it.
    ],

    'python' => [
        // Windows example: 'C:\\Users\\student\\AppData\\Local\\Programs\\Python\\Python312\\python.exe'
        'bin' => 'python',
    ],

    'bootstrap_admin' => [
        // Used only when the users table is empty.
        'username' => 'admin',
        'password' => 'GeoTrack@2026',
    ],

    'ip_geolocation' => [
        'enabled' => true,
    ],
];

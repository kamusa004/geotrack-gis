<?php

require_once dirname(__DIR__) . '/includes/api.php';

gt_require_method('GET');
$user = gt_require_auth();

try {
    gt_enforce_rate_limit(
        'ip_lookup:' . (int) $user['id'],
        12,
        60,
        'Too many IP geolocation lookups. Try again in a minute.'
    );

    $config = gt_config()['ip_geolocation'];
    $ip = gt_client_ip();
    $masked = gt_masked_ip($ip);
    $provider = (string) $config['provider'];
    $sample = [
        'is_sample' => true,
        'country' => (string) ($config['sample']['country'] ?? 'Uganda'),
        'region' => (string) ($config['sample']['region'] ?? 'Western Region'),
        'city' => (string) ($config['sample']['city'] ?? 'Mbarara (sample)'),
        'latitude' => (float) ($config['sample']['latitude'] ?? -0.6117),
        'longitude' => (float) ($config['sample']['longitude'] ?? 30.6581),
        'source' => (string) ($config['sample']['source'] ?? 'Bundled sample data'),
        'label' => 'SAMPLE DATA — NOT A LIVE LOOKUP',
    ];

    $limitations = [
        'IP geolocation estimates the location of a network, not the physical device.',
        'Accuracy is typically city or region level and can be wrong by many kilometres.',
        'VPNs, proxies, corporate gateways, and mobile networks can make the result point elsewhere entirely.',
        'An IP address does not provide continuous movement tracking or a device GPS fix.',
        'Results should be treated as informational context only, never as proof of a person\'s location.',
    ];

    if ($config['enabled'] !== true) {
        gt_api_success([
            'status' => 'disabled',
            'enabled' => false,
            'is_sample' => true,
            'ip_masked' => $masked,
            'provider' => $provider,
            'source' => 'Sample data bundled with GeoTrack GIS',
            'looked_up_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'sample' => $sample,
            'limitations' => $limitations,
            'setup_hint' => 'IP geolocation is disabled. Set ip_geolocation.enabled to true in config/config.local.php to allow server-side lookups.',
        ]);
    }

    if (gt_is_private_or_reserved_ip($ip)) {
        gt_api_success([
            'status' => 'unavailable',
            'enabled' => true,
            'is_sample' => true,
            'ip_masked' => $masked,
            'provider' => $provider,
            'source' => 'No lookup performed',
            'looked_up_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'message' => 'The server sees a local or private network address (for example 127.0.0.1), so an IP geolocation provider cannot locate it. This is expected during localhost development.',
            'sample' => $sample,
            'limitations' => $limitations,
        ]);
    }

    $ipHash = hash('sha256', $ip);
    $cacheStmt = gt_db()->prepare(
        'SELECT * FROM ip_geolocation_cache WHERE ip_hash = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1'
    );
    $cacheStmt->execute([$ipHash]);
    $cached = $cacheStmt->fetch();

    if ($cached) {
        gt_api_success([
            'status' => 'ok',
            'enabled' => true,
            'is_sample' => false,
            'approximate' => true,
            'cache_hit' => true,
            'ip_masked' => (string) $cached['ip_masked'],
            'country' => $cached['country'],
            'region' => $cached['region'],
            'city' => $cached['city'],
            'latitude' => $cached['latitude'] !== null ? (float) $cached['latitude'] : null,
            'longitude' => $cached['longitude'] !== null ? (float) $cached['longitude'] : null,
            'timezone' => $cached['timezone'],
            'isp' => $cached['isp'],
            'org' => $cached['org'],
            'provider' => (string) $cached['provider'],
            'source' => 'IP geolocation provider: ' . $cached['provider'] . ' (cached result)',
            'looked_up_at' => gt_iso_datetime((string) $cached['fetched_at']),
            'limitations' => $limitations,
        ]);
    }

    $endpoint = str_replace('{ip}', rawurlencode($ip), (string) $config['endpoint']);
    $timeout = max(2, (int) $config['timeout_seconds']);
    $response = null;
    $httpCode = 0;
    $fetchError = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'GeoTrack-GIS/' . gt_config()['app']['version'] . ' (student GIS project)',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false) {
            $fetchError = curl_error($ch);
            $response = null;
        }
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: GeoTrack-GIS\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $response = @file_get_contents($endpoint, false, $context);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $httpCode = (int) $m[1];
        }
        if ($response === false) {
            $fetchError = 'The provider request failed.';
            $response = null;
        }
    }

    if ($response === null || $httpCode !== 200 || strlen($response) > 300000) {
        gt_api_success([
            'status' => 'error',
            'enabled' => true,
            'is_sample' => true,
            'ip_masked' => $masked,
            'provider' => $provider,
            'source' => 'IP geolocation provider unavailable',
            'looked_up_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'message' => 'The IP geolocation provider could not be reached (HTTP ' . $httpCode . '). No live result is shown.',
            'detail' => $fetchError,
            'sample' => $sample,
            'limitations' => $limitations,
        ]);
    }

    try {
        $data = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        $data = null;
    }
    if (!is_array($data) || (isset($data['success']) && $data['success'] === false)) {
        gt_api_success([
            'status' => 'error',
            'enabled' => true,
            'is_sample' => true,
            'ip_masked' => $masked,
            'provider' => $provider,
            'source' => 'IP geolocation provider returned an error',
            'looked_up_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'message' => 'The IP geolocation provider did not return a usable result. No live result is shown.',
            'sample' => $sample,
            'limitations' => $limitations,
        ]);
    }

    $lat = isset($data['latitude']) && is_numeric($data['latitude']) ? (float) $data['latitude'] : null;
    $lon = isset($data['longitude']) && is_numeric($data['longitude']) ? (float) $data['longitude'] : null;
    if ($lat !== null && ($lat < -90 || $lat > 90)) {
        $lat = null;
    }
    if ($lon !== null && ($lon < -180 || $lon > 180)) {
        $lon = null;
    }

    $country = isset($data['country']) ? substr((string) $data['country'], 0, 64) : null;
    $region = isset($data['region']) ? substr((string) $data['region'], 0, 64) : null;
    $city = isset($data['city']) ? substr((string) $data['city'], 0, 64) : null;
    $timezone = isset($data['timezone']['id']) ? substr((string) $data['timezone']['id'], 0, 64) : (isset($data['timezone']) && is_string($data['timezone']) ? substr($data['timezone'], 0, 64) : null);
    $isp = isset($data['connection']['isp']) ? substr((string) $data['connection']['isp'], 0, 128) : null;
    $org = isset($data['connection']['org']) ? substr((string) $data['connection']['org'], 0, 128) : null;
    $fetchedAt = gmdate('Y-m-d H:i:s');
    $expiresAt = gmdate('Y-m-d H:i:s', time() + max(60, (int) $config['cache_seconds']));

    try {
        $upsert = gt_db()->prepare(
            'INSERT INTO ip_geolocation_cache
                (ip_hash, ip_masked, provider, country, region, city, latitude, longitude, timezone, isp, org, raw_json, fetched_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                ip_masked = VALUES(ip_masked), provider = VALUES(provider), country = VALUES(country),
                region = VALUES(region), city = VALUES(city), latitude = VALUES(latitude), longitude = VALUES(longitude),
                timezone = VALUES(timezone), isp = VALUES(isp), org = VALUES(org), raw_json = VALUES(raw_json),
                fetched_at = VALUES(fetched_at), expires_at = VALUES(expires_at)'
        );
        $upsert->execute([
            $ipHash,
            $masked,
            $provider,
            $country,
            $region,
            $city,
            $lat,
            $lon,
            $timezone,
            $isp,
            $org,
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            $fetchedAt,
            $expiresAt,
        ]);
    } catch (Throwable $e) {
        error_log('IP geolocation cache write failed: ' . $e->getMessage());
    }

    gt_api_success([
        'status' => 'ok',
        'enabled' => true,
        'is_sample' => false,
        'approximate' => true,
        'cache_hit' => false,
        'ip_masked' => $masked,
        'country' => $country,
        'region' => $region,
        'city' => $city,
        'latitude' => $lat,
        'longitude' => $lon,
        'timezone' => $timezone,
        'isp' => $isp,
        'org' => $org,
        'provider' => $provider,
        'source' => 'IP geolocation provider: ' . $provider,
        'looked_up_at' => gt_iso_datetime($fetchedAt),
        'limitations' => $limitations,
    ]);
} catch (Throwable $e) {
    throw $e;
}

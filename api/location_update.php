<?php

require_once dirname(__DIR__) . '/includes/api.php';

gt_require_method('POST');
$user = gt_require_auth();
gt_csrf_check();
gt_enforce_rate_limit(
    'location_write:' . (int) $user['id'],
    (int) gt_config()['security']['write_rate_per_minute'],
    60,
    'Too many location updates. Slow down and try again shortly.'
);

try {
    $body = gt_read_json(['POST']);
    $entityId = (int) ($body['entity_id'] ?? 0);
    if ($entityId <= 0) {
        throw new ApiException(422, 'invalid_entity', 'A valid entity_id is required.');
    }

    $entity = gt_require_writable_entity($entityId);
    $isDemo = (int) ($entity['is_demo'] ?? 0) === 1;

    $latitudeRaw = $body['latitude'] ?? null;
    $longitudeRaw = $body['longitude'] ?? null;
    if (!is_numeric($latitudeRaw) || !is_numeric($longitudeRaw)) {
        throw new ApiException(422, 'invalid_coordinates', 'Latitude and longitude must be numeric.');
    }
    $latitude = (float) $latitudeRaw;
    $longitude = (float) $longitudeRaw;
    if (!is_finite($latitude) || $latitude < -90 || $latitude > 90) {
        throw new ApiException(422, 'invalid_latitude', 'Latitude must be between -90 and 90.');
    }
    if (!is_finite($longitude) || $longitude < -180 || $longitude > 180) {
        throw new ApiException(422, 'invalid_longitude', 'Longitude must be between -180 and 180.');
    }

    $accuracy = null;
    if (isset($body['accuracy']) && $body['accuracy'] !== '') {
        if (!is_numeric($body['accuracy'])) {
            throw new ApiException(422, 'invalid_accuracy', 'Accuracy must be a number in metres.');
        }
        $accuracy = (float) $body['accuracy'];
        if (!is_finite($accuracy) || $accuracy < 0 || $accuracy > 100000) {
            throw new ApiException(422, 'invalid_accuracy', 'Accuracy must be between 0 and 100,000 metres.');
        }
    }

    $speed = null;
    if (isset($body['speed']) && $body['speed'] !== '') {
        if (!is_numeric($body['speed'])) {
            throw new ApiException(422, 'invalid_speed', 'Speed must be a number in metres per second.');
        }
        $speed = (float) $body['speed'];
        if (!is_finite($speed) || $speed < 0 || $speed > 300) {
            throw new ApiException(422, 'invalid_speed', 'Speed must be between 0 and 300 m/s.');
        }
    }

    $heading = null;
    if (isset($body['heading']) && $body['heading'] !== '') {
        if (!is_numeric($body['heading'])) {
            throw new ApiException(422, 'invalid_heading', 'Heading must be numeric.');
        }
        $heading = (float) $body['heading'];
        if (!is_finite($heading) || $heading < 0 || $heading > 360) {
            throw new ApiException(422, 'invalid_heading', 'Heading must be between 0 and 360 degrees.');
        }
    }

    $recordedAt = gt_parse_client_datetime($body['recorded_at'] ?? null);

    $source = strtolower(trim((string) ($body['source_type'] ?? '')));
    if ($source === '') {
        $source = $isDemo ? 'simulated' : (string) $entity['tracking_method'];
    }
    if (!in_array($source, ['gps', 'simulated', 'ip', 'manual'], true)) {
        throw new ApiException(422, 'invalid_source', 'source_type must be gps, simulated, ip, or manual.');
    }
    if ($source === 'simulated' && ($user['role'] !== 'admin' || $isDemo !== true)) {
        throw new ApiException(403, 'forbidden', 'Simulated records may only be written by an administrator to a labelled demo entity.');
    }
    if ($source === 'ip' && $user['role'] !== 'admin') {
        throw new ApiException(403, 'forbidden', 'Only administrators can attach IP-derived coordinates.');
    }
    if ($isDemo && !in_array($source, ['simulated', 'ip'], true)) {
        throw new ApiException(422, 'invalid_source', 'Demo entities can only carry simulated or IP-derived records.');
    }
    if (!$isDemo && in_array($source, ['simulated', 'ip'], true)) {
        throw new ApiException(422, 'invalid_source', 'Simulated/IP records cannot be stored on a real tracking entity.');
    }
    if ($entity['tracking_method'] === 'gps' && !in_array($source, ['gps', 'manual'], true)) {
        throw new ApiException(422, 'source_mismatch', 'This entity is configured for GPS/manual records.');
    }
    if ($entity['tracking_method'] === 'manual' && !in_array($source, ['manual', 'gps'], true)) {
        throw new ApiException(422, 'source_mismatch', 'This entity is configured for manual/GPS records.');
    }

    // Idempotency: exact retries return the existing record instead of duplicating it.
    $duplicate = gt_db()->prepare(
        'SELECT * FROM location_history WHERE entity_id = ? AND recorded_at = ? ORDER BY id DESC LIMIT 1'
    );
    $duplicate->execute([$entityId, $recordedAt]);
    $existing = $duplicate->fetch();
    if ($existing && abs((float) $existing['latitude'] - $latitude) < 0.0000001 && abs((float) $existing['longitude'] - $longitude) < 0.0000001) {
        gt_api_success([
            'record' => gt_location_payload($existing),
            'duplicate' => true,
            'warnings' => [],
        ]);
    }

    // Flag — never silently reject — implausible jumps. Python repeats this
    // analysis with fuller context in api/analysis.php.
    $qualityFlag = 'ok';
    $qualityNotes = [];
    if ($accuracy !== null && $accuracy > 500) {
        $qualityFlag = 'suspect';
        $qualityNotes[] = 'accuracy worse than 500 m';
    } elseif ($accuracy !== null && $accuracy > 100) {
        $qualityFlag = 'coarse';
        $qualityNotes[] = 'accuracy worse than 100 m';
    }

    $previousStmt = gt_db()->prepare(
        'SELECT latitude, longitude, recorded_at FROM location_history
         WHERE entity_id = ? AND recorded_at < ? ORDER BY recorded_at DESC, id DESC LIMIT 1'
    );
    $previousStmt->execute([$entityId, $recordedAt]);
    $previous = $previousStmt->fetch();
    if ($previous) {
        try {
            $previousAt = new DateTimeImmutable((string) $previous['recorded_at'], new DateTimeZone('UTC'));
            $currentAt = new DateTimeImmutable($recordedAt, new DateTimeZone('UTC'));
            $dt = (float) $currentAt->format('U.u') - (float) $previousAt->format('U.u');
        } catch (Throwable $e) {
            $dt = 0.0;
        }
        if ($dt > 0) {
            $metres = gt_haversine_m(
                (float) $previous['latitude'],
                (float) $previous['longitude'],
                $latitude,
                $longitude
            );
            $impliedKmh = ($metres / $dt) * 3.6;
            $thresholds = ['person' => 60, 'laptop' => 180, 'vehicle' => 320, 'asset' => 250, 'other' => 300];
            $threshold = $thresholds[(string) $entity['entity_type']] ?? 300;
            if ($impliedKmh > $threshold) {
                $qualityFlag = 'suspect';
                $qualityNotes[] = sprintf('implied speed %.0f km/h exceeds review threshold', $impliedKmh);
            }
        }
        if ($dt <= 0) {
            $qualityNotes[] = 'non-increasing timestamp';
        }
    }

    $qualityNote = $qualityNotes ? implode('; ', array_slice($qualityNotes, 0, 3)) : null;

    $stmt = gt_db()->prepare(
        'INSERT INTO location_history
            (entity_id, latitude, longitude, accuracy, speed, heading, recorded_at, source_type, quality_flag, quality_note, client_ip_masked)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $entityId,
        round($latitude, 7),
        round($longitude, 7),
        $accuracy !== null ? round($accuracy, 2) : null,
        $speed !== null ? round($speed, 2) : null,
        $heading !== null ? round($heading, 2) : null,
        $recordedAt,
        $source,
        $qualityFlag,
        $qualityNote,
        gt_masked_ip(gt_client_ip()),
    ]);
    $recordId = (int) gt_db()->lastInsertId();

    $touch = gt_db()->prepare(
        'UPDATE tracked_entities
         SET last_seen_at = GREATEST(COALESCE(last_seen_at, ?), ?)
         WHERE id = ?'
    );
    $touch->execute([$recordedAt, $recordedAt, $entityId]);

    $heartbeat = gt_db()->prepare(
        'UPDATE tracking_sessions SET last_heartbeat_at = UTC_TIMESTAMP()
         WHERE entity_id = ? AND status = "active"'
    );
    $heartbeat->execute([$entityId]);

    $recordStmt = gt_db()->prepare('SELECT * FROM location_history WHERE id = ? LIMIT 1');
    $recordStmt->execute([$recordId]);
    $record = $recordStmt->fetch();

    gt_api_success([
        'record' => $record ? gt_location_payload($record) : null,
        'duplicate' => false,
        'warnings' => $qualityNotes,
    ], 201);
} catch (Throwable $e) {
    throw $e;
}

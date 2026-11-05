<?php

require_once dirname(__DIR__) . '/includes/api.php';

gt_require_method('POST');
$user = gt_require_auth();

try {
    $body = gt_read_json(['POST']);
    $action = strtolower(trim((string) ($body['action'] ?? '')));
    $validTypes = ['person', 'laptop', 'vehicle', 'asset', 'other'];

    if ($action === 'distance') {
        foreach (['lat1', 'lon1', 'lat2', 'lon2'] as $field) {
            if (!isset($body[$field]) || !is_numeric($body[$field])) {
                throw new ApiException(422, 'invalid_coordinate', 'Provide numeric lat1, lon1, lat2, and lon2 values.');
            }
        }
        $result = gt_analyse([
            'action' => 'distance',
            'lat1' => (float) $body['lat1'],
            'lon1' => (float) $body['lon1'],
            'lat2' => (float) $body['lat2'],
            'lon2' => (float) $body['lon2'],
        ]);
        gt_api_success(['analysis' => $result, 'engine' => 'python']);
    }

    if ($action === 'movement') {
        $entityId = (int) ($body['entity_id'] ?? 0);
        if ($entityId <= 0) {
            throw new ApiException(422, 'invalid_entity', 'A valid entity_id is required.');
        }
        $entity = gt_require_readable_entity($entityId);

        $maxPoints = (int) gt_config()['analysis']['max_points'];
        $where = ['entity_id = ?'];
        $params = [$entityId];
        $from = gt_parse_range_datetime($body['from'] ?? null);
        $to = gt_parse_range_datetime($body['to'] ?? null);
        if ($from !== null) {
            $where[] = 'recorded_at >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $where[] = 'recorded_at <= ?';
            $params[] = $to;
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = gt_db()->prepare('SELECT COUNT(*) FROM location_history WHERE ' . $whereSql);
        $countStmt->execute($params);
        $totalAvailable = (int) $countStmt->fetchColumn();

        // Analyse the most recent N points, then hand them to Python in
        // chronological order.
        $stmt = gt_db()->prepare(
            'SELECT * FROM location_history WHERE ' . $whereSql
            . ' ORDER BY recorded_at DESC, id DESC LIMIT ' . $maxPoints
        );
        $stmt->execute($params);
        $rows = array_reverse($stmt->fetchAll());

        $points = array_map(static function (array $row): array {
            return [
                'latitude' => (float) $row['latitude'],
                'longitude' => (float) $row['longitude'],
                'accuracy' => $row['accuracy'] !== null ? (float) $row['accuracy'] : null,
                'speed' => $row['speed'] !== null ? (float) $row['speed'] : null,
                'recorded_at' => gt_iso_datetime((string) $row['recorded_at']),
                'source_type' => (string) $row['source_type'],
            ];
        }, $rows);

        $result = gt_analyse([
            'action' => 'movement',
            'entity_type' => (string) $entity['entity_type'],
            'points' => $points,
        ]);

        gt_api_success([
            'engine' => 'python',
            'entity' => gt_entity_payload($entity, $user),
            'analysis' => $result,
            'range' => ['from' => gt_iso_datetime($from), 'to' => gt_iso_datetime($to)],
            'total_available' => $totalAvailable,
            'analysed_points' => count($points),
            'truncated' => $totalAvailable > $maxPoints,
        ]);
    }

    if ($action === 'proximity') {
        if (!isset($body['latitude'], $body['longitude']) || !is_numeric($body['latitude']) || !is_numeric($body['longitude'])) {
            throw new ApiException(422, 'invalid_coordinate', 'Provide a numeric origin latitude and longitude.');
        }
        $latitude = (float) $body['latitude'];
        $longitude = (float) $body['longitude'];
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new ApiException(422, 'invalid_coordinate', 'Origin coordinates are outside valid ranges.');
        }
        $radius = isset($body['radius_m']) && is_numeric($body['radius_m'])
            ? (float) $body['radius_m']
            : (float) gt_config()['analysis']['default_radius_m'];
        $maxRadius = (float) gt_config()['analysis']['max_radius_m'];
        if ($radius <= 0 || $radius > $maxRadius) {
            throw new ApiException(422, 'invalid_radius', 'Radius must be greater than 0 and at most ' . $maxRadius . ' metres.');
        }

        $typeFilter = [];
        if (isset($body['types']) && is_array($body['types'])) {
            foreach ($body['types'] as $type) {
                if (is_string($type) && in_array($type, $validTypes, true)) {
                    $typeFilter[] = $type;
                }
            }
        }
        $typeSql = '';
        $params = [];
        if ($typeFilter) {
            $placeholders = implode(',', array_fill(0, count($typeFilter), '?'));
            $typeSql = ' AND e.entity_type IN (' . $placeholders . ')';
            $params = $typeFilter;
        }

        $sql = 'SELECT e.id AS entity_id, e.entity_code, e.entity_name, e.entity_type, e.tracking_status, e.is_demo,
                       latest.latitude, latest.longitude, latest.recorded_at, latest.source_type, latest.accuracy
                FROM tracked_entities e
                INNER JOIN location_history latest ON latest.id = (
                    SELECT lh.id FROM location_history lh
                    WHERE lh.entity_id = e.id
                    ORDER BY lh.recorded_at DESC, lh.id DESC
                    LIMIT 1
                )
                WHERE (e.is_demo = 1 OR e.owner_user_id = ? OR ? = 1)' . $typeSql . '
                ORDER BY e.entity_name ASC
                LIMIT 500';
        $params = array_merge([
            (int) $user['id'],
            $user['role'] === 'admin' ? 1 : 0,
        ], $params);
        $stmt = gt_db()->prepare($sql);
        $stmt->execute($params);
        $candidates = $stmt->fetchAll();

        $pythonEntities = array_map(static function (array $row): array {
            return [
                'entity_id' => (int) $row['entity_id'],
                'entity_code' => (string) $row['entity_code'],
                'entity_name' => (string) $row['entity_name'],
                'entity_type' => (string) $row['entity_type'],
                'latitude' => (float) $row['latitude'],
                'longitude' => (float) $row['longitude'],
                'recorded_at' => gt_iso_datetime((string) $row['recorded_at']),
                'source_type' => (string) $row['source_type'],
            ];
        }, $candidates);

        $result = gt_analyse([
            'action' => 'proximity',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'radius_m' => $radius,
            'entities' => $pythonEntities,
            'limit' => 100,
        ]);

        $detailById = [];
        foreach ($candidates as $row) {
            $detailById[(int) $row['entity_id']] = [
                'tracking_status' => (string) $row['tracking_status'],
                'is_demo' => (int) $row['is_demo'] === 1,
                'accuracy' => $row['accuracy'] !== null ? (float) $row['accuracy'] : null,
            ];
        }
        foreach ($result['results'] as &$match) {
            $id = (int) ($match['entity_id'] ?? 0);
            $match['tracking_status'] = $detailById[$id]['tracking_status'] ?? null;
            $match['is_demo'] = $detailById[$id]['is_demo'] ?? null;
            $match['accuracy'] = $detailById[$id]['accuracy'] ?? null;
        }
        unset($match);

        gt_api_success([
            'engine' => 'python',
            'analysis' => $result,
            'candidates' => count($candidates),
        ]);
    }

    if ($action === 'network') {
        $days = isset($body['days']) ? (int) $body['days'] : (int) gt_config()['analysis']['network_days'];
        $days = max(1, min(30, $days));
        $since = gmdate('Y-m-d H:i:s', time() - ($days * 86400));
        $maxPoints = (int) gt_config()['analysis']['max_network_points'];

        $entitySql = 'SELECT e.id, e.entity_code, e.entity_name, e.entity_type, e.tracking_status, e.is_demo
                      FROM tracked_entities e
                      WHERE (e.is_demo = 1 OR e.owner_user_id = ? OR ? = 1)
                      ORDER BY e.entity_name ASC';
        $entityStmt = gt_db()->prepare($entitySql);
        $entityStmt->execute([(int) $user['id'], $user['role'] === 'admin' ? 1 : 0]);
        $entityRows = $entityStmt->fetchAll();

        $pointSql = 'SELECT lh.entity_id, lh.latitude, lh.longitude, lh.accuracy, lh.speed, lh.recorded_at, lh.source_type
                     FROM location_history lh
                     INNER JOIN tracked_entities e ON e.id = lh.entity_id
                     WHERE (e.is_demo = 1 OR e.owner_user_id = ? OR ? = 1)
                       AND lh.recorded_at >= ?
                     ORDER BY lh.entity_id ASC, lh.recorded_at ASC, lh.id ASC
                     LIMIT ' . ($maxPoints + 1);
        $pointStmt = gt_db()->prepare($pointSql);
        $pointStmt->execute([(int) $user['id'], $user['role'] === 'admin' ? 1 : 0, $since]);
        $pointRows = $pointStmt->fetchAll();
        $truncated = count($pointRows) > $maxPoints;
        if ($truncated) {
            array_pop($pointRows);
        }

        $grouped = [];
        foreach ($entityRows as $row) {
            $grouped[(int) $row['id']] = [
                'entity_id' => (int) $row['id'],
                'entity_code' => (string) $row['entity_code'],
                'entity_name' => (string) $row['entity_name'],
                'entity_type' => (string) $row['entity_type'],
                'tracking_status' => (string) $row['tracking_status'],
                'is_demo' => (int) $row['is_demo'] === 1,
                'points' => [],
            ];
        }
        foreach ($pointRows as $row) {
            $id = (int) $row['entity_id'];
            if (!isset($grouped[$id])) {
                continue;
            }
            $grouped[$id]['points'][] = [
                'latitude' => (float) $row['latitude'],
                'longitude' => (float) $row['longitude'],
                'accuracy' => $row['accuracy'] !== null ? (float) $row['accuracy'] : null,
                'speed' => $row['speed'] !== null ? (float) $row['speed'] : null,
                'recorded_at' => gt_iso_datetime((string) $row['recorded_at']),
                'source_type' => (string) $row['source_type'],
            ];
        }

        $result = gt_analyse([
            'action' => 'network',
            'entities' => array_values($grouped),
        ]);

        gt_api_success([
            'engine' => 'python',
            'analysis' => $result,
            'days' => $days,
            'since' => gt_iso_datetime($since),
            'truncated' => $truncated,
            'analysed_points' => count($pointRows),
        ]);
    }

    if ($action === 'validate') {
        if (!isset($body['record']) || !is_array($body['record'])) {
            throw new ApiException(422, 'invalid_record', 'Provide a record object to validate.');
        }
        $result = gt_analyse([
            'action' => 'validate',
            'record' => $body['record'],
        ]);
        gt_api_success(['engine' => 'python', 'validation' => $result]);
    }

    if ($action === 'selftest') {
        if ($user['role'] !== 'admin') {
            throw new ApiException(403, 'forbidden', 'Only administrators can run the Python self-test.');
        }
        $result = gt_python_call(['action' => 'selftest']);
        gt_api_success(['engine' => 'python', 'selftest' => $result]);
    }

    throw new ApiException(400, 'unknown_action', 'Supported actions: distance, movement, proximity, network, validate, selftest.');
} catch (Throwable $e) {
    throw $e;
}

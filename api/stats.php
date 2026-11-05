<?php

require_once dirname(__DIR__) . '/includes/api.php';

gt_require_method('GET');
$user = gt_require_auth();

try {
    $ownerId = (int) $user['id'];
    $isAdmin = $user['role'] === 'admin' ? 1 : 0;
    $readable = '(e.is_demo = 1 OR e.owner_user_id = ? OR ? = 1)';
    $params = [$ownerId, $isAdmin];

    $entityStmt = gt_db()->prepare(
        'SELECT e.id, e.entity_code, e.entity_name, e.entity_type, e.tracking_status, e.is_demo,
                e.last_seen_at, e.created_at,
                (SELECT COUNT(*) FROM location_history lh WHERE lh.entity_id = e.id) AS point_count
         FROM tracked_entities e
         WHERE ' . $readable . '
         ORDER BY e.entity_name ASC'
    );
    $entityStmt->execute($params);
    $entities = $entityStmt->fetchAll();

    $totalEntities = count($entities);
    $activeEntities = 0;
    $demoEntities = 0;
    $totalPoints = 0;
    $typeCounts = ['person' => 0, 'laptop' => 0, 'vehicle' => 0, 'asset' => 0, 'other' => 0];
    $statusCounts = ['active' => 0, 'paused' => 0, 'stopped' => 0];
    foreach ($entities as $entity) {
        $totalPoints += (int) $entity['point_count'];
        if ($entity['tracking_status'] === 'active') {
            $activeEntities++;
        }
        $statusCounts[$entity['tracking_status']] = ($statusCounts[$entity['tracking_status']] ?? 0) + 1;
        $typeCounts[$entity['entity_type']] = ($typeCounts[$entity['entity_type']] ?? 0) + 1;
        if ((int) $entity['is_demo'] === 1) {
            $demoEntities++;
        }
    }

    $sessionStmt = gt_db()->prepare(
        'SELECT COUNT(*) FROM tracking_sessions s
         INNER JOIN tracked_entities e ON e.id = s.entity_id
         WHERE ' . $readable . '
           AND s.status = "active"
           AND COALESCE(s.last_heartbeat_at, s.started_at) >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE'
    );
    $sessionStmt->execute($params);
    $activeSessions = (int) $sessionStmt->fetchColumn();

    $updateStmt = gt_db()->prepare(
        'SELECT
            COUNT(*) AS total_points,
            SUM(lh.recorded_at >= UTC_DATE()) AS updates_today,
            SUM(lh.recorded_at >= UTC_TIMESTAMP() - INTERVAL 1 HOUR) AS updates_last_hour,
            SUM(lh.recorded_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR) AS updates_last_24h,
            MAX(lh.recorded_at) AS last_update
         FROM location_history lh
         INNER JOIN tracked_entities e ON e.id = lh.entity_id
         WHERE ' . $readable
    );
    $updateStmt->execute($params);
    $updateCounts = $updateStmt->fetch() ?: [];

    $dailyStmt = gt_db()->prepare(
        'SELECT DATE(lh.recorded_at) AS day, COUNT(*) AS count
         FROM location_history lh
         INNER JOIN tracked_entities e ON e.id = lh.entity_id
         WHERE ' . $readable . '
           AND lh.recorded_at >= UTC_DATE() - INTERVAL 6 DAY
         GROUP BY DATE(lh.recorded_at)
         ORDER BY day ASC'
    );
    $dailyStmt->execute($params);
    $dailyRows = $dailyStmt->fetchAll();
    $dailyMap = [];
    foreach ($dailyRows as $row) {
        $dailyMap[(string) $row['day']] = (int) $row['count'];
    }
    $daily = [];
    for ($i = 6; $i >= 0; $i--) {
        $day = gmdate('Y-m-d', time() - ($i * 86400));
        $daily[] = ['date' => $day, 'count' => $dailyMap[$day] ?? 0];
    }

    $latestStmt = gt_db()->prepare(
        'SELECT lh.id, lh.entity_id, lh.latitude, lh.longitude, lh.accuracy, lh.speed,
                lh.recorded_at, lh.source_type, lh.quality_flag,
                e.entity_code, e.entity_name, e.entity_type, e.is_demo
         FROM location_history lh
         INNER JOIN tracked_entities e ON e.id = lh.entity_id
         WHERE ' . $readable . '
         ORDER BY lh.recorded_at DESC, lh.id DESC
         LIMIT 10'
    );
    $latestStmt->execute($params);
    $latestRows = $latestStmt->fetchAll();
    $latest = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'entity_id' => (int) $row['entity_id'],
            'entity_code' => (string) $row['entity_code'],
            'entity_name' => (string) $row['entity_name'],
            'entity_type' => (string) $row['entity_type'],
            'is_demo' => (int) $row['is_demo'] === 1,
            'latitude' => (float) $row['latitude'],
            'longitude' => (float) $row['longitude'],
            'accuracy' => $row['accuracy'] !== null ? (float) $row['accuracy'] : null,
            'speed' => $row['speed'] !== null ? (float) $row['speed'] : null,
            'recorded_at' => gt_iso_datetime((string) $row['recorded_at']),
            'source_type' => (string) $row['source_type'],
            'quality_flag' => (string) $row['quality_flag'],
        ];
    }, $latestRows);

    $entitySummary = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'entity_code' => (string) $row['entity_code'],
            'entity_name' => (string) $row['entity_name'],
            'entity_type' => (string) $row['entity_type'],
            'tracking_status' => (string) $row['tracking_status'],
            'is_demo' => (int) $row['is_demo'] === 1,
            'point_count' => (int) $row['point_count'],
            'last_seen_at' => gt_iso_datetime(isset($row['last_seen_at']) ? (string) $row['last_seen_at'] : null),
        ];
    }, $entities);

    gt_api_success([
        'summary' => [
            'total_entities' => $totalEntities,
            'active_entities' => $activeEntities,
            'active_sessions' => $activeSessions,
            'demo_entities' => $demoEntities,
            'real_entities' => max(0, $totalEntities - $demoEntities),
            'total_points' => $totalPoints,
            'updates_today' => (int) ($updateCounts['updates_today'] ?? 0),
            'updates_last_hour' => (int) ($updateCounts['updates_last_hour'] ?? 0),
            'updates_last_24h' => (int) ($updateCounts['updates_last_24h'] ?? 0),
            'last_update' => gt_iso_datetime(isset($updateCounts['last_update']) ? (string) $updateCounts['last_update'] : null),
        ],
        'type_counts' => $typeCounts,
        'status_counts' => $statusCounts,
        'daily_updates' => $daily,
        'latest_updates' => $latest,
        'entities' => $entitySummary,
        'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
        'retention_days' => (int) gt_config()['app']['retention_days'],
    ]);
} catch (Throwable $e) {
    throw $e;
}

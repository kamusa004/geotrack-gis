<?php

require_once dirname(__DIR__) . '/includes/api.php';

$user = gt_require_auth();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($method === 'GET') {
        $entityId = (int) ($_GET['entity_id'] ?? 0);
        if ($entityId <= 0) {
            throw new ApiException(422, 'invalid_entity', 'A valid entity_id is required.');
        }
        $entity = gt_require_readable_entity($entityId);

        $limit = (int) ($_GET['limit'] ?? 100);
        $limit = max(1, min(500, $limit));
        $order = strtolower(trim((string) ($_GET['order'] ?? 'desc')));
        $order = $order === 'asc' ? 'ASC' : 'DESC';

        $where = ['entity_id = ?'];
        $params = [$entityId];

        $beforeId = (int) ($_GET['before_id'] ?? 0);
        if ($beforeId > 0) {
            $where[] = 'id < ?';
            $params[] = $beforeId;
        }
        $from = gt_parse_range_datetime($_GET['from'] ?? null);
        $to = gt_parse_range_datetime($_GET['to'] ?? null);
        if ($from !== null) {
            $where[] = 'recorded_at >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $where[] = 'recorded_at <= ?';
            $params[] = $to;
        }

        $sql = 'SELECT * FROM location_history WHERE ' . implode(' AND ', $where)
            . ' ORDER BY recorded_at ' . $order . ', id ' . $order . ' LIMIT ' . ($limit + 1);
        $stmt = gt_db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $records = array_map('gt_location_payload', $rows);
        $oldestId = null;
        $newestId = null;
        if ($records) {
            $oldestId = min(array_column($records, 'id'));
            $newestId = max(array_column($records, 'id'));
        }

        gt_api_success([
            'entity' => gt_entity_payload($entity, $user),
            'records' => $records,
            'count' => count($records),
            'has_more' => $hasMore,
            'oldest_id' => $oldestId,
            'newest_id' => $newestId,
            'order' => strtolower($order),
            'retention_days' => (int) gt_config()['app']['retention_days'],
        ]);
    }

    if ($method === 'DELETE') {
        gt_csrf_check();
        $body = gt_read_json(['DELETE']);
        $scope = strtolower(trim((string) ($body['scope'] ?? 'entity')));

        if ($scope === 'demo') {
            if ($user['role'] !== 'admin') {
                throw new ApiException(403, 'forbidden', 'Only administrators can clear demonstration history.');
            }
            $stmt = gt_db()->prepare(
                'DELETE lh FROM location_history lh
                 INNER JOIN tracked_entities e ON e.id = lh.entity_id
                 WHERE e.is_demo = 1'
            );
            $stmt->execute();
            $deleted = $stmt->rowCount();
            gt_audit('demo_history_cleared', null, 'Deleted ' . $deleted . ' demo record(s).');
            gt_api_success(['deleted_count' => $deleted, 'scope' => 'demo']);
        }

        $entityId = (int) ($body['entity_id'] ?? 0);
        if ($entityId <= 0) {
            throw new ApiException(422, 'invalid_entity', 'A valid entity_id is required.');
        }
        $entity = gt_find_entity($entityId);
        if ($entity === null) {
            throw new ApiException(404, 'entity_not_found', 'Tracked entity not found.');
        }
        if (!gt_can_manage_entity($entity, $user)) {
            throw new ApiException(403, 'forbidden', 'You cannot clear this entity\'s history.');
        }

        $stmt = gt_db()->prepare('DELETE FROM location_history WHERE entity_id = ?');
        $stmt->execute([$entityId]);
        $deleted = $stmt->rowCount();
        $update = gt_db()->prepare('UPDATE tracked_entities SET last_seen_at = NULL WHERE id = ?');
        $update->execute([$entityId]);
        gt_audit('history_cleared', $entityId, 'Deleted ' . $deleted . ' record(s).');
        gt_api_success(['deleted_count' => $deleted, 'scope' => 'entity', 'entity_id' => $entityId]);
    }

    throw new ApiException(405, 'method_not_allowed', 'Allowed methods: GET, DELETE.');
} catch (Throwable $e) {
    throw $e;
}

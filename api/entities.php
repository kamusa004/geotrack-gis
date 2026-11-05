<?php

require_once dirname(__DIR__) . '/includes/api.php';

$user = gt_require_auth();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$types = ['person', 'laptop', 'vehicle', 'asset', 'other'];
$methods = ['gps', 'simulated', 'ip', 'manual'];

try {
    if ($method === 'GET') {
        $params = [];
        $where = ['(e.is_demo = 1 OR e.owner_user_id = :owner_id OR :is_admin = 1)'];
        $params[':owner_id'] = (int) $user['id'];
        $params[':is_admin'] = $user['role'] === 'admin' ? 1 : 0;

        $type = strtolower(trim((string) ($_GET['type'] ?? '')));
        if ($type !== '') {
            if (!in_array($type, $types, true)) {
                throw new ApiException(422, 'invalid_type', 'Unknown entity type filter.');
            }
            $where[] = 'e.entity_type = :type';
            $params[':type'] = $type;
        }

        $sql = 'SELECT e.*,
                    (SELECT COUNT(*) FROM location_history lh WHERE lh.entity_id = e.id) AS point_count,
                    latest.latitude AS last_lat,
                    latest.longitude AS last_lng,
                    latest.accuracy AS last_accuracy,
                    latest.speed AS last_speed,
                    latest.recorded_at AS last_recorded_at,
                    latest.source_type AS last_source
                FROM tracked_entities e
                LEFT JOIN location_history latest ON latest.id = (
                    SELECT lh2.id FROM location_history lh2
                    WHERE lh2.entity_id = e.id
                    ORDER BY lh2.recorded_at DESC, lh2.id DESC
                    LIMIT 1
                )
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY (e.tracking_status = "active") DESC, e.is_demo DESC, e.entity_name ASC
                LIMIT 200';
        $stmt = gt_db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $entities = array_map(static fn(array $row): array => gt_entity_payload($row, $user), $rows);
        gt_api_success([
            'entities' => $entities,
            'count' => count($entities),
            'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    if ($method === 'POST') {
        gt_csrf_check();
        gt_enforce_rate_limit(
            'entity_create:' . (int) $user['id'],
            (int) gt_config()['security']['entity_create_per_hour'],
            3600,
            'You have created too many entities in the last hour.'
        );
        $body = gt_read_json(['POST']);

        $name = trim((string) ($body['entity_name'] ?? ''));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        if ($nameLength < 2 || $nameLength > 80) {
            throw new ApiException(422, 'invalid_name', 'Entity name must be between 2 and 80 characters.');
        }
        if (function_exists('mb_check_encoding') && !mb_check_encoding($name, 'UTF-8')) {
            throw new ApiException(422, 'invalid_name', 'Entity name must be valid UTF-8.');
        }

        $entityType = strtolower(trim((string) ($body['entity_type'] ?? 'other')));
        if (!in_array($entityType, $types, true)) {
            throw new ApiException(422, 'invalid_type', 'Entity type must be person, laptop, vehicle, asset, or other.');
        }

        $trackingMethod = strtolower(trim((string) ($body['tracking_method'] ?? 'manual')));
        if (!in_array($trackingMethod, $methods, true)) {
            throw new ApiException(422, 'invalid_method', 'Tracking method must be gps, simulated, ip, or manual.');
        }

        $isDemo = !empty($body['is_demo']) ? 1 : 0;
        if ($isDemo === 1 && $user['role'] !== 'admin') {
            throw new ApiException(403, 'forbidden', 'Only administrators can create clearly labelled demonstration entities.');
        }
        if ($trackingMethod === 'simulated' && $isDemo !== 1) {
            throw new ApiException(422, 'invalid_method', 'Simulated tracking must be labelled as demo data.');
        }
        if ($trackingMethod === 'ip' && $isDemo !== 1) {
            throw new ApiException(422, 'invalid_method', 'IP geolocation entities must be labelled as demo/informational.');
        }
        if ($trackingMethod === 'gps' && $isDemo === 1) {
            throw new ApiException(422, 'invalid_method', 'Demo entities cannot claim to be live GPS devices.');
        }
        if ($trackingMethod === 'simulated' && $isDemo !== 1) {
            throw new ApiException(422, 'invalid_method', 'Simulation must be visibly marked as demo data.');
        }

        $prefix = ['person' => 'P', 'laptop' => 'L', 'vehicle' => 'V', 'asset' => 'A', 'other' => 'X'][$entityType];
        $requestedCode = strtoupper(trim((string) ($body['entity_code'] ?? '')));
        if ($requestedCode !== '') {
            if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{2,31}$/', $requestedCode)) {
                throw new ApiException(422, 'invalid_code', 'Entity code must be 3-32 characters: A-Z, 0-9, underscore or hyphen.');
            }
            $entityCode = $requestedCode;
        } else {
            $entityCode = '';
            for ($attempt = 0; $attempt < 6; $attempt++) {
                $candidate = $prefix . '-' . strtoupper(bin2hex(random_bytes(3)));
                $check = gt_db()->prepare('SELECT COUNT(*) FROM tracked_entities WHERE entity_code = ?');
                $check->execute([$candidate]);
                if ((int) $check->fetchColumn() === 0) {
                    $entityCode = $candidate;
                    break;
                }
            }
            if ($entityCode === '') {
                throw new ApiException(500, 'code_generation_failed', 'Could not allocate a unique entity code. Try again.');
            }
        }

        $notes = trim((string) ($body['notes'] ?? ''));
        if (strlen($notes) > 255) {
            throw new ApiException(422, 'invalid_notes', 'Notes must be 255 characters or fewer.');
        }

        $accessToken = null;
        $tokenHash = null;
        $ownerId = $isDemo === 1 ? null : (int) $user['id'];
        if ($isDemo !== 1) {
            $accessToken = bin2hex(random_bytes(24));
            $tokenHash = hash('sha256', $accessToken);
        }

        $stmt = gt_db()->prepare(
            'INSERT INTO tracked_entities
                (entity_code, entity_name, entity_type, tracking_method, tracking_status, owner_user_id, is_demo, access_token_hash, notes)
             VALUES (?, ?, ?, ?, "stopped", ?, ?, ?, ?)'
        );
        $stmt->execute([
            $entityCode,
            $name,
            $entityType,
            $trackingMethod,
            $ownerId,
            $isDemo,
            $tokenHash,
            $notes !== '' ? $notes : null,
        ]);
        $entityId = (int) gt_db()->lastInsertId();
        $entity = gt_find_entity($entityId);
        if ($entity === null) {
            throw new ApiException(500, 'create_failed', 'The entity was created but could not be loaded.');
        }
        gt_audit('entity_created', $entityId, $entityCode . ' / ' . $trackingMethod . ($isDemo ? ' (demo)' : ''));
        $payload = gt_entity_payload(array_merge($entity, ['point_count' => 0]), $user);
        $response = [
            'entity' => $payload,
            'access_token' => $accessToken,
            'token_notice' => $accessToken !== null ? 'Store this token securely; it is shown only once and authorises location updates for this entity.' : null,
        ];
        gt_api_success($response, 201);
    }

    if ($method === 'DELETE') {
        gt_csrf_check();
        $body = gt_read_json(['DELETE']);
        $entityId = (int) ($body['entity_id'] ?? 0);
        $entity = gt_find_entity($entityId);
        if ($entity === null) {
            throw new ApiException(404, 'entity_not_found', 'Tracked entity not found.');
        }
        if (!gt_can_manage_entity($entity, $user)) {
            throw new ApiException(403, 'forbidden', 'You cannot delete this entity.');
        }
        $stmt = gt_db()->prepare('DELETE FROM tracked_entities WHERE id = ?');
        $stmt->execute([$entityId]);
        gt_audit('entity_deleted', $entityId, (string) $entity['entity_code']);
        gt_api_success(['deleted' => true, 'entity_id' => $entityId]);
    }

    throw new ApiException(405, 'method_not_allowed', 'Allowed methods: GET, POST, DELETE.');
} catch (Throwable $e) {
    throw $e;
}

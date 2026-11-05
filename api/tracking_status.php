<?php

require_once dirname(__DIR__) . '/includes/api.php';

gt_require_method('POST');
$user = gt_require_auth();
gt_csrf_check();

try {
    $body = gt_read_json(['POST']);
    $entityId = (int) ($body['entity_id'] ?? 0);
    $status = strtolower(trim((string) ($body['status'] ?? '')));
    if (!in_array($status, ['active', 'paused', 'stopped'], true)) {
        throw new ApiException(422, 'invalid_status', 'Status must be active, paused, or stopped.');
    }
    $entity = gt_require_writable_entity($entityId);

    gt_enforce_rate_limit(
        'status_write:' . (int) $user['id'],
        120,
        60,
        'Too many status updates. Try again shortly.'
    );

    $stmt = gt_db()->prepare('UPDATE tracked_entities SET tracking_status = ? WHERE id = ?');
    $stmt->execute([$status, $entityId]);

    $source = (int) ($entity['is_demo'] ?? 0) === 1 ? 'simulated' : (string) $entity['tracking_method'];
    if (!in_array($source, ['gps', 'simulated', 'ip'], true)) {
        $source = 'gps';
    }

    if ($status === 'active') {
        $existing = gt_db()->prepare(
            'SELECT id FROM tracking_sessions WHERE entity_id = ? AND status IN ("active", "paused") ORDER BY id DESC LIMIT 1'
        );
        $existing->execute([$entityId]);
        $sessionId = $existing->fetchColumn();
        if ($sessionId !== false && $sessionId !== null) {
            $update = gt_db()->prepare(
                'UPDATE tracking_sessions
                 SET status = "active", last_heartbeat_at = UTC_TIMESTAMP(), stopped_at = NULL
                 WHERE id = ?'
            );
            $update->execute([(int) $sessionId]);
        } else {
            $uuid = sprintf(
                '%s-%s-%s-%s-%s',
                bin2hex(random_bytes(4)),
                bin2hex(random_bytes(2)),
                bin2hex(random_bytes(2)),
                bin2hex(random_bytes(2)),
                bin2hex(random_bytes(6))
            );
            $insert = gt_db()->prepare(
                'INSERT INTO tracking_sessions
                    (entity_id, user_id, session_uuid, source_type, status, started_at, last_heartbeat_at, client_ip_masked)
                 VALUES (?, ?, ?, ?, "active", UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?)'
            );
            $insert->execute([$entityId, (int) $user['id'], $uuid, $source, gt_masked_ip(gt_client_ip())]);
        }
    } elseif ($status === 'paused') {
        $update = gt_db()->prepare('UPDATE tracking_sessions SET status = "paused" WHERE entity_id = ? AND status = "active"');
        $update->execute([$entityId]);
    } else {
        $update = gt_db()->prepare(
            'UPDATE tracking_sessions
             SET status = "stopped", stopped_at = UTC_TIMESTAMP()
             WHERE entity_id = ? AND status IN ("active", "paused")'
        );
        $update->execute([$entityId]);
    }

    gt_audit('tracking_status', $entityId, 'Status set to ' . $status);
    gt_api_success([
        'entity_id' => $entityId,
        'tracking_status' => $status,
    ]);
} catch (Throwable $e) {
    throw $e;
}

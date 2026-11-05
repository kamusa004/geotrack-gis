<?php

require_once dirname(__DIR__) . '/includes/api.php';

$user = gt_require_auth();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($method === 'GET') {
        $retention = (int) gt_config()['app']['retention_days'];
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($retention * 86400));

        $totals = gt_db()->query(
            'SELECT COUNT(*) AS total,
                    SUM(recorded_at < ' . gt_db()->quote($cutoff) . ') AS expired
             FROM location_history'
        )->fetch() ?: [];

        $demoStmt = gt_db()->prepare(
            'SELECT COUNT(*) FROM location_history lh
             INNER JOIN tracked_entities e ON e.id = lh.entity_id
             WHERE e.is_demo = 1'
        );
        $demoStmt->execute();
        $demoCount = (int) $demoStmt->fetchColumn();

        $mineStmt = gt_db()->prepare(
            'SELECT COUNT(*) FROM location_history lh
             INNER JOIN tracked_entities e ON e.id = lh.entity_id
             WHERE e.owner_user_id = ?'
        );
        $mineStmt->execute([(int) $user['id']]);
        $myCount = (int) $mineStmt->fetchColumn();

        $lastPurge = gt_db()->prepare('SELECT meta_value FROM system_meta WHERE meta_key = ? LIMIT 1');
        $lastPurge->execute(['last_retention_purge']);
        $lastPurgeValue = $lastPurge->fetchColumn();

        gt_api_success([
            'retention_days' => $retention,
            'purge_cutoff' => gt_iso_datetime($cutoff),
            'total_records' => (int) ($totals['total'] ?? 0),
            'expired_records' => (int) ($totals['expired'] ?? 0),
            'demo_records' => $demoCount,
            'my_records' => $myCount,
            'last_purge' => $lastPurgeValue !== false ? gt_iso_datetime(gmdate('Y-m-d H:i:s', (int) $lastPurgeValue)) : null,
            'is_admin' => $user['role'] === 'admin',
            'auto_purge_note' => 'Expired records are purged automatically at most once per day when an administrator signs in. You can also run a manual purge below or schedule api/maintenance.php from cron.',
        ]);
    }

    if ($method === 'POST') {
        gt_csrf_check();
        $body = gt_read_json(['POST']);
        $action = strtolower(trim((string) ($body['action'] ?? '')));
        gt_enforce_rate_limit('maintenance:' . (int) $user['id'], 20, 60, 'Too many maintenance requests. Try again shortly.');

        if ($action === 'purge_expired') {
            if ($user['role'] !== 'admin') {
                throw new ApiException(403, 'forbidden', 'Only administrators can purge expired records.');
            }
            $deleted = gt_maybe_purge_expired(true);
            gt_api_success([
                'action' => 'purge_expired',
                'deleted_count' => $deleted,
                'message' => $deleted === 0
                    ? 'No records were older than the retention window.'
                    : 'Deleted ' . $deleted . ' record(s) older than ' . (int) gt_config()['app']['retention_days'] . ' days.',
            ]);
        }

        if ($action === 'clear_demo_history') {
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
            $reset = gt_db()->prepare('UPDATE tracked_entities SET tracking_status = "stopped", last_seen_at = NULL WHERE is_demo = 1');
            $reset->execute();
            gt_audit('demo_history_cleared', null, 'Deleted ' . $deleted . ' demo record(s) from maintenance.');
            gt_api_success([
                'action' => 'clear_demo_history',
                'deleted_count' => $deleted,
                'message' => 'Cleared ' . $deleted . ' demonstration record(s). Demo entities were reset to stopped.',
            ]);
        }

        if ($action === 'clear_my_data') {
            $stmt = gt_db()->prepare(
                'DELETE lh FROM location_history lh
                 INNER JOIN tracked_entities e ON e.id = lh.entity_id
                 WHERE e.owner_user_id = ?'
            );
            $stmt->execute([(int) $user['id']]);
            $deleted = $stmt->rowCount();
            $reset = gt_db()->prepare('UPDATE tracked_entities SET last_seen_at = NULL, tracking_status = "stopped" WHERE owner_user_id = ?');
            $reset->execute([(int) $user['id']]);
            gt_audit('my_location_data_cleared', null, 'Deleted ' . $deleted . ' own record(s).');
            gt_api_success([
                'action' => 'clear_my_data',
                'deleted_count' => $deleted,
                'message' => 'Deleted ' . $deleted . ' of your stored location record(s).',
            ]);
        }

        if ($action === 'purge_cache') {
            if ($user['role'] !== 'admin') {
                throw new ApiException(403, 'forbidden', 'Only administrators can clear the geolocation cache.');
            }
            $deleted = gt_db()->exec('DELETE FROM ip_geolocation_cache WHERE expires_at < UTC_TIMESTAMP()');
            gt_api_success(['action' => 'purge_cache', 'deleted_count' => (int) $deleted]);
        }

        throw new ApiException(400, 'unknown_action', 'Supported actions: purge_expired, clear_demo_history, clear_my_data, purge_cache.');
    }

    throw new ApiException(405, 'method_not_allowed', 'Allowed methods: GET, POST.');
} catch (Throwable $e) {
    throw $e;
}

<?php
/**
 * Shared bootstrap for every GeoTrack GIS request.
 * Handles configuration, secure sessions, JSON/CSRF helpers, authorization,
 * auditing, rate limiting, and the safe Python bridge.
 */

declare(strict_types=1);

define('GT_APP_ROOT', dirname(__DIR__));

require_once GT_APP_ROOT . '/config/database.php';

date_default_timezone_set('UTC');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
putenv('PYTHONIOENCODING=UTF-8');
putenv('PYTHONUTF8=1');

$GLOBALS['GEOTRACK_CONFIG'] = gt_config();

$GLOBALS['GEOTRACK_STORAGE'] = GT_APP_ROOT . '/storage';
@mkdir($GLOBALS['GEOTRACK_STORAGE'] . '/rate', 0775, true);
@mkdir($GLOBALS['GEOTRACK_STORAGE'] . '/cache', 0775, true);

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: geolocation=(self), camera=(), microphone=()');
    header('Cross-Origin-Opener-Policy: same-origin');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = gt_is_https();
    session_name((string) gt_config()['app']['session_name']);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'secure' => $secure,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Idle-session expiry.
$idleTimeout = (int) gt_config()['app']['session_idle_timeout'];
if (!empty($_SESSION['user_id'])) {
    $last = isset($_SESSION['last_activity']) ? (int) $_SESSION['last_activity'] : time();
    if (($last + $idleTimeout) < time()) {
        unset($_SESSION['user_id'], $_SESSION['last_activity']);
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['session_expired'] = true;
    }
}
$_SESSION['last_activity'] = time();

/**
 * API-friendly exception. The exception handler turns this into JSON.
 */
class ApiException extends RuntimeException
{
    public int $status;
    public string $errorCode;
    /** @var array<string,mixed>|null */
    public ?array $details;

    /**
     * @param array<string,mixed>|null $details
     */
    public function __construct(int $status, string $errorCode, string $message, ?array $details = null)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->details = $details;
    }
}

function gt_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }
    if (gt_config()['security']['trust_proxy'] === true) {
        $proto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        return $proto === 'https';
    }
    return false;
}

function gt_json_encode(mixed $value): string
{
    try {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
    } catch (JsonException $e) {
        error_log('JSON encode failed: ' . $e->getMessage());
        return '{"success":false,"error":{"code":"json_error","message":"Could not encode the response."}}';
    }
}

/**
 * Send a JSON response and stop.
 */
function gt_json_response(mixed $data, int $status = 200): void
{
    $GLOBALS['GEOTRACK_RESPONDED'] = true;
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
    }
    echo gt_json_encode($data);
    exit;
}

/**
 * @param array<string,mixed> $data
 */
function gt_api_success(array $data = [], int $status = 200): void
{
    gt_json_response(['success' => true, 'data' => $data], $status);
}

function gt_api_fail(int $status, string $code, string $message, ?array $details = null): void
{
    $error = ['code' => $code, 'message' => $message];
    if ($details !== null) {
        $error['details'] = $details;
    }
    if ($status === 401) {
        $error['auth_required'] = true;
    }
    gt_json_response(['success' => false, 'error' => $error], $status);
}

function gt_csrf_token(): string
{
    return (string) $_SESSION['csrf_token'];
}

function gt_csrf_check(): void
{
    $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($sent === '' && isset($_POST['csrf_token'])) {
        $sent = (string) $_POST['csrf_token'];
    }
    if ($sent === '' || !hash_equals(gt_csrf_token(), $sent)) {
        throw new ApiException(419, 'csrf_failed', 'Your security token expired. Refresh the page and sign in again.');
    }
}

function gt_read_json(array $allowedMethods = ['POST', 'PUT', 'PATCH', 'DELETE']): array
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, $allowedMethods, true)) {
        throw new ApiException(405, 'method_not_allowed', 'This endpoint does not accept ' . $method . ' requests.');
    }

    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $max = (int) gt_config()['security']['max_request_bytes'];
    if ($length > $max) {
        throw new ApiException(413, 'payload_too_large', 'The request body is too large.');
    }

    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType !== 'application/json') {
        throw new ApiException(415, 'unsupported_media_type', 'Send this request as application/json.');
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    if (strlen($raw) > $max) {
        throw new ApiException(413, 'payload_too_large', 'The request body is too large.');
    }

    try {
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new ApiException(400, 'invalid_json', 'The request body is not valid JSON.');
    }
    if (!is_array($decoded)) {
        throw new ApiException(400, 'invalid_json', 'The JSON body must be an object.');
    }
    return $decoded;
}

function gt_require_method(string ...$allowed): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $allowed = array_map('strtoupper', $allowed);
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        throw new ApiException(405, 'method_not_allowed', 'Allowed methods: ' . implode(', ', $allowed) . '.');
    }
}

/**
 * @return array<string,mixed>|null
 */
function gt_current_user(): ?array
{
    static $loaded = false;
    static $user = null;
    if ($loaded) {
        return $user;
    }
    $loaded = true;

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = gt_db()->prepare(
        'SELECT id, username, display_name, role, is_active, created_at, last_login_at
         FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([(int) $_SESSION['user_id']]);
    $row = $stmt->fetch();

    if (!$row || (int) $row['is_active'] !== 1) {
        unset($_SESSION['user_id']);
        return null;
    }

    $user = [
        'id' => (int) $row['id'],
        'username' => (string) $row['username'],
        'display_name' => (string) $row['display_name'],
        'role' => (string) $row['role'],
        'created_at' => gt_iso_datetime($row['created_at']),
        'last_login_at' => gt_iso_datetime($row['last_login_at']),
    ];
    return $user;
}

/**
 * @return array<string,mixed>
 */
function gt_require_auth(): array
{
    $user = gt_current_user();
    if ($user === null) {
        throw new ApiException(401, 'auth_required', 'Please sign in to continue.');
    }
    return $user;
}

/**
 * @return array<string,mixed>
 */
function gt_require_admin(): array
{
    $user = gt_require_auth();
    if ($user['role'] !== 'admin') {
        throw new ApiException(403, 'forbidden', 'This action requires an administrator account.');
    }
    return $user;
}

function gt_is_admin(?array $user = null): bool
{
    $user = $user ?? gt_current_user();
    return $user !== null && $user['role'] === 'admin';
}

/**
 * Best-effort client IP. Only trusts proxy headers when explicitly enabled.
 */
function gt_client_ip(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (gt_config()['security']['trust_proxy'] === true && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
        $candidate = trim($parts[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
}

function gt_masked_ip(string $ip): string
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ip);
        $parts[3] = 'x';
        return implode('.', $parts);
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $parts = explode(':', $ip);
        $parts[count($parts) - 1] = 'xxxx';
        return implode(':', $parts);
    }
    return 'unknown';
}

function gt_is_public_ip(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

/**
 * Small file-backed sliding-window rate limiter. Fails open if storage is
 * unwritable so the applicaton remains usable on restricted shared hosting.
 *
 * @return array{allowed:bool,retry_after:int,remaining:int}
 */
function gt_rate_limit(string $key, int $max, int $windowSeconds): array
{
    $dir = $GLOBALS['GEOTRACK_STORAGE'] . '/rate';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_writable($dir)) {
        return ['allowed' => true, 'retry_after' => 0, 'remaining' => $max];
    }

    $file = $dir . '/' . hash('sha256', $key) . '.json';
    $handle = @fopen($file, 'c+');
    if ($handle === false) {
        return ['allowed' => true, 'retry_after' => 0, 'remaining' => $max];
    }

    $allowed = true;
    $retryAfter = 0;
    $remaining = $max;

    if (flock($handle, LOCK_EX)) {
        $raw = stream_get_contents($handle);
        $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $now = time();
        if (!is_array($state) || !isset($state['start'], $state['count']) || ($now - (int) $state['start']) >= $windowSeconds) {
            $state = ['start' => $now, 'count' => 0];
        }
        $state['count'] = (int) $state['count'] + 1;
        $allowed = $state['count'] <= $max;
        $retryAfter = max(0, $windowSeconds - ($now - (int) $state['start']));
        $remaining = max(0, $max - (int) $state['count']);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) json_encode($state));
        fflush($handle);
        flock($handle, LOCK_UN);
    }
    fclose($handle);

    return ['allowed' => $allowed, 'retry_after' => $retryAfter, 'remaining' => $remaining];
}

function gt_enforce_rate_limit(string $key, int $max, int $windowSeconds, string $message): void
{
    $limit = gt_rate_limit($key, $max, $windowSeconds);
    if (!$limit['allowed']) {
        throw new ApiException(429, 'rate_limited', $message, ['retry_after' => $limit['retry_after']]);
    }
}

function gt_audit(string $action, ?int $entityId = null, ?string $details = null): void
{
    try {
        $stmt = gt_db()->prepare(
            'INSERT INTO audit_log (user_id, action, entity_id, details, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            substr($action, 0, 64),
            $entityId,
            $details !== null ? substr($details, 0, 500) : null,
            gt_client_ip(),
        ]);
    } catch (Throwable $e) {
        error_log('Audit log write failed: ' . $e->getMessage());
    }
}

/**
 * Convert a MySQL DATETIME/DATETIME(3) string (stored in UTC) to ISO-8601 UTC.
 */
function gt_iso_datetime(?string $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    try {
        $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Parse a client timestamp. Rejects timestamps far from "now" to prevent
 * accidental or malicious backdated live records.
 */
function gt_parse_client_datetime(mixed $value, int $futureTolerance = 180, int $pastDays = 7): string
{
    if (!is_string($value) || trim($value) === '') {
        throw new ApiException(422, 'invalid_timestamp', 'A valid recorded_at timestamp is required.');
    }
    $value = trim($value);
    if (strlen($value) > 40) {
        throw new ApiException(422, 'invalid_timestamp', 'The recorded_at timestamp is too long.');
    }
    try {
        $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        throw new ApiException(422, 'invalid_timestamp', 'recorded_at must be an ISO-8601 date/time.');
    }
    $utc = $dt->setTimezone(new DateTimeZone('UTC'));
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($utc->getTimestamp() > ($now->getTimestamp() + $futureTolerance)) {
        throw new ApiException(422, 'timestamp_in_future', 'The recorded_at timestamp is in the future. Check the device clock.');
    }
    if ($utc->getTimestamp() < ($now->getTimestamp() - ($pastDays * 86400))) {
        throw new ApiException(422, 'timestamp_too_old', 'Live locations must be recorded within the last ' . $pastDays . ' days.');
    }
    return $utc->format('Y-m-d H:i:s.v');
}

/**
 * Parse an optional analysis range bound (ISO date/time). Returns MySQL format.
 */
function gt_parse_range_datetime(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_string($value) || strlen($value) > 40) {
        throw new ApiException(422, 'invalid_datetime', 'Date/time filters must be ISO-8601 strings.');
    }
    try {
        $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        throw new ApiException(422, 'invalid_datetime', 'Date/time filters must be ISO-8601 strings.');
    }
}

/**
 * @return array<string,mixed>|null
 */
function gt_find_entity(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $stmt = gt_db()->prepare('SELECT * FROM tracked_entities WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * @param array<string,mixed> $entity
 */
function gt_can_read_entity(array $entity, ?array $user = null): bool
{
    $user = $user ?? gt_current_user();
    if ($user === null) {
        return false;
    }
    if ($user['role'] === 'admin') {
        return true;
    }
    if ((int) ($entity['is_demo'] ?? 0) === 1) {
        return true;
    }
    return isset($entity['owner_user_id']) && (int) $entity['owner_user_id'] === (int) $user['id'];
}

/**
 * Write access: admin, owner, or a matching per-entity API token.
 *
 * @param array<string,mixed> $entity
 */
function gt_can_write_entity(array $entity, ?array $user = null): bool
{
    $user = $user ?? gt_current_user();
    if ($user === null) {
        return false;
    }
    if ($user['role'] === 'admin') {
        return true;
    }
    if ((int) ($entity['is_demo'] ?? 0) === 1) {
        return false; // Demo/simulated entities are admin-only to prevent fake data mixing.
    }
    if (isset($entity['owner_user_id']) && (int) $entity['owner_user_id'] === (int) $user['id']) {
        return true;
    }

    $token = (string) ($_SERVER['HTTP_X_ENTITY_TOKEN'] ?? '');
    $hash = (string) ($entity['access_token_hash'] ?? '');
    return $token !== '' && $hash !== '' && hash_equals($hash, hash('sha256', $token));
}

/**
 * Management (delete, rename, clear history): admin or non-demo owner.
 *
 * @param array<string,mixed> $entity
 */
function gt_can_manage_entity(array $entity, ?array $user = null): bool
{
    $user = $user ?? gt_current_user();
    if ($user === null) {
        return false;
    }
    if ($user['role'] === 'admin') {
        return true;
    }
    if ((int) ($entity['is_demo'] ?? 0) === 1) {
        return false;
    }
    return isset($entity['owner_user_id']) && (int) $entity['owner_user_id'] === (int) $user['id'];
}

/**
 * @return array<string,mixed>
 */
function gt_require_readable_entity(int $id): array
{
    $entity = gt_find_entity($id);
    $user = gt_require_auth();
    if ($entity === null || !gt_can_read_entity($entity, $user)) {
        // Deliberately does not reveal whether an inaccessible ID exists.
        throw new ApiException(404, 'entity_not_found', 'Tracked entity not found or access is denied.');
    }
    return $entity;
}

/**
 * @return array<string,mixed>
 */
function gt_require_writable_entity(int $id): array
{
    $entity = gt_find_entity($id);
    $user = gt_require_auth();
    if ($entity === null) {
        throw new ApiException(404, 'entity_not_found', 'Tracked entity not found.');
    }
    if (!gt_can_write_entity($entity, $user)) {
        throw new ApiException(403, 'forbidden', 'You are not allowed to write locations for this entity.');
    }
    return $entity;
}

/**
 * Public representation of an entity row. Never exposes access_token_hash.
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function gt_entity_payload(array $row, ?array $user = null): array
{
    $user = $user ?? gt_current_user();
    $payload = [
        'id' => (int) $row['id'],
        'entity_code' => (string) $row['entity_code'],
        'entity_name' => (string) $row['entity_name'],
        'entity_type' => (string) $row['entity_type'],
        'tracking_method' => (string) $row['tracking_method'],
        'tracking_status' => (string) $row['tracking_status'],
        'is_demo' => (int) ($row['is_demo'] ?? 0) === 1,
        'is_owner' => $user !== null && isset($row['owner_user_id']) && (int) $row['owner_user_id'] === (int) $user['id'],
        'can_manage' => gt_can_manage_entity($row, $user),
        'can_write' => gt_can_write_entity($row, $user),
        'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
        'last_seen_at' => gt_iso_datetime(isset($row['last_seen_at']) ? (string) $row['last_seen_at'] : null),
        'created_at' => gt_iso_datetime(isset($row['created_at']) ? (string) $row['created_at'] : null),
        'updated_at' => gt_iso_datetime(isset($row['updated_at']) ? (string) $row['updated_at'] : null),
        'point_count' => isset($row['point_count']) ? (int) $row['point_count'] : null,
        'last_location' => null,
    ];

    if (isset($row['last_lat']) && $row['last_lat'] !== null) {
        $payload['last_location'] = [
            'latitude' => (float) $row['last_lat'],
            'longitude' => (float) $row['last_lng'],
            'accuracy' => $row['last_accuracy'] !== null ? (float) $row['last_accuracy'] : null,
            'speed' => $row['last_speed'] !== null ? (float) $row['last_speed'] : null,
            'recorded_at' => gt_iso_datetime(isset($row['last_recorded_at']) ? (string) $row['last_recorded_at'] : null),
            'source_type' => isset($row['last_source']) ? (string) $row['last_source'] : null,
        ];
    }

    return $payload;
}

/**
 * @return array<string,mixed>
 */
function gt_location_payload(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'entity_id' => (int) $row['entity_id'],
        'latitude' => (float) $row['latitude'],
        'longitude' => (float) $row['longitude'],
        'accuracy' => $row['accuracy'] !== null ? (float) $row['accuracy'] : null,
        'speed' => $row['speed'] !== null ? (float) $row['speed'] : null,
        'recorded_at' => gt_iso_datetime((string) $row['recorded_at']),
        'source_type' => (string) $row['source_type'],
        'quality_flag' => (string) ($row['quality_flag'] ?? 'ok'),
        'quality_note' => $row['quality_note'] ?? null,
    ];
}

/**
 * Candidate Python interpreters, configured first.
 *
 * @return list<string>
 */
function gt_python_candidates(): array
{
    $py = gt_config()['python'];
    $candidates = [(string) $py['bin']];
    foreach ((array) ($py['fallbacks'] ?? []) as $fallback) {
        if (is_string($fallback) && $fallback !== '') {
            $candidates[] = $fallback;
        }
    }
    return array_values(array_unique($candidates));
}

/**
 * Configuration that is safe to send to the browser.
 *
 * @return array<string,mixed>
 */
function gt_public_app_config(): array
{
    $config = gt_config();
    $script = realpath((string) $config['python']['script']);
    $ip = $config['ip_geolocation'];
    $demoHint = null;
    if ($config['app']['show_demo_credentials'] === true && $config['bootstrap_admin']['enabled'] === true) {
        $demoHint = [
            'username' => (string) $config['bootstrap_admin']['username'],
            'password' => (string) $config['bootstrap_admin']['password'],
            'note' => 'Classroom bootstrap account. Change the password after installation.',
        ];
    }

    return [
        'name' => $config['app']['name'],
        'version' => $config['app']['version'],
        'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
        'map' => $config['map'],
        'simulation' => [
            'interval_ms' => (int) $config['simulation']['interval_ms'],
            'max_local_points' => (int) $config['simulation']['max_local_points'],
        ],
        'analysis' => [
            'default_radius_m' => (int) $config['analysis']['default_radius_m'],
            'max_radius_m' => (int) $config['analysis']['max_radius_m'],
            'network_days' => (int) $config['analysis']['network_days'],
        ],
        'retention_days' => (int) $config['app']['retention_days'],
        'ip_geolocation' => [
            'enabled' => (bool) $ip['enabled'],
            'provider' => (string) $ip['provider'],
        ],
        'python_configured' => $script !== false && is_file((string) $script),
        'demo_hint' => $demoHint,
        'security' => [
            'session_idle_minutes' => (int) floor(((int) $config['app']['session_idle_timeout']) / 60),
            'retention_days' => (int) $config['app']['retention_days'],
        ],
    ];
}

/**
 * Session bootstrap sent to the browser on page load and after login.
 *
 * @return array<string,mixed>
 */
function gt_session_payload(): array
{
    $user = gt_current_user();
    return [
        'authenticated' => $user !== null,
        'user' => $user,
        'csrf_token' => gt_csrf_token(),
        'session_expired' => (bool) ($_SESSION['session_expired'] ?? false),
        'app' => gt_public_app_config(),
    ];
}

/**
 * Run the Python GIS module with a JSON payload on stdin.
 * Command arguments are fixed (never user input), and proc_open is called
 * with an argument array so no shell is involved.
 *
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function gt_python_call(array $payload, array $extraArgs = []): array
{
    $script = realpath((string) gt_config()['python']['script']);
    if ($script === false || !is_file($script)) {
        throw new ApiException(
            503,
            'python_unavailable',
            'The Python analysis module was not found. Check python.script in config/config.php.'
        );
    }

    $timeout = (int) gt_config()['python']['timeout_seconds'];
    $stdin = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $lastError = 'Python could not be started.';
    $scriptDir = dirname($script);

    foreach (gt_python_candidates() as $bin) {
        $command = array_merge([$bin, $script], $extraArgs);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = @proc_open(
            $command,
            $descriptors,
            $pipes,
            $scriptDir,
            null,
            ['bypass_shell' => true]
        );

        if (!is_resource($process)) {
            $lastError = 'Could not start "' . $bin . '". Install Python or set GEOTRACK_PYTHON.';
            continue;
        }

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeout;
        $timedOut = false;

        while (true) {
            $status = proc_get_status($process);
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, 200000);
            if ($ready > 0) {
                foreach ($read as $stream) {
                    $chunk = stream_get_contents($stream);
                    if ($chunk !== false && $chunk !== '') {
                        if ($stream === $pipes[1]) {
                            $stdout .= $chunk;
                        } else {
                            $stderr .= $chunk;
                        }
                    }
                }
            }
            if (!$status['running']) {
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                break;
            }
            if (microtime(true) > $deadline) {
                $timedOut = true;
                @proc_terminate($process, 9);
                break;
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($timedOut) {
            $lastError = 'The Python analysis timed out after ' . $timeout . ' seconds.';
            continue;
        }

        $trimmed = trim($stdout);
        if ($trimmed === '') {
            $lastError = 'Python returned no output. ' . trim(substr($stderr, 0, 500));
            continue;
        }

        try {
            $decoded = json_decode($trimmed, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $lastError = 'Python returned invalid JSON. ' . trim(substr($stderr !== '' ? $stderr : $stdout, 0, 500));
            continue;
        }

        if (!is_array($decoded)) {
            $lastError = 'Python returned an unexpected result.';
            continue;
        }

        // A successful interpreter run — use this one from now on.
        return $decoded;
    }

    error_log('Python bridge failure: ' . $lastError);
    throw new ApiException(
        503,
        'python_unavailable',
        'The Python GIS analysis service is unavailable. Install Python 3.8+ and set python.bin in config/config.php (or GEOTRACK_PYTHON). Details: ' . $lastError,
        ['how_to_fix' => 'Run: python --version. Then set the full path to python.exe in config/config.local.php.']
    );
}

/**
 * Run an analysis action and require a successful envelope.
 *
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function gt_analyse(array $payload): array
{
    $result = gt_python_call($payload);
    if (($result['ok'] ?? false) !== true) {
        $error = $result['error'] ?? [];
        $message = is_array($error) && isset($error['message'])
            ? (string) $error['message']
            : 'The analysis request was rejected.';
        $code = is_array($error) && isset($error['code']) ? (string) $error['code'] : 'analysis_error';
        throw new ApiException(422, $code, $message, isset($error['details']) && is_array($error['details']) ? $error['details'] : null);
    }
    return $result;
}

/**
 * Run the Python self-test (safe fixed argument).
 *
 * @return array<string,mixed>
 */
function gt_python_selftest(): array
{
    return gt_python_call(['action' => 'selftest']);
}

/**
 * Haversine distance in metres, used only for lightweight immediate checks.
 * Kept in PHP for request-time validation; full analysis is in Python.
 */
function gt_haversine_m(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earth = 6371008.8;
    $p1 = deg2rad($lat1);
    $p2 = deg2rad($lat2);
    $dp = deg2rad($lat2 - $lat1);
    $dl = deg2rad($lon2 - $lon1);
    $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function gt_is_private_or_reserved_ip(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return true;
    }
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

/**
 * Purge records older than the retention window. Called when an administrator
 * signs in and from maintenance actions. Uses a system_meta timestamp so it
 * runs at most once per day.
 */
function gt_maybe_purge_expired(bool $force = false): int
{
    $days = max(1, (int) gt_config()['app']['retention_days']);
    $now = time();

    if (!$force) {
        $stmt = gt_db()->prepare('SELECT meta_value FROM system_meta WHERE meta_key = ? LIMIT 1');
        $stmt->execute(['last_retention_purge']);
        $last = $stmt->fetchColumn();
        if ($last !== false && $last !== null && (int) $last > ($now - 86400)) {
            return 0;
        }
    }

    $cutoff = gmdate('Y-m-d H:i:s', $now - ($days * 86400));
    $stmt = gt_db()->prepare('DELETE FROM location_history WHERE recorded_at < ?');
    $stmt->execute([$cutoff]);
    $deleted = $stmt->rowCount();

    $upsert = gt_db()->prepare(
        'INSERT INTO system_meta (meta_key, meta_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)'
    );
    $upsert->execute(['last_retention_purge', (string) $now]);

    if ($deleted > 0) {
        gt_audit('retention_purge', null, 'Deleted ' . $deleted . ' record(s) older than ' . $days . ' days.');
    }
    return $deleted;
}

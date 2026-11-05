<?php
/**
 * API bootstrap: JSON headers, global exception handling, and fatal-error
 * safety. Every file in /api must require this first.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/app.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    if ($e instanceof ApiException) {
        $error = [
            'code' => $e->errorCode,
            'message' => $e->getMessage(),
        ];
        if ($e->details !== null) {
            $error['details'] = $e->details;
        }
        if ($e->status === 401) {
            $error['auth_required'] = true;
        }
        gt_json_response(['success' => false, 'error' => $error], $e->status);
    }

    if ($e instanceof PDOException) {
        error_log('Database error: ' . $e->getMessage());
        gt_api_fail(
            503,
            'database_unavailable',
            'The database is unavailable. Ensure MySQL is running and config/config.local.php has the correct credentials, then import database/geotrack_gis.sql.'
        );
    }

    $reference = bin2hex(random_bytes(4));
    error_log('[' . $reference . '] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    gt_api_fail(
        500,
        'server_error',
        'An unexpected server error occurred. Check the PHP error log for reference ' . $reference . '.',
        ['reference' => $reference]
    );
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'], $fatal, true)) {
        return;
    }
    if (!empty($GLOBALS['GEOTRACK_RESPONDED'])) {
        return;
    }
    error_log('Fatal error: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"success":false,"error":{"code":"server_error","message":"A fatal server error occurred. Check the PHP error log."}}';
    }
});

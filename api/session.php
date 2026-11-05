<?php

require_once dirname(__DIR__) . '/includes/api.php';

try {
    gt_require_method('GET', 'POST');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        gt_api_success(gt_session_payload());
    }

    gt_csrf_check();
    $body = gt_read_json(['POST']);
    $action = strtolower(trim((string) ($body['action'] ?? '')));

    if ($action === 'login') {
        $username = strtolower(trim((string) ($body['username'] ?? '')));
        $password = (string) ($body['password'] ?? '');

        if ($username === '' || $password === '') {
            throw new ApiException(422, 'missing_credentials', 'Enter a username and password.');
        }

        gt_enforce_rate_limit(
            'login:' . gt_client_ip(),
            (int) gt_config()['security']['login_max_attempts'],
            (int) gt_config()['security']['login_window_seconds'],
            'Too many sign-in attempts. Try again later.'
        );

        $stmt = gt_db()->prepare(
            'SELECT id, username, display_name, password_hash, role, is_active, login_attempts, locked_until
             FROM users WHERE LOWER(username) = ? LIMIT 1'
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user) {
            // Bootstrap admin on first run if enabled and no users exist.
            $countStmt = gt_db()->query('SELECT COUNT(*) FROM users');
            if ((int) $countStmt->fetchColumn() === 0 && gt_config()['bootstrap_admin']['enabled'] === true) {
                $bootstrap = gt_config()['bootstrap_admin'];
                if (strtolower(trim((string) $bootstrap['username'])) === $username && (string) $bootstrap['password'] === $password) {
                    $hash = password_hash((string) $bootstrap['password'], PASSWORD_DEFAULT);
                    $insert = gt_db()->prepare(
                        'INSERT INTO users (username, display_name, password_hash, role, is_active) VALUES (?, ?, ?, ?, 1)'
                    );
                    $insert->execute([
                        strtolower(trim((string) $bootstrap['username'])),
                        (string) $bootstrap['display_name'],
                        $hash,
                        (string) $bootstrap['role'],
                    ]);
                    $userId = (int) gt_db()->lastInsertId();
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    unset($_SESSION['session_expired']);
                    gt_audit('bootstrap_admin_login', null, 'First-run bootstrap administrator created and signed in.');
                    gt_maybe_purge_expired();
                    gt_api_success([
                        'user' => gt_current_user(),
                        'csrf_token' => gt_csrf_token(),
                        'app' => gt_public_app_config(),
                        'notice' => 'Bootstrap administrator created. Change the default password immediately.',
                    ]);
                }
            }
            gt_audit('login_failed_unknown', null, 'Username not found from ' . gt_masked_ip(gt_client_ip()));
            throw new ApiException(401, 'invalid_credentials', 'Invalid username or password.');
        }

        if ((int) $user['is_active'] !== 1) {
            throw new ApiException(403, 'account_disabled', 'This account has been disabled.');
        }

        $lockedUntil = $user['locked_until'] ? strtotime((string) $user['locked_until']) : 0;
        if ($lockedUntil > time()) {
            throw new ApiException(423, 'account_locked', 'This account is temporarily locked due to repeated failed sign-in attempts.');
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            $attempts = (int) $user['login_attempts'] + 1;
            $locked = $attempts >= (int) gt_config()['security']['login_max_attempts'] ? gmdate('Y-m-d H:i:s', time() + 900) : null;
            $update = gt_db()->prepare('UPDATE users SET login_attempts = ?, locked_until = ? WHERE id = ?');
            $update->execute([$attempts, $locked, (int) $user['id']]);
            gt_audit('login_failed', (int) $user['id'], 'Incorrect password from ' . gt_masked_ip(gt_client_ip()));
            throw new ApiException(401, 'invalid_credentials', 'Invalid username or password.');
        }

        $update = gt_db()->prepare('UPDATE users SET login_attempts = 0, locked_until = NULL, last_login_at = UTC_TIMESTAMP() WHERE id = ?');
        $update->execute([(int) $user['id']]);

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        unset($_SESSION['session_expired']);

        gt_audit('login_success', (int) $user['id']);
        gt_maybe_purge_expired();

        gt_api_success([
            'user' => gt_current_user(),
            'csrf_token' => gt_csrf_token(),
            'app' => gt_public_app_config(),
        ]);
    }

    if ($action === 'change_password') {
        $user = gt_require_auth();
        $current = (string) ($body['current_password'] ?? '');
        $new = (string) ($body['new_password'] ?? '');
        $confirm = (string) ($body['confirm_password'] ?? '');

        if ($current === '' || $new === '' || $confirm === '') {
            throw new ApiException(422, 'missing_fields', 'Enter your current password and the new password twice.');
        }
        if ($new !== $confirm) {
            throw new ApiException(422, 'password_mismatch', 'The new passwords do not match.');
        }
        if (strlen($new) < 10 || strlen($new) > 200) {
            throw new ApiException(422, 'weak_password', 'The new password must be between 10 and 200 characters.');
        }
        if (!preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
            throw new ApiException(422, 'weak_password', 'Use at least one letter and one number in the new password.');
        }

        $stmt = gt_db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $user['id']]);
        $hash = (string) $stmt->fetchColumn();
        if ($hash === '' || !password_verify($current, $hash)) {
            gt_audit('password_change_failed', null, 'Incorrect current password.');
            throw new ApiException(403, 'invalid_password', 'The current password is incorrect.');
        }

        $update = gt_db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $update->execute([password_hash($new, PASSWORD_DEFAULT), (int) $user['id']]);
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        gt_audit('password_changed', null);
        gt_api_success([
            'message' => 'Password updated successfully.',
            'csrf_token' => gt_csrf_token(),
        ]);
    }

    if ($action === 'logout') {
        $user = gt_current_user();
        if ($user !== null) {
            gt_audit('logout', null);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        gt_api_success(['logged_out' => true]);
    }

    if ($action === 'whoami') {
        gt_api_success([
            'user' => gt_current_user(),
            'csrf_token' => gt_csrf_token(),
            'app' => gt_public_app_config(),
        ]);
    }

    throw new ApiException(400, 'unknown_action', 'Unknown action for session endpoint.');
} catch (Throwable $e) {
    throw $e;
}

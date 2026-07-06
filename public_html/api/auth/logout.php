<?php

/**
 * api/auth/logout.php
 * Clears the current session and records the logout event.
 */

require_once __DIR__ . '/../../includes/auth_middleware.php';
require_once __DIR__ . '/../../includes/audit_logger.php';

start_secure_session();

$auth = current_user();

if ($auth !== null) {
    $userId = isset($auth['type']) && $auth['type'] === 'staff' ? (int)($auth['id'] ?? 0) : null;
    $description = ($auth['type'] ?? 'guest') === 'parent'
        ? 'Parent logout for ' . ($auth['email'] ?? 'unknown')
        : 'Staff logout for ' . ($auth['email'] ?? 'unknown');

    log_action($userId, 'LOGOUT', 'info', $description);
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
}

session_destroy();

if (wants_json_response()) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'message' => 'Logged out successfully.',
        'redirect_url' => app_url('/auth/login.php'),
    ]);
    exit;
}

    header('Location: ' . app_url('/auth/login.php'));
exit;

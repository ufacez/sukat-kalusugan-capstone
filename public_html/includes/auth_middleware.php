<?php

/**
 * auth_middleware.php
 * Session/role checking functions used at the top of every protected API/page.
 *
 * Functions to implement:
 *   start_secure_session(): void
 *   current_user(): array|null
 *   require_login(): void                 -- redirects/exits if not logged in
 *   require_permission(string $code): void -- checks role_permissions table
 *   is_parent_session(): bool             -- distinguishes staff vs parent session
 */

require_once __DIR__ . '/db.php';

function app_base_path(): string
{
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));

    if ($scriptName === '') {
        return '';
    }

    $routeMarkers = ['/api/', '/admin/', '/nutritionist/', '/parent/', '/kiosk/', '/auth/'];

    foreach ($routeMarkers as $marker) {
        $position = strpos($scriptName, $marker);

        if ($position !== false) {
            return rtrim(substr($scriptName, 0, $position), '/');
        }
    }

    if ($scriptName === '/index.php' || $scriptName === '/index') {
        return '';
    }

    return rtrim(dirname($scriptName), '/');
}

function app_url(string $path = ''): string
{
    $basePath = app_base_path();
    $normalizedPath = '/' . ltrim($path, '/');

    if ($basePath === '' || $basePath === '/') {
        return $normalizedPath;
    }

    return $basePath . $normalizedPath;
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

    session_name('sukat_kalusugan_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function current_user(): ?array
{
    start_secure_session();

    if (!isset($_SESSION['auth']) || !is_array($_SESSION['auth'])) {
        return null;
    }

    return $_SESSION['auth'];
}

function is_parent_session(): bool
{
    $user = current_user();

    return $user !== null && ($user['type'] ?? null) === 'parent';
}

function wants_json_response(): bool
{
    $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
    $xhr = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');

    return str_contains($accept, 'application/json') || $xhr === 'xmlhttprequest';
}

function redirect_for_current_user(array $user): string
{
    if (($user['type'] ?? null) === 'parent') {
        return app_url('/parent/dashboard.php');
    }

    return match ($user['role'] ?? '') {
        'admin' => app_url('/admin/dashboard.php'),
        'nutritionist' => app_url('/nutritionist/dashboard.php'),
        default => app_url('/auth/login.php'),
    };
}

function deny_access(string $message, int $statusCode = 403): void
{
    http_response_code($statusCode);

    if (wants_json_response()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => $message,
        ]);
        exit;
    }

    header('Location: ' . app_url('/auth/login.php?error=' . urlencode($message)));
    exit;
}

function require_login(): void
{
    if (current_user() !== null) {
        return;
    }

    deny_access('Please sign in to continue.', 401);
}

function require_permission(string $code): void
{
    $user = current_user();

    if ($user === null) {
        deny_access('Please sign in to continue.', 401);
    }

    if (($user['type'] ?? null) === 'parent') {
        deny_access('You do not have permission to access this page.', 403);
    }

    if (($user['role'] ?? null) === 'admin') {
        return;
    }

    $roleId = (int)($user['role_id'] ?? 0);

    if ($roleId <= 0) {
        deny_access('You do not have permission to access this page.', 403);
    }

    $conn = get_db_connection();
    $sql = '
		SELECT 1
		FROM role_permissions rp
		INNER JOIN permissions p ON p.id = rp.permission_id
		WHERE rp.role_id = ? AND p.code = ?
		LIMIT 1
	';
    $stmt = mysqli_prepare($conn, $sql);

    if ($stmt === false) {
        deny_access('Unable to verify permissions right now.', 500);
    }

    mysqli_stmt_bind_param($stmt, 'is', $roleId, $code);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);

    if (mysqli_stmt_num_rows($stmt) < 1) {
        mysqli_stmt_close($stmt);
        deny_access('You do not have permission to access this page.', 403);
    }

    mysqli_stmt_close($stmt);
}

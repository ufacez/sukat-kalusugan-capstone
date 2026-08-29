<?php

/**
 * auth_middleware.php
 * Session/role checking functions used at the top of every protected API/page.
 *
 * Functions to implement:
 *   start_secure_session(): void
 *   current_user(): array|null
 *   require_login(): void                 -- redirects/exits if not logged in
 *   require_permission(string $code): void -- checks per-user access_level
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

function start_secure_session(?int $lifetimeSeconds = null): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

    // Default 12-hour lifetime covers a full clinic day. Callers can pass
    // a custom value (e.g. 30 days for "remember me") before session_start.
    if ($lifetimeSeconds === null || $lifetimeSeconds <= 0) {
        $lifetimeSeconds = 12 * 60 * 60;
    }

    ini_set('session.gc_maxlifetime', (string)$lifetimeSeconds);

    session_name('sukat_kalusugan_session');
    session_set_cookie_params([
        'lifetime' => $lifetimeSeconds,
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

    $user = &$_SESSION['auth'];

    if (($user['type'] ?? null) === 'staff' && isset($user['id'])) {
        $conn = get_db_connection();
        $stmt = mysqli_prepare($conn, 'SELECT access_level, status FROM users WHERE id = ? LIMIT 1');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $user['id']);
            if (mysqli_stmt_execute($stmt)) {
                $result = mysqli_stmt_get_result($stmt);
                if ($row = mysqli_fetch_assoc($result)) {
                    $newLevel = $row['access_level'] ?? 'full';
                    $newStatus = $row['status'] ?? 'active';
                    if (($user['access_level'] ?? '') !== $newLevel) {
                        $user['access_level'] = $newLevel;
                    }
                    if (($user['status'] ?? '') !== $newStatus) {
                        $user['status'] = $newStatus;
                    }
                }
            }
            mysqli_stmt_close($stmt);
        }
    }

    return $user;
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

    $accessLevel = $user['access_level'] ?? 'full';

    if (!user_has_access_for_code($accessLevel, $code)) {
        deny_access('You do not have permission to access this page.', 403);
    }
}

function user_has_access_for_code(string $accessLevel, string $code): bool
{
    switch ($accessLevel) {
        case 'full':
            return true;
        case 'standard':
            if (in_array($code, ['settings.update', 'sensors.update', 'users.delete', 'parents.delete', 'children.delete'])) {
                return false;
            }
            return true;
        case 'readonly':
            return str_ends_with($code, '.view');
        default:
            return false;
    }
}

function has_permission(string $code): bool
{
    $user = current_user();

    if ($user === null) {
        return false;
    }

    if (($user['type'] ?? null) === 'parent') {
        return false;
    }

    $accessLevel = $user['access_level'] ?? 'full';

    return user_has_access_for_code($accessLevel, $code);
}
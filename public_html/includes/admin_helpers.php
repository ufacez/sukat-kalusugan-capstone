<?php

require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/audit_logger.php';

function admin_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function admin_nav_items(): array
{
    return [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => app_url('/admin/dashboard.php')],
        ['key' => 'users', 'label' => 'Users', 'href' => app_url('/admin/users.php')],
        ['key' => 'audit_logs', 'label' => 'Audit Logs', 'href' => app_url('/admin/audit_logs.php')],
        ['key' => 'roles_permissions', 'label' => 'Roles & Permissions', 'href' => app_url('/admin/roles_permissions.php')],
        ['key' => 'sensors', 'label' => 'Sensors', 'href' => app_url('/admin/sensors.php')],
        ['key' => 'settings', 'label' => 'Settings', 'href' => app_url('/admin/settings.php')],
    ];
}

function admin_bind_params(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '' || $params === []) {
        return;
    }

    $bindArgs = [$stmt, $types];

    foreach ($params as $index => &$value) {
        $bindArgs[] = &$value;
    }

    call_user_func_array('mysqli_stmt_bind_param', $bindArgs);
}

function admin_fetch_all(string $sql, string $types = '', array $params = []): array
{
    $conn = get_db_connection();
    $stmt = mysqli_prepare($conn, $sql);

    if ($stmt === false) {
        return [];
    }

    admin_bind_params($stmt, $types, $params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];

    if ($result instanceof mysqli_result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }

    mysqli_stmt_close($stmt);

    return $rows;
}

function admin_fetch_one(string $sql, string $types = '', array $params = []): ?array
{
    $rows = admin_fetch_all($sql, $types, $params);

    return $rows[0] ?? null;
}

function admin_execute(string $sql, string $types = '', array $params = []): bool
{
    $conn = get_db_connection();
    $stmt = mysqli_prepare($conn, $sql);

    if ($stmt === false) {
        return false;
    }

    admin_bind_params($stmt, $types, $params);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $ok;
}

function admin_scalar(string $sql, string $types = '', array $params = [], int $default = 0): int
{
    $row = admin_fetch_one($sql, $types, $params);

    if ($row === null) {
        return $default;
    }

    $value = array_values($row)[0] ?? $default;

    return (int)$value;
}

function admin_find_role_id(string $roleName): int
{
    return admin_scalar('SELECT id FROM roles WHERE name = ? LIMIT 1', 's', [$roleName]);
}

function admin_redirect(string $path, array $query = []): void
{
    $url = $path;

    if ($query !== []) {
        $url .= (str_contains($path, '?') ? '&' : '?') . http_build_query($query);
    }

    header('Location: ' . app_url($url));
    exit;
}

function admin_flash_message(): ?array
{
    $message = trim((string)($_GET['notice'] ?? ''));

    if ($message === '') {
        return null;
    }

    return [
        'message' => $message,
        'type' => trim((string)($_GET['type'] ?? 'success')),
    ];
}

function admin_layout_start(string $title, string $subtitle, string $activeSection, string $actionsHtml = ''): void
{
    $currentUser = current_user();
    $userName = $currentUser['name'] ?? 'Administrator';
    $userRole = $currentUser['role'] ?? 'admin';
    $flash = admin_flash_message();
    $navItems = admin_nav_items();
    $logoutUrl = app_url('/api/auth/logout.php');

    echo '<!doctype html>';
    echo '<html lang="en">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . admin_e($title) . ' | Sukat Kalusugan Admin</title>';
    echo '<link rel="stylesheet" href="' . admin_e(app_url('/assets/css/app.css')) . '">';
    echo '<link rel="stylesheet" href="' . admin_e(app_url('/assets/css/admin.css')) . '">';
    echo '</head>';
    echo '<body class="admin-page">';
    echo '<div class="admin-shell">';
    echo '<aside class="admin-sidebar" data-admin-sidebar>';
    echo '<div class="admin-brand">';
    echo '<div class="admin-brand-mark">SK</div>';
    echo '<div>';
    echo '<div class="admin-brand-name">Sukat Kalusugan</div>';
    echo '<div class="admin-brand-sub">Admin console</div>';
    echo '</div>';
    echo '</div>';
    echo '<nav class="admin-nav">';

    foreach ($navItems as $item) {
        $isActive = $item['key'] === $activeSection ? ' is-active' : '';
        echo '<a class="admin-nav-link' . $isActive . '" href="' . admin_e($item['href']) . '">' . admin_e($item['label']) . '</a>';
    }

    echo '</nav>';
    echo '<div class="admin-sidebar-footer">';
    echo '<div class="admin-session-card">';
    echo '<div class="admin-session-role">' . admin_e(ucfirst($userRole)) . '</div>';
    echo '<div class="admin-session-name">' . admin_e($userName) . '</div>';
    echo '</div>';
    echo '<a class="admin-logout" href="' . admin_e($logoutUrl) . '">Sign out</a>';
    echo '</div>';
    echo '</aside>';
    echo '<div class="admin-main">';
    echo '<header class="admin-topbar">';
    echo '<button class="admin-sidebar-toggle" type="button" data-admin-sidebar-toggle aria-label="Toggle navigation">☰</button>';
    echo '<div class="admin-pagehead">';
    echo '<p class="admin-kicker">Administration</p>';
    echo '<h1>' . admin_e($title) . '</h1>';
    echo '<p>' . admin_e($subtitle) . '</p>';
    echo '</div>';
    echo '<div class="admin-topbar-actions">' . $actionsHtml . '</div>';
    echo '</header>';

    if ($flash !== null) {
        $flashClass = $flash['type'] === 'error' ? 'admin-flash is-error' : 'admin-flash';
        echo '<div class="' . $flashClass . '">' . admin_e($flash['message']) . '</div>';
    }

    echo '<main class="admin-content">';
}

function admin_layout_end(): void
{
    echo '</main>';
    echo '</div>';
    echo '</div>';
    echo '<script src="' . admin_e(app_url('/assets/js/admin.js')) . '"></script>';
    echo '</body>';
    echo '</html>';
}

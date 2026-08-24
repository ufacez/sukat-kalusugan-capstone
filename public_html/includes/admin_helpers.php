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
        ['key' => 'barangays', 'label' => 'Barangays', 'href' => app_url('/admin/barangays.php')],
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
        error_log('[SukatKalusugan] admin_fetch_all prepare failed: ' . mysqli_error($conn) . ' | SQL: ' . $sql);

        return [];
    }

    admin_bind_params($stmt, $types, $params);

    if (!mysqli_stmt_execute($stmt)) {
        error_log('[SukatKalusugan] admin_fetch_all execute failed: ' . mysqli_stmt_error($stmt) . ' | SQL: ' . $sql);

        mysqli_stmt_close($stmt);

        return [];
    }

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
        error_log('[SukatKalusugan] admin_execute prepare failed: ' . mysqli_error($conn) . ' | SQL: ' . $sql);

        return false;
    }

    admin_bind_params($stmt, $types, $params);
    $ok = mysqli_stmt_execute($stmt);

    if (!$ok) {
        error_log('[SukatKalusugan] admin_execute failed: ' . mysqli_stmt_error($stmt) . ' | SQL: ' . $sql);
    }

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

/**
 * Best-effort split of a single "name" column into first / middle / last
 * name parts, used to pre-fill the Add/Edit forms when editing a record
 * that only ever stored one combined name string.
 */
function admin_split_full_name(?string $fullName): array
{
    $fullName = trim(preg_replace('/\s+/', ' ', (string)$fullName));

    if ($fullName === '') {
        return ['first' => '', 'middle' => '', 'last' => ''];
    }

    $parts = explode(' ', $fullName);

    if (count($parts) === 1) {
        return ['first' => $parts[0], 'middle' => '', 'last' => ''];
    }

    $first = array_shift($parts);
    $last = array_pop($parts);
    $middle = implode(' ', $parts);

    return ['first' => $first, 'middle' => $middle, 'last' => $last];
}

/**
 * Recombine first / middle / last name inputs into the single "name"
 * column used everywhere else in the app (dashboards, reports, audit
 * logs, session display, etc.), so no other file needs to change.
 */
function admin_combine_name(string $first, string $middle, string $last): string
{
    $parts = array_filter(
        [trim($first), trim($middle), trim($last)],
        static fn(string $part): bool => $part !== ''
    );

    return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));
}

/**
 * A name part (first/middle/last) may contain letters, spaces, hyphens,
 * apostrophes, and periods (e.g. "Jr.", "Sto. Niño", "D'Angelo").
 */
function admin_is_valid_name_part(string $value, bool $required = true): bool
{
    $value = trim($value);

    if ($value === '') {
        return !$required;
    }

    return (bool)preg_match("/^[A-Za-zÀ-ÖØ-öø-ÿ.'\\-\\s]{2,60}$/u", $value);
}

/**
 * Philippine mobile numbers: 11 digits, always starting with 09
 * (e.g. 0917xxxxxxx). Spaces/dashes are stripped before checking.
 */
function admin_is_valid_ph_mobile(string $phone): bool
{
    $digitsOnly = preg_replace('/[^0-9]/', '', $phone);

    return (bool)preg_match('/^09\d{9}$/', (string)$digitsOnly);
}

/**
 * Strong password: at least 8 characters with an uppercase letter,
 * a lowercase letter, a number, and a special character.
 */
function admin_is_strong_password(string $password): bool
{
    if (strlen($password) < 8) {
        return false;
    }

    return preg_match('/[a-z]/', $password) === 1
        && preg_match('/[A-Z]/', $password) === 1
        && preg_match('/[0-9]/', $password) === 1
        && preg_match('/[^A-Za-z0-9]/', $password) === 1;
}

/**
 * Shared dropdown source for every "assign a barangay" form across the
 * admin, nutritionist, and settings pages. Active barangays only, sorted
 * by name so the <select> stays predictable.
 */
function admin_barangay_options(): array
{
    return admin_fetch_all(
        "SELECT id, name, city_municipality, status
         FROM barangays
         WHERE status = 'active'
         ORDER BY name ASC"
    );
}

function admin_redirect(string $path, array $query = []): void
{
    $url = $path;

    if ($query !== []) {
        $url .= (str_contains($path, '?') ? '&' : '?') . http_build_query($query);
    }

    header('Location: ' . app_url($url), true, 303);
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
    echo '<script src="' . admin_e(app_url('/assets/js/admin-form-validate.js')) . '"></script>';
    echo '</body>';
    echo '</html>';
}
<?php

require_once __DIR__ . '/admin_helpers.php';

function nutritionist_e(string $value): string
{
    return admin_e($value);
}

function nutritionist_nav_items(): array
{
    return [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => app_url('/nutritionist/dashboard.php')],
        ['key' => 'children', 'label' => 'Children', 'href' => app_url('/nutritionist/children.php')],
        ['key' => 'measurements', 'label' => 'Measurements', 'href' => app_url('/nutritionist/measurements.php')],
        ['key' => 'who_analysis', 'label' => 'WHO Analysis', 'href' => app_url('/nutritionist/who_analysis.php')],
        ['key' => 'parents', 'label' => 'Parents', 'href' => app_url('/nutritionist/parents.php')],
        ['key' => 'appointments', 'label' => 'Appointments', 'href' => app_url('/nutritionist/appointments.php')],
        ['key' => 'reports', 'label' => 'Reports', 'href' => app_url('/nutritionist/reports.php')],
        ['key' => 'settings', 'label' => 'Settings', 'href' => app_url('/nutritionist/settings.php')],
    ];
}

function nutritionist_require_access(): array
{
    $user = current_user();

    if ($user === null) {
        deny_access('Please sign in to continue.', 401);
    }

    if (($user['type'] ?? null) !== 'staff') {
        deny_access('You do not have permission to access this page.', 403);
    }

    $role = (string)($user['role'] ?? '');

    if (!in_array($role, ['admin', 'nutritionist'], true)) {
        deny_access('You do not have permission to access this page.', 403);
    }

    if (($user['status'] ?? 'active') !== 'active') {
        deny_access('This account is inactive.', 403);
    }

    return $user;
}

function nutritionist_layout_start(string $title, string $subtitle, string $activeSection, string $actionsHtml = ''): void
{
    $currentUser = nutritionist_require_access();
    $userName = $currentUser['name'] ?? 'Nutritionist';
    $userRole = $currentUser['role'] ?? 'nutritionist';
    $flash = admin_flash_message();
    $navItems = nutritionist_nav_items();
    $logoutUrl = app_url('/api/auth/logout.php');

    echo '<!doctype html>';
    echo '<html lang="en">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . nutritionist_e($title) . ' | Sukat Kalusugan Nutritionist</title>';
    echo '<link rel="stylesheet" href="' . nutritionist_e(app_url('/assets/css/app.css')) . '">';
    echo '<link rel="stylesheet" href="' . nutritionist_e(app_url('/assets/css/admin.css')) . '">';
    echo '<link rel="stylesheet" href="' . nutritionist_e(app_url('/assets/css/nutritionist.css')) . '">';
    echo '</head>';
    echo '<body class="admin-page nutritionist-page">';
    echo '<div class="admin-shell">';
    echo '<aside class="admin-sidebar" data-admin-sidebar>';
    echo '<div class="admin-brand">';
    echo '<div class="admin-brand-mark">SK</div>';
    echo '<div>';
    echo '<div class="admin-brand-name">Sukat Kalusugan</div>';
    echo '<div class="admin-brand-sub">Nutritionist console</div>';
    echo '</div>';
    echo '</div>';
    echo '<nav class="admin-nav">';

    foreach ($navItems as $item) {
        $isActive = $item['key'] === $activeSection ? ' is-active' : '';
        echo '<a class="admin-nav-link' . $isActive . '" href="' . nutritionist_e($item['href']) . '">' . nutritionist_e($item['label']) . '</a>';
    }

    echo '</nav>';
    echo '<div class="admin-sidebar-footer">';
    echo '<div class="admin-session-card">';
    echo '<div class="admin-session-role">' . nutritionist_e(ucfirst($userRole)) . '</div>';
    echo '<div class="admin-session-name">' . nutritionist_e($userName) . '</div>';
    echo '</div>';
    echo '<a class="admin-logout" href="' . nutritionist_e($logoutUrl) . '">Sign out</a>';
    echo '</div>';
    echo '</aside>';
    echo '<div class="admin-main">';
    echo '<header class="admin-topbar">';
    echo '<button class="admin-sidebar-toggle" type="button" data-admin-sidebar-toggle aria-label="Toggle navigation">☰</button>';
    echo '<div class="admin-pagehead">';
    echo '<p class="admin-kicker">Nutritionist Panel</p>';
    echo '<h1>' . nutritionist_e($title) . '</h1>';
    echo '<p>' . nutritionist_e($subtitle) . '</p>';
    echo '</div>';
    echo '<div class="admin-topbar-actions">' . $actionsHtml . '</div>';
    echo '</header>';

    if ($flash !== null) {
        $flashClass = $flash['type'] === 'error' ? 'admin-flash is-error' : 'admin-flash';
        echo '<div class="' . $flashClass . '">' . nutritionist_e($flash['message']) . '</div>';
    }

    echo '<main class="admin-content">';
}

function nutritionist_layout_end(): void
{
    echo '</main>';
    echo '</div>';
    echo '</div>';
    echo '<script src="' . nutritionist_e(app_url('/assets/js/admin.js')) . '"></script>';
    echo '</body>';
    echo '</html>';
}

function nutritionist_scope_fragment(array $user, string $column, array &$params): string
{
    $barangay = trim((string)($user['barangay'] ?? ''));

    if (($user['role'] ?? '') === 'admin' || $barangay === '' || strcasecmp($barangay, 'all') === 0) {
        return '1=1';
    }

    $params[] = $barangay;

    return $column . ' = ?';
}

function nutritionist_status_class(?string $status): string
{
    $normalized = strtolower(trim((string)$status));

    return match ($normalized) {
        'normal', 'confirmed', 'completed', 'active' => 'is-success',
        'overweight', 'pending' => 'is-warn',
        'cancelled', 'inactive' => 'is-danger',
        default => 'is-danger',
    };
}

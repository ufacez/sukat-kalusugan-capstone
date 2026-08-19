<?php

require_once __DIR__ . '/admin_helpers.php';

function parent_e(string $value): string
{
    return admin_e($value);
}

function parent_nav_items(): array
{
    return [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => app_url('/parent/dashboard.php')],
        ['key' => 'children', 'label' => 'Children', 'href' => app_url('/parent/children.php')],
        ['key' => 'growth_history', 'label' => 'Growth History', 'href' => app_url('/parent/growth_history.php')],
        ['key' => 'appointments', 'label' => 'Appointments', 'href' => app_url('/parent/appointments.php')],
        ['key' => 'settings', 'label' => 'Settings', 'href' => app_url('/parent/settings.php')],
    ];
}

function parent_require_access(): array
{
    $user = current_user();

    if ($user === null) {
        deny_access('Please sign in to continue.', 401);
    }

    if (($user['type'] ?? null) !== 'parent') {
        deny_access('You do not have permission to access this page.', 403);
    }

    if (($user['status'] ?? 'active') !== 'active') {
        deny_access('This account is inactive.', 403);
    }

    return $user;
}

function parent_status_class(?string $status): string
{
    $normalized = strtolower(trim((string)$status));

    return match ($normalized) {
        'normal', 'confirmed', 'completed', 'active', 'n' => 'is-success',
        'overweight', 'pending', 'ow', 'muw', 'mst', 'mw/mam' => 'is-warn',
        'cancelled', 'inactive', 'suw', 'sst', 'sw/sam', 'ob' => 'is-danger',
        'tall' => 'is-muted',
        default => 'is-muted',
    };
}

function parent_layout_start(string $title, string $subtitle, string $activeSection, string $actionsHtml = ''): void
{
    $currentUser = parent_require_access();
    $flash = admin_flash_message();
    $navItems = parent_nav_items();
    $logoutUrl = app_url('/api/auth/logout.php');
    $userName = $currentUser['name'] ?? 'Parent';

    echo '<!doctype html>';
    echo '<html lang="en">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . parent_e($title) . ' | Sukat Kalusugan Parent Portal</title>';
    echo '<link rel="stylesheet" href="' . parent_e(app_url('/assets/css/app.css')) . '">';
    echo '<link rel="stylesheet" href="' . parent_e(app_url('/assets/css/admin.css')) . '">';
    echo '<link rel="stylesheet" href="' . parent_e(app_url('/assets/css/parent.css')) . '">';
    echo '</head>';
    echo '<body class="admin-page parent-page">';
    echo '<div class="admin-shell">';
    echo '<aside class="admin-sidebar" data-admin-sidebar>';
    echo '<div class="admin-brand">';
    echo '<div class="admin-brand-mark">SK</div>';
    echo '<div>';
    echo '<div class="admin-brand-name">Sukat Kalusugan</div>';
    echo '<div class="admin-brand-sub">Parent portal</div>';
    echo '</div>';
    echo '</div>';
    echo '<nav class="admin-nav">';

    foreach ($navItems as $item) {
        $isActive = $item['key'] === $activeSection ? ' is-active' : '';
        echo '<a class="admin-nav-link' . $isActive . '" href="' . parent_e($item['href']) . '">' . parent_e($item['label']) . '</a>';
    }

    echo '</nav>';
    echo '<div class="admin-sidebar-footer">';
    echo '<div class="admin-session-card">';
    echo '<div class="admin-session-role">Parent</div>';
    echo '<div class="admin-session-name">' . parent_e($userName) . '</div>';
    echo '</div>';
    echo '<a class="admin-logout" href="' . parent_e($logoutUrl) . '">Sign out</a>';
    echo '</div>';
    echo '</aside>';
    echo '<div class="admin-main">';
    echo '<header class="admin-topbar">';
    echo '<button class="admin-sidebar-toggle" type="button" data-admin-sidebar-toggle aria-label="Toggle navigation">☰</button>';
    echo '<div class="admin-pagehead">';
    echo '<p class="admin-kicker">Parent Portal</p>';
    echo '<h1>' . parent_e($title) . '</h1>';
    echo '<p>' . parent_e($subtitle) . '</p>';
    echo '</div>';
    echo '<div class="admin-topbar-actions">' . $actionsHtml . '</div>';
    echo '</header>';

    if ($flash !== null) {
        $flashClass = $flash['type'] === 'error' ? 'admin-flash is-error' : 'admin-flash';
        echo '<div class="' . $flashClass . '">' . parent_e($flash['message']) . '</div>';
    }

    echo '<main class="admin-content">';
}

function parent_layout_end(): void
{
    echo '</main>';
    echo '</div>';
    echo '</div>';
    echo '<script src="' . parent_e(app_url('/assets/js/admin.js')) . '"></script>';
    echo '</body>';
    echo '</html>';
}

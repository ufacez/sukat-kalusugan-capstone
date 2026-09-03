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

function parent_grouped_nav_items(): array
{
    return [
        [
            'label' => 'Overview',
            'items' => [
                ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => app_url('/parent/dashboard.php'), 'icon' => 'dashboard'],
            ],
        ],
        [
            'label' => 'Health Tracking',
            'items' => [
                ['key' => 'children', 'label' => 'Children', 'href' => app_url('/parent/children.php'), 'icon' => 'children'],
                ['key' => 'growth_history', 'label' => 'Growth History', 'href' => app_url('/parent/growth_history.php'), 'icon' => 'linechart'],
                ['key' => 'appointments', 'label' => 'Appointments', 'href' => app_url('/parent/appointments.php'), 'icon' => 'calendar'],
            ],
        ],
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
        'normal', 'confirmed', 'completed', 'active', 'n', 't', 'tall' => 'is-success',
        'moderately underweight', 'moderately stunted', 'moderately wasted', 'muw', 'mst', 'mw' => 'is-warn',
        'overweight', 'obese', 'ow', 'ob' => 'is-orange',
        'suw', 'sst', 'sw', 'severely underweight', 'severely stunted', 'severely wasted' => 'is-danger',
        'pending' => 'is-muted',
        'cancelled', 'inactive' => 'is-danger',
        default => 'is-muted',
    };
}

function parent_layout_start(string $title, string $subtitle, string $activeSection, string $actionsHtml = ''): void
{
    $currentUser = parent_require_access();
    $flash = admin_flash_message();
    $logoutUrl = app_url('/api/auth/logout.php');
    $userName = $currentUser['name'] ?? 'Parent';

    echo '<!doctype html>';
    echo '<html lang="en">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . parent_e($title) . ' | Sukat Kalusugan Parent Portal</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="' . parent_e(app_url('/assets/css/app.css')) . '">';
    echo '<link rel="stylesheet" href="' . parent_e(app_url('/assets/css/admin.css')) . '">';
    echo '<link rel="stylesheet" href="' . parent_e(app_url('/assets/css/parent.css')) . '">';
    echo '<link rel="icon" type="image/svg+xml" href="' . parent_e(app_url('/assets/img/logo/logo_forlight.svg')) . '">';

    echo '<link rel="stylesheet" href="' . parent_e(app_url('/assets/css/chatbot.css')) . '">';
    echo '<script>';
    echo '(function(){';
    echo 'var t=localStorage.getItem("theme");';
    echo 'if(t==="dark"||(!t&&window.matchMedia("(prefers-color-scheme:dark)").matches)){';
    echo 'document.documentElement.setAttribute("data-theme","dark");';
    echo '}';
    echo '})();';
    echo '</script>';
    echo '</head>';
    echo '<body class="admin-page parent-page">';
    echo '<div class="admin-shell">';
    echo '<aside class="admin-sidebar" data-admin-sidebar>';

    echo '<div class="admin-brand">';
    echo '<div class="admin-brand-mark">';
    echo '<img src="' . parent_e(app_url('/assets/img/logo/logo_forlight.svg')) . '" alt="Sukat Kalusugan" class="admin-brand-img logo-light">';
    echo '<img src="' . parent_e(app_url('/assets/img/logo/logo_fordark.svg')) . '" alt="Sukat Kalusugan" class="admin-brand-img logo-dark">';
    echo '</div>';
    echo '<div class="admin-brand-text">';
    echo '<div class="admin-brand-name">Sukat Kalusugan</div>';
    echo '<div class="admin-brand-sub">Parent portal</div>';
    echo '</div>';
    echo '</div>';
    echo '<button type="button" class="admin-sidebar-collapse" data-admin-sidebar-collapse title="Toggle sidebar">';
    echo '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>';
    echo '</button>';

    echo '<nav class="admin-nav">';
    foreach (parent_grouped_nav_items() as $groupIndex => $group) {
        echo '<div class="admin-nav-group">';
        echo '<div class="admin-nav-section"><span class="admin-nav-label">' . parent_e($group['label']) . '</span></div>';
        foreach ($group['items'] as $item) {
            $isActive = $item['key'] === $activeSection ? ' is-active' : '';
            $iconHtml = admin_sidebar_icon($item['icon']);
            echo '<a class="admin-nav-link' . $isActive . '" href="' . parent_e($item['href']) . '">';
            if ($iconHtml !== '') {
                echo $iconHtml;
            }
            echo '<span>' . parent_e($item['label']) . '</span>';
            echo '</a>';
        }
        echo '</div>';
    }
    echo '</nav>';

    echo '<div class="admin-sidebar-footer">';
    echo '<div class="admin-session-card">';
    echo '<div class="admin-session-role">Parent</div>';
    echo '<div class="admin-session-name">' . parent_e($userName) . '</div>';
    echo '</div>';
    echo '<a class="admin-logout" href="' . parent_e($logoutUrl) . '">' . admin_action_icon('logout') . ' Sign out</a>';
    echo '</div>';

    echo '</aside>';
    echo '<div class="admin-sidebar-overlay" data-admin-sidebar-overlay></div>';
    echo '<div class="admin-main">';
    echo '<header class="admin-topbar">';
    echo '<div class="admin-topbar-left">';
    echo '<button class="admin-sidebar-toggle" type="button" data-admin-sidebar-toggle aria-label="Toggle navigation" aria-expanded="false">';
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>';
    echo '</button>';
    echo '<div class="admin-pagehead">';
    echo '<p class="admin-kicker">Parent Portal</p>';
    echo '<h1>' . parent_e($title) . '</h1>';
    echo '<p>' . parent_e($subtitle) . '</p>';
    echo '</div>';
    echo '</div>';
    echo '<div class="admin-topbar-right">';
    echo '<div class="admin-topbar-actions">' . $actionsHtml . '</div>';
    echo admin_topbar_theme_toggle();
    echo '<a href="' . parent_e(app_url('/parent/settings.php')) . '" class="admin-topbar-settings" title="Settings">' . admin_action_icon('settings') . '</a>';
    echo '<div class="admin-topbar-profile">';
    echo '<span class="admin-avatar" style="background:' . admin_avatar_color($userName) . '">' . admin_initials($userName) . '</span>';
    echo '</div>';
    echo '</div>';
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

    $chatbotConfig = ['apiBase' => app_url('/api/chatbot'), 'role' => 'parent'];
    echo '<script>window.CHATBOT_CONFIG = ' . json_encode($chatbotConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';</script>';
    echo '<script src="' . parent_e(app_url('/assets/js/chatbot_widget.js')) . '"></script>';

    echo '</body>';
    echo '</html>';
}

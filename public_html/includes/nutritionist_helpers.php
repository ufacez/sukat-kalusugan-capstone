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
        ['key' => 'who_reference', 'label' => 'WHO Reference', 'href' => app_url('/nutritionist/who_reference.php')],
        ['key' => 'risk_map', 'label' => 'Risk Map', 'href' => app_url('/nutritionist/risk_map.php')],
        ['key' => 'parents', 'label' => 'Parents', 'href' => app_url('/nutritionist/parents.php')],
        ['key' => 'appointments', 'label' => 'Appointments', 'href' => app_url('/nutritionist/appointments.php')],
        ['key' => 'eopt_reports', 'label' => 'EOPT Reports', 'href' => app_url('/nutritionist/eopt_reports.php')],
        ['key' => 'settings', 'label' => 'Settings', 'href' => app_url('/nutritionist/settings.php')],
    ];
}

function nutritionist_grouped_nav_items(): array
{
    return [
        [
            'label' => 'Overview',
            'items' => [
                ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => app_url('/nutritionist/dashboard.php'), 'icon' => 'dashboard'],
            ],
        ],
        [
            'label' => 'Clinical',
            'items' => [
                ['key' => 'children', 'label' => 'Children', 'href' => app_url('/nutritionist/children.php'), 'icon' => 'children'],
                ['key' => 'measurements', 'label' => 'Measurements', 'href' => app_url('/nutritionist/measurements.php'), 'icon' => 'clipboard'],
                ['key' => 'who_analysis', 'label' => 'WHO Analysis', 'href' => app_url('/nutritionist/who_analysis.php'), 'icon' => 'chart'],
                ['key' => 'who_reference', 'label' => 'WHO Reference', 'href' => app_url('/nutritionist/who_reference.php'), 'icon' => 'book'],
            ],
        ],
        [
            'label' => 'Community',
            'items' => [
                ['key' => 'risk_map', 'label' => 'Risk Map', 'href' => app_url('/nutritionist/risk_map.php'), 'icon' => 'map'],
                ['key' => 'parents', 'label' => 'Parents', 'href' => app_url('/nutritionist/parents.php'), 'icon' => 'users'],
            ],
        ],
        [
            'label' => 'Operations',
            'items' => [
                ['key' => 'appointments', 'label' => 'Appointments', 'href' => app_url('/nutritionist/appointments.php'), 'icon' => 'calendar'],
                ['key' => 'eopt_reports', 'label' => 'EOPT Reports', 'href' => app_url('/nutritionist/eopt_reports.php'), 'icon' => 'document'],
            ],
        ],
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
    echo '<link rel="stylesheet" href="' . nutritionist_e(app_url('/assets/css/chatbot.css')) . '">';
    echo '<script>';
    echo '(function(){';
    echo 'var t=localStorage.getItem("theme");';
    echo 'if(t==="dark"||(!t&&window.matchMedia("(prefers-color-scheme:dark)").matches)){';
    echo 'document.documentElement.setAttribute("data-theme","dark");';
    echo '}';
    echo '})();';
    echo '</script>';
    echo '</head>';
    echo '<body class="admin-page nutritionist-page">';
    echo '<div class="admin-shell">';
    echo '<aside class="admin-sidebar" data-admin-sidebar>';

    echo '<div class="admin-brand">';
    echo '<div class="admin-brand-mark"><img src="' . nutritionist_e(app_url('/assets/images/logo.jpg')) . '" alt="Sukat Kalusugan" class="admin-brand-img"></div>';
    echo '<div class="admin-brand-text">';
    echo '<div class="admin-brand-name">Sukat Kalusugan</div>';
    echo '<div class="admin-brand-sub">Nutritionist console</div>';
    echo '</div>';
    echo '</div>';
    echo '<button type="button" class="admin-sidebar-collapse" data-admin-sidebar-collapse title="Toggle sidebar">';
    echo '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>';
    echo '</button>';

    echo '<nav class="admin-nav">';
    foreach (nutritionist_grouped_nav_items() as $groupIndex => $group) {
        echo '<div class="admin-nav-group">';
        echo '<div class="admin-nav-section"><span class="admin-nav-label">' . nutritionist_e($group['label']) . '</span></div>';
        foreach ($group['items'] as $item) {
            $isActive = $item['key'] === $activeSection ? ' is-active' : '';
            $iconHtml = admin_sidebar_icon($item['icon']);
            echo '<a class="admin-nav-link' . $isActive . '" href="' . nutritionist_e($item['href']) . '">';
            if ($iconHtml !== '') {
                echo $iconHtml;
            }
            echo '<span>' . nutritionist_e($item['label']) . '</span>';
            echo '</a>';
        }
        echo '</div>';
    }
    echo '</nav>';

    echo '<div class="admin-sidebar-footer">';
    echo '<div class="admin-nav-group sidebar-theme-toggle">';
    echo '<button type="button" data-theme-toggle>';
    echo '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z"/></svg>';
    echo '<span class="theme-toggle-label">Dark Mode</span>';
    echo '</button>';
    echo '</div>';
    echo '<div class="admin-session-card">';
    echo '<div class="admin-session-role">' . nutritionist_e(ucfirst($userRole)) . '</div>';
    echo '<div class="admin-session-name">' . nutritionist_e($userName) . '</div>';
    echo '</div>';
    echo '<a class="admin-logout" href="' . nutritionist_e($logoutUrl) . '">' . admin_action_icon('logout') . ' Sign out</a>';
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
    echo '<p class="admin-kicker">Nutritionist Panel</p>';
    echo '<h1>' . nutritionist_e($title) . '</h1>';
    echo '<p>' . nutritionist_e($subtitle) . '</p>';
    echo '</div>';
    echo '</div>';
    echo '<div class="admin-topbar-right">';
    echo '<div class="admin-topbar-actions">' . $actionsHtml . '</div>';
    echo '<a href="' . nutritionist_e(app_url('/nutritionist/settings.php')) . '" class="admin-topbar-settings" title="Settings">' . admin_action_icon('settings') . '</a>';
    echo '<div class="admin-topbar-profile">';
    echo '<span class="admin-avatar" style="background:' . admin_avatar_color($userName) . '">' . admin_initials($userName) . '</span>';
    echo '</div>';
    echo '</div>';
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
    echo '<script src="' . nutritionist_e(app_url('/assets/js/admin-form-validate.js')) . '"></script>';

    $chatbotConfig = ['apiBase' => app_url('/api/chatbot'), 'role' => 'staff'];
    echo '<script>window.CHATBOT_CONFIG = ' . json_encode($chatbotConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';</script>';
    echo '<script src="' . nutritionist_e(app_url('/assets/js/chatbot_widget.js')) . '"></script>';

    echo '</body>';
    echo '</html>';
}

function nutritionist_scope_fragment(array $user, string $column, array &$params): string
{
    $barangayId = $user['barangay_id'] ?? null;

    if (($user['role'] ?? '') === 'admin' || $barangayId === null || $barangayId === '') {
        return '1=1';
    }

    $params[] = (int)$barangayId;

    return $column . ' = ?';
}

function nutritionist_status_class(?string $status): string
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

function nutritionist_calendar_color(string $entryType): string
{
    return match ($entryType) {
        'appointment' => '#E03131',
        'meeting' => '#4a9fd5',
        'oplan_timbang' => 'var(--admin-primary)',
        default => '#94a3b8',
    };
}

function nutritionist_calendar_label(string $entryType): string
{
    return match ($entryType) {
        'appointment' => 'Appointment',
        'meeting' => 'Meeting',
        'oplan_timbang' => 'Oplan Timbang',
        default => ucfirst($entryType),
    };
}
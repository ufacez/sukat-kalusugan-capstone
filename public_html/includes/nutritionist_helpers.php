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
        ['key' => 'risk_map', 'label' => 'Barangay Risk Map', 'href' => app_url('/nutritionist/risk_map.php')],
        ['key' => 'parents', 'label' => 'Parents', 'href' => app_url('/nutritionist/parents.php')],
        ['key' => 'appointments', 'label' => 'Appointments', 'href' => app_url('/nutritionist/appointments.php')],
        ['key' => 'eopt_reports', 'label' => 'EOPT Reports', 'href' => app_url('/nutritionist/eopt_reports.php')],
        ['key' => 'ai_assistant', 'label' => 'AI Assistant', 'href' => app_url('/nutritionist/ai_assistant.php')],
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
                ['key' => 'risk_map', 'label' => 'Barangay Risk Map', 'href' => app_url('/nutritionist/risk_map.php'), 'icon' => 'map'],
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
        [
            'label' => 'Tools',
            'items' => [
                ['key' => 'ai_assistant', 'label' => 'AI Assistant', 'href' => app_url('/nutritionist/ai_assistant.php'), 'icon' => 'robot'],
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

function nutritionist_require_write(string $permissionCode = ''): array
{
    $user = nutritionist_require_access();

    if (($user['role'] ?? '') === 'admin') {
        return $user;
    }

    $accessLevel = $user['access_level'] ?? 'full';

    if ($accessLevel === 'readonly') {
        deny_access('You do not have permission to modify data. Your access level is Read Only.', 403);
    }

    if ($accessLevel === 'standard' && $permissionCode !== '' && has_permission($permissionCode) === false) {
        deny_access('You do not have permission to perform this action.', 403);
    }

    return $user;
}

function nutritionist_can_write(string $permissionCode = ''): bool
{
    $user = current_user();

    if ($user === null) {
        return false;
    }

    if (($user['role'] ?? '') === 'admin') {
        return true;
    }

    $accessLevel = $user['access_level'] ?? 'full';

    if ($accessLevel === 'readonly') {
        return false;
    }

    if ($accessLevel === 'standard' && $permissionCode !== '' && has_permission($permissionCode) === false) {
        return false;
    }

    return true;
}

function nutritionist_build_breadcrumb(string $activeSection, array $groupedNav, ?string $breadcrumbExtra = null): array
{
    if ($activeSection === 'settings') {
        return ['group' => '', 'page' => 'Settings'];
    }

    foreach ($groupedNav as $group) {
        foreach ($group['items'] as $item) {
            if ($item['key'] === $activeSection) {
                if ($breadcrumbExtra !== null && $breadcrumbExtra !== '') {
                    return [
                        'group' => $group['label'],
                        'page' => $item['label'],
                        'extra' => $breadcrumbExtra,
                    ];
                }
                return ['group' => $group['label'], 'page' => $item['label']];
            }
        }
    }
    if ($breadcrumbExtra !== null && $breadcrumbExtra !== '') {
        return ['group' => '', 'page' => $activeSection, 'extra' => $breadcrumbExtra];
    }
    return ['group' => '', 'page' => $activeSection];
}

function nutritionist_layout_start(string $title, string $subtitle, string $activeSection, string $actionsHtml = '', ?string $breadcrumbExtra = null): void
{
    $currentUser = nutritionist_require_access();
    $userName = $currentUser['name'] ?? 'Nutritionist';
    $userRole = $currentUser['role'] ?? 'nutritionist';
    $flash = admin_flash_message();
    $logoutUrl = app_url('/api/auth/logout.php');
    $breadcrumb = nutritionist_build_breadcrumb($activeSection, nutritionist_grouped_nav_items(), $breadcrumbExtra);

    echo '<!doctype html>';
    echo '<html lang="en">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . nutritionist_e($title) . ' | Sukat Kalusugan Nutritionist</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="' . nutritionist_e(app_url('/assets/css/app.css')) . '">';
    $adminCssVersion = (int) @filemtime(__DIR__ . '/../assets/css/admin.css');
    echo '<link rel="stylesheet" href="' . nutritionist_e(app_url('/assets/css/admin.css?v=' . $adminCssVersion)) . '">';
    $nutritionistCssVersion = (int) @filemtime(__DIR__ . '/../assets/css/nutritionist.css');
    echo '<link rel="stylesheet" href="' . nutritionist_e(app_url('/assets/css/nutritionist.css?v=' . $nutritionistCssVersion)) . '">';
    echo '<link rel="icon" type="image/svg+xml" href="' . nutritionist_e(app_url('/assets/img/logo/logo_forlight.svg')) . '">';

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
    echo '<div class="admin-brand-mark">';
    echo '<img src="' . nutritionist_e(app_url('/assets/img/logo/logo_forlight.svg')) . '" alt="Sukat Kalusugan" class="admin-brand-img logo-light">';
    echo '<img src="' . nutritionist_e(app_url('/assets/img/logo/logo_fordark.svg')) . '" alt="Sukat Kalusugan" class="admin-brand-img logo-dark">';
    echo '</div>';
    echo '<div class="admin-brand-text">';
    echo '<div class="admin-brand-name">Sukat Kalusugan</div>';
    echo '<div class="admin-brand-sub">Nutritionist console</div>';
    echo '</div>';
    echo '</div>';

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
    echo '<nav class="admin-breadcrumb" data-admin-breadcrumb>';
    $hasGroup = ($breadcrumb['group'] ?? '') !== '';
    $hasExtra = isset($breadcrumb['extra']) && $breadcrumb['extra'] !== '';
    if ($hasGroup) {
        echo '<span class="admin-breadcrumb-group">' . nutritionist_e($breadcrumb['group']) . '</span>';
        echo '<span class="admin-breadcrumb-sep" aria-hidden="true">&#8250;</span>';
    }
    if ($hasExtra) {
        echo '<span class="admin-breadcrumb-page">' . nutritionist_e($breadcrumb['page']) . '</span>';
        echo '<span class="admin-breadcrumb-sep" aria-hidden="true">&#8250;</span>';
        echo '<span class="admin-breadcrumb-page is-active">' . nutritionist_e($breadcrumb['extra']) . '</span>';
    } else {
        echo '<span class="admin-breadcrumb-page is-active">' . nutritionist_e($breadcrumb['page']) . '</span>';
    }
    echo '</nav>';
    echo '</div>';
    echo '<div class="admin-topbar-right">';
    echo admin_topbar_theme_toggle();
    echo '<a href="' . nutritionist_e(app_url('/nutritionist/settings.php')) . '" class="admin-topbar-settings" title="Settings">' . admin_action_icon('settings') . '</a>';
    echo '<div class="admin-topbar-profile">';
    echo '<span class="admin-avatar" style="background:' . admin_avatar_color($userName) . '">' . admin_initials($userName) . '</span>';
    echo '<div class="admin-topbar-profile-text">';
    echo '<span class="admin-topbar-name">' . nutritionist_e($userName) . '</span>';
    echo '<span class="admin-topbar-role">' . nutritionist_e(ucfirst($userRole)) . '</span>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</header>';

    if ($flash !== null) {
        $flashClass = $flash['type'] === 'error' ? 'admin-flash is-error' : 'admin-flash';
        echo '<div class="' . $flashClass . '">' . nutritionist_e($flash['message']) . '</div>';
    }

    echo '<main class="admin-content">';
    echo '<div class="admin-pageheader">';
    echo '<div class="admin-pageheader-left">';
    echo '<h1>' . nutritionist_e($title) . '</h1>';
    echo '<p>' . nutritionist_e($subtitle) . '</p>';
    echo '</div>';
    if ($actionsHtml !== '') {
        echo '<div class="admin-pageheader-actions">' . $actionsHtml . '</div>';
    }
    echo '</div>';
}

function nutritionist_layout_end(): void
{
    echo '</main>';
    echo '</div>';
    echo '</div>';
    $adminJsVersion = (int) @filemtime(__DIR__ . '/../assets/js/admin.js');
    echo '<script src="' . nutritionist_e(app_url('/assets/js/admin.js?v=' . $adminJsVersion)) . '"></script>';
    $calendarJsVersion = (int) @filemtime(__DIR__ . '/../assets/js/calendar.js');
    echo '<script src="' . nutritionist_e(app_url('/assets/js/calendar.js?v=' . $calendarJsVersion)) . '"></script>';
    echo '<script src="' . nutritionist_e(app_url('/assets/js/admin-form-validate.js')) . '"></script>';

    echo '</body>';
    echo '</html>';
}

function nutritionist_scope_fragment(array $user, string $column, array &$params): string
{
    $barangayId = $user['barangay_id'] ?? null;

    if (($user['role'] ?? '') === 'admin') {
        return '1=1';
    }

    if ($barangayId === null || $barangayId === '') {
        return '0=1';
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
        // WFA overflow: WAZ > +2 is a redirect, not a real WFA label.
        'refer to wfl/h', 'refer to wflh', 'refer', 'ref' => 'is-info',
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

/**
 * Canonical event-type color tokens used by both the dashboard and the
 * appointments-page calendars. Returns an array keyed by entryType with
 * `color`, `label`, and a short CSS-friendly hex.
 */
function nutritionist_calendar_legend(): array
{
    return [
        ['type' => 'appointment',    'label' => 'Appointment',    'color' => '#E03131'],
        ['type' => 'meeting',        'label' => 'Meeting',        'color' => '#4a9fd5'],
        ['type' => 'oplan_timbang',  'label' => 'Oplan Timbang',  'color' => '#16a34a'],
    ];
}

/**
 * Renders the shared nutritionist calendar grid. Days are interactive
 * buttons that carry the day's full ISO date plus a JSON-serialized list
 * of events so the JS layer can populate the detail panel on click.
 *
 * Each $entriesByDay[$day] entry must contain:
 *   - type  (string, one of: appointment|meeting|oplan_timbang)
 *   - title (string)
 *   - time  (?string, "g:i A" formatted, optional)
 *   - id    (?int, optional — appointment id for cross-linking)
 *   - location (?string)
 *   - status (?string, "overdue"|"cancelled"|null)
 *   - color (?string, override; otherwise looked up by type)
 *
 * @param array<int, array<int, array<string, mixed>>> $entriesByDay
 */
function nutritionist_render_calendar_grid(
    DateTimeImmutable $monthAnchor,
    array $entriesByDay,
    DateTimeImmutable $today
): string {
    $firstWeekday = (int)$monthAnchor->format('w');
    $daysInMonth = (int)$monthAnchor->format('t');
    $monthIso = $monthAnchor->format('Y-m');

    $html = '<div class="sk-cal-grid">';
    foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $wk) {
        $html .= '<span class="sk-cal-weekday">' . nutritionist_e($wk) . '</span>';
    }

    for ($i = 0; $i < $firstWeekday; $i++) {
        $html .= '<span class="sk-cal-day is-empty" aria-hidden="true"></span>';
    }

    for ($d = 1; $d <= $daysInMonth; $d++) {
        $dayEntries = $entriesByDay[$d] ?? [];
        $isoDate = $monthAnchor->setDate(
            (int)$monthAnchor->format('Y'),
            (int)$monthAnchor->format('n'),
            $d
        )->format('Y-m-d');

        $isToday = $today->format('Y-m-d') === $isoDate;
        $hasEntries = $dayEntries !== [];

        $hasOverdue = false;
        foreach ($dayEntries as $entry) {
            if (($entry['status'] ?? null) === 'overdue') {
                $hasOverdue = true;
                break;
            }
        }

        $classes = ['sk-cal-day'];
        if ($isToday) {
            $classes[] = 'is-today';
        }
        if ($hasEntries) {
            $classes[] = 'has-events';
        }
        if ($hasOverdue) {
            $classes[] = 'has-overdue';
        }

        $dotsHtml = '';
        if ($hasEntries) {
            $dotEntries = [];
            $seenDotTypes = [];
            foreach ($dayEntries as $entry) {
                $type = (string)($entry['type'] ?? 'appointment');
                if (isset($seenDotTypes[$type])) {
                    continue;
                }
                $seenDotTypes[$type] = true;
                $dotEntries[] = $entry;
                if (count($dotEntries) === 3) {
                    break;
                }
            }
            $dotsHtml .= '<span class="sk-cal-day-dots">';
            foreach ($dotEntries as $entry) {
                $type = (string)($entry['type'] ?? 'appointment');
                $color = (string)($entry['color'] ?? nutritionist_calendar_color($type));
                $dotsHtml .= '<span class="sk-cal-day-dot" style="background:' . nutritionist_e($color) . ';"></span>';
            }
            if (count($dayEntries) > count($dotEntries)) {
                $dotsHtml .= '<span class="sk-cal-day-more">+' . (count($dayEntries) - count($dotEntries)) . '</span>';
            }
            $dotsHtml .= '</span>';
        }

        $payload = [];
        foreach ($dayEntries as $entry) {
            $type = (string)($entry['type'] ?? 'appointment');
            $payload[] = [
                'type' => $type,
                'title' => (string)($entry['title'] ?? ''),
                'time' => isset($entry['time']) ? (string)$entry['time'] : null,
                'id' => isset($entry['id']) ? (int)$entry['id'] : null,
                'location' => isset($entry['location']) ? (string)$entry['location'] : null,
                'status' => isset($entry['status']) ? (string)$entry['status'] : null,
                'color' => (string)($entry['color'] ?? nutritionist_calendar_color($type)),
                'label' => nutritionist_calendar_label($type),
            ];
        }

        $payloadJson = json_encode(
            $payload,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $html .= '<button type="button"'
            . ' class="' . nutritionist_e(implode(' ', $classes)) . '"'
            . ' data-calendar-day="' . nutritionist_e($isoDate) . '"'
            . ' data-calendar-entries=\'' . nutritionist_e((string)$payloadJson) . '\''
            . ' aria-label="' . nutritionist_e($monthAnchor->format('F') . ' ' . $d . ', ' . $monthAnchor->format('Y')) . '">'
            . '<span class="sk-cal-day-num">' . $d . '</span>'
            . $dotsHtml
            . '</button>';
    }

    $rendered = $firstWeekday + $daysInMonth;
    while ($rendered % 7 !== 0) {
        $html .= '<span class="sk-cal-day is-empty" aria-hidden="true"></span>';
        $rendered++;
    }
    $html .= '</div>';

    return $html;
}

// ----------------------------------------------------------------------
// Wave-chart helpers (used by the dashboard's stacked-area charts)
//
// The chart renders a smooth stacked-area visualization in pure SVG. The
// data is collapsed to a small number of meaningful bands (Normal / Needs
// Monitoring / Urgent / Overweight-Obese) so the chart reads cleanly
// instead of overlapping 9 thin lines. The path strings are produced by
// nutritionist_smooth_path(), which converts a sequence of (x,y) points
// into a Catmull-Rom-spline cubic-Bezier path that curves smoothly
// between them (matching the rounded look in the reference mockups).
// ----------------------------------------------------------------------

/**
 * Map a single fine-grained WHO/eOPT status into one of the 4 collapsed
 * bands used by the dashboard's "Nutritional Status Trend" chart.
 * Unknown values fall through to "needs_monitoring" so they're still
 * surfaced.
 */
function nutritionist_collapse_status_band(?string $status): string
{
    $normalized = strtolower(trim((string)$status));

    return match (true) {
        $normalized === '', $normalized === 'normal' => 'normal',
        $normalized === 'severely underweight',
        $normalized === 'severely stunted',
        $normalized === 'severely wasted',
        $normalized === 'suw', $normalized === 'sst', $normalized === 'sw' => 'urgent',
        $normalized === 'overweight', $normalized === 'obese',
        $normalized === 'ow', $normalized === 'ob' => 'overweight',
        default => 'needs_monitoring', // MW, MUW, MSt, etc.
    };
}

/**
 * Map a single fine-grained HFA (height-for-age) code into one of the 4
 * collapsed bands used by the dashboard's "Growth Trend — Height-for-Age"
 * chart. Same four-band shape as the status chart for visual consistency.
 */
function nutritionist_collapse_hfa_band(?string $hfa): string
{
    $normalized = strtolower(trim((string)$hfa));

    return match (true) {
        $normalized === '', $normalized === 'normal', $normalized === 'n' => 'normal',
        $normalized === 'sst', $normalized === 'severely stunted' => 'urgent',
        $normalized === 't', $normalized === 'tall' => 'tall',
        default => 'stunted', // St, MSt, etc.
    };
}

/**
 * Canonical band definitions used by both charts. Centralized here so the
 * label, color, and rendering order stay in sync across the dashboard.
 *
 * The order is intentional: Normal at the bottom, severity layers stacked
 * on top — this gives the natural "the chart fills up as more children
 * are flagged" reading.
 */
function nutritionist_chart_bands(string $chart = 'status'): array
{
    if ($chart === 'hfa') {
        return [
            'normal'  => ['label' => 'Normal',            'color' => '#16a34a'],
            'stunted' => ['label' => 'Moderate Stunting', 'color' => '#eab308'],
            'urgent'  => ['label' => 'Severe Stunting',   'color' => '#dc2626'],
            'tall'    => ['label' => 'Tall',              'color' => '#2563eb'],
        ];
    }

    return [
        'normal'            => ['label' => 'Normal',             'color' => '#16a34a'],
        'needs_monitoring'  => ['label' => 'Moderate Case',      'color' => '#eab308'],
        'urgent'            => ['label' => 'Severe Case',        'color' => '#dc2626'],
        'overweight'        => ['label' => 'Overweight / Obese', 'color' => '#f97316'],
    ];
}

/**
 * Convert an array of (x, y) points into a smooth cubic-Bezier SVG path
 * using the Catmull-Rom → Bezier conversion. The first and last points
 * are mirrored so the curve has natural end-slopes (instead of kinking
 * to a stop at the chart edges).
 *
 * Returns just the path's "M ... C ..." segment (no leading L) so callers
 * can use it for both the upper and lower edge of a stacked area.
 */
function nutritionist_smooth_path(array $points, float $tension = 0.2): string
{
    $count = count($points);
    if ($count === 0) {
        return '';
    }
    if ($count === 1) {
        $p = $points[0];
        return sprintf('M%.2f,%.2f', $p[0], $p[1]);
    }

    $cmds = [sprintf('M%.2f,%.2f', $points[0][0], $points[0][1])];

    for ($i = 0; $i < $count - 1; $i++) {
        $p0 = $points[max($i - 1, 0)];
        $p1 = $points[$i];
        $p2 = $points[$i + 1];
        $p3 = $points[min($i + 2, $count - 1)];

        $c1x = $p1[0] + ($p2[0] - $p0[0]) * $tension;
        $c1y = $p1[1] + ($p2[1] - $p0[1]) * $tension;
        $c2x = $p2[0] - ($p3[0] - $p1[0]) * $tension;
        $c2y = $p2[1] - ($p3[1] - $p1[1]) * $tension;

        $cmds[] = sprintf(
            'C%.2f,%.2f %.2f,%.2f %.2f,%.2f',
            $c1x, $c1y, $c2x, $c2y, $p2[0], $p2[1]
        );
    }

    return implode(' ', $cmds);
}

/**
 * Build a closed SVG area path between two smooth lines: the upper line
 * (top of the band) and the lower line (bottom of the band, walked in
 * reverse). The result is a single "M ... C ... L ... C ... Z" path that
 * fills correctly as a stacked-area band.
 */
function nutritionist_area_path(array $upperPoints, array $lowerPoints): string
{
    $upper = nutritionist_smooth_path($upperPoints);
    if ($upper === '' || count($lowerPoints) === 0) {
        return '';
    }

    $lowerRev = array_reverse($lowerPoints);
    $cmds = [$upper, sprintf('L%.2f,%.2f', $lowerRev[0][0], $lowerRev[0][1])];
    for ($i = 1; $i < count($lowerRev); $i++) {
        $cmds[] = sprintf('L%.2f,%.2f', $lowerRev[$i][0], $lowerRev[$i][1]);
    }
    $cmds[] = 'Z';

    return implode(' ', $cmds);
}

/**
 * Convert a hex color (#rrggbb) into an rgba() string at the requested
 * alpha. Used by the dashboard's wave chart so each band gets a soft
 * fill that matches its line color (matching the audit_logs chart style).
 */
function nutritionist_chart_fill_rgba(string $hex, float $alpha): string
{
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
        return 'rgba(99,102,241,' . $alpha . ')';
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    return 'rgba(' . $r . ',' . $g . ',' . $b . ',' . $alpha . ')';
}
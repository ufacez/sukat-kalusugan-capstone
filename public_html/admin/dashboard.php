<?php

require_once __DIR__ . '/../includes/admin_helpers.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/firebase_sync.php';

start_secure_session();
require_permission('dashboard.view');

/*
|--------------------------------------------------------------------------
| Summary Statistics
|--------------------------------------------------------------------------
*/

// Total staff users (admins + nutritionists)
$totalUsers = admin_scalar('SELECT COUNT(*) FROM users');

$adminCount = admin_scalar(
    "SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE r.name = 'admin'"
);

$nutritionistCount = admin_scalar(
    "SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE r.name = 'nutritionist'"
);

// Kiosk devices
$devicesTotal = admin_scalar('SELECT COUNT(*) FROM devices');

$devices = admin_fetch_all(
    'SELECT d.id, d.device_code, d.location, d.status, d.barangay_id, bg.name AS barangay,
            d.last_seen_at, d.last_calibration_at, d.hx711_calibration_factor, d.mounting_height_cm,
            d.calibration_offset_height, d.calibration_offset_weight, d.updated_at,
            TIMESTAMPDIFF(SECOND, d.last_seen_at, NOW()) AS seconds_since_last_seen
     FROM devices d
     LEFT JOIN barangays bg ON bg.id = d.barangay_id
     ORDER BY d.device_code ASC'
);

$devicesOnlineCount = 0;
foreach ($devices as &$device) {
    $device = api_sync_stale_device_status($device);
    if (api_device_is_online($device)) {
        $devicesOnlineCount++;
    }
}
unset($device);

// Security events: danger + warning level audit logs
$securityEvents = admin_scalar(
    "SELECT COUNT(*) FROM audit_logs WHERE level IN ('danger', 'warning')"
);

$dangerEvents = admin_scalar(
    "SELECT COUNT(*) FROM audit_logs WHERE level = 'danger'"
);

$warningEvents = admin_scalar(
    "SELECT COUNT(*) FROM audit_logs WHERE level = 'warning'"
);

// Failed login attempts
$failedLogins = admin_scalar(
    "SELECT COUNT(*) FROM login_attempts WHERE success = 0"
);

// Sensor status computation
$sensorHealthy = 0;
$sensorNeedsRepair = 0;
$sensorOffline = 0;

foreach ($devices as $d) {
    $isOnline = api_device_is_online($d);
    $status = (string)($d['status'] ?? 'offline');

    if ($status === 'maintenance') {
        $sensorNeedsRepair++;
    } elseif (!$isOnline) {
        $sensorOffline++;
    } else {
        $sensorHealthy++;
    }
}

/*
|--------------------------------------------------------------------------
| Users Across the City (per-barangay)
|--------------------------------------------------------------------------
*/

$barangayUserStats = admin_fetch_all(
    "SELECT
        b.id,
        b.name,
        COUNT(DISTINCT p.id) AS parent_count,
        COUNT(DISTINCT c.id) AS child_count,
        (COUNT(DISTINCT p.id) + COUNT(DISTINCT c.id)) AS total_users
     FROM barangays b
     LEFT JOIN parents p ON p.barangay_id = b.id AND p.status = 'active'
     LEFT JOIN children c ON c.barangay_id = b.id
     WHERE b.status = 'active'
     GROUP BY b.id, b.name
     ORDER BY total_users DESC, b.name ASC"
);

$totalParents = 0;
$totalChildren = 0;
$barangayMaxUsers = 0;

foreach ($barangayUserStats as &$bs) {
    $totalParents += (int)$bs['parent_count'];
    $totalChildren += (int)$bs['child_count'];
    if ((int)$bs['total_users'] > $barangayMaxUsers) {
        $barangayMaxUsers = (int)$bs['total_users'];
    }
}
unset($bs);

$totalSystemUsers = $totalParents + $totalChildren;

// Normalize barangay names for GeoJSON matching
function dashboard_normalize_name(string $name): string
{
    $name = preg_replace('/\s*\((?:pob\.?|poblacion)\)\s*/i', '', $name) ?? $name;
    $name = preg_replace('/\s+/', ' ', trim($name)) ?? trim($name);
    return mb_strtolower($name);
}

$byNormalizedName = [];
foreach ($barangayUserStats as $row) {
    $byNormalizedName[dashboard_normalize_name((string)$row['name'])] = [
        'name' => $row['name'],
        'parents' => (int)$row['parent_count'],
        'children' => (int)$row['child_count'],
        'total' => (int)$row['total_users'],
    ];
}

/*
|--------------------------------------------------------------------------
| Recent Audit Logs (for live alerts sidebar)
|--------------------------------------------------------------------------
*/

$recentLogs = admin_fetch_all(
    'SELECT a.id, a.action, a.level, a.description, a.created_at, COALESCE(u.email, "System") AS actor
     FROM audit_logs a
     LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.created_at DESC, a.id DESC
     LIMIT 8'
);

/*
|--------------------------------------------------------------------------
| Staff Users (for User Management section)
|--------------------------------------------------------------------------
*/

$staffUsers = admin_fetch_all(
    'SELECT u.id, u.name, u.email, u.username, u.status, u.last_login, u.created_at,
            r.name AS role_name, b.name AS barangay
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     LEFT JOIN barangays b ON b.id = u.barangay_id
     ORDER BY u.created_at DESC, u.id DESC'
);

$actions = '<a class="admin-btn-secondary" href="' . admin_e(app_url('/admin/users.php')) . '">' . admin_action_icon('view') . ' Manage users</a>';

admin_layout_start('Dashboard', 'System overview, user distribution, and device monitoring.', 'dashboard', $actions);
?>

<?php
/* ─── TOP SUMMARY CARDS ─────────────────────────────────────────────── */
?>
<section class="admin-grid-cards">
    <article class="admin-card admin-card--dashboard">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Total Users</div>
                <div class="admin-card-value"><?php echo (int)$totalUsers; ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-up">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="12" height="12"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 19.5l15-15m0 0H8.25m11.25 0v11.25"/></svg>
                        <?php echo (int)$adminCount; ?> admins
                    </span>
                    <span class="admin-card-sep">&middot;</span>
                    <span class="admin-card-trend is-up"><?php echo (int)$nutritionistCount; ?> nutritionists</span>
                </div>
            </div>
        </div>
    </article>

    <article class="admin-card admin-card--dashboard">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.429 9.75 2.25 12l4.179 2.25m0-4.5 5.571 3 5.571-3m-11.142 0L2.25 7.5 12 2.25l9.75 5.25-4.179 2.25m0 0L12 12.75 6.429 9.75m11.142 0 4.179 2.25-9.75 5.25-9.75-5.25 4.179-2.25"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Kiosk Devices</div>
                <div class="admin-card-value"><?php echo (int)$devicesTotal; ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-up">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="12" height="12"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 19.5l15-15m0 0H8.25m11.25 0v11.25"/></svg>
                        <?php echo (int)$devicesOnlineCount; ?> online
                    </span>
                    <span class="admin-card-sep">&middot;</span>
                    <span class="admin-card-trend is-up"><?php echo (int)$devicesTotal - (int)$devicesOnlineCount; ?> offline</span>
                </div>
            </div>
        </div>
    </article>

    <article class="admin-card admin-card--dashboard">
        <div class="admin-card-row">
            <div class="admin-card-icon is-danger">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Security Events</div>
                <div class="admin-card-value"><?php echo (int)$securityEvents; ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-danger">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="12" height="12"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
                        <?php echo (int)$dangerEvents; ?> critical &middot; <?php echo (int)$warningEvents; ?> warnings
                    </span>
                    <span class="admin-card-sep">&middot;</span>
                    <span class="admin-card-trend is-danger"><?php echo (int)$failedLogins; ?> failed logins</span>
                </div>
            </div>
        </div>
    </article>

    <article class="admin-card admin-card--dashboard">
        <div class="admin-card-row">
            <div class="admin-card-icon is-success">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.288 15.038a5.25 5.25 0 0 1 7.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 0 1 1.06 0Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Sensor Status</div>
                <?php if ($sensorNeedsRepair > 0): ?>
                    <div class="admin-card-value admin-card-value--text admin-card-value--warn">Needs Repair</div>
                <?php elseif ($sensorOffline > 0): ?>
                    <div class="admin-card-value admin-card-value--text">Partial Offline</div>
                <?php else: ?>
                    <div class="admin-card-value admin-card-value--text admin-card-value--success">All Healthy</div>
                <?php endif; ?>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-up">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="12" height="12"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                        <?php echo (int)$sensorHealthy; ?> healthy
                    </span>
                    <?php if ($sensorNeedsRepair > 0): ?>
                        <span class="admin-card-sep">&middot;</span>
                        <span class="admin-card-trend is-danger"><?php echo (int)$sensorNeedsRepair; ?> repair</span>
                    <?php endif; ?>
                    <?php if ($sensorOffline > 0): ?>
                        <span class="admin-card-sep">&middot;</span>
                        <span class="admin-card-trend is-danger"><?php echo (int)$sensorOffline; ?> offline</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </article>
</section>

<?php
/* ─── USERS ACROSS THE CITY + USER DISTRIBUTION ────────────────────── */
?>
<section class="admin-dashboard-maprow">
    <article class="admin-section admin-dashboard-mapsection">
        <div class="admin-section-head">
            <div>
                <h2 class="admin-section-title">Users Across the Entire City</h2>
                <p class="admin-section-subtitle">Distribution of registered parents and children across barangays, City of San Fernando, Pampanga.</p>
            </div>
        </div>

        <div class="admin-riskmap-layout">
            <div class="admin-riskmap-mapwrap">
                <div id="user-map" class="admin-riskmap-canvas-v2"></div>
            </div>

            <aside class="admin-riskmap-sidebar">
                <div class="admin-riskmap-card">
                    <h3 class="admin-riskmap-card-title">User Density</h3>
                    <ul id="user-map-legend" class="admin-riskmap-legend-v2">
                        <li data-level="high">
                            <span class="admin-riskmap-swatch" style="background:#0b6e4f"></span>
                            <span class="admin-riskmap-legend-text">High density<em>&ge;20 users</em></span>
                            <span class="admin-riskmap-legend-count" id="legend-high">0</span>
                        </li>
                        <li data-level="medium">
                            <span class="admin-riskmap-swatch" style="background:#2ec57a"></span>
                            <span class="admin-riskmap-legend-text">Medium density<em>10&ndash;19 users</em></span>
                            <span class="admin-riskmap-legend-count" id="legend-medium">0</span>
                        </li>
                        <li data-level="low">
                            <span class="admin-riskmap-swatch" style="background:#a8e6c3"></span>
                            <span class="admin-riskmap-legend-text">Low density<em>1&ndash;9 users</em></span>
                            <span class="admin-riskmap-legend-count" id="legend-low">0</span>
                        </li>
                        <li data-level="none">
                            <span class="admin-riskmap-swatch" style="background:#c7ccd1"></span>
                            <span class="admin-riskmap-legend-text">No users yet</span>
                            <span class="admin-riskmap-legend-count" id="legend-none">0</span>
                        </li>
                    </ul>
                </div>

                <div class="admin-riskmap-card">
                    <h3 class="admin-riskmap-card-title">Top Barangays</h3>
                    <div id="top-barangays-list" class="admin-dashboard-toplist"></div>
                </div>
            </aside>
        </div>

        <div id="user-map-status" class="admin-mini" style="margin-top:8px;"></div>
    </article>

    <article class="admin-section admin-dashboard-distsection">
        <div class="admin-section-head">
            <div>
                <h2 class="admin-section-title">User Distribution</h2>
                <p class="admin-section-subtitle">Registered parents, children, and staff across the system.</p>
            </div>
        </div>

        <div class="admin-dashboard-donut-wrap">
            <canvas id="user-dist-donut" width="240" height="240"></canvas>
        </div>

        <div class="admin-dashboard-dist-stats">
            <div class="admin-dashboard-dist-stat">
                <div class="admin-dashboard-dist-dot" style="background:#0b6e4f"></div>
                <div class="admin-dashboard-dist-info">
                    <span class="admin-dashboard-dist-label">Parents/Guardians</span>
                    <span class="admin-dashboard-dist-value"><?php echo (int)$totalParents; ?></span>
                </div>
            </div>
            <div class="admin-dashboard-dist-stat">
                <div class="admin-dashboard-dist-dot" style="background:#2ec57a"></div>
                <div class="admin-dashboard-dist-info">
                    <span class="admin-dashboard-dist-label">Children</span>
                    <span class="admin-dashboard-dist-value"><?php echo (int)$totalChildren; ?></span>
                </div>
            </div>
            <div class="admin-dashboard-dist-stat">
                <div class="admin-dashboard-dist-dot" style="background:#f2a93b"></div>
                <div class="admin-dashboard-dist-info">
                    <span class="admin-dashboard-dist-label">Staff (Admin + Nutritionist)</span>
                    <span class="admin-dashboard-dist-value"><?php echo (int)$totalUsers; ?></span>
                </div>
            </div>
        </div>

        <div class="admin-dashboard-dist-total">
            <span class="admin-dashboard-dist-total-label">Total System Users</span>
            <span class="admin-dashboard-dist-total-value"><?php echo (int)($totalSystemUsers + $totalUsers); ?></span>
        </div>
    </article>
</section>

<?php
/* ─── LIVE KIOSK & SENSOR MONITORING ────────────────────────────────── */
?>
<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">Live Kiosk &amp; Sensor Monitoring</h2>
            <p class="admin-section-subtitle">Current status of deployed SukatKalusugan kiosk devices and their sensors.</p>
        </div>
        <a class="admin-btn-secondary" href="<?php echo admin_e(app_url('/admin/sensors.php')); ?>"><?php echo admin_action_icon('open'); ?> View all devices</a>
    </div>

    <div class="admin-table-wrap">
        <table class="admin-table" id="kiosk-monitor-table">
            <thead>
                <tr>
                    <th>Device</th>
                    <th>Barangay</th>
                    <th>Status</th>
                    <th>Connection</th>
                    <th>HX711 Weight</th>
                    <th>TF-Luna Height</th>
                    <th>Last Activity</th>
                    <th>Last Calibration</th>
                    <th>Maintenance</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($devices as $device): ?>
                    <?php
                    $isOnline = api_device_is_online($device);
                    $deviceStatus = (string)($device['status'] ?? 'offline');

                    // Connection pill
                    $connectionText = $isOnline ? 'online' : 'offline';
                    $connectionClass = $isOnline ? 'is-success' : 'is-danger';

                    // Device status pill
                    if ($deviceStatus === 'active') {
                        $statusPillClass = 'is-success';
                        $statusLabel = 'Active';
                    } elseif ($deviceStatus === 'maintenance') {
                        $statusPillClass = 'is-warn';
                        $statusLabel = 'Maintenance';
                    } else {
                        $statusPillClass = 'is-danger';
                        $statusLabel = 'Offline';
                    }

                    // Sensor health: derive from device status and connectivity
                    if ($deviceStatus === 'maintenance') {
                        $hx711Text = 'Needs repair';
                        $hx711Class = 'is-warn';
                        $tflunaText = 'Needs repair';
                        $tflunaClass = 'is-warn';
                    } elseif (!$isOnline) {
                        $hx711Text = 'Offline';
                        $hx711Class = 'is-muted';
                        $tflunaText = 'Offline';
                        $tflunaClass = 'is-muted';
                    } else {
                        $hx711Text = 'Healthy';
                        $hx711Class = 'is-success';
                        $tflunaText = 'Healthy';
                        $tflunaClass = 'is-success';
                    }

                    // Last seen formatting
                    $lastSeen = (string)($device['last_seen_at'] ?? 'never');
                    $secondsAgo = (int)($device['seconds_since_last_seen'] ?? 9999);
                    if ($secondsAgo < 60) {
                        $lastSeenDisplay = $secondsAgo . 's ago';
                    } elseif ($secondsAgo < 3600) {
                        $lastSeenDisplay = floor($secondsAgo / 60) . 'm ago';
                    } elseif ($secondsAgo < 86400) {
                        $lastSeenDisplay = floor($secondsAgo / 3600) . 'h ago';
                    } else {
                        $lastSeenDisplay = floor($secondsAgo / 86400) . 'd ago';
                    }
                    ?>
                    <tr data-filter-text="<?php echo admin_e(strtolower($device['device_code'] . ' ' . (string)($device['barangay'] ?? '') . ' ' . $deviceStatus . ' ' . $connectionText)); ?>">
                        <td style="font-weight:700;font-family:monospace;"><?php echo admin_e((string)$device['device_code']); ?></td>
                        <td><?php echo admin_e((string)($device['barangay'] ?? 'Unassigned')); ?></td>
                        <td><span class="admin-pill <?php echo $statusPillClass; ?>"><?php echo $statusLabel; ?></span></td>
                        <td>
                            <span class="admin-status-dot" style="background:<?php echo $isOnline ? '#0b6e4f' : '#c93b3b'; ?>"></span>
                            <?php echo $connectionText; ?>
                            <span class="admin-mini">&nbsp;(<?php echo admin_e($lastSeenDisplay); ?>)</span>
                        </td>
                        <td><span class="admin-pill <?php echo $hx711Class; ?>"><?php echo $hx711Text; ?></span></td>
                        <td><span class="admin-pill <?php echo $tflunaClass; ?>"><?php echo $tflunaText; ?></span></td>
                        <td class="admin-mini"><?php echo admin_e($lastSeen); ?></td>
                        <td class="admin-mini"><?php echo admin_e((string)($device['last_calibration_at'] ?? 'n/a')); ?></td>
                        <td>
                            <?php if ($deviceStatus === 'maintenance'): ?>
                                <span class="admin-pill is-warn">Repair required</span>
                            <?php elseif (!$isOnline): ?>
                                <span class="admin-pill is-muted">Check device</span>
                            <?php else: ?>
                                <span class="admin-pill is-success">Healthy</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php
/* ─── RECENT SECURITY ALERTS ────────────────────────────────────────── */
?>
<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">Recent Security Alerts</h2>
            <p class="admin-section-subtitle">Latest critical and warning events from the audit log.</p>
        </div>
        <a class="admin-btn-secondary" href="<?php echo admin_e(app_url('/admin/audit_logs.php')); ?>"><?php echo admin_action_icon('view'); ?> View all logs</a>
    </div>

    <?php if (empty($recentLogs)): ?>
        <div class="admin-empty">
            <p>No recent security events recorded.</p>
        </div>
    <?php else: ?>
        <div class="admin-list">
            <?php foreach ($recentLogs as $log): ?>
                <?php
                $levelClass = match ($log['level']) {
                    'danger' => 'is-danger',
                    'warning' => 'is-warn',
                    default => 'is-success',
                };
                ?>
                <div class="admin-list-item">
                    <div>
                        <div><span class="admin-pill <?php echo $levelClass; ?>"><?php echo admin_e($log['level']); ?></span> <?php echo admin_e($log['action']); ?></div>
                        <div class="admin-mini"><?php echo admin_e($log['description'] ?? ''); ?></div>
                    </div>
                    <div class="admin-mini" style="text-align:right;">
                        <div><?php echo admin_e($log['actor']); ?></div>
                        <div><?php echo admin_e((string)$log['created_at']); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php
/* ─── USER MANAGEMENT ───────────────────────────────────────────────── */
?>
<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">User Management</h2>
            <p class="admin-section-subtitle">Nutritionist and Administrator accounts with status and activity.</p>
        </div>
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <input class="admin-search" type="search" placeholder="Search users..." data-admin-filter="#staff-users-table" style="flex:1; min-width:180px;">
            <a class="admin-btn" href="<?php echo admin_e(app_url('/admin/user_form.php')); ?>"><?php echo admin_action_icon('add'); ?> Add user</a>
        </div>
    </div>

    <div class="admin-table-wrap">
        <table class="admin-table" id="staff-users-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Role</th>
                    <th>Barangay</th>
                    <th>Status</th>
                    <th>Date Registered</th>
                    <th>Last Login</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($staffUsers as $su): ?>
                    <?php
                    $statusClass = $su['status'] === 'active' ? 'is-success' : 'is-muted';
                    ?>
                    <tr data-filter-text="<?php echo admin_e(strtolower($su['name'] . ' ' . $su['role_name'] . ' ' . (string)($su['barangay'] ?? '') . ' ' . $su['status'])); ?>">
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <span class="admin-avatar" style="background:<?php echo admin_avatar_color($su['name']); ?>;width:32px;height:32px;font-size:0.7rem;"><?php echo admin_initials($su['name']); ?></span>
                                <div>
                                    <div style="font-weight:700;"><?php echo admin_e($su['name']); ?></div>
                                    <div class="admin-mini"><?php echo admin_e($su['email']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td><span class="admin-pill <?php echo $su['role_name'] === 'admin' ? 'is-warn' : 'is-success'; ?>"><?php echo admin_e(ucfirst($su['role_name'])); ?></span></td>
                        <td><?php echo admin_e((string)($su['barangay'] ?? 'All barangays')); ?></td>
                        <td><span class="admin-pill <?php echo $statusClass; ?>"><?php echo admin_e(ucfirst($su['status'])); ?></span></td>
                        <td class="admin-mini"><?php echo admin_e((string)($su['created_at'] ?? 'n/a')); ?></td>
                        <td class="admin-mini"><?php echo admin_e((string)($su['last_login'] ?? 'never')); ?></td>
                        <td>
                            <div class="admin-actions">
                                <a class="admin-icon-btn" title="Edit" href="<?php echo admin_e(app_url('/admin/user_form.php?id=' . (int)$su['id'])); ?>"><?php echo admin_action_icon('edit'); ?></a>
                                <form method="post" action="<?php echo admin_e(app_url('/api/admin/users_delete.php')); ?>" onsubmit="return confirm('Delete <?php echo admin_e($su['name']); ?>?');" style="display:inline;">
                                    <input type="hidden" name="id" value="<?php echo (int)$su['id']; ?>">
                                    <button class="admin-icon-btn admin-icon-btn-danger" title="Delete" type="submit"><?php echo admin_action_icon('delete'); ?></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script>
<script>
(function () {
    "use strict";

    var STATS_BY_NAME = <?php echo json_encode($byNormalizedName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    var GEOJSON_URL = <?php echo json_encode(app_url('/assets/data/sanfernando_barangays.geojson')); ?>;
    var MAX_USERS = <?php echo (int)$barangayMaxUsers; ?>;

    var DIST_COLORS = {
        high: '#0b6e4f',
        medium: '#2ec57a',
        low: '#a8e6c3',
        none: '#c7ccd1'
    };

    function getLevel(total) {
        if (total === 0) return 'none';
        if (total >= 20) return 'high';
        if (total >= 10) return 'medium';
        return 'low';
    }

    function normalizeName(name) {
        return String(name || '')
            .replace(/\s*\((?:pob\.?|poblacion)\)\s*/i, '')
            .trim()
            .replace(/\s+/g, ' ')
            .toLowerCase();
    }

    /* ─── User Distribution Donut ──────────────────────────────────── */
    function renderDonut() {
        var canvas = document.getElementById('user-dist-donut');
        if (!canvas || typeof Chart === 'undefined') return;

        var parents = <?php echo (int)$totalParents; ?>;
        var children = <?php echo (int)$totalChildren; ?>;
        var staff = <?php echo (int)$totalUsers; ?>;

        new Chart(canvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Parents/Guardians', 'Children', 'Staff'],
                datasets: [{
                    data: [parents, children, staff],
                    backgroundColor: ['#0b6e4f', '#2ec57a', '#f2a93b'],
                    borderColor: '#ffffff',
                    borderWidth: 3
                }]
            },
            options: {
                responsive: false,
                cutout: '65%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                                var pct = total > 0 ? Math.round((ctx.parsed / total) * 100) : 0;
                                return ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    /* ─── Top Barangays List ───────────────────────────────────────── */
    function renderTopBarangays() {
        var container = document.getElementById('top-barangays-list');
        if (!container) return;

        var sorted = Object.keys(STATS_BY_NAME).map(function (k) { return STATS_BY_NAME[k]; })
            .filter(function (s) { return s.total > 0; })
            .sort(function (a, b) { return b.total - a.total; })
            .slice(0, 6);

        if (sorted.length === 0) {
            container.innerHTML = '<div class="admin-empty"><p>No registered users yet.</p></div>';
            return;
        }

        var html = '';
        sorted.forEach(function (s, i) {
            var barWidth = MAX_USERS > 0 ? Math.round((s.total / MAX_USERS) * 100) : 0;
            html += '<div class="admin-dashboard-toplist-item">';
            html += '<div class="admin-dashboard-toplist-header">';
            html += '<span class="admin-dashboard-toplist-rank">' + (i + 1) + '</span>';
            html += '<span class="admin-dashboard-toplist-name">' + s.name + '</span>';
            html += '<span class="admin-dashboard-toplist-count">' + s.total + '</span>';
            html += '</div>';
            html += '<div class="admin-dashboard-toplist-bar"><div class="admin-dashboard-toplist-fill" style="width:' + barWidth + '%"></div></div>';
            html += '<div class="admin-dashboard-toplist-meta">' + s.parents + ' parents &middot; ' + s.children + ' children</div>';
            html += '</div>';
        });
        container.innerHTML = html;
    }

    /* ─── Leaflet Map ──────────────────────────────────────────────── */
    function initMap() {
        var mapEl = document.getElementById('user-map');
        if (!mapEl || typeof L === 'undefined') return;

        var map = L.map(mapEl, { scrollWheelZoom: false, zoomControl: false }).setView([15.034, 120.686], 12);
        L.control.zoom({ position: 'bottomright' }).addTo(map);

        var baseLayers = {
            street: L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors'
            }),
            satellite: L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
                maxZoom: 19,
                attribution: 'Tiles &copy; Esri, Maxar, Earthstar Geographics'
            }),
            terrain: L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {
                maxZoom: 17,
                attribution: '&copy; OpenStreetMap contributors, SRTM &mdash; &copy; OpenTopoMap (CC-BY-SA)'
            })
        };

        var activeBase = baseLayers.street.addTo(map);

        // Basemap gallery
        var BasemapGallery = L.Control.extend({
            options: { position: 'topright' },
            onAdd: function (map) {
                var container = L.DomUtil.create('div', 'admin-riskmap-basemaps');
                var options = [
                    { key: 'street', label: 'Street' },
                    { key: 'satellite', label: 'Satellite' },
                    { key: 'terrain', label: 'Terrain' }
                ];
                options.forEach(function (opt) {
                    var btn = L.DomUtil.create('button', 'admin-riskmap-basemap-btn', container);
                    btn.type = 'button';
                    btn.textContent = opt.label;
                    btn.dataset.basemap = opt.key;
                    if (opt.key === 'street') btn.classList.add('is-active');
                    L.DomEvent.on(btn, 'click', function (e) {
                        L.DomEvent.stopPropagation(e);
                        this._select(opt.key, container);
                    }, this);
                }, this);
                L.DomEvent.disableClickPropagation(container);
                L.DomEvent.disableScrollPropagation(container);
                this._container = container;
                return container;
            },
            _select: function (key, container) {
                if (this._onSelect) this._onSelect(key);
                container.querySelectorAll('.admin-riskmap-basemap-btn').forEach(function (b) {
                    b.classList.toggle('is-active', b.dataset.basemap === key);
                });
            },
            onSelect: function (fn) { this._onSelect = fn; return this; }
        });

        // Search
        var BarangaySearch = L.Control.extend({
            options: { position: 'topleft' },
            onAdd: function () {
                var container = L.DomUtil.create('div', 'admin-riskmap-search');
                var input = L.DomUtil.create('input', 'admin-riskmap-search-input', container);
                input.type = 'text';
                input.placeholder = 'Find a barangay\u2026';
                var dropdown = L.DomUtil.create('div', 'admin-riskmap-search-dropdown', container);
                dropdown.style.display = 'none';
                var names = Object.keys(STATS_BY_NAME).map(function (k) { return STATS_BY_NAME[k].name; });

                function showDropdown(query) {
                    dropdown.innerHTML = '';
                    if (!query) { dropdown.style.display = 'none'; return; }
                    var lower = query.toLowerCase();
                    var matches = names.filter(function (n) { return n.toLowerCase().indexOf(lower) !== -1; }).slice(0, 8);
                    if (matches.length === 0) { dropdown.style.display = 'none'; return; }
                    matches.forEach(function (name) {
                        var item = document.createElement('div');
                        item.className = 'admin-riskmap-search-item';
                        item.textContent = name;
                        item.addEventListener('mousedown', function (e) {
                            e.preventDefault();
                            input.value = name;
                            dropdown.style.display = 'none';
                            if (this._onSearch) this._onSearch(name);
                        }.bind(this));
                        dropdown.appendChild(item);
                    }.bind(this));
                    dropdown.style.display = 'block';
                }

                L.DomEvent.on(input, 'input', function () { showDropdown.call(this, input.value); }, this);
                L.DomEvent.on(input, 'keydown', function (e) {
                    if (e.key === 'Enter') { dropdown.style.display = 'none'; if (this._onSearch) this._onSearch(input.value); }
                    if (e.key === 'Escape') dropdown.style.display = 'none';
                }, this);
                L.DomEvent.on(input, 'blur', function () { setTimeout(function () { dropdown.style.display = 'none'; }, 150); });
                L.DomEvent.disableClickPropagation(container);
                L.DomEvent.disableScrollPropagation(container);
                this._dropdown = dropdown;
                this._input = input;
                return container;
            },
            onSearch: function (fn) { this._onSearch = fn; return this; }
        });

        var geoLayer = null;

        new BarangaySearch().onSearch(function (query) {
            if (!geoLayer || !query) return;
            var target = null;
            geoLayer.eachLayer(function (fl) {
                if (fl.feature.properties.name.toLowerCase() === query.toLowerCase()) target = fl;
            });
            if (target) {
                map.fitBounds(target.getBounds(), { padding: [40, 40], maxZoom: 15 });
                target.openPopup();
            }
        }).addTo(map);

        new BasemapGallery().onSelect(function (key) {
            if (baseLayers[key] === activeBase) return;
            map.removeLayer(activeBase);
            activeBase = baseLayers[key].addTo(map);
        }).addTo(map);

        // Legend counters
        var levelCounts = { high: 0, medium: 0, low: 0, none: 0 };

        fetch(GEOJSON_URL)
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (geojson) {
                var matched = 0;

                geoLayer = L.geoJSON(geojson, {
                    style: function (feature) {
                        var key = normalizeName(feature.properties.name);
                        var stat = STATS_BY_NAME[key];
                        var total = stat ? stat.total : 0;
                        var level = getLevel(total);
                        levelCounts[level]++;
                        return {
                            color: '#ffffff',
                            weight: 1,
                            fillColor: DIST_COLORS[level],
                            fillOpacity: 0.75
                        };
                    },
                    onEachFeature: function (feature, featureLayer) {
                        var key = normalizeName(feature.properties.name);
                        var stat = STATS_BY_NAME[key];
                        if (stat) matched++;

                        var label = stat ? stat.name : feature.properties.name;
                        var body = stat && stat.total > 0
                            ? '<strong>' + label + '</strong><br>' +
                              stat.total + ' users (' + stat.parents + ' parents, ' + stat.children + ' children)'
                            : '<strong>' + label + '</strong><br>No registered users yet.';

                        featureLayer.bindPopup(body);
                        featureLayer.on('mouseover', function () { featureLayer.setStyle({ weight: 2.5, color: '#2f3d3a' }); });
                        featureLayer.on('mouseout', function () { geoLayer.resetStyle(featureLayer); });
                    }
                }).addTo(map);

                map.fitBounds(geoLayer.getBounds(), { padding: [16, 16] });

                // Update legend counts
                document.getElementById('legend-high').textContent = levelCounts.high;
                document.getElementById('legend-medium').textContent = levelCounts.medium;
                document.getElementById('legend-low').textContent = levelCounts.low;
                document.getElementById('legend-none').textContent = levelCounts.none;

                var missing = Object.keys(STATS_BY_NAME).length - matched;
                var statusEl = document.getElementById('user-map-status');
                if (statusEl) {
                    statusEl.textContent = missing > 0
                        ? 'Boundary data isn\'t available for ' + missing + ' barangay(s) in the master list.'
                        : '';
                }
            })
            .catch(function () {
                mapEl.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--admin-muted);font-size:0.9rem;">Could not load map boundaries. Data is available in the table above.</div>';
            });
    }

    /* ─── Initialize ───────────────────────────────────────────────── */
    document.addEventListener('DOMContentLoaded', function () {
        renderDonut();
        renderTopBarangays();
        initMap();
    });
})();
</script>
<?php
admin_layout_end();

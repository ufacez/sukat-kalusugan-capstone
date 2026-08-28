<?php

require_once __DIR__ . '/../includes/admin_helpers.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/firebase_sync.php';

start_secure_session();
require_permission('dashboard.view');

$recentLogs = admin_fetch_all(
    'SELECT a.id, a.action, a.level, a.description, a.created_at, COALESCE(u.email, "System") AS actor
     FROM audit_logs a
     LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.created_at DESC, a.id DESC
     LIMIT 5'
);

/*
|--------------------------------------------------------------------------
| "ONLINE" IS COMPUTED, NEVER JUST READ FROM devices.status
|--------------------------------------------------------------------------
|
| devices.status is an admin-set flag (active/maintenance/offline) — it
| does NOT get pushed to "offline" by itself. It only used to change when
| someone happened to be polling device_ping.php from an open kiosk tab,
| which meant this dashboard could show "active" for a device that had
| been powered off for hours if nobody had the kiosk page open.
|
| Fix: this dashboard now computes connectivity itself, the same way
| device_ping.php does, straight from last_seen_at — so it's correct the
| moment you load the page, with no dependency on any other page's polling.
|
*/
$devices = admin_fetch_all(
    'SELECT device_code, location, status, last_seen_at, last_calibration_at, updated_at,
            TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) AS seconds_since_last_seen
     FROM devices
     ORDER BY device_code ASC'
);

$devicesOnlineCount = 0;
foreach ($devices as &$device) {
    // Correct devices.status in the table too, not just the badge below —
    // see api_sync_stale_device_status() in api_helpers.php.
    $device = api_sync_stale_device_status($device);
    if (api_device_is_online($device)) {
        $devicesOnlineCount++;
    }
}
unset($device);

$summary = [
    'users' => admin_scalar('SELECT COUNT(*) FROM users'),
    'admins' => admin_scalar("SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE r.name = 'admin'"),
    'nutritionists' => admin_scalar("SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE r.name = 'nutritionist'"),
    'audit_errors' => admin_scalar("SELECT COUNT(*) FROM audit_logs WHERE level = 'danger'"),
    'devices_online' => $devicesOnlineCount,
    'devices_total' => admin_scalar('SELECT COUNT(*) FROM devices'),
];


$actions = '<a class="admin-btn-secondary" href="' . admin_e(app_url('/admin/users.php')) . '">' . admin_action_icon('view') . ' Manage users</a>';

admin_layout_start('Dashboard', 'System overview, account health, and device status.', 'dashboard', $actions);
?>
<section class="admin-grid-cards">
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Total Users</div>
                <div class="admin-card-value"><?php echo (int)$summary['users']; ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-up">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="12" height="12"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 19.5l15-15m0 0H8.25m11.25 0v11.25"/></svg>
                        <?php echo (int)$summary['admins']; ?> admins
                    </span>
                    <span class="admin-card-sep">&middot;</span>
                    <span class="admin-card-trend is-up"><?php echo (int)$summary['nutritionists']; ?> nutritionists</span>
                </div>
            </div>
        </div>
    </article>

    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon is-danger">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Security Events</div>
                <div class="admin-card-value"><?php echo (int)$summary['audit_errors']; ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-danger">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="12" height="12"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
                        Danger-level logs
                    </span>
                </div>
            </div>
        </div>
    </article>

    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.429 9.75 2.25 12l4.179 2.25m0-4.5 5.571 3 5.571-3m-11.142 0L2.25 7.5 12 2.25l9.75 5.25-4.179 2.25m0 0L12 12.75 6.429 9.75m11.142 0 4.179 2.25-9.75 5.25-9.75-5.25 4.179-2.25"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Devices</div>
                <div class="admin-card-value"><?php echo (int)$summary['devices_total']; ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-up">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="12" height="12"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 19.5l15-15m0 0H8.25m11.25 0v11.25"/></svg>
                        <?php echo (int)$summary['devices_online']; ?> active
                    </span>
                </div>
            </div>
        </div>
    </article>

    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon is-success">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">System Status</div>
                <div class="admin-card-value admin-card-value--text">Healthy</div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-up">All systems operational</span>
                </div>
            </div>
        </div>
    </article>
</section>

<section class="admin-panel-grid">
    <article class="admin-section">
        <div class="admin-section-head">
            <div>
                <h2 class="admin-section-title">Recent Audit Activity</h2>
                <p class="admin-section-subtitle">Latest security and operational events.</p>
            </div>
            <a class="admin-btn-secondary" href="<?php echo admin_e(app_url('/admin/audit_logs.php')); ?>"><?php echo admin_action_icon('view'); ?> View all</a>
        </div>
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
    </article>

    <article class="admin-section">
        <div class="admin-section-head">
            <div>
                <h2 class="admin-section-title">Device Status</h2>
                <p class="admin-section-subtitle">Current kiosk and hardware inventory.</p>
            </div>
            <a class="admin-btn-secondary" href="<?php echo admin_e(app_url('/admin/sensors.php')); ?>"><?php echo admin_action_icon('open'); ?> Open sensors</a>
        </div>
        <div class="admin-list">
            <?php foreach ($devices as $device): ?>
                <?php
                $isOnline = api_device_is_online($device);
                $isMaintenance = ($device['status'] ?? '') === 'maintenance';
                $pillLabel = $isMaintenance ? 'maintenance' : ($isOnline ? 'online' : 'offline');
                $pillClass = $isMaintenance ? 'is-warn' : ($isOnline ? 'is-success' : 'is-danger');
                $dotStyle = $isMaintenance ? 'background:#f2a93b;' : ($isOnline ? 'background:#0b6e4f;' : 'background:#c93b3b;');
                ?>
                <div class="admin-list-item">
                    <div>
                        <div><span class="admin-status-dot" style="<?php echo admin_e($dotStyle); ?>"></span><?php echo admin_e($device['device_code']); ?></div>
                        <div class="admin-mini"><?php echo admin_e($device['location'] ?? 'No location set'); ?></div>
                    </div>
                    <div style="text-align:right;">
                        <div class="admin-pill <?php echo $pillClass; ?>"><?php echo admin_e($pillLabel); ?></div>
                        <div class="admin-mini">Calibrated: <?php echo admin_e((string)($device['last_calibration_at'] ?? 'n/a')); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </article>
</section>
<?php
admin_layout_end();
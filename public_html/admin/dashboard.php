<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();
require_permission('dashboard.view');

$summary = [
    'users' => admin_scalar('SELECT COUNT(*) FROM users'),
    'admins' => admin_scalar("SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE r.name = 'admin'"),
    'nutritionists' => admin_scalar("SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE r.name = 'nutritionist'"),
    'audit_errors' => admin_scalar("SELECT COUNT(*) FROM audit_logs WHERE level = 'danger'"),
    'devices_online' => admin_scalar("SELECT COUNT(*) FROM devices WHERE status = 'active'"),
    'devices_total' => admin_scalar('SELECT COUNT(*) FROM devices'),
];

$recentLogs = admin_fetch_all(
    'SELECT a.id, a.action, a.level, a.description, a.created_at, COALESCE(u.email, "System") AS actor
     FROM audit_logs a
     LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.created_at DESC, a.id DESC
     LIMIT 5'
);

$devices = admin_fetch_all(
    'SELECT device_code, location, status, last_calibration_at, updated_at
     FROM devices
     ORDER BY device_code ASC'
);

$actions = '<a class="admin-btn-secondary" href="' . admin_e(app_url('/admin/users.php')) . '">Manage users</a>';

admin_layout_start('Dashboard', 'System overview, account health, and device status.', 'dashboard', $actions);
?>
<section class="admin-grid-cards">
    <article class="admin-card">
        <div class="admin-stat-label">Total Users</div>
        <div class="admin-stat-value"><?php echo (int)$summary['users']; ?></div>
        <div class="admin-stat-note"><?php echo (int)$summary['admins']; ?> admins · <?php echo (int)$summary['nutritionists']; ?> nutritionists</div>
    </article>
    <article class="admin-card">
        <div class="admin-stat-label">Security Events</div>
        <div class="admin-stat-value"><?php echo (int)$summary['audit_errors']; ?></div>
        <div class="admin-stat-note">Danger-level audit logs</div>
    </article>
    <article class="admin-card">
        <div class="admin-stat-label">Devices</div>
        <div class="admin-stat-value"><?php echo (int)$summary['devices_total']; ?></div>
        <div class="admin-stat-note"><?php echo (int)$summary['devices_online']; ?> active devices</div>
    </article>
    <article class="admin-card">
        <div class="admin-stat-label">System Status</div>
        <div class="admin-stat-value">Healthy</div>
        <div class="admin-stat-note">Database and session layer online</div>
    </article>
</section>

<section class="admin-panel-grid">
    <article class="admin-section">
        <div class="admin-section-head">
            <div>
                <h2 class="admin-section-title">Recent Audit Activity</h2>
                <p class="admin-section-subtitle">Latest security and operational events.</p>
            </div>
            <a class="admin-btn-secondary" href="<?php echo admin_e(app_url('/admin/audit_logs.php')); ?>">View all</a>
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
            <a class="admin-btn-secondary" href="<?php echo admin_e(app_url('/admin/sensors.php')); ?>">Open sensors</a>
        </div>
        <div class="admin-list">
            <?php foreach ($devices as $device): ?>
                <?php
                $pillClass = $device['status'] === 'active' ? 'is-success' : ($device['status'] === 'maintenance' ? 'is-warn' : 'is-danger');
                $dotStyle = $device['status'] === 'active' ? 'background:#0b6e4f;' : ($device['status'] === 'maintenance' ? 'background:#f2a93b;' : 'background:#c93b3b;');
                ?>
                <div class="admin-list-item">
                    <div>
                        <div><span class="admin-status-dot" style="<?php echo admin_e($dotStyle); ?>"></span><?php echo admin_e($device['device_code']); ?></div>
                        <div class="admin-mini"><?php echo admin_e($device['location'] ?? 'No location set'); ?></div>
                    </div>
                    <div style="text-align:right;">
                        <div class="admin-pill <?php echo $pillClass; ?>"><?php echo admin_e($device['status']); ?></div>
                        <div class="admin-mini">Calibrated: <?php echo admin_e((string)($device['last_calibration_at'] ?? 'n/a')); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </article>
</section>
<?php
admin_layout_end();


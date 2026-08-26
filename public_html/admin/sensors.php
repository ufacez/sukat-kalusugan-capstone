<?php

require_once __DIR__ . '/../includes/admin_helpers.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/firebase_sync.php';

start_secure_session();
require_permission('sensors.view');

$devices = admin_fetch_all(
    'SELECT d.id, d.device_code, d.location, d.barangay_id, bg.name AS barangay, d.status, d.last_seen_at, d.last_calibration_at,
            TIMESTAMPDIFF(SECOND, d.last_seen_at, NOW()) AS seconds_since_last_seen
     FROM devices d
     LEFT JOIN barangays bg ON bg.id = d.barangay_id
     ORDER BY d.device_code ASC'
);
$deviceCount = count($devices);
$activeCount = 0;
$maintenanceCount = 0;
$offlineCount = 0;

foreach ($devices as &$device) {
    // Correct devices.status in the database itself the moment we notice
    // it's stale, instead of only computing a separate "online/offline"
    // pill for display. Without this, the Active/Maintenance/Offline
    // badge below (and the counts above) kept reading the admin-set
    // 'active' label straight from the table, which never changed by
    // itself unless a kiosk tab happened to be open polling device_ping.php.
    $device = api_sync_stale_device_status($device);

    // Use the same heartbeat window as everywhere else (device_ping.php,
    // the admin dashboard) instead of a separate hardcoded number, so
    // "online" means the same thing on every screen in the app.
    $device['connection_online'] = api_device_is_online($device);

    if ($device['status'] === 'active') {
        $activeCount++;
    } elseif ($device['status'] === 'maintenance') {
        $maintenanceCount++;
    } else {
        $offlineCount++;
    }
}
unset($device);

$actions = '';

admin_layout_start('Sensors', 'Manage kiosk devices and calibration offsets.', 'sensors', $actions);
?>
<section class="admin-grid-cards">
    <article class="admin-card">
        <div class="admin-stat-label">Devices</div>
        <div class="admin-stat-value"><?php echo $deviceCount; ?></div>
        <div class="admin-stat-note">Registered hardware nodes</div>
    </article>
    <article class="admin-card">
        <div class="admin-stat-label">Active</div>
        <div class="admin-stat-value"><?php echo $activeCount; ?></div>
        <div class="admin-stat-note">Ready for measurements</div>
    </article>
    <article class="admin-card">
        <div class="admin-stat-label">Maintenance</div>
        <div class="admin-stat-value"><?php echo $maintenanceCount; ?></div>
        <div class="admin-stat-note">Calibration in progress</div>
    </article>
    <article class="admin-card">
        <div class="admin-stat-label">Offline</div>
        <div class="admin-stat-value"><?php echo $offlineCount; ?></div>
        <div class="admin-stat-note">Needs attention</div>
    </article>
</section>

<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">Device Inventory</h2>
            <p class="admin-section-subtitle">Open a device to edit its location, assignment, status, and calibration.</p>
        </div>
    </div>

    <div class="admin-table-wrap">
        <table class="admin-table" id="devices-table">
            <thead>
                <tr>
                    <th>Device code</th>
                    <th>Location</th>
                    <th>Barangay</th>
                    <th>Status</th>
                    <th>Connection</th>
                    <th>Last seen</th>
                    <th>Last calibration</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($devices as $device): ?>
                    <?php
                        $connectionText = !empty($device['connection_online']) ? 'online' : 'offline';
                        $connectionClass = !empty($device['connection_online']) ? 'is-success' : 'is-danger';
                        $deviceStatus = (string)($device['status'] ?? 'offline');
                    ?>
                    <tr data-filter-text="<?php echo admin_e(strtolower((string)$device['device_code'] . ' ' . (string)($device['location'] ?? '') . ' ' . (string)($device['barangay'] ?? '') . ' ' . $deviceStatus)); ?>">
                        <td style="font-weight:700;color:var(--admin-text);font-family:monospace;"><?php echo admin_e((string)($device['device_code'] ?? '')); ?></td>
                        <td style="color:var(--admin-muted);"><?php echo admin_e((string)($device['location'] ?? '')); ?></td>
                        <td style="color:var(--admin-muted);"><?php echo admin_e((string)($device['barangay'] ?? 'All barangays')); ?></td>
                        <td><span class="admin-pill <?php echo $deviceStatus === 'active' ? 'is-success' : ($deviceStatus === 'maintenance' ? 'is-warn' : 'is-danger'); ?>"><?php echo admin_e($deviceStatus); ?></span></td>
                        <td><span class="admin-pill <?php echo $connectionClass; ?>"><?php echo admin_e($connectionText); ?></span></td>
                        <td style="color:var(--admin-muted);"><?php echo admin_e((string)($device['last_seen_at'] ?? 'never')); ?></td>
                        <td style="color:var(--admin-muted);"><?php echo admin_e((string)($device['last_calibration_at'] ?? 'n/a')); ?></td>
                        <td>
                            <div class="admin-actions">
                                <a class="admin-btn-secondary" href="<?php echo admin_e(app_url('/admin/device_form.php?id=' . (int)$device['id'])); ?>">Edit</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php
admin_layout_end();

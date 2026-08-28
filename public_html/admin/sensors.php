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
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M5.25 14.25h13.5m-13.5 0a3 3 0 0 1-3-3m3 3a3 3 0 1 0 0 6h13.5a3 3 0 1 0 0-6m-16.5-3a3 3 0 0 1 3-3h13.5a3 3 0 0 1 3 3m-19.5 0a4.5 4.5 0 0 1 .9-2.7L5.737 5.1a3.375 3.375 0 0 1 2.7-1.35h7.126c1.062 0 2.062.5 2.7 1.35l2.587 3.45a4.5 4.5 0 0 1 .9 2.7m0 0a3 3 0 0 1-3 3m0 3h.008v.008h-.008v-.008Zm0-6h.008v.008h-.008v-.008Zm-3 6h.008v.008h-.008v-.008Zm0-6h.008v.008h-.008v-.008Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Devices</div>
                <div class="admin-card-value"><?php echo $deviceCount; ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-up">Registered hardware nodes</span>
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
                <div class="admin-card-label">Active</div>
                <div class="admin-card-value"><?php echo $activeCount; ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-up">Ready for measurements</span>
                </div>
            </div>
        </div>
    </article>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437 1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Maintenance</div>
                <div class="admin-card-value"><?php echo $maintenanceCount; ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-up">Calibration in progress</span>
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
                <div class="admin-card-label">Offline</div>
                <div class="admin-card-value"><?php echo $offlineCount; ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-danger">Needs attention</span>
                </div>
            </div>
        </div>
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
                                <a class="admin-icon-btn" title="Edit" href="<?php echo admin_e(app_url('/admin/device_form.php?id=' . (int)$device['id'])); ?>"><?php echo admin_action_icon('edit'); ?></a>
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

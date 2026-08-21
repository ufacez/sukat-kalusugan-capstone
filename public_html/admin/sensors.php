<?php

require_once __DIR__ . '/../includes/admin_helpers.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/firebase_sync.php';

start_secure_session();
require_permission('sensors.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_device'])) {
    require_permission('sensors.update');

    $deviceId = (int)($_POST['device_id'] ?? 0);
    $location = trim((string)($_POST['location'] ?? ''));
    $barangayIdRaw = trim((string)($_POST['barangay_id'] ?? ''));
    $barangayId = $barangayIdRaw !== '' ? (int)$barangayIdRaw : null;
    $status = trim((string)($_POST['status'] ?? 'active'));
    $lastCalibrationAt = trim((string)($_POST['last_calibration_at'] ?? ''));
    $heightOffset = (float)($_POST['calibration_offset_height'] ?? 0);
    $weightOffset = (float)($_POST['calibration_offset_weight'] ?? 0);

    if ($deviceId > 0) {
        admin_execute(
            'UPDATE devices SET location = ?, barangay_id = ?, status = ?, last_calibration_at = NULLIF(?, ""), calibration_offset_height = ?, calibration_offset_weight = ? WHERE id = ?',
            'sissddi',
            [$location, $barangayId, $status, $lastCalibrationAt, $heightOffset, $weightOffset, $deviceId]
        );

        log_action((current_user()['id'] ?? null), 'UPDATE_DEVICE', 'info', 'Updated device ' . $deviceId);
        admin_redirect('/admin/sensors.php', ['notice' => 'Sensor settings updated successfully.', 'type' => 'success']);
    }
}

$devices = admin_fetch_all(
    'SELECT d.id, d.device_code, d.location, d.barangay_id, bg.name AS barangay, d.status, d.last_seen_at, d.last_calibration_at, d.calibration_offset_height, d.calibration_offset_weight, d.updated_at,
            TIMESTAMPDIFF(SECOND, d.last_seen_at, NOW()) AS seconds_since_last_seen
     FROM devices d
     LEFT JOIN barangays bg ON bg.id = d.barangay_id
     ORDER BY d.device_code ASC'
);
$barangays = admin_barangay_options();
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

$actions = '<div class="admin-muted-block">Edit offsets and status directly per device.</div>';

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
            <p class="admin-section-subtitle">Each row can be saved back to the devices table.</p>
        </div>
    </div>

    <div class="admin-list">
        <?php foreach ($devices as $device): ?>
            <?php
                $connectionText = !empty($device['connection_online']) ? 'online' : 'offline';
                $connectionClass = !empty($device['connection_online']) ? 'is-success' : 'is-danger';
                $deviceStatus = (string)($device['status'] ?? 'offline');
            ?>
            <form class="admin-check-card" method="post">
                <input type="hidden" name="device_id" value="<?php echo (int)$device['id']; ?>">
                <input type="hidden" name="save_device" value="1">
                <div class="admin-section-head" style="margin-bottom:12px;">
                    <div>
                        <h3 class="admin-section-title" style="font-size:1rem;"><?php echo admin_e((string)($device['device_code'] ?? '')); ?></h3>
                        <p class="admin-section-subtitle">Last seen: <?php echo admin_e((string)($device['last_seen_at'] ?? 'never')); ?></p>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <span class="admin-pill <?php echo $deviceStatus === 'active' ? 'is-success' : ($deviceStatus === 'maintenance' ? 'is-warn' : 'is-danger'); ?>"><?php echo admin_e($deviceStatus); ?></span>
                        <span class="admin-pill <?php echo $connectionClass; ?>"><?php echo admin_e($connectionText); ?></span>
                        <span class="admin-pill is-muted"><?php echo admin_e((string)($device['barangay'] ?? 'All barangays')); ?></span>
                    </div>
                </div>
                <div class="admin-form-grid">
                    <label class="admin-field">
                        <span>Location</span>
                        <input name="location" value="<?php echo admin_e((string)($device['location'] ?? '')); ?>">
                    </label>
                    <label class="admin-field">
                        <span>Barangay</span>
                        <select name="barangay_id">
                            <option value="">-- Unassigned (shows all barangays) --</option>
                            <?php foreach ($barangays as $barangay): ?>
                                <option value="<?php echo (int)$barangay['id']; ?>" <?php echo (int)($device['barangay_id'] ?? 0) === (int)$barangay['id'] ? 'selected' : ''; ?>><?php echo admin_e($barangay['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="admin-field">
                        <span>Device status</span>
                        <select name="status">
                            <option value="active" <?php echo $deviceStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="maintenance" <?php echo $deviceStatus === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                            <option value="offline" <?php echo $deviceStatus === 'offline' ? 'selected' : ''; ?>>Offline</option>
                        </select>
                    </label>
                    <label class="admin-field">
                        <span>Last calibration date</span>
                        <input type="date" name="last_calibration_at" value="<?php echo admin_e((string)($device['last_calibration_at'] ?? '')); ?>">
                    </label>
                    <label class="admin-field">
                        <span>Height offset (cm)</span>
                        <input type="number" step="0.01" name="calibration_offset_height" value="<?php echo admin_e((string)($device['calibration_offset_height'] ?? '0')); ?>">
                    </label>
                    <label class="admin-field">
                        <span>Weight offset (kg)</span>
                        <input type="number" step="0.001" name="calibration_offset_weight" value="<?php echo admin_e((string)($device['calibration_offset_weight'] ?? '0')); ?>">
                    </label>
                    <div class="admin-field" style="align-content:end;">
                        <span>&nbsp;</span>
                        <button class="admin-btn" type="submit">Save sensor settings</button>
                    </div>
                </div>
            </form>
        <?php endforeach; ?>
    </div>
</section>
<?php
admin_layout_end();
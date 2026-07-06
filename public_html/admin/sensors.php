<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();
require_permission('sensors.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_device'])) {
    require_permission('sensors.update');

    $deviceId = (int)($_POST['device_id'] ?? 0);
    $location = trim((string)($_POST['location'] ?? ''));
    $status = trim((string)($_POST['status'] ?? 'active'));
    $lastCalibrationAt = trim((string)($_POST['last_calibration_at'] ?? ''));
    $heightOffset = (float)($_POST['calibration_offset_height'] ?? 0);
    $weightOffset = (float)($_POST['calibration_offset_weight'] ?? 0);

    if ($deviceId > 0) {
        admin_execute(
            'UPDATE devices SET location = ?, status = ?, last_calibration_at = NULLIF(?, ""), calibration_offset_height = ?, calibration_offset_weight = ? WHERE id = ?',
            'sssddi',
            [$location, $status, $lastCalibrationAt, $heightOffset, $weightOffset, $deviceId]
        );
        log_action((current_user()['id'] ?? null), 'UPDATE_DEVICE', 'info', 'Updated device ' . $deviceId);
        admin_redirect('/admin/sensors.php', ['notice' => 'Device updated successfully.', 'type' => 'success']);
    }
}

$devices = admin_fetch_all('SELECT id, device_code, location, status, last_calibration_at, calibration_offset_height, calibration_offset_weight, updated_at FROM devices ORDER BY device_code ASC');
$deviceCount = count($devices);
$activeCount = 0;
$maintenanceCount = 0;
$offlineCount = 0;

foreach ($devices as $device) {
    if ($device['status'] === 'active') {
        $activeCount++;
    } elseif ($device['status'] === 'maintenance') {
        $maintenanceCount++;
    } else {
        $offlineCount++;
    }
}

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
            <form class="admin-check-card" method="post">
                <input type="hidden" name="device_id" value="<?php echo (int)$device['id']; ?>">
                <input type="hidden" name="save_device" value="1">
                <div class="admin-section-head" style="margin-bottom:12px;">
                    <div>
                        <h3 class="admin-section-title" style="font-size:1rem;"><?php echo admin_e($device['device_code']); ?></h3>
                        <p class="admin-section-subtitle"><?php echo admin_e((string)($device['updated_at'] ?? '')); ?></p>
                    </div>
                    <span class="admin-pill <?php echo $device['status'] === 'active' ? 'is-success' : ($device['status'] === 'maintenance' ? 'is-warn' : 'is-danger'); ?>"><?php echo admin_e($device['status']); ?></span>
                </div>
                <div class="admin-form-grid">
                    <label class="admin-field">
                        <span>Location</span>
                        <input name="location" value="<?php echo admin_e($device['location'] ?? ''); ?>">
                    </label>
                    <label class="admin-field">
                        <span>Status</span>
                        <select name="status">
                            <option value="active" <?php echo $device['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="maintenance" <?php echo $device['status'] === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                            <option value="offline" <?php echo $device['status'] === 'offline' ? 'selected' : ''; ?>>Offline</option>
                        </select>
                    </label>
                    <label class="admin-field">
                        <span>Last calibration date</span>
                        <input type="date" name="last_calibration_at" value="<?php echo admin_e((string)($device['last_calibration_at'] ?? '')); ?>">
                    </label>
                    <label class="admin-field">
                        <span>Height offset</span>
                        <input type="number" step="0.01" name="calibration_offset_height" value="<?php echo admin_e((string)$device['calibration_offset_height']); ?>">
                    </label>
                    <label class="admin-field">
                        <span>Weight offset</span>
                        <input type="number" step="0.001" name="calibration_offset_weight" value="<?php echo admin_e((string)$device['calibration_offset_weight']); ?>">
                    </label>
                    <div class="admin-field" style="align-content:end;">
                        <span>&nbsp;</span>
                        <button class="admin-btn" type="submit">Save device</button>
                    </div>
                </div>
            </form>
        <?php endforeach; ?>
    </div>
</section>
<?php
admin_layout_end();


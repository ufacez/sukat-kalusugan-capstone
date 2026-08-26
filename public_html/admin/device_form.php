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
    $hx711CalFactor = (float)($_POST['hx711_calibration_factor'] ?? -20892.50);
    $mountingHeightCm = (float)($_POST['mounting_height_cm'] ?? 182.88);

    if ($deviceId > 0) {
        admin_execute(
            'UPDATE devices SET location = ?, barangay_id = ?, status = ?, last_calibration_at = NULLIF(?, ""), calibration_offset_height = ?, calibration_offset_weight = ?, hx711_calibration_factor = ?, mounting_height_cm = ? WHERE id = ?',
            'sissddddi',
            [$location, $barangayId, $status, $lastCalibrationAt, $heightOffset, $weightOffset, $hx711CalFactor, $mountingHeightCm, $deviceId]
        );

        log_action((current_user()['id'] ?? null), 'UPDATE_DEVICE', 'info', 'Updated device ' . $deviceId);
        admin_redirect('/admin/sensors.php', ['notice' => 'Sensor settings updated successfully.', 'type' => 'success']);
    }

    admin_redirect('/admin/sensors.php', ['notice' => 'Device not found.', 'type' => 'error']);
}

$editId = (int)($_GET['id'] ?? ($_GET['edit'] ?? 0));

$device = null;

if ($editId > 0) {
    $deviceParams = [$editId];
    $deviceStmtSql = 'SELECT d.id, d.device_code, d.location, d.barangay_id, bg.name AS barangay, d.status, d.last_seen_at, d.last_calibration_at, d.calibration_offset_height, d.calibration_offset_weight, d.hx711_calibration_factor, d.mounting_height_cm, d.updated_at,
            TIMESTAMPDIFF(SECOND, d.last_seen_at, NOW()) AS seconds_since_last_seen
     FROM devices d
     LEFT JOIN barangays bg ON bg.id = d.barangay_id
     WHERE d.id = ?
     LIMIT 1';

    $device = admin_fetch_one($deviceStmtSql, 'i', $deviceParams);
}

if ($device === null) {
    admin_redirect(
        '/admin/sensors.php',
        [
            'notice' => 'Device not found.',
            'type' => 'error'
        ]
    );
}

$device = api_sync_stale_device_status($device);
$device['connection_online'] = api_device_is_online($device);

$barangays = admin_barangay_options();

$connectionText = !empty($device['connection_online']) ? 'online' : 'offline';
$connectionClass = !empty($device['connection_online']) ? 'is-success' : 'is-danger';
$deviceStatus = (string)($device['status'] ?? 'offline');

$actions = '<a class="admin-btn-secondary" href="'
    . admin_e(app_url('/admin/sensors.php'))
    . '">' . admin_action_icon('back') . ' Sensors</a>';

admin_layout_start('Edit Device', 'Update location, assignment, status, and calibration offsets.', 'sensors', $actions);
?>
<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title"><?php echo admin_e((string)($device['device_code'] ?? '')); ?></h2>
            <p class="admin-section-subtitle">Last seen: <?php echo admin_e((string)($device['last_seen_at'] ?? 'never')); ?></p>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <span class="admin-pill <?php echo $deviceStatus === 'active' ? 'is-success' : ($deviceStatus === 'maintenance' ? 'is-warn' : 'is-danger'); ?>"><?php echo admin_e($deviceStatus); ?></span>
            <span class="admin-pill <?php echo $connectionClass; ?>"><?php echo admin_e($connectionText); ?></span>
            <span class="admin-pill is-muted"><?php echo admin_e((string)($device['barangay'] ?? 'All barangays')); ?></span>
        </div>
    </div>

    <form class="admin-form-grid" method="post" action="<?php echo admin_e(app_url('/admin/device_form.php')); ?>">
        <input type="hidden" name="device_id" value="<?php echo (int)$device['id']; ?>">
        <input type="hidden" name="save_device" value="1">

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
        <label class="admin-field">
            <span>HX711 calibration factor</span>
            <input type="number" step="0.0001" name="hx711_calibration_factor" value="<?php echo admin_e((string)($device['hx711_calibration_factor'] ?? '-20892.5')); ?>">
            <small class="admin-field-hint">Raw load-cell scale factor (grams per HX711 count). Fetched by the ESP32 on its next poll -- no reflash needed.</small>
        </label>
        <label class="admin-field">
            <span>Mounting height (cm)</span>
            <input type="number" step="0.01" name="mounting_height_cm" value="<?php echo admin_e((string)($device['mounting_height_cm'] ?? '182.88')); ?>">
            <small class="admin-field-hint">TF-Luna sensor height above an empty platform. Fetched by the ESP32 on its next poll -- no reflash needed.</small>
        </label>

        <div class="admin-field admin-field-wide" style="align-content:end;">
            <span>&nbsp;</span>
            <div class="admin-actions">
                <button class="admin-btn" type="submit">Save sensor settings</button>
            </div>
        </div>
    </form>
</section>
<?php
admin_layout_end();

<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_helpers.php';

api_require_method(['GET', 'POST']);

$deviceCode = api_string($_GET['device'] ?? $_POST['device_id'] ?? $_POST['deviceCode'] ?? 'ESP32-KIOSK-01', 'ESP32-KIOSK-01');

$conn = get_db_connection();
$upsert = mysqli_prepare(
    $conn,
    'INSERT INTO devices (device_code, location, status, last_seen_at, updated_at)
     VALUES (?, ?, ?, NOW(), NOW())
     ON DUPLICATE KEY UPDATE
         location = COALESCE(VALUES(location), location),
         status = COALESCE(VALUES(status), status),
         last_seen_at = NOW(),
         updated_at = NOW()'
);

if ($upsert !== false) {
    $defaultLocation = 'ESP32 Kiosk';
    $defaultStatus = 'active';
    mysqli_stmt_bind_param($upsert, 'sss', $deviceCode, $defaultLocation, $defaultStatus);
    mysqli_stmt_execute($upsert);
    mysqli_stmt_close($upsert);
}

$stmt = mysqli_prepare($conn, 'SELECT id, device_code, location, status, last_seen_at, last_calibration_at, calibration_offset_height, calibration_offset_weight, updated_at FROM devices WHERE device_code = ? LIMIT 1');

if ($stmt !== false) {
    mysqli_stmt_bind_param($stmt, 's', $deviceCode);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $device = $result instanceof mysqli_result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
} else {
    $device = null;
}

if ($device === null) {
    api_success([
        'device_id' => $deviceCode,
        'connected' => false,
        'status' => 'offline',
        'device_status' => 'offline',
        'lidar_status' => 'waiting',
        'loadcell_status' => 'waiting',
        'height' => null,
        'weight' => null,
        'message' => 'Device not registered yet. Connect the kiosk and register the ESP32 first.',
    ], 'Device not ready.');
}

$deviceStatus = strtolower((string)($device['status'] ?? 'offline'));
$online = api_device_is_online($device, 30);
if ($deviceStatus !== 'maintenance' && !$online) {
    $statusUpdate = mysqli_prepare($conn, 'UPDATE devices SET status = ?, last_seen_at = NOW(), updated_at = NOW() WHERE device_code = ?');
    if ($statusUpdate !== false) {
        $offlineStatus = 'offline';
        mysqli_stmt_bind_param($statusUpdate, 'ss', $offlineStatus, $deviceCode);
        mysqli_stmt_execute($statusUpdate);
        mysqli_stmt_close($statusUpdate);
    }
    $deviceStatus = 'offline';
}

api_success([
    'device_id' => (string)($device['device_code'] ?? $deviceCode),
    'connected' => $online && $deviceStatus !== 'maintenance',
    'status' => $online && $deviceStatus !== 'maintenance' ? 'online' : 'offline',
    'device_status' => $online && $deviceStatus !== 'maintenance' ? 'online' : 'offline',
    'lidar_status' => $online && $deviceStatus !== 'maintenance' ? 'ready' : 'waiting',
    'loadcell_status' => $online && $deviceStatus !== 'maintenance' ? 'ready' : 'waiting',
    'height' => null,
    'weight' => null,
    'location' => (string)($device['location'] ?? ''),
    'last_seen_at' => (string)($device['last_seen_at'] ?? ''),
    'updated_at' => (string)($device['updated_at'] ?? date('Y-m-d H:i:s')),
], 'Device available.');

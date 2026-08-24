<?php

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_helpers.php';

api_require_method(['GET']);

$deviceCode = api_string($_GET['device'] ?? $_GET['device_id'] ?? 'ESP32-KIOSK-01', 'ESP32-KIOSK-01');
$conn = get_db_connection();
$stmt = mysqli_prepare(
    $conn,
    'SELECT device_code, location, status, last_seen_at, updated_at,
            TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) AS seconds_since_last_seen
     FROM devices
     WHERE device_code = ?
     LIMIT 1'
);

$device = null;
if ($stmt !== false) {
    mysqli_stmt_bind_param($stmt, 's', $deviceCode);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $device = $result instanceof mysqli_result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
}

if ($device === null) {
    api_success([
        'device_id' => $deviceCode,
        'connected' => false,
        'status' => 'offline',
        'device_status' => 'offline',
        'lidar_status' => 'waiting',
        'loadcell_status' => 'waiting',
        'message' => 'Device not registered yet.',
    ], 'Device not ready.');
}

$deviceStatus = strtolower((string)($device['status'] ?? 'offline'));
$online = api_device_is_online($device, DEVICE_ONLINE_THRESHOLD_SECONDS);
$isConnected = $online && $deviceStatus !== 'maintenance' && $deviceStatus !== 'offline';

api_success([
    'device_id' => (string)$device['device_code'],
    'connected' => $isConnected,
    'status' => $isConnected ? 'online' : 'offline',
    'device_status' => $isConnected ? 'online' : 'offline',
    'lidar_status' => $isConnected ? 'ready' : 'waiting',
    'loadcell_status' => $isConnected ? 'ready' : 'waiting',
    'location' => (string)($device['location'] ?? ''),
    'last_seen_at' => (string)($device['last_seen_at'] ?? ''),
    'updated_at' => (string)($device['updated_at'] ?? ''),
], 'Device available.');

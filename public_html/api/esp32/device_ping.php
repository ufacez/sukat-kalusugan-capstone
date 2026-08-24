<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/firebase_sync.php';

api_require_method(['GET', 'POST']);

api_require_device_key();

$deviceCode = api_string($_GET['device'] ?? $_POST['device_id'] ?? $_POST['deviceCode'] ?? 'ESP32-KIOSK-01', 'ESP32-KIOSK-01');

$conn = get_db_connection();

/*
|--------------------------------------------------------------------------
| READ-ONLY STATUS CHECK
|--------------------------------------------------------------------------
|
| This endpoint is polled by the kiosk browser (kiosk.js) to display
| device/LiDAR/scale chips and gate the Start button. It must NOT write
| last_seen_at itself, or the browser polling would keep the device
| looking "online" even after the physical ESP32 is powered off.
|
| The real heartbeat comes from the ESP32 firmware calling
| get_command.php (see api_device_upsert() in api_helpers.php). This
| endpoint only reads that value.
|
*/

$stmt = mysqli_prepare($conn, 'SELECT id, device_code, location, status, last_seen_at, last_calibration_at, calibration_offset_height, calibration_offset_weight, updated_at,
    TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) AS seconds_since_last_seen
    FROM devices WHERE device_code = ? LIMIT 1');

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
        'message' => 'Device not registered yet. Power on the ESP32 so it can check in.',
    ], 'Device not ready.');
}

$deviceStatus = strtolower((string)($device['status'] ?? 'offline'));
$online = api_device_is_online($device, DEVICE_ONLINE_THRESHOLD_SECONDS);

/*
|--------------------------------------------------------------------------
| LAZILY FLAG A STALE DEVICE AS OFFLINE
|--------------------------------------------------------------------------
|
| Only touch the `status` column here, never `last_seen_at`. Rewriting
| last_seen_at would refresh the heartbeat clock and make a genuinely
| offline device look "recently seen" again on the very next poll.
|
| This check only runs when the kiosk browser polls this endpoint
| (every syncSeconds, see kiosk.js), so the worst-case detection time
| for a powered-off ESP32 is roughly syncSeconds + DEVICE_ONLINE_THRESHOLD_SECONDS.
| There is no way to make this literally instantaneous over HTTP polling —
| the server can only notice a device is gone once it has stayed silent
| longer than the threshold, since cutting power sends no "goodbye"
| message. Keeping both numbers small is what makes this feel real-time.
|
*/

if ($deviceStatus !== 'maintenance' && $deviceStatus !== 'offline' && !$online) {
    $device = api_sync_stale_device_status($device);
    $deviceStatus = $device['status'];
}

$isConnected = $online && $deviceStatus !== 'maintenance' && $deviceStatus !== 'offline';

api_success([
    'device_id' => (string)($device['device_code'] ?? $deviceCode),
    'connected' => $isConnected,
    'status' => $isConnected ? 'online' : 'offline',
    'device_status' => $isConnected ? 'online' : 'offline',
    'lidar_status' => $isConnected ? 'ready' : 'waiting',
    'loadcell_status' => $isConnected ? 'ready' : 'waiting',
    'height' => null,
    'weight' => null,
    'location' => (string)($device['location'] ?? ''),
    'last_seen_at' => (string)($device['last_seen_at'] ?? ''),
    'updated_at' => (string)($device['updated_at'] ?? date('Y-m-d H:i:s')),
], 'Device available.');
<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_helpers.php';

api_require_method(['GET', 'POST']);

$deviceCode = api_string($_GET['device_id'] ?? $_POST['device_id'] ?? $_GET['device'] ?? $_POST['device'] ?? 'ESP32-KIOSK-01', 'ESP32-KIOSK-01');
$token = api_string($_GET['token'] ?? $_POST['token'] ?? '', '');

if ($token === '') {
    api_error('Scan token is required.', 400);
}

$conn = get_db_connection();
$stmt = mysqli_prepare($conn, 'SELECT id, status FROM devices WHERE device_code = ? LIMIT 1');

if ($stmt === false) {
    api_error('Unable to validate device.', 500);
}

mysqli_stmt_bind_param($stmt, 's', $deviceCode);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$device = $result instanceof mysqli_result ? mysqli_fetch_assoc($result) : null;
mysqli_stmt_close($stmt);

if ($device === null) {
    api_error('Device not registered.', 404);
}

$deviceStatus = strtolower((string)($device['status'] ?? 'offline'));
$online = api_device_is_online($device, 30);
if ($deviceStatus !== 'active' || !$online) {
    $markOffline = mysqli_prepare($conn, 'UPDATE devices SET status = ?, last_seen_at = NOW(), updated_at = NOW() WHERE device_code = ?');
    if ($markOffline !== false) {
        $offlineStatus = 'offline';
        mysqli_stmt_bind_param($markOffline, 'ss', $offlineStatus, $deviceCode);
        mysqli_stmt_execute($markOffline);
        mysqli_stmt_close($markOffline);
    }

    api_success([
        'device_id' => $deviceCode,
        'scan_requested' => false,
        'accepted' => false,
        'message' => 'Device is offline or not active.',
    ], 'Device not ready for scan.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$scanKey = 'esp32_scan_' . $deviceCode;
$scanState = $_SESSION[$scanKey] ?? [
    'token' => '',
    'active' => false,
    'expires_at' => 0,
];

$currentTime = time();
if (($scanState['active'] ?? false) && ($scanState['expires_at'] ?? 0) > $currentTime) {
    $existingToken = (string)($scanState['token'] ?? '');

    if ($existingToken === $token) {
        api_success([
            'device_id' => $deviceCode,
            'scan_requested' => true,
            'accepted' => true,
            'message' => 'Scan already pending for this device.',
        ], 'Scan already pending.');
    }

    api_success([
        'device_id' => $deviceCode,
        'scan_requested' => false,
        'accepted' => false,
        'message' => 'Scan already in progress for this device. Please wait for the current measurement to finish.',
    ], 'Scan already in progress.');
}

$_SESSION[$scanKey] = [
    'token' => $token,
    'active' => true,
    'expires_at' => $currentTime + 30,
];

api_success([
    'device_id' => $deviceCode,
    'scan_requested' => true,
    'accepted' => true,
    'token' => $token,
    'message' => 'Scan command accepted. ESP32 can begin measurement sequence.',
], 'Scan command accepted.');

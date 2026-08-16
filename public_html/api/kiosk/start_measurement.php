<?php

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/measurement_sessions.php';

api_require_method(['POST']);

$payload = api_payload();
$deviceCode = api_string($payload['device_id'] ?? $payload['deviceCode'] ?? 'ESP32-KIOSK-01', 'ESP32-KIOSK-01');
$childId = api_int($payload['child_id'] ?? $payload['childId'] ?? 0, 0);
$requestedLocation = api_string($payload['location'] ?? $payload['source'] ?? 'Kiosk', 'Kiosk');

if (!preg_match('/^[A-Za-z0-9_-]{3,50}$/', $deviceCode)) {
    api_error('Invalid device ID.', 400);
}

if ($childId <= 0) {
    api_error('Child selection is required.', 400);
}

$conn = get_db_connection();
$deviceRow = api_device_upsert($deviceCode, $requestedLocation, 'active');

if (!is_array($deviceRow)) {
    api_error('Unable to register kiosk device.', 500);
}

$deviceId = (int)($deviceRow['id'] ?? 0);

if ($deviceId <= 0) {
    api_error('Device is not available.', 500);
}

$childStmt = mysqli_prepare($conn, 'SELECT id, child_code, first_name, last_name FROM children WHERE id = ? LIMIT 1');
if ($childStmt === false) {
    api_error('Unable to validate child selection.', 500);
}

mysqli_stmt_bind_param($childStmt, 'i', $childId);
mysqli_stmt_execute($childStmt);
$childResult = mysqli_stmt_get_result($childStmt);
$childRow = $childResult instanceof mysqli_result ? mysqli_fetch_assoc($childResult) : null;
mysqli_stmt_close($childStmt);

if (!is_array($childRow)) {
    api_error('Child not found.', 404);
}

mysqli_begin_transaction($conn);

$deviceLock = mysqli_prepare($conn, 'SELECT id, device_code FROM devices WHERE id = ? LIMIT 1 FOR UPDATE');
if ($deviceLock === false) {
    mysqli_rollback($conn);
    api_error('Unable to lock device.', 500);
}

mysqli_stmt_bind_param($deviceLock, 'i', $deviceId);
mysqli_stmt_execute($deviceLock);
$deviceLockResult = mysqli_stmt_get_result($deviceLock);
$deviceLockRow = $deviceLockResult instanceof mysqli_result ? mysqli_fetch_assoc($deviceLockResult) : null;
mysqli_stmt_close($deviceLock);

if (!is_array($deviceLockRow)) {
    mysqli_rollback($conn);
    api_error('Device not found.', 404);
}

$activeSessionStmt = mysqli_prepare(
    $conn,
    'SELECT id, status, command, child_id, started_at, completed_at, expires_at
     FROM measurement_sessions
     WHERE device_id = ?
       AND status IN (\'START_REQUESTED\', \'MEASURING\')
     ORDER BY id DESC
     LIMIT 1
     FOR UPDATE'
);

if ($activeSessionStmt === false) {
    mysqli_rollback($conn);
    api_error('Unable to inspect active session.', 500);
}

mysqli_stmt_bind_param($activeSessionStmt, 'i', $deviceId);
mysqli_stmt_execute($activeSessionStmt);
$activeResult = mysqli_stmt_get_result($activeSessionStmt);
$activeSession = $activeResult instanceof mysqli_result ? mysqli_fetch_assoc($activeResult) : null;
mysqli_stmt_close($activeSessionStmt);

if (is_array($activeSession)) {
    mysqli_commit($conn);

    api_success([
        'accepted' => true,
        'duplicate' => true,
        'device_id' => $deviceCode,
        'device_db_id' => $deviceId,
        'session_id' => (int)($activeSession['id'] ?? 0),
        'status' => (string)($activeSession['status'] ?? 'START_REQUESTED'),
        'command' => (string)($activeSession['command'] ?? 'START'),
        'child_id' => (int)($activeSession['child_id'] ?? $childId),
        'child_code' => (string)($childRow['child_code'] ?? ''),
        'child_name' => trim((string)($childRow['first_name'] ?? '') . ' ' . (string)($childRow['last_name'] ?? '')),
        'started_at' => (string)($activeSession['started_at'] ?? ''),
        'completed_at' => (string)($activeSession['completed_at'] ?? ''),
        'expires_at' => (string)($activeSession['expires_at'] ?? ''),
        'can_start_new' => false,
        'device_online' => api_device_is_online($deviceRow, 30),
    ], 'Measurement already in progress.');
}

$expiresAt = (new DateTimeImmutable('now'))->modify('+' . MEASUREMENT_SESSION_TIMEOUT_SECONDS . ' seconds')->format('Y-m-d H:i:s');
$sessionStatus = 'START_REQUESTED';
$sessionCommand = 'START';

$insertStmt = mysqli_prepare(
    $conn,
    'INSERT INTO measurement_sessions (device_id, child_id, status, command, expires_at, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
);

if ($insertStmt === false) {
    mysqli_rollback($conn);
    api_error('Unable to queue measurement session.', 500);
}

mysqli_stmt_bind_param($insertStmt, 'iisss', $deviceId, $childId, $sessionStatus, $sessionCommand, $expiresAt);

if (!mysqli_stmt_execute($insertStmt)) {
    mysqli_stmt_close($insertStmt);
    mysqli_rollback($conn);
    api_error('Could not create measurement session.', 500);
}

$sessionId = mysqli_insert_id($conn);
mysqli_stmt_close($insertStmt);

mysqli_commit($conn);

api_success([
    'accepted' => true,
    'duplicate' => false,
    'device_id' => $deviceCode,
    'device_db_id' => $deviceId,
    'session_id' => $sessionId,
    'status' => $sessionStatus,
    'command' => $sessionCommand,
    'child_id' => $childId,
    'child_code' => (string)($childRow['child_code'] ?? ''),
    'child_name' => trim((string)($childRow['first_name'] ?? '') . ' ' . (string)($childRow['last_name'] ?? '')),
    'started_at' => null,
    'completed_at' => null,
    'expires_at' => $expiresAt,
    'can_start_new' => false,
    'device_online' => api_device_is_online($deviceRow, 30),
], 'Measurement start queued.');

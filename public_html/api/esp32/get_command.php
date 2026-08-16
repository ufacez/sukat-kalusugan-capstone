<?php

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/measurement_sessions.php';

api_require_method(['GET', 'POST']);

$payload = api_payload();
$deviceCode = api_string($payload['device_id'] ?? $payload['deviceCode'] ?? $_GET['device_id'] ?? $_GET['device'] ?? 'ESP32-KIOSK-01', 'ESP32-KIOSK-01');

if (!preg_match('/^[A-Za-z0-9_-]{3,50}$/', $deviceCode)) {
    api_error('Invalid device ID.', 400);
}

$conn = get_db_connection();
$deviceRow = api_device_upsert($deviceCode, 'ESP32 Kiosk', 'active');

if (!is_array($deviceRow)) {
    api_error('Unable to validate device.', 500);
}

$deviceId = (int)($deviceRow['id'] ?? 0);
if ($deviceId <= 0) {
    api_error('Device is not available.', 500);
}

mysqli_begin_transaction($conn);

$sessionStmt = mysqli_prepare(
    $conn,
    'SELECT
        s.id AS session_id,
        s.device_id AS device_db_id,
        d.device_code,
        s.child_id,
        c.child_code,
        c.first_name AS child_first_name,
        c.last_name AS child_last_name,
        s.status,
        s.command,
        s.started_at,
        s.completed_at,
        s.expires_at,
        s.height_cm,
        s.weight_kg,
        s.measurement_id,
        s.error_message,
        s.created_at,
        s.updated_at
     FROM measurement_sessions s
     INNER JOIN devices d ON d.id = s.device_id
     INNER JOIN children c ON c.id = s.child_id
     WHERE s.device_id = ?
       AND s.status IN (\'START_REQUESTED\', \'MEASURING\')
     ORDER BY s.id ASC
     LIMIT 1
     FOR UPDATE'
);

if ($sessionStmt === false) {
    mysqli_rollback($conn);
    api_error('Unable to read session queue.', 500);
}

mysqli_stmt_bind_param($sessionStmt, 'i', $deviceId);
mysqli_stmt_execute($sessionStmt);
$sessionResult = mysqli_stmt_get_result($sessionStmt);
$sessionRow = $sessionResult instanceof mysqli_result ? mysqli_fetch_assoc($sessionResult) : null;
mysqli_stmt_close($sessionStmt);

if (is_array($sessionRow) && (string)($sessionRow['status'] ?? '') === 'START_REQUESTED') {
    $now = new DateTimeImmutable('now');
    $expiresAt = $now->modify('+' . MEASUREMENT_SESSION_TIMEOUT_SECONDS . ' seconds')->format('Y-m-d H:i:s');

    $claimStmt = mysqli_prepare(
        $conn,
        'UPDATE measurement_sessions
         SET status = \'MEASURING\',
             started_at = COALESCE(started_at, NOW()),
             expires_at = ?,
             updated_at = NOW()
         WHERE id = ?'
    );

    if ($claimStmt !== false) {
        $sessionId = (int)($sessionRow['session_id'] ?? 0);
        mysqli_stmt_bind_param($claimStmt, 'si', $expiresAt, $sessionId);
        mysqli_stmt_execute($claimStmt);
        mysqli_stmt_close($claimStmt);
        $sessionRow['status'] = 'MEASURING';
        $sessionRow['started_at'] = (string)($sessionRow['started_at'] ?: $now->format('Y-m-d H:i:s'));
        $sessionRow['expires_at'] = $expiresAt;
    }

    mysqli_commit($conn);

    api_success([
        'device_id' => $deviceCode,
        'device_db_id' => $deviceId,
        'session_id' => (int)($sessionRow['session_id'] ?? 0),
        'child_id' => (int)($sessionRow['child_id'] ?? 0),
        'child_code' => (string)($sessionRow['child_code'] ?? ''),
        'child_name' => trim((string)($sessionRow['child_first_name'] ?? '') . ' ' . (string)($sessionRow['child_last_name'] ?? '')),
        'status' => 'MEASURING',
        'state' => 'MEASURING',
        'command' => 'START',
        'should_measure' => true,
        'measurement_active' => true,
        'started_at' => (string)($sessionRow['started_at'] ?? ''),
        'expires_at' => (string)($sessionRow['expires_at'] ?? ''),
    ], 'Measurement command dispatched.');
}

if (is_array($sessionRow) && (string)($sessionRow['status'] ?? '') === 'MEASURING') {
    mysqli_commit($conn);

    api_success([
        'device_id' => $deviceCode,
        'device_db_id' => $deviceId,
        'session_id' => (int)($sessionRow['session_id'] ?? 0),
        'child_id' => (int)($sessionRow['child_id'] ?? 0),
        'child_code' => (string)($sessionRow['child_code'] ?? ''),
        'child_name' => trim((string)($sessionRow['child_first_name'] ?? '') . ' ' . (string)($sessionRow['child_last_name'] ?? '')),
        'status' => 'MEASURING',
        'state' => 'MEASURING',
        'command' => 'NONE',
        'should_measure' => false,
        'measurement_active' => true,
        'started_at' => (string)($sessionRow['started_at'] ?? ''),
        'expires_at' => (string)($sessionRow['expires_at'] ?? ''),
    ], 'Measurement already in progress.');
}

mysqli_commit($conn);

api_success([
    'device_id' => $deviceCode,
    'device_db_id' => $deviceId,
    'session_id' => null,
    'status' => 'IDLE',
    'state' => 'IDLE',
    'command' => 'NONE',
    'should_measure' => false,
    'measurement_active' => false,
], 'No command available.');

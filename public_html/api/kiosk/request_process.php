<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/measurement_sessions.php';

/*
|--------------------------------------------------------------------------
| REQUEST PROCESS
|--------------------------------------------------------------------------
|
| Called by the kiosk browser when the operator clicks "Process
| Measurement". This does NOT compute or save the final measurement
| itself — it only flips measurement_sessions.command to 'PROCESS'.
|
| The ESP32 (see runMeasurement() in the firmware) polls get_command.php
| every SESSION_VALIDATE_INTERVAL while it is live-sampling and waits to
| see this command before it stops sampling, averages its buffered
| readings, and calls submit_measurement.php. Until this endpoint is
| called, the ESP32 must keep collecting/publishing live readings and
| must NEVER submit a final measurement on its own.
|
*/

api_require_method(['POST']);

$payload = api_payload();

$deviceCode = api_string(
    $payload['device_id']
        ?? $payload['device']
        ?? 'ESP32-KIOSK-01',
    'ESP32-KIOSK-01'
);

$sessionId = api_int(
    $payload['session_id']
        ?? $payload['sessionId']
        ?? 0,
    0
);

if (!preg_match('/^[A-Za-z0-9_-]{3,50}$/', $deviceCode)) {
    api_error('Invalid device ID.', 400);
}

if ($sessionId <= 0) {
    api_error('A valid session ID is required.', 400);
}

$conn = get_db_connection();

mysqli_begin_transaction($conn);

$sessionRow = measurement_session_fetch_by_id_for_device(
    $conn,
    $sessionId,
    $deviceCode
);

if (!is_array($sessionRow)) {
    mysqli_rollback($conn);

    api_error('Measurement session not found for this device.', 404);
}

$status = (string)($sessionRow['status'] ?? '');

/*
|--------------------------------------------------------------------------
| Only a session the ESP32 is actively sampling can be told to process.
|--------------------------------------------------------------------------
*/

if ($status !== 'MEASURING') {
    mysqli_rollback($conn);

    api_error(
        'Measurement is not currently active for this session.',
        409,
        ['status' => $status]
    );
}

$updateStmt = mysqli_prepare(
    $conn,
    'UPDATE measurement_sessions
     SET
        command = \'PROCESS\',
        updated_at = NOW()
     WHERE id = ?
       AND status = \'MEASURING\''
);

if ($updateStmt === false) {
    mysqli_rollback($conn);

    api_error('Unable to request processing.', 500);
}

mysqli_stmt_bind_param($updateStmt, 'i', $sessionId);

$executed = mysqli_stmt_execute($updateStmt);

$affected = $executed ? mysqli_stmt_affected_rows($updateStmt) : 0;

mysqli_stmt_close($updateStmt);

if (!$executed) {
    mysqli_rollback($conn);

    api_error('Unable to request processing.', 500);
}

if ($affected <= 0) {
    // MySQL reports 0 affected rows both when the WHERE clause
    // matched nothing AND when it matched a row that already had
    // these exact values (a no-op UPDATE). Tell those apart before
    // deciding whether this is an error.
    $latest = measurement_session_fetch_by_id_for_device(
        $conn,
        $sessionId,
        $deviceCode
    );

    $latestStatus = (string)($latest['status'] ?? '');
    $latestCommand = (string)($latest['command'] ?? '');

    if ($latestStatus === 'MEASURING' && $latestCommand === 'PROCESS') {
        // Duplicate click (e.g. a double-tap) — the command was
        // already set, nothing changed, nothing went wrong.
        mysqli_commit($conn);

        api_success(
            measurement_session_row_to_payload($latest),
            'Processing already requested.'
        );
    }

    // Someone else (a concurrent request, or the session finishing/
    // erroring in the meantime) changed the row first.
    mysqli_rollback($conn);

    api_error(
        'Measurement session already moved on.',
        409,
        ['status' => $latestStatus]
    );
}

mysqli_commit($conn);

$updatedRow = measurement_session_fetch_by_id_for_device(
    $conn,
    $sessionId,
    $deviceCode
);

$responsePayload = is_array($updatedRow)
    ? measurement_session_row_to_payload($updatedRow)
    : ['session_id' => $sessionId, 'command' => 'PROCESS'];

api_success(
    $responsePayload,
    'Processing requested. Waiting for the device to finalize the reading.'
);
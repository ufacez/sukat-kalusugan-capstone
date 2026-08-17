<?php

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/measurement_sessions.php';

api_require_method(['GET']);

$deviceCode = api_string(
    $_GET['device_id'] ?? $_GET['device'] ?? 'ESP32-KIOSK-01',
    'ESP32-KIOSK-01'
);

$sessionId = api_int(
    $_GET['session_id'] ?? $_GET['sessionId'] ?? 0,
    0
);

if (!preg_match('/^[A-Za-z0-9_-]{3,50}$/', $deviceCode)) {
    api_error('Invalid device ID.', 400);
}

$conn = get_db_connection();

/*
|--------------------------------------------------------------------------
| Check device
|--------------------------------------------------------------------------
*/

$deviceStmt = mysqli_prepare(
    $conn,
    'SELECT
        id,
        device_code,
        status,
        last_seen_at,
        updated_at
     FROM devices
     WHERE device_code = ?
     LIMIT 1'
);

if ($deviceStmt === false) {
    api_error('Unable to inspect device.', 500);
}

mysqli_stmt_bind_param($deviceStmt, 's', $deviceCode);
mysqli_stmt_execute($deviceStmt);

$deviceResult = mysqli_stmt_get_result($deviceStmt);

$deviceRow = $deviceResult instanceof mysqli_result
    ? mysqli_fetch_assoc($deviceResult)
    : null;

mysqli_stmt_close($deviceStmt);

if (!is_array($deviceRow)) {
    api_success(
        [
            'device_id' => $deviceCode,
            'status' => 'IDLE',
            'state' => 'IDLE',
            'can_start_new' => true,
            'active' => false,
            'session' => null,
            'measurement' => null,
        ],
        'No session found.'
    );
}

/*
|--------------------------------------------------------------------------
| IMPORTANT:
|
| If the kiosk provides a session_id, ALWAYS retrieve that exact session.
|
| This is what fixes:
|
| STEP 3 -> stuck forever
|
| Previously the endpoint only searched for START_REQUESTED/MEASURING.
| Once submit_measurement.php changed the session to COMPLETE,
| the endpoint could no longer find it.
|--------------------------------------------------------------------------
*/

if ($sessionId > 0) {

    $sessionRow = measurement_session_fetch_by_id_for_device(
        $conn,
        $sessionId,
        $deviceCode
    );

} else {

    /*
     * Keep the old behavior for requests that do not specify
     * a session ID.
     */
    $sessionRow = measurement_session_fetch_active_for_device(
        $conn,
        $deviceCode
    );
}

/*
|--------------------------------------------------------------------------
| No session
|--------------------------------------------------------------------------
*/

if (!is_array($sessionRow)) {

    api_success(
        [
            'device_id' => $deviceCode,
            'device_db_id' => (int)($deviceRow['id'] ?? 0),
            'status' => 'IDLE',
            'state' => 'IDLE',
            'can_start_new' => true,
            'active' => false,
            'device_online' => api_device_is_online($deviceRow, 30),
            'session' => null,
            'measurement' => null,
        ],
        'No session found.'
    );
}

/*
|--------------------------------------------------------------------------
| Session status
|--------------------------------------------------------------------------
*/

$sessionStatus = (string)($sessionRow['status'] ?? 'IDLE');

$expiresAt = (string)($sessionRow['expires_at'] ?? '');

/*
|--------------------------------------------------------------------------
| Timeout active sessions
|
| DO NOT timeout COMPLETE sessions.
|--------------------------------------------------------------------------
*/

if (
    measurement_session_is_active_status($sessionStatus)
    && $expiresAt !== ''
) {

    $expiryTimestamp = strtotime($expiresAt);

    if (
        $expiryTimestamp !== false
        && $expiryTimestamp < time()
    ) {

        $timeoutStmt = mysqli_prepare(
            $conn,
            'UPDATE measurement_sessions
             SET
                status = \'ERROR\',
                error_message = ?,
                updated_at = NOW()
             WHERE id = ?
               AND status IN (\'START_REQUESTED\', \'MEASURING\')'
        );

        if ($timeoutStmt !== false) {

            $timeoutMessage =
                'Measurement timed out before the ESP32 completed a result.';

            $actualSessionId = (int)(
                $sessionRow['session_id'] ?? 0
            );

            mysqli_stmt_bind_param(
                $timeoutStmt,
                'si',
                $timeoutMessage,
                $actualSessionId
            );

            mysqli_stmt_execute($timeoutStmt);

            mysqli_stmt_close($timeoutStmt);

            $sessionRow['status'] = 'ERROR';
            $sessionRow['error_message'] = $timeoutMessage;

            $sessionStatus = 'ERROR';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Convert database session to API payload
|--------------------------------------------------------------------------
*/

$payload = measurement_session_row_to_payload($sessionRow);

$payload['device_online'] = api_device_is_online(
    $deviceRow,
    30
);

$payload['can_start_new'] =
    !measurement_session_is_active_status(
        (string)$payload['status']
    );

$payload['state'] = $payload['status'];

$payload['active'] =
    measurement_session_is_active_status(
        (string)$payload['status']
    );

/*
|--------------------------------------------------------------------------
| Return COMPLETE session including measurement
|--------------------------------------------------------------------------
*/

api_success(
    $payload,
    'Measurement status loaded.'
);
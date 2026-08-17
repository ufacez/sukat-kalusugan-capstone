<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/measurement_sessions.php';

api_require_method(['POST']);

/*
|--------------------------------------------------------------------------
| Read JSON request
|--------------------------------------------------------------------------
*/

$raw = file_get_contents('php://input');

$input = json_decode($raw ?: '{}', true);

if (!is_array($input)) {
    api_error('Invalid JSON request.', 400);
}

/*
|--------------------------------------------------------------------------
| Request values
|--------------------------------------------------------------------------
*/

$deviceCode = api_string(
    $input['device_id']
        ?? $input['device']
        ?? 'ESP32-KIOSK-01',
    'ESP32-KIOSK-01'
);

$childId = api_int(
    $input['child_id']
        ?? $input['childId']
        ?? 0,
    0
);

$location = api_string(
    $input['location'] ?? 'Kiosk',
    'Kiosk'
);

/*
|--------------------------------------------------------------------------
| Validate
|--------------------------------------------------------------------------
*/

if (!preg_match('/^[A-Za-z0-9_-]{3,50}$/', $deviceCode)) {
    api_error('Invalid device ID.', 400);
}

if ($childId <= 0) {
    api_error('A valid child must be selected.', 400);
}

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

$conn = get_db_connection();

mysqli_begin_transaction($conn);

try {

    /*
    |--------------------------------------------------------------------------
    | Find device
    |--------------------------------------------------------------------------
    */

    $deviceStmt = mysqli_prepare(
        $conn,
        'SELECT
            id,
            device_code,
            status
         FROM devices
         WHERE device_code = ?
         LIMIT 1'
    );

    if ($deviceStmt === false) {
        throw new RuntimeException(
            'Unable to prepare device lookup.'
        );
    }

    mysqli_stmt_bind_param(
        $deviceStmt,
        's',
        $deviceCode
    );

    mysqli_stmt_execute($deviceStmt);

    $deviceResult = mysqli_stmt_get_result($deviceStmt);

    $device = (
        $deviceResult instanceof mysqli_result
            ? mysqli_fetch_assoc($deviceResult)
            : null
    );

    mysqli_stmt_close($deviceStmt);

    if (!is_array($device)) {
        throw new RuntimeException(
            'Device not registered: ' . $deviceCode
        );
    }

    $deviceDbId = (int)$device['id'];

    /*
    |--------------------------------------------------------------------------
    | Verify child exists
    |--------------------------------------------------------------------------
    */

    $childStmt = mysqli_prepare(
        $conn,
        'SELECT
            id,
            child_code,
            first_name,
            last_name
         FROM children
         WHERE id = ?
         LIMIT 1'
    );

    if ($childStmt === false) {
        throw new RuntimeException(
            'Unable to prepare child lookup.'
        );
    }

    mysqli_stmt_bind_param(
        $childStmt,
        'i',
        $childId
    );

    mysqli_stmt_execute($childStmt);

    $childResult = mysqli_stmt_get_result($childStmt);

    $child = (
        $childResult instanceof mysqli_result
            ? mysqli_fetch_assoc($childResult)
            : null
    );

    mysqli_stmt_close($childStmt);

    if (!is_array($child)) {
        throw new RuntimeException(
            'Selected child was not found.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Check for an existing active session
    |--------------------------------------------------------------------------
    */

    $activeStmt = mysqli_prepare(
        $conn,
        'SELECT
            id,
            status,
            child_id
         FROM measurement_sessions
         WHERE device_id = ?
           AND status IN (
                \'START_REQUESTED\',
                \'MEASURING\'
           )
         ORDER BY id DESC
         LIMIT 1'
    );

    if ($activeStmt === false) {
        throw new RuntimeException(
            'Unable to check active measurement session.'
        );
    }

    mysqli_stmt_bind_param(
        $activeStmt,
        'i',
        $deviceDbId
    );

    mysqli_stmt_execute($activeStmt);

    $activeResult = mysqli_stmt_get_result($activeStmt);

    $activeSession = (
        $activeResult instanceof mysqli_result
            ? mysqli_fetch_assoc($activeResult)
            : null
    );

    mysqli_stmt_close($activeStmt);

    if (is_array($activeSession)) {

        throw new RuntimeException(
            'A measurement is already active for this kiosk.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Create measurement session
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | START_REQUESTED tells the ESP32 that a new measurement
    | has been requested.
    |
    */

    $insertStmt = mysqli_prepare(
        $conn,
        'INSERT INTO measurement_sessions (
            device_id,
            child_id,
            status,
            command,
            started_at,
            expires_at,
            created_at,
            updated_at
         )
         VALUES (
            ?,
            ?,
            \'START_REQUESTED\',
            \'START\',
            NOW(),
            DATE_ADD(
                NOW(),
                INTERVAL ? SECOND
            ),
            NOW(),
            NOW()
         )'
    );

    if ($insertStmt === false) {
        throw new RuntimeException(
            'Unable to prepare measurement session creation.'
        );
    }

    $timeoutSeconds =
        (int)MEASUREMENT_SESSION_TIMEOUT_SECONDS;

    mysqli_stmt_bind_param(
        $insertStmt,
        'iii',
        $deviceDbId,
        $childId,
        $timeoutSeconds
    );

    if (!mysqli_stmt_execute($insertStmt)) {

        $error = mysqli_stmt_error($insertStmt);

        mysqli_stmt_close($insertStmt);

        throw new RuntimeException(
            'Unable to create measurement session: ' . $error
        );
    }

    $sessionId =
        (int)mysqli_insert_id($conn);

    mysqli_stmt_close($insertStmt);

    if ($sessionId <= 0) {
        throw new RuntimeException(
            'Measurement session was created without an ID.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Update device
    |--------------------------------------------------------------------------
    */

    $deviceUpdate = mysqli_prepare(
        $conn,
        'UPDATE devices
         SET
            status = \'MEASURING\',
            last_seen_at = NOW(),
            updated_at = NOW()
         WHERE id = ?'
    );

    if ($deviceUpdate !== false) {

        mysqli_stmt_bind_param(
            $deviceUpdate,
            'i',
            $deviceDbId
        );

        mysqli_stmt_execute($deviceUpdate);

        mysqli_stmt_close($deviceUpdate);
    }

    /*
    |--------------------------------------------------------------------------
    | Commit
    |--------------------------------------------------------------------------
    */

    mysqli_commit($conn);

    /*
    |--------------------------------------------------------------------------
    | Retrieve the complete session
    |--------------------------------------------------------------------------
    */

    $sessionRow =
        measurement_session_fetch_by_id_for_device(
            $conn,
            $sessionId,
            $deviceCode
        );

    if (!is_array($sessionRow)) {
        api_error(
            'Measurement session was created but could not be loaded.',
            500
        );
    }

    $payload =
        measurement_session_row_to_payload(
            $sessionRow
        );

    /*
    |--------------------------------------------------------------------------
    | Extra response information
    |--------------------------------------------------------------------------
    */

    $payload['location'] = $location;

    $payload['device_online'] = true;

    $payload['can_start_new'] = false;

    $payload['active'] = true;

    $payload['state'] = 'START_REQUESTED';

    api_success(
        $payload,
        'Measurement started.'
    );

} catch (Throwable $e) {

    mysqli_rollback($conn);

    error_log(
        '[SukatKalusugan] start_measurement.php: '
        . $e->getMessage()
    );

    api_error(
        $e->getMessage(),
        500
    );
}
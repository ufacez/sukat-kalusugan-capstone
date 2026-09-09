<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/measurement_sessions.php';
require_once __DIR__ . '/../../includes/followup_scheduler.php';

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

/*
|--------------------------------------------------------------------------
| Find device (before transaction — needed for cleanup)
|--------------------------------------------------------------------------
*/

$preDeviceStmt = mysqli_prepare(
    $conn,
    'SELECT id FROM devices WHERE device_code = ? LIMIT 1'
);

if ($preDeviceStmt === false) {
    api_error('Unable to prepare device lookup.', 500);
}

mysqli_stmt_bind_param($preDeviceStmt, 's', $deviceCode);
mysqli_stmt_execute($preDeviceStmt);
$preDeviceResult = mysqli_stmt_get_result($preDeviceStmt);
$preDeviceRow = (
    $preDeviceResult instanceof mysqli_result
        ? mysqli_fetch_assoc($preDeviceResult)
        : null
);
mysqli_stmt_close($preDeviceStmt);

if (!is_array($preDeviceRow)) {
    api_error('Device not registered: ' . $deviceCode, 400);
}

$deviceDbId = (int)$preDeviceRow['id'];
$timeoutSeconds = (int)MEASUREMENT_SESSION_TIMEOUT_SECONDS;

/*
|--------------------------------------------------------------------------
| CLEANUP STALE SESSIONS (OUTSIDE transaction — persists on rollback)
|--------------------------------------------------------------------------
|
| This MUST run outside the transaction so that cleaned-up sessions
| are not undone by a later rollback. Otherwise, the active-session
| check throws an exception, the rollback undoes the cleanup, and
| the kiosk stays permanently locked.
|
| Any session in START_REQUESTED or MEASURING status for this device
| is treated as abandoned. The kiosk operator explicitly chose to
| start a new measurement, so the old session is by definition stale.
| Time-based conditions are deliberately avoided — clock skew or
| NTP drift can cause future-dated timestamps that make time-based
| comparisons silently skip the cleanup.
|
*/

$staleCleanup = mysqli_prepare(
    $conn,
    'UPDATE measurement_sessions
     SET
        status = \'ERROR\',
        error_message = \'Session superseded by new measurement request.\',
        updated_at = NOW()
     WHERE device_id = ?
       AND status IN (\'START_REQUESTED\', \'MEASURING\')'
);

if ($staleCleanup !== false) {
    mysqli_stmt_bind_param(
        $staleCleanup,
        'i',
        $deviceDbId
    );
    mysqli_stmt_execute($staleCleanup);
    mysqli_stmt_close($staleCleanup);
}

/*
|--------------------------------------------------------------------------
| Transaction — validate inputs, check for active sessions, create session
|--------------------------------------------------------------------------
*/

mysqli_begin_transaction($conn);

try {

    /*
    |--------------------------------------------------------------------------
    | Find device (re-verify inside transaction)
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
         AND status = \'active\'
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
    | DUE-DATE CHECK
    |--------------------------------------------------------------------------
    |
    | The kiosk MUST verify the child is scheduled for measurement today.
    | This is the backend authority — the kiosk cannot start a measurement
    | unless the child is due (or within the grace window).
    |
    */

    $dueCheck = followup_is_due_today($childId);

    if (!$dueCheck['is_due']) {
        log_action(
            null,
            'MEASUREMENT_REJECTED_NOT_DUE',
            'warning',
            sprintf(
                'Kiosk measurement rejected for child #%d (%s %s): %s',
                $childId,
                $child['first_name'] ?? '',
                $child['last_name'] ?? '',
                $dueCheck['reason']
            )
        );

        throw new RuntimeException(
            $dueCheck['reason']
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
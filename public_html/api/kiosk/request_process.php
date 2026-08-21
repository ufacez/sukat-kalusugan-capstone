<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/measurement_sessions.php';

api_require_method(['POST']);

$payload = api_payload();

/*
|--------------------------------------------------------------------------
| REQUEST DATA
|--------------------------------------------------------------------------
*/

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

/*
|--------------------------------------------------------------------------
| VALIDATION
|--------------------------------------------------------------------------
*/

if (
    !preg_match(
        '/^[A-Za-z0-9_-]{3,50}$/',
        $deviceCode
    )
) {
    api_error(
        'Invalid device ID.',
        400
    );
}

if ($sessionId <= 0) {
    api_error(
        'A valid session ID is required.',
        400
    );
}

/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

$conn = get_db_connection();

mysqli_begin_transaction($conn);

try {

    /*
    |--------------------------------------------------------------------------
    | GET EXACT SESSION
    |--------------------------------------------------------------------------
    */

    $sessionRow =
        measurement_session_fetch_by_id_for_device(
            $conn,
            $sessionId,
            $deviceCode
        );

    if (!is_array($sessionRow)) {

        mysqli_rollback($conn);

        api_error(
            'Measurement session not found for this device.',
            404
        );
    }

    $status = strtoupper(
        (string)(
            $sessionRow['status']
            ?? ''
        )
    );

    /*
    |--------------------------------------------------------------------------
    | SESSION MUST STILL BE MEASURING
    |--------------------------------------------------------------------------
    */

    if ($status !== 'MEASURING') {

        mysqli_rollback($conn);

        api_error(
            'Measurement is not currently active for this session.',
            409,
            [
                'status' => $status
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | REQUEST PROCESS
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | The kiosk DOES NOT submit the final measurement.
    |
    | It only tells the ESP32:
    |
    |     command = PROCESS
    |
    | The ESP32 will:
    |
    | 1. Stop live sampling.
    | 2. Average its final buffered readings.
    | 3. Validate them.
    | 4. Call submit_measurement.php.
    |
    */

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

        api_error(
            'Unable to request processing.',
            500
        );
    }

    mysqli_stmt_bind_param(
        $updateStmt,
        'i',
        $sessionId
    );

    $executed =
        mysqli_stmt_execute(
            $updateStmt
        );

    $affected =
        $executed
            ? mysqli_stmt_affected_rows(
                $updateStmt
            )
            : 0;

    mysqli_stmt_close(
        $updateStmt
    );

    if (!$executed) {

        mysqli_rollback($conn);

        api_error(
            'Unable to request processing.',
            500
        );
    }

    /*
    |--------------------------------------------------------------------------
    | HANDLE DUPLICATE CLICK
    |--------------------------------------------------------------------------
    */

    if ($affected <= 0) {

        $latest =
            measurement_session_fetch_by_id_for_device(
                $conn,
                $sessionId,
                $deviceCode
            );

        $latestStatus =
            strtoupper(
                (string)(
                    $latest['status']
                    ?? ''
                )
            );

        $latestCommand =
            strtoupper(
                (string)(
                    $latest['command']
                    ?? ''
                )
            );

        if (
            $latestStatus === 'MEASURING' &&
            $latestCommand === 'PROCESS'
        ) {

            mysqli_commit($conn);

            api_success(
                measurement_session_row_to_payload(
                    $latest
                ),
                'Processing already requested.'
            );
        }

        mysqli_rollback($conn);

        api_error(
            'Measurement session already moved on.',
            409,
            [
                'status' => $latestStatus
            ]
        );
    }

    mysqli_commit($conn);

    /*
    |--------------------------------------------------------------------------
    | RETURN UPDATED SESSION
    |--------------------------------------------------------------------------
    */

    $updatedRow =
        measurement_session_fetch_by_id_for_device(
            $conn,
            $sessionId,
            $deviceCode
        );

    $responsePayload =
        is_array($updatedRow)
            ? measurement_session_row_to_payload(
                $updatedRow
            )
            : [
                'session_id' => $sessionId,
                'command' => 'PROCESS'
            ];

    api_success(
        $responsePayload,
        'Processing requested. Waiting for the device to finalize the reading.'
    );

} catch (Throwable $e) {

    mysqli_rollback($conn);

    error_log(
        '[SukatKalusugan] request_process.php: '
        . $e->getMessage()
    );

    api_error(
        'Unable to request processing.',
        500
    );
}
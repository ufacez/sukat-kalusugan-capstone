<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/measurement_sessions.php';

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

$conn = get_db_connection();

mysqli_begin_transaction($conn);

try {

    $sessionRow =
        measurement_session_fetch_by_id_for_device(
            $conn,
            $sessionId,
            $deviceCode
        );

    if (!is_array($sessionRow)) {

        mysqli_rollback($conn);

        api_success(
            [
                'session_id' => $sessionId,
                'status' => 'NOT_FOUND',
            ],
            'Session not found — nothing to cancel.'
        );
    }

    $status = strtoupper(
        (string)(
            $sessionRow['status']
            ?? ''
        )
    );

    if (
        $status === 'COMPLETE' ||
        $status === 'ERROR' ||
        $status === 'CANCELLED'
    ) {

        mysqli_rollback($conn);

        api_success(
            [
                'session_id' => $sessionId,
                'status' => $status,
            ],
            'Session already finished (' . $status . ').'
        );
    }

    $updateStmt = mysqli_prepare(
        $conn,
        'UPDATE measurement_sessions
         SET
            status = \'CANCELLED\',
            command = \'CANCEL\',
            error_message = \'Cancelled by operator.\',
            updated_at = NOW()
         WHERE id = ?
           AND status IN (\'START_REQUESTED\', \'MEASURING\')'
    );

    if ($updateStmt === false) {

        mysqli_rollback($conn);

        api_error(
            'Unable to cancel session.',
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
            'Unable to cancel session.',
            500
        );
    }

    mysqli_commit($conn);

    api_success(
        [
            'session_id' => $sessionId,
            'status' => 'CANCELLED',
            'affected' => $affected,
        ],
        $affected > 0
            ? 'Session cancelled.'
            : 'Session was already in a terminal state.'
    );

} catch (Throwable $e) {

    mysqli_rollback($conn);

    error_log(
        '[SukatKalusugan] cancel_session.php: '
        . $e->getMessage()
    );

    api_error(
        'Unable to cancel session.',
        500
    );
}

<?php

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/measurement_sessions.php';

api_require_method(['GET']);

$deviceCode = api_string(
    $_GET['device_id']
        ?? $_GET['deviceCode']
        ?? $_GET['device']
        ?? 'ESP32-KIOSK-01',
    'ESP32-KIOSK-01'
);

$sessionId = api_int(
    $_GET['session_id']
        ?? $_GET['sessionId']
        ?? 0,
    0
);

if (!preg_match('/^[A-Za-z0-9_-]{3,50}$/', $deviceCode)) {
    api_error('Invalid device ID.', 400);
}

$conn = get_db_connection();

/*
|--------------------------------------------------------------------------
| EXACT SESSION LOOKUP
|--------------------------------------------------------------------------
|
| If the kiosk sends session_id, we MUST return that exact session.
|
| We must NOT simply return the latest session for the device because
| doing that can cause:
|
|   Kiosk session #3
|          ↓
|   SQL session #3
|          ↓
|   status endpoint returns session #2
|
| This was one of the causes of the session mismatch problem.
|
*/

if ($sessionId > 0) {

    $row = measurement_session_fetch_by_id_for_device(
        $conn,
        $sessionId,
        $deviceCode
    );

} else {

    /*
     * No session ID supplied.
     *
     * Only fall back to the currently active session.
     */

    $row = measurement_session_fetch_active_for_device(
        $conn,
        $deviceCode
    );
}

if (!$row) {
    api_error(
        'Measurement session not found for this device.',
        404
    );
}

/*
|--------------------------------------------------------------------------
| EXTRA SESSION SAFETY
|--------------------------------------------------------------------------
*/

$returnedSessionId = (int)($row['session_id'] ?? 0);

if ($sessionId > 0 && $returnedSessionId !== $sessionId) {

    api_error(
        'Measurement session mismatch.',
        409
    );
}

/*
|--------------------------------------------------------------------------
| RETURN EXACT SESSION
|--------------------------------------------------------------------------
*/

api_success(
    measurement_session_row_to_payload($row),
    'Measurement session loaded.'
);
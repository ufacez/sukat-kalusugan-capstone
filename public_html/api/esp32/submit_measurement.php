<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/measurement_sessions.php';
require_once __DIR__ . '/../../includes/who_calculator.php';
require_once __DIR__ . '/../../includes/firebase_sync.php';

api_require_method(['POST']);

api_require_device_key();

$payload = api_payload();

/*
|--------------------------------------------------------------------------
| REQUEST DATA
|--------------------------------------------------------------------------
*/

$deviceCode = api_string(
    $payload['device_id']
        ?? $payload['deviceCode']
        ?? $_GET['device']
        ?? 'ESP32-KIOSK-01',
    'ESP32-KIOSK-01'
);

$sessionId = api_int(
    $payload['session_id']
        ?? $payload['sessionId']
        ?? 0,
    0
);

$sourceType = api_normalize_enum(
    $payload['source_type'] ?? 'kiosk',
    ['kiosk', 'manual', 'mobile'],
    'kiosk'
);

$heightCm = api_float(
    $payload['height_cm']
        ?? $payload['height']
        ?? $payload['distance_cm']
        ?? null,
    null
);

$weightKg = api_float(
    $payload['weight_kg']
        ?? $payload['weight']
        ?? null,
    null
);

/*
|--------------------------------------------------------------------------
| WEIGHT GRAMS SUPPORT
|--------------------------------------------------------------------------
*/

if (
    $weightKg === null &&
    isset($payload['weight_g'])
) {
    $weightKg =
        api_float(
            $payload['weight_g'],
            null
        ) / 1000.0;
}

/*
|--------------------------------------------------------------------------
| HEIGHT FALLBACK
|--------------------------------------------------------------------------
*/

if (
    $heightCm === null &&
    isset($payload['height'])
) {
    $heightCm = api_float(
        $payload['height'],
        null
    );
}

/*
|--------------------------------------------------------------------------
| BASIC VALIDATION
|--------------------------------------------------------------------------
*/

$manualWeight = filter_var($payload['manual_weight'] ?? false, FILTER_VALIDATE_BOOLEAN);
$manualHeight = filter_var($payload['manual_height'] ?? false, FILTER_VALIDATE_BOOLEAN);

if (
    ($heightCm === null && !$manualHeight) ||
    ($weightKg === null && !$manualWeight)
) {
    api_error(
        'At least one measurement (height or weight) is required. If a sensor is offline, set manual_height or manual_weight to true.',
        400
    );
}

if ($heightCm === null && $weightKg === null) {
    api_error(
        'Both height and weight are missing. At least one sensor must provide data.',
        400
    );
}

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
        'Session ID is required.',
        400
    );
}

/*
|--------------------------------------------------------------------------
| FAIL SESSION HELPER
|--------------------------------------------------------------------------
*/

function fail_session(
    mysqli $conn,
    int $sessionId,
    string $message,
    int $statusCode = 400
): void {

    $stmt = mysqli_prepare(
        $conn,
        'UPDATE measurement_sessions
         SET
            status = \'ERROR\',
            error_message = ?,
            updated_at = NOW()
         WHERE id = ?
           AND status IN (
                \'START_REQUESTED\',
                \'MEASURING\'
           )'
    );

    if ($stmt !== false) {

        mysqli_stmt_bind_param(
            $stmt,
            'si',
            $message,
            $sessionId
        );

        mysqli_stmt_execute($stmt);

        mysqli_stmt_close($stmt);
    }

    mysqli_commit($conn);

    api_error(
        $message,
        $statusCode
    );
}

/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

$conn = get_db_connection();

/*
|--------------------------------------------------------------------------
| BEGIN TRANSACTION
|--------------------------------------------------------------------------
*/

mysqli_begin_transaction($conn);

/*
|--------------------------------------------------------------------------
| LOCK EXACT SESSION
|--------------------------------------------------------------------------
|
| This MUST be the exact session sent by the ESP32.
|
*/

$sessionStmt = mysqli_prepare(
    $conn,
    'SELECT
        s.id AS session_id,
        s.device_id AS device_db_id,

        d.device_code,
        d.calibration_offset_height,
        d.calibration_offset_weight,

        s.child_id,

        c.birthdate,
        c.sex,
        c.child_code,
        c.first_name AS child_first_name,
        c.last_name AS child_last_name,

        s.status,
        s.command,
        s.started_at,
        s.completed_at,
        s.expires_at,

        s.height_cm AS session_height_cm,
        s.weight_kg AS session_weight_kg,

        s.measurement_id

     FROM measurement_sessions s

     INNER JOIN devices d
        ON d.id = s.device_id

     INNER JOIN children c
        ON c.id = s.child_id

     WHERE s.id = ?
       AND d.device_code = ?

     LIMIT 1

     FOR UPDATE'
);

if ($sessionStmt === false) {

    mysqli_rollback($conn);

    api_error(
        'Unable to validate measurement session.',
        500
    );
}

mysqli_stmt_bind_param(
    $sessionStmt,
    'is',
    $sessionId,
    $deviceCode
);

mysqli_stmt_execute(
    $sessionStmt
);

$sessionResult =
    mysqli_stmt_get_result(
        $sessionStmt
    );

$sessionRow =
    $sessionResult instanceof mysqli_result
        ? mysqli_fetch_assoc($sessionResult)
        : null;

mysqli_stmt_close(
    $sessionStmt
);

/*
|--------------------------------------------------------------------------
| SESSION NOT FOUND
|--------------------------------------------------------------------------
*/

if (!is_array($sessionRow)) {

    mysqli_rollback($conn);

    api_error(
        'Measurement session not found for this device.',
        404
    );
}

/*
|--------------------------------------------------------------------------
| EXACT SESSION CHECK
|--------------------------------------------------------------------------
*/

$databaseSessionId =
    (int)(
        $sessionRow['session_id']
        ?? 0
    );

if ($databaseSessionId !== $sessionId) {

    mysqli_rollback($conn);

    api_error(
        'Session mismatch. The submitted measurement does not belong to the requested session.',
        409
    );
}

/*
|--------------------------------------------------------------------------
| EXACT DEVICE CHECK
|--------------------------------------------------------------------------
*/

$databaseDeviceCode =
    (string)(
        $sessionRow['device_code']
        ?? ''
    );

if ($databaseDeviceCode !== $deviceCode) {

    mysqli_rollback($conn);

    api_error(
        'Device does not match measurement session.',
        409
    );
}

/*
|--------------------------------------------------------------------------
| SESSION STATE
|--------------------------------------------------------------------------
*/

$sessionStatus =
    strtoupper(
        trim(
            (string)(
                $sessionRow['status']
                ?? ''
            )
        )
    );

$sessionCommand =
    strtoupper(
        trim(
            (string)(
                $sessionRow['command']
                ?? ''
            )
        )
    );

$measurementId =
    (int)(
        $sessionRow['measurement_id']
        ?? 0
    );

/*
|--------------------------------------------------------------------------
| DUPLICATE COMPLETE PROTECTION
|--------------------------------------------------------------------------
*/

if (
    $sessionStatus === 'COMPLETE' &&
    $measurementId > 0
) {

    $measurementStmt =
        mysqli_prepare(
            $conn,
            'SELECT
                id,
                child_id,
                height_cm,
                weight_kg,
                age_months,
                waz,
                haz,
                whz,
                nutritional_status,
                wfa_status,
                hfa_status,
                wfh_status,
                is_flagged,
                flag_reason,
                source_type,
                device_id,
                created_at
             FROM measurements
             WHERE id = ?
             LIMIT 1'
        );

    if ($measurementStmt !== false) {

        mysqli_stmt_bind_param(
            $measurementStmt,
            'i',
            $measurementId
        );

        mysqli_stmt_execute(
            $measurementStmt
        );

        $measurementResult =
            mysqli_stmt_get_result(
                $measurementStmt
            );

        $measurementRow =
            $measurementResult instanceof mysqli_result
                ? mysqli_fetch_assoc($measurementResult)
                : null;

        mysqli_stmt_close(
            $measurementStmt
        );

        mysqli_commit($conn);

        api_success(
            [
                'measurement_id' =>
                    (int)(
                        $measurementRow['id']
                        ?? $measurementId
                    ),

                'session_id' =>
                    $sessionId,

                'device_id' =>
                    $deviceCode,

                'child_id' =>
                    (int)(
                        $measurementRow['child_id']
                        ?? $sessionRow['child_id']
                    ),

                'height_cm' =>
                    isset($measurementRow['height_cm'])
                        ? (float)$measurementRow['height_cm']
                        : null,

                'weight_kg' =>
                    isset($measurementRow['weight_kg'])
                        ? (float)$measurementRow['weight_kg']
                        : null,

                'age_months' =>
                    isset($measurementRow['age_months'])
                        ? (int)$measurementRow['age_months']
                        : 0,

                'age_days' =>
                    isset($measurementRow['age_days'])
                        ? (int)$measurementRow['age_days']
                        : 0,

                'waz' =>
                    isset($measurementRow['waz'])
                        ? (float)$measurementRow['waz']
                        : null,

                'haz' =>
                    isset($measurementRow['haz'])
                        ? (float)$measurementRow['haz']
                        : null,

                'whz' =>
                    isset($measurementRow['whz'])
                        ? (float)$measurementRow['whz']
                        : null,

                'nutritional_status' =>
                    (string)(
                        $measurementRow['nutritional_status']
                        ?? ''
                    ),

                'wfa_status' =>
                    $measurementRow['wfa_status'] ?? null,

                'hfa_status' =>
                    $measurementRow['hfa_status'] ?? null,

                'wfh_status' =>
                    $measurementRow['wfh_status'] ?? null,

                'is_flagged' =>
                    (bool)(
                        $measurementRow['is_flagged']
                        ?? false
                    ),

                'flag_reason' =>
                    $measurementRow['flag_reason']
                    ?? null,

                'source_type' =>
                    (string)(
                        $measurementRow['source_type']
                        ?? 'kiosk'
                    ),

                'firebase_synced' =>
                    firebase_database_url() !== '',

                'duplicate' =>
                    true,
            ],
            'Measurement already saved.'
        );
    }
}

/*
|--------------------------------------------------------------------------
| CRITICAL PROCESS LOCK
|--------------------------------------------------------------------------
|
| THIS IS THE IMPORTANT FIX.
|
| The ESP32 is NOT allowed to submit merely because a stable
| measurement exists.
|
| It MUST first receive:
|
|     command = PROCESS
|
| from get_command.php.
|
| request_process.php sets this command only when the operator
| clicks "Process Measurement".
|
*/

if ($sessionStatus !== 'MEASURING') {

    mysqli_rollback($conn);

    api_error(
        'This session is not actively measuring.',
        409,
        [
            'status' => $sessionStatus,
            'command' => $sessionCommand
        ]
    );
}

if ($sessionCommand !== 'PROCESS') {

    mysqli_rollback($conn);

    api_error(
        'Measurement is not authorized for processing yet. The kiosk must click Process Measurement first.',
        409,
        [
            'status' => $sessionStatus,
            'command' => $sessionCommand,
            'session_id' => $sessionId
        ]
    );
}

/*
|--------------------------------------------------------------------------
| VALID SENSOR VALUES
|--------------------------------------------------------------------------
*/

if (
    $heightCm <= 0 ||
    $weightKg <= 0
) {

    fail_session(
        $conn,
        $sessionId,
        'Sensor readings are not ready yet. Please wait until the height and weight sensors return valid values above zero.'
    );
}

/*
|--------------------------------------------------------------------------
| DEVICE CALIBRATION
|--------------------------------------------------------------------------
|
| This MUST run before the height/weight range checks below. The raw
| sensor reading can legitimately sit outside 40-140 cm (or 2-80 kg)
| before the device's configured calibration offset is applied, and the
| operator only ever sees/expects the CALIBRATED value to make sense.
| Validating the raw, uncalibrated number here was rejecting otherwise
| valid measurements (and could equally let a bad raw reading slip
| through if calibration happened to push it into range afterward).
|
*/

$deviceCalibrationHeight =
    isset(
        $sessionRow['calibration_offset_height']
    )
        ? (float)$sessionRow[
            'calibration_offset_height'
        ]
        : 0.0;

$deviceCalibrationWeight =
    isset(
        $sessionRow['calibration_offset_weight']
    )
        ? (float)$sessionRow[
            'calibration_offset_weight'
        ]
        : 0.0;

$heightCm =
    $heightCm +
    $deviceCalibrationHeight;

$weightKg =
    $weightKg +
    $deviceCalibrationWeight;

/*
|--------------------------------------------------------------------------
| HEIGHT RANGE
|--------------------------------------------------------------------------
*/

if (
    $heightCm < 40 ||
    $heightCm > 140
) {

    fail_session(
        $conn,
        $sessionId,
        'Height must be between 40 cm and 140 cm for a valid child measurement.'
    );
}

/*
|--------------------------------------------------------------------------
| WEIGHT RANGE
|--------------------------------------------------------------------------
*/

if (
    $weightKg < 2 ||
    $weightKg > 80
) {

    fail_session(
        $conn,
        $sessionId,
        'Weight must be between 2 kg and 80 kg for a valid child measurement.'
    );
}

/*
|--------------------------------------------------------------------------
| AGE
|--------------------------------------------------------------------------
*/

$childBirthdate =
    (string)(
        $sessionRow['birthdate']
        ?? ''
    );

$childSex =
    (string)(
        $sessionRow['sex']
        ?? 'Male'
    );

$ageDays = 0;
$ageMonths = 0;

if ($childBirthdate !== '') {
    // age_days is the canonical input for the WHO calculator
    // (WAZ/HAZ day-keyed lookups + WFL/WFH cutover at 731 days).
    // age_months is a whole-number estimate derived from the day
    // count -- it's stored on the row for the eOPT Plus reports,
    // never for calculator use. Use the system "today" (matching
    // CURDATE() in the insert below) so the day count lines up
    // with the row we end up writing.
    $age = doh_age($childBirthdate) ?? ['days' => 0, 'months' => 0];
    $ageDays = (int)$age['days'];
    $ageMonths = (int)$age['months'];
}

/*
|--------------------------------------------------------------------------
| WHO CALCULATIONS
|--------------------------------------------------------------------------
*/

$metrics = null;
$waz = $haz = $whz = null;
$status = $wfaStatus = $hfaStatus = $wfhStatus = null;

if ($weightKg !== null && $heightCm !== null) {
    $metrics = calculate_who_metrics(
        $weightKg,
        $heightCm,
        $ageDays,
        $childSex
    );
    $waz = $metrics['waz'];
    $haz = $metrics['haz'];
    $whz = $metrics['whz'];
    $status = $metrics['nutritional_status'];
    $wfaStatus = $metrics['wfa_status'];
    $hfaStatus = $metrics['hfa_status'];
    $wfhStatus = $metrics['wfh_status'];
} elseif ($weightKg !== null && $heightCm === null) {
    // Weight only — compute WAZ / WFA only; no HAZ / WHZ
    $waz = calculate_waz($weightKg, $ageDays, $childSex);
    $wfaStatus = classify_wfa_status($waz);
    $status = $wfaStatus;
} elseif ($heightCm !== null && $weightKg === null) {
    // Height only — compute HAZ / HFA only; no WAZ / WHZ
    $haz = calculate_haz($heightCm, $ageDays, $childSex);
    $hfaStatus = classify_hfa_status($haz);
    $status = $hfaStatus;
}

$isFlagged =
    $metrics['is_flagged'] ? 1 : 0;

$flagReason =
    $metrics['flag_reason'];

/*
|--------------------------------------------------------------------------
| INSERT MEASUREMENT
|--------------------------------------------------------------------------
*/

$measurementInsert =
    mysqli_prepare(
        $conn,
        'INSERT INTO measurements
        (
            child_id,
            height_cm,
            weight_kg,
            age_months,
            age_days,
            measurement_date,
            source_type,
            waz,
            haz,
            whz,
            nutritional_status,
            wfa_status,
            hfa_status,
            wfh_status,
            is_flagged,
            flag_reason,
            device_id
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            CURDATE(),
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?
        )'
    );

if ($measurementInsert === false) {

    mysqli_rollback($conn);

    api_error(
        'Could not save measurement.',
        500
    );
}

$childId =
    (int)(
        $sessionRow['child_id']
        ?? 0
    );

$deviceDbId =
    (int)(
        $sessionRow['device_db_id']
        ?? 0
    );

mysqli_stmt_bind_param(
    $measurementInsert,
    'iddiisdddssssisi',
    $childId,
    $heightCm,
    $weightKg,
    $ageMonths,
    $ageDays,
    $sourceType,
    $waz,
    $haz,
    $whz,
    $status,
    $wfaStatus,
    $hfaStatus,
    $wfhStatus,
    $isFlagged,
    $flagReason,
    $deviceDbId
);

if (
    !mysqli_stmt_execute(
        $measurementInsert
    )
) {

    mysqli_stmt_close(
        $measurementInsert
    );

    mysqli_rollback($conn);

    api_error(
        'Measurement insert failed.',
        500
    );
}

$insertedMeasurementId =
    mysqli_insert_id($conn);

mysqli_stmt_close(
    $measurementInsert
);

/*
|--------------------------------------------------------------------------
| COMPLETE SESSION
|--------------------------------------------------------------------------
|
| Only COMPLETE after the measurement was actually inserted.
|
*/

$sessionUpdate =
    mysqli_prepare(
        $conn,
        'UPDATE measurement_sessions
         SET
            status = \'COMPLETE\',
            command = \'COMPLETE\',
            completed_at = NOW(),
            height_cm = ?,
            weight_kg = ?,
            measurement_id = ?,
            error_message = NULL,
            updated_at = NOW()
         WHERE id = ?
           AND status = \'MEASURING\'
           AND command = \'PROCESS\''
    );

if ($sessionUpdate === false) {

    mysqli_rollback($conn);

    api_error(
        'Could not finalize measurement session.',
        500
    );
}

mysqli_stmt_bind_param(
    $sessionUpdate,
    'ddii',
    $heightCm,
    $weightKg,
    $insertedMeasurementId,
    $sessionId
);

if (
    !mysqli_stmt_execute(
        $sessionUpdate
    )
) {

    mysqli_stmt_close(
        $sessionUpdate
    );

    mysqli_rollback($conn);

    api_error(
        'Could not finalize measurement session.',
        500
    );
}

$sessionAffected =
    mysqli_stmt_affected_rows(
        $sessionUpdate
    );

mysqli_stmt_close(
    $sessionUpdate
);

if ($sessionAffected <= 0) {

    mysqli_rollback($conn);

    api_error(
        'Measurement was saved but the session could not be finalized safely.',
        409
    );
}

mysqli_commit($conn);

/*
|--------------------------------------------------------------------------
| FIREBASE PAYLOAD
|--------------------------------------------------------------------------
*/

$measurementPayload = [

    'measurement_id' =>
        $insertedMeasurementId,

    'session_id' =>
        $sessionId,

    'child_id' =>
        $childId,

    'child_code' =>
        (string)(
            $sessionRow['child_code']
            ?? ''
        ),

    'child_name' =>
        trim(
            (string)(
                $sessionRow['child_first_name']
                ?? ''
            )
            . ' ' .
            (string)(
                $sessionRow['child_last_name']
                ?? ''
            )
        ),

    'height_cm' =>
        round(
            $heightCm,
            2
        ),

    'weight_kg' =>
        round(
            $weightKg,
            3
        ),

    'age_months' =>
        $ageMonths,

    'waz' =>
        $waz,

    'haz' =>
        $haz,

    'whz' =>
        $whz,

    'nutritional_status' =>
        $status,

    'wfa_status' =>
        $wfaStatus,

    'hfa_status' =>
        $hfaStatus,

    'wfh_status' =>
        $wfhStatus,

    'is_flagged' =>
        (bool)$isFlagged,

    'flag_reason' =>
        $flagReason,

    'source_type' =>
        $sourceType,

    'device_id' =>
        $deviceCode,

    'status' =>
        'COMPLETE',

    'timestamp' =>
        date('c'),
];

/*
|--------------------------------------------------------------------------
| FIREBASE
|--------------------------------------------------------------------------
*/

push_latest_measurement(
    $deviceCode,
    $measurementPayload
);

/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

api_success(
    [
        'measurement_id' =>
            $insertedMeasurementId,

        'session_id' =>
            $sessionId,

        'device_id' =>
            $deviceCode,

        'child_id' =>
            $childId,

        'height_cm' =>
            round(
                $heightCm,
                2
            ),

        'weight_kg' =>
            round(
                $weightKg,
                3
            ),

        'age_months' =>
            $ageMonths,

        'waz' =>
            $waz,

        'haz' =>
            $haz,

        'whz' =>
            $whz,

        'nutritional_status' =>
            $status,

        'wfa_status' =>
            $wfaStatus,

        'hfa_status' =>
            $hfaStatus,

        'wfh_status' =>
            $wfhStatus,

        'is_flagged' =>
            (bool)$isFlagged,

        'flag_reason' =>
            $flagReason,

        'source_type' =>
            $sourceType,

        'firebase_synced' =>
            firebase_database_url() !== '',

        'manual_weight' =>
            $manualWeight,

        'manual_height' =>
            $manualHeight,
    ],
    'Measurement saved successfully.'
);
<?php

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/measurement_sessions.php';
require_once __DIR__ . '/../../includes/who_calculator.php';
require_once __DIR__ . '/../../includes/firebase_sync.php';

api_require_method(['POST']);

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

if (
    $heightCm === null ||
    $weightKg === null
) {
    api_error(
        'Height and weight are required.',
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
| LOCK EXACT SESSION
|--------------------------------------------------------------------------
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

    api_error(
        'Unable to validate measurement session.',
        500
    );
}

mysqli_begin_transaction($conn);

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
| VERIFY EXACT SESSION
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
| VERIFY DEVICE
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
| SESSION STATUS
|--------------------------------------------------------------------------
*/

$sessionStatus =
    strtoupper(
        (string)(
            $sessionRow['status']
            ?? ''
        )
    );

$measurementId =
    (int)(
        $sessionRow['measurement_id']
        ?? 0
    );

/*
|--------------------------------------------------------------------------
| ALREADY COMPLETE
|--------------------------------------------------------------------------
|
| This prevents duplicate ESP32 retries from creating another
| measurement record.
|
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
| SESSION MUST BE ACTIVE
|--------------------------------------------------------------------------
*/

if (
    !in_array(
        $sessionStatus,
        [
            'START_REQUESTED',
            'MEASURING'
        ],
        true
    )
) {

    mysqli_rollback($conn);

    api_error(
        'This session is not active.',
        409
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

$ageMonths = 0;

if ($childBirthdate !== '') {

    try {

        $birth =
            new DateTimeImmutable(
                $childBirthdate
            );

        $today =
            new DateTimeImmutable(
                'today'
            );

        $diff =
            $birth->diff(
                $today
            );

        $ageMonths =
            ($diff->y * 12) +
            $diff->m;

    } catch (Throwable $e) {

        $ageMonths = 0;
    }
}

/*
|--------------------------------------------------------------------------
| DEVICE CALIBRATION
|--------------------------------------------------------------------------
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
| WHO CALCULATIONS
|--------------------------------------------------------------------------
*/

$metrics =
    calculate_who_metrics(
        $weightKg,
        $heightCm,
        $ageMonths,
        $childSex
    );

$waz =
    $metrics['waz'];

$haz =
    $metrics['haz'];

$whz =
    $metrics['whz'];

$status =
    $metrics['nutritional_status'];

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
            measurement_date,
            source_type,
            waz,
            haz,
            whz,
            nutritional_status,
            device_id
        )
        VALUES
        (
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
    'iddisdddsi',
    $childId,
    $heightCm,
    $weightKg,
    $ageMonths,
    $sourceType,
    $waz,
    $haz,
    $whz,
    $status,
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
*/

$sessionUpdate =
    mysqli_prepare(
        $conn,
        'UPDATE measurement_sessions
         SET
            status = \'COMPLETE\',
            completed_at = NOW(),
            height_cm = ?,
            weight_kg = ?,
            measurement_id = ?,
            error_message = NULL,
            updated_at = NOW()
         WHERE id = ?
           AND status IN (
                \'START_REQUESTED\',
                \'MEASURING\'
           )'
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

mysqli_stmt_close(
    $sessionUpdate
);

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

    'source_type' =>
        $sourceType,

    'device_id' =>
        $deviceCode,

    'status' =>
        'COMPLETE',

    'timestamp' =>
        date(
            'c'
        ),
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

        'source_type' =>
            $sourceType,

        'firebase_synced' =>
            firebase_database_url() !== '',
    ],
    'Measurement saved successfully.'
);
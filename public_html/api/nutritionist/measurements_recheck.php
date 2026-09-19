<?php

declare(strict_types=1);

/**
 * measurements_recheck.php
 *
 * Nutritionist API — records an anytime double-check (recheck) measurement.
 *
 * RECHECK is verification-only:
 *   - allowed anytime, including the same date as an existing measurement;
 *   - does NOT count toward monthly/quarterly period completion
 *     (only ROUTINE/OVERRIDE rows count);
 *   - keeps history: the previous reading stays, this row links back via
 *     recheck_of_measurement_id and shows as the verified value.
 *
 * POST { child_id, height_cm, weight_kg, recheck_reason, measurement_date? }
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/who_calculator.php';
require_once __DIR__ . '/../../includes/audit_logger.php';

api_require_method(['POST']);

$user = api_require_staff_session(['admin', 'nutritionist']);

if (($user['role'] ?? '') !== 'admin') {
    $accessLevel = $user['access_level'] ?? 'full';
    if ($accessLevel === 'readonly') {
        api_error('You do not have permission to record recheck measurements.', 403);
    }
}

$payload = api_payload();

$childId = api_int($payload['child_id'] ?? 0, 0);
$heightCm = api_float($payload['height_cm'] ?? null, null);
$weightKg = api_float($payload['weight_kg'] ?? null, null);
$recheckReason = trim((string)($payload['recheck_reason'] ?? ''));

if ($childId <= 0) {
    api_error('Please select a child.', 422);
}

if ($heightCm === null || $weightKg === null) {
    api_error('Height and weight are required.', 422);
}

if (!is_finite($heightCm) || !is_finite($weightKg)) {
    api_error('Height and weight must be valid numbers.', 422);
}

if ($recheckReason === '') {
    api_error('A reason for the recheck is required (e.g. child moved during scan, unstable reading).', 422);
}

if (strlen($recheckReason) > 255) {
    api_error('Recheck reason must be 255 characters or fewer.', 422);
}

$heightCm = round($heightCm, 2);
$weightKg = round($weightKg, 3);

$measurementDate = api_string($payload['measurement_date'] ?? '', '');

if ($measurementDate === '') {
    $measurementDate = date('Y-m-d');
}

$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $measurementDate);

if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $measurementDate) {
    api_error('Measurement date must be a valid date in YYYY-MM-DD format.', 422);
}

$today = new DateTimeImmutable('today');

if ($parsedDate > $today) {
    api_error('Measurement date cannot be in the future.', 422);
}

$conn = get_db_connection();

// Recheck columns require migration 20260914_recheck_measurements.sql.
$measCols = [];
try {
    $colRes = $conn->query('SHOW COLUMNS FROM measurements');
    if ($colRes) {
        while ($colRow = $colRes->fetch_assoc()) {
            $measCols[] = (string)$colRow['Field'];
        }
    }
} catch (Throwable $e) {
    error_log('[SukatKalusugan] measurements_recheck column probe failed: ' . $e->getMessage());
}

if (!in_array('measurement_type', $measCols, true)
    || !in_array('recheck_reason', $measCols, true)
    || !in_array('recheck_of_measurement_id', $measCols, true)
) {
    api_error('Recheck feature needs DB migration 20260914_recheck_measurements.sql applied.', 500);
}

// Fetch child
$childStmt = mysqli_prepare(
    $conn,
    'SELECT id, child_code, first_name, middle_name, last_name, birthdate, sex, barangay_id
     FROM children WHERE id = ? LIMIT 1'
);

if ($childStmt === false) {
    api_error('Unable to verify the child record.', 500);
}

mysqli_stmt_bind_param($childStmt, 'i', $childId);
mysqli_stmt_execute($childStmt);
$childResult = mysqli_stmt_get_result($childStmt);
$child = $childResult instanceof mysqli_result ? mysqli_fetch_assoc($childResult) : null;
mysqli_stmt_close($childStmt);

if (!is_array($child)) {
    api_error('Child not found.', 404);
}

// Barangay scope check
if (($user['role'] ?? '') !== 'admin') {
    $userBarangayId = $user['barangay_id'] ?? null;
    if ($userBarangayId !== null && $userBarangayId !== '' && (int)$userBarangayId !== (int)($child['barangay_id'] ?? 0)) {
        api_error('You can only record measurements for children under your assigned barangay.', 403);
    }
}

$childBirthdate = trim((string)$child['birthdate']);
$childSex = (string)$child['sex'];

if ($childBirthdate === '') {
    api_error('This child has no birthdate on record.', 422);
}

try {
    $birthDate = new DateTimeImmutable($childBirthdate);
} catch (Exception) {
    api_error('This child has an invalid birthdate on record.', 422);
}

if ($birthDate > $parsedDate) {
    api_error('Measurement date cannot be before the child\'s birthdate.', 422);
}

$ageDays = (int)$birthDate->diff($parsedDate)->format('%r%a');
if ($ageDays < 0) {
    $ageDays = 0;
}

$ageMonths = intdiv($ageDays, 30);

if ($ageMonths >= 60) {
    api_error('This child is ' . $ageMonths . ' months old and has aged out of the eOPT Plus monitoring program.', 422);
}

// Link back to the reading being verified: prefer today's latest row
// (any type), else the latest row overall. History keeps both.
$recheckOf = admin_fetch_one(
    "SELECT id FROM measurements WHERE child_id = ? AND measurement_date = ? ORDER BY id DESC LIMIT 1",
    'is',
    [$childId, $measurementDate]
);

if ($recheckOf === null) {
    $recheckOf = admin_fetch_one(
        "SELECT id FROM measurements WHERE child_id = ? ORDER BY measurement_date DESC, id DESC LIMIT 1",
        'i',
        [$childId]
    );
}

$recheckOfId = $recheckOf !== null ? (int)($recheckOf['id'] ?? 0) : null;
if ($recheckOfId !== null && $recheckOfId <= 0) {
    $recheckOfId = null;
}

// WHO calculations (canonical)
$metrics = calculate_who_metrics($weightKg, $heightCm, $ageDays, $childSex);

$waz = $metrics['waz'];
$haz = $metrics['haz'];
$whz = $metrics['whz'];
$status = $metrics['nutritional_status'];
$wfaStatus = $metrics['wfa_status'];
$hfaStatus = $metrics['hfa_status'];
$wfhStatus = $metrics['wfh_status'];
$isFlagged = $metrics['is_flagged'] ? 1 : 0;
$flagReason = $metrics['flag_reason'];
$recordedBy = (int)($user['id'] ?? 0);

// Insert recheck measurement. source_type stays 'manual' (kiosk/manual/mobile
// distinction lives there); measurement_type carries ROUTINE/OVERRIDE/RECHECK.
$insertStmt = mysqli_prepare(
    $conn,
    "INSERT INTO measurements
        (
            child_id, height_cm, weight_kg, age_months, age_days,
            measurement_date, source_type, measurement_type,
            override_reason, override_authority,
            recheck_reason, recheck_of_measurement_id,
            waz, haz, whz, nutritional_status,
            wfa_status, hfa_status, wfh_status,
            is_flagged, flag_reason, device_id, recorded_by
        )
     VALUES
        (?, ?, ?, ?, ?, ?, 'manual', 'RECHECK', NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)"
);

if ($insertStmt === false) {
    error_log('[SukatKalusugan] measurements_recheck prepare failed: ' . mysqli_error($conn));
    api_error('Could not save the recheck measurement.', 500);
}

mysqli_stmt_bind_param(
    $insertStmt,
    'iddiissidddsssssisi',
    $childId,
    $heightCm,
    $weightKg,
    $ageMonths,
    $ageDays,
    $measurementDate,
    $recheckReason,
    $recheckOfId,
    $waz,
    $haz,
    $whz,
    $status,
    $wfaStatus,
    $hfaStatus,
    $wfhStatus,
    $isFlagged,
    $flagReason,
    $recordedBy
);

if (!mysqli_stmt_execute($insertStmt)) {
    error_log('[SukatKalusugan] measurements_recheck execute failed: ' . mysqli_stmt_error($insertStmt));
    mysqli_stmt_close($insertStmt);
    api_error('Could not save the recheck measurement.', 500);
}

$measurementId = (int)mysqli_insert_id($conn);
mysqli_stmt_close($insertStmt);

$childName = trim(
    (string)$child['first_name']
    . ' '
    . (string)($child['middle_name'] ?? '')
    . ' '
    . (string)$child['last_name']
);

log_action(
    $recordedBy,
    'MEASUREMENT_RECHECK',
    'info',
    sprintf(
        'Recheck measurement #%d recorded for %s (%s): %.2f kg / %.2f cm @ %d months | WAZ %.2f, HAZ %.2f, WHZ %.2f | %s | Verifies #%s | Reason: %s (due schedule untouched)',
        $measurementId,
        $childName,
        (string)$child['child_code'],
        $weightKg,
        $heightCm,
        $ageMonths,
        $waz,
        $haz,
        $whz,
        (string)$status,
        $recheckOfId !== null ? (string)$recheckOfId : 'none',
        $recheckReason
    )
);

// Intentionally no schedule sync: rechecks are verification-only and
// period completion derives from ROUTINE/OVERRIDE rows inside the
// month/quarter, so there is no next_due to report.
$dueCheck = ['next_due' => null];

api_success(
    [
        'measurement_id' => $measurementId,
        'child_id' => $childId,
        'child_code' => (string)$child['child_code'],
        'child_name' => $childName,
        'measurement_date' => $measurementDate,
        'height_cm' => round($heightCm, 2),
        'weight_kg' => round($weightKg, 3),
        'age_months' => $ageMonths,
        'sex' => $childSex,
        'waz' => $waz,
        'haz' => $haz,
        'whz' => $whz,
        'nutritional_status' => $status,
        'wfa_status' => $wfaStatus,
        'hfa_status' => $hfaStatus,
        'wfh_status' => $wfhStatus,
        'is_flagged' => $isFlagged === 1,
        'flag_reason' => $flagReason,
        'source_type' => 'manual',
        'measurement_type' => 'RECHECK',
        'recheck_reason' => $recheckReason,
        'recheck_of_measurement_id' => $recheckOfId,
        'recorded_by' => $recordedBy,
        'due_unchanged' => true,
        'next_due' => $dueCheck['next_due'] ?? null,
    ],
    'Recheck saved. Due schedule unchanged.'
);

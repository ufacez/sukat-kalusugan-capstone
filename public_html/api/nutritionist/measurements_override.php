<?php

declare(strict_types=1);

/**
 * measurements_override.php
 *
 * Nutritionist API — records an exceptional (override) measurement outside
 * the normal kiosk schedule. Requires authorization, reason, and creates
 * a measurement with measurement_type = 'OVERRIDE'.
 *
 * POST { child_id, height_cm, weight_kg, override_reason, measurement_date }
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/who_calculator.php';
require_once __DIR__ . '/../../includes/audit_logger.php';
require_once __DIR__ . '/../../includes/followup_scheduler.php';

api_require_method(['POST']);

$user = api_require_staff_session(['admin', 'nutritionist']);

if (($user['role'] ?? '') !== 'admin') {
    $accessLevel = $user['access_level'] ?? 'full';
    if ($accessLevel === 'readonly') {
        api_error('You do not have permission to record override measurements.', 403);
    }
}

$payload = api_payload();

$childId = api_int($payload['child_id'] ?? 0, 0);
$heightCm = api_float($payload['height_cm'] ?? null, null);
$weightKg = api_float($payload['weight_kg'] ?? null, null);
$overrideReason = trim((string)($payload['override_reason'] ?? ''));

if ($childId <= 0) {
    api_error('Please select a child.', 422);
}

if ($heightCm === null || $weightKg === null) {
    api_error('Height and weight are required.', 422);
}

if (!is_finite($heightCm) || !is_finite($weightKg)) {
    api_error('Height and weight must be valid numbers.', 422);
}

if ($overrideReason === '') {
    api_error('A reason for the override measurement is required.', 422);
}

if (strlen($overrideReason) > 255) {
    api_error('Override reason must be 255 characters or fewer.', 422);
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

// Duplicate date check
$dupStmt = mysqli_prepare($conn, 'SELECT COUNT(*) FROM measurements WHERE child_id = ? AND measurement_date = ?');
mysqli_stmt_bind_param($dupStmt, 'is', $childId, $measurementDate);
mysqli_stmt_execute($dupStmt);
$dupResult = mysqli_stmt_get_result($dupStmt);
$dupCount = $dupResult ? (int)mysqli_fetch_row($dupResult)[0] : 0;
mysqli_stmt_close($dupStmt);

if ($dupCount > 0) {
    api_error('A measurement for this child already exists on ' . $measurementDate . '.', 422);
}

// WHO calculations
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
$authorityName = (string)($user['name'] ?? '');

// Insert override measurement
$insertStmt = mysqli_prepare(
    $conn,
    "INSERT INTO measurements
        (
            child_id, height_cm, weight_kg, age_months, age_days,
            measurement_date, source_type, measurement_type,
            override_reason, override_authority,
            waz, haz, whz, nutritional_status,
            wfa_status, hfa_status, wfh_status,
            is_flagged, flag_reason, device_id, recorded_by
        )
     VALUES
        (?, ?, ?, ?, ?, ?, 'manual', 'OVERRIDE', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)"
);

if ($insertStmt === false) {
    error_log('[SukatKalusugan] measurements_override prepare failed: ' . mysqli_error($conn));
    api_error('Could not save the override measurement.', 500);
}

mysqli_stmt_bind_param(
    $insertStmt,
    'iddiissssdsssssiii',
    $childId,
    $heightCm,
    $weightKg,
    $ageMonths,
    $ageDays,
    $measurementDate,
    $overrideReason,
    $authorityName,
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
    error_log('[SukatKalusugan] measurements_override execute failed: ' . mysqli_stmt_error($insertStmt));
    mysqli_stmt_close($insertStmt);
    api_error('Could not save the override measurement.', 500);
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
    'MEASUREMENT_OVERRIDE',
    'info',
    sprintf(
        'Override measurement #%d recorded for %s (%s): %.2f kg / %.2f cm @ %d months | WAZ %.2f, HAZ %.2f, WHZ %.2f | %s | Reason: %s',
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
        $overrideReason
    )
);

// Trigger follow-up sync to schedule next measurement
$followupSync = followup_sync_for_child($childId);

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
        'measurement_type' => 'OVERRIDE',
        'override_reason' => $overrideReason,
        'override_authority' => $authorityName,
        'recorded_by' => $recordedBy,
        'followup' => [
            'generated' => (int)$followupSync['generated'],
            'completed' => (int)$followupSync['completed'],
            'recategorized' => (int)($followupSync['recategorized'] ?? 0),
            'track' => $followupSync['track'],
            'category' => $followupSync['category'],
        ],
    ],
    'Override measurement saved successfully.'
);

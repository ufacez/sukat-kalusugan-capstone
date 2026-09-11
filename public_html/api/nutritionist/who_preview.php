<?php

declare(strict_types=1);

/**
 * api/nutritionist/who_preview.php
 *
 * Live WHO assessment preview for the manual measurement form. Read-only:
 * no rows are written, no audit, no Firebase. Uses the canonical
 * calculate_who_metrics() so the preview numbers always match what save
 * will produce.
 *
 * GET ?child_id=&weight_kg=&height_cm=&measurement_date=YYYY-MM-DD
 * → {waz, haz, whz, nutritional_status, wfa_status, hfa_status,
 *    wfh_status, is_flagged, flag_reason, age_days, age_months}
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/who_calculator.php';

api_require_method(['GET']);

$user = api_require_staff_session(['admin', 'nutritionist']);

$childId = api_int($_GET['child_id'] ?? null, 0);
$weightKg = api_float($_GET['weight_kg'] ?? null, null);
$heightCm = api_float($_GET['height_cm'] ?? null, null);
$measurementDate = api_string($_GET['measurement_date'] ?? '', '');

if ($childId <= 0) {
    api_error('Please select a child.', 422);
}

if ($weightKg === null || $heightCm === null || !is_finite($weightKg) || !is_finite($heightCm) || $weightKg <= 0 || $heightCm <= 0) {
    api_error('Enter a valid weight and height to preview the assessment.', 422);
}

if ($measurementDate === '') {
    $measurementDate = date('Y-m-d');
}

$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $measurementDate);

if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $measurementDate) {
    api_error('Measurement date must be a valid date in YYYY-MM-DD format.', 422);
}

$conn = get_db_connection();

$childStmt = mysqli_prepare(
    $conn,
    'SELECT id, birthdate, sex, barangay_id
     FROM children
     WHERE id = ?
     LIMIT 1'
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

if (($user['role'] ?? '') !== 'admin') {
    $userBarangayId = $user['barangay_id'] ?? null;

    if (
        $userBarangayId !== null
        && $userBarangayId !== ''
        && (int)$userBarangayId !== (int)($child['barangay_id'] ?? 0)
    ) {
        api_error('You can only preview measurements for children under your assigned barangay.', 403);
    }
}

$childBirthdate = trim((string)$child['birthdate']);

if ($childBirthdate === '') {
    api_error('This child has no birthdate on record, so WHO z-scores cannot be computed.', 422);
}

try {
    $birthDate = new DateTimeImmutable($childBirthdate);
} catch (Exception) {
    api_error('This child has an invalid birthdate on record.', 422);
}

if ($birthDate > $parsedDate) {
    api_error('Measurement date cannot be before the child\'s birthdate.', 422);
}

// age_days is the canonical calculator input (mirrors measurements_create.php).
$ageDays = (int)$birthDate->diff($parsedDate)->format('%r%a');

if ($ageDays < 0) {
    $ageDays = 0;
}

$ageMonths = intdiv($ageDays, 30);

if ($ageMonths >= 60) {
    api_error('This child is ' . $ageMonths . ' months old and has aged out of the eOPT Plus monitoring program (maximum 59 months).', 422);
}

$metrics = calculate_who_metrics(round($weightKg, 3), round($heightCm, 2), $ageDays, (string)$child['sex']);

api_success([
    'waz' => $metrics['waz'],
    'haz' => $metrics['haz'],
    'whz' => $metrics['whz'],
    'nutritional_status' => $metrics['nutritional_status'],
    'wfa_status' => $metrics['wfa_status'],
    'hfa_status' => $metrics['hfa_status'],
    'wfh_status' => $metrics['wfh_status'],
    'is_flagged' => $metrics['is_flagged'],
    'flag_reason' => $metrics['flag_reason'],
    'age_days' => $ageDays,
    'age_months' => $ageMonths,
]);

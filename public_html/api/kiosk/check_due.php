<?php

declare(strict_types=1);

/**
 * check_due.php
 *
 * Kiosk API endpoint — checks whether a child may be measured.
 *
 * Period-based monitoring has no exact due dates: any active child aged
 * 0-59 months may be measured at any time (the Monitoring List only
 * tracks whether a measurement fell inside the month/quarter). This
 * endpoint therefore answers "eligible", keeping the kiosk UI flow and
 * the response shape unchanged.
 *
 * POST { child_id, device_id }
 * GET  ?child_id=ID&device_id=CODE
 *
 * Returns:
 *   { success, data: { is_due, next_due, reason, monitoring_status,
 *     already_measured_today, can_recheck } }
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/audit_logger.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = api_payload();
    $childId = api_int($payload['child_id'] ?? 0, 0);
    $deviceCode = api_string($payload['device_id'] ?? $payload['device'] ?? '', '');
} else {
    $childId = api_int($_GET['child_id'] ?? 0, 0);
    $deviceCode = api_string($_GET['device_id'] ?? $_GET['device'] ?? '', '');
}

if ($childId <= 0) {
    api_error('A valid child ID is required.', 400);
}

// Validate device if provided (barangay scope check)
if ($deviceCode !== '') {
    $conn = get_db_connection();

    $device = admin_fetch_one(
        "SELECT id, device_code, barangay_id FROM devices WHERE device_code = ? LIMIT 1",
        's',
        [$deviceCode]
    );

    if ($device === null) {
        api_error('Device not found: ' . $deviceCode, 404);
    }

    // Check barangay scope: if device has a barangay assigned,
    // the child must be in that barangay
    if ((int)($device['barangay_id'] ?? 0) > 0) {
        $child = admin_fetch_one(
            "SELECT id, barangay_id FROM children WHERE id = ? AND status = 'active' LIMIT 1",
            'i',
            [$childId]
        );

        if ($child !== null && (int)$child['barangay_id'] !== (int)$device['barangay_id']) {
            api_error(
                'This child is not authorized for measurement at this kiosk. '
                . 'Child belongs to a different barangay.',
                403
            );
        }
    }
}

// Eligibility: active child aged 0-59 months may always be measured.
$childRow = admin_fetch_one(
    "SELECT id, birthdate FROM children WHERE id = ? AND status = 'active' LIMIT 1",
    'i',
    [$childId]
);

if ($childRow === null) {
    api_error('Child not found or inactive.', 404);
}

$ageMonths = 0;
try {
    $birth = new DateTimeImmutable((string)$childRow['birthdate']);
    $diff = $birth->diff(new DateTimeImmutable('today'));
    $ageMonths = $diff->y * 12 + $diff->m;
} catch (Exception) {
    $ageMonths = 0;
}

if ($ageMonths > 59) {
    log_action(
        null,
        'MEASUREMENT_CHECK_NOT_DUE',
        'info',
        sprintf('Kiosk eligibility check for child #%d: NOT ELIGIBLE (aged out at %d months).', $childId, $ageMonths)
    );

    api_success([
        'is_due' => false,
        'next_due' => null,
        'reason' => 'Child is ' . $ageMonths . ' months old — aged out of eOPT coverage (maximum 59 months).',
        'monitoring_status' => 'routine',
        'already_measured_today' => false,
        'can_recheck' => true,
    ]);
}

// already_measured_today lets the kiosk offer "Sukatin Ulit" (recheck)
// instead of a dead-end screen: a recheck is allowed anytime.
$alreadyMeasuredToday = false;
try {
    $todayRow = admin_fetch_one(
        "SELECT id FROM measurements WHERE child_id = ? AND measurement_date = CURDATE() AND measurement_type IN ('ROUTINE','OVERRIDE') LIMIT 1",
        'i',
        [$childId]
    );
    $alreadyMeasuredToday = ($todayRow !== null);
} catch (Throwable $e) {
    $alreadyMeasuredToday = false;
}

api_success([
    'is_due' => true,
    'next_due' => null,
    'reason' => 'Eligible — period-based monitoring allows measurement at any time.',
    'monitoring_status' => 'routine',
    'already_measured_today' => $alreadyMeasuredToday,
    'can_recheck' => true,
]);

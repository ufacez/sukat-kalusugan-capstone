<?php

declare(strict_types=1);

/**
 * check_due.php
 *
 * Kiosk API endpoint — checks whether a child is due for measurement today.
 * Called by the kiosk UI when an operator selects a child, before starting
 * a measurement session. Backend is the final authority.
 *
 * POST { child_id, device_id }
 * GET  ?child_id=ID&device_id=CODE
 *
 * Returns:
 *   { success, data: { is_due, next_due, reason, monitoring_status } }
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/followup_scheduler.php';
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

// Check if child is due for measurement today
$dueCheck = followup_is_due_today($childId);

// Audit log rejections (not due)
if (!$dueCheck['is_due']) {
    log_action(
        null,
        'MEASUREMENT_CHECK_NOT_DUE',
        'info',
        sprintf(
            'Kiosk due-date check for child #%d: NOT DUE. %s',
            $childId,
            $dueCheck['reason']
        )
    );
}

api_success([
    'is_due' => $dueCheck['is_due'],
    'next_due' => $dueCheck['next_due'],
    'reason' => $dueCheck['reason'],
    'monitoring_status' => $dueCheck['monitoring_status'],
]);

<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth_middleware.php';
require_once __DIR__ . '/../../includes/followup_scheduler.php';

start_secure_session();
$user = current_user();

if ($user === null || !in_array($user['role'] ?? '', ['admin', 'nutritionist'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// POST = set status; GET = redirect back to child page (no-op)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $childId = (int)($_GET['child_id'] ?? 0);
    header('Location: ' . app_url('/nutritionist/followup_child.php?id=' . $childId));
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$childId = (int)($input['child_id'] ?? 0);
$monitoringStatus = (string)($input['monitoring_status'] ?? 'routine');
$customIntervalDays = $input['custom_interval_days'] !== null ? (int)$input['custom_interval_days'] : null;
$customReason = trim((string)($input['custom_reason'] ?? $input['reason'] ?? ''));

if (!in_array($monitoringStatus, ['routine', 'special', 'sick', 'other'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid monitoring status.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($childId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Child ID is required.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($monitoringStatus !== 'routine') {
    if ($customIntervalDays === null || $customIntervalDays < 7) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Custom interval must be at least 7 days.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($customReason === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Reason is required for special monitoring.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$conn = get_db_connection();

// Verify child exists and is accessible
$childCheck = mysqli_prepare($conn, 'SELECT id, barangay_id FROM children WHERE id = ? AND status = ? LIMIT 1');
if ($childCheck === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$activeStatus = 'active';
mysqli_stmt_bind_param($childCheck, 'is', $childId, $activeStatus);
mysqli_stmt_execute($childCheck);
$childResult = mysqli_stmt_get_result($childCheck);
$child = $childResult instanceof mysqli_result ? mysqli_fetch_assoc($childResult) : null;
mysqli_stmt_close($childCheck);

if (!is_array($child)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Child not found.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Scope check for nutritionist
if (($user['role'] ?? '') !== 'admin') {
    $userBarangayId = $user['barangay_id'] ?? null;
    if ($userBarangayId !== null && $userBarangayId !== '' && (int)$userBarangayId !== (int)$child['barangay_id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You can only update children under your assigned barangay.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$setBy = (int)$user['id'];
$staffName = (string)($user['name'] ?? '');

$result = followup_set_monitoring_status(
    $childId,
    $monitoringStatus,
    $monitoringStatus === 'routine' ? null : $customIntervalDays,
    $monitoringStatus === 'routine' ? null : $customReason,
    $setBy,
    $staffName
);

if ($result['success']) {
    // Re-sync follow-up schedule after monitoring status change
    followup_sync_for_child($childId);

    if (wants_json_response()) {
        echo json_encode([
            'success' => true,
            'message' => 'Monitoring status updated successfully.',
            'data' => $result,
        ], JSON_UNESCAPED_UNICODE);
    } else {
        header('Location: ' . app_url('/nutritionist/followup_child.php?id=' . $childId));
        exit;
    }
} else {
    http_response_code(500);
    if (wants_json_response()) {
        echo json_encode(['success' => false, 'message' => 'Failed to update monitoring status.'], JSON_UNESCAPED_UNICODE);
    } else {
        header('Location: ' . app_url('/nutritionist/followup_child.php?id=' . $childId . '&error=1'));
        exit;
    }
}

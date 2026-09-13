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

// Plain form POSTs (no-JS fallback) get redirects back to the child page;
// fetch callers get JSON. Mirrors the forgot-password response pattern.
function monitoring_respond(bool $success, string $message, int $statusCode, int $childId, array $data = []): void
{
    if (wants_json_response()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        $payload = ['success' => $success, 'message' => $message];
        if ($data !== []) {
            $payload['data'] = $data;
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $param = $success ? 'notice' : 'error';
    header('Location: ' . app_url('/nutritionist/followup_child.php?id=' . $childId . '&' . $param . '=' . urlencode($message)));
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
$customIntervalRaw = $input['custom_interval_days'] ?? null;
$customIntervalDays = ($customIntervalRaw !== null && $customIntervalRaw !== '') ? (int)$customIntervalRaw : null;
$customReason = trim((string)($input['custom_reason'] ?? $input['reason'] ?? ''));

if (!in_array($monitoringStatus, ['routine', 'special', 'sick', 'other'], true)) {
    monitoring_respond(false, 'Invalid monitoring status.', 422, $childId);
}

if ($childId <= 0) {
    monitoring_respond(false, 'Child ID is required.', 422, $childId);
}

if ($monitoringStatus !== 'routine') {
    if ($customIntervalDays === null || $customIntervalDays < 7) {
        monitoring_respond(false, 'Custom interval must be at least 7 days.', 422, $childId);
    }
    if ($customReason === '') {
        monitoring_respond(false, 'Reason is required for special monitoring.', 422, $childId);
    }
}

$conn = get_db_connection();

// Verify child exists and is accessible
$childCheck = mysqli_prepare($conn, 'SELECT id, barangay_id FROM children WHERE id = ? AND status = ? LIMIT 1');
if ($childCheck === false) {
    monitoring_respond(false, 'Database error.', 500, $childId);
}
$activeStatus = 'active';
mysqli_stmt_bind_param($childCheck, 'is', $childId, $activeStatus);
mysqli_stmt_execute($childCheck);
$childResult = mysqli_stmt_get_result($childCheck);
$child = $childResult instanceof mysqli_result ? mysqli_fetch_assoc($childResult) : null;
mysqli_stmt_close($childCheck);

if (!is_array($child)) {
    monitoring_respond(false, 'Child not found.', 404, $childId);
}

// Scope check for nutritionist
if (($user['role'] ?? '') !== 'admin') {
    $userBarangayId = $user['barangay_id'] ?? null;
    if ($userBarangayId !== null && $userBarangayId !== '' && (int)$userBarangayId !== (int)$child['barangay_id']) {
        monitoring_respond(false, 'You can only update children under your assigned barangay.', 403, $childId);
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

    monitoring_respond(true, 'Monitoring status updated successfully.', 200, $childId, $result);
} else {
    monitoring_respond(false, 'Failed to update monitoring status.', 500, $childId);
}

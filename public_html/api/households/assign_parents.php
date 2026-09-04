<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth_middleware.php';
require_once __DIR__ . '/../../includes/audit_logger.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
$user = current_user();

if (($user['type'] ?? '') !== 'staff' || !in_array($user['role'] ?? '', ['admin', 'nutritionist'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$householdId = isset($_POST['household_id']) ? (int)$_POST['household_id'] : 0;
$parentIds = $_POST['parent_ids'] ?? [];
if (!is_array($parentIds)) {
    $parentIds = [$parentIds];
}
$parentIds = array_values(array_unique(array_filter(array_map('intval', $parentIds), static fn($v) => $v > 0)));

if ($householdId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Household ID is required.']);
    exit;
}
if (empty($parentIds)) {
    echo json_encode(['success' => false, 'message' => 'No parents selected.']);
    exit;
}

$conn = get_db_connection();

$hh = admin_fetch_one(
    'SELECT id, barangay_id, local_area_id FROM households WHERE id = ? AND status = "active" LIMIT 1',
    'i',
    [$householdId]
);
if (!$hh) {
    echo json_encode(['success' => false, 'message' => 'Household not found.']);
    exit;
}

$isBarangayAdmin = ($user['role'] ?? '') === 'admin';
$userBarangayId = $user['barangay_id'] ?? null;
if (!$isBarangayAdmin && $userBarangayId !== null && (int)$hh['barangay_id'] !== (int)$userBarangayId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have access to this household.']);
    exit;
}

$assignedCount = 0;
$skipped = [];
$hhBarangayId = (int)$hh['barangay_id'];
$hhLocalAreaId = $hh['local_area_id'] !== null ? (int)$hh['local_area_id'] : null;

foreach ($parentIds as $pid) {
    $check = admin_fetch_one(
        'SELECT id, barangay_id FROM parents WHERE id = ? LIMIT 1',
        'i',
        [$pid]
    );
    if (!$check) {
        $skipped[] = ['id' => $pid, 'reason' => 'Not found'];
        continue;
    }
    if (!$isBarangayAdmin && $userBarangayId !== null && (int)$check['barangay_id'] !== (int)$userBarangayId) {
        $skipped[] = ['id' => $pid, 'reason' => 'Out of barangay scope'];
        continue;
    }

    $stmt = mysqli_prepare(
        $conn,
        'UPDATE parents
            SET household_id = ?, barangay_id = ?, local_area_id = COALESCE(?, local_area_id)
          WHERE id = ?'
    );
    if ($stmt === false) {
        $skipped[] = ['id' => $pid, 'reason' => 'Update failed'];
        continue;
    }

    $laVar = $hhLocalAreaId;
    mysqli_stmt_bind_param($stmt, 'iiii', $householdId, $hhBarangayId, $laVar, $pid);
    if (mysqli_stmt_execute($stmt)) {
        $assignedCount++;
    } else {
        $skipped[] = ['id' => $pid, 'reason' => 'Update failed'];
    }
    mysqli_stmt_close($stmt);
}

log_action(
    $user['id'] ?? null,
    'ASSIGN_PARENTS_TO_HOUSEHOLD',
    'info',
    "Assigned {$assignedCount} parent(s) to household #{$householdId} (" . count($skipped) . ' skipped)'
);

echo json_encode([
    'success' => true,
    'message' => "{$assignedCount} parent(s) assigned to household.",
    'assigned' => $assignedCount,
    'skipped' => $skipped,
]);

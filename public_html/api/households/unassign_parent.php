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

// Read-only tier may view but never modify (UI hides the buttons;
// this is the backend enforcement for forged requests).
if (($user['access_level'] ?? 'full') === 'readonly') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Read-only accounts cannot modify data.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$parentId = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : 0;
if ($parentId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Parent ID is required.']);
    exit;
}

$conn = get_db_connection();

$parent = admin_fetch_one(
    'SELECT id, household_id, barangay_id FROM parents WHERE id = ? LIMIT 1',
    'i',
    [$parentId]
);

if (!$parent) {
    echo json_encode(['success' => false, 'message' => 'Parent not found.']);
    exit;
}

$isBarangayAdmin = ($user['role'] ?? '') === 'admin';
$userBarangayId = $user['barangay_id'] ?? null;
if (!$isBarangayAdmin && $userBarangayId !== null && (int)$parent['barangay_id'] !== (int)$userBarangayId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have access to this record.']);
    exit;
}

$stmt = mysqli_prepare($conn, 'UPDATE parents SET household_id = NULL WHERE id = ?');
if ($stmt === false) {
    echo json_encode(['success' => false, 'message' => 'Update failed.']);
    exit;
}
mysqli_stmt_bind_param($stmt, 'i', $parentId);
$ok = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Failed to unassign parent.']);
    exit;
}

// Orphan sweep: spots are parent-driven, so any active child left in this
// household without a parent still assigned to it follows the parent out.
// This covers the removed parent's own children AND cross-assigned children
// (via child_form / import) or children of already-removed parents. Children
// whose parents remain in the household are never touched — and when the
// last parent leaves, the spot is naturally left with no children.
$removedChildren = 0;
$oldHouseholdId = isset($parent['household_id']) ? (int)$parent['household_id'] : 0;
if ($oldHouseholdId > 0) {
    $sweepStmt = mysqli_prepare(
        $conn,
        'UPDATE children c
            LEFT JOIN parents p ON p.id = c.parent_id
            SET c.household_id = NULL
          WHERE c.household_id = ?
            AND c.status = "active"
            AND (p.id IS NULL OR p.household_id IS NULL OR p.household_id != ? OR p.status != "active")'
    );
    if ($sweepStmt !== false) {
        mysqli_stmt_bind_param($sweepStmt, 'ii', $oldHouseholdId, $oldHouseholdId);
        if (mysqli_stmt_execute($sweepStmt)) {
            $removedChildren = (int)mysqli_stmt_affected_rows($sweepStmt);
        }
        mysqli_stmt_close($sweepStmt);
    }
}

log_action($user['id'] ?? null, 'UNASSIGN_PARENT', 'info', "Unassigned parent #{$parentId} from household ({$removedChildren} child(ren) removed with parent)");

echo json_encode(['success' => true, 'message' => 'Parent unassigned from household.', 'removed_children' => $removedChildren]);

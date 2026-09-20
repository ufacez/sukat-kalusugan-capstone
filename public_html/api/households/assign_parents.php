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
$autoAssignedChildren = 0;
$hhBarangayId = (int)$hh['barangay_id'];
$hhLocalAreaId = $hh['local_area_id'] !== null ? (int)$hh['local_area_id'] : null;

foreach ($parentIds as $pid) {
    $check = admin_fetch_one(
        'SELECT id, barangay_id, household_id FROM parents WHERE id = ? LIMIT 1',
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
    $currentHh = isset($check['household_id']) ? (int)$check['household_id'] : 0;
    if ($currentHh > 0 && $currentHh !== $householdId) {
        $skipped[] = ['id' => $pid, 'reason' => 'Already assigned to another household'];
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

        // Auto-add: unassigned children of this parent follow the parent
        // into the same household (same barangay/local-area sync as a
        // manual assign). Children already in another household are left
        // untouched so they never get silently moved.
        $kids = admin_fetch_all(
            'SELECT id, barangay_id FROM children WHERE parent_id = ? AND status = "active" AND (household_id IS NULL OR household_id = 0)',
            'i',
            [$pid]
        );
        foreach ($kids as $kid) {
            $kidId = (int)($kid['id'] ?? 0);
            if ($kidId <= 0) {
                continue;
            }
            if (!$isBarangayAdmin && $userBarangayId !== null && (int)($kid['barangay_id'] ?? 0) !== (int)$userBarangayId) {
                continue;
            }
            $kidStmt = mysqli_prepare(
                $conn,
                'UPDATE children
                    SET household_id = ?, barangay_id = ?, local_area_id = COALESCE(?, local_area_id)
                  WHERE id = ? AND (household_id IS NULL OR household_id = 0)'
            );
            if ($kidStmt === false) {
                continue;
            }
            $kidLaVar = $hhLocalAreaId;
            mysqli_stmt_bind_param($kidStmt, 'iiii', $householdId, $hhBarangayId, $kidLaVar, $kidId);
            if (mysqli_stmt_execute($kidStmt) && mysqli_stmt_affected_rows($kidStmt) > 0) {
                $autoAssignedChildren++;
            }
            mysqli_stmt_close($kidStmt);
        }
    } else {
        $skipped[] = ['id' => $pid, 'reason' => 'Update failed'];
    }
    mysqli_stmt_close($stmt);
}

log_action(
    $user['id'] ?? null,
    'ASSIGN_PARENTS_TO_HOUSEHOLD',
    'info',
    "Assigned {$assignedCount} parent(s) to household #{$householdId} (" . count($skipped) . ' skipped, ' . $autoAssignedChildren . ' children auto-assigned)'
);

echo json_encode([
    'success' => true,
    'message' => "{$assignedCount} parent(s) assigned to household.",
    'assigned' => $assignedCount,
    'skipped' => $skipped,
    'auto_assigned_children' => $autoAssignedChildren,
]);

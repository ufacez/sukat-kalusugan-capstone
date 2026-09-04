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

log_action($user['id'] ?? null, 'UNASSIGN_PARENT', 'info', "Unassigned parent #{$parentId} from household");

echo json_encode(['success' => true, 'message' => 'Parent unassigned from household.']);

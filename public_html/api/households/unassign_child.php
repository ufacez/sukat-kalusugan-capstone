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

$childId = isset($_POST['child_id']) ? (int)$_POST['child_id'] : 0;
if ($childId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Child ID is required.']);
    exit;
}

$conn = get_db_connection();

$child = admin_fetch_one(
    'SELECT c.id, c.household_id, c.barangay_id, h.barangay_id AS hh_barangay_id
       FROM children c
       LEFT JOIN households h ON h.id = c.household_id
      WHERE c.id = ? LIMIT 1',
    'i',
    [$childId]
);

if (!$child) {
    echo json_encode(['success' => false, 'message' => 'Child not found.']);
    exit;
}

$isBarangayAdmin = ($user['role'] ?? '') === 'admin';
$userBarangayId = $user['barangay_id'] ?? null;
if (!$isBarangayAdmin && $userBarangayId !== null && (int)$child['barangay_id'] !== (int)$userBarangayId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have access to this record.']);
    exit;
}

$stmt = mysqli_prepare($conn, 'UPDATE children SET household_id = NULL WHERE id = ?');
if ($stmt === false) {
    echo json_encode(['success' => false, 'message' => 'Update failed.']);
    exit;
}
mysqli_stmt_bind_param($stmt, 'i', $childId);
$ok = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Failed to unassign child.']);
    exit;
}

log_action($user['id'] ?? null, 'UNASSIGN_CHILD', 'info', "Unassigned child #{$childId} from household");

echo json_encode(['success' => true, 'message' => 'Child unassigned from household.']);

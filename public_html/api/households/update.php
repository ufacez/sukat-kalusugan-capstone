<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth_middleware.php';
require_once __DIR__ . '/../../includes/audit_logger.php';

start_secure_session();
$user = current_user();

if (($user['type'] ?? '') !== 'staff' || !in_array($user['role'] ?? '', ['admin', 'nutritionist'], true)) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$address = trim((string)($_POST['address'] ?? ''));
$lat = isset($_POST['lat']) && $_POST['lat'] !== '' ? (float)$_POST['lat'] : null;
$lng = isset($_POST['lng']) && $_POST['lng'] !== '' ? (float)$_POST['lng'] : null;
$localAreaId = isset($_POST['local_area_id']) && $_POST['local_area_id'] !== '' ? (int)$_POST['local_area_id'] : null;

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Household ID is required.']);
    exit;
}

$conn = get_db_connection();

$check = mysqli_prepare($conn, 'SELECT id, barangay_id, household_code FROM households WHERE id = ?');
if ($check) {
    mysqli_stmt_bind_param($check, 'i', $id);
    mysqli_stmt_execute($check);
    $result = mysqli_stmt_get_result($check);
    $existing = mysqli_fetch_assoc($result);
    mysqli_stmt_close($check);

    if (!$existing) {
        echo json_encode(['success' => false, 'message' => 'Household not found.']);
        exit;
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit;
}

$stmt = mysqli_prepare($conn, 'UPDATE households SET address = ?, lat = ?, lng = ?, local_area_id = ? WHERE id = ?');
if ($stmt === false) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit;
}

mysqli_stmt_bind_param($stmt, 'sddii', $address, $lat, $lng, $localAreaId, $id);

if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    echo json_encode(['success' => false, 'message' => 'Failed to update household.']);
    exit;
}

mysqli_stmt_close($stmt);

log_action($user['id'] ?? null, 'UPDATE_HOUSEHOLD', 'info', 'Updated household #' . $id . ' (' . ($existing['household_code'] ?? '') . ')');

echo json_encode(['success' => true, 'message' => 'Household updated.', 'id' => $id]);

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

$barangayId = isset($_POST['barangay_id']) ? (int)$_POST['barangay_id'] : 0;
$address = trim((string)($_POST['address'] ?? ''));
$lat = isset($_POST['lat']) && $_POST['lat'] !== '' ? (float)$_POST['lat'] : null;
$lng = isset($_POST['lng']) && $_POST['lng'] !== '' ? (float)$_POST['lng'] : null;
$localAreaId = isset($_POST['local_area_id']) && $_POST['local_area_id'] !== '' ? (int)$_POST['local_area_id'] : null;

if ($barangayId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Barangay ID is required.']);
    exit;
}

$conn = get_db_connection();

$stmt = mysqli_prepare($conn, 'INSERT INTO households (barangay_id, local_area_id, household_code, address, lat, lng) VALUES (?, ?, ?, ?, ?, ?)');
if ($stmt === false) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit;
}

$householdCode = '';
mysqli_stmt_bind_param($stmt, 'iissdd', $barangayId, $localAreaId, $householdCode, $address, $lat, $lng);

if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    echo json_encode(['success' => false, 'message' => 'Failed to create household.']);
    exit;
}

$newId = mysqli_insert_id($conn);
mysqli_stmt_close($stmt);

$householdCode = 'HH-' . str_pad((string)$newId, 4, '0', STR_PAD_LEFT);

$upd = mysqli_prepare($conn, 'UPDATE households SET household_code = ? WHERE id = ?');
if ($upd) {
    mysqli_stmt_bind_param($upd, 'si', $householdCode, $newId);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);
}

log_action($user['id'] ?? null, 'CREATE_HOUSEHOLD', 'info', 'Created household ' . $householdCode . ' in barangay #' . $barangayId);

echo json_encode(['success' => true, 'message' => 'Household created.', 'id' => $newId, 'code' => $householdCode]);

<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth_middleware.php';
require_once __DIR__ . '/../../includes/audit_logger.php';

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

$barangayId = isset($_POST['barangay_id']) ? (int)$_POST['barangay_id'] : 0;

if ($barangayId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Barangay ID is required.']);
    exit;
}

if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Please upload a valid CSV file.']);
    exit;
}

$tmpFile = $_FILES['csv_file']['tmp_name'];
$handle = fopen($tmpFile, 'r');
if ($handle === false) {
    echo json_encode(['success' => false, 'message' => 'Could not read the uploaded file.']);
    exit;
}

$conn = get_db_connection();

$imported = 0;
$skipped = 0;
$errors = [];
$lineNum = 0;

$header = fgetcsv($handle);
if ($header === false) {
    fclose($handle);
    echo json_encode(['success' => false, 'message' => 'CSV file is empty.']);
    exit;
}

$headerMap = [];
foreach ($header as $idx => $col) {
    $headerMap[strtolower(trim($col))] = $idx;
}

while (($row = fgetcsv($handle)) !== false) {
    $lineNum++;
    if (count($row) < count($header)) {
        $row = array_merge($row, array_fill(0, count($header) - count($row), ''));
    }

    $address = isset($headerMap['address']) ? trim($row[$headerMap['address']] ?? '') : '';
    $lat = isset($headerMap['lat']) && $row[$headerMap['lat']] !== '' ? (float)$row[$headerMap['lat']] : null;
    $lng = isset($headerMap['lng']) && $row[$headerMap['lng']] !== '' ? (float)$row[$headerMap['lng']] : null;
    $localAreaName = isset($headerMap['local_area_name']) ? trim($row[$headerMap['local_area_name']] ?? '') : '';
    $localAreaId = null;

    if ($localAreaName !== '') {
        $laCheck = mysqli_prepare($conn, 'SELECT id FROM local_areas WHERE barangay_id = ? AND area_name = ? AND is_active = 1');
        if ($laCheck) {
            mysqli_stmt_bind_param($laCheck, 'is', $barangayId, $localAreaName);
            mysqli_stmt_execute($laCheck);
            $laResult = mysqli_stmt_get_result($laCheck);
            if ($laRow = mysqli_fetch_assoc($laResult)) {
                $localAreaId = (int)$laRow['id'];
            }
            mysqli_stmt_close($laCheck);
        }
    }

    $stmt = mysqli_prepare($conn, 'INSERT INTO households (barangay_id, local_area_id, household_code, address, lat, lng) VALUES (?, ?, ?, ?, ?, ?)');
    if ($stmt === false) {
        $errors[] = 'Line ' . $lineNum . ': database error';
        continue;
    }

    $placeholderCode = '';
    mysqli_stmt_bind_param($stmt, 'iissdd', $barangayId, $localAreaId, $placeholderCode, $address, $lat, $lng);

    if (mysqli_stmt_execute($stmt)) {
        $newId = mysqli_insert_id($conn);
        $autoCode = 'HH-' . str_pad((string)$newId, 4, '0', STR_PAD_LEFT);
        $upd = mysqli_prepare($conn, 'UPDATE households SET household_code = ? WHERE id = ?');
        if ($upd) {
            mysqli_stmt_bind_param($upd, 'si', $autoCode, $newId);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
        }
        $imported++;
    } else {
        $errors[] = 'Line ' . $lineNum . ': insert failed';
    }
    mysqli_stmt_close($stmt);
}

fclose($handle);

log_action($user['id'] ?? null, 'IMPORT_HOUSEHOLDS', 'info', 'Imported ' . $imported . ' households into barangay #' . $barangayId);

$message = 'Imported ' . $imported . ' household(s).';
if ($skipped > 0) {
    $message .= ' Skipped ' . $skipped . ' row(s) (empty or duplicate).';
}
if (!empty($errors)) {
    $message .= ' ' . count($errors) . ' error(s).';
}

echo json_encode([
    'success' => $imported > 0,
    'message' => $message,
    'imported' => $imported,
    'skipped' => $skipped,
    'errors' => $errors,
]);

<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';
require_once __DIR__ . '/../../includes/api_helpers.php';

start_secure_session();

$conn = get_db_connection();

$method = strtoupper($_SERVER['REQUEST_METHOD']);
if ($method === 'POST' && isset($_POST['_method'])) {
    $method = strtoupper($_POST['_method']);
}

// GET requests allow any authenticated staff (barangays.view).
// Mutations require barangays.manage.
if ($method === 'GET') {
    require_permission('barangays.view');
} else {
    require_permission('barangays.manage');
}

if ($method === 'GET') {

    $barangayId = api_int($_GET['barangay_id'] ?? 0);

    if ($barangayId <= 0) {
        api_error('barangay_id is required.', 400);
    }

    $areas = admin_fetch_all(
        "SELECT id, barangay_id, area_code, area_name, area_type, description, is_active, created_at
         FROM local_areas
         WHERE barangay_id = ?
         ORDER BY area_type ASC, area_name ASC",
        'i',
        [$barangayId]
    );

    api_success($areas);
    exit;
}

if ($method === 'POST') {

    $payload = api_payload();

    $barangayId = api_int($payload['barangay_id'] ?? 0);
    $areaName   = trim((string)($payload['area_name'] ?? ''));
    $areaType   = strtolower(trim((string)($payload['area_type'] ?? 'purok')));
    $areaCode   = trim((string)($payload['area_code'] ?? ''));
    $description = trim((string)($payload['description'] ?? ''));

    $allowedTypes = ['purok','sitio','subdivision','village','zone','phase','other'];

    if ($barangayId <= 0) {
        api_error('Barangay is required.', 400);
    }

    if ($areaName === '') {
        api_error('Area name is required.', 400);
    }

    if (!in_array($areaType, $allowedTypes, true)) {
        $areaType = 'purok';
    }

    $barangay = admin_fetch_one(
        "SELECT id FROM barangays WHERE id = ? LIMIT 1",
        'i',
        [$barangayId]
    );

    if (!$barangay) {
        api_error('Barangay not found.', 404);
    }

    $existing = admin_fetch_one(
        "SELECT id FROM local_areas WHERE barangay_id = ? AND area_name = ? LIMIT 1",
        'is',
        [$barangayId, $areaName]
    );

    if ($existing) {
        api_error('A local area with this name already exists in this barangay.', 409);
    }

    $codeValue = $areaCode !== '' ? $areaCode : null;
    $descValue = $description !== '' ? $description : null;

    $ok = admin_execute(
        "INSERT INTO local_areas (barangay_id, area_code, area_name, area_type, description)
         VALUES (?, ?, ?, ?, ?)",
        'issss',
        [$barangayId, $codeValue, $areaName, $areaType, $descValue]
    );

    if (!$ok) {
        api_error('Local area could not be created.', 500);
    }

    $actor = current_user();
    log_action($actor['id'] ?? null, 'CREATE_LOCAL_AREA', 'info',
        "Created local area \"{$areaName}\" ({$areaType}) in barangay #{$barangayId}");

    api_success([], 'Local area created.', 201);
    exit;
}

if ($method === 'PUT' || $method === 'PATCH') {

    $payload = api_payload();
    $id = api_int($payload['id'] ?? $_GET['id'] ?? 0);

    if ($id <= 0) {
        api_error('Local area ID is required.', 400);
    }

    $existing = admin_fetch_one(
        "SELECT id, barangay_id FROM local_areas WHERE id = ? LIMIT 1",
        'i',
        [$id]
    );

    if (!$existing) {
        api_error('Local area not found.', 404);
    }

    $areaName   = trim((string)($payload['area_name'] ?? ''));
    $areaType   = strtolower(trim((string)($payload['area_type'] ?? '')));
    $areaCode   = trim((string)($payload['area_code'] ?? ''));
    $description = trim((string)($payload['description'] ?? ''));
    $isActive   = isset($payload['is_active']) ? (int)$payload['is_active'] : null;

    $allowedTypes = ['purok','sitio','subdivision','village','zone','phase','other'];

    $sets = [];
    $types = '';
    $params = [];

    if ($areaName !== '') {
        $dup = admin_fetch_one(
            "SELECT id FROM local_areas WHERE barangay_id = ? AND area_name = ? AND id != ? LIMIT 1",
            'isi',
            [$existing['barangay_id'], $areaName, $id]
        );
        if ($dup) {
            api_error('Another local area with this name already exists in this barangay.', 409);
        }
        $sets[] = 'area_name = ?';
        $types .= 's';
        $params[] = $areaName;
    }

    if ($areaType !== '' && in_array($areaType, $allowedTypes, true)) {
        $sets[] = 'area_type = ?';
        $types .= 's';
        $params[] = $areaType;
    }

    if (array_key_exists('area_code', $payload) || $areaCode !== '') {
        $sets[] = 'area_code = ?';
        $types .= 's';
        $params[] = $areaCode !== '' ? $areaCode : null;
    }

    if (array_key_exists('description', $payload) || $description !== '') {
        $sets[] = 'description = ?';
        $types .= 's';
        $params[] = $description !== '' ? $description : null;
    }

    if ($isActive !== null) {
        $sets[] = 'is_active = ?';
        $types .= 'i';
        $params[] = $isActive ? 1 : 0;
    }

    if ($sets === []) {
        api_error('No fields to update.', 400);
    }

    $params[] = $id;
    $sql = 'UPDATE local_areas SET ' . implode(', ', $sets) . ' WHERE id = ?';
    $ok = admin_execute($sql, $types . 'i', $params);

    if (!$ok) {
        api_error('Local area could not be updated.', 500);
    }

    $actor = current_user();
    log_action($actor['id'] ?? null, 'UPDATE_LOCAL_AREA', 'info',
        "Updated local area #{$id}");

    api_success([], 'Local area updated.');
    exit;
}

if ($method === 'DELETE') {

    $payload = api_payload();
    $id = api_int($payload['id'] ?? $_GET['id'] ?? 0);

    if ($id <= 0) {
        api_error('Local area ID is required.', 400);
    }

    $existing = admin_fetch_one(
        "SELECT id, area_name FROM local_areas WHERE id = ? LIMIT 1",
        'i',
        [$id]
    );

    if (!$existing) {
        api_error('Local area not found.', 404);
    }

    $childCount = admin_scalar(
        "SELECT COUNT(*) FROM children WHERE local_area_id = ?",
        'i',
        [$id]
    );

    if ($childCount > 0) {
        admin_execute(
            "UPDATE local_areas SET is_active = 0 WHERE id = ?",
            'i',
            [$id]
        );

        $actor = current_user();
        log_action($actor['id'] ?? null, 'DEACTIVATE_LOCAL_AREA', 'info',
            "Deactivated local area \"{$existing['area_name']}\" (#{$id}) — {$childCount} children linked");

        api_success([], 'Local area deactivated (children still reference it).');
        exit;
    }

    admin_execute("DELETE FROM local_areas WHERE id = ?", 'i', [$id]);

    $actor = current_user();
    log_action($actor['id'] ?? null, 'DELETE_LOCAL_AREA', 'info',
        "Deleted local area \"{$existing['area_name']}\" (#{$id})");

    api_success([], 'Local area deleted.');
    exit;
}

api_error('Method not allowed.', 405);

<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_login();

// Read-only list of active local areas (purok / sitio / etc.) for one barangay,
// keyed by barangay NAME because the shared address picker resolves barangays
// by name. Parents also use the picker (parent/settings.php), so any signed-in
// user may read it; staff still need the barangays.view grant.
if (!is_parent_session()) {
    require_permission('barangays.view');
}

header('Content-Type: application/json; charset=utf-8');

$barangayName = trim((string)($_GET['barangay_name'] ?? ''));

if ($barangayName === '') {
    echo json_encode([
        'success' => false,
        'message' => 'barangay_name is required.',
        'areas' => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rows = admin_fetch_all(
    "SELECT la.area_name, la.area_type
     FROM local_areas la
     JOIN barangays b ON b.id = la.barangay_id
     WHERE b.name = ? AND la.is_active = 1
     ORDER BY FIELD(la.area_type, 'purok', 'sitio', 'subdivision', 'village', 'zone', 'phase', 'other'), la.area_name ASC",
    's',
    [$barangayName]
);

$areas = array_map(
    static fn(array $row): array => [
        'name' => (string)($row['area_name'] ?? ''),
        'type' => (string)($row['area_type'] ?? 'purok'),
    ],
    $rows
);

echo json_encode([
    'success' => true,
    'barangay_name' => $barangayName,
    'areas' => $areas,
], JSON_UNESCAPED_UNICODE);

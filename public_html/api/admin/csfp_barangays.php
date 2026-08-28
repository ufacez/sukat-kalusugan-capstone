<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_permission('barangays.view');

$barangays = admin_fetch_all(
    "SELECT name FROM barangays WHERE status = 'active' ORDER BY name ASC"
);

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'city_municipality' => 'City of San Fernando, Pampanga',
    'barangays' => array_map(fn($b) => $b['name'], $barangays),
], JSON_UNESCAPED_UNICODE);

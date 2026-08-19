<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_permission('barangays.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    admin_redirect('/admin/barangays.php', ['notice' => 'Method not allowed.', 'type' => 'error']);
}

$id = (int)($_POST['id'] ?? 0);
$name = trim((string)($_POST['name'] ?? ''));
$cityMunicipality = trim((string)($_POST['city_municipality'] ?? ''));
$status = trim((string)($_POST['status'] ?? 'active'));

if ($id <= 0 || $name === '') {
    admin_redirect('/admin/barangays.php', ['notice' => 'Barangay id and name are required.', 'type' => 'error']);
}

if (!in_array($status, ['active', 'inactive'], true)) {
    $status = 'active';
}

$cityMunicipalityValue = $cityMunicipality !== '' ? $cityMunicipality : null;

$ok = admin_execute(
    'UPDATE barangays SET name = ?, city_municipality = ?, status = ? WHERE id = ?',
    'sssi',
    [$name, $cityMunicipalityValue, $status, $id]
);

if (!$ok) {
    admin_redirect('/admin/barangays.php?edit=' . $id, ['notice' => 'Barangay could not be updated. The name may already be in use.', 'type' => 'error']);
}

$actor = current_user();
log_action($actor['id'] ?? null, 'UPDATE_BARANGAY', 'info', 'Updated barangay ' . $name . ' (' . $id . ')');

admin_redirect('/admin/barangays.php', ['notice' => 'Barangay updated successfully.', 'type' => 'success']);

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

// Friendly pre-check (excluding self) so a rename-to-existing shows a red
// toast instead of a 500. UNIQUE key is case-insensitive, so compare LOWER().
$duplicate = admin_fetch_one(
    'SELECT id FROM barangays WHERE LOWER(name) = LOWER(?) AND id != ? LIMIT 1',
    'si',
    [$name, $id]
);

if ($duplicate !== null) {
    admin_redirect('/admin/barangay_form.php?id=' . $id, ['notice' => "Another barangay already uses the name '" . $name . "'.", 'type' => 'error']);
}

// admin_execute() returns false on failure, but PHP 8 mysqli throws
// mysqli_sql_exception (errno 1062) on duplicate instead — catch it for the
// race where two admins rename to the same name at the same time.
try {
    $ok = admin_execute(
        'UPDATE barangays SET name = ?, city_municipality = ?, status = ? WHERE id = ?',
        'sssi',
        [$name, $cityMunicipalityValue, $status, $id]
    );
} catch (mysqli_sql_exception $e) {
    error_log('[SukatKalusugan] barangays_update failed: ' . $e->getMessage());
    if ((int)$e->getCode() === 1062) {
        admin_redirect('/admin/barangay_form.php?id=' . $id, ['notice' => "Another barangay already uses the name '" . $name . "'.", 'type' => 'error']);
    }
    admin_redirect('/admin/barangay_form.php?id=' . $id, ['notice' => 'Barangay could not be updated right now.', 'type' => 'error']);
}

if (!$ok) {
    admin_redirect('/admin/barangay_form.php?id=' . $id, ['notice' => 'Barangay could not be updated. The name may already be in use.', 'type' => 'error']);
}

$actor = current_user();
log_action($actor['id'] ?? null, 'UPDATE_BARANGAY', 'info', 'Updated barangay ' . $name . ' (' . $id . ')');

admin_redirect('/admin/barangays.php', ['notice' => 'Barangay updated successfully.', 'type' => 'success']);

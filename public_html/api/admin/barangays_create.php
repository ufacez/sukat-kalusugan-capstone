<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_permission('barangays.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    admin_redirect('/admin/barangays.php', ['notice' => 'Method not allowed.', 'type' => 'error']);
}

$name = trim((string)($_POST['name'] ?? ''));
$cityMunicipality = trim((string)($_POST['city_municipality'] ?? ''));
$status = trim((string)($_POST['status'] ?? 'active'));

if ($name === '') {
    admin_redirect('/admin/barangays.php', ['notice' => 'Barangay name is required.', 'type' => 'error']);
}

if (!in_array($status, ['active', 'inactive'], true)) {
    $status = 'active';
}

$cityMunicipalityValue = $cityMunicipality !== '' ? $cityMunicipality : null;

$conn = get_db_connection();
$stmt = mysqli_prepare($conn, 'INSERT INTO barangays (name, city_municipality, status) VALUES (?, ?, ?)');

if ($stmt === false) {
    admin_redirect('/admin/barangays.php', ['notice' => 'Unable to create barangay right now.', 'type' => 'error']);
}

mysqli_stmt_bind_param($stmt, 'sss', $name, $cityMunicipalityValue, $status);

if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    admin_redirect('/admin/barangays.php', ['notice' => 'Barangay could not be created. It may already exist.', 'type' => 'error']);
}

mysqli_stmt_close($stmt);

$actor = current_user();
log_action($actor['id'] ?? null, 'CREATE_BARANGAY', 'info', 'Created barangay ' . $name);

admin_redirect('/admin/barangays.php', ['notice' => 'Barangay created successfully.', 'type' => 'success']);

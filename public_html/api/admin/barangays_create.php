<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_permission('barangays.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    admin_redirect('/admin/barangay_form.php', ['notice' => 'Method not allowed.', 'type' => 'error']);
}

$name = trim((string)($_POST['name'] ?? ''));
$cityMunicipality = trim((string)($_POST['city_municipality'] ?? ''));
$status = trim((string)($_POST['status'] ?? 'active'));

if ($name === '') {
    admin_redirect('/admin/barangay_form.php', ['notice' => 'Barangay name is required.', 'type' => 'error']);
}

if (!in_array($status, ['active', 'inactive'], true)) {
    $status = 'active';
}

$cityMunicipalityValue = $cityMunicipality !== '' ? $cityMunicipality : null;

// Friendly pre-check so a duplicate shows a red toast instead of a 500.
// The UNIQUE key is case-insensitive (utf8mb4_unicode_ci), so compare LOWER().
$existing = admin_fetch_one(
    'SELECT id FROM barangays WHERE LOWER(name) = LOWER(?) LIMIT 1',
    's',
    [$name]
);

if ($existing !== null) {
    admin_redirect('/admin/barangay_form.php', ['notice' => "Barangay '" . $name . "' already exists.", 'type' => 'error']);
}

$conn = get_db_connection();
$stmt = mysqli_prepare($conn, 'INSERT INTO barangays (name, city_municipality, status) VALUES (?, ?, ?)');

if ($stmt === false) {
    admin_redirect('/admin/barangay_form.php', ['notice' => 'Unable to create barangay right now.', 'type' => 'error']);
}

mysqli_stmt_bind_param($stmt, 'sss', $name, $cityMunicipalityValue, $status);

// PHP 8 mysqli throws mysqli_sql_exception (errno 1062) on duplicate instead
// of returning false — catch it so the admin gets a toast, not a fatal.
// The pre-check above handles the common case; this catch covers the race
// where two admins submit the same new name at the same time.
try {
    $executed = mysqli_stmt_execute($stmt);
} catch (mysqli_sql_exception $e) {
    $errno = (int)$e->getCode();
    error_log('[SukatKalusugan] barangays_create failed: ' . $e->getMessage());
    mysqli_stmt_close($stmt);
    if ($errno === 1062) {
        admin_redirect('/admin/barangay_form.php', ['notice' => "Barangay '" . $name . "' already exists.", 'type' => 'error']);
    }
    admin_redirect('/admin/barangay_form.php', ['notice' => 'Unable to create barangay right now.', 'type' => 'error']);
}

if (!$executed) {
    $errno = (int)mysqli_stmt_errno($stmt);
    mysqli_stmt_close($stmt);
    if ($errno === 1062) {
        admin_redirect('/admin/barangay_form.php', ['notice' => "Barangay '" . $name . "' already exists.", 'type' => 'error']);
    }
    admin_redirect('/admin/barangay_form.php', ['notice' => 'Barangay could not be created. It may already exist.', 'type' => 'error']);
}

mysqli_stmt_close($stmt);

$actor = current_user();
log_action($actor['id'] ?? null, 'CREATE_BARANGAY', 'info', 'Created barangay ' . $name);

admin_redirect('/admin/barangays.php', ['notice' => 'Barangay created successfully.', 'type' => 'success']);

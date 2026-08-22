<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_permission('parents.create');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    admin_redirect('/admin/parents.php', ['notice' => 'Method not allowed.', 'type' => 'error']);
}

$name = trim((string)($_POST['name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$address = trim((string)($_POST['address'] ?? ''));
$parentType = trim((string)($_POST['parent_type'] ?? 'Guardian'));
$barangayIdRaw = trim((string)($_POST['barangay_id'] ?? ''));
$barangayId = $barangayIdRaw !== '' ? (int)$barangayIdRaw : null;
$status = trim((string)($_POST['status'] ?? 'active'));
$password = (string)($_POST['password'] ?? '');

$allowedParentTypes = ['Father', 'Mother', 'Guardian', 'Grandparent', 'Other'];

if ($name === '' || $email === '' || $password === '') {
    admin_redirect('/admin/parents.php', ['notice' => 'Name, email, and password are required.', 'type' => 'error']);
}

if (!in_array($parentType, $allowedParentTypes, true)) {
    $parentType = 'Guardian';
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$conn = get_db_connection();
$stmt = mysqli_prepare(
    $conn,
    'INSERT INTO parents (name, email, password_hash, parent_type, phone, address, barangay_id, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);

if ($stmt === false) {
    admin_redirect('/admin/parents.php', ['notice' => 'Unable to create parent right now.', 'type' => 'error']);
}

mysqli_stmt_bind_param($stmt, 'ssssssis', $name, $email, $hash, $parentType, $phone, $address, $barangayId, $status);

if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    admin_redirect('/admin/parents.php', ['notice' => 'Parent could not be created. Check for a duplicate email.', 'type' => 'error']);
}

mysqli_stmt_close($stmt);

$actor = current_user();
log_action($actor['id'] ?? null, 'CREATE_PARENT', 'info', 'Created parent account ' . $email);

admin_redirect('/admin/parents.php', ['notice' => 'Parent created successfully.', 'type' => 'success']);

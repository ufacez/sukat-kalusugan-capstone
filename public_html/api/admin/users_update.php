<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_permission('users.update');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    admin_redirect('/admin/users.php', ['notice' => 'Method not allowed.', 'type' => 'error']);
}

$id = (int)($_POST['id'] ?? 0);
$name = trim((string)($_POST['name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$username = trim((string)($_POST['username'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$barangay = trim((string)($_POST['barangay'] ?? ''));
$roleName = trim((string)($_POST['role'] ?? 'nutritionist'));
$status = trim((string)($_POST['status'] ?? 'active'));
$password = (string)($_POST['password'] ?? '');

if ($id <= 0 || $name === '' || $email === '' || $username === '') {
    admin_redirect('/admin/users.php', ['notice' => 'User id, name, email, and username are required.', 'type' => 'error']);
}

$roleId = admin_find_role_id($roleName);

if ($roleId <= 0) {
    admin_redirect('/admin/users.php', ['notice' => 'Selected role does not exist.', 'type' => 'error']);
}

$conn = get_db_connection();

if ($password !== '') {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = mysqli_prepare($conn, 'UPDATE users SET name = ?, email = ?, username = ?, password_hash = ?, phone = ?, role_id = ?, barangay = ?, status = ? WHERE id = ?');

    if ($stmt === false) {
        admin_redirect('/admin/users.php', ['notice' => 'Unable to update user right now.', 'type' => 'error']);
    }

    mysqli_stmt_bind_param($stmt, 'sssssissi', $name, $email, $username, $hash, $phone, $roleId, $barangay, $status, $id);
} else {
    $stmt = mysqli_prepare($conn, 'UPDATE users SET name = ?, email = ?, username = ?, phone = ?, role_id = ?, barangay = ?, status = ? WHERE id = ?');

    if ($stmt === false) {
        admin_redirect('/admin/users.php', ['notice' => 'Unable to update user right now.', 'type' => 'error']);
    }

    mysqli_stmt_bind_param($stmt, 'sssssisi', $name, $email, $username, $phone, $roleId, $barangay, $status, $id);
}

if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    admin_redirect('/admin/users.php?edit=' . $id, ['notice' => 'User could not be updated. Check for duplicate email or username.', 'type' => 'error']);
}

mysqli_stmt_close($stmt);

$actor = current_user();
log_action($actor['id'] ?? null, 'UPDATE_USER', 'info', 'Updated user ' . $email . ' (' . $id . ')');

admin_redirect('/admin/users.php', ['notice' => 'User updated successfully.', 'type' => 'success']);


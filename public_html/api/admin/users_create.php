<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_permission('users.create');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    admin_redirect('/admin/users.php', ['notice' => 'Method not allowed.', 'type' => 'error']);
}

$name = trim((string)($_POST['name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$username = trim((string)($_POST['username'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$barangay = trim((string)($_POST['barangay'] ?? ''));
$roleName = trim((string)($_POST['role'] ?? 'nutritionist'));
$status = trim((string)($_POST['status'] ?? 'active'));
$password = (string)($_POST['password'] ?? '');

if ($name === '' || $email === '' || $username === '' || $password === '') {
    admin_redirect('/admin/users.php', ['notice' => 'Name, email, username, and password are required.', 'type' => 'error']);
}

$roleId = admin_find_role_id($roleName);

if ($roleId <= 0) {
    admin_redirect('/admin/users.php', ['notice' => 'Selected role does not exist.', 'type' => 'error']);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$conn = get_db_connection();
$stmt = mysqli_prepare($conn, 'INSERT INTO users (name, email, username, password_hash, phone, role_id, barangay, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');

if ($stmt === false) {
    admin_redirect('/admin/users.php', ['notice' => 'Unable to create user right now.', 'type' => 'error']);
}

mysqli_stmt_bind_param($stmt, 'sssssiss', $name, $email, $username, $hash, $phone, $roleId, $barangay, $status);

if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    admin_redirect('/admin/users.php', ['notice' => 'User could not be created. Check for duplicate email or username.', 'type' => 'error']);
}

mysqli_stmt_close($stmt);

$actor = current_user();
log_action($actor['id'] ?? null, 'CREATE_USER', 'info', 'Created user ' . $email . ' as ' . $roleName);

admin_redirect('/admin/users.php', ['notice' => 'User created successfully.', 'type' => 'success']);


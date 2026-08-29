<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_permission('users.update');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    admin_redirect('/admin/users.php', ['notice' => 'Method not allowed.', 'type' => 'error']);
}

$id = (int)($_POST['id'] ?? 0);
$firstName = trim((string)($_POST['first_name'] ?? ''));
$middleName = trim((string)($_POST['middle_name'] ?? ''));
$lastName = trim((string)($_POST['last_name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$username = trim((string)($_POST['username'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$barangayIdRaw = trim((string)($_POST['barangay_id'] ?? ''));
$barangayId = $barangayIdRaw !== '' ? (int)$barangayIdRaw : null;
$roleName = trim((string)($_POST['role'] ?? 'nutritionist'));
$status = trim((string)($_POST['status'] ?? 'active'));
$password = (string)($_POST['password'] ?? '');
$passwordConfirm = (string)($_POST['password_confirm'] ?? '');

if (
    !admin_is_valid_name_part($firstName, true)
    || !admin_is_valid_name_part($middleName, false)
    || !admin_is_valid_name_part($lastName, true)
) {
    admin_redirect('/admin/user_form.php?id=' . $id, ['notice' => 'Enter a valid first name and surname (letters only). Middle name is optional.', 'type' => 'error']);
}

$name = admin_combine_name($firstName, $middleName, $lastName);

if ($id <= 0 || $name === '' || $email === '' || $username === '') {
    admin_redirect('/admin/user_form.php?id=' . $id, ['notice' => 'User id, name, email, and username are required.', 'type' => 'error']);
}

if (!admin_is_valid_ph_mobile($phone)) {
    admin_redirect('/admin/user_form.php?id=' . $id, ['notice' => 'Enter a valid 11-digit PH mobile number starting with 09.', 'type' => 'error']);
}

$phone = preg_replace('/[^0-9]/', '', $phone);

if ($password !== '' && !admin_is_strong_password($password)) {
    admin_redirect('/admin/user_form.php?id=' . $id, ['notice' => 'Password must be at least 8 characters and include an uppercase letter, a lowercase letter, a number, and a special character.', 'type' => 'error']);
}

if ($password !== '' && $password !== $passwordConfirm) {
    admin_redirect('/admin/user_form.php?id=' . $id, ['notice' => 'Password and confirm password do not match.', 'type' => 'error']);
}

$roleId = admin_find_role_id($roleName);

if ($roleId <= 0) {
    admin_redirect('/admin/user_form.php?id=' . $id, ['notice' => 'Selected role does not exist.', 'type' => 'error']);
}

// Duplicate checks (exclude current user)
$existingEmail = admin_fetch_one('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1', 'si', [$email, $id]);
if ($existingEmail !== null) {
    admin_redirect('/admin/user_form.php?id=' . $id, ['notice' => 'A user with this email already exists.', 'type' => 'error']);
}

$existingUsername = admin_fetch_one('SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1', 'si', [$username, $id]);
if ($existingUsername !== null) {
    admin_redirect('/admin/user_form.php?id=' . $id, ['notice' => 'A user with this username already exists.', 'type' => 'error']);
}

$conn = get_db_connection();

if ($password !== '') {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = mysqli_prepare($conn, 'UPDATE users SET name = ?, email = ?, username = ?, password_hash = ?, phone = ?, role_id = ?, barangay_id = ?, status = ? WHERE id = ?');

    if ($stmt === false) {
        admin_redirect('/admin/users.php', ['notice' => 'Unable to update user right now.', 'type' => 'error']);
    }

    mysqli_stmt_bind_param($stmt, 'sssssiisi', $name, $email, $username, $hash, $phone, $roleId, $barangayId, $status, $id);
} else {
    $stmt = mysqli_prepare($conn, 'UPDATE users SET name = ?, email = ?, username = ?, phone = ?, role_id = ?, barangay_id = ?, status = ? WHERE id = ?');

    if ($stmt === false) {
        admin_redirect('/admin/users.php', ['notice' => 'Unable to update user right now.', 'type' => 'error']);
    }

    mysqli_stmt_bind_param($stmt, 'ssssiisi', $name, $email, $username, $phone, $roleId, $barangayId, $status, $id);
}

if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    admin_redirect('/admin/user_form.php?id=' . $id, ['notice' => 'User could not be updated. Check for duplicate email or username.', 'type' => 'error']);
}

mysqli_stmt_close($stmt);

$actor = current_user();
log_action($actor['id'] ?? null, 'UPDATE_USER', 'info', 'Updated user ' . $email . ' (' . $id . ')');

admin_redirect('/admin/users.php', ['notice' => 'User updated successfully.', 'type' => 'success']);


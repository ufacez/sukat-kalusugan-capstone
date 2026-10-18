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
$address = trim((string)($_POST['address'] ?? ''));
$barangayIdRaw = trim((string)($_POST['barangay_id'] ?? ''));
$barangayId = $barangayIdRaw !== '' ? (int)$barangayIdRaw : null;
$roleName = trim((string)($_POST['role'] ?? 'nutritionist'));
$status = trim((string)($_POST['status'] ?? 'active'));
$accessLevel = trim((string)($_POST['access_level'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$passwordConfirm = (string)($_POST['password_confirm'] ?? '');

if (
    !admin_is_valid_name_part($firstName, true)
    || !admin_is_valid_name_part($middleName, false)
    || !admin_is_valid_name_part($lastName, true)
) {
    admin_flash_form_state($_POST, 'first_name');
    admin_redirect('/admin/user_form.php?id=' . $id, ['notice' => 'Enter a valid first name and surname (letters only). Middle name is optional.', 'type' => 'error']);
}

$name = admin_combine_name($firstName, $middleName, $lastName);

if ($id <= 0 || $name === '' || $email === '' || $username === '') {
    admin_flash_form_state($_POST);
    admin_redirect('/admin/user_form.php?id=' . $id, ['notice' => 'User id, name, email, and username are required.', 'type' => 'error']);
}

$isSeededAdmin = ((int)$id === 1);

if ($isSeededAdmin) {
    $existingRole = admin_fetch_one('SELECT r.name AS role_name FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE u.id = 1 LIMIT 1');
    if ($existingRole !== null && $existingRole['role_name'] !== $roleName) {
        admin_flash_form_state($_POST, 'role');
        admin_redirect('/admin/user_form.php?id=' . $id, ['notice' => 'The system administrator role cannot be changed.', 'type' => 'error']);
    }
    if ($status !== 'active') {
        admin_flash_form_state($_POST);
        admin_redirect('/admin/user_form.php?id=' . $id, ['notice' => 'The system administrator account cannot be deactivated.', 'type' => 'error']);
    }
}

if (!admin_is_valid_ph_mobile($phone)) {
    admin_flash_form_state($_POST, 'phone');
    admin_redirect('/admin/user_form.php?id=' . $id, ['notice' => 'Enter a valid PH mobile number (e.g. 09171234567).', 'type' => 'error']);
}

$phone = (string)admin_normalize_ph_mobile($phone);

if ($password !== '' && !admin_is_strong_password($password)) {
    admin_flash_form_state($_POST);
    admin_redirect('/admin/user_form.php?id=' . $id, ['notice' => 'Password must be at least 8 characters and include an uppercase letter, a lowercase letter, a number, and a special character.', 'type' => 'error']);
}

if ($password !== '' && $password !== $passwordConfirm) {
    admin_flash_form_state($_POST);
    admin_redirect('/admin/user_form.php?id=' . $id, ['notice' => 'Password and confirm password do not match.', 'type' => 'error']);
}

$roleId = admin_find_role_id($roleName);

if ($roleId <= 0) {
    admin_flash_form_state($_POST, 'role');
    admin_redirect('/admin/user_form.php?id=' . $id, ['notice' => 'Selected role does not exist.', 'type' => 'error']);
}

// "All barangays" (null) is an admin-only scope; every other role must be
// tied to a single barangay (mirrors the JS toggle on the form).
if ($roleName !== 'admin' && $barangayId === null) {
    admin_flash_form_state($_POST, 'barangay_id');
    admin_redirect('/admin/user_form.php?id=' . $id, ['notice' => 'Select a barangay — only admins can have "All barangays" scope.', 'type' => 'error']);
}

if ($accessLevel !== '' && !in_array($accessLevel, ['full', 'standard', 'readonly'], true)) {
    admin_flash_form_state($_POST, 'access_level');
    admin_redirect('/admin/user_form.php?id=' . $id, ['notice' => 'Select a valid access level.', 'type' => 'error']);
}

$canChangeAccess = has_permission('roles_permissions.update');

// Duplicate enforcement lives on the ADD paths only — edits must always
// save (same-table conflicts still fail on the UNIQUE keys below with a
// generic message). Format is still validated here.
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    admin_flash_form_state($_POST, 'email');
    admin_redirect('/admin/user_form.php?id=' . $id, ['notice' => 'Enter a valid email address.', 'type' => 'error']);
}

$conn = get_db_connection();

if ($password !== '') {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $sql = $canChangeAccess && $accessLevel !== ''
        ? 'UPDATE users SET name = ?, email = ?, username = ?, password_hash = ?, phone = ?, address = ?, role_id = ?, barangay_id = ?, status = ?, access_level = ? WHERE id = ?'
        : 'UPDATE users SET name = ?, email = ?, username = ?, password_hash = ?, phone = ?, address = ?, role_id = ?, barangay_id = ?, status = ? WHERE id = ?';
    $stmt = mysqli_prepare($conn, $sql);

    if ($stmt === false) {
        admin_redirect('/admin/users.php', ['notice' => 'Unable to update user right now.', 'type' => 'error']);
    }

    if ($canChangeAccess && $accessLevel !== '') {
        mysqli_stmt_bind_param($stmt, 'ssssssiissi', $name, $email, $username, $hash, $phone, $address, $roleId, $barangayId, $status, $accessLevel, $id);
    } else {
        mysqli_stmt_bind_param($stmt, 'ssssssiisi', $name, $email, $username, $hash, $phone, $address, $roleId, $barangayId, $status, $id);
    }
} else {
    $sql = $canChangeAccess && $accessLevel !== ''
        ? 'UPDATE users SET name = ?, email = ?, username = ?, phone = ?, address = ?, role_id = ?, barangay_id = ?, status = ?, access_level = ? WHERE id = ?'
        : 'UPDATE users SET name = ?, email = ?, username = ?, phone = ?, address = ?, role_id = ?, barangay_id = ?, status = ? WHERE id = ?';
    $stmt = mysqli_prepare($conn, $sql);

    if ($stmt === false) {
        admin_redirect('/admin/users.php', ['notice' => 'Unable to update user right now.', 'type' => 'error']);
    }

    if ($canChangeAccess && $accessLevel !== '') {
        mysqli_stmt_bind_param($stmt, 'sssssiissi', $name, $email, $username, $phone, $address, $roleId, $barangayId, $status, $accessLevel, $id);
    } else {
        mysqli_stmt_bind_param($stmt, 'sssssiisi', $name, $email, $username, $phone, $address, $roleId, $barangayId, $status, $id);
    }
}

if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    admin_flash_form_state($_POST, 'email');
    admin_redirect('/admin/user_form.php?id=' . $id, ['notice' => 'User could not be updated. Check for duplicate email or username.', 'type' => 'error']);
}

mysqli_stmt_close($stmt);

$actor = current_user();
log_action($actor['id'] ?? null, 'UPDATE_USER', 'info', 'Updated staff #' . $id);

admin_clear_form_state();
admin_redirect('/admin/users.php', ['notice' => 'User updated successfully.', 'type' => 'success']);

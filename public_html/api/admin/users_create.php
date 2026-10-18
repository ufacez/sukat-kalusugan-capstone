<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';
require_once __DIR__ . '/../../includes/display_ids.php';

start_secure_session();
require_permission('users.create');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    admin_redirect('/admin/user_form.php', ['notice' => 'Method not allowed.', 'type' => 'error']);
}

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
    admin_redirect('/admin/user_form.php', ['notice' => 'Enter a valid first name and surname (letters only). Middle name is optional.', 'type' => 'error']);
}

$name = admin_combine_name($firstName, $middleName, $lastName);

if ($name === '' || $email === '' || $username === '' || $password === '') {
    admin_redirect('/admin/user_form.php', ['notice' => 'Name, email, username, and password are required.', 'type' => 'error']);
}

if (!admin_is_valid_ph_mobile($phone)) {
    admin_redirect('/admin/user_form.php', ['notice' => 'Enter a valid PH mobile number (09XXXXXXXXX or +639XXXXXXXXX).', 'type' => 'error']);
}

$phone = (string)admin_normalize_ph_mobile($phone);

if (!admin_is_strong_password($password)) {
    admin_redirect('/admin/user_form.php', ['notice' => 'Password must be at least 8 characters and include an uppercase letter, a lowercase letter, a number, and a special character.', 'type' => 'error']);
}

if ($password !== $passwordConfirm) {
    admin_redirect('/admin/user_form.php', ['notice' => 'Password and confirm password do not match.', 'type' => 'error']);
}

$roleId = admin_find_role_id($roleName);

if ($roleId <= 0) {
    admin_redirect('/admin/user_form.php', ['notice' => 'Selected role does not exist.', 'type' => 'error']);
}

// "All barangays" (null) is an admin-only scope; every other role must be
// tied to a single barangay (mirrors the JS toggle on the form).
if ($roleName !== 'admin' && $barangayId === null) {
    admin_flash_form_state($_POST, 'barangay_id');
    admin_redirect('/admin/user_form.php', ['notice' => 'Select a barangay — only admins can have "All barangays" scope.', 'type' => 'error']);
}

// Duplicate checks — email must be globally unique across staff + parents,
// username unique across staff.
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    admin_redirect('/admin/user_form.php', ['notice' => 'Enter a valid email address.', 'type' => 'error']);
}
if (admin_email_in_use($email)) {
    admin_redirect('/admin/user_form.php', ['notice' => 'This email is already in use by another account. Use a different email address.', 'type' => 'error']);
}

if (admin_username_in_use($username)) {
    admin_redirect('/admin/user_form.php', ['notice' => 'A user with this username already exists.', 'type' => 'error']);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$conn = get_db_connection();

// Allocate the display code inside the same transaction as the insert so a
// failed create never burns a code (ADM-#### / NUT-####).
mysqli_begin_transaction($conn);
$userCode = sk_next_staff_code($roleId);

$stmt = mysqli_prepare($conn, 'INSERT INTO users (user_code, name, email, username, password_hash, phone, role_id, barangay_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');

if ($stmt === false) {
    mysqli_rollback($conn);
    admin_redirect('/admin/user_form.php', ['notice' => 'Unable to create user right now.', 'type' => 'error']);
}

mysqli_stmt_bind_param($stmt, 'ssssssiis', $userCode, $name, $email, $username, $hash, $phone, $roleId, $barangayId, $status);

if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    mysqli_rollback($conn);
    admin_redirect('/admin/user_form.php', ['notice' => 'User could not be created. Check for duplicate email or username.', 'type' => 'error']);
}

mysqli_stmt_close($stmt);
mysqli_commit($conn);

$actor = current_user();
$newUserId = (int)$conn->insert_id;
log_action($actor['id'] ?? null, 'CREATE_USER', 'info', 'Created staff #' . $newUserId . ' (' . $userCode . ') as ' . $roleName);

admin_redirect('/admin/users.php', ['notice' => 'User created successfully.', 'type' => 'success']);


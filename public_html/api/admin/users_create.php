<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

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
    admin_redirect('/admin/user_form.php', ['notice' => 'Enter a valid 11-digit PH mobile number starting with 09.', 'type' => 'error']);
}

$phone = preg_replace('/[^0-9]/', '', $phone);

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

// Duplicate checks
$existingEmail = admin_fetch_one('SELECT id FROM users WHERE email = ? LIMIT 1', 's', [$email]);
if ($existingEmail !== null) {
    admin_redirect('/admin/user_form.php', ['notice' => 'A user with this email already exists.', 'type' => 'error']);
}

$existingUsername = admin_fetch_one('SELECT id FROM users WHERE username = ? LIMIT 1', 's', [$username]);
if ($existingUsername !== null) {
    admin_redirect('/admin/user_form.php', ['notice' => 'A user with this username already exists.', 'type' => 'error']);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$conn = get_db_connection();
$stmt = mysqli_prepare($conn, 'INSERT INTO users (name, email, username, password_hash, phone, role_id, barangay_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');

if ($stmt === false) {
    admin_redirect('/admin/user_form.php', ['notice' => 'Unable to create user right now.', 'type' => 'error']);
}

mysqli_stmt_bind_param($stmt, 'sssssiis', $name, $email, $username, $hash, $phone, $roleId, $barangayId, $status);

if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    admin_redirect('/admin/user_form.php', ['notice' => 'User could not be created. Check for duplicate email or username.', 'type' => 'error']);
}

mysqli_stmt_close($stmt);

$actor = current_user();
log_action($actor['id'] ?? null, 'CREATE_USER', 'info', 'Created user ' . $email . ' as ' . $roleName);

admin_redirect('/admin/users.php', ['notice' => 'User created successfully.', 'type' => 'success']);


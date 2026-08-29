<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_permission('parents.create');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    admin_redirect('/admin/parent_form.php', ['notice' => 'Method not allowed.', 'type' => 'error']);
}

$firstName = trim((string)($_POST['first_name'] ?? ''));
$middleName = trim((string)($_POST['middle_name'] ?? ''));
$lastName = trim((string)($_POST['last_name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$address = trim((string)($_POST['address'] ?? ''));
$parentType = trim((string)($_POST['parent_type'] ?? 'Guardian'));
$barangayIdRaw = trim((string)($_POST['barangay_id'] ?? ''));
$barangayId = $barangayIdRaw !== '' ? (int)$barangayIdRaw : null;
$localAreaIdRaw = trim((string)($_POST['local_area_id'] ?? ''));
$localAreaId = $localAreaIdRaw !== '' ? (int)$localAreaIdRaw : null;
$status = trim((string)($_POST['status'] ?? 'active'));
$password = (string)($_POST['password'] ?? '');
$passwordConfirm = (string)($_POST['password_confirm'] ?? '');

$parentTypes = ['Father', 'Mother', 'Guardian', 'Grandparent', 'Other'];

if (
    !admin_is_valid_name_part($firstName, true)
    || !admin_is_valid_name_part($middleName, false)
    || !admin_is_valid_name_part($lastName, true)
) {
    admin_redirect('/admin/parent_form.php', ['notice' => 'Enter a valid first name and surname (letters only). Middle name is optional.', 'type' => 'error']);
}

$name = admin_combine_name($firstName, $middleName, $lastName);

if (!in_array($status, ['active', 'inactive'], true)) {
    $status = 'active';
}

if (!in_array($parentType, $parentTypes, true)) {
    $parentType = 'Guardian';
}

if ($name === '' || $email === '') {
    admin_redirect('/admin/parent_form.php', ['notice' => 'Name and email are required.', 'type' => 'error']);
}

if (!admin_is_valid_ph_mobile($phone)) {
    admin_redirect('/admin/parent_form.php', ['notice' => 'Enter a valid 11-digit PH mobile number starting with 09.', 'type' => 'error']);
}

$phone = preg_replace('/[^0-9]/', '', $phone);

if ($password === '') {
    admin_redirect('/admin/parent_form.php', ['notice' => 'Password is required.', 'type' => 'error']);
}

if (!admin_is_strong_password($password)) {
    admin_redirect('/admin/parent_form.php', ['notice' => 'Password must be at least 8 characters and include an uppercase letter, a lowercase letter, a number, and a special character.', 'type' => 'error']);
}

if ($password !== $passwordConfirm) {
    admin_redirect('/admin/parent_form.php', ['notice' => 'Password and confirm password do not match.', 'type' => 'error']);
}

// Duplicate email check
$existingEmail = admin_fetch_one('SELECT id FROM parents WHERE email = ? LIMIT 1', 's', [$email]);
if ($existingEmail !== null) {
    admin_redirect('/admin/parent_form.php', ['notice' => 'A parent with this email already exists.', 'type' => 'error']);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$ok = admin_execute(
    'INSERT INTO parents (name, email, password_hash, parent_type, phone, address, barangay_id, local_area_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
    'ssssssss',
    [$name, $email, $hash, $parentType, $phone, $address, $barangayId, $localAreaId, $status]
);

if ($ok) {
    $actor = current_user();
    log_action($actor['id'] ?? null, 'CREATE_PARENT', 'info', 'Created parent account ' . $email);
}

admin_redirect('/admin/parents.php', $ok ? ['notice' => 'Parent added.'] : ['notice' => 'Parent could not be added. Check for a duplicate email.', 'type' => 'error']);

<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_permission('parents.update');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    admin_redirect('/admin/parents.php', ['notice' => 'Method not allowed.', 'type' => 'error']);
}

$id = (int)($_POST['id'] ?? 0);
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
    admin_redirect('/admin/parent_form.php?id=' . $id, ['notice' => 'Enter a valid first name and surname (letters only). Middle name is optional.', 'type' => 'error']);
}

$name = admin_combine_name($firstName, $middleName, $lastName);

if ($id <= 0 || $name === '' || $email === '') {
    admin_redirect('/admin/parent_form.php?id=' . $id, ['notice' => 'Parent id, name, and email are required.', 'type' => 'error']);
}

$existingParent = admin_fetch_one(
    'SELECT id, barangay_id, local_area_id FROM parents WHERE id = ? LIMIT 1',
    'i',
    [$id]
);
if (!$existingParent) {
    admin_redirect('/admin/parent_form.php?id=' . $id, ['notice' => 'Parent not found.', 'type' => 'error']);
}

if (!admin_is_valid_ph_mobile($phone)) {
    admin_redirect('/admin/parent_form.php?id=' . $id, ['notice' => 'Enter a valid 11-digit PH mobile number starting with 09.', 'type' => 'error']);
}

$phone = preg_replace('/[^0-9]/', '', $phone);

if ($localAreaId !== null && $localAreaId > 0) {
    $localArea = admin_fetch_one(
        'SELECT id FROM local_areas WHERE id = ? AND barangay_id = ? AND is_active = 1 LIMIT 1',
        'ii',
        [$localAreaId, $barangayId]
    );
    $keepsExistingInactiveArea = $localAreaId === (int)($existingParent['local_area_id'] ?? 0)
        && $barangayId !== null
        && (int)$barangayId === (int)($existingParent['barangay_id'] ?? 0);

    if (!$localArea && !$keepsExistingInactiveArea) {
        admin_redirect('/admin/parent_form.php?id=' . $id, ['notice' => 'Selected Local Area is inactive or does not belong to the selected Barangay.', 'type' => 'error']);
    }
}

if (!in_array($status, ['active', 'inactive'], true)) {
    $status = 'active';
}

if (!in_array($parentType, $parentTypes, true)) {
    $parentType = 'Guardian';
}

if ($password !== '' && !admin_is_strong_password($password)) {
    admin_redirect('/admin/parent_form.php?id=' . $id, ['notice' => 'Password must be at least 8 characters and include an uppercase letter, a lowercase letter, a number, and a special character.', 'type' => 'error']);
}

if ($password !== '' && $password !== $passwordConfirm) {
    admin_redirect('/admin/parent_form.php?id=' . $id, ['notice' => 'Password and confirm password do not match.', 'type' => 'error']);
}

// Duplicate email check
$existingEmail = admin_fetch_one('SELECT id FROM parents WHERE email = ? AND id != ? LIMIT 1', 'si', [$email, $id]);
if ($existingEmail !== null) {
    admin_redirect('/admin/parent_form.php?id=' . $id, ['notice' => 'A parent with this email already exists.', 'type' => 'error']);
}

if ($password !== '') {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $ok = admin_execute(
        'UPDATE parents SET name = ?, email = ?, password_hash = ?, parent_type = ?, phone = ?, address = ?, barangay_id = ?, local_area_id = ?, status = ? WHERE id = ?',
        'ssssssssi',
        [$name, $email, $hash, $parentType, $phone, $address, $barangayId, $localAreaId, $status, $id]
    );
} else {
    $ok = admin_execute(
        'UPDATE parents SET name = ?, email = ?, parent_type = ?, phone = ?, address = ?, barangay_id = ?, local_area_id = ?, status = ? WHERE id = ?',
        'sssssssi',
        [$name, $email, $parentType, $phone, $address, $barangayId, $localAreaId, $status, $id]
    );
}

if ($ok) {
    $actor = current_user();
    log_action($actor['id'] ?? null, 'UPDATE_PARENT', 'info', 'Updated parent ' . $email . ' (' . $id . ')');
}

admin_redirect('/admin/parents.php', $ok ? ['notice' => 'Parent updated.'] : ['notice' => 'Parent could not be updated. Check for a duplicate email.', 'type' => 'error']);

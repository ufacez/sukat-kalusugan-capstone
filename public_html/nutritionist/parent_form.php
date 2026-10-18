<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/display_ids.php';

$user = nutritionist_require_access();

/*
 * GET requests redirect away — the edit form is now an inline modal
 * on parents.php, and create mode lives in the unified family form.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
	admin_redirect('/nutritionist/parents.php');
}

$parentTypes = ['Father', 'Mother', 'Guardian', 'Grandparent', 'Other'];

$action = (string)($_POST['action'] ?? '');

if ($action === 'create') {
	nutritionist_require_write('parents.create');
} elseif ($action === 'update') {
	nutritionist_require_write('parents.update');
}

$parentId = (int)($_POST['id'] ?? 0);

if ($action !== 'create' && $action !== 'update') {
	admin_redirect('/nutritionist/parents.php', ['notice' => 'No action was performed.', 'type' => 'error']);
}

$firstName = trim((string)($_POST['first_name'] ?? ''));
$middleName = trim((string)($_POST['middle_name'] ?? ''));
$lastName = trim((string)($_POST['last_name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$address = trim((string)($_POST['address'] ?? ''));
$parentType = trim((string)($_POST['parent_type'] ?? 'Guardian'));

/*
 * For non-admin nutritionists, the barangay is ALWAYS their
 * assigned barangay — they cannot pick a different one even if the
 * client tampers with the POST body. We force it server-side.
 */
$isAdmin = ($user['role'] ?? '') === 'admin';
$userBarangayId = $user['barangay_id'] ?? null;
$redirectBack = '/nutritionist/parents.php';

if (!$isAdmin) {
	if ($userBarangayId === null || $userBarangayId === '') {
		/** @disregard */
		admin_redirect($redirectBack, [
			'notice' => 'Your account is not assigned to a barangay. Contact your administrator to set one before adding parents.',
			'type' => 'error',
		]);
	}
	$barangayId = (int)$userBarangayId;
} else {
	$barangayIdRaw = trim((string)($_POST['barangay_id'] ?? ''));
	$barangayId = $barangayIdRaw !== '' ? (int)$barangayIdRaw : null;
}

$localAreaIdRaw = trim((string)($_POST['local_area_id'] ?? ''));
$localAreaId = $localAreaIdRaw !== '' ? (int)$localAreaIdRaw : null;
$status = trim((string)($_POST['status'] ?? 'active'));
$password = (string)($_POST['password'] ?? '');
$passwordConfirm = (string)($_POST['password_confirm'] ?? '');
$householdIdRaw = trim((string)($_POST['household_id'] ?? ''));
$householdId = $householdIdRaw !== '' ? (int)$householdIdRaw : null;

if (
	!admin_is_valid_name_part($firstName, true)
	|| !admin_is_valid_name_part($middleName, false)
	|| !admin_is_valid_name_part($lastName, true)
) {
	admin_redirect($redirectBack, ['notice' => 'Enter a valid first name and surname (letters only). Middle name is optional.', 'type' => 'error']);
}

$name = admin_combine_name($firstName, $middleName, $lastName);

if (!in_array($status, ['active', 'inactive'], true)) {
	$status = 'active';
}

if (!in_array($parentType, $parentTypes, true)) {
	$parentType = 'Guardian';
}

if ($name === '' || $email === '') {
	admin_redirect($redirectBack, ['notice' => 'Name and email are required.', 'type' => 'error']);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
	admin_redirect($redirectBack, ['notice' => 'Enter a valid email address.', 'type' => 'error']);
}

// Global uniqueness is enforced on ADD only — edits must always save
// (same-table conflicts still fail on the UNIQUE key with a generic
// message).
if ($action === 'create' && admin_email_in_use($email, null, null)) {
	admin_redirect($redirectBack, ['notice' => 'This email is already in use by another account. Use a different email address.', 'type' => 'error']);
}

if (!admin_is_valid_ph_mobile($phone)) {
	admin_redirect($redirectBack, ['notice' => 'Enter a valid PH mobile number (e.g. 09171234567).', 'type' => 'error']);
}

$phone = (string)admin_normalize_ph_mobile($phone);

// AES-256-GCM at-rest PII (passthrough while APP_ENCRYPTION_KEY is unset).
$name = (string)sk_encrypt_value($name);
$phone = (string)sk_encrypt_value($phone);
$address = (string)sk_encrypt_value($address);

if ($action === 'create' && $password === '') {
	admin_redirect($redirectBack, ['notice' => 'Password is required.', 'type' => 'error']);
}

if ($password !== '' && !admin_is_strong_password($password)) {
	admin_redirect($redirectBack, ['notice' => 'Password must be at least 8 characters and include an uppercase letter, a lowercase letter, a number, and a special character.', 'type' => 'error']);
}

if ($password !== '' && $password !== $passwordConfirm) {
	admin_redirect($redirectBack, ['notice' => 'Password and confirm password do not match.', 'type' => 'error']);
}

/*
 * Local-area validation: if a local area is selected, it must
 * belong to the parent's barangay so a Dela Paz Norte parent can't
 * be assigned a Malpitic purok by accident.
 */
$existingParent = null;
if ($action === 'update') {
	$existingParent = admin_fetch_one(
		'SELECT id, barangay_id, local_area_id, status FROM parents WHERE id = ? LIMIT 1',
		'i',
		[$parentId]
	);
	if (!$existingParent) {
		admin_redirect($redirectBack, ['notice' => 'Parent not found.', 'type' => 'error']);
	}
}

if ($localAreaId !== null && $localAreaId > 0) {
	if ($barangayId === null) {
		admin_redirect($redirectBack, [
			'notice' => 'A barangay is required before assigning a local area.',
			'type' => 'error',
		]);
	}

	$localAreaCheck = admin_fetch_one(
		'SELECT id FROM local_areas WHERE id = ? AND barangay_id = ? AND is_active = 1 LIMIT 1',
		'ii',
		[$localAreaId, $barangayId]
	);
	$keepsExistingInactiveArea = $action === 'update'
		&& $existingParent !== null
		&& $localAreaId === (int)($existingParent['local_area_id'] ?? 0)
		&& $barangayId === (int)($existingParent['barangay_id'] ?? 0);

	if (!$localAreaCheck && !$keepsExistingInactiveArea) {
		admin_redirect($redirectBack, [
			'notice' => 'The selected local area is inactive or does not belong to the assigned barangay.',
			'type' => 'error',
		]);
	}
}

/*
 * Household validation: if a household is selected, it must
 * belong to the parent's barangay.
 */
if ($householdId !== null && $barangayId !== null) {
	$householdCheck = admin_fetch_one(
		'SELECT id FROM households WHERE id = ? AND barangay_id = ? AND status = "active" LIMIT 1',
		'ii',
		[$householdId, $barangayId]
	);
	if (!$householdCheck) {
		$householdId = null;
	}
}

if ($action === 'create') {
	$hash = password_hash($password, PASSWORD_DEFAULT);
	$parentCode = sk_next_display_code('PAR');
	$ok = admin_execute(
		'INSERT INTO parents (parent_code, name, email, password_hash, parent_type, phone, address, barangay_id, local_area_id, household_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
		'sssssssiiis',
		[$parentCode, $name, $email, $hash, $parentType, $phone, $address, $barangayId, $localAreaId, $householdId, $status]
	);

	if ($ok) {
		$actor = current_user();
		$newParentId = (int)get_db_connection()->insert_id;
		log_action($actor['id'] ?? null, 'CREATE_PARENT', 'info', 'Created parent account ' . $parentCode . ' (#' . $newParentId . ') via nutritionist form');
	}

	admin_redirect('/nutritionist/parents.php', $ok ? ['notice' => 'Parent added.'] : ['notice' => 'Parent could not be added. Check for a duplicate email.', 'type' => 'error']);
}

if ($action === 'update' && $parentId > 0) {
	if ($password !== '') {
		$hash = password_hash($password, PASSWORD_DEFAULT);
		$ok = admin_execute(
			'UPDATE parents SET name = ?, email = ?, password_hash = ?, parent_type = ?, phone = ?, address = ?, barangay_id = ?, local_area_id = ?, household_id = ?, status = ? WHERE id = ?',
			'ssssssiiisi',
			[$name, $email, $hash, $parentType, $phone, $address, $barangayId, $localAreaId, $householdId, $status, $parentId]
		);
	} else {
		$ok = admin_execute(
			'UPDATE parents SET name = ?, email = ?, parent_type = ?, phone = ?, address = ?, barangay_id = ?, local_area_id = ?, household_id = ?, status = ? WHERE id = ?',
			'sssssiiisi',
			[$name, $email, $parentType, $phone, $address, $barangayId, $localAreaId, $householdId, $status, $parentId]
		);
	}

	if ($ok) {
		// Status flip via Edit cascades to children, same as Archive/Restore.
		if ($status !== (string)($existingParent['status'] ?? 'active')) {
			admin_cascade_parent_status($parentId, $status);
		}
		// Barangay move cascades too — otherwise children keep the old
		// (or NULL) barangay_id and vanish from dashboard counts until
		// each child edit form is re-saved.
		if ($barangayId !== null && (int)$barangayId !== (int)($existingParent['barangay_id'] ?? 0)) {
			admin_cascade_parent_barangay($parentId, $barangayId);
		}
		$actor = current_user();
		log_action($actor['id'] ?? null, 'UPDATE_PARENT', 'info', 'Updated parent #' . $parentId . ' via nutritionist form');
	}

	admin_redirect('/nutritionist/parents.php', $ok ? ['notice' => 'Parent updated.'] : ['notice' => 'Parent could not be updated. Check for a duplicate email.', 'type' => 'error']);
}

admin_redirect('/nutritionist/parents.php', ['notice' => 'No action was performed.', 'type' => 'error']);
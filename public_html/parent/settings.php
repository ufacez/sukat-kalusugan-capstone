<?php

require_once __DIR__ . '/../includes/parent_helpers.php';

$user = parent_require_access();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password'])) {
	$oldPassword = (string)($_POST['old_password'] ?? '');
	$password = (string)($_POST['password'] ?? '');
	$passwordRepeat = (string)($_POST['password_repeat'] ?? '');
	$current = admin_fetch_one('SELECT password_hash FROM parents WHERE id = ? LIMIT 1', 'i', [(int)$user['id']]);
	if ($current === null || $oldPassword === '' || !password_verify($oldPassword, (string)$current['password_hash'])) {
		admin_redirect('/parent/settings.php', ['notice' => 'Current password is incorrect.', 'type' => 'error']);
	}
	if ($password === '' || $password !== $passwordRepeat) {
		admin_redirect('/parent/settings.php', ['notice' => 'New password and repeat do not match.', 'type' => 'error']);
	}
	if (!admin_is_strong_password($password)) {
		admin_redirect('/parent/settings.php', ['notice' => 'New password must be at least 8 characters and include upper, lower, number, and special characters.', 'type' => 'error']);
	}
	$ok = admin_execute('UPDATE parents SET password_hash = ? WHERE id = ?', 'si', [password_hash($password, PASSWORD_DEFAULT), (int)$user['id']]);
	admin_redirect('/parent/settings.php', $ok
		? ['notice' => 'Password updated successfully.', 'type' => 'success']
		: ['notice' => 'Password could not be updated. Please try again.', 'type' => 'error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_account']) && !isset($_POST['update_password'])) {
	$firstName = trim((string)($_POST['first_name'] ?? ''));
	$lastName  = trim((string)($_POST['last_name'] ?? ''));
	$name      = admin_combine_name($firstName, '', $lastName);
	$email     = trim((string)($_POST['email'] ?? ''));
	$username  = trim((string)($_POST['username'] ?? ''));
	$phone     = trim((string)($_POST['phone'] ?? ''));
	$address   = trim((string)($_POST['address'] ?? ''));
	$parentType = trim((string)($_POST['parent_type'] ?? 'Guardian'));
	$oldPassword    = (string)($_POST['old_password'] ?? '');
	$password       = (string)($_POST['password'] ?? '');
	$passwordRepeat = (string)($_POST['password_repeat'] ?? '');

	if ($firstName === '' || !admin_is_valid_name_part($firstName, true)) {
		admin_redirect('/parent/settings.php', ['notice' => 'Enter a valid first name (letters only, at least 2 characters).', 'type' => 'error']);
	}
	if ($lastName === '' || !admin_is_valid_name_part($lastName, true)) {
		admin_redirect('/parent/settings.php', ['notice' => 'Enter a valid last name (letters only, at least 2 characters).', 'type' => 'error']);
	}
	if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
		admin_redirect('/parent/settings.php', ['notice' => 'Enter a valid email address.', 'type' => 'error']);
	}
	if ($username === '' || preg_match('/^[A-Za-z0-9._]{3,30}$/', $username) !== 1) {
		admin_redirect('/parent/settings.php', ['notice' => 'Enter a valid username (3-30 characters: letters, numbers, dot, or underscore).', 'type' => 'error']);
	}
	if ($phone !== '' && !admin_is_valid_ph_mobile($phone)) {
		admin_redirect('/parent/settings.php', ['notice' => 'Enter a valid PH mobile number (e.g. 09171234567).', 'type' => 'error']);
	}
	$phone = $phone !== '' ? (string)admin_normalize_ph_mobile($phone) : '';
	if ($address !== '' && strlen($address) > 255) {
		admin_redirect('/parent/settings.php', ['notice' => 'Address is too long (max 255 characters).', 'type' => 'error']);
	}

	// Duplicate enforcement lives on the ADD paths only — self-save must
	// always go through (same-table conflicts still fail on the UNIQUE
	// key with a generic message).

	$allowedTypes = ['Father', 'Mother', 'Guardian', 'Grandparent', 'Other'];
	if (!in_array($parentType, $allowedTypes, true)) {
		$parentType = 'Guardian';
	}

	$current = admin_fetch_one('SELECT password_hash FROM parents WHERE id = ? LIMIT 1', 'i', [(int)$user['id']]);

	if ($current === null) {
		admin_redirect('/parent/settings.php', ['notice' => 'Your account could not be loaded.', 'type' => 'error']);
	}

	$wantsNewPassword = $password !== '' || $passwordRepeat !== '';
	if ($wantsNewPassword) {
		if ($oldPassword === '' || !password_verify($oldPassword, (string)$current['password_hash'])) {
			admin_redirect('/parent/settings.php', ['notice' => 'Current password is incorrect.', 'type' => 'error']);
		}
		if ($password !== $passwordRepeat) {
			admin_redirect('/parent/settings.php', ['notice' => 'New password and repeat do not match.', 'type' => 'error']);
		}
		if (!admin_is_strong_password($password)) {
			admin_redirect('/parent/settings.php', ['notice' => 'New password must be at least 8 characters and include upper, lower, number, and special characters.', 'type' => 'error']);
		}
	}

	$sql = 'UPDATE parents SET name = ?, email = ?, username = ?, phone = ?, address = ?, parent_type = ?';
	$params = [$name, $email, $username, $phone, $address !== '' ? $address : null, $parentType];
	$types = 'ssssss';

	if ($wantsNewPassword) {
		$sql .= ', password_hash = ?';
		$params[] = password_hash($password, PASSWORD_DEFAULT);
		$types .= 's';

		// A fresh password clears the import-minted temporary-password
		// hold — but only when the column exists (pre-migration safety).
		$flagProbe = admin_fetch_one(
			"SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parents' AND COLUMN_NAME = 'must_change_password'"
		);
		if ((int)($flagProbe['c'] ?? 0) > 0) {
			$sql .= ', must_change_password = 0';
		}
	}

	$sql .= ' WHERE id = ?';
	$params[] = (int)$user['id'];
	$types .= 'i';

	$ok = admin_execute($sql, $types, $params);

	if ($ok) {
		$actor = current_user();
		log_action($actor['id'] ?? null, 'UPDATE_OWN_ACCOUNT', 'info', 'Parent updated their own account details');
		$_SESSION['auth']['name'] = $name;
		$_SESSION['auth']['email'] = $email;
		$_SESSION['auth']['username'] = $username;
		$_SESSION['auth']['phone'] = $phone;
		$_SESSION['auth']['address'] = $address;
		$_SESSION['auth']['parent_type'] = $parentType;
		if ($wantsNewPassword && isset($_SESSION['auth']['must_change_password'])) {
			unset($_SESSION['auth']['must_change_password']);
		}
	}

	admin_redirect('/parent/settings.php', $ok ? ['notice' => 'Account updated successfully.', 'type' => 'success'] : ['notice' => 'Account could not be updated. This email may already be in use.', 'type' => 'error']);
}

$profile = admin_fetch_one(
	'SELECT p.id, p.name, p.email, p.username, p.parent_type, p.phone, p.address, p.status, p.created_at
	 FROM parents p
	 WHERE p.id = ?
	 LIMIT 1',
	'i',
	[(int)$user['id']]
);

if ($profile === null) {
	deny_access('Parent profile could not be loaded.', 404);
}

$fullName = trim((string)($profile['name'] ?? ''));
$nameParts = $fullName === '' ? ['', ''] : preg_split('/\s+/', $fullName);
if (count($nameParts) === 1) {
	$firstNameValue = $nameParts[0];
	$lastNameValue  = '';
} else {
	$lastNameValue  = (string)array_pop($nameParts);
	$firstNameValue = implode(' ', $nameParts);
}

$avatarColor    = admin_avatar_color($fullName !== '' ? $fullName : ($profile['email'] ?? 'Parent'));
$avatarInitials = $fullName !== '' ? admin_initials($fullName) : strtoupper(substr((string)($profile['email'] ?? 'P'), 0, 1));
$storedAddress  = (string)($profile['address'] ?? '');
$lastLoginText = 'Today, ' . date('g:i A');

$settingsProfile = $profile;
$settingsRoleLabel = 'Parent';
$settingsUserCode = '';
$settingsLastLoginText = $lastLoginText;
$settingsEscaper = 'parent_e';
$settingsProfile['username'] = (string)($profile['username'] ?? ($user['username'] ?? ''));
$settingsShowUsername = true;
$settingsMustChangeBanner = (($_GET['must_change'] ?? '') !== '' && ($_GET['must_change'] ?? '') !== '0')
    || parent_must_change_password((int)($user['id'] ?? 0));

$actions = '<a class="admin-btn-secondary" href="' . parent_e(app_url('/parent/dashboard.php')) . '">' . admin_action_icon('back') . ' Dashboard</a>';
parent_layout_start('Settings', 'Manage your account details.', 'settings', $actions);
require __DIR__ . '/../includes/account_settings_view.php';
parent_layout_end();

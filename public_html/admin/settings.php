<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();
admin_require_access('settings.view');

$actor = current_user();

// ── Profile + address save (Basic info / Address cards) ──────────────
// This form no longer carries the password fields. Changing the password
// is a separate form + action (update_password below) so a profile save
// can never silently rotate credentials.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_account'])) {
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $lastName  = trim((string)($_POST['last_name'] ?? ''));
    $name      = admin_combine_name($firstName, '', $lastName);
    $email     = trim((string)($_POST['email'] ?? ''));
    $username  = trim((string)($_POST['username'] ?? ''));
    $phone     = trim((string)($_POST['phone'] ?? ''));
    $address   = trim((string)($_POST['address'] ?? ''));

    if ($firstName === '' || !admin_is_valid_name_part($firstName, true)) {
        admin_redirect('/admin/settings.php', ['notice' => 'Enter a valid first name (letters only, at least 2 characters).', 'type' => 'error']);
    }
    if ($lastName === '' || !admin_is_valid_name_part($lastName, true)) {
        admin_redirect('/admin/settings.php', ['notice' => 'Enter a valid last name (letters only, at least 2 characters).', 'type' => 'error']);
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        admin_redirect('/admin/settings.php', ['notice' => 'Enter a valid email address.', 'type' => 'error']);
    }
    if ($username === '' || preg_match('/^[A-Za-z0-9._]{3,30}$/', $username) !== 1) {
        admin_redirect('/admin/settings.php', ['notice' => 'Enter a valid username (3-30 characters: letters, numbers, dot, or underscore).', 'type' => 'error']);
    }
    if ($phone !== '' && !admin_is_valid_ph_mobile($phone)) {
        admin_redirect('/admin/settings.php', ['notice' => 'Enter a valid PH mobile number (e.g. 09171234567).', 'type' => 'error']);
    }
    $phone = $phone !== '' ? (string)admin_normalize_ph_mobile($phone) : '';
    if ($address !== '' && strlen($address) > 255) {
        admin_redirect('/admin/settings.php', ['notice' => 'Address is too long (max 255 characters).', 'type' => 'error']);
    }

    // Duplicate enforcement lives on the ADD paths only — self-save must
    // always go through (same-table conflicts still fail on the UNIQUE
    // keys with a generic message).
    $sql    = 'UPDATE users SET name = ?, email = ?, username = ?, phone = ?, address = ?';
    $params = [$name, $email, $username, $phone, $address !== '' ? $address : null];
    $types  = 'sssss';

    $sql .= ' WHERE id = ?';
    $params[] = (int)$actor['id'];
    $types .= 'i';

    $ok = admin_execute($sql, $types, $params);

    if ($ok) {
        $_SESSION['auth']['name']  = $name;
        $_SESSION['auth']['email'] = $email;
        $_SESSION['auth']['username'] = $username;
        log_action((int)$actor['id'], 'UPDATE_OWN_ACCOUNT', 'info', 'Admin updated their own account details');
    }

    admin_redirect('/admin/settings.php', $ok
        ? ['notice' => 'Account updated successfully.', 'type' => 'success']
        : ['notice' => 'Account could not be updated. This email or username may already be in use.', 'type' => 'error']);
}

// ── Password change (own Change password card) ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password'])) {
    $oldPassword    = (string)($_POST['old_password'] ?? '');
    $password       = (string)($_POST['password'] ?? '');
    $passwordRepeat = (string)($_POST['password_repeat'] ?? '');

    $current = admin_fetch_one('SELECT password_hash FROM users WHERE id = ? LIMIT 1', 'i', [(int)$actor['id']]);

    if ($current === null) {
        admin_redirect('/admin/settings.php', ['notice' => 'Your account could not be loaded.', 'type' => 'error']);
    }

    // Proving you know the current password stops a hijacked (or left-open)
    // session from silently locking the real owner out of their account.
    if ($oldPassword === '' || !password_verify($oldPassword, (string)$current['password_hash'])) {
        admin_redirect('/admin/settings.php', ['notice' => 'Old password is incorrect.', 'type' => 'error']);
    }

    if ($password === '' || $password !== $passwordRepeat) {
        admin_redirect('/admin/settings.php', ['notice' => 'New password and repeat do not match.', 'type' => 'error']);
    }

    if (!admin_is_strong_password($password)) {
        admin_redirect('/admin/settings.php', ['notice' => 'New password must be at least 8 characters and include upper, lower, number, and special characters.', 'type' => 'error']);
    }

    $ok = admin_execute(
        'UPDATE users SET password_hash = ? WHERE id = ?',
        'si',
        [password_hash($password, PASSWORD_DEFAULT), (int)$actor['id']]
    );

    if ($ok) {
        log_action((int)$actor['id'], 'UPDATE_OWN_PASSWORD', 'info', 'Admin changed their own password');
    }

    admin_redirect('/admin/settings.php', $ok
        ? ['notice' => 'Password updated successfully.', 'type' => 'success']
        : ['notice' => 'Password could not be updated. Please try again.', 'type' => 'error']);
}

$myAccount = admin_fetch_one(
    'SELECT u.name, u.email, u.username, u.user_code, u.phone, u.address, u.last_login, r.name AS role_name
     FROM users u
     LEFT JOIN roles r ON r.id = u.role_id
     WHERE u.id = ? LIMIT 1',
    'i',
    [(int)$actor['id']]
);

// Split the stored full name back into first/last for the input fields.
// The convention in this codebase is to combine them in PHP and store the
// full string in users.name; splitting on the *last* whitespace keeps
// "Shekainah Lacson" + "Gonzales" working without losing the middle name.
$fullName = trim((string)($myAccount['name'] ?? ''));
$nameParts = $fullName === '' ? ['', ''] : preg_split('/\s+/', $fullName);
if (count($nameParts) === 1) {
    $firstNameValue = $nameParts[0];
    $lastNameValue  = '';
} else {
    $lastNameValue  = (string)array_pop($nameParts);
    $firstNameValue = implode(' ', $nameParts);
}

$avatarColor    = admin_avatar_color($fullName !== '' ? $fullName : ($actor['email'] ?? 'User'));
$avatarInitials = $fullName !== '' ? admin_initials($fullName) : strtoupper(substr((string)($actor['email'] ?? 'U'), 0, 1));
$storedAddress  = (string)($myAccount['address'] ?? '');

$roleRaw   = strtolower(trim((string)($myAccount['role_name'] ?? '')));
$roleNames = ['admin' => 'Administrator', 'nutritionist' => 'Nutritionist'];
$roleLabel = $roleNames[$roleRaw] ?? ($roleRaw !== '' ? ucfirst($roleRaw) : 'Staff');

$lastLoginRaw  = (string)($myAccount['last_login'] ?? '');
$lastLoginTs   = $lastLoginRaw !== '' ? strtotime($lastLoginRaw) : false;
$lastLoginText = $lastLoginTs ? date('M j, Y \a\t g:i A', $lastLoginTs) : 'Not yet signed in';

$userCode = (string)($myAccount['user_code'] ?? '');
$myAccount['username'] = (string)($myAccount['username'] ?? ($actor['username'] ?? ''));

$settingsProfile = $myAccount;
$settingsRoleLabel = $roleLabel;
$settingsUserCode = $userCode;
$settingsLastLoginText = $lastLoginText;
$settingsEscaper = 'admin_e';
$settingsShowUsername = true;

admin_layout_start('Settings', 'Manage your account details.', 'settings');
require __DIR__ . '/../includes/account_settings_view.php';
admin_layout_end();

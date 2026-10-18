<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

start_secure_session();
$actor = nutritionist_require_access();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password'])) {
    nutritionist_require_write('settings.update');
    $oldPassword = (string)($_POST['old_password'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $passwordRepeat = (string)($_POST['password_repeat'] ?? '');
    $current = admin_fetch_one('SELECT password_hash FROM users WHERE id = ? LIMIT 1', 'i', [(int)$actor['id']]);
    if ($current === null || $oldPassword === '' || !password_verify($oldPassword, (string)$current['password_hash'])) {
        admin_redirect('/nutritionist/settings.php', ['notice' => 'Old password is incorrect.', 'type' => 'error']);
    }
    if ($password === '' || $password !== $passwordRepeat) {
        admin_redirect('/nutritionist/settings.php', ['notice' => 'New password and repeat do not match.', 'type' => 'error']);
    }
    if (!admin_is_strong_password($password)) {
        admin_redirect('/nutritionist/settings.php', ['notice' => 'New password must be at least 8 characters and include upper, lower, number, and special characters.', 'type' => 'error']);
    }
    $ok = admin_execute('UPDATE users SET password_hash = ? WHERE id = ?', 'si', [password_hash($password, PASSWORD_DEFAULT), (int)$actor['id']]);
    admin_redirect('/nutritionist/settings.php', $ok
        ? ['notice' => 'Password updated successfully.', 'type' => 'success']
        : ['notice' => 'Password could not be updated. Please try again.', 'type' => 'error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_account']) && !isset($_POST['update_password'])) {
    nutritionist_require_write('settings.update');
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $lastName  = trim((string)($_POST['last_name'] ?? ''));
    $name      = admin_combine_name($firstName, '', $lastName);
    $email     = trim((string)($_POST['email'] ?? ''));
    $username  = trim((string)($_POST['username'] ?? ''));
    $phone     = trim((string)($_POST['phone'] ?? ''));
    $address   = trim((string)($_POST['address'] ?? ''));
    $oldPassword    = (string)($_POST['old_password'] ?? '');
    $password       = (string)($_POST['password'] ?? '');
    $passwordRepeat = (string)($_POST['password_repeat'] ?? '');

    if ($firstName === '' || !admin_is_valid_name_part($firstName, true)) {
        admin_redirect('/nutritionist/settings.php', ['notice' => 'Enter a valid first name (letters only, at least 2 characters).', 'type' => 'error']);
    }
    if ($lastName === '' || !admin_is_valid_name_part($lastName, true)) {
        admin_redirect('/nutritionist/settings.php', ['notice' => 'Enter a valid last name (letters only, at least 2 characters).', 'type' => 'error']);
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        admin_redirect('/nutritionist/settings.php', ['notice' => 'Enter a valid email address.', 'type' => 'error']);
    }
    if ($username === '' || preg_match('/^[A-Za-z0-9._]{3,30}$/', $username) !== 1) {
        admin_redirect('/nutritionist/settings.php', ['notice' => 'Enter a valid username (3-30 characters: letters, numbers, dot, or underscore).', 'type' => 'error']);
    }
    if ($phone !== '' && !admin_is_valid_ph_mobile($phone)) {
        admin_redirect('/nutritionist/settings.php', ['notice' => 'Enter a valid PH mobile number (e.g. 09171234567).', 'type' => 'error']);
    }
    $phone = $phone !== '' ? (string)admin_normalize_ph_mobile($phone) : '';
    if ($address !== '' && strlen($address) > 255) {
        admin_redirect('/nutritionist/settings.php', ['notice' => 'Address is too long (max 255 characters).', 'type' => 'error']);
    }

    // Duplicate enforcement lives on the ADD paths only — self-save must
    // always go through (same-table conflicts still fail on the UNIQUE
    // keys with a generic message).

    $current = admin_fetch_one('SELECT password_hash, email FROM users WHERE id = ? LIMIT 1', 'i', [(int)$actor['id']]);

    if ($current === null) {
        admin_redirect('/nutritionist/settings.php', ['notice' => 'Your account could not be loaded.', 'type' => 'error']);
    }

    $wantsNewPassword = $password !== '' || $passwordRepeat !== '';
    if ($wantsNewPassword) {
        if ($oldPassword === '' || !password_verify($oldPassword, (string)$current['password_hash'])) {
            admin_redirect('/nutritionist/settings.php', ['notice' => 'Old password is incorrect.', 'type' => 'error']);
        }

        if ($password === '' || $password !== $passwordRepeat) {
            admin_redirect('/nutritionist/settings.php', ['notice' => 'New password and repeat do not match.', 'type' => 'error']);
        }

        if (!admin_is_strong_password($password)) {
            admin_redirect('/nutritionist/settings.php', ['notice' => 'New password must be at least 8 characters and include upper, lower, number, and special characters.', 'type' => 'error']);
        }
    }

    $sql    = 'UPDATE users SET name = ?, email = ?, username = ?, phone = ?, address = ?';
    $params = [$name, $email, $username, $phone, $address !== '' ? $address : null];
    $types  = 'sssss';

    if ($wantsNewPassword) {
        $sql .= ', password_hash = ?';
        $params[] = password_hash($password, PASSWORD_DEFAULT);
        $types .= 's';
    }

    $sql .= ' WHERE id = ?';
    $params[] = (int)$actor['id'];
    $types .= 'i';

    $ok = admin_execute($sql, $types, $params);

    if ($ok) {
        $_SESSION['auth']['name']  = $name;
        $_SESSION['auth']['email'] = $email;
        $_SESSION['auth']['username'] = $username;
        $_SESSION['auth']['phone'] = $phone;
        log_action((int)$actor['id'], 'UPDATE_OWN_ACCOUNT', 'info', 'Nutritionist updated their own account details');
    }

    admin_redirect('/nutritionist/settings.php', $ok
        ? ['notice' => 'Account updated successfully.', 'type' => 'success']
        : ['notice' => 'Account could not be updated. This email or username may already be in use.', 'type' => 'error']);
}

$myAccount = admin_fetch_one('SELECT name, email, username, user_code, phone, address, last_login FROM users WHERE id = ? LIMIT 1', 'i', [(int)$actor['id']]);
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
$lastLoginRaw = (string)($myAccount['last_login'] ?? '');
$lastLoginTimestamp = $lastLoginRaw !== '' ? strtotime($lastLoginRaw) : false;
$lastLoginText = $lastLoginTimestamp !== false
    ? date('M j, Y \a\t g:i A', $lastLoginTimestamp)
    : 'Not yet signed in';

$settingsProfile = $myAccount;
$settingsRoleLabel = 'Nutritionist';
$settingsUserCode = (string)($myAccount['user_code'] ?? '');
$settingsLastLoginText = $lastLoginText;
$settingsEscaper = 'nutritionist_e';
$settingsShowUsername = true;

$actions = '<a class="admin-btn-secondary" href="' . nutritionist_e(app_url('/nutritionist/dashboard.php')) . '">' . admin_action_icon('back') . ' Dashboard</a>';
nutritionist_layout_start('Settings', 'Manage your account details.', 'settings', $actions);
require __DIR__ . '/../includes/account_settings_view.php';
nutritionist_layout_end();

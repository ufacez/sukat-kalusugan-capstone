<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

start_secure_session();
$actor = nutritionist_require_access();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_account'])) {
    nutritionist_require_write('settings.update');
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $lastName  = trim((string)($_POST['last_name'] ?? ''));
    $name      = admin_combine_name($firstName, '', $lastName);
    $email     = trim((string)($_POST['email'] ?? ''));
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
    if ($phone !== '' && !preg_match('/^09\d{9}$/', $phone)) {
        admin_redirect('/nutritionist/settings.php', ['notice' => 'Enter a valid 11-digit PH mobile number starting with 09.', 'type' => 'error']);
    }
    if ($address !== '' && strlen($address) > 255) {
        admin_redirect('/nutritionist/settings.php', ['notice' => 'Address is too long (max 255 characters).', 'type' => 'error']);
    }

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

        if (strlen($password) < 8 || !preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
            admin_redirect('/nutritionist/settings.php', ['notice' => 'New password must be at least 8 characters and include upper, lower, number, and special characters.', 'type' => 'error']);
        }
    }

    $sql    = 'UPDATE users SET name = ?, email = ?, phone = ?, address = ?';
    $params = [$name, $email, $phone, $address !== '' ? $address : null];
    $types  = 'ssss';

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
        $_SESSION['auth']['phone'] = $phone;
        log_action((int)$actor['id'], 'UPDATE_OWN_ACCOUNT', 'info', 'Nutritionist updated their own account details');
    }

    admin_redirect('/nutritionist/settings.php', $ok
        ? ['notice' => 'Account updated successfully.', 'type' => 'success']
        : ['notice' => 'Account could not be updated. Check for a duplicate email.', 'type' => 'error']);
}

$myAccount = admin_fetch_one('SELECT name, email, phone, address FROM users WHERE id = ? LIMIT 1', 'i', [(int)$actor['id']]);
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

$actions = '<a class="admin-btn-secondary" href="' . nutritionist_e(app_url('/nutritionist/dashboard.php')) . '">' . admin_action_icon('back') . ' Dashboard</a>';
nutritionist_layout_start('Settings', 'Manage your account details.', 'settings', $actions);
?>
<form method="post" data-validate-form class="admin-settings-form">
    <input type="hidden" name="update_account" value="1">
    <div class="admin-flash is-error" data-validate-banner style="display:none;"></div>

    <div class="admin-settings-grid">
        <section class="admin-section admin-settings-card">
            <header class="admin-section-head">
                <h2 class="admin-section-title">General Information</h2>
            </header>

            <div class="admin-settings-avatar">
                <span class="admin-avatar admin-avatar--lg" style="background:<?php echo admin_e($avatarColor); ?>;">
                    <?php echo admin_e($avatarInitials); ?>
                </span>
            </div>

            <h3 class="admin-settings-subhead">Basic Info</h3>

            <div class="admin-form-grid">
                <label class="admin-field">
                    <span>First Name</span>
                    <input id="account_first_name" name="first_name" required data-validate="name" data-label="First name" autocomplete="given-name" value="<?php echo admin_e($firstNameValue); ?>">
                    <span class="admin-field-message"></span>
                </label>
                <label class="admin-field">
                    <span>Last Name</span>
                    <input id="account_last_name" name="last_name" required data-validate="name" data-label="Last name" autocomplete="family-name" value="<?php echo admin_e($lastNameValue); ?>">
                    <span class="admin-field-message"></span>
                </label>

                <label class="admin-field admin-field-wide">
                    <span>Email</span>
                    <input id="account_email" type="email" name="email" required data-validate="email" data-label="Email" autocomplete="email" value="<?php echo admin_e($myAccount['email'] ?? ''); ?>">
                    <span class="admin-field-message"></span>
                </label>

                <label class="admin-field">
                    <span>Phone</span>
                    <input id="account_phone" name="phone" data-validate="phone-ph" data-label="Phone" autocomplete="tel" value="<?php echo admin_e($myAccount['phone'] ?? ''); ?>" placeholder="+1 (000) 000-0000">
                    <span class="admin-field-message"></span>
                </label>
                <div class="admin-field admin-field-wide">
                    <span>Address</span>
                    <div class="admin-address-picker" data-psgc-picker data-psgc-address-target="account_address">
                        <label class="admin-field">
                            <span>Province</span>
                            <select data-psgc="province"><option value="">Loading provinces...</option></select>
                        </label>
                        <label class="admin-field">
                            <span>City / Municipality</span>
                            <select data-psgc="city" disabled><option value="">-- Select province first --</option></select>
                        </label>
                        <label class="admin-field">
                            <span>Barangay</span>
                            <select data-psgc="barangay" disabled><option value="">-- Select city/municipality first --</option></select>
                        </label>
                    </div>
                    <label class="admin-field" style="margin-top:10px;">
                        <span>House no. / street</span>
                        <input data-psgc="street" placeholder="143 Purok 6" value="">
                    </label>
                    <div class="admin-address-status" data-psgc-status></div>
                    <label class="admin-field" style="margin-top:10px;">
                        <span>Full address</span>
                        <textarea id="account_address" name="address" maxlength="255" rows="2" placeholder="Auto-built from province / city / barangay / street"><?php echo admin_e($storedAddress); ?></textarea>
                    </label>
                </div>
            </div>
        </section>

        <aside class="admin-section admin-settings-card admin-settings-card--narrow">
            <header class="admin-section-head">
                <h2 class="admin-section-title">Change password</h2>
            </header>

            <div class="admin-form-grid admin-form-grid--single">
                <label class="admin-field">
                    <span>Old password</span>
                    <input id="account_old_password" type="password" name="old_password" autocomplete="current-password" placeholder="••••••••••">
                    <span class="admin-field-message"></span>
                </label>
                <label class="admin-field">
                    <span>New password</span>
                    <input id="account_password" type="password" name="password" data-validate="password" data-label="New password" autocomplete="new-password" placeholder="Leave blank to keep current">
                    <span class="admin-field-message"></span>
                    <ul class="admin-pw-checklist" data-pw-checklist-for="account_password">
                        <li data-pw-rule="length">At least 8 characters</li>
                        <li data-pw-rule="upper">One uppercase letter</li>
                        <li data-pw-rule="lower">One lowercase letter</li>
                        <li data-pw-rule="number">One number</li>
                        <li data-pw-rule="special">One special character</li>
                    </ul>
                    <div class="admin-pw-strength" data-pw-strength-for="account_password">
                        <div class="admin-pw-strength-track"><div class="admin-pw-strength-fill"></div></div>
                        <div class="admin-pw-strength-label"></div>
                    </div>
                </label>
                <label class="admin-field">
                    <span>Repeat New password</span>
                    <input id="account_password_repeat" type="password" name="password_repeat" data-validate="confirm-password" data-label="Repeat new password" data-match="account_password" autocomplete="new-password" placeholder="Confirm new password">
                    <span class="admin-field-message"></span>
                </label>
            </div>
        </aside>
    </div>

    <div class="admin-settings-footer">
        <button class="admin-btn" type="submit"><?php echo admin_action_icon('save'); ?> Save All Changes</button>
    </div>
</form>
<?php
nutritionist_layout_end();

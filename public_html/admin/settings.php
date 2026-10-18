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

    if (strlen($password) < 8 || !preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
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

admin_layout_start('Account settings', 'Manage your profile, contact details, and password.', 'settings');
?>
<div class="acct-settings" data-account-settings>

    <!-- ── Profile header ─────────────────────────────────────────── -->
    <section class="acct-hero">
        <span class="acct-hero-avatar" style="background:<?php echo admin_e($avatarColor); ?>;" aria-hidden="true"><?php echo admin_e($avatarInitials); ?></span>
        <div class="acct-hero-info">
            <h2 class="acct-hero-name"><?php echo admin_e($fullName !== '' ? $fullName : 'Unnamed account'); ?></h2>
            <div class="acct-hero-meta">
                <span class="acct-role"><?php echo admin_e($roleLabel); ?></span>
                <?php if ($userCode !== ''): ?>
                    <span class="acct-id-badge"><?php echo admin_e($userCode); ?></span>
                <?php endif; ?>
            </div>
            <p class="acct-hero-signin">Last sign-in &middot; <?php echo admin_e($lastLoginText); ?></p>
        </div>
        <div class="acct-hero-actions">
            <!-- TODO: profile photo upload is not implemented yet. These controls
                 are intentionally disabled so they never fake a working upload. -->
            <button type="button" class="admin-btn-secondary" disabled title="Photo upload is not available yet.">Change photo</button>
            <button type="button" class="admin-btn-secondary" disabled title="Photo removal is not available yet.">Remove</button>
        </div>
    </section>

    <div class="acct-grid">
        <!-- ── Left column: Basic info + Address ──────────────────── -->
        <form method="post" id="account-profile-form" class="acct-col-main" data-account-profile-form data-validate-form>
            <input type="hidden" name="update_account" value="1">
            <div class="admin-flash is-error" data-validate-banner style="display:none;"></div>

            <section class="admin-section acct-card">
                <header class="admin-section-head">
                    <h2 class="admin-section-title">Basic info</h2>
                </header>

                <div class="admin-form-grid acct-form-grid">
                    <label class="admin-field">
                        <span>First name</span>
                        <input id="account_first_name" name="first_name" required data-validate="name" data-label="First name" autocomplete="given-name" placeholder="Juan" value="<?php echo admin_e($firstNameValue); ?>">
                        <span class="admin-field-message"></span>
                    </label>
                    <label class="admin-field">
                        <span>Last name</span>
                        <input id="account_last_name" name="last_name" required data-validate="name" data-label="Last name" autocomplete="family-name" placeholder="Dela Cruz" value="<?php echo admin_e($lastNameValue); ?>">
                        <span class="admin-field-message"></span>
                    </label>

                    <label class="admin-field">
                        <span>Email</span>
                        <input id="account_email" type="email" name="email" required data-validate="email" data-label="Email" autocomplete="email" placeholder="juan@example.com" value="<?php echo admin_e($myAccount['email'] ?? ''); ?>">
                        <span class="admin-field-message"></span>
                    </label>
                    <label class="admin-field">
                        <span>Username</span>
                        <input id="account_username" name="username" required data-validate="username" data-label="Username" autocomplete="username" maxlength="30" placeholder="juandc" value="<?php echo admin_e($myAccount['username'] ?? ''); ?>">
                        <span class="admin-field-message"></span>
                    </label>

                    <label class="admin-field">
                        <span>Phone</span>
                        <input id="account_phone" name="phone" data-validate="phone-ph" data-label="Phone" autocomplete="tel" inputmode="tel" placeholder="09171234567" value="<?php echo admin_e($myAccount['phone'] ?? ''); ?>">
                        <span class="admin-field-message"></span>
                    </label>
                </div>
            </section>

            <section class="admin-section acct-card">
                <header class="admin-section-head">
                    <h2 class="admin-section-title">Address</h2>
                </header>

                <!--
                    CSFP address picker, locked to the City of San Fernando,
                    Pampanga. Province + City are fixed/disabled (readable), the
                    Barangay is a searchable combobox (enhanced over the native
                    select that feeds assets/js/admin-form-validate.js), and the
                    combined string is written into the read-only "Full address"
                    field that is what actually gets persisted to users.address.
                -->
                <div class="admin-field-wide acct-address">
                    <div class="admin-address-picker" data-psgc-picker data-psgc-address-target="account_address">
                        <label class="admin-field">
                            <span class="acct-locked-label">Province
                                <span class="acct-lock" title="Locked" aria-label="Locked">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
                                </span>
                            </span>
                            <select data-psgc="province" aria-label="Province (locked)" title="Locked"><option value="">Loading…</option></select>
                        </label>
                        <label class="admin-field">
                            <span class="acct-locked-label">City / Municipality
                                <span class="acct-lock" title="Locked" aria-label="Locked">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
                                </span>
                            </span>
                            <select data-psgc="city" aria-label="City or municipality (locked)" title="Locked" disabled><option value="">Loading…</option></select>
                        </label>
                        <label class="admin-field">
                            <span>Barangay</span>
                            <div class="acct-combobox" data-account-combobox>
                                <div class="acct-combobox-control">
                                    <input id="account_barangay_input" type="text" class="acct-combobox-input" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="account_barangay_list" autocomplete="off" placeholder="Search barangay" data-combobox-input>
                                    <button type="button" class="acct-combobox-toggle" tabindex="-1" aria-hidden="true" data-combobox-toggle>
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
                                    </button>
                                </div>
                                <ul id="account_barangay_list" class="acct-combobox-list" role="listbox" data-combobox-list hidden></ul>
                                <select id="account_barangay_select" data-psgc="barangay" class="acct-sr-only" tabindex="-1" aria-hidden="true" data-combobox-select><option value="">Loading…</option></select>
                            </div>
                        </label>
                    </div>

                    <label class="admin-field acct-field">
                        <span>House no. / street</span>
                        <input data-psgc="street" placeholder="e.g. 143 Purok 6" value="">
                    </label>

                    <div class="admin-address-status" data-psgc-status role="status" aria-live="polite"></div>

                    <label class="admin-field acct-field">
                        <span>Full address</span>
                        <input id="account_address" type="text" name="address" maxlength="255" readonly placeholder="Built automatically from the fields above" value="<?php echo admin_e($storedAddress); ?>">
                    </label>
                </div>
            </section>
        </form>

        <!-- ── Right column: Change password + Security ───────────── -->
        <div class="acct-col-aside">
            <form method="post" class="admin-section acct-card" data-account-password-form data-validate-form>
                <input type="hidden" name="update_password" value="1">
                <header class="admin-section-head">
                    <h2 class="admin-section-title">Change password</h2>
                </header>

                <div class="admin-form-grid admin-form-grid--single acct-form-grid">
                    <label class="admin-field">
                        <span>Old password</span>
                        <input id="account_old_password" type="password" name="old_password" autocomplete="current-password" placeholder="Enter current password">
                        <span class="admin-field-message"></span>
                    </label>

                    <label class="admin-field">
                        <span>New password</span>
                        <input id="account_password" type="password" name="password" data-validate="password" data-label="New password" autocomplete="new-password" placeholder="Create a new password">
                        <span class="admin-field-message"></span>
                        <ul class="admin-pw-checklist" data-pw-checklist-for="account_password">
                            <li data-pw-rule="length">At least 8 characters</li>
                            <li data-pw-rule="upper">One uppercase letter</li>
                            <li data-pw-rule="lower">One lowercase letter</li>
                            <li data-pw-rule="number">One number</li>
                            <li data-pw-rule="special">One special character</li>
                        </ul>
                        <div class="acct-strength" data-account-strength>
                            <div class="acct-segments" data-acct-strength-bar>
                                <span class="acct-segment" data-acct-segment></span>
                                <span class="acct-segment" data-acct-segment></span>
                                <span class="acct-segment" data-acct-segment></span>
                                <span class="acct-segment" data-acct-segment></span>
                            </div>
                            <div class="acct-strength-label" data-acct-strength-label aria-live="polite"></div>
                        </div>
                    </label>

                    <label class="admin-field">
                        <span>Repeat new password</span>
                        <input id="account_password_repeat" type="password" name="password_repeat" data-validate="confirm-password" data-label="Repeat new password" data-match="account_password" autocomplete="new-password" placeholder="Re-enter new password">
                        <span class="admin-field-message"></span>
                        <div class="acct-match" data-account-match hidden></div>
                    </label>
                </div>

                <div class="acct-card-actions">
                    <button type="submit" class="admin-btn" data-account-password-submit disabled>Update password</button>
                </div>
            </form>

            <section class="admin-section acct-card">
                <header class="admin-section-head">
                    <h2 class="admin-section-title">Security</h2>
                </header>

                <!-- TODO: Two-factor authentication is not implemented yet. The
                     control is disabled rather than faking a working setup flow. -->
                <div class="acct-security-row">
                    <div>
                        <div class="acct-security-title">Two-factor authentication</div>
                        <div class="acct-security-desc">Add an extra layer of security to your account.</div>
                    </div>
                    <button type="button" class="admin-btn-secondary" disabled title="Two-factor authentication is not available yet.">Set up</button>
                </div>

                <!-- TODO: active-session tracking is not implemented yet. The
                     device count below is a placeholder, not live data. -->
                <div class="acct-security-row">
                    <div>
                        <div class="acct-security-title">Active sessions</div>
                        <div class="acct-security-desc">You&rsquo;re signed in on 1 device.</div>
                    </div>
                    <button type="button" class="admin-btn-secondary" disabled title="Session management is not available yet.">Sign out other devices</button>
                </div>
            </section>
        </div>
    </div>

    <!-- ── Sticky save bar (profile + address only) ───────────────── -->
    <div class="acct-savebar" data-account-savebar>
        <span class="acct-savebar-hint">You have unsaved changes.</span>
        <div class="acct-savebar-actions">
            <button type="button" class="admin-btn-secondary" data-account-cancel>Cancel</button>
            <button type="submit" form="account-profile-form" class="admin-btn" data-account-save disabled>Save changes</button>
        </div>
    </div>
</div>
<?php
admin_layout_end();

<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();
require_permission('settings.view');

$actor = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_account'])) {
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $passwordConfirm = (string)($_POST['password_confirm'] ?? '');

    if ($name === '' || $email === '' || $username === '') {
        admin_redirect('/admin/settings.php', ['notice' => 'Name, email, and username are required.', 'type' => 'error']);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        admin_redirect('/admin/settings.php', ['notice' => 'Enter a valid email address.', 'type' => 'error']);
    }

    if ($phone !== '' && !preg_match('/^09\d{9}$/', $phone)) {
        admin_redirect('/admin/settings.php', ['notice' => 'Enter a valid 11-digit PH mobile number starting with 09.', 'type' => 'error']);
    }

    $current = admin_fetch_one('SELECT password_hash FROM users WHERE id = ? LIMIT 1', 'i', [(int)$actor['id']]);

    if ($current === null) {
        admin_redirect('/admin/settings.php', ['notice' => 'Your account could not be loaded.', 'type' => 'error']);
    }

    // Only setting a new password requires proving you know the current one —
    // editing name/email/username/phone doesn't. This stops someone with a
    // hijacked or left-open session from silently locking the real owner out.
    if ($password !== '') {
        if ($currentPassword === '' || !password_verify($currentPassword, (string)$current['password_hash'])) {
            admin_redirect('/admin/settings.php', ['notice' => 'Current password is incorrect.', 'type' => 'error']);
        }

        if ($password !== $passwordConfirm) {
            admin_redirect('/admin/settings.php', ['notice' => 'New password and confirmation do not match.', 'type' => 'error']);
        }

        if (strlen($password) < 8 || !preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
            admin_redirect('/admin/settings.php', ['notice' => 'New password must be at least 8 characters and include upper, lower, number, and special characters.', 'type' => 'error']);
        }
    }

    $sql = 'UPDATE users SET name = ?, email = ?, username = ?, phone = ?';
    $params = [$name, $email, $username, $phone];
    $types = 'ssss';

    if ($password !== '') {
        $sql .= ', password_hash = ?';
        $params[] = password_hash($password, PASSWORD_DEFAULT);
        $types .= 's';
    }

    $sql .= ' WHERE id = ?';
    $params[] = (int)$actor['id'];
    $types .= 'i';

    $ok = admin_execute($sql, $types, $params);

    if ($ok) {
        $_SESSION['auth']['name'] = $name;
        $_SESSION['auth']['email'] = $email;
        $_SESSION['auth']['username'] = $username;
        log_action((int)$actor['id'], 'UPDATE_OWN_ACCOUNT', 'info', 'Admin updated their own account details');
    }

    admin_redirect('/admin/settings.php', $ok ? ['notice' => 'Account updated successfully.', 'type' => 'success'] : ['notice' => 'Account could not be updated. Check for a duplicate email or username.', 'type' => 'error']);
}

$myAccount = admin_fetch_one('SELECT name, email, username, phone FROM users WHERE id = ? LIMIT 1', 'i', [(int)$actor['id']]);

admin_layout_start('Settings', 'Manage your account details.', 'settings');
?>
<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">My Account</h2>
            <p class="admin-section-subtitle">Update your own profile and password.</p>
        </div>
    </div>

    <form class="admin-form-grid" method="post" data-validate-form>
        <input type="hidden" name="update_account" value="1">
        <div class="admin-field-wide admin-flash is-error" data-validate-banner style="display:none;"></div>
        <label class="admin-field">
            <span>Full name</span>
            <input id="account_name" name="name" required data-validate="name" data-label="Full name" value="<?php echo admin_e($myAccount['name'] ?? ''); ?>">
            <span class="admin-field-message"></span>
        </label>
        <label class="admin-field">
            <span>Email</span>
            <input id="account_email" type="email" name="email" required data-validate="email" value="<?php echo admin_e($myAccount['email'] ?? ''); ?>">
            <span class="admin-field-message"></span>
        </label>
        <label class="admin-field">
            <span>Username</span>
            <input id="account_username" name="username" required data-validate="username" value="<?php echo admin_e($myAccount['username'] ?? ''); ?>">
            <span class="admin-field-message"></span>
        </label>
        <label class="admin-field">
            <span>Phone</span>
            <input id="account_phone" name="phone" data-validate="phone-ph" value="<?php echo admin_e($myAccount['phone'] ?? ''); ?>" placeholder="09171234567">
            <span class="admin-field-message"></span>
        </label>
        <label class="admin-field">
            <span>Current Password</span>
            <input id="account_current_password" type="password" name="current_password" autocomplete="current-password" placeholder="Only needed if setting a new password below">
            <span class="admin-field-message"></span>
        </label>
        <label class="admin-field admin-field-wide">
            <span>New Password</span>
            <input id="account_password" type="password" name="password" data-validate="password" autocomplete="new-password" placeholder="Leave blank to keep current password">
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
            <span>Confirm New Password</span>
            <input id="account_password_confirm" type="password" name="password_confirm" data-validate="confirm-password" data-match="account_password" autocomplete="new-password" placeholder="Re-type the new password">
            <span class="admin-field-message"></span>
        </label>
        <div class="admin-field" style="align-content:end;">
            <span>&nbsp;</span>
            <button class="admin-btn" type="submit"><?php echo admin_action_icon('save'); ?> Save account</button>
        </div>
    </form>
</section>
<?php
admin_layout_end();

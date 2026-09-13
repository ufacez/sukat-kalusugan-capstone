<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();

$editId = (int)($_GET['id'] ?? ($_GET['edit'] ?? 0));

if ($editId <= 0) {
    admin_redirect('/admin/users.php', ['notice' => 'Select a user to edit.', 'type' => 'error']);
}

require_permission('users.update');

$roles = admin_fetch_all('SELECT name FROM roles ORDER BY name ASC');
$barangays = admin_barangay_options();

$editingUser = admin_fetch_one(
    'SELECT u.id, u.name, u.email, u.username, u.phone, u.barangay_id, b.name AS barangay, u.status, r.name AS role_name
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     LEFT JOIN barangays b ON b.id = u.barangay_id
     WHERE u.id = ?
     LIMIT 1',
    'i',
    [$editId]
);

if ($editingUser === null) {
    admin_redirect(
        '/admin/users.php',
        [
            'notice' => 'User not found.',
            'type' => 'error'
        ]
    );
}

$editingNameParts = admin_split_full_name($editingUser['name'] ?? '');

// Previously submitted values after a validation error (e.g. duplicate
// email) win over the DB values so the form is never wiped. Passwords
// are never flashed and always come back blank.
$formState = admin_take_form_state();
$old = $formState['old'];
$formErrorField = $formState['error_field'];
$formErrorNotice = trim((string)($_GET['notice'] ?? ''));

if (array_key_exists('first_name', $old) || array_key_exists('middle_name', $old) || array_key_exists('last_name', $old)) {
    $editingNameParts = [
        'first' => admin_old_value($old, 'first_name', $editingNameParts['first'] ?? ''),
        'middle' => admin_old_value($old, 'middle_name', $editingNameParts['middle'] ?? ''),
        'last' => admin_old_value($old, 'last_name', $editingNameParts['last'] ?? ''),
    ];
}

$formEmail = admin_old_value($old, 'email', $editingUser['email'] ?? '');
$formUsername = admin_old_value($old, 'username', $editingUser['username'] ?? '');
$formPhone = admin_old_value($old, 'phone', $editingUser['phone'] ?? '');
$formRole = admin_old_value($old, 'role', $editingUser['role_name'] ?? 'nutritionist');
$formBarangayId = (int)admin_old_value($old, 'barangay_id', (string)($editingUser['barangay_id'] ?? 0));
$formStatus = admin_old_value($old, 'status', $editingUser['status'] ?? 'active');

$actions = '<a class="admin-btn-secondary" href="'
    . admin_e(app_url('/admin/users.php'))
    . '">' . admin_action_icon('back') . ' Users</a>';

admin_layout_start(
    'Edit User',
    'Update account details and role assignment.',
    'users',
    $actions,
    'Edit User'
);
?>
<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">Edit User</h2>
            <p class="admin-section-subtitle"><?php echo admin_e((string)$editingUser['name']); ?> · <?php echo admin_e(ucfirst((string)$editingUser['role_name'])); ?></p>
        </div>
    </div>

    <form class="admin-form-grid" method="post" data-validate-form action="<?php echo admin_e(app_url('/api/admin/users_update.php')); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$editingUser['id']; ?>">

        <div class="admin-field-wide admin-flash is-error" data-validate-banner style="display:none;"></div>

        <div class="admin-field-wide">
            <div class="admin-field-row">
                <label class="admin-field" id="user-first-name-field">
                    <span>First name<span class="admin-required">*</span></span>
                    <input id="user_first_name" name="first_name" required maxlength="60" data-validate="name" data-label="First name" value="<?php echo admin_e($editingNameParts['first']); ?>" placeholder="Jane">
                    <span class="admin-field-message"></span>
                </label>
                <label class="admin-field" id="user-middle-name-field">
                    <span>Middle name</span>
                    <input id="user_middle_name" name="middle_name" maxlength="60" data-validate="name" data-label="Middle name" value="<?php echo admin_e($editingNameParts['middle']); ?>" placeholder="Santos">
                    <span class="admin-field-message"></span>
                </label>
                <label class="admin-field" id="user-last-name-field">
                    <span>Surname<span class="admin-required">*</span></span>
                    <input id="user_last_name" name="last_name" required maxlength="60" data-validate="name" data-label="Surname" value="<?php echo admin_e($editingNameParts['last']); ?>" placeholder="Doe">
                    <span class="admin-field-message"></span>
                </label>
            </div>
        </div>

        <label class="admin-field<?php echo $formErrorField === 'email' ? ' is-invalid' : ''; ?>">
            <span>Email<span class="admin-required">*</span></span>
            <input id="user_email" type="email" name="email" required data-validate="email" data-label="Email" value="<?php echo admin_e($formEmail); ?>" placeholder="jane@example.com">
            <span class="admin-field-message"><?php echo $formErrorField === 'email' && $formErrorNotice !== '' ? admin_e($formErrorNotice) : ''; ?></span>
        </label>
        <label class="admin-field<?php echo $formErrorField === 'username' ? ' is-invalid' : ''; ?>">
            <span>Username<span class="admin-required">*</span></span>
            <input id="user_username" name="username" required data-validate="username" data-label="Username" value="<?php echo admin_e($formUsername); ?>" placeholder="janedoe">
            <span class="admin-field-message"><?php echo $formErrorField === 'username' && $formErrorNotice !== '' ? admin_e($formErrorNotice) : ''; ?></span>
        </label>
        <div class="admin-field-wide">
            <div class="admin-field-row">
                <label class="admin-field<?php echo $formErrorField === 'phone' ? ' is-invalid' : ''; ?>">
                    <span>Mobile number<span class="admin-required">*</span></span>
                    <input id="user_phone" name="phone" required data-validate="phone-ph" data-label="Mobile number" value="<?php echo admin_e($formPhone); ?>" placeholder="09171234567">
                    <span class="admin-field-message"><?php echo $formErrorField === 'phone' && $formErrorNotice !== '' ? admin_e($formErrorNotice) : ''; ?></span>
                </label>
                <label class="admin-field<?php echo $formErrorField === 'role' ? ' is-invalid' : ''; ?>">
                    <span>Role<span class="admin-required">*</span></span>
                    <select name="role" required>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?php echo admin_e($role['name']); ?>" <?php echo ($formRole === $role['name']) ? 'selected' : ''; ?>><?php echo admin_e(ucfirst($role['name'])); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="admin-field-message"><?php echo $formErrorField === 'role' && $formErrorNotice !== '' ? admin_e($formErrorNotice) : ''; ?></span>
                </label>
            </div>
        </div>
        <label class="admin-field">
            <span>Barangay scope</span>
            <select name="barangay_id">
                <option value="">-- All barangays (admin scope) --</option>
                <?php foreach ($barangays as $barangay): ?>
                    <option value="<?php echo (int)$barangay['id']; ?>" <?php echo $formBarangayId === (int)$barangay['id'] ? 'selected' : ''; ?>><?php echo admin_e($barangay['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="admin-field">
            <span>Status<span class="admin-required">*</span></span>
            <select name="status" required>
                <option value="active" <?php echo ($formStatus === 'active') ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo ($formStatus === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </label>

        <label class="admin-field admin-field-wide">
            <span>New password (optional)</span>
            <input id="user_password" type="password" name="password" data-validate="password" autocomplete="new-password" placeholder="Leave blank to keep current password">
            <span class="admin-field-message"></span>
            <ul class="admin-pw-checklist" data-pw-checklist-for="user_password">
                <li data-pw-rule="length">At least 8 characters</li>
                <li data-pw-rule="upper">One uppercase letter</li>
                <li data-pw-rule="lower">One lowercase letter</li>
                <li data-pw-rule="number">One number</li>
                <li data-pw-rule="special">One special character</li>
            </ul>
            <div class="admin-pw-strength" data-pw-strength-for="user_password">
                <div class="admin-pw-strength-track"><div class="admin-pw-strength-fill"></div></div>
                <div class="admin-pw-strength-label"></div>
            </div>
        </label>
        <label class="admin-field admin-field-wide">
            <span>Confirm new password</span>
            <input id="user_password_confirm" type="password" name="password_confirm" data-validate="confirm-password" data-match="user_password" autocomplete="new-password" placeholder="Re-type the password">
            <span class="admin-field-message"></span>
        </label>

        <div class="admin-field admin-field-wide" style="align-content:end;">
            <button class="admin-btn" type="submit"><?php echo admin_action_icon('save'); ?> Save changes</button>
        </div>
    </form>
</section>
<?php
admin_layout_end();

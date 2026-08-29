<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();

$editId = (int)($_GET['id'] ?? ($_GET['edit'] ?? 0));

if ($editId > 0) {
    require_permission('users.update');
} else {
    require_permission('users.create');
}

$roles = admin_fetch_all('SELECT name FROM roles ORDER BY name ASC');
$barangays = admin_barangay_options();

$editId = (int)($_GET['id'] ?? ($_GET['edit'] ?? 0));

$editingUser = $editId > 0 ? admin_fetch_one(
    'SELECT u.id, u.name, u.email, u.username, u.phone, u.barangay_id, b.name AS barangay, u.status, r.name AS role_name
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     LEFT JOIN barangays b ON b.id = u.barangay_id
     WHERE u.id = ?
     LIMIT 1',
    'i',
    [$editId]
) : null;

if ($editId > 0 && $editingUser === null) {
    admin_redirect(
        '/admin/users.php',
        [
            'notice' => 'User not found.',
            'type' => 'error'
        ]
    );
}

$editingNameParts = admin_split_full_name($editingUser['name'] ?? '');

$actions = '<a class="admin-btn-secondary" href="'
    . admin_e(app_url('/admin/users.php'))
    . '">' . admin_action_icon('back') . ' Users</a>';

admin_layout_start(
    $editingUser ? 'Edit User' : 'Add User',
    $editingUser ? 'Update account details and role assignment.' : 'Create a new staff account backed by the users table.',
    'users',
    $actions
);
?>
<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title"><?php echo $editingUser ? 'Edit User' : 'Add User'; ?></h2>
            <p class="admin-section-subtitle"><?php echo $editingUser ? admin_e((string)$editingUser['name']) . ' · ' . admin_e(ucfirst((string)$editingUser['role_name'])) : 'Create a new staff account backed by the users table.'; ?></p>
        </div>
    </div>

    <form class="admin-form-grid" method="post" data-validate-form action="<?php echo admin_e(app_url($editingUser ? '/api/admin/users_update.php' : '/api/admin/users_create.php')); ?>">
        <?php if ($editingUser): ?>
            <input type="hidden" name="id" value="<?php echo (int)$editingUser['id']; ?>">
        <?php endif; ?>

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

        <label class="admin-field">
            <span>Email<span class="admin-required">*</span></span>
            <input id="user_email" type="email" name="email" required data-validate="email" value="<?php echo admin_e($editingUser['email'] ?? ''); ?>" placeholder="jane@example.com">
            <span class="admin-field-message"></span>
        </label>
        <label class="admin-field">
            <span>Username<span class="admin-required">*</span></span>
            <input id="user_username" name="username" required data-validate="username" value="<?php echo admin_e($editingUser['username'] ?? ''); ?>" placeholder="janedoe">
            <span class="admin-field-message"></span>
        </label>
        <div class="admin-field-wide">
            <div class="admin-field-row">
                <label class="admin-field">
                    <span>Mobile number<span class="admin-required">*</span></span>
                    <input id="user_phone" name="phone" required data-validate="phone-ph" value="<?php echo admin_e($editingUser['phone'] ?? ''); ?>" placeholder="09171234567">
                    <span class="admin-field-message"></span>
                </label>
                <label class="admin-field">
                    <span>Role<span class="admin-required">*</span></span>
                    <select name="role" required>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?php echo admin_e($role['name']); ?>" <?php echo (($editingUser['role_name'] ?? 'nutritionist') === $role['name']) ? 'selected' : ''; ?>><?php echo admin_e(ucfirst($role['name'])); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
        </div>
        <label class="admin-field">
            <span>Barangay scope</span>
            <select name="barangay_id">
                <option value="">-- All barangays (admin scope) --</option>
                <?php foreach ($barangays as $barangay): ?>
                    <option value="<?php echo (int)$barangay['id']; ?>" <?php echo (int)($editingUser['barangay_id'] ?? 0) === (int)$barangay['id'] ? 'selected' : ''; ?>><?php echo admin_e($barangay['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="admin-field">
            <span>Status<span class="admin-required">*</span></span>
            <select name="status" required>
                <option value="active" <?php echo (($editingUser['status'] ?? 'active') === 'active') ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo (($editingUser['status'] ?? 'active') === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </label>

        <label class="admin-field admin-field-wide">
            <span><?php echo $editingUser ? 'New password (optional)' : 'Password'; ?><?php echo $editingUser ? '' : '<span class="admin-required">*</span>'; ?></span>
            <input id="user_password" type="password" name="password" <?php echo $editingUser ? '' : 'required'; ?> data-validate="password" autocomplete="new-password" placeholder="<?php echo $editingUser ? 'Leave blank to keep current password' : 'Create a strong password'; ?>">
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
            <span><?php echo $editingUser ? 'Confirm new password' : 'Confirm password'; ?><?php echo $editingUser ? '' : '<span class="admin-required">*</span>'; ?></span>
            <input id="user_password_confirm" type="password" name="password_confirm" <?php echo $editingUser ? '' : 'required'; ?> data-validate="confirm-password" data-match="user_password" autocomplete="new-password" placeholder="Re-type the password">
            <span class="admin-field-message"></span>
        </label>

        <div class="admin-field admin-field-wide" style="align-content:end;">
            <button class="admin-btn" type="submit"><?php echo admin_action_icon('save'); ?> <?php echo $editingUser ? 'Save changes' : 'Create user'; ?></button>
        </div>
    </form>
</section>
<?php
admin_layout_end();

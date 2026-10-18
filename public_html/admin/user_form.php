<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();

$editId = (int)($_GET['id'] ?? ($_GET['edit'] ?? 0));

if ($editId <= 0) {
    admin_redirect('/admin/users.php', ['notice' => 'Select a user to edit.', 'type' => 'error']);
}

admin_require_access('users.update');

$roles = admin_fetch_all('SELECT name FROM roles ORDER BY name ASC');
$barangays = admin_barangay_options();
$accessLevels = [
    'full' => ['label' => 'Full', 'description' => 'View, add, edit and archive records.'],
    'standard' => ['label' => 'Standard', 'description' => 'View, add and edit records. Cannot archive.'],
    'readonly' => ['label' => 'Read only', 'description' => 'View records only. No changes.'],
];

$editingUser = admin_fetch_one(
    'SELECT u.id, u.name, u.email, u.username, u.user_code, u.phone, u.barangay_id, b.name AS barangay, u.status, u.access_level, r.name AS role_name
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
$formAccessLevel = admin_old_value($old, 'access_level', $editingUser['access_level'] ?? 'full');
$canChangeAccess = has_permission('roles_permissions.update');

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
            <p class="admin-section-subtitle">Update account details and role assignment.</p>
        </div>
        <button class="admin-btn" type="button" data-edit-user-open><?php echo admin_action_icon('edit'); ?> Edit user</button>
    </div>

    <div class="admin-edit-layout">
        <div class="admin-edit-modal-overlay" data-edit-user-overlay data-open-on-load="<?php echo $formErrorNotice !== '' ? 'true' : 'false'; ?>" hidden>
        <form class="admin-modal admin-modal--form admin-edit-modal" method="post" data-validate-form data-role-barangay-form data-edit-user-form action="<?php echo admin_e(app_url('/api/admin/users_update.php')); ?>" role="dialog" aria-modal="true" aria-labelledby="edit-user-modal-title">
            <div class="admin-modal-head">
                <div>
                    <h3 id="edit-user-modal-title">Edit user</h3>
                    <p class="admin-edit-modal-subtitle">Update account details and role assignment.</p>
                </div>
                <button class="admin-modal-close" type="button" data-edit-user-close aria-label="Close">&times;</button>
            </div>
            <div class="admin-edit-modal-body">
            <input type="hidden" name="id" value="<?php echo (int)$editingUser['id']; ?>">
            <div class="admin-field-wide admin-flash is-error" data-validate-banner style="display:none;"></div>

            <section class="admin-edit-card">
                <h3>Personal information</h3>
                <p>The name shown on records, reports and audit logs.</p>
                <div class="admin-field-row">
                    <label class="admin-field">
                        <span>First name<span class="admin-required">*</span></span>
                        <input id="user_first_name" name="first_name" required maxlength="60" data-validate="name" data-label="First name" value="<?php echo admin_e($editingNameParts['first']); ?>" placeholder="Jane">
                        <span class="admin-field-message"></span>
                    </label>
                    <label class="admin-field">
                        <span>Middle name</span>
                        <input id="user_middle_name" name="middle_name" maxlength="60" data-validate="name" data-label="Middle name" value="<?php echo admin_e($editingNameParts['middle']); ?>" placeholder="Santos">
                        <span class="admin-field-message"></span>
                    </label>
                    <label class="admin-field">
                        <span>Surname<span class="admin-required">*</span></span>
                        <input id="user_last_name" name="last_name" required maxlength="60" data-validate="name" data-label="Surname" value="<?php echo admin_e($editingNameParts['last']); ?>" placeholder="Doe">
                        <span class="admin-field-message"></span>
                    </label>
                </div>
            </section>

            <section class="admin-edit-card">
                <h3>Account</h3>
                <p>How this person signs in and how they can be reached.</p>
                <div class="admin-field-row">
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
                </div>
                <label class="admin-field admin-edit-short-field<?php echo $formErrorField === 'phone' ? ' is-invalid' : ''; ?>">
                    <span>Mobile number<span class="admin-required">*</span></span>
                    <input id="user_phone" name="phone" required data-validate="phone-ph" data-label="Mobile number" value="<?php echo admin_e($formPhone); ?>" placeholder="09171234567">
                    <span class="admin-field-message"><?php echo $formErrorField === 'phone' && $formErrorNotice !== '' ? admin_e($formErrorNotice) : ''; ?></span>
                </label>
            </section>

            <section class="admin-edit-card">
                <h3>Role &amp; access</h3>
                <p>What this person can do and where.</p>
                <div class="admin-field-row">
                    <label class="admin-field<?php echo $formErrorField === 'role' ? ' is-invalid' : ''; ?>">
                        <span>Role<span class="admin-required">*</span></span>
                        <select name="role" required>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo admin_e($role['name']); ?>" <?php echo ($formRole === $role['name']) ? 'selected' : ''; ?>><?php echo admin_e(ucfirst($role['name'])); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="admin-field-message"><?php echo $formErrorField === 'role' && $formErrorNotice !== '' ? admin_e($formErrorNotice) : ''; ?></span>
                    </label>
                    <label class="admin-field">
                        <span>Barangay scope</span>
                        <select name="barangay_id">
                            <option value="">-- All barangays (admin scope) --</option>
                            <?php foreach ($barangays as $barangay): ?>
                                <option value="<?php echo (int)$barangay['id']; ?>" <?php echo $formBarangayId === (int)$barangay['id'] ? 'selected' : ''; ?>><?php echo admin_e($barangay['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="admin-edit-hint">Records they are assigned to this barangay.</small>
                    </label>
                </div>
                <?php if ($canChangeAccess): ?>
                <div class="admin-edit-access">
                    <span class="admin-edit-access-label">Access level<span class="admin-required">*</span></span>
                    <div class="admin-edit-access-options">
                        <?php foreach ($accessLevels as $accessKey => $accessOption): ?>
                        <label class="admin-edit-access-option<?php echo $formAccessLevel === $accessKey ? ' is-selected' : ''; ?>">
                            <input type="radio" name="access_level" value="<?php echo admin_e($accessKey); ?>" <?php echo $formAccessLevel === $accessKey ? 'checked' : ''; ?>>
                            <strong><?php echo admin_e($accessOption['label']); ?></strong>
                            <small><?php echo admin_e($accessOption['description']); ?></small>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <label class="admin-field admin-edit-short-field">
                    <span>Status<span class="admin-required">*</span></span>
                    <select name="status" required>
                        <option value="active" <?php echo ($formStatus === 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo ($formStatus === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </label>
            </section>

            <div class="admin-edit-actions">
                <button class="admin-btn-secondary" type="button" data-edit-user-close>Cancel</button>
                <button class="admin-btn" type="submit" data-edit-user-save hidden><?php echo admin_action_icon('save'); ?> Save changes</button>
            </div>
            </div>
        </form>
        </div>

        <aside class="admin-edit-sidebar">
            <section class="admin-edit-card admin-user-summary">
                <div class="admin-user-summary-head">
                    <span class="admin-avatar" style="background:<?php echo admin_avatar_color((string)$editingUser['name']); ?>;"><?php echo admin_initials((string)$editingUser['name']); ?></span>
                    <div>
                        <strong><?php echo admin_e((string)$editingUser['name']); ?></strong>
                        <span><?php echo admin_e(ucfirst((string)$editingUser['role_name'])); ?></span>
                    </div>
                </div>
                <div class="admin-user-summary-status">
                    <span class="admin-pill <?php echo $formStatus === 'active' ? 'is-success' : 'is-muted'; ?>"><?php echo admin_e(ucfirst($formStatus)); ?></span>
                    <?php echo sk_code_badge((string)($editingUser['user_code'] ?? '')); ?>
                </div>
                <dl>
                    <dt>User ID</dt><dd><?php echo admin_e((string)($editingUser['user_code'] ?? '')); ?></dd>
                    <dt>Username</dt><dd><?php echo admin_e($formUsername); ?></dd>
                    <dt>Barangay</dt><dd><?php echo admin_e((string)($editingUser['barangay'] ?? 'All barangays')); ?></dd>
                </dl>
            </section>

            <?php if (has_permission('users.delete')): ?>
            <section class="admin-edit-card admin-edit-archive">
                <h3>Archive user</h3>
                <p>Archived users can no longer sign in. Everything they recorded is kept.</p>
                <form method="post" action="<?php echo admin_e(app_url('/api/admin/users_archive.php')); ?>" data-admin-confirm="Archive <?php echo admin_e($editingUser['name']); ?>?">
                    <input type="hidden" name="id" value="<?php echo (int)$editingUser['id']; ?>">
                    <button class="admin-btn-danger" type="submit"><?php echo admin_action_icon('archive'); ?> Archive user</button>
                </form>
            </section>
            <?php endif; ?>
        </aside>
    </div>
</section>
<?php
admin_layout_end();

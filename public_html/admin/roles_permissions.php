<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();
require_permission('roles_permissions.view');

$roles = admin_fetch_all('SELECT id, name, description FROM roles ORDER BY name ASC');
$permissions = admin_fetch_all('SELECT id, code, description FROM permissions ORDER BY code ASC');

$firstSelectableRole = null;
foreach ($roles as $role) {
    if (strtolower((string)$role['name']) !== 'nutritionist') {
        $firstSelectableRole = $role;
        break;
    }
}

$selectedRoleId = (int)($_POST['role_id'] ?? $_GET['role_id'] ?? ($firstSelectableRole['id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_permissions'])) {
    require_permission('roles_permissions.update');

    $selectedRoleId = (int)($_POST['role_id'] ?? 0);
    $selectedPermissionIds = array_map('intval', (array)($_POST['permissions'] ?? []));

    if ($selectedRoleId > 0) {
        admin_execute('DELETE FROM role_permissions WHERE role_id = ?', 'i', [$selectedRoleId]);

        foreach ($selectedPermissionIds as $permissionId) {
            if ($permissionId > 0) {
                admin_execute('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', 'ii', [$selectedRoleId, $permissionId]);
            }
        }

        log_action((current_user()['id'] ?? null), 'UPDATE_ROLE_PERMISSIONS', 'info', 'Updated permissions for role ' . $selectedRoleId);
        admin_redirect('/admin/roles_permissions.php', ['role_id' => $selectedRoleId, 'notice' => 'Permissions updated successfully.', 'type' => 'success']);
    }
}

$rolePermissionsRows = $selectedRoleId > 0 ? admin_fetch_all('SELECT permission_id FROM role_permissions WHERE role_id = ?', 'i', [$selectedRoleId]) : [];
$rolePermissionMap = [];

foreach ($rolePermissionsRows as $row) {
    $rolePermissionMap[(int)$row['permission_id']] = true;
}

$roleStats = [];
foreach ($roles as $role) {
    $roleStats[$role['id']] = admin_scalar('SELECT COUNT(*) FROM role_permissions WHERE role_id = ?', 'i', [(int)$role['id']]);
}

// The nutritionist role is managed separately and shouldn't appear as an
// option in the role picker below — it's still shown in the stat cards above.
$dropdownRoles = array_values(array_filter($roles, static function ($role) {
    return strtolower((string)$role['name']) !== 'nutritionist';
}));

$actions = '<div class="admin-muted-block">Use the checkbox matrix to assign access.</div>';

admin_layout_start('Roles & Permissions', 'Define access rules for admin and staff accounts.', 'roles_permissions', $actions);
?>
<section class="admin-grid-cards">
    <?php foreach ($roles as $role): ?>
        <article class="admin-card">
            <div class="admin-card-row">
                <div class="admin-card-icon is-danger">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z"/></svg>
                </div>
                <div class="admin-card-content">
                    <div class="admin-card-label"><?php echo admin_e(ucfirst($role['name'])); ?></div>
                    <div class="admin-card-value"><?php echo (int)($roleStats[$role['id']] ?? 0); ?></div>
                    <div class="admin-card-meta">
                        <span class="admin-card-trend is-up">members</span>
                    </div>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
</section>

<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">Permission Matrix</h2>
            <p class="admin-section-subtitle">Select a role and persist its permissions into the database.</p>
        </div>
    </div>

    <form id="permissions-form" method="post" class="admin-list">
        <input type="hidden" name="save_permissions" value="1">
        <label class="admin-field" style="max-width:320px;">
            <span>Role</span>
            <select name="role_id">
                <?php foreach ($dropdownRoles as $role): ?>
                    <option value="<?php echo (int)$role['id']; ?>" <?php echo $selectedRoleId === (int)$role['id'] ? 'selected' : ''; ?>><?php echo admin_e(ucfirst($role['name'])); ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <div class="admin-grid-3">
            <?php foreach ($permissions as $permission): ?>
                <?php $allowed = !empty($rolePermissionMap[(int)$permission['id']]); ?>
                <label class="admin-check-card">
                    <div class="admin-toggle">
                        <input type="checkbox" name="permissions[]" value="<?php echo (int)$permission['id']; ?>" <?php echo $allowed ? 'checked' : ''; ?>>
                        <strong><?php echo admin_e($permission['code']); ?></strong>
                    </div>
                    <div class="admin-mini" style="margin-top:6px;"><?php echo admin_e($permission['description'] ?? ''); ?></div>
                </label>
            <?php endforeach; ?>
        </div>

        <div>
            <button class="admin-btn" type="submit"><?php echo admin_action_icon('save'); ?> Save permissions</button>
        </div>
    </form>
</section>
<?php
admin_layout_end();


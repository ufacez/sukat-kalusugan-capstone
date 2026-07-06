<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();
require_permission('roles_permissions.view');

$roles = admin_fetch_all('SELECT id, name, description FROM roles ORDER BY name ASC');
$permissions = admin_fetch_all('SELECT id, code, description FROM permissions ORDER BY code ASC');
$selectedRoleId = (int)($_POST['role_id'] ?? $_GET['role_id'] ?? ($roles[0]['id'] ?? 0));

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

$actions = '<div class="admin-muted-block">Use the checkbox matrix to assign access.</div>';

admin_layout_start('Roles & Permissions', 'Define access rules for admin and staff accounts.', 'roles_permissions', $actions);
?>
<section class="admin-grid-cards">
    <?php foreach ($roles as $role): ?>
        <article class="admin-card">
            <div class="admin-stat-label"><?php echo admin_e(ucfirst($role['name'])); ?></div>
            <div class="admin-stat-value"><?php echo (int)($roleStats[$role['id']] ?? 0); ?></div>
            <div class="admin-stat-note"><?php echo admin_e($role['description'] ?? ''); ?></div>
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
                <?php foreach ($roles as $role): ?>
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
            <button class="admin-btn" type="submit">Save permissions</button>
        </div>
    </form>
</section>
<?php
admin_layout_end();


<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('roles_permissions.update');

    $roleId = (int)($_POST['role_id'] ?? 0);
    $permissionIds = array_map('intval', (array)($_POST['permissions'] ?? []));

    if ($roleId <= 0) {
        admin_redirect('/admin/roles_permissions.php', ['notice' => 'Invalid role id.', 'type' => 'error']);
    }

    admin_execute('DELETE FROM role_permissions WHERE role_id = ?', 'i', [$roleId]);

    foreach ($permissionIds as $permissionId) {
        if ($permissionId > 0) {
            admin_execute('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', 'ii', [$roleId, $permissionId]);
        }
    }

    log_action((current_user()['id'] ?? null), 'UPDATE_ROLE_PERMISSIONS', 'info', 'Updated permissions for role ' . $roleId);

    if (wants_json_response()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'message' => 'Permissions updated successfully.',
        ]);
        exit;
    }

    admin_redirect('/admin/roles_permissions.php', ['role_id' => $roleId, 'notice' => 'Permissions updated successfully.', 'type' => 'success']);
}

require_permission('roles_permissions.view');
header('Content-Type: application/json; charset=utf-8');

$roles = admin_fetch_all('SELECT id, name, description FROM roles ORDER BY name ASC');
$permissions = admin_fetch_all('SELECT id, code, description FROM permissions ORDER BY code ASC');

echo json_encode([
    'success' => true,
    'roles' => $roles,
    'permissions' => $permissions,
]);


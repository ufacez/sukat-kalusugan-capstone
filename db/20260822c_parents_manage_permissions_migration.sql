-- ============================================================================
-- Permission codes for managing parent accounts from the admin console
-- ============================================================================
-- parents.view already existed (admin_migration.sql). This adds the
-- create/update/delete counterparts so the new Add/Edit/Delete Parent
-- actions on public_html/admin/parents.php follow the same
-- require_permission() pattern as users.create / users.update / users.delete.
--
-- Note: the 'admin' role bypasses require_permission()'s DB check entirely
-- (see auth_middleware.php require_permission()), so these grants matter
-- for any future non-admin role that should be allowed to manage parents,
-- not for admin access itself.
-- ============================================================================

INSERT INTO permissions (code, description)
SELECT 'parents.create', 'Create parent accounts'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'parents.create');

INSERT INTO permissions (code, description)
SELECT 'parents.update', 'Update parent accounts'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'parents.update');

INSERT INTO permissions (code, description)
SELECT 'parents.delete', 'Delete parent accounts'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'parents.delete');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.name = 'admin'
  AND p.code IN ('parents.create', 'parents.update', 'parents.delete');

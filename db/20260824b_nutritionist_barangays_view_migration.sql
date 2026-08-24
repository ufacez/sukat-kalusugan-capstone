-- ============================================================================
-- Grant the nutritionist role the barangays.view permission
-- ============================================================================
-- db/20260818_barangays_migration.sql added the 'barangays.view' permission
-- and the /api/admin/csfp_barangays.php endpoint (used to populate the
-- Barangay dropdown on the Add/Edit Child form) requires it via
-- require_permission('barangays.view'). That migration only granted the
-- permission to the 'admin' role, so nutritionist accounts get a 403 from
-- that endpoint, the JS fetch in admin-form-validate.js rejects, and the
-- Barangay <select> is left stuck on "Loading barangays..." — a required
-- field, which blocks the whole Add/Edit Child form from being submitted.
--
-- This grants nutritionist the same barangays.view permission admin already
-- has (view-only; NOT barangays.manage, which stays admin-only).
-- ============================================================================

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.name = 'nutritionist'
  AND p.code = 'barangays.view';

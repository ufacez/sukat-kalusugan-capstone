-- ============================================================================
-- Sukat Kalusugan Admin Migration
-- Import this AFTER the core schema if your database already exists.
-- Safe for reruns: uses IF NOT EXISTS / INSERT IGNORE where appropriate.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE parents
    ADD COLUMN IF NOT EXISTS parent_type ENUM('Father','Mother','Guardian','Grandparent','Other') NOT NULL DEFAULT 'Guardian' AFTER password_hash;

-- ----------------------------------------------------------------------------
-- Admin RBAC seeds
-- ----------------------------------------------------------------------------
INSERT INTO roles (name, description)
SELECT 'admin', 'System administrator'
WHERE NOT EXISTS (
    SELECT 1 FROM roles WHERE name = 'admin'
);

INSERT INTO roles (name, description)
SELECT 'nutritionist', 'Clinic nutritionist'
WHERE NOT EXISTS (
    SELECT 1 FROM roles WHERE name = 'nutritionist'
);

INSERT INTO permissions (code, description)
SELECT 'dashboard.view', 'View the admin dashboard'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'dashboard.view');

INSERT INTO permissions (code, description)
SELECT 'users.view', 'View staff accounts'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'users.view');

INSERT INTO permissions (code, description)
SELECT 'users.create', 'Create staff accounts'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'users.create');

INSERT INTO permissions (code, description)
SELECT 'users.update', 'Update staff accounts'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'users.update');

INSERT INTO permissions (code, description)
SELECT 'users.delete', 'Delete staff accounts'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'users.delete');

INSERT INTO permissions (code, description)
SELECT 'audit_logs.view', 'View audit logs'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'audit_logs.view');

INSERT INTO permissions (code, description)
SELECT 'roles_permissions.view', 'View role policies'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'roles_permissions.view');

INSERT INTO permissions (code, description)
SELECT 'roles_permissions.update', 'Update role policies'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'roles_permissions.update');

INSERT INTO permissions (code, description)
SELECT 'sensors.view', 'View device calibration data'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'sensors.view');

INSERT INTO permissions (code, description)
SELECT 'sensors.update', 'Update device calibration data'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'sensors.update');

INSERT INTO permissions (code, description)
SELECT 'settings.view', 'View system settings'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'settings.view');

INSERT INTO permissions (code, description)
SELECT 'settings.update', 'Update system settings'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'settings.update');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.name = 'admin';

-- ----------------------------------------------------------------------------
-- System settings table + default values
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS system_settings (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key     VARCHAR(100) NOT NULL UNIQUE,
    setting_value   TEXT NOT NULL,
    value_type      ENUM('string','number','boolean','json') NOT NULL DEFAULT 'string',
    description     VARCHAR(255) NULL,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO system_settings (setting_key, setting_value, value_type, description)
SELECT 'app_name', 'Sukat Kalusugan', 'string', 'Displayed application name'
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = 'app_name');

INSERT INTO system_settings (setting_key, setting_value, value_type, description)
SELECT 'clinic_name', 'Barangay Nutrition Center', 'string', 'Primary clinic or office name'
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = 'clinic_name');

INSERT INTO system_settings (setting_key, setting_value, value_type, description)
SELECT 'support_email', 'support@sukat.local', 'string', 'System support contact'
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = 'support_email');

INSERT INTO system_settings (setting_key, setting_value, value_type, description)
SELECT 'sync_interval_minutes', '15', 'number', 'Telemetry and sync interval'
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = 'sync_interval_minutes');

INSERT INTO system_settings (setting_key, setting_value, value_type, description)
SELECT 'maintenance_mode', '0', 'boolean', 'Toggle read-only maintenance mode'
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = 'maintenance_mode');

-- ----------------------------------------------------------------------------
-- Default admin account seed
-- ----------------------------------------------------------------------------
INSERT INTO users (name, email, username, password_hash, phone, role_id, barangay, status)
SELECT
    'System Administrator',
    'admin@sukat.local',
    'admin',
    '$2y$10$QeU7O5MRHmHPRIcCxGxluewFYWG9XlLAjQekBTU/bTNufGHqPNTmC',
    NULL,
    r.id,
    'All',
    'active'
FROM roles r
WHERE r.name = 'admin'
  AND NOT EXISTS (
      SELECT 1 FROM users WHERE email = 'admin@sukat.local'
  );

INSERT INTO users (name, email, username, password_hash, phone, role_id, barangay, status)
SELECT
    'Nutritionist User',
    'nutritionist@sukat.ph',
    'nutritionist',
    '$2y$10$mLbAvfFAvfm63tCDmfN/0ezjmtXt6zv.e0r.SAUgdBJXIbV.I1BKy',
    NULL,
    r.id,
    'Bagong Silang',
    'active'
FROM roles r
WHERE r.name = 'nutritionist'
  AND NOT EXISTS (
      SELECT 1 FROM users WHERE email = 'nutritionist@sukat.ph'
  );

SET FOREIGN_KEY_CHECKS = 1;

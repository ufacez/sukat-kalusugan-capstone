-- ============================================================================
-- Web-Based Child Nutrition Monitoring System
-- Phase 1: Core Database Schema
-- Engine: InnoDB (foreign keys + transactions)
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- RBAC: roles, permissions, role_permissions
-- ----------------------------------------------------------------------------
CREATE TABLE roles (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(50) NOT NULL UNIQUE,       -- e.g. 'admin', 'nutritionist'
    description     VARCHAR(255) NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE permissions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(100) NOT NULL UNIQUE,       -- e.g. 'children.create', 'users.delete'
    description     VARCHAR(255) NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
    role_id         INT UNSIGNED NOT NULL,
    permission_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- Staff users (admin, nutritionist)
-- ----------------------------------------------------------------------------
CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    email           VARCHAR(150) NOT NULL UNIQUE,
    username        VARCHAR(100) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    phone           VARCHAR(30) NULL,
    role_id         INT UNSIGNED NOT NULL,
    barangay        VARCHAR(100) NULL,                  -- scoping for nutritionists; NULL/'All' for admin
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    last_login      TIMESTAMP NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT,
    INDEX idx_users_role (role_id)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- Parents (separate auth domain from staff users)
-- ----------------------------------------------------------------------------
CREATE TABLE parents (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    email           VARCHAR(150) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    parent_type     ENUM('Father','Mother','Guardian','Grandparent','Other') NOT NULL DEFAULT 'Guardian',
    phone           VARCHAR(30) NULL,
    address         VARCHAR(255) NULL,
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- Children
-- ----------------------------------------------------------------------------
CREATE TABLE children (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    child_code      VARCHAR(20) NOT NULL UNIQUE,         -- e.g. CHD-0001
    first_name      VARCHAR(100) NOT NULL,
    last_name       VARCHAR(100) NOT NULL,
    birthdate       DATE NOT NULL,
    sex             ENUM('Male','Female') NOT NULL,
    barangay        VARCHAR(100) NULL,
    address         VARCHAR(255) NULL,
    parent_id       INT UNSIGNED NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_children_parent FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE RESTRICT,
    INDEX idx_children_parent (parent_id),
    INDEX idx_children_barangay (barangay)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- Devices (ESP32 kiosks / sensor units)
-- ----------------------------------------------------------------------------
CREATE TABLE devices (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_code         VARCHAR(50) NOT NULL UNIQUE,     -- e.g. ESP32-KIOSK-01
    location            VARCHAR(150) NULL,               -- e.g. Barangay Health Center - Bagong Silang
    last_calibration_at DATE NULL,
    calibration_offset_height DECIMAL(6,2) DEFAULT 0.00,
    calibration_offset_weight DECIMAL(6,3) DEFAULT 0.000,
    status              ENUM('active','maintenance','offline') NOT NULL DEFAULT 'active',
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- Measurements (the core clinical record)
-- ----------------------------------------------------------------------------
CREATE TABLE measurements (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    child_id            INT UNSIGNED NOT NULL,
    height_cm           DECIMAL(5,2) NOT NULL,
    weight_kg           DECIMAL(5,3) NOT NULL,
    age_months          INT UNSIGNED NOT NULL,           -- snapshot at time of measurement, not recomputed later
    measurement_date    DATE NOT NULL,
    source_type         ENUM('kiosk','manual','mobile') NOT NULL DEFAULT 'kiosk',
    waz                 DECIMAL(5,2) NULL,                -- weight-for-age z-score
    haz                 DECIMAL(5,2) NULL,                -- height-for-age z-score
    whz                 DECIMAL(5,2) NULL,                -- weight-for-height z-score
    nutritional_status  ENUM('Normal','Underweight','Severely Underweight','Stunted','Wasted','Overweight') NULL,
    device_id           INT UNSIGNED NULL,                -- which ESP32/kiosk took this reading
    recorded_by         INT UNSIGNED NULL,                -- staff user, only set for manual entries
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_measurements_child FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE RESTRICT,
    CONSTRAINT fk_measurements_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL,
    CONSTRAINT fk_measurements_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_measurements_child (child_id),
    INDEX idx_measurements_date (measurement_date)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- Appointments
-- ----------------------------------------------------------------------------
CREATE TABLE appointments (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    child_id            INT UNSIGNED NOT NULL,
    parent_id           INT UNSIGNED NOT NULL,
    nutritionist_id     INT UNSIGNED NOT NULL,
    scheduled_at         DATETIME NOT NULL,
    status              ENUM('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending',
    notes               TEXT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_appt_child FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE RESTRICT,
    CONSTRAINT fk_appt_parent FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE RESTRICT,
    CONSTRAINT fk_appt_user FOREIGN KEY (nutritionist_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_appt_schedule (scheduled_at)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- Audit logs
-- ----------------------------------------------------------------------------
CREATE TABLE audit_logs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NULL,
    action          VARCHAR(150) NOT NULL,
    level           ENUM('info','warning','danger') NOT NULL DEFAULT 'info',
    description     TEXT NULL,
    ip_address      VARCHAR(45) NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- WHO Child Growth Standards reference tables (LMS method)
-- These are seeded from official WHO tables, not user-editable.
-- ----------------------------------------------------------------------------
CREATE TABLE who_weight_for_age (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sex         ENUM('Male','Female') NOT NULL,
    age_months  INT UNSIGNED NOT NULL,
    L           DECIMAL(10,6) NOT NULL,
    M           DECIMAL(10,6) NOT NULL,
    S           DECIMAL(10,6) NOT NULL,
    UNIQUE KEY uq_wfa (sex, age_months)
) ENGINE=InnoDB;

CREATE TABLE who_height_for_age (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sex         ENUM('Male','Female') NOT NULL,
    age_months  INT UNSIGNED NOT NULL,
    L           DECIMAL(10,6) NOT NULL,
    M           DECIMAL(10,6) NOT NULL,
    S           DECIMAL(10,6) NOT NULL,
    UNIQUE KEY uq_hfa (sex, age_months)
) ENGINE=InnoDB;

CREATE TABLE who_weight_for_height (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sex         ENUM('Male','Female') NOT NULL,
    height_cm   DECIMAL(4,1) NOT NULL,                   -- WHO tables step in 0.1/0.5 cm increments
    L           DECIMAL(10,6) NOT NULL,
    M           DECIMAL(10,6) NOT NULL,
    S           DECIMAL(10,6) NOT NULL,
    UNIQUE KEY uq_wfh (sex, height_cm)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- Initial access seed data
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

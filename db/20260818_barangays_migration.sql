-- ============================================================================
-- Barangay normalization
-- Promotes "barangay" from a free-typed text field scattered across
-- children, users, and nutritionist_events into a real `barangays` table
-- with proper foreign keys. Children, parents, nutritionist accounts
-- (users), and kiosks (devices) all now relate to a barangay by id instead
-- of a loosely-typed string, so scoping/reporting can rely on referential
-- integrity instead of exact text matches.
--
-- Safe to run against an existing sukat_kalusugan database that still has
-- the old `barangay` VARCHAR columns. Import this AFTER db/schema.sql (or
-- after your existing database) and BEFORE deploying the updated app code.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 0. Unrelated pre-existing bug fix, bundled here because it blocks the
-- Sensors page (where kiosk barangay assignment now lives): device_sensor_settings.device_code
-- was created with utf8mb4_unicode_ci while devices.device_code uses
-- utf8mb4_general_ci, so any query joining them on device_code throws
-- "Illegal mix of collations". Safe no-op if already fixed.
-- ----------------------------------------------------------------------------
ALTER TABLE device_sensor_settings MODIFY device_code VARCHAR(50) NOT NULL COLLATE utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 1. Master barangays table
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS barangays (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name               VARCHAR(100) NOT NULL,
    city_municipality  VARCHAR(150) NULL,
    status             ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_barangays_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seed from every distinct barangay value already sitting in text columns
-- (children, users, nutritionist_events), ignoring blanks and the "All"
-- placeholder that used to mean "not scoped to a barangay".
INSERT INTO barangays (name)
SELECT DISTINCT t.barangay FROM (
    SELECT barangay FROM children WHERE barangay IS NOT NULL AND TRIM(barangay) <> ''
    UNION
    SELECT barangay FROM users WHERE barangay IS NOT NULL AND TRIM(barangay) <> '' AND LOWER(TRIM(barangay)) <> 'all'
    UNION
    SELECT barangay FROM nutritionist_events WHERE barangay IS NOT NULL AND TRIM(barangay) <> ''
) AS t
WHERE NOT EXISTS (SELECT 1 FROM barangays b WHERE b.name = t.barangay);

-- ----------------------------------------------------------------------------
-- 2. Add barangay_id relations
-- ----------------------------------------------------------------------------
ALTER TABLE children
    ADD COLUMN IF NOT EXISTS barangay_id INT UNSIGNED NULL AFTER sex;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS barangay_id INT UNSIGNED NULL AFTER phone;

ALTER TABLE parents
    ADD COLUMN IF NOT EXISTS barangay_id INT UNSIGNED NULL AFTER address;

ALTER TABLE devices
    ADD COLUMN IF NOT EXISTS barangay_id INT UNSIGNED NULL AFTER location;

ALTER TABLE nutritionist_events
    ADD COLUMN IF NOT EXISTS barangay_id INT UNSIGNED NULL AFTER location;

-- ----------------------------------------------------------------------------
-- 3. Backfill barangay_id from the old text values
-- ----------------------------------------------------------------------------
UPDATE children c
INNER JOIN barangays b ON b.name = c.barangay
SET c.barangay_id = b.id
WHERE c.barangay IS NOT NULL AND TRIM(c.barangay) <> '';

UPDATE users u
INNER JOIN barangays b ON b.name = u.barangay
SET u.barangay_id = b.id
WHERE u.barangay IS NOT NULL AND TRIM(u.barangay) <> '' AND LOWER(TRIM(u.barangay)) <> 'all';

UPDATE nutritionist_events e
INNER JOIN barangays b ON b.name = e.barangay
SET e.barangay_id = b.id
WHERE e.barangay IS NOT NULL AND TRIM(e.barangay) <> '';

-- parents.barangay_id and devices.barangay_id have no prior text source
-- (this is a brand-new relation) so they stay NULL until assigned via the
-- admin console (Parents / Sensors pages).

-- ----------------------------------------------------------------------------
-- 4. Foreign keys + indexes
-- ----------------------------------------------------------------------------
ALTER TABLE children
    ADD CONSTRAINT fk_children_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE SET NULL,
    ADD INDEX idx_children_barangay_id (barangay_id);

ALTER TABLE users
    ADD CONSTRAINT fk_users_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE SET NULL,
    ADD INDEX idx_users_barangay_id (barangay_id);

ALTER TABLE parents
    ADD CONSTRAINT fk_parents_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE SET NULL,
    ADD INDEX idx_parents_barangay_id (barangay_id);

ALTER TABLE devices
    ADD CONSTRAINT fk_devices_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE SET NULL,
    ADD INDEX idx_devices_barangay_id (barangay_id);

ALTER TABLE nutritionist_events
    ADD CONSTRAINT fk_nutritionist_events_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE SET NULL,
    ADD INDEX idx_nutritionist_events_barangay_id (barangay_id);

-- ----------------------------------------------------------------------------
-- 5. Drop the old free-text columns now that every relation is FK-backed
-- ----------------------------------------------------------------------------
DROP INDEX IF EXISTS idx_children_barangay ON children;
ALTER TABLE children DROP COLUMN IF EXISTS barangay;

DROP INDEX IF EXISTS idx_nutritionist_events_barangay ON nutritionist_events;
ALTER TABLE nutritionist_events DROP COLUMN IF EXISTS barangay;

ALTER TABLE users DROP COLUMN IF EXISTS barangay;

-- ----------------------------------------------------------------------------
-- 6. New permission for managing the barangay master list
-- ----------------------------------------------------------------------------
INSERT INTO permissions (code, description)
SELECT 'barangays.view', 'View barangay master list'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'barangays.view');

INSERT INTO permissions (code, description)
SELECT 'barangays.manage', 'Create, update, and deactivate barangays'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'barangays.manage');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.name = 'admin'
  AND p.code IN ('barangays.view', 'barangays.manage')
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

SET FOREIGN_KEY_CHECKS = 1;

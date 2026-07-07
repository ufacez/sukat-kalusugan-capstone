-- ============================================================================
-- Parent type migration
-- Adds parent_type to the parents table and backfills existing rows.
-- Safe to rerun.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE parents
    ADD COLUMN IF NOT EXISTS parent_type ENUM('Father','Mother','Guardian','Grandparent','Other') NOT NULL DEFAULT 'Guardian' AFTER password_hash;

UPDATE parents
SET parent_type = 'Guardian'
WHERE parent_type IS NULL OR parent_type = '';

SET FOREIGN_KEY_CHECKS = 1;
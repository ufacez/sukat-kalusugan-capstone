-- ============================================================
-- Recheck (anytime double-check) measurements — Schema
-- Date: 2026-09-14
-- ============================================================
-- RECHECK = verification reading that does NOT affect the due
-- schedule. Scheduler queries filter to ROUTINE/OVERRIDE only;
-- display/history queries keep showing RECHECK as the latest
-- verified value.
-- ROUTINE  = normal due-date measurement (affects schedule)
-- OVERRIDE = exceptional out-of-schedule measurement (affects schedule)
-- RECHECK  = double-check / retry, anytime incl. same day (neutral)

-- 1. Extend measurement_type enum
ALTER TABLE `measurements`
  MODIFY COLUMN `measurement_type` ENUM('ROUTINE','OVERRIDE','RECHECK') NOT NULL DEFAULT 'ROUTINE';

-- 2. Recheck bookkeeping columns (reason + link to the reading being verified)
ALTER TABLE `measurements`
  ADD COLUMN `recheck_reason` VARCHAR(255) NULL DEFAULT NULL
    AFTER `override_authority`,
  ADD COLUMN `recheck_of_measurement_id` INT UNSIGNED NULL DEFAULT NULL
    AFTER `recheck_reason`;

-- Backstop the self-reference FK (ignore if it already exists on re-run)
-- NOTE: plain ADD CONSTRAINT errors on re-run; deployments apply each
-- timestamped file once in filename order, matching existing convention.

-- 3. Fast same-day recheck lookups (child + date + type)
ALTER TABLE `measurements`
  ADD INDEX `idx_measurements_child_date_type` (`child_id`, `measurement_date`, `measurement_type`);

-- 4. Kiosk session flag so ESP32 submit knows to save as RECHECK
--    without trusting a client-supplied type string.
ALTER TABLE `measurement_sessions`
  ADD COLUMN `is_recheck` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `measurement_id`;

-- 5. Permission for anytime re-measurements
INSERT INTO `permissions` (`code`, `description`, `created_at`) VALUES
  ('measurements.recheck', 'Record anytime double-check (recheck) measurements without affecting the due schedule', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- Assign to nutritionist role (role_id = 2)
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT 2, `id` FROM `permissions` WHERE `code` = 'measurements.recheck'
ON DUPLICATE KEY UPDATE `permission_id` = `permission_id`;

-- Assign to admin role (role_id = 1) as well
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT 1, `id` FROM `permissions` WHERE `code` = 'measurements.recheck'
ON DUPLICATE KEY UPDATE `permission_id` = `permission_id`;

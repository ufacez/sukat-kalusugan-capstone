-- ============================================================
-- Follow-Up Monitoring & Reweighing Scheduler — Schema Rebuild
-- Date: 2026-09-08
-- ============================================================

-- 1. Add measurement_type and override columns to measurements
ALTER TABLE `measurements`
  ADD COLUMN `measurement_type` ENUM('ROUTINE','OVERRIDE') NOT NULL DEFAULT 'ROUTINE'
    AFTER `source_type`,
  ADD COLUMN `override_reason` VARCHAR(255) NULL DEFAULT NULL
    AFTER `measurement_type`,
  ADD COLUMN `override_authority` VARCHAR(150) NULL DEFAULT NULL
    AFTER `override_reason`;

-- 2. Create child_monitoring_status table
CREATE TABLE IF NOT EXISTS `child_monitoring_status` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `child_id` int(10) UNSIGNED NOT NULL,
  `monitoring_status` enum('routine','special','sick','other') NOT NULL DEFAULT 'routine',
  `custom_interval_days` int(10) UNSIGNED DEFAULT NULL COMMENT 'NULL = use age-based default; set for special monitoring',
  `reason` text DEFAULT NULL,
  `set_by` int(10) UNSIGNED NOT NULL COMMENT 'users.id — who set this status',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_child_monitoring` (`child_id`),
  KEY `fk_monitoring_setby` (`set_by`),
  CONSTRAINT `fk_monitoring_child` FOREIGN KEY (`child_id`) REFERENCES `children` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_monitoring_setby_user` FOREIGN KEY (`set_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. Add index for fast follow-up due-date queries
ALTER TABLE `appointments`
  ADD INDEX `idx_followup_scheduled` (`appointment_type`, `status`, `scheduled_at`);

-- 4. Add permission for override measurements
INSERT INTO `permissions` (`code`, `description`, `created_at`) VALUES
  ('measurements.override', 'Record exceptional measurements outside the normal schedule', NOW());

-- Assign to nutritionist role (role_id = 2)
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT 2, `id` FROM `permissions` WHERE `code` = 'measurements.override'
  ON DUPLICATE KEY UPDATE `permission_id` = `permission_id`;

-- 5. Assign to admin role (role_id = 1) as well
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT 1, `id` FROM `permissions` WHERE `code` = 'measurements.override'
  ON DUPLICATE KEY UPDATE `permission_id` = `permission_id`;

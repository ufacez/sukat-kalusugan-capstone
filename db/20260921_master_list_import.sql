-- ============================================================================
-- 20260921_master_list_import.sql
--
-- Master-list (.xlsx OPT Plus) bulk import for nutritionists
-- (nutritionist/family_import.php):
--
--   parents.needs_completion   1 when the row was auto-filled (no phone /
--                              no purok / staged data) and still needs staff
--                              follow-up. 0 for manually created accounts.
--   parents.must_change_password 1 for import-minted accounts sharing the
--                              announced default password — the parent is
--                              forced to set a real password in Settings on
--                              first sign-in (see parent_require_access()).
--   import_batches             one row per committed import (who, which
--                              barangay, file name, counters).
--   import_staging_rows        rows that could not be imported yet (missing
--                              birthdate/sex, ...). Staff completes them
--                              later instead of the importer relaxing the
--                              NOT NULL rules on children.
--
-- Idempotent: safe to re-run (same INFORMATION_SCHEMA pattern as
-- 20260921_appointment_recommendations.sql; CREATE TABLE IF NOT EXISTS).
-- ============================================================================

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'parents'
    AND COLUMN_NAME = 'needs_completion'
);

SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `parents`
     ADD COLUMN `needs_completion` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'parents'
    AND COLUMN_NAME = 'must_change_password'
);

SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `parents`
     ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 AFTER `needs_completion`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `import_batches` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `barangay_id` int(10) UNSIGNED DEFAULT NULL,
  `source_filename` varchar(255) DEFAULT NULL,
  `uploaded_by` int(10) UNSIGNED DEFAULT NULL,
  `total_rows` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `imported_parents` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `imported_children` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `graduated_children` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `skipped_rows` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `staged_rows` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `status` enum('committed') NOT NULL DEFAULT 'committed',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_import_batches_barangay` (`barangay_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `import_staging_rows` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_id` int(10) UNSIGNED NOT NULL,
  `row_num` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `mother_raw` varchar(150) DEFAULT NULL,
  `child_raw` varchar(150) DEFAULT NULL,
  `sex_raw` varchar(20) DEFAULT NULL,
  `dob_raw` varchar(40) DEFAULT NULL,
  `mother_first` varchar(100) DEFAULT NULL,
  `mother_middle` varchar(60) DEFAULT NULL,
  `mother_last` varchar(100) DEFAULT NULL,
  `child_first` varchar(100) DEFAULT NULL,
  `child_middle` varchar(60) DEFAULT NULL,
  `child_last` varchar(100) DEFAULT NULL,
  `sex_norm` varchar(10) DEFAULT NULL,
  `dob_norm` date DEFAULT NULL,
  `reason` varchar(255) NOT NULL DEFAULT '',
  `status` enum('pending','resolved') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_staging_batch_status` (`batch_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

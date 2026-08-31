-- ============================================================================
-- 20260831_who_age_days_migration.sql
--
-- Switches the WHO Weight-for-Age and Height-for-Age reference lookups from
-- completed-months (`age_months`) to days (`age_days = measurement_date -
-- birthdate`). The LMS values themselves are now keyed by Day instead of
-- completed months, so the lookup is an exact match against the official
-- WHO 2006 expanded daily tables (Day 0..1856, sourced from the existing
-- xlsx files under public_html/data/who lms 2006expanded/).
--
-- The legacy monthly tables (who_weight_for_age, who_height_for_age) are
-- kept untouched for any third-party reporting, exports, or old joins that
-- still reference them. The day-keyed versions are the new source of truth
-- for the app's `calculate_who_metrics()` path.
--
-- Also extends `measurements.wfa_status` to accept the new DOH eOPT Plus
-- overflow value 'Refer to WFL/H' (z-score > +2 on WFA is read off the
-- Weight-for-Length/Height axis instead). Backfills the new `age_days`
-- column on `measurements` from the child's birthdate + measurement_date.
-- ============================================================================

-- 1. Day-keyed reference tables (one row per day, 0..1856 for each sex).
CREATE TABLE IF NOT EXISTS `who_weight_for_age_days` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `sex` enum('Male','Female') NOT NULL,
  `age_days` int(10) UNSIGNED NOT NULL,
  `L` decimal(10,6) NOT NULL,
  `M` decimal(10,6) NOT NULL,
  `S` decimal(10,6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wfad_sex_day` (`sex`, `age_days`),
  KEY `idx_wfad_sex_age` (`sex`, `age_days`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `who_height_for_age_days` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `sex` enum('Male','Female') NOT NULL,
  `age_days` int(10) UNSIGNED NOT NULL,
  `L` decimal(10,6) NOT NULL,
  `M` decimal(10,6) NOT NULL,
  `S` decimal(10,6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hfad_sex_day` (`sex`, `age_days`),
  KEY `idx_hfad_sex_age` (`sex`, `age_days`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. measurements gets a new age_days column for the WFA/HFA lookups.
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'measurements' AND COLUMN_NAME = 'age_days'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `measurements` ADD COLUMN `age_days` int(10) UNSIGNED DEFAULT NULL AFTER `age_months`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill age_days from the child's birthdate + measurement_date. Skips
-- rows that already have a value (idempotent re-runs are safe).
UPDATE `measurements` m
JOIN `children` c ON c.id = m.child_id
SET m.age_days = GREATEST(0, DATEDIFF(m.measurement_date, c.birthdate))
WHERE m.age_days IS NULL
  AND c.birthdate IS NOT NULL
  AND m.measurement_date IS NOT NULL;

-- 3. WFA status gains the DOH eOPT Plus overflow value 'Refer to WFL/H',
-- which the app stores whenever the WAZ z-score lands above +2.
ALTER TABLE `measurements`
  MODIFY COLUMN `wfa_status` enum('SUW','MUW','Normal','OW','Refer to WFL/H') DEFAULT NULL;

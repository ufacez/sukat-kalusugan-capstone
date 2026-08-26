-- ============================================================================
-- 20260827_eopt_v2_list_restructure.sql
--
-- EOPT Version 2 monitoring list restructure:
--   1. Adds intervention tracking columns to appointments
--   2. Widens nutritional_status ENUM for moderate labels
--   3. Backfills old label spellings
--
-- Safe to re-run (idempotent via IF NOT EXISTS / no-op updates).
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. Intervention tracking on appointments
-- ---------------------------------------------------------------------------

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'appointments'
    AND COLUMN_NAME = 'intervention_type'
);

SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `appointments`
     ADD COLUMN `intervention_type` ENUM(
       ''nutrition_counseling'',''feeding_counseling'',''supplement_distribution'',
       ''referral'',''weighing_only'',''other''
     ) NULL DEFAULT NULL AFTER `followup_category`,
     ADD COLUMN `intervention_notes` VARCHAR(255) NULL DEFAULT NULL AFTER `intervention_type`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. Widen nutritional_status ENUM for moderate labels
-- ---------------------------------------------------------------------------

ALTER TABLE `measurements`
  MODIFY COLUMN `nutritional_status`
    ENUM('Normal','Underweight','Moderately Underweight','Severely Underweight',
         'Stunted','Moderately Stunted','Severely Stunted',
         'Wasted','Moderately Wasted','Severely Wasted',
         'Overweight','Obese') DEFAULT NULL;

-- ---------------------------------------------------------------------------
-- 3. Backfill old label spellings to new V2 labels
-- ---------------------------------------------------------------------------

UPDATE `measurements` SET `nutritional_status` = 'Moderately Underweight'
  WHERE `nutritional_status` = 'Underweight';

UPDATE `measurements` SET `nutritional_status` = 'Moderately Stunted'
  WHERE `nutritional_status` = 'Stunted';

UPDATE `measurements` SET `nutritional_status` = 'Moderately Wasted'
  WHERE `nutritional_status` = 'Wasted';

-- ---------------------------------------------------------------------------
-- 4. Narrow to final V2 ENUM (removes old labels)
-- ---------------------------------------------------------------------------

ALTER TABLE `measurements`
  MODIFY COLUMN `nutritional_status`
    ENUM('Normal','Moderately Underweight','Severely Underweight',
         'Moderately Stunted','Severely Stunted',
         'Moderately Wasted','Severely Wasted',
         'Overweight','Obese') DEFAULT NULL;

-- ---------------------------------------------------------------------------
-- 5. Verification queries (run manually):
--    SHOW COLUMNS FROM appointments LIKE 'intervention_type';
--    SHOW COLUMNS FROM appointments LIKE 'intervention_notes';
--    SHOW COLUMNS FROM measurements LIKE 'nutritional_status';
--    SELECT nutritional_status, COUNT(*) FROM measurements GROUP BY nutritional_status;
--    SELECT intervention_type, COUNT(*) FROM appointments GROUP BY intervention_type;
-- ============================================================================

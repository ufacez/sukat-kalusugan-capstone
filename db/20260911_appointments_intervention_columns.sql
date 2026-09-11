-- ============================================================================
-- Ensure appointments.intervention_type / intervention_notes exist.
--
-- followup_fetch_visits() (includes/followup_scheduler.php) selects these
-- columns unconditionally. The conditional block in
-- 20260827_eopt_v2_list_restructure.sql was meant to add them, but
-- databases built from dumps that skipped that file (local dev and Azure
-- alike) hit "Unknown column 'a.intervention_type'" fatals — notably the
-- parent AI chat with a child selected. Idempotent: safe to re-run.
-- ============================================================================

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

-- ============================================================================
-- 20260921_appointment_recommendations.sql
--
-- Adds the column the nutritionist writes when marking a consultation
-- Done, so both sides can re-view it later:
--
--   recommendations  Free-text guidance written at completion time
--                    (e.g. feeding advice, follow-up schedule). Optional.
--
-- Shown read-only to parents in the appointment details modal; written
-- only by the assigned nutritionist via the complete_request flow.
-- Idempotent: safe to re-run (same INFORMATION_SCHEMA pattern as
-- 20260911_appointments_intervention_columns.sql).
-- ============================================================================

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'appointments'
    AND COLUMN_NAME = 'recommendations'
);

SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `appointments`
     ADD COLUMN `recommendations` TEXT NULL DEFAULT NULL AFTER `notes`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

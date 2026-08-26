-- ============================================================================
-- 20260827_appointment_followups_migration.sql
--
-- Adds the columns the automatic EOPT follow-up engine needs on top of the
-- existing `appointments` table:
--
--   appointment_type       'regular' (manually booked) or 'followup'
--                          (auto-generated mandatory re-measurement).
--   followup_track         Which EOPT monitoring cadence produced this
--                          appointment: 'monthly' (0-23 months and
--                          malnourished 24-59 months) or 'quarterly'
--                          (normal 24-59 months re-checked Apr/Jul/Oct).
--   followup_category      Short eOPT status context captured at generation
--                          time, e.g. 'SUW', 'St', 'SW', 'Normal', or
--                          'Needs baseline'.
--   source_measurement_id  The measurement row whose result triggered the
--                          generation (traceability). NULL-safe.
--
-- Pairs with public_html/includes/followup_scheduler.php which generates,
-- auto-completes, and chains these appointments. Safe to re-run.
-- ============================================================================

ALTER TABLE `appointments`
  ADD COLUMN `appointment_type` ENUM('regular','followup') NOT NULL DEFAULT 'regular' AFTER `status`;

ALTER TABLE `appointments`
  ADD COLUMN `followup_track` ENUM('monthly','quarterly') NULL DEFAULT NULL AFTER `appointment_type`;

ALTER TABLE `appointments`
  ADD COLUMN `followup_category` VARCHAR(60) NULL DEFAULT NULL AFTER `followup_track`;

ALTER TABLE `appointments`
  ADD COLUMN `source_measurement_id` INT UNSIGNED NULL DEFAULT NULL AFTER `followup_category`;

ALTER TABLE `appointments`
  ADD INDEX `idx_appt_child_type_status` (`child_id`, `appointment_type`, `status`),
  ADD INDEX `idx_appt_type_schedule` (`appointment_type`, `scheduled_at`);

-- ---------------------------------------------------------------------------
-- Verification queries (run manually):
--   SHOW COLUMNS FROM appointments LIKE 'appointment_type';
--   SHOW COLUMNS FROM appointments LIKE 'followup_track';
--   SELECT appointment_type, COUNT(*) FROM appointments GROUP BY appointment_type;
-- ============================================================================

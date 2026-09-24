-- ============================================================================
-- 20260920_monitoring_consultation_rebuild.sql
--
-- Monitoring List rebuild + appointments simplification (consultation only):
--
--   1. Deletes auto-generated EOPT follow-up appointments. Consultation
--      requests (parent/nutritionist created) are kept.
--   2. Drops child_monitoring_status (routine/special/sick/other infra is
--      removed entirely — there is no "sick" concept anymore; monthly 24-59
--      is purely abnormal-WHO, quarterly 24-59 purely normal).
--   3. Drops the follow-up/intervention columns from appointments.
--      `created_by` (parent/nutritionist) and `scheduled_at` stay:
--      consultations are requested by either side and confirmed/completed
--      by the other.
--   4. Replaces the follow-up indexes with consultation-friendly ones.
--
-- Run once, in filename order with the other db/*.sql migrations.
-- ============================================================================

-- 1. Remove auto-generated follow-up rows (consultations have no
--    appointment_type anymore, so these must go before the column drop).
DELETE FROM appointments WHERE appointment_type = 'followup';

-- 2. Remove the custom monitoring-status table + its FKs.
DROP TABLE IF EXISTS `child_monitoring_status`;

-- 3. Strip follow-up columns, swap indexes.
ALTER TABLE `appointments`
  DROP INDEX `idx_followup_scheduled`,
  DROP INDEX `idx_appt_child_type_status`,
  DROP INDEX `idx_appt_type_schedule`,
  DROP COLUMN `appointment_type`,
  DROP COLUMN `followup_track`,
  DROP COLUMN `followup_category`,
  DROP COLUMN `intervention_type`,
  DROP COLUMN `intervention_notes`,
  DROP COLUMN `source_measurement_id`,
  ADD INDEX `idx_appt_child_status` (`child_id`, `status`),
  ADD INDEX `idx_appt_nutritionist_status` (`nutritionist_id`, `status`);

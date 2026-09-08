-- 20260908_appointments_rebuild.sql
-- Fix followup_track ENUM to allow 'custom' + backfill existing data

-- 1. Add 'custom' to followup_track ENUM
ALTER TABLE appointments
  MODIFY COLUMN followup_track ENUM('monthly','quarterly','custom') NULL DEFAULT NULL;

-- 2. Delete all auto-generated followup appointments (clean slate)
DELETE FROM appointments WHERE appointment_type = 'followup';

-- 3. Reset all monitoring status to routine
UPDATE child_monitoring_status
  SET monitoring_status = 'routine',
      custom_interval_days = NULL,
      reason = NULL;

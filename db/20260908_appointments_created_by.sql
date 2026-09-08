-- Add created_by column to distinguish parent vs nutritionist created appointments
-- Run after 20260908_appointments_rebuild.sql

ALTER TABLE appointments
  ADD COLUMN created_by ENUM('parent','nutritionist') NOT NULL DEFAULT 'nutritionist'
  AFTER appointment_type;

-- Backfill: followups are always nutritionist-created
UPDATE appointments SET created_by = 'nutritionist' WHERE appointment_type = 'followup';

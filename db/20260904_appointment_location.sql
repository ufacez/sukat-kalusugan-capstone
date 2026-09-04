-- Add a free-text location column to appointments so each visit can record
-- its own venue (e.g. "Barangay Health Center", "Sitio A Covered Court").
-- Existing rows fall back to the column default so nothing shows as NULL.

ALTER TABLE appointments
  ADD COLUMN location VARCHAR(150) DEFAULT 'Barangay Health Center' AFTER notes;
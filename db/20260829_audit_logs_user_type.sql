-- Migration: Add user_type to audit_logs for parent/nutritionist/admin identification
-- Date: 2026-08-29

ALTER TABLE `audit_logs`
  ADD COLUMN `user_type` enum('admin','nutritionist','parent') DEFAULT NULL AFTER `user_id`;

ALTER TABLE `audit_logs`
  ADD INDEX `idx_user_type` (`user_type`);

-- Backfill existing logs: match user_id to users table role
UPDATE `audit_logs` a
  INNER JOIN users u ON u.id = a.user_id
  SET a.user_type = 'admin'
  WHERE a.user_type IS NULL;

-- Seed sample audit logs for parents (if parents table has data)
UPDATE `audit_logs` a
  INNER JOIN parents p ON p.id = a.user_id
  SET a.user_type = 'parent'
  WHERE a.user_type IS NULL AND a.user_id IS NOT NULL;

-- ============================================================================
-- Invitation username: lets the admin set the staff username on the
-- New Invitation form instead of auto-generating it at activation.
-- Idempotent: safe to re-run on XAMPP or Azure.
-- ============================================================================

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'invitations'
    AND COLUMN_NAME = 'invitee_username'
);
SET @sql = IF(@col_exists > 0, 'SELECT 1', 'ALTER TABLE `invitations` ADD COLUMN `invitee_username` VARCHAR(30) DEFAULT NULL AFTER `invitee_email`');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

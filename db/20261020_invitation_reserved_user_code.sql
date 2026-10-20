-- ============================================================================
-- 20261020_invitation_reserved_user_code.sql
--
-- Reserve the staff display code when an invitation is created, so the
-- auto-generated login email can include the final code suffix:
--   NUT-0001 + "Juan Santos Jimenez" -> juanjimenez001@sukat.kalusugan
--
-- The code is consumed from code_counters in the same transaction as the
-- invitation insert. Cancelled/expired invitations keep their reserved code
-- (the counter is not rewound), so codes are never reused.
--
-- Idempotent: safe to re-run on XAMPP or Azure.
-- ============================================================================

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'invitations'
    AND COLUMN_NAME = 'invitee_user_code'
);
SET @sql = IF(
  @col_exists > 0,
  'SELECT 1',
  'ALTER TABLE `invitations` ADD COLUMN `invitee_user_code` VARCHAR(12) NULL DEFAULT NULL AFTER `invitee_email`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'invitations'
    AND INDEX_NAME = 'uq_invitation_user_code'
);
SET @sql = IF(
  @index_exists > 0,
  'SELECT 1',
  'ALTER TABLE `invitations` ADD UNIQUE KEY `uq_invitation_user_code` (`invitee_user_code`)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

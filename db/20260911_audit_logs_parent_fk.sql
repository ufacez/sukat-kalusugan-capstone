-- ============================================================================
-- Allow parent-portal rows in audit_logs.
--
-- audit_logs.user_id is polymorphic: staff sessions carry users.id but
-- parent sessions carry parents.id (see api/auth/login.php). The
-- fk_audit_user FOREIGN KEY (user_id REFERENCES users.id) therefore rejects
-- every parent login/logout audit row with a 500 (Uncaught
-- mysqli_sql_exception), breaking parent sign-in and sign-out entirely.
-- Drop the constraint; the user_id index stays for lookups, and rows remain
-- resolvable through the user_type column. Same precedent as
-- 20260911_chat_conversations_parent_fk.sql. Idempotent: safe to re-run.
-- ============================================================================

SET @fk_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'audit_logs'
    AND CONSTRAINT_NAME = 'fk_audit_user'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @sql = IF(@fk_exists > 0,
  'ALTER TABLE `audit_logs` DROP FOREIGN KEY `fk_audit_user`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

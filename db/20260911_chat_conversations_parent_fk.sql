-- ============================================================================
-- Allow parent-portal conversations in chat_conversations.
--
-- chat_conversations.user_id is polymorphic: staff sessions carry users.id
-- but parent sessions carry parents.id (see api/auth/login.php). The
-- fk_chat_conv_user FOREIGN KEY (user_id REFERENCES users.id) therefore
-- rejects every parent whose id has no match in users, breaking the parent
-- assistant page. Drop the constraint; the idx_chat_conv_user index stays
-- for lookups. The child FK (child_id REFERENCES children.id) is valid for
-- both roles and is left untouched. Idempotent: safe to re-run.
-- ============================================================================

SET @fk_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'chat_conversations'
    AND CONSTRAINT_NAME = 'fk_chat_conv_user'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @sql = IF(@fk_exists > 0,
  'ALTER TABLE `chat_conversations` DROP FOREIGN KEY `fk_chat_conv_user`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Enforce globally-unique emails (and staff usernames).
-- users.email, users.username, parents.email must each be UNIQUE so the
-- app-level admin_email_in_use() / admin_username_in_use() checks have a
-- DB backstop. Idempotent: safe to re-run on XAMPP or Azure.
-- NOTE: if the live DB already contains duplicate emails this migration
-- will fail on ADD UNIQUE — dedup first:
--   SELECT LOWER(email), COUNT(*) c FROM users GROUP BY LOWER(email) HAVING c>1;
--   SELECT LOWER(email), COUNT(*) c FROM parents GROUP BY LOWER(email) HAVING c>1;
--   SELECT u.email FROM users u INNER JOIN parents p
--     ON LOWER(u.email)=LOWER(p.email);
-- ============================================================================

-- users.email
SET @idx_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND INDEX_NAME = 'email'
);
SET @sql = IF(@idx_exists > 0, 'SELECT 1', 'ALTER TABLE `users` ADD UNIQUE KEY `email` (`email`)');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- users.username
SET @idx_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND INDEX_NAME = 'username'
);
SET @sql = IF(@idx_exists > 0, 'SELECT 1', 'ALTER TABLE `users` ADD UNIQUE KEY `username` (`username`)');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- parents.email
SET @idx_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'parents'
    AND INDEX_NAME = 'email'
);
SET @sql = IF(@idx_exists > 0, 'SELECT 1', 'ALTER TABLE `parents` ADD UNIQUE KEY `email` (`email`)');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

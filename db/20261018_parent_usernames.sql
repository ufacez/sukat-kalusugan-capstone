-- Add the same username capability used by staff accounts to parent accounts.
-- Existing parent email login remains valid; usernames are an additional
-- identifier and are completed from the parent Settings page.

SET @column_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'parents'
      AND COLUMN_NAME = 'username'
);

SET @add_username_sql := IF(
    @column_exists = 0,
    'ALTER TABLE `parents` ADD COLUMN `username` VARCHAR(30) NULL AFTER `email`',
    'SELECT 1'
);
PREPARE add_username_stmt FROM @add_username_sql;
EXECUTE add_username_stmt;
DEALLOCATE PREPARE add_username_stmt;

SET @index_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'parents'
      AND INDEX_NAME = 'username'
);

SET @add_username_index_sql := IF(
    @index_exists = 0,
    'ALTER TABLE `parents` ADD UNIQUE KEY `username` (`username`)',
    'SELECT 1'
);
PREPARE add_username_index_stmt FROM @add_username_index_sql;
EXECUTE add_username_index_stmt;
DEALLOCATE PREPARE add_username_index_stmt;

UPDATE `parents`
SET `username` = CONCAT('parent_', `id`)
WHERE (`username` IS NULL OR `username` = '');

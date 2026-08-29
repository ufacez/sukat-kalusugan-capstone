-- Add per-user access_level to enforce granular permission control.
-- 'full'     = all permissions
-- 'standard' = view + create + update + manage (no delete, no admin settings)
-- 'readonly' = view only

-- Idempotent: only add column if it doesn't exist
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'sukat_kalusugan' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'access_level'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN access_level ENUM(\'full\',\'standard\',\'readonly\') NOT NULL DEFAULT \'full\' AFTER status',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Set existing nutritionists to standard
UPDATE users u
  INNER JOIN roles r ON r.id = u.role_id
  SET u.access_level = 'standard'
  WHERE r.name = 'nutritionist' AND u.access_level = 'full';

-- Drop old RBAC tables (replaced by per-user access_level)
DROP TABLE IF EXISTS role_permissions;
DROP TABLE IF EXISTS permissions;

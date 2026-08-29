-- Add phone and address to invitations table.
-- Add address to users table (carries forward on activation).

-- Idempotent: add invitee_phone to invitations
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'sukat_kalusugan' AND TABLE_NAME = 'invitations' AND COLUMN_NAME = 'invitee_phone'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE invitations ADD COLUMN invitee_phone VARCHAR(30) NULL AFTER invitee_email',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Idempotent: add invitee_address to invitations
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'sukat_kalusugan' AND TABLE_NAME = 'invitations' AND COLUMN_NAME = 'invitee_address'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE invitations ADD COLUMN invitee_address VARCHAR(255) NULL AFTER invitee_phone',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Idempotent: add address to users
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'sukat_kalusugan' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'address'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN address VARCHAR(255) NULL AFTER phone',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

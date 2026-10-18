-- ============================================================================
-- Human-readable display IDs for people.
--
--   users.role_id = 1 (admin)        -> ADM-0001, ADM-0002, ...
--   users.role_id = 2 (nutritionist) -> NUT-0001, NUT-0002, ...
--   parents                          -> PAR-0001, PAR-0002, ...
--   children.child_code (existing)   -> CHD-0001, ... (left untouched)
--
-- These are DISPLAY identifiers only: primary keys, foreign keys, auth and
-- the parent portal are all unchanged. Each prefix has its own independent
-- counter, tracked in `code_counters` and allocated atomically at runtime by
-- includes/display_ids.php.
--
-- Columns stay NULLable + UNIQUE as a safety net; a later migration flips
-- them to NOT NULL once verify_display_ids.php reports zero gaps.
--
-- Idempotent: safe to re-run on XAMPP or Azure.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. Add the columns (guarded: MySQL has no ADD COLUMN IF NOT EXISTS)
-- ---------------------------------------------------------------------------
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'user_code'
);
SET @sql = IF(@col_exists > 0, 'SELECT 1',
    'ALTER TABLE `users` ADD COLUMN `user_code` VARCHAR(12) NULL AFTER `id`');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'parents'
      AND COLUMN_NAME = 'parent_code'
);
SET @sql = IF(@col_exists > 0, 'SELECT 1',
    'ALTER TABLE `parents` ADD COLUMN `parent_code` VARCHAR(12) NULL AFTER `id`');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. Unique backstops (guarded: MariaDB has no ADD UNIQUE IF NOT EXISTS)
-- ---------------------------------------------------------------------------
SET @idx_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'uq_users_code'
);
SET @sql = IF(@idx_exists > 0, 'SELECT 1',
    'ALTER TABLE `users` ADD UNIQUE KEY `uq_users_code` (`user_code`)');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'parents'
      AND INDEX_NAME = 'uq_parents_code'
);
SET @sql = IF(@idx_exists > 0, 'SELECT 1',
    'ALTER TABLE `parents` ADD UNIQUE KEY `uq_parents_code` (`parent_code`)');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3. Counter table: one row per prefix, advanced atomically at runtime
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS code_counters (
    prefix     VARCHAR(4)      NOT NULL,
    last_value INT UNSIGNED    NOT NULL DEFAULT 0,
    PRIMARY KEY (prefix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 4. Backfill existing rows, in id order (= creation order), per role.
--    Rank = COUNT(rows with id <= this row); deterministic, no session vars.
--    Materialized into a temp table so the UPDATE never reads its own table.
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_display_codes;
CREATE TEMPORARY TABLE tmp_display_codes (
    tbl  VARCHAR(8)  NOT NULL,
    id   INT UNSIGNED NOT NULL,
    code VARCHAR(12) NOT NULL,
    PRIMARY KEY (tbl, id)
) ENGINE=InnoDB;

INSERT INTO tmp_display_codes (tbl, id, code)
SELECT 'users', u.id,
       CONCAT('ADM-', LPAD((SELECT COUNT(*) FROM users x WHERE x.role_id = 1 AND x.id <= u.id), 4, '0'))
FROM users u
WHERE u.role_id = 1;

INSERT INTO tmp_display_codes (tbl, id, code)
SELECT 'users', u.id,
       CONCAT('NUT-', LPAD((SELECT COUNT(*) FROM users x WHERE x.role_id = 2 AND x.id <= u.id), 4, '0'))
FROM users u
WHERE u.role_id = 2;

INSERT INTO tmp_display_codes (tbl, id, code)
SELECT 'parents', p.id,
       CONCAT('PAR-', LPAD((SELECT COUNT(*) FROM parents x WHERE x.id <= p.id), 4, '0'))
FROM parents p;

UPDATE users u
JOIN tmp_display_codes t ON t.tbl = 'users' AND t.id = u.id
SET u.user_code = t.code
WHERE u.user_code IS NULL;

UPDATE parents p
JOIN tmp_display_codes t ON t.tbl = 'parents' AND t.id = p.id
SET p.parent_code = t.code
WHERE p.parent_code IS NULL;

DROP TEMPORARY TABLE IF EXISTS tmp_display_codes;

-- ---------------------------------------------------------------------------
-- 5. Seed the counters past the highest backfilled value (never decreases).
-- ---------------------------------------------------------------------------
INSERT INTO code_counters (prefix, last_value)
SELECT 'ADM', COUNT(*) FROM users WHERE role_id = 1
ON DUPLICATE KEY UPDATE last_value = GREATEST(last_value, VALUES(last_value));

INSERT INTO code_counters (prefix, last_value)
SELECT 'NUT', COUNT(*) FROM users WHERE role_id = 2
ON DUPLICATE KEY UPDATE last_value = GREATEST(last_value, VALUES(last_value));

INSERT INTO code_counters (prefix, last_value)
SELECT 'PAR', COUNT(*) FROM parents
ON DUPLICATE KEY UPDATE last_value = GREATEST(last_value, VALUES(last_value));

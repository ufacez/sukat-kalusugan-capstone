-- ============================================================================
-- Backfill: add local_area_id to parents + children if missing.
-- Safe to run multiple times (uses IF NOT EXISTS / conditional checks).
-- ============================================================================

-- 1. Create local_areas table if it doesn't exist
CREATE TABLE IF NOT EXISTS `local_areas` (
    `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `barangay_id`   int(10) UNSIGNED NOT NULL,
    `area_code`     varchar(30)  DEFAULT NULL,
    `area_name`     varchar(150) NOT NULL,
    `area_type`     enum('purok','sitio','subdivision','village','zone','phase','other') NOT NULL DEFAULT 'purok',
    `description`   varchar(255) DEFAULT NULL,
    `is_active`     tinyint(1)   NOT NULL DEFAULT 1,
    `created_at`    timestamp    NOT NULL DEFAULT current_timestamp(),
    `updated_at`    timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_local_areas_barangay` (`barangay_id`),
    KEY `idx_local_areas_active` (`is_active`),
    CONSTRAINT `fk_local_areas_barangay` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. Add local_area_id to children (skip if already exists)
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'children'
      AND COLUMN_NAME = 'local_area_id'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `children` ADD COLUMN `local_area_id` int(10) UNSIGNED DEFAULT NULL AFTER `barangay_id`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Add FK on children.local_area_id (skip if already exists)
SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'children'
      AND CONSTRAINT_NAME = 'fk_children_local_area'
);

SET @sql2 = IF(@fk_exists = 0,
    'ALTER TABLE `children` ADD CONSTRAINT `fk_children_local_area` FOREIGN KEY (`local_area_id`) REFERENCES `local_areas` (`id`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- 4. Add index on children.local_area_id (skip if already exists)
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'children'
      AND INDEX_NAME = 'idx_children_local_area_id'
);

SET @sql3 = IF(@idx_exists = 0,
    'ALTER TABLE `children` ADD KEY `idx_children_local_area_id` (`local_area_id`)',
    'SELECT 1'
);
PREPARE stmt3 FROM @sql3;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;

-- 5. Add local_area_id to parents (skip if already exists)
SET @col_exists2 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'parents'
      AND COLUMN_NAME = 'local_area_id'
);

SET @sql4 = IF(@col_exists2 = 0,
    'ALTER TABLE `parents` ADD COLUMN `local_area_id` int(10) UNSIGNED DEFAULT NULL AFTER `barangay_id`',
    'SELECT 1'
);
PREPARE stmt4 FROM @sql4;
EXECUTE stmt4;
DEALLOCATE PREPARE stmt4;

-- 6. Add FK on parents.local_area_id (skip if already exists)
SET @fk_exists2 = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'parents'
      AND CONSTRAINT_NAME = 'fk_parents_local_area'
);

SET @sql5 = IF(@fk_exists2 = 0,
    'ALTER TABLE `parents` ADD CONSTRAINT `fk_parents_local_area` FOREIGN KEY (`local_area_id`) REFERENCES `local_areas` (`id`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt5 FROM @sql5;
EXECUTE stmt5;
DEALLOCATE PREPARE stmt5;

-- 7. Add index on parents.local_area_id (skip if already exists)
SET @idx_exists2 = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'parents'
      AND INDEX_NAME = 'idx_parents_local_area_id'
);

SET @sql6 = IF(@idx_exists2 = 0,
    'ALTER TABLE `parents` ADD KEY `idx_parents_local_area_id` (`local_area_id`)',
    'SELECT 1'
);
PREPARE stmt6 FROM @sql6;
EXECUTE stmt6;
DEALLOCATE PREPARE stmt6;

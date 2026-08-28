-- ============================================================================
-- 20260828_local_areas.sql
-- Flexible Purok / Local Area system under each Barangay.
-- ============================================================================

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

-- Add local_area_id to children (nullable — existing children keep working)
ALTER TABLE `children`
    ADD COLUMN IF NOT EXISTS `local_area_id` int(10) UNSIGNED DEFAULT NULL AFTER `barangay_id`;

ALTER TABLE `children`
    ADD CONSTRAINT `fk_children_local_area` FOREIGN KEY (`local_area_id`) REFERENCES `local_areas` (`id`) ON DELETE SET NULL;

ALTER TABLE `children`
    ADD KEY `idx_children_local_area_id` (`local_area_id`);

-- Add local_area_id to parents (nullable — so children can inherit)
ALTER TABLE `parents`
    ADD COLUMN IF NOT EXISTS `local_area_id` int(10) UNSIGNED DEFAULT NULL AFTER `barangay_id`;

ALTER TABLE `parents`
    ADD CONSTRAINT `fk_parents_local_area` FOREIGN KEY (`local_area_id`) REFERENCES `local_areas` (`id`) ON DELETE SET NULL;

ALTER TABLE `parents`
    ADD KEY `idx_parents_local_area_id` (`local_area_id`);

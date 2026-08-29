-- ============================================================================
-- 20260829_children_permissions_status.sql
-- Add children CRUD permissions + status column for archive/restore
-- ============================================================================

-- Add status column to children (archive/restore support)
ALTER TABLE `children`
    ADD COLUMN IF NOT EXISTS `status` enum('active','inactive') NOT NULL DEFAULT 'active' AFTER `has_disability`;

ALTER TABLE `children`
    ADD KEY `idx_children_status` (`status`);

-- Add new permissions for children CRUD
INSERT IGNORE INTO `permissions` (`id`, `code`, `description`, `created_at`) VALUES
    (16, 'parents.view', 'View parent accounts', NOW()),
    (17, 'parents.create', 'Create parent accounts', NOW()),
    (18, 'parents.update', 'Update parent accounts', NOW()),
    (19, 'parents.delete', 'Delete (archive) parent accounts', NOW()),
    (20, 'children.view', 'View child profiles', NOW()),
    (21, 'children.create', 'Create child profiles', NOW()),
    (22, 'children.update', 'Update child profiles', NOW()),
    (23, 'children.delete', 'Delete (archive) child profiles', NOW());

-- Fix duplicate parents.view permission — remove old id=13
DELETE FROM `role_permissions` WHERE `permission_id` = 13;
DELETE FROM `permissions` WHERE `id` = 13;

-- Admin gets all permissions (16-23)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, id FROM `permissions` WHERE `id` BETWEEN 16 AND 23;

-- Nutritionist gets parents.view/create/update + children.view/create/update (no delete)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, id FROM `permissions` WHERE `id` IN (16, 17, 18, 20, 21, 22);

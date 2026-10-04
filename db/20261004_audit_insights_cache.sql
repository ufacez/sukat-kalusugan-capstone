-- ============================================================================
-- Audit-log insights cache + audit_logs grouping indexes (lag fix)
-- ----------------------------------------------------------------------------
-- /admin/audit_logs.php fires api/admin/audit_insights.php on every visit
-- and every 60s poll. That endpoint runs ~15 aggregation queries plus an
-- external AI call with zero caching, so the page feels like it reloads
-- late (stat cards / chart / AI panel pop in seconds after paint).
--
-- This migration:
--   1. Creates audit_insights_cache so the endpoint serves a cached payload
--      (10-minute TTL) instead of recomputing on every hit.
--   2. Adds single-column indexes on audit_logs(action) and audit_logs(level)
--      so the GROUP BY breakdowns use index scans instead of full scans +
--      temp-table sorts.
--
-- Idempotent: safe to re-run.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `audit_insights_cache` (
    `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `scope_key`     VARCHAR(64)  NOT NULL DEFAULT 'global',
    `cache_key`     VARCHAR(64)  NOT NULL DEFAULT 'default',
    `payload_json`  MEDIUMTEXT   NOT NULL,
    `source`        ENUM('ai','rule_based') NOT NULL DEFAULT 'rule_based',
    `generated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_audit_insights_cache` (`scope_key`, `cache_key`),
    KEY `idx_audit_insights_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MySQL-compatible idempotent ADD KEY (same pattern as
-- 20260913_unique_emails.sql; MariaDB-only IF NOT EXISTS avoided).
SET @idx_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'audit_logs'
    AND INDEX_NAME = 'idx_audit_action'
);
SET @sql = IF(@idx_exists > 0, 'SELECT 1', 'ALTER TABLE `audit_logs` ADD KEY `idx_audit_action` (`action`)');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'audit_logs'
    AND INDEX_NAME = 'idx_audit_level'
);
SET @sql = IF(@idx_exists > 0, 'SELECT 1', 'ALTER TABLE `audit_logs` ADD KEY `idx_audit_level` (`level`)');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

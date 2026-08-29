-- ============================================================================
-- Nutritionist dashboard AI insights cache
-- ----------------------------------------------------------------------------
-- The AI Insights panel on /nutritionist/dashboard.php makes one Gemini
-- (or OpenAI) call per refresh. To control API cost and latency, the
-- response is cached per (scope_key, cache_key) tuple for 6 hours.
--
-- A manual "Refresh insights" button (POST with force=1) bypasses the
-- cache and writes a fresh row.
--
-- Idempotent: safe to re-run.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `nutritionist_ai_insight_cache` (
    `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `scope_key`     VARCHAR(64)  NOT NULL COMMENT 'e.g. "all", "brgy:1" — drives cache lookup',
    `cache_key`     VARCHAR(64)  NOT NULL DEFAULT 'default' COMMENT 'bucket inside a scope, future-proofing',
    `payload_json`  TEXT         NOT NULL,
    `source`        ENUM('ai','rule_based') NOT NULL DEFAULT 'rule_based',
    `generated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`    TIMESTAMP    NOT NULL,
    UNIQUE KEY `uniq_nai_scope_cache` (`scope_key`, `cache_key`),
    KEY `idx_nai_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

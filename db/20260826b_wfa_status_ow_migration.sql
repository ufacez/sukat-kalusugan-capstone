-- ============================================================================
-- 20260826b_wfa_status_ow_migration.sql
--
-- Adds 'Overweight' to the weight-for-age axis, matching the official DOH
-- e-OPT Plus tool (verified against public_html/data/refrence_stanrddeviations.xlsx,
-- which emits OW under Weight-for-Age Status for children above +2SD).
--
-- Pairs with:
--   - who_calculator.php::classify_wfa_status() now returning 'OW' (not NULL)
--     when WAZ > +2
--   - db/20260826_who_reference_rebuild_expanded.sql (reference data rebuild)
--
-- Safe to re-run; MODIFY COLUMN is idempotent here since the target definition
-- matches what this migration produces.
-- ============================================================================

ALTER TABLE `measurements`
  MODIFY COLUMN `wfa_status`
    ENUM('SUW','MUW','Normal','OW') NULL DEFAULT NULL;

-- Rows measured before the code fix stored NULL on the WFA axis whenever
-- WAZ was above +2SD (the old classifier had no OW category). Recompute them
-- from the already-stored z-score so reports don't lose these children.
UPDATE `measurements`
SET `wfa_status` = 'OW'
WHERE `waz` IS NOT NULL
  AND `waz` > 2
  AND `wfa_status` IS NULL;

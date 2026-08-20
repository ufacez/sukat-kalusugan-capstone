-- ============================================================================
-- Measurement flagging (biologically implausible z-scores)
--
-- eOPT Plus flags a measurement when its z-score falls outside a per-age/
-- sex standard-deviation cutoff computed from a 4th reference parameter
-- (Nut_StatusTool!P10/V10/AB10 formulas). This system's who_weight_for_age
-- / who_height_for_age / who_weight_for_height tables only carry L/M/S, not
-- that 4th parameter, so this uses WHO's own simplified, widely-published
-- flagging thresholds instead (the same ones WHO Anthro software uses when
-- the extended SD tables aren't available):
--   WAZ flagged if outside -6 to 5
--   HAZ flagged if outside -6 to 6
--   WHZ flagged if outside -5 to 5
-- These catch the same thing in practice -- a measurement that's not
-- biologically possible for a living child, almost always a data-entry or
-- device error rather than a real result.
-- ============================================================================

SET NAMES utf8mb4;

ALTER TABLE measurements
  ADD COLUMN IF NOT EXISTS is_flagged TINYINT(1) NOT NULL DEFAULT 0 AFTER wfh_status,
  ADD COLUMN IF NOT EXISTS flag_reason VARCHAR(150) NULL AFTER is_flagged;

-- Backfill every existing measurement that already has computed z-scores.
UPDATE measurements
SET
  is_flagged = CASE WHEN (waz < -6 OR waz > 5 OR haz < -6 OR haz > 6 OR whz < -5 OR whz > 5) THEN 1 ELSE 0 END,
  flag_reason = TRIM(BOTH '; ' FROM CONCAT(
    CASE WHEN waz < -6 OR waz > 5 THEN 'WAZ out of range; ' ELSE '' END,
    CASE WHEN haz < -6 OR haz > 6 THEN 'HAZ out of range; ' ELSE '' END,
    CASE WHEN whz < -5 OR whz > 5 THEN 'WHZ out of range; ' ELSE '' END
  ))
WHERE waz IS NOT NULL AND haz IS NOT NULL AND whz IS NOT NULL;

UPDATE measurements SET flag_reason = NULL WHERE flag_reason = '';

ALTER TABLE measurements
  ADD KEY IF NOT EXISTS idx_measurements_is_flagged (is_flagged);

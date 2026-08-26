-- 20260827_backfill_status_from_zscores.sql
-- Backfills wfa_status, hfa_status, wfh_status, and nutritional_status
-- from existing waz, haz, whz z-scores for measurements where status columns are NULL.

-- wfa_status from waz
UPDATE measurements
SET wfa_status = CASE
    WHEN waz < -3 THEN 'SUW'
    WHEN waz < -2 THEN 'MUW'
    WHEN waz > 2 THEN 'OW'
    ELSE 'Normal'
END
WHERE wfa_status IS NULL AND waz IS NOT NULL;

-- hfa_status from haz
UPDATE measurements
SET hfa_status = CASE
    WHEN haz < -3 THEN 'SSt'
    WHEN haz < -2 THEN 'MSt'
    WHEN haz > 2 THEN 'Tall'
    ELSE 'Normal'
END
WHERE hfa_status IS NULL AND haz IS NOT NULL;

-- wfh_status from whz
UPDATE measurements
SET wfh_status = CASE
    WHEN whz < -3 THEN 'SW'
    WHEN whz < -2 THEN 'MW'
    WHEN whz > 3 THEN 'Ob'
    WHEN whz > 2 THEN 'OW'
    ELSE 'Normal'
END
WHERE wfh_status IS NULL AND whz IS NOT NULL;

-- nutritional_status (most severe axis)
UPDATE measurements
SET nutritional_status = CASE
    WHEN waz < -3 THEN 'Severely Underweight'
    WHEN haz < -3 THEN 'Severely Stunted'
    WHEN whz < -3 THEN 'Severely Wasted'
    WHEN waz < -2 THEN 'Moderately Underweight'
    WHEN haz < -2 THEN 'Moderately Stunted'
    WHEN whz < -2 THEN 'Moderately Wasted'
    WHEN whz > 3 THEN 'Obese'
    WHEN whz > 2 THEN 'Overweight'
    ELSE 'Normal'
END
WHERE nutritional_status IS NULL AND waz IS NOT NULL AND haz IS NOT NULL AND whz IS NOT NULL;

-- Also backfill is_flagged where NULL
UPDATE measurements
SET is_flagged = CASE
    WHEN waz < -6 OR waz > 5 OR haz < -6 OR haz > 6 OR whz < -5 OR whz > 5 THEN 1
    ELSE 0
END,
flag_reason = CASE
    WHEN (waz < -6 OR waz > 5) AND (haz < -6 OR haz > 6) AND (whz < -5 OR whz > 5) THEN 'WAZ out of range; HAZ out of range; WHZ out of range'
    WHEN (waz < -6 OR waz > 5) AND (haz < -6 OR haz > 6) THEN 'WAZ out of range; HAZ out of range'
    WHEN (waz < -6 OR waz > 5) AND (whz < -5 OR whz > 5) THEN 'WAZ out of range; WHZ out of range'
    WHEN (haz < -6 OR haz > 6) AND (whz < -5 OR whz > 5) THEN 'HAZ out of range; WHZ out of range'
    WHEN (waz < -6 OR waz > 5) THEN 'WAZ out of range'
    WHEN (haz < -6 OR haz > 6) THEN 'HAZ out of range'
    WHEN (whz < -5 OR whz > 5) THEN 'WHZ out of range'
    ELSE NULL
END
WHERE is_flagged IS NULL AND waz IS NOT NULL;

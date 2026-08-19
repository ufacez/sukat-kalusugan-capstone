-- ============================================================================
-- 20260819_measurements_data_quality_flag.sql
-- Adds measurements.data_quality_flag so the WHO-standard "biologically
-- implausible" flag computed by calculate_who_metrics() (see who_calculator.php
-- -> WHO_FLAG_LIMITS / who_is_flagged()) is actually stored and can be shown
-- to nutritionists, instead of being computed and thrown away.
--
-- This is the app's equivalent of the "flagged children" columns (P/V/AB) in
-- the DOH e-OPT Plus "Nut_StatusTool" sheet: it does NOT change the
-- nutritional_status label (a flagged record can be genuinely severe), it
-- just marks the record for a human to double-check the raw
-- height/weight/age before acting on it.
-- ============================================================================

ALTER TABLE `measurements`
  ADD COLUMN `data_quality_flag` TINYINT(1) NOT NULL DEFAULT 0
  AFTER `wfh_status`;

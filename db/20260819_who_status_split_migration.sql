-- ============================================================================
-- DOH eOPT Plus alignment: three-axis nutritional status + Form 1A fields
--
-- The Operation Timbang Plus (OPT Plus) community-level tool used by DOH
-- classifies every child on THREE independent axes per visit:
--   - Weight-for-Age   (WFA): SUW / MUW / Normal
--   - Height-for-Age   (HFA): SSt / MSt / Normal / Tall
--   - Weight-for-Height(WFH): SW/SAM / MW/MAM / Normal / OW / Ob
-- A child can be stunted (HFA) with a perfectly normal weight-for-height
-- (WFH) -- collapsing these into the single `nutritional_status` column
-- loses exactly the distinction DOH programs act on. That column is left
-- in place (existing follow-up/report logic depends on it) and these three
-- new columns are added alongside it as the DOH-accurate representation.
--
-- Cutoffs match the eOPT Plus "Nut_StatusTool" sheet formulas exactly:
--   WFA: waz<-3 SUW | -3<=waz<-2 MUW | -2<=waz<=2 Normal | waz>2 not classified (see WFH)
--   HFA: haz<-3 SSt | -3<=haz<-2 MSt | -2<=haz<=2 Normal | haz>2 Tall
--   WFH: whz<-3 SW/SAM | -3<=whz<-2 MW/MAM | -2<=whz<=2 Normal | 2<whz<=3 OW | whz>3 Ob
--
-- Also adds three DOH Form 1A fields to `children`: IP-group flag,
-- disability flag, and purok (sub-barangay location) as their own column
-- instead of buried in free-text `address`.
--
-- MUAC and bilateral pitting edema are intentionally NOT included -- this
-- system's kiosk only takes weight/height, so there's no measurement
-- source to populate those fields from.
-- ============================================================================

SET NAMES utf8mb4;

ALTER TABLE measurements
  ADD COLUMN IF NOT EXISTS wfa_status ENUM('SUW','MUW','Normal') NULL AFTER nutritional_status,
  ADD COLUMN IF NOT EXISTS hfa_status ENUM('SSt','MSt','Normal','Tall') NULL AFTER wfa_status,
  ADD COLUMN IF NOT EXISTS wfh_status ENUM('SW/SAM','MW/MAM','Normal','OW','Ob') NULL AFTER hfa_status;

ALTER TABLE children
  ADD COLUMN IF NOT EXISTS purok VARCHAR(150) NULL AFTER address,
  ADD COLUMN IF NOT EXISTS is_ip TINYINT(1) NOT NULL DEFAULT 0 AFTER purok,
  ADD COLUMN IF NOT EXISTS has_disability TINYINT(1) NOT NULL DEFAULT 0 AFTER is_ip;

-- Backfill the three status columns for every existing measurement that
-- already has waz/haz/whz computed, using the exact DOH cutoffs above.
UPDATE measurements
SET wfa_status = CASE
    WHEN waz IS NULL THEN NULL
    WHEN waz < -3 THEN 'SUW'
    WHEN waz < -2 THEN 'MUW'
    WHEN waz <= 2 THEN 'Normal'
    ELSE NULL
END
WHERE waz IS NOT NULL AND wfa_status IS NULL;

UPDATE measurements
SET hfa_status = CASE
    WHEN haz IS NULL THEN NULL
    WHEN haz < -3 THEN 'SSt'
    WHEN haz < -2 THEN 'MSt'
    WHEN haz <= 2 THEN 'Normal'
    ELSE 'Tall'
END
WHERE haz IS NOT NULL AND hfa_status IS NULL;

UPDATE measurements
SET wfh_status = CASE
    WHEN whz IS NULL THEN NULL
    WHEN whz < -3 THEN 'SW/SAM'
    WHEN whz < -2 THEN 'MW/MAM'
    WHEN whz <= 2 THEN 'Normal'
    WHEN whz <= 3 THEN 'OW'
    ELSE 'Ob'
END
WHERE whz IS NOT NULL AND wfh_status IS NULL;

ALTER TABLE measurements
  ADD KEY IF NOT EXISTS idx_measurements_wfa_status (wfa_status),
  ADD KEY IF NOT EXISTS idx_measurements_hfa_status (hfa_status),
  ADD KEY IF NOT EXISTS idx_measurements_wfh_status (wfh_status);

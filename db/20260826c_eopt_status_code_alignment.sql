-- ============================================================================
-- 20260826c_eopt_status_code_alignment.sql
--
-- Aligns the stored per-axis nutritional-status codes with the DOH e-OPT Plus
-- community-tool spelling used by public_html/data/refrence_stanrddeviations.xlsx
-- and the national eOPT Plus coding legend:
--
--   MUW    -> UW   (moderate underweight)
--   MSt    -> St   (moderate stunting)
--   Tall   -> T    (above +2SD height-for-age)
--   SW/SAM -> SW   (severe wasting)
--   MW/MAM -> MW   (moderate wasting)
--
-- Canonical sets after this migration:
--   wfa_status : 'SUW','UW','Normal','OW'
--   hfa_status : 'SSt','St','Normal','T'
--   wfh_status : 'SW','MW','Normal','OW','Ob'
--
-- Pattern: widen each ENUM so old + new spellings coexist, remap the rows,
-- then narrow to the canonical set (MySQL would silently blank out unknown
-- values on narrowing, hence the order).
--
-- Pairs with who_calculator.php::classify_*_status() which now emit the new
-- literals. Safe to re-run (updates affect zero rows on second pass).
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. Widen (old + new spellings valid at once)
-- ---------------------------------------------------------------------------

ALTER TABLE `measurements`
  MODIFY COLUMN `wfa_status`
    ENUM('SUW','MUW','UW','Normal','OW') NULL DEFAULT NULL;

ALTER TABLE `measurements`
  MODIFY COLUMN `hfa_status`
    ENUM('SSt','MSt','St','Normal','Tall','T') NULL DEFAULT NULL;

ALTER TABLE `measurements`
  MODIFY COLUMN `wfh_status`
    ENUM('SW/SAM','MW/MAM','SW','MW','Normal','OW','Ob') NULL DEFAULT NULL;

-- ---------------------------------------------------------------------------
-- 2. Remap stored rows
-- ---------------------------------------------------------------------------

UPDATE `measurements` SET `wfa_status` = 'UW' WHERE `wfa_status` = 'MUW';

UPDATE `measurements` SET `hfa_status` = 'St' WHERE `hfa_status` = 'MSt';

UPDATE `measurements` SET `hfa_status` = 'T'  WHERE `hfa_status` = 'Tall';

UPDATE `measurements` SET `wfh_status` = 'SW' WHERE `wfh_status` = 'SW/SAM';

UPDATE `measurements` SET `wfh_status` = 'MW' WHERE `wfh_status` = 'MW/MAM';

-- ---------------------------------------------------------------------------
-- 3. Narrow to the canonical e-OPT sets
-- ---------------------------------------------------------------------------

ALTER TABLE `measurements`
  MODIFY COLUMN `wfa_status`
    ENUM('SUW','UW','Normal','OW') NULL DEFAULT NULL;

ALTER TABLE `measurements`
  MODIFY COLUMN `hfa_status`
    ENUM('SSt','St','Normal','T') NULL DEFAULT NULL;

ALTER TABLE `measurements`
  MODIFY COLUMN `wfh_status`
    ENUM('SW','MW','Normal','OW','Ob') NULL DEFAULT NULL;

-- ---------------------------------------------------------------------------
-- 4. Verification queries (run manually):
--    SHOW COLUMNS FROM measurements LIKE 'wfa_status';  -- enum('SUW','UW','Normal','OW')
--    SHOW COLUMNS FROM measurements LIKE 'hfa_status';  -- enum('SSt','St','Normal','T')
--    SHOW COLUMNS FROM measurements LIKE 'wfh_status';  -- enum('SW','MW','Normal','OW','Ob')
--    SELECT wfa_status, COUNT(*) FROM measurements GROUP BY wfa_status;
--    SELECT hfa_status, COUNT(*) FROM measurements GROUP BY hfa_status;
--    SELECT wfh_status, COUNT(*) FROM measurements GROUP BY wfh_status;
-- ============================================================================

-- 20260827_nutritional_status_enum_update.sql
-- Updates the measurements.nutritional_status ENUM to include all DOH eOPT Plus categories.
--
-- Before: enum('Normal','Underweight','Severely Underweight','Stunted','Wasted','Overweight')
-- After:  enum('Normal','Underweight','Severely Underweight','Stunted','Severely Stunted',
--             'Moderately Wasted','Severely Wasted','Overweight','Obese')
--
-- Also ensures wfa_status, hfa_status, wfh_status use the correct abbreviated codes
-- (in case schema.sql was re-imported after the 20260826c migration).

-- 1. Widen nutritional_status ENUM to include the new categories
ALTER TABLE `measurements`
  MODIFY COLUMN `nutritional_status`
    ENUM(
      'Normal',
      'Underweight',
      'Severely Underweight',
      'Stunted',
      'Severely Stunted',
      'Moderately Wasted',
      'Severely Wasted',
      'Overweight',
      'Obese'
    ) NULL DEFAULT NULL;

-- 2. Remap existing 'Wasted' values to 'Moderately Wasted' (WHZ -3..-2 range)
UPDATE `measurements` SET `nutritional_status` = 'Moderately Wasted' WHERE `nutritional_status` = 'Wasted';

-- 3. Ensure wfa_status uses abbreviated codes (fix if schema.sql was re-imported)
ALTER TABLE `measurements`
  MODIFY COLUMN `wfa_status`
    ENUM('SUW','UW','Normal','OW') NULL DEFAULT NULL;

UPDATE `measurements` SET `wfa_status` = 'UW' WHERE `wfa_status` = 'MUW';

-- 4. Ensure hfa_status uses abbreviated codes
ALTER TABLE `measurements`
  MODIFY COLUMN `hfa_status`
    ENUM('SSt','St','Normal','T') NULL DEFAULT NULL;

UPDATE `measurements` SET `hfa_status` = 'St' WHERE `hfa_status` = 'MSt';
UPDATE `measurements` SET `hfa_status` = 'T'  WHERE `hfa_status` = 'Tall';

-- 5. Ensure wfh_status uses abbreviated codes
ALTER TABLE `measurements`
  MODIFY COLUMN `wfh_status`
    ENUM('SW','MW','Normal','OW','Ob') NULL DEFAULT NULL;

UPDATE `measurements` SET `wfh_status` = 'SW' WHERE `wfh_status` IN ('SW/SAM', 'SW(SAM)');
UPDATE `measurements` SET `wfh_status` = 'MW' WHERE `wfh_status` IN ('MW/MAM', 'MW(MAM)');

-- 6. Backfill NULL/empty wfa_status, hfa_status, wfh_status, nutritional_status
--    from existing waz/haz/whz values using the correct classification rules.

-- WFA: SUW < -3 | UW -3..-2 | Normal -2..+2 | OW > +2
UPDATE `measurements`
SET `wfa_status` = CASE
    WHEN `waz` < -3 THEN 'SUW'
    WHEN `waz` < -2 THEN 'UW'
    WHEN `waz` > 2  THEN 'OW'
    ELSE 'Normal'
END
WHERE (`wfa_status` IS NULL OR `wfa_status` = '') AND `waz` IS NOT NULL;

-- HFA: SSt < -3 | St -3..-2 | Normal -2..+2 | T > +2
UPDATE `measurements`
SET `hfa_status` = CASE
    WHEN `haz` < -3 THEN 'SSt'
    WHEN `haz` < -2 THEN 'St'
    WHEN `haz` > 2  THEN 'T'
    ELSE 'Normal'
END
WHERE (`hfa_status` IS NULL OR `hfa_status` = '') AND `haz` IS NOT NULL;

-- WFH: SW < -3 | MW -3..-2 | Normal -2..+2 | OW +2..+3 | Ob > +3
UPDATE `measurements`
SET `wfh_status` = CASE
    WHEN `whz` < -3 THEN 'SW'
    WHEN `whz` < -2 THEN 'MW'
    WHEN `whz` > 3  THEN 'Ob'
    WHEN `whz` > 2  THEN 'OW'
    ELSE 'Normal'
END
WHERE (`wfh_status` IS NULL OR `wfh_status` = '') AND `whz` IS NOT NULL;

-- nutritional_status: single most severe label
UPDATE `measurements`
SET `nutritional_status` = CASE
    WHEN `waz` < -3 THEN 'Severely Underweight'
    WHEN `haz` < -3 THEN 'Severely Stunted'
    WHEN `whz` < -3 THEN 'Severely Wasted'
    WHEN `waz` < -2 THEN 'Underweight'
    WHEN `haz` < -2 THEN 'Stunted'
    WHEN `whz` < -2 THEN 'Moderately Wasted'
    WHEN `whz` > 3  THEN 'Obese'
    WHEN `whz` > 2  THEN 'Overweight'
    ELSE 'Normal'
END
WHERE (`nutritional_status` IS NULL OR `nutritional_status` = '') AND `waz` IS NOT NULL;

-- Verify:
--    SHOW COLUMNS FROM measurements LIKE 'nutritional_status';
--    SHOW COLUMNS FROM measurements LIKE 'wfa_status';
--    SHOW COLUMNS FROM measurements LIKE 'hfa_status';
--    SHOW COLUMNS FROM measurements LIKE 'wfh_status';
--    SELECT nutritional_status, COUNT(*) FROM measurements GROUP BY nutritional_status;
--    SELECT wfa_status, COUNT(*) FROM measurements GROUP BY wfa_status;
--    SELECT hfa_status, COUNT(*) FROM measurements GROUP BY hfa_status;
--    SELECT wfh_status, COUNT(*) FROM measurements GROUP BY wfh_status;

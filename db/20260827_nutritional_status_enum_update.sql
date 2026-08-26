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

-- Verify:
--    SHOW COLUMNS FROM measurements LIKE 'nutritional_status';
--    SHOW COLUMNS FROM measurements LIKE 'wfa_status';
--    SHOW COLUMNS FROM measurements LIKE 'hfa_status';
--    SHOW COLUMNS FROM measurements LIKE 'wfh_status';
--    SELECT nutritional_status, COUNT(*) FROM measurements GROUP BY nutritional_status;
--    SELECT wfa_status, COUNT(*) FROM measurements GROUP BY wfa_status;
--    SELECT hfa_status, COUNT(*) FROM measurements GROUP BY hfa_status;
--    SELECT wfh_status, COUNT(*) FROM measurements GROUP BY wfh_status;

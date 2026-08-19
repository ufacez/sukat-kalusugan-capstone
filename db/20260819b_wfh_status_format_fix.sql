-- ============================================================================
-- Correction: WFH status value format
--
-- The first version of the wfh_status column used 'SW(SAM)' / 'MW(MAM)'
-- (parentheses), matching the acronym legend's display format. Checking
-- against the actual eOPT Plus workbook formulas (Nut_StatusTool!O10 etc.)
-- shows the tool's real computed output uses a forward slash instead:
-- 'SW/SAM' and 'MW/MAM'. This matters if you ever cross-reference or
-- import/export data against an actual eOPT Plus file, since the values
-- need to match exactly.
--
-- Requires 20260819_who_status_split_migration.sql to have been run first
-- (wfh_status must already exist). Safe to run regardless of which
-- spelling that migration produced -- if every row already uses the
-- correct 'SW/SAM'/'MW/MAM' spelling, the UPDATE statements below simply
-- affect zero rows.
-- ============================================================================

SET NAMES utf8mb4;

-- Widen the enum first so both old and new spellings are valid while we
-- migrate the data -- narrowing it before fixing the values would cause
-- MySQL to silently blank out any row still holding the old spelling.
ALTER TABLE measurements
  MODIFY COLUMN wfh_status ENUM('SW(SAM)','MW(MAM)','SW/SAM','MW/MAM','Normal','OW','Ob') NULL;

UPDATE measurements SET wfh_status = 'SW/SAM' WHERE wfh_status = 'SW(SAM)';
UPDATE measurements SET wfh_status = 'MW/MAM' WHERE wfh_status = 'MW(MAM)';

-- Now that no row uses the old spelling, narrow the enum to the correct set.
ALTER TABLE measurements
  MODIFY COLUMN wfh_status ENUM('SW/SAM','MW/MAM','Normal','OW','Ob') NULL;

-- V2 Classification Alignment Migration
-- Remaps DB status codes to V2 canonical codes:
--   wfa_status: UW → MUW
--   hfa_status: St → MSt, T → Tall
--   nutritional_status: V1 labels → V2 labels
-- Run AFTER 20260827_eopt_v2_list_restructure.sql

-- =====================================================
-- 1. Backfill existing data: remap codes
-- =====================================================

-- wfa_status: UW → MUW (SUW and OW stay the same)
UPDATE measurements SET wfa_status = 'MUW' WHERE wfa_status = 'UW';

-- hfa_status: St → MSt, T → Tall (SSt stays the same)
UPDATE measurements SET hfa_status = 'MSt' WHERE hfa_status = 'St';
UPDATE measurements SET hfa_status = 'Tall' WHERE hfa_status = 'T';

-- nutritional_status: V1 → V2 labels
UPDATE measurements SET nutritional_status = 'Moderately Underweight' WHERE nutritional_status = 'Underweight';
UPDATE measurements SET nutritional_status = 'Moderately Stunted' WHERE nutritional_status = 'Stunted';
UPDATE measurements SET nutritional_status = 'Moderately Wasted' WHERE nutritional_status = 'Wasted';
UPDATE measurements SET nutritional_status = 'Severely Underweight' WHERE nutritional_status = 'Severely Underweight';
UPDATE measurements SET nutritional_status = 'Severely Stunted' WHERE nutritional_status = 'Severely Stunted';
UPDATE measurements SET nutritional_status = 'Severely Wasted' WHERE nutritional_status = 'Severely Wasted';

-- Also handle legacy 'Tall' that may exist from before previous migrations
UPDATE measurements SET hfa_status = 'Tall' WHERE hfa_status = 'T';

-- =====================================================
-- 2. Alter ENUMs to V2 canonical codes
-- =====================================================

ALTER TABLE measurements
  MODIFY COLUMN wfa_status ENUM('SUW','MUW','Normal','OW') DEFAULT NULL;

ALTER TABLE measurements
  MODIFY COLUMN hfa_status ENUM('SSt','MSt','Normal','Tall') DEFAULT NULL;

ALTER TABLE measurements
  MODIFY COLUMN nutritional_status ENUM(
    'Normal',
    'Moderately Underweight','Severely Underweight',
    'Moderately Stunted','Severely Stunted',
    'Moderately Wasted','Severely Wasted',
    'Overweight','Obese'
  ) DEFAULT NULL;

-- wfh_status stays the same: SW, MW, Normal, OW, Ob

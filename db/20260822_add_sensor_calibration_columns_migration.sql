-- ============================================================================
-- Add live sensor calibration columns to devices
-- ============================================================================
-- Adds the two raw sensor-calibration values that the ESP32 firmware used to
-- hardcode as .ino constants:
--   - hx711_calibration_factor : HX711 load-cell scale factor (grams/count)
--   - mounting_height_cm       : TF-Luna sensor height above an empty platform
--
-- These are DIFFERENT from the existing devices.calibration_offset_height /
-- calibration_offset_weight columns, which are still the live, working
-- fine-tune offsets applied server-side in api/esp32/submit_measurement.php.
-- The two columns added here are the raw sensor-level values the firmware
-- itself needs in order to convert a raw HX711 reading into grams and a raw
-- TF-Luna distance into a height. Previously changing them required editing
-- the .ino sketch and reflashing. As of this migration, the ESP32 firmware
-- (esp32_kios_arduino_code.ino) fetches these two fields from
-- api/esp32/get_command.php on every poll (~every 2s) and applies them live,
-- so the admin Sensors page is now the single place to calibrate a device --
-- no reflash needed for either sensor.
--
-- Defaults match the values that were previously hardcoded in the firmware,
-- so existing devices keep behaving exactly as before until an admin
-- changes them.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE devices
    ADD COLUMN IF NOT EXISTS hx711_calibration_factor DECIMAL(12,4) NOT NULL DEFAULT -20892.5000 AFTER calibration_offset_weight,
    ADD COLUMN IF NOT EXISTS mounting_height_cm DECIMAL(6,2) NOT NULL DEFAULT 182.88 AFTER hx711_calibration_factor;

SET FOREIGN_KEY_CHECKS = 1;

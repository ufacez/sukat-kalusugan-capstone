-- ============================================================================
-- Drop unused device_sensor_settings table
-- ============================================================================
-- This table (HX711 cal. factor, TF-Luna offset/scale, mounted height,
-- weight_offset_kg) was written to from the admin Sensors form but never
-- read back by any code path: not the ESP32 firmware (which hardcodes its
-- own HX711_CAL_FACTOR / MOUNTING_HEIGHT_CM / HEIGHT_OFFSET_CM constants in
-- the .ino sketch and only changes on reflash), and not
-- api/esp32/submit_measurement.php (which only ever reads
-- devices.calibration_offset_height and devices.calibration_offset_weight).
--
-- Editing those fields in the admin UI silently did nothing to a real
-- measurement. The only calibration path that is actually live is
-- devices.calibration_offset_height / devices.calibration_offset_weight,
-- which the admin Sensors form still writes to directly. This migration
-- removes the dead table so there is exactly one place to calibrate a
-- device from the web app.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS device_sensor_settings;

SET FOREIGN_KEY_CHECKS = 1;

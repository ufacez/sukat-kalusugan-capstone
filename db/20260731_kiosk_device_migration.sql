-- ============================================================================
-- Kiosk device migration
-- Adds a heartbeat column and seeds the default kiosk device used by the demo
-- and API submission flow.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE devices
    ADD COLUMN IF NOT EXISTS last_seen_at TIMESTAMP NULL AFTER status;

INSERT INTO devices (device_code, location, last_seen_at, calibration_offset_height, calibration_offset_weight, status)
SELECT
    'ESP32-KIOSK-01',
    'Anthropometric Kiosk',
    CURRENT_TIMESTAMP,
    0.00,
    0.000,
    'active'
WHERE NOT EXISTS (
    SELECT 1 FROM devices WHERE device_code = 'ESP32-KIOSK-01'
);

SET FOREIGN_KEY_CHECKS = 1;
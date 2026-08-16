-- ============================================================================
-- Sensor device settings migration
-- Adds a dedicated config table for HX711 and TF-Luna calibration settings and
-- ensures kiosk devices have a heartbeat field for online/offline status.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE devices
    ADD COLUMN IF NOT EXISTS last_seen_at TIMESTAMP NULL AFTER status;

CREATE TABLE IF NOT EXISTS device_sensor_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_code VARCHAR(50) NOT NULL,
    hx711_calibration_factor DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
    hx711_tare_offset DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    tf_luna_offset_cm DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    tf_luna_scale_factor DECIMAL(8,4) NOT NULL DEFAULT 1.0000,
    height_offset_cm DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    weight_offset_kg DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    last_calibration_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_device_sensor_settings (device_code),
    CONSTRAINT fk_device_sensor_settings_device FOREIGN KEY (device_code)
        REFERENCES devices(device_code)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO device_sensor_settings (
    device_code,
    hx711_calibration_factor,
    hx711_tare_offset,
    tf_luna_offset_cm,
    tf_luna_scale_factor,
    height_offset_cm,
    weight_offset_kg,
    last_calibration_at
)
SELECT
    d.device_code,
    0.0000,
    0.000,
    0.00,
    1.0000,
    0.00,
    0.0000,
    NULL
FROM devices d
LEFT JOIN device_sensor_settings s ON s.device_code = d.device_code
WHERE s.id IS NULL;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- Kiosk sensors migration
-- Adds a simple table to persist TF-Luna / HX711 sensor readings from kiosk devices.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS kiosk_sensor_readings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_code VARCHAR(128) NOT NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    height_cm DECIMAL(6,2) NULL,
    weight_kg DECIMAL(6,3) NULL,
    raw_payload JSON NULL,
    source_ip VARCHAR(45) NULL,
    INDEX idx_device_time (device_code, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

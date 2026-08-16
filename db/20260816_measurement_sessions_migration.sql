-- ============================================================================
-- Measurement session command queue
-- Adds a device-scoped session table so the kiosk can queue a START command,
-- the ESP32 can claim it once, and the completed measurement can be stored
-- exactly once per session.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS measurement_sessions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id       INT UNSIGNED NOT NULL,
    child_id        INT UNSIGNED NOT NULL,
    status          ENUM('IDLE','START_REQUESTED','MEASURING','COMPLETE','ERROR','CANCELLED') NOT NULL DEFAULT 'IDLE',
    command         VARCHAR(20) NOT NULL DEFAULT 'START',
    started_at      TIMESTAMP NULL,
    completed_at    TIMESTAMP NULL,
    expires_at      TIMESTAMP NULL,
    height_cm       DECIMAL(6,2) NULL,
    weight_kg       DECIMAL(6,3) NULL,
    measurement_id  INT UNSIGNED NULL,
    error_message   VARCHAR(255) NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_measurement_sessions_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
    CONSTRAINT fk_measurement_sessions_child FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE RESTRICT,
    CONSTRAINT fk_measurement_sessions_measurement FOREIGN KEY (measurement_id) REFERENCES measurements(id) ON DELETE SET NULL,
    UNIQUE KEY uq_measurement_sessions_measurement (measurement_id),
    INDEX idx_measurement_sessions_device_status (device_id, status, id),
    INDEX idx_measurement_sessions_device_created (device_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

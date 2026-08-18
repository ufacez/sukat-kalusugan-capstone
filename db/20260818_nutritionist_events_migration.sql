-- ============================================================================
-- Nutritionist calendar events
-- Backs the "Meeting" and "Oplan Timbang" entries shown on the nutritionist
-- dashboard calendar so they can be created, edited, and removed instead of
-- being hard-coded placeholder dots. Appointments already have their own
-- table and continue to be pulled into the calendar from there.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS nutritionist_events (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type      ENUM('meeting','oplan_timbang') NOT NULL,
    title           VARCHAR(150) NOT NULL,
    event_date      DATE NOT NULL,
    event_time      TIME NULL,
    location        VARCHAR(150) NULL,
    barangay        VARCHAR(100) NULL,
    notes           TEXT NULL,
    nutritionist_id INT UNSIGNED NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_nutritionist_events_user FOREIGN KEY (nutritionist_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_nutritionist_events_date (event_date),
    INDEX idx_nutritionist_events_type_date (event_type, event_date),
    INDEX idx_nutritionist_events_barangay (barangay)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

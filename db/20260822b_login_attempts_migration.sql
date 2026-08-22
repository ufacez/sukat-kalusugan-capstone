-- ============================================================================
-- Login attempt tracking for brute-force lockout
-- ============================================================================
-- Records every failed login attempt (staff and parent both share the same
-- login form/endpoint, so this is keyed by whatever identifier the person
-- typed, not by a specific table). api/auth/login.php checks this table
-- before validating credentials and blocks the attempt if the identifier
-- has too many recent failures — see includes/login_throttle.php for the
-- actual lockout logic (LOGIN_MAX_FAILED_ATTEMPTS / LOGIN_LOCKOUT_WINDOW_MINUTES).
-- ============================================================================

CREATE TABLE IF NOT EXISTS login_attempts (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier    VARCHAR(150) NOT NULL,
    ip_address    VARCHAR(45) NULL,
    success       TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_identifier_time (identifier, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

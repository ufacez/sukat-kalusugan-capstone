-- ============================================================================
-- Password reset tokens (forgot password flow)
-- ============================================================================
-- Staff (users) and parent accounts share the login form/endpoint, so this
-- table is keyed by account_type + account_id instead of a foreign key into
-- a single table. Only the SHA-256 hash of the reset token is stored — the
-- raw token only ever exists in the emailed link, the same way password_hash
-- keeps the raw password out of the database. See
-- includes/password_reset.php for the create/validate/consume helpers, and
-- api/auth/forgot_password.php + api/auth/reset_password.php for the flow.
-- ============================================================================

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_type  ENUM('staff','parent') NOT NULL,
    account_id    INT UNSIGNED NOT NULL,
    token_hash    CHAR(64) NOT NULL,
    expires_at    TIMESTAMP NOT NULL,
    used_at       TIMESTAMP NULL DEFAULT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address    VARCHAR(45) NULL,
    UNIQUE KEY uniq_password_reset_token_hash (token_hash),
    INDEX idx_password_reset_account (account_type, account_id),
    INDEX idx_password_reset_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

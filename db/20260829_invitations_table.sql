-- Invitations table for staff account provisioning.
-- Admin generates a code → shares via email or verbally → new staff activates.

CREATE TABLE IF NOT EXISTS invitations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inviter_user_id INT UNSIGNED NOT NULL,
    invitee_name VARCHAR(150) NOT NULL,
    invitee_email VARCHAR(150) NULL,
    barangay_id INT UNSIGNED NULL,
    role ENUM('admin','nutritionist') NOT NULL DEFAULT 'nutritionist',
    code VARCHAR(8) NOT NULL,
    method ENUM('email','manual') NOT NULL DEFAULT 'manual',
    status ENUM('pending','used','expired','cancelled') NOT NULL DEFAULT 'pending',
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_invitation_code (code),
    KEY idx_invitation_status (status),
    KEY idx_invitation_inviter (inviter_user_id),
    FOREIGN KEY (inviter_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

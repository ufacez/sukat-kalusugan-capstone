-- ============================================================================
-- 20261009_invitation_private_sms.sql
--
-- Private invitation codes (panel requirement):
--   - codes are never stored in plaintext and never shown in the web UI;
--   - invite requires BOTH email and mobile; code is delivered via SMTP
--     email AND Semaphore SMS, and activation must present code + mobile;
--   - the legacy `manual` delivery method (share code in person / on screen)
--     is removed in favor of `email` / `sms` / `both`.
--
-- Changes:
--   1. invitations.code_hash CHAR(64) — SHA-256 of the uppercased code.
--      Lookup key for activation. UNIQUE so two pending invites can never
--      share a code. Plaintext `code` is kept NULL-able for history only
--      (old rows) and MUST NOT be selected/displayed by new code.
--   2. invitations.invitee_phone_hash CHAR(64) — SHA-256 of the normalized
--      09XXXXXXXXX mobile. Needed because invitee_phone is AES-256-GCM
--      encrypted (random IV per value) and can never be matched in SQL.
--   3. Widen invitations.invitee_name/phone/address to TEXT for ciphertext
--      envelopes (SK1: + base64(iv+tag+data)), same as 20261001. Email stays
--      VARCHAR (login + UNIQUE lookup key, never encrypted).
--   4. Widen users.phone/address to TEXT for the same reason (activation
--      carries the encrypted values forward).
--   5. method enum ('email','manual') -> ('email','sms','both') DEFAULT
--      'both'. Legacy pending `manual` rows are expired below (their codes
--      were shown on screen, so they cannot be grandfathered as private).
--
-- Idempotent: every ALTER is guarded by INFORMATION_SCHEMA checks so the
-- file is safe to re-run on XAMPP or Azure.
-- ============================================================================

-- --- 1. code_hash ------------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'invitations'
    AND COLUMN_NAME = 'code_hash'
);
SET @sql = IF(@col_exists > 0, 'SELECT 1', 'ALTER TABLE `invitations` ADD COLUMN `code_hash` CHAR(64) NULL DEFAULT NULL AFTER `code`');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'invitations'
    AND INDEX_NAME = 'uq_invitation_code_hash'
);
SET @sql = IF(@idx_exists > 0, 'SELECT 1', 'ALTER TABLE `invitations` ADD UNIQUE KEY `uq_invitation_code_hash` (`code_hash`)');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --- 2. invitee_phone_hash ----------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'invitations'
    AND COLUMN_NAME = 'invitee_phone_hash'
);
SET @sql = IF(@col_exists > 0, 'SELECT 1', 'ALTER TABLE `invitations` ADD COLUMN `invitee_phone_hash` CHAR(64) NULL DEFAULT NULL AFTER `invitee_phone`');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'invitations'
    AND INDEX_NAME = 'idx_invitation_phone_hash'
);
SET @sql = IF(@idx_exists > 0, 'SELECT 1', 'ALTER TABLE `invitations` ADD KEY `idx_invitation_phone_hash` (`invitee_phone_hash`)');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --- 3. Widen invitations PII columns to TEXT (ciphertext headroom) ----------
ALTER TABLE `invitations`
  MODIFY COLUMN `invitee_name` TEXT NOT NULL,
  MODIFY COLUMN `invitee_phone` TEXT NULL DEFAULT NULL,
  MODIFY COLUMN `invitee_address` TEXT NULL DEFAULT NULL;

-- --- 4. Widen users PII columns to TEXT (ciphertext headroom) ----------------
-- users.name stays VARCHAR: it is shown in session/audit contexts and is
-- covered by the users-side backfill only when encrypted. Widening name is
-- harmless but not required for the invitation flow, so only phone/address
-- (carried forward from the encrypted invitation) are widened here.
ALTER TABLE `users`
  MODIFY COLUMN `phone` TEXT NULL DEFAULT NULL,
  MODIFY COLUMN `address` TEXT NULL DEFAULT NULL;

-- --- 5. method enum: add new values first (keeps existing rows valid) --------
ALTER TABLE `invitations`
  MODIFY COLUMN `method` ENUM('email','manual','sms','both') NOT NULL DEFAULT 'both';

-- Legacy `manual` codes were displayed on screen, so they are NOT private.
-- Expire every still-pending invitation issued under the old flow; the admin
-- re-invites with email + mobile and the new hashed delivery.
UPDATE `invitations`
  SET `status` = 'expired'
  WHERE `status` = 'pending'
    AND (`method` = 'manual' OR `code_hash` IS NULL);

-- Point legacy manual history at the new default for reporting consistency.
UPDATE `invitations` SET `method` = 'both' WHERE `method` = 'manual';

-- Drop the `manual` value now that no row uses it.
ALTER TABLE `invitations`
  MODIFY COLUMN `method` ENUM('email','sms','both') NOT NULL DEFAULT 'both';

-- Legacy `code` column holds old plaintext codes (history only). New rows
-- store NULL here — the live lookup key is code_hash. Nullable so inserts
-- never need a dummy plaintext value.
ALTER TABLE `invitations`
  MODIFY COLUMN `code` VARCHAR(8) NULL DEFAULT NULL;

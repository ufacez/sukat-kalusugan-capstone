-- 20261001_pii_encryption_columns.sql
-- AES-256-GCM readiness: ciphertext envelopes (SK1: + base64(iv+tag+data))
-- are longer than the plaintext they replace, so PII columns must hold ~400+
-- chars. No indexes exist on these columns (only child_code is UNIQUE), so
-- widening to TEXT is constraint-safe. Emails, codes, birthdate, and hashes
-- are intentionally untouched (search keys / SQL date math / bcrypt).
-- Idempotent: safe to re-run on XAMPP or Azure.

ALTER TABLE `parents`
  MODIFY COLUMN `name` TEXT NOT NULL,
  MODIFY COLUMN `phone` TEXT NULL DEFAULT NULL,
  MODIFY COLUMN `address` TEXT NULL DEFAULT NULL;

ALTER TABLE `children`
  MODIFY COLUMN `first_name` TEXT NOT NULL,
  MODIFY COLUMN `middle_name` TEXT NULL DEFAULT NULL,
  MODIFY COLUMN `last_name` TEXT NOT NULL;

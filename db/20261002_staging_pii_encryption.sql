-- ============================================================================
-- 20261002_staging_pii_encryption.sql
--
-- Extends AES-256-GCM at-rest PII coverage to the master-list import
-- staging table (public_html/nutritionist/family_import.php).
--
-- Before this, a committed import wrote parents.name and
-- children.first/middle/last_name encrypted, but every "kailangang
-- kumpletuhin" row landed in import_staging_rows with full names in
-- CLEARTEXT -- the same PII, one table over, unprotected.
--
-- Why TEXT and not varchar: a ciphertext envelope is
--   'SK1:' + base64(iv[12] || tag[16] || ciphertext)
-- so a 150-char raw "Surname, First Middle" becomes ~244 chars. Under
-- the old varchar(150)/varchar(100)/varchar(60) widths the envelope is
-- TRUNCATED, and GCM authentication then fails on read (sk_decrypt_value
-- returns the ciphertext as-is). Matches the 20261001_pii_encryption_columns
-- approach for parents.name / children.*_name.
--
-- sex_raw (varchar 20), dob_raw (varchar 40) and reason (varchar 255) stay
-- narrow on purpose: they are not PII in sk_pii_columns(), so they are
-- never enveloped and never need ciphertext headroom.
--
-- Idempotent: MODIFY COLUMN is safe to re-run on XAMPP or Azure.
-- ============================================================================

ALTER TABLE `import_staging_rows`
  MODIFY COLUMN `mother_raw` TEXT NULL DEFAULT NULL,
  MODIFY COLUMN `child_raw` TEXT NULL DEFAULT NULL,
  MODIFY COLUMN `mother_first` TEXT NULL DEFAULT NULL,
  MODIFY COLUMN `mother_middle` TEXT NULL DEFAULT NULL,
  MODIFY COLUMN `mother_last` TEXT NULL DEFAULT NULL,
  MODIFY COLUMN `child_first` TEXT NULL DEFAULT NULL,
  MODIFY COLUMN `child_middle` TEXT NULL DEFAULT NULL,
  MODIFY COLUMN `child_last` TEXT NULL DEFAULT NULL;
<?php

declare(strict_types=1);

/**
 * crypto.php — AES-256-GCM application-layer encryption for PII at rest.
 *
 * Scope (deliberate, do not widen without a search redesign):
 *   ENCRYPTED: parents.name/phone/address, children.first_name/middle_name/last_name
 *   PLAINTEXT (must stay): emails (login + UNIQUE lookups), child_code/device_code
 *     (kiosk lookup keys), birthdate (DATE used by SQL TIMESTAMPDIFF age math),
 *     password_hash (bcrypt one-way hash — NEVER reversible encryption).
 *
 * Why GCM with a random IV per value: same plaintext encrypts differently
 * every time, so a DB dump leaks nothing via frequency analysis, and
 * tampered ciphertext fails authentication instead of decrypting to garbage.
 *
 * Backward compatibility: sk_decrypt_value() returns non-enveloped input
 * unchanged, so plaintext rows (pre-migration, or written while the key is
 * unset) keep working. sk_encrypt_value() is a passthrough while no valid
 * key is configured, so XAMPP/Azure without APP_ENCRYPTION_KEY behave
 * exactly as before — encryption is opt-in, never a breakage vector.
 *
 * Envelope: 'SK1:' + base64(iv[12] || tag[16] || ciphertext).
 */

define('SK_CRYPTO_PREFIX', 'SK1:');
define('SK_CRYPTO_IV_BYTES', 12);
define('SK_CRYPTO_TAG_BYTES', 16);

/**
 * Raw 32-byte key, or null when encryption is not configured/available.
 * Reads the APP_ENCRYPTION_KEY constant (config.php) with getenv fallback
 * so CLI tools work even when constants are not defined yet.
 */
function sk_encryption_key(): ?string
{
    static $cached = false;
    static $key = null;

    if ($cached) {
        return $key;
    }
    $cached = true;

    if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
        error_log('[SukatKalusugan] crypto: openssl extension missing — PII encryption disabled.');
        return null;
    }

    $raw = defined('APP_ENCRYPTION_KEY') ? (string)APP_ENCRYPTION_KEY : '';
    if ($raw === '') {
        $fromEnv = getenv('APP_ENCRYPTION_KEY');
        $raw = is_string($fromEnv) ? trim($fromEnv) : '';
    }
    if ($raw === '') {
        return null; // Not configured — passthrough mode, nothing breaks.
    }

    $decoded = base64_decode($raw, true);
    if (!is_string($decoded) || strlen($decoded) !== 32) {
        error_log('[SukatKalusugan] crypto: APP_ENCRYPTION_KEY must be base64 of 32 random bytes. Generate: php -r "echo base64_encode(random_bytes(32)), PHP_EOL;" — PII encryption disabled.');
        return null;
    }

    $key = $decoded;
    return $key;
}

/** True only when a valid key exists and writes should be encrypted. */
function sk_pii_encryption_enabled(): bool
{
    return sk_encryption_key() !== null;
}

/** True when the value already carries our envelope (never double-encrypt). */
function sk_is_encrypted(?string $value): bool
{
    return is_string($value) && str_starts_with($value, SK_CRYPTO_PREFIX);
}

/**
 * Encrypt a plaintext PII value. Passthrough when disabled, empty, or
 * already encrypted — so callers can wrap writes unconditionally.
 */
function sk_encrypt_value(?string $plaintext): ?string
{
    if ($plaintext === null || $plaintext === '') {
        return $plaintext;
    }
    if (sk_is_encrypted($plaintext)) {
        return $plaintext;
    }
    $key = sk_encryption_key();
    if ($key === null) {
        return $plaintext;
    }

    try {
        $iv = random_bytes(SK_CRYPTO_IV_BYTES);
    } catch (Throwable $e) {
        error_log('[SukatKalusugan] crypto: random_bytes failed — storing plaintext. ' . $e->getMessage());
        return $plaintext;
    }

    $tag = '';
    $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if (!is_string($cipher) || $tag === '') {
        error_log('[SukatKalusugan] crypto: openssl_encrypt failed — storing plaintext.');
        return $plaintext;
    }

    return SK_CRYPTO_PREFIX . base64_encode($iv . $tag . $cipher);
}

/**
 * Decrypt an enveloped value. Returns input unchanged when it is not
 * enveloped (plaintext row), when no key is configured, or when auth
 * fails (tamper/wrong key — logged, never fatal).
 */
function sk_decrypt_value(?string $value): ?string
{
    if ($value === null || $value === '' || !sk_is_encrypted($value)) {
        return $value;
    }
    $key = sk_encryption_key();
    if ($key === null) {
        return $value; // Key not configured here — leave ciphertext for the keyed host.
    }

    $blob = base64_decode(substr($value, strlen(SK_CRYPTO_PREFIX)), true);
    if (!is_string($blob) || strlen($blob) <= SK_CRYPTO_IV_BYTES + SK_CRYPTO_TAG_BYTES) {
        error_log('[SukatKalusugan] crypto: malformed envelope — returning ciphertext as-is.');
        return $value;
    }

    $iv = substr($blob, 0, SK_CRYPTO_IV_BYTES);
    $tag = substr($blob, SK_CRYPTO_IV_BYTES, SK_CRYPTO_TAG_BYTES);
    $cipher = substr($blob, SK_CRYPTO_IV_BYTES + SK_CRYPTO_TAG_BYTES);

    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if (!is_string($plain)) {
        error_log('[SukatKalusugan] crypto: authentication failed (wrong key or tampered row) — returning ciphertext as-is.');
        return $value;
    }

    return $plain;
}

/**
 * PII columns AND output aliases covered by at-rest encryption. Aliases must
 * be listed because the central fetch decryptors match result keys, not
 * source columns: p.name AS parent_name, p.phone AS parent_phone,
 * p.address AS parent_address, COALESCE(...) AS actor_name (audit logs),
 * c.first_name/c.last_name AS child_first/last_name (kiosk/ESP32 payloads).
 * Non-PII lookalikes (barangay_name, area-derived address) are never
 * encrypted; listing `address` is harmless passthrough for those.
 */
function sk_pii_columns(): array
{
    return ['name', 'parent_name', 'phone', 'parent_phone', 'address', 'parent_address', 'actor_name', 'first_name', 'middle_name', 'last_name', 'child_first_name', 'child_last_name'];
}

/** Decrypt every PII column present in a fetched DB row (in place). */
function sk_decrypt_pii_row(array &$row): void
{
    foreach (sk_pii_columns() as $col) {
        if (array_key_exists($col, $row) && is_string($row[$col]) && sk_is_encrypted($row[$col])) {
            $row[$col] = sk_decrypt_value($row[$col]);
        }
    }
}

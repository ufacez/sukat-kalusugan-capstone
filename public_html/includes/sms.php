<?php

declare(strict_types=1);

/**
 * sms.php — UniSMS delivery for staff invitation codes.
 *
 * SMTP (mailer.php) is email-only and cannot send SMS, so invitation codes
 * go out over BOTH channels: SMTP email + UniSMS SMS. Activation then
 * requires the code and a new password.
 *
 * Design notes (match firebase_sync.php mirror philosophy):
 *   - best-effort: returns bool, never throws; callers log and continue.
 *   - short timeouts so a slow SMS API never hangs the invite form.
 *   - the plaintext code is passed in, sent, and forgotten — it is never
 *     written to the DB, logs, or the web UI (only code_hash is stored).
 *   - EXCEPTION: with SMS_DRY_RUN enabled (local testing only) send_sms()
 *     writes the message — including the test code — to the error log so
 *     activation can be tested without spending SMS credits. Never enable
 *     SMS_DRY_RUN on live.
 *   - messages are kept to one GSM-7 segment (<=160 chars) to save credits.
 */

require_once __DIR__ . '/config.php';

/** True only when a UniSMS secret key is configured and cURL is available. */
function sms_is_configured(): bool
{
    if (!function_exists('curl_init')) {
        return false;
    }
    $key = defined('UNISMS_SECRET_KEY') ? trim((string)UNISMS_SECRET_KEY) : '';
    return $key !== '';
}

/**
 * True when SMS_DRY_RUN is enabled. In dry-run mode send_sms() logs the
 * message and reports success without calling UniSMS, so invite/activation
 * flows can be tested locally without spending credits.
 */
function sms_is_dry_run(): bool
{
    if (!defined('SMS_DRY_RUN')) {
        return false;
    }
    return in_array(strtolower(trim((string)SMS_DRY_RUN)), ['1', 'true', 'yes', 'on'], true);
}

/**
 * Send a plain-text SMS via UniSMS (https://unismsapi.com).
 *
 * @param string $toNormalized Canonical 09XXXXXXXXX number (normalized by the
 *   shared PH mobile helper). The +63 international form is derived.
 * @param string $message      Plain-text body (GSM-7 safe recommended).
 * @return bool True when UniSMS accepted the message.
 */
function send_sms(string $toNormalized, string $message): bool
{
    if (sms_is_dry_run()) {
        $masked = trim($toNormalized) !== ''
            ? substr(trim($toNormalized), 0, 4) . '***' . substr(trim($toNormalized), -2)
            : '(unknown)';
        error_log('[sms] DRY-RUN — SMS NOT sent to ' . $masked . ' (message/test code logged for local testing): ' . $message);
        return true;
    }

    $secretKey = defined('UNISMS_SECRET_KEY') ? trim((string)UNISMS_SECRET_KEY) : '';
    $sender = defined('UNISMS_SENDER_ID') && trim((string)UNISMS_SENDER_ID) !== ''
        ? trim((string)UNISMS_SENDER_ID)
        : 'Unisoft';

    if ($secretKey === '') {
        error_log('[sms] UNISMS_SECRET_KEY not configured — SMS skipped.');
        return false;
    }
    if (!function_exists('curl_init')) {
        error_log('[sms] cURL extension missing — SMS skipped.');
        return false;
    }

    $to = trim($toNormalized);
    if ($to === '' || $message === '') {
        return false;
    }
    // UniSMS accepts +63... or 09...; normalize to +63...
    if (str_starts_with($to, '09') && strlen($to) === 11) {
        $to = '+63' . substr($to, 1);
    } elseif (str_starts_with($to, '639') && strlen($to) === 12) {
        $to = '+' . $to;
    } elseif (!str_starts_with($to, '+63')) {
        error_log('[sms] Invalid recipient format — SMS skipped.');
        return false;
    }

    // UniSMS sender IDs are limited to 11 characters.
    if (strlen($sender) > 11) {
        error_log('[sms] UNISMS_SENDER_ID exceeds 11 characters — SMS skipped.');
        return false;
    }

    $payload = json_encode([
        'recipient' => $to,
        'content' => $message,
        'sender_id' => $sender,
    ], JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        error_log('[sms] Unable to encode UniSMS payload.');
        return false;
    }

    $ch = curl_init('https://unismsapi.com/api/sms');
    if ($ch === false) {
        error_log('[sms] curl_init failed.');
        return false;
    }
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode($secretKey . ':'),
        'Content-Type: application/json',
        'Accept: application/json',
    ]);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($errno !== 0) {
        error_log('[sms] cURL error ' . $errno . ': ' . (string)curl_error($ch));
        return false;
    }

    $decoded = json_decode((string)$raw, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        $detail = is_array($decoded) && isset($decoded['message'])
            ? (is_array($decoded['message'])
                ? (string)($decoded['message']['fail_reason'] ?? $decoded['message']['status'] ?? '')
                : (string)$decoded['message'])
            : substr((string)$raw, 0, 300);
        error_log('[sms] UniSMS HTTP ' . $httpCode . ': ' . $detail);
        return false;
    }

    if (!is_array($decoded) || !isset($decoded['message']) || !is_array($decoded['message'])) {
        $detail = is_array($decoded) && isset($decoded['message'])
            ? (string)$decoded['message']
            : substr((string)$raw, 0, 300);
        error_log('[sms] UniSMS rejected message: ' . $detail);
        return false;
    }

    $status = strtolower((string)($decoded['message']['status'] ?? ''));
    if ($status === 'failed' || $status === 'error') {
        $detail = (string)($decoded['message']['fail_reason'] ?? $status);
        error_log('[sms] UniSMS rejected message: ' . $detail);
        return false;
    }

    $referenceId = (string)($decoded['message']['reference_id'] ?? '');
    if ($referenceId !== '') {
        error_log('[sms] UniSMS accepted message ' . $referenceId . '.');
    }

    return true;
}

/**
 * Send a staff invitation code over SMS.
 *
 * The message carries the code to the invitee's own phone — that IS the
 * private delivery channel (vs. showing it in the admin web UI). Never
 * include the code in logs or notices; callers must only report the
 * masked number on success/failure.
 */
function send_invite_sms(string $toNormalized, string $plainCode): bool
{
    $masked = $toNormalized !== '' ? substr($toNormalized, 0, 4) . '***' . substr($toNormalized, -2) : '(unknown)';
    // Keep this within 160 GSM-7 characters so it stays one SMS credit.
    // UniSMS requires the brand, OTP code, purpose, and expiration in the body.
    $message = sprintf(
        'Sukat Kalusugan OTP code is %s for staff activation. Expires in 48 hours. Do not share.',
        $plainCode
    );
    $ok = send_sms($toNormalized, $message);
    if (!$ok) {
        error_log('[sms] Invitation SMS failed for ' . $masked . '.');
    }
    return $ok;
}

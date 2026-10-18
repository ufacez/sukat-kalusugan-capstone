<?php

declare(strict_types=1);

/**
 * sms.php — Semaphore SMS delivery for staff invitation codes.
 *
 * SMTP (mailer.php) is email-only and cannot send SMS, so invitation codes
 * go out over BOTH channels: SMTP email + Semaphore SMS. Activation then
 * requires the code AND the matching mobile number.
 *
 * Design notes (match firebase_sync.php mirror philosophy):
 *   - best-effort: returns bool, never throws; callers log and continue.
 *   - short timeouts so a slow SMS API never hangs the invite form.
 *   - the plaintext code is passed in, sent, and forgotten — it is never
 *     written to the DB, logs, or the web UI (only code_hash is stored).
 */

require_once __DIR__ . '/config.php';

/** True only when a Semaphore key is configured and cURL is available. */
function sms_is_configured(): bool
{
    if (!function_exists('curl_init')) {
        return false;
    }
    $key = defined('SEMAPHORE_API_KEY') ? trim((string)SEMAPHORE_API_KEY) : '';
    return $key !== '';
}

/**
 * Send a plain-text SMS via Semaphore (https://api.semaphore.co).
 *
 * @param string $toNormalized Canonical 09XXXXXXXXX number (see
 *   admin_normalize_ph_mobile()). The +63 international form is derived here.
 * @param string $message      Plain-text body (GSM-7 safe recommended).
 * @return bool True when Semaphore accepted the message.
 */
function send_sms(string $toNormalized, string $message): bool
{
    $apiKey = defined('SEMAPHORE_API_KEY') ? trim((string)SEMAPHORE_API_KEY) : '';
    $sender = defined('SEMAPHORE_SENDER_NAME') && trim((string)SEMAPHORE_SENDER_NAME) !== ''
        ? trim((string)SEMAPHORE_SENDER_NAME)
        : 'SukatKalusugan';

    if ($apiKey === '') {
        error_log('[sms] SEMAPHORE_API_KEY not configured — SMS skipped.');
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
    // Semaphore accepts the international form; convert 09.. -> +639...
    if (str_starts_with($to, '09') && strlen($to) === 11) {
        $to = '+63' . substr($to, 1);
    }

    $payload = [
        'apikey' => $apiKey,
        'number' => $to,
        'message' => $message,
        'sendername' => $sender,
    ];

    $ch = curl_init('https://api.semaphore.co/api/v4/messages');
    if ($ch === false) {
        error_log('[sms] curl_init failed.');
        return false;
    }
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($errno !== 0) {
        error_log('[sms] cURL error ' . $errno . ': ' . (string)curl_error($ch));
        curl_close($ch);
        return false;
    }
    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300) {
        error_log('[sms] Semaphore HTTP ' . $httpCode . ': ' . substr((string)$raw, 0, 300));
        return false;
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
function send_invite_sms(string $toNormalized, string $plainCode, string $role): bool
{
    $masked = $toNormalized !== '' ? substr($toNormalized, 0, 4) . '***' . substr($toNormalized, -2) : '(unknown)';
    $message = sprintf(
        'Sukat Kalusugan: your staff activation code is %s (role: %s). '
        . 'Enter it with this mobile number at Activate Account. '
        . 'Expires in 48 hours. Do not share.',
        $plainCode,
        ucfirst($role)
    );
    $ok = send_sms($toNormalized, $message);
    if (!$ok) {
        error_log('[sms] Invitation SMS failed for ' . $masked . '.');
    }
    return $ok;
}

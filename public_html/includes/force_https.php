<?php
declare(strict_types=1);
/**
 * force_https.php
 * Proxy-aware HTTPS detection + http->https enforcement for Azure VM live app.
 *
 * Why this file exists:
 * - Browsers show "Not secure" when the app is served over plain HTTP, even
 *   when the TLS certificate itself is valid for https://. The repo previously
 *   had no HTTPS enforcement (public_html/.htaccess is intentionally a no-op
 *   locally, and FORCE_HTTPS was documented in .env.example but never read).
 * - Azure sits behind a proxy/LB where $_SERVER['HTTPS'] is unreliable, so
 *   detection must honor X-Forwarded-Proto (and Azure's X-ARR-SSL header).
 * - The ESP32 firmware only speaks plain HTTP (see
 *   docs/firmware/esp32_kios_arduino_code.ino), so /api/esp32/* is ALWAYS
 *   exempt from redirects and HSTS.
 *
 * Loaded from config.php (which is loaded via db.php on every request), so
 * this runs before any output. Safe on CLI (no-op).
 */

/**
 * True when the current request reached us over TLS, either directly or via
 * a trusted Azure proxy/LB that sets X-Forwarded-Proto: https.
 */
function is_https_request(): bool
{
    // Azure App Service / Application Gateway / Nginx proxy convention.
    // May contain a comma-separated chain ("https,http"); the leftmost entry
    // is the client-facing protocol.
    $forwardedProto = (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    if ($forwardedProto !== '') {
        $first = strtolower(trim(explode(',', $forwardedProto)[0]));
        if ($first === 'https') {
            return true;
        }
        if ($first === 'http') {
            // Explicit http from proxy wins over local fallbacks below.
            return false;
        }
    }

    // Azure App Service sets X-ARR-SSL when the client used HTTPS.
    if (!empty($_SERVER['HTTP_X_ARR_SSL'])) {
        return true;
    }

    // Direct TLS (Apache/Nginx without proxy).
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }

    // Some stacks set REQUEST_SCHEME.
    if (isset($_SERVER['REQUEST_SCHEME']) && strtolower((string)$_SERVER['REQUEST_SCHEME']) === 'https') {
        return true;
    }

    return false;
}

/**
 * Whether http->https redirects are active for this request.
 *
 * FORCE_HTTPS=1 forces on everywhere; FORCE_HTTPS=0 disables everywhere.
 * Empty/unset = auto: ON when APP_ENV=production, OFF otherwise (so local
 * XAMPP over http://localhost keeps working without a cert).
 */
function force_https_enabled(): bool
{
    $raw = strtolower(trim((string)(defined('FORCE_HTTPS') ? (string)FORCE_HTTPS : '')));

    if (in_array($raw, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }

    if (in_array($raw, ['0', 'false', 'no', 'off', 'disabled'], true)) {
        return false;
    }

    return defined('APP_ENV') && APP_ENV === 'production';
}

/**
 * ESP32 hardware only speaks plain HTTP. Never redirect these endpoints or
 * the device stops reporting measurements.
 */
function https_redirect_exempt(): bool
{
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    if ($uri === '') {
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        if ($script !== '' && str_contains($script, '/api/esp32/')) {
            return true;
        }
        return false;
    }

    $path = (string)parse_url($uri, PHP_URL_PATH);
    if ($path === '') {
        $path = $uri;
    }

    return str_contains($path, '/api/esp32/');
}

/**
 * 301/308 redirect plain-HTTP browser traffic to the https:// equivalent.
 * No-op on CLI, when already HTTPS, when disabled, or for ESP32 paths.
 */
function maybe_enforce_https(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    if (!force_https_enabled()) {
        return;
    }

    if (is_https_request()) {
        return;
    }

    if (https_redirect_exempt()) {
        return;
    }

    if (headers_sent()) {
        return;
    }

    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        $host = trim((string)($_SERVER['SERVER_NAME'] ?? ''));
    }
    if ($host === '') {
        // Cannot build a safe redirect target without a host; let the
        // request through on HTTP rather than redirecting somewhere wrong.
        return;
    }

    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    if ($requestUri === '') {
        $requestUri = '/';
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    // 301 is fine for navigations; use 308 elsewhere so POST/PUT bodies are
    // preserved instead of being rewritten to GET by the browser.
    $statusCode = ($method === 'GET' || $method === 'HEAD') ? 301 : 308;

    http_response_code($statusCode);
    header('Location: https://' . $host . $requestUri, true, $statusCode);
    exit;
}

/**
 * Security headers. HSTS is only sent over HTTPS (sending it over HTTP is
 * ignored by browsers and would be misleading). Other hardening headers are
 * safe on both schemes and skipped for ESP32 polling responses to keep the
 * constrained firmware path minimal.
 */
function send_security_headers(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    if (headers_sent()) {
        return;
    }

    if (https_redirect_exempt()) {
        return;
    }

    if (is_https_request()) {
        // No "preload" yet: enable it only after confirming the whole site
        // (including subdomains) is HTTPS-clean for a full renewal cycle.
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains', false);
    }

    header('X-Content-Type-Options: nosniff', false);
    header('Referrer-Policy: strict-origin-when-cross-origin', false);
    header('X-Frame-Options: SAMEORIGIN', false);
    // Last-resort mixed-content guard: if any http:// asset URL slips through
    // (email template, old DB content), the browser upgrades it to https://
    // instead of dropping the padlock.
    header("Content-Security-Policy: upgrade-insecure-requests", false);
}

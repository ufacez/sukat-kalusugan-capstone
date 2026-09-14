<?php
declare(strict_types=1);

/**
 * request_security.php
 *
 * HTTPS enforcement + proxy-aware TLS detection, loaded from config.php
 * (which runs before any output on every page/API via db.php).
 *
 * Why this file exists:
 * - Live runs on Azure behind a proxy, so $_SERVER['HTTPS'] is unreliable.
 *   request_is_https() honors X-Forwarded-Proto first (same approach as
 *   app_absolute_url() in auth_middleware.php).
 * - The ESP32 talks plain HTTP to /api/esp32/* and must NEVER be
 *   redirected — a 301 there would break the 2s heartbeat and measurement
 *   submit. Enforcement always exempts that path.
 * - Local XAMPP stays untouched: enforcement is off unless APP_ENV is
 *   'production' (or FORCE_HTTPS=1 is set), and loopback/LAN hosts are
 *   never redirected even if misconfigured.
 */

// ── TLS detection (proxy-aware) ──────────────────────────────────────────────

if (!function_exists('request_is_https')) {
    function request_is_https(): bool
    {
        $forwardedProto = (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
        if ($forwardedProto !== '') {
            $parts = explode(',', $forwardedProto);
            $proto = strtolower(trim($parts[0]));
            if ($proto === 'http' || $proto === 'https') {
                return $proto === 'https';
            }
        }

        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    }
}

if (!function_exists('request_host_is_local')) {
    function request_host_is_local(string $host): bool
    {
        // Strip any :port suffix (careful with IPv6 literals in brackets).
        if (strpos($host, '[') === 0) {
            $bracketEnd = strpos($host, ']');
            $host = $bracketEnd === false ? $host : substr($host, 0, $bracketEnd + 1);
        } elseif (substr_count($host, ':') === 1) {
            $host = strstr($host, ':', true);
        }

        $host = strtolower(trim($host, " \t\n\r\0\x0B[]"));

        if ($host === '' || $host === 'localhost' || $host === '::1' || $host === '127.0.0.1') {
            return true;
        }

        foreach (['.local', '.test', '.invalid', '.example'] as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                return true;
            }
        }

        if (strpos($host, '192.168.') === 0 || strpos($host, '10.') === 0) {
            return true;
        }

        // 172.16.0.0/12 private range.
        if (strpos($host, '172.') === 0) {
            $octets = explode('.', $host);
            if (isset($octets[1]) && ctype_digit($octets[1])) {
                $second = (int)$octets[1];
                if ($second >= 16 && $second <= 31) {
                    return true;
                }
            }
        }

        return false;
    }
}

if (!function_exists('https_enforcement_enabled')) {
    function https_enforcement_enabled(): bool
    {
        $forced = strtolower(trim((string)(defined('FORCE_HTTPS') ? FORCE_HTTPS : '')));

        if ($forced === '1' || $forced === 'true' || $forced === 'on') {
            return true;
        }

        if ($forced === '0' || $forced === 'false' || $forced === 'off') {
            return false;
        }

        return defined('APP_ENV') && APP_ENV === 'production';
    }
}

if (!function_exists('is_esp32_device_endpoint')) {
    function is_esp32_device_endpoint(): bool
    {
        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));

        return strpos($scriptName, '/api/esp32/') !== false;
    }
}

// ── Enforcement ──────────────────────────────────────────────────────────────

if (!function_exists('enforce_https')) {
    function enforce_https(): void
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return;
        }

        if (headers_sent() || !https_enforcement_enabled() || request_is_https()) {
            return;
        }

        // The ESP32 firmware uses plain HTTP for its heartbeat/submit
        // endpoints — redirecting them would break the kiosk hardware.
        if (is_esp32_device_endpoint()) {
            return;
        }

        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '' || request_host_is_local($host)) {
            return;
        }

        // Only safe methods get a 301: redirecting a POST would silently
        // drop the request body (measurement submits, logins, etc.).
        // Unsafe methods continue over HTTP this once; the HSTS header
        // served on HTTPS responses upgrades subsequent visits.
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return;
        }

        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        if ($uri === '' || $uri[0] !== '/') {
            $uri = '/' . $uri;
        }

        header('Location: https://' . $host . $uri, true, 301);
        exit;
    }
}

if (!function_exists('send_strict_transport_security')) {
    function send_strict_transport_security(): void
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return;
        }

        if (headers_sent() || !request_is_https() || !https_enforcement_enabled()) {
            return;
        }

        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($host !== '' && request_host_is_local($host)) {
            return;
        }

        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// Runs on include (from config.php, before any output).
enforce_https();
send_strict_transport_security();

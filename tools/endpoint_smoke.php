<?php

declare(strict_types=1);

/**
 * tools/endpoint_smoke.php
 *
 * Live endpoint smoke test. Proves every api/ file executes without fatals.
 *
 * SAFETY MODEL (read the whole comment before running):
 * - Every endpoint is probed with a plain GET — no writes are triggered.
 *   A file that runs cleanly answers 401/403/404/405/422 with a JSON
 *   envelope. Only HTTP 500 or a non-JSON body counts as FAIL.
 * - Mutating endpoints are NEVER auto-fired. With --templates, the tool
 *   instead emits ready-to-paste curl commands for deliberate manual runs.
 * - Login attempts are rate-limited server-side: use CORRECT credentials.
 *   A wrong password 5x locks the account. Prefer dedicated test accounts.
 * - Order: local XAMPP first, staging next, Azure prod last (safe subset —
 *   this tool only ever sends GETs, so the subset question answers itself).
 *
 * Usage:
 *   php tools/endpoint_smoke.php --base=http://localhost/sukat-kalusugan-capstone/public_html [--user=.. --pass=..] [--device-key=..] [--templates]
 *
 * Exit 1 when any endpoint FAILs.
 */

$opt = ['base' => null, 'user' => null, 'pass' => null, 'device-key' => null, 'templates' => false];
foreach ($argv as $arg) {
    foreach (['base', 'user', 'pass', 'device-key'] as $key) {
        if (str_starts_with($arg, '--' . $key . '=')) {
            $opt[$key] = substr($arg, strlen($key) + 3);
        }
    }
    if ($arg === '--templates') {
        $opt['templates'] = true;
    }
}

if ($opt['base'] === null) {
    fwrite(STDERR, "Missing --base (e.g. --base=http://localhost/sukat-kalusugan-capstone/public_html)\n");
    exit(2);
}
$base = rtrim($opt['base'], '/');

$root = getcwd();
$apiDir = $root . '/public_html/api';
$endpoints = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($apiDir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (str_ends_with($file->getFilename(), '.php')) {
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen(str_replace('\\', '/', $apiDir))));
        $endpoints[] = ltrim($rel, '/');
    }
}
sort($endpoints);

function http_get(string $url, ?string $cookieJar, ?string $deviceKey, int $timeout = 20): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($deviceKey !== null && $deviceKey !== '') {
        $headers[] = 'X-Device-Key: ' . $deviceKey;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($cookieJar !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        return ['code' => 0, 'body' => '', 'curl_error' => $error !== '' ? $error : ('cURL error ' . $errno)];
    }
    $parts = preg_split("/\r\n\r\n/", (string)$raw);
    return ['code' => $code, 'body' => trim((string)end($parts)), 'curl_error' => null];
}

function verdict(array $res): array
{
    if ($res['curl_error'] !== null) {
        return ['FAIL', 'transport: ' . $res['curl_error']];
    }
    if ($res['code'] === 500) {
        return ['FAIL', 'HTTP 500 — see server error log'];
    }
    if ($res['code'] === 0) {
        return ['FAIL', 'no HTTP response'];
    }
    $decoded = json_decode($res['body'], true);
    // Two envelope shapes exist: action endpoints use {success,...}, while
    // session_check.php answers the session probe {authenticated,user,...}.
    if (!is_array($decoded) || (!array_key_exists('success', $decoded) && !array_key_exists('authenticated', $decoded))) {
        $preview = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($res['body']))), 0, 120);
        return ['FAIL', 'non-JSON envelope (HTTP ' . $res['code'] . '): ' . $preview];
    }
    return ['PASS', 'HTTP ' . $res['code'] . ' + JSON envelope'];
}

// --- Optional login (cookie jar) --------------------------------------
$cookieJar = null;
if ($opt['user'] !== null) {
    if ($opt['pass'] === null) {
        fwrite(STDERR, "Missing --pass for --user.\n");
        exit(2);
    }
    $cookieJar = tempnam(sys_get_temp_dir(), 'smoke_jar_');
    $ch = curl_init($base . '/api/auth/login.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'identifier' => $opt['user'],
            'password' => $opt['pass'],
        ]),
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Requested-With: XMLHttpRequest'],
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $parts = preg_split("/\r\n\r\n/", (string)$raw);
    $decoded = json_decode(trim((string)end($parts)), true);
    if ($code !== 200 || !is_array($decoded) || ($decoded['success'] ?? false) !== true) {
        fwrite(STDERR, 'Login failed (HTTP ' . $code . '). Check credentials — repeated failures lock the account.' . "\n");
        if (is_string($cookieJar)) {
            @unlink($cookieJar);
        }
        exit(2);
    }
    echo 'Logged in as ' . $opt['user'] . " (session jar ready).\n\n";
} else {
    echo "No --user given: probing unauthenticated (expect mostly 401s — still proves no fatals).\n\n";
}

// --- Probe every endpoint ----------------------------------------------
$pass = 0;
$fail = 0;
$failures = [];
foreach ($endpoints as $rel) {
    $url = $base . '/api/' . $rel;
    $t0 = microtime(true);
    $res = http_get($url, $cookieJar, $opt['device-key']);
    $ms = (int)((microtime(true) - $t0) * 1000);
    [$verdict, $note] = verdict($res);
    if ($verdict === 'PASS') {
        $pass++;
        echo str_pad('PASS', 6) . str_pad($ms . 'ms', 8) . $rel . "\n";
    } else {
        $fail++;
        $failures[] = [$rel, $note];
        echo str_pad('FAIL', 6) . str_pad($ms . 'ms', 8) . $rel . '  <-- ' . $note . "\n";
    }
}

if (is_string($cookieJar)) {
    @unlink($cookieJar);
}

// --- Mutating templates (manual runs only) ------------------------------
if ($opt['templates']) {
    echo "\n=== MUTATING-ENDPOINT CURL TEMPLATES (run deliberately, one at a time) ===\n";
    $mutating = array_values(array_filter($endpoints, static function (string $rel): bool {
        return (bool)preg_match('/create|update|delete|archive|restore|import|cancel|submit|chat|conversation|reset|forgot|activate|ping|scan|register|request_process|interpret|login|logout|session/i', $rel);
    }));
    foreach ($mutating as $rel) {
        echo '# ' . $rel . "\n";
        echo 'curl -s -b COOKIEJAR -c COOKIEJAR -X POST ' . escapeshellarg($base . '/api/' . $rel)
            . " -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{\"TODO\":\"fill payload\"}' | head -c 500; echo\n\n";
    }
}

echo "\nProbed " . count($endpoints) . ' endpoints: ' . $pass . ' PASS, ' . $fail . " FAIL.\n";
exit($fail > 0 ? 1 : 0);

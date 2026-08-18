<?php
/**
 * firebase_sync.php
 * Pushes a measurement payload to Firebase Realtime Database after MySQL save.
 * MySQL remains the source of truth; Firebase is a live mirror/notification layer.
 */

function firebase_database_url(): string
{
    return trim((string)(defined('FIREBASE_DATABASE_URL') ? FIREBASE_DATABASE_URL : ''));
}

function firebase_auth_token(): string
{
    return trim((string)(defined('FIREBASE_AUTH_TOKEN') ? FIREBASE_AUTH_TOKEN : ''));
}

/**
 * Low-level helper: PUT a JSON payload to a Firebase Realtime Database path.
 * PUT overwrites the value AT that exact path (unlike POST, which appends a
 * new child with a random push key) — every caller here wants an overwrite
 * so pollers always read one flat, current object.
 *
 * Shared by push_latest_measurement() and push_device_status() so there is
 * one place that knows how to talk to Firebase, and one timeout policy.
 */
function firebase_put(string $path, array $payload): bool
{
    $databaseUrl = firebase_database_url();

    if ($databaseUrl === '' || !function_exists('curl_init')) {
        return false;
    }

    $targetUrl = rtrim($databaseUrl, '/') . '/' . ltrim($path, '/') . '.json';
    $authToken = firebase_auth_token();

    if ($authToken !== '') {
        $targetUrl .= '?auth=' . rawurlencode($authToken);
    }

    $ch = curl_init($targetUrl);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        // Kept short and non-blocking on purpose: this call happens inside the
        // ESP32's 2s heartbeat request/response cycle (get_command.php) and
        // inside the browser's status poll (device_ping.php). A slow or dead
        // Firebase must never make the kiosk itself feel unresponsive.
        CURLOPT_TIMEOUT => 4,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);

    $response = curl_exec($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $statusCode >= 200 && $statusCode < 300 && $response !== false;
}

function push_latest_measurement(string $deviceId, array $measurementData): bool
{
    $payload = [
        'device_id' => $deviceId,
        'session_id' => $measurementData['session_id'] ?? null,
        'measurement_id' => $measurementData['measurement_id'] ?? null,
        'child_id' => $measurementData['child_id'] ?? null,
        'child_code' => $measurementData['child_code'] ?? null,
        'child_name' => $measurementData['child_name'] ?? null,
        'height_cm' => $measurementData['height_cm'] ?? null,
        'weight_kg' => $measurementData['weight_kg'] ?? null,
        'age_months' => $measurementData['age_months'] ?? null,
        'waz' => $measurementData['waz'] ?? null,
        'haz' => $measurementData['haz'] ?? null,
        'whz' => $measurementData['whz'] ?? null,
        'nutritional_status' => $measurementData['nutritional_status'] ?? null,
        'source_type' => $measurementData['source_type'] ?? 'kiosk',
        'timestamp' => gmdate('c'),
    ];

    return firebase_put('latest_measurements/' . rawurlencode($deviceId), $payload);
}

/**
 * Mirrors the device's connectivity flag to Firebase under
 * /device_status/{deviceId}. MySQL (devices.status / last_seen_at) stays
 * the source of truth per the project architecture — this is a read-only
 * live copy for anything that wants to watch connectivity without hitting
 * PHP/MySQL directly (e.g. a future dashboard widget).
 *
 * Best-effort by design: called from inside the ESP32 heartbeat endpoint
 * and the browser status-poll endpoint, so a Firebase hiccup must never
 * throw or block either of those requests. Callers should not check the
 * return value for control flow — just fire-and-forget it.
 */
function push_device_status(string $deviceId, bool $isOnline, array $extra = []): bool
{
    $payload = array_merge([
        'device_id' => $deviceId,
        'status' => $isOnline ? 'online' : 'offline',
        'online' => $isOnline,
        'updated_at' => gmdate('c'),
    ], $extra);

    return firebase_put('device_status/' . rawurlencode($deviceId), $payload);
}

function firebase_status_response(string $deviceId, array $measurementData = []): array
{
    return [
        'device_id' => $deviceId,
        'firebase_enabled' => firebase_database_url() !== '',
        'last_sync' => $measurementData['timestamp'] ?? gmdate('c'),
        'status' => 'ready',
    ];
}
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

function push_latest_measurement(string $deviceId, array $measurementData): bool
{
    $databaseUrl = firebase_database_url();

    if ($databaseUrl === '' || !function_exists('curl_init')) {
        return false;
    }

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

    $targetUrl = rtrim($databaseUrl, '/') . '/latest_measurements/' . rawurlencode($deviceId) . '.json';
    $authToken = firebase_auth_token();

    if ($authToken !== '') {
        $targetUrl .= '?auth=' . rawurlencode($authToken);
    }
    $ch = curl_init($targetUrl);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        // Firebase REST semantics: POST appends a new child with a random push key
        // under this path. PUT overwrites the value AT this exact path, which is
        // what we need since the kiosk polls this path expecting a flat object.
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $statusCode >= 200 && $statusCode < 300 && $response !== false;
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
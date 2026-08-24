<?php

require_once __DIR__ . '/auth_middleware.php';

/*
|--------------------------------------------------------------------------
| DEVICE HEARTBEAT WINDOW
|--------------------------------------------------------------------------
|
| The ESP32 firmware calls get_command.php every COMMAND_POLL_INTERVAL
| (2000ms, see esp32_kios_arduino_code.ino) to refresh devices.last_seen_at.
| This constant is how long we wait after the last heartbeat before we
| consider the device offline. It must be a few multiples of that 2s
| interval so one dropped packet or a slow Wi-Fi round trip doesn't cause
| a false "offline" flicker, but it should stay small so power-off is
| reflected quickly. 6 seconds = 3 missed heartbeats.
|
| This is single source of truth: device_ping.php, start_scan.php, and
| api_device_is_online()'s default all read this constant so the
| threshold can never drift out of sync between endpoints.
|
*/
if (!defined('DEVICE_ONLINE_THRESHOLD_SECONDS')) {
    define('DEVICE_ONLINE_THRESHOLD_SECONDS', 6);
}

function api_json_headers(int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
}

function api_response(array $payload, int $statusCode = 200): void
{
    api_json_headers($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function api_success(array $data = [], string $message = 'OK', int $statusCode = 200): void
{
    api_response([
        'success' => true,
        'message' => $message,
        'data' => $data,
    ], $statusCode);
}

function api_error(string $message, int $statusCode = 400, array $extra = []): void
{
    api_response(array_merge([
        'success' => false,
        'message' => $message,
    ], $extra), $statusCode);
}

function api_require_method(array $allowedMethods): void
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if (!in_array($method, array_map('strtoupper', $allowedMethods), true)) {
        api_error('Method not allowed.', 405);
    }
}

function api_payload(): array
{
    $payload = $_POST;
    $rawBody = trim((string)file_get_contents('php://input'));

    if ($rawBody === '') {
        return $payload;
    }

    $decoded = json_decode($rawBody, true);

    if (is_array($decoded)) {
        return array_merge($payload, $decoded);
    }

    return $payload;
}

function api_string(mixed $value, string $default = ''): string
{
    $text = trim((string)$value);

    return $text === '' ? $default : $text;
}

function api_int(mixed $value, int $default = 0): int
{
    if ($value === null || $value === '') {
        return $default;
    }

    return (int)$value;
}

function api_float(mixed $value, ?float $default = null): ?float
{
    if ($value === null || $value === '') {
        return $default;
    }

    return (float)$value;
}

function api_bool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower(trim((string)$value));

    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function api_normalize_enum(mixed $value, array $allowed, string $default): string
{
    $text = api_string($value, $default);

    return in_array($text, $allowed, true) ? $text : $default;
}

/**
 * Validate the ESP32's shared device key before processing any request
 * from the kiosk hardware. Previously these endpoints trusted the plain
 * device_id string alone, which is guessable/enumerable and not a secret
 * -- fine on a LAN-only server, not fine now that the server is public.
 * Sends a 401 and halts execution if the key is missing or wrong.
 */
function api_require_device_key(): void
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $headers = is_array($headers) ? array_change_key_case($headers, CASE_LOWER) : [];

    $providedKey = trim((string)($headers['x-device-key'] ?? ''));

    $expectedKey = defined('ESP32_DEVICE_KEY') ? (string)ESP32_DEVICE_KEY : '';

    if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
        api_error('Invalid or missing device key.', 401);
    }
}

function api_require_staff_session(array $allowedRoles = ['admin', 'nutritionist']): array
{
    $user = current_user();

    if ($user === null || ($user['type'] ?? null) !== 'staff') {
        api_error('Please sign in to continue.', 401);
    }

    if (($user['status'] ?? 'active') !== 'active') {
        api_error('This account is inactive.', 403);
    }

    if (!in_array((string)($user['role'] ?? ''), $allowedRoles, true)) {
        api_error('You do not have permission to access this resource.', 403);
    }

    return $user;
}

function api_require_parent_session(): array
{
    $user = current_user();

    if ($user === null || ($user['type'] ?? null) !== 'parent') {
        api_error('Please sign in to continue.', 401);
    }

    if (($user['status'] ?? 'active') !== 'active') {
        api_error('This account is inactive.', 403);
    }

    return $user;
}

function api_measurement_classification(float $waz, float $haz, float $whz): string
{
    if ($waz < -3 || $whz < -3) {
        return 'Severely Underweight';
    }

    if ($waz < -2 || $whz < -2) {
        return 'Underweight';
    }

    if ($haz < -2) {
        return 'Stunted';
    }

    if ($whz > 2) {
        return 'Overweight';
    }

    return 'Normal';
}

function api_device_is_online(?array $device, int $heartbeatSeconds = DEVICE_ONLINE_THRESHOLD_SECONDS): bool
{
    if (!is_array($device)) {
        return false;
    }

    $status = strtolower((string)($device['status'] ?? 'offline'));
    if ($status === 'maintenance') {
        return false;
    }

    /*
    | Deliberately NOT doing `time() - strtotime($lastSeen)` here. That
    | compares a PHP-clock timestamp against a MySQL-clock timestamp, and
    | the two are not guaranteed to share a timezone (last_seen_at is
    | written by MySQL's NOW()/CURRENT_TIMESTAMP, while time() reads PHP's
    | date.timezone). A mismatch there previously made this always return
    | true, no matter how stale the row was.
    |
    | Instead the caller's SQL is expected to compute the staleness with
    | MySQL's own clock, e.g.:
    |   SELECT ..., TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) AS seconds_since_last_seen
    | and hand us that number. Both sides of the subtraction then come
    | from the same clock, so there's nothing left to disagree.
    */
    if (!array_key_exists('seconds_since_last_seen', $device) || $device['seconds_since_last_seen'] === null) {
        // No precomputed staleness available — fail closed rather than
        // silently falling back to the broken PHP-vs-MySQL comparison.
        return false;
    }

    $secondsSinceLastSeen = (int)$device['seconds_since_last_seen'];

    return $secondsSinceLastSeen >= 0 && $secondsSinceLastSeen <= $heartbeatSeconds;
}

/*
|--------------------------------------------------------------------------
| WRITE THE STALE STATUS BACK TO devices.status
|--------------------------------------------------------------------------
|
| api_device_is_online() only computes an answer for the current request;
| it never touches the database, so devices.status can still say 'active'
| in the table itself even after every page correctly renders "offline".
| This function is the single place that actually corrects that column.
| Call it anywhere a device row is loaded for display (kiosk ping,
| admin dashboard, sensors page) — a genuinely online or manually
| maintenance/offline device is left untouched, so this is always safe
| to call.
|
*/
function api_sync_stale_device_status(array $device): array
{
    $status = strtolower((string)($device['status'] ?? 'offline'));

    if ($status === 'maintenance' || $status === 'offline') {
        return $device;
    }

    if (api_device_is_online($device)) {
        return $device;
    }

    $deviceCode = (string)($device['device_code'] ?? '');
    if ($deviceCode === '') {
        return $device;
    }

    $conn = get_db_connection();
    $stmt = mysqli_prepare($conn, 'UPDATE devices SET status = ?, updated_at = NOW() WHERE device_code = ?');
    if ($stmt !== false) {
        $offlineStatus = 'offline';
        mysqli_stmt_bind_param($stmt, 'ss', $offlineStatus, $deviceCode);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    $device['status'] = 'offline';

    // Mirror to Firebase too, same as device_ping.php does, but only if
    // firebase_sync.php happens to be loaded on this page — never fatal
    // on a page that hasn't required it.
    if (function_exists('push_device_status')) {
        push_device_status($deviceCode, false, [
            'message' => 'Heartbeat timed out (no check-in for over ' . DEVICE_ONLINE_THRESHOLD_SECONDS . 's).',
        ]);
    }

    return $device;
}

function api_device_upsert(string $deviceCode, ?string $location = null, ?string $status = null): ?array
{
    $conn = get_db_connection();
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO devices (device_code, location, last_seen_at, status, updated_at)
         VALUES (?, ?, CURRENT_TIMESTAMP, ?, CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE
             location = COALESCE(VALUES(location), location),
             status = COALESCE(VALUES(status), status),
             last_seen_at = CURRENT_TIMESTAMP,
             updated_at = CURRENT_TIMESTAMP'
    );

    if ($stmt === false) {
        return null;
    }

    $normalizedStatus = api_normalize_enum($status, ['active', 'maintenance', 'offline'], 'active');
    $normalizedLocation = $location === null ? null : api_string($location, '');
    mysqli_stmt_bind_param($stmt, 'sss', $deviceCode, $normalizedLocation, $normalizedStatus);

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);

        return null;
    }

    mysqli_stmt_close($stmt);

    $lookup = mysqli_prepare($conn, 'SELECT id, device_code, location, last_seen_at, calibration_offset_height, calibration_offset_weight, hx711_calibration_factor, mounting_height_cm, status, updated_at FROM devices WHERE device_code = ? LIMIT 1');

    if ($lookup === false) {
        return null;
    }

    mysqli_stmt_bind_param($lookup, 's', $deviceCode);
    mysqli_stmt_execute($lookup);
    $result = mysqli_stmt_get_result($lookup);
    $row = $result instanceof mysqli_result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($lookup);

    return is_array($row) ? $row : null;
}
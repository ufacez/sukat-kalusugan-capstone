<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/api_helpers.php';

if (!defined('MEASUREMENT_SESSION_TIMEOUT_SECONDS')) {
    define('MEASUREMENT_SESSION_TIMEOUT_SECONDS', 180);
}

if (!defined('MEASUREMENT_SESSION_POLL_SECONDS')) {
    define('MEASUREMENT_SESSION_POLL_SECONDS', 2);
}

function measurement_session_is_active_status(string $status): bool
{
    return in_array($status, ['START_REQUESTED', 'MEASURING'], true);
}

function measurement_session_row_to_payload(array $row): array
{
    $childName = trim((string)($row['child_first_name'] ?? '') . ' ' . (string)($row['child_last_name'] ?? ''));

    $measurement = null;
    if (isset($row['measurement_id']) && (int)$row['measurement_id'] > 0) {
        $measurement = [
            'measurement_id' => (int)$row['measurement_id'],
            'height_cm' => isset($row['measurement_height_cm']) ? (float)$row['measurement_height_cm'] : null,
            'weight_kg' => isset($row['measurement_weight_kg']) ? (float)$row['measurement_weight_kg'] : null,
            // Canonical day count -- the WHO calculator uses this.
            'age_days' => isset($row['measurement_age_days']) ? (int)$row['measurement_age_days'] : null,
            // Whole-number month estimate (intdiv(days, 30)) for the
            // "X mo" label.
            'age_months' => isset($row['measurement_age_months']) ? (int)$row['measurement_age_months'] : null,
            'waz' => isset($row['measurement_waz']) ? (float)$row['measurement_waz'] : null,
            'haz' => isset($row['measurement_haz']) ? (float)$row['measurement_haz'] : null,
            'whz' => isset($row['measurement_whz']) ? (float)$row['measurement_whz'] : null,
            'nutritional_status' => (string)($row['measurement_status_text'] ?? ''),
            'wfa_status' => (string)($row['measurement_wfa_status'] ?? ''),
            'hfa_status' => (string)($row['measurement_hfa_status'] ?? ''),
            'wflh_status' => (string)($row['measurement_wflh_status'] ?? ''),
            'is_flagged' => !empty($row['measurement_is_flagged']),
            'flag_reason' => isset($row['measurement_flag_reason']) ? (string)$row['measurement_flag_reason'] : null,
            'source_type' => (string)($row['measurement_source_type'] ?? 'kiosk'),
            'created_at' => (string)($row['measurement_created_at'] ?? ''),
        ];
    }

    return [
        'session_id' => (int)($row['session_id'] ?? $row['id'] ?? 0),
        'device_id' => (string)($row['device_code'] ?? ''),
        'device_db_id' => isset($row['device_db_id']) ? (int)$row['device_db_id'] : null,
        'child_id' => isset($row['child_id']) ? (int)$row['child_id'] : null,
        'child_code' => (string)($row['child_code'] ?? ''),
        'child_name' => $childName,
        'status' => (string)($row['status'] ?? 'IDLE'),
        'command' => (string)($row['command'] ?? 'START'),
        'started_at' => (string)($row['started_at'] ?? ''),
        'completed_at' => (string)($row['completed_at'] ?? ''),
        'expires_at' => (string)($row['expires_at'] ?? ''),
        'height_cm' => isset($row['height_cm']) ? (float)$row['height_cm'] : null,
        'weight_kg' => isset($row['weight_kg']) ? (float)$row['weight_kg'] : null,
        'measurement_id' => isset($row['measurement_id']) ? (int)$row['measurement_id'] : null,
        'error_message' => (string)($row['error_message'] ?? ''),
        'measurement' => $measurement,
        'created_at' => (string)($row['created_at'] ?? ''),
        'updated_at' => (string)($row['updated_at'] ?? ''),
    ];
}

function measurement_session_fetch_latest_for_device(mysqli $conn, string $deviceCode): ?array
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT
            s.id AS session_id,
            s.device_id AS device_db_id,
            d.device_code,
            s.child_id,
            c.child_code,
            c.first_name AS child_first_name,
            c.last_name AS child_last_name,
            s.status,
            s.command,
            s.started_at,
            s.completed_at,
            s.expires_at,
            s.height_cm,
            s.weight_kg,
            s.measurement_id,
            s.error_message,
            s.created_at,
            s.updated_at,
            m.height_cm AS measurement_height_cm,
            m.weight_kg AS measurement_weight_kg,
            m.age_months AS measurement_age_months,
            m.age_days AS measurement_age_days,
            m.waz AS measurement_waz,
            m.haz AS measurement_haz,
            m.whz AS measurement_whz,
            m.nutritional_status AS measurement_status_text,
            m.wfa_status AS measurement_wfa_status,
            m.hfa_status AS measurement_hfa_status,
            m.wfh_status AS measurement_wflh_status,
            m.is_flagged AS measurement_is_flagged,
            m.flag_reason AS measurement_flag_reason,
            m.source_type AS measurement_source_type,
            m.created_at AS measurement_created_at
         FROM measurement_sessions s
         INNER JOIN devices d ON d.id = s.device_id
         INNER JOIN children c ON c.id = s.child_id
         LEFT JOIN measurements m ON m.id = s.measurement_id
         WHERE d.device_code = ?
         ORDER BY s.id DESC
         LIMIT 1'
    );

    if ($stmt === false) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, 's', $deviceCode);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result instanceof mysqli_result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    return is_array($row) ? $row : null;
}

function measurement_session_fetch_active_for_device(mysqli $conn, string $deviceCode): ?array
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT
            s.id AS session_id,
            s.device_id AS device_db_id,
            d.device_code,
            s.child_id,
            c.child_code,
            c.first_name AS child_first_name,
            c.last_name AS child_last_name,
            s.status,
            s.command,
            s.started_at,
            s.completed_at,
            s.expires_at,
            s.height_cm,
            s.weight_kg,
            s.measurement_id,
            s.error_message,
            s.created_at,
            s.updated_at,
            m.height_cm AS measurement_height_cm,
            m.weight_kg AS measurement_weight_kg,
            m.age_months AS measurement_age_months,
            m.age_days AS measurement_age_days,
            m.waz AS measurement_waz,
            m.haz AS measurement_haz,
            m.whz AS measurement_whz,
            m.nutritional_status AS measurement_status_text,
            m.wfa_status AS measurement_wfa_status,
            m.hfa_status AS measurement_hfa_status,
            m.wfh_status AS measurement_wflh_status,
            m.is_flagged AS measurement_is_flagged,
            m.flag_reason AS measurement_flag_reason,
            m.source_type AS measurement_source_type,
            m.created_at AS measurement_created_at
         FROM measurement_sessions s
         INNER JOIN devices d ON d.id = s.device_id
         INNER JOIN children c ON c.id = s.child_id
         LEFT JOIN measurements m ON m.id = s.measurement_id
         WHERE d.device_code = ?
           AND s.status IN (\'START_REQUESTED\', \'MEASURING\')
         ORDER BY s.id DESC
         LIMIT 1'
    );

    if ($stmt === false) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, 's', $deviceCode);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result instanceof mysqli_result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    return is_array($row) ? $row : null;
}

function measurement_session_fetch_by_id_for_device(
    mysqli $conn,
    int $sessionId,
    string $deviceCode
): ?array {
    $stmt = mysqli_prepare(
        $conn,
        'SELECT
            s.id AS session_id,
            s.device_id AS device_db_id,
            d.device_code,
            s.child_id,
            c.child_code,
            c.first_name AS child_first_name,
            c.last_name AS child_last_name,
            s.status,
            s.command,
            s.started_at,
            s.completed_at,
            s.expires_at,
            s.height_cm,
            s.weight_kg,
            s.measurement_id,
            s.error_message,
            s.created_at,
            s.updated_at,
            m.height_cm AS measurement_height_cm,
            m.weight_kg AS measurement_weight_kg,
            m.age_months AS measurement_age_months,
            m.age_days AS measurement_age_days,
            m.waz AS measurement_waz,
            m.haz AS measurement_haz,
            m.whz AS measurement_whz,
            m.nutritional_status AS measurement_status_text,
            m.wfa_status AS measurement_wfa_status,
            m.hfa_status AS measurement_hfa_status,
            m.wfh_status AS measurement_wflh_status,
            m.is_flagged AS measurement_is_flagged,
            m.flag_reason AS measurement_flag_reason,
            m.source_type AS measurement_source_type,
            m.created_at AS measurement_created_at
         FROM measurement_sessions s
         INNER JOIN devices d ON d.id = s.device_id
         INNER JOIN children c ON c.id = s.child_id
         LEFT JOIN measurements m ON m.id = s.measurement_id
         WHERE s.id = ?
           AND d.device_code = ?
         LIMIT 1'
    );

    if ($stmt === false) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, 'is', $sessionId, $deviceCode);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result instanceof mysqli_result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    return is_array($row) ? $row : null;
}
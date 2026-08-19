<?php

require_once __DIR__ . '/who_calculator.php';

function kiosk_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function kiosk_fetch_all(string $sql, string $types = '', array $params = []): array
{
    $conn = get_db_connection();
    $stmt = mysqli_prepare($conn, $sql);

    if ($stmt === false) {
        return [];
    }

    if ($types !== '' && $params !== []) {
        $bindArgs = [$stmt, $types];
        foreach ($params as $index => &$value) {
            $bindArgs[] = &$value;
        }
        call_user_func_array('mysqli_stmt_bind_param', $bindArgs);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];

    if ($result instanceof mysqli_result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }

    mysqli_stmt_close($stmt);
    return $rows;
}

/**
 * Resolves the barangay a given kiosk device is assigned to.
 *
 * A device with no barangay assigned (or one that doesn't exist yet) is
 * treated as unscoped, so a freshly-registered kiosk keeps showing every
 * child instead of an empty list until an admin assigns it a barangay
 * from Admin > Sensors.
 *
 * @return array{id:int,name:string}|null
 */
function kiosk_resolve_device_barangay(string $deviceCode): ?array
{
    $rows = kiosk_fetch_all(
        'SELECT b.id, b.name
         FROM devices d
         INNER JOIN barangays b ON b.id = d.barangay_id
         WHERE d.device_code = ?
         LIMIT 1',
        's',
        [$deviceCode]
    );

    if ($rows === []) {
        return null;
    }

    return [
        'id' => (int)$rows[0]['id'],
        'name' => (string)$rows[0]['name'],
    ];
}

function kiosk_age_months(?string $birthdate): int
{
    if (!$birthdate) return 0;

    // Delegates to doh_age_in_months() (includes/who_calculator.php) so this
    // matches the DOH e-OPT Plus convention and every other screen in the
    // app instead of using its own calendar-based calculation.
    return doh_age_in_months($birthdate) ?? 0;
}

function kiosk_person_name(array $child): string
{
    return trim(
        (string)($child['first_name'] ?? '') . ' ' .
        (string)($child['last_name'] ?? '')
    );
}

function kiosk_json(array $value): string
{
    return htmlspecialchars(
        (string)json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ),
        ENT_QUOTES,
        'UTF-8'
    );
}

<?php

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

function kiosk_age_months(?string $birthdate): int
{
    if (!$birthdate) return 0;

    $birth = new DateTimeImmutable($birthdate);
    $today = new DateTimeImmutable('today');
    $diff = $birth->diff($today);

    return ($diff->y * 12) + $diff->m;
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

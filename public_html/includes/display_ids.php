<?php

declare(strict_types=1);

/**
 * display_ids.php — human-readable, immutable display identifiers for people.
 *
 *   Admin        A0001-style  ->  ADM-0001   (users.role_id = 1, user_code)
 *   Nutritionist             ->  NUT-0001   (users.role_id = 2, user_code)
 *   Parent                   ->  PAR-0001   (parents.parent_code)
 *   Child                    ->  CHD-0001   (children.child_code, pre-existing)
 *
 * These are DISPLAY identifiers only. Primary keys, foreign keys, auth and
 * the parent portal are untouched. Each prefix has an independent counter row
 * in `code_counters`, advanced atomically per connection so two simultaneous
 * creates can never mint the same code.
 *
 * Codes are immutable: a code is assigned once at creation and never
 * rewritten (even if a role were to change).
 */

require_once __DIR__ . '/db.php';

/** Role id -> display prefix for staff accounts. */
function sk_staff_prefix(int $roleId): string
{
    return match ($roleId) {
        1 => 'ADM', // roles.id 1 = admin
        2 => 'NUT', // roles.id 2 = nutritionist
        default => 'USR',
    };
}

/**
 * Allocate the next code for a prefix, e.g. "ADM-0007".
 *
 * Atomic via LAST_INSERT_ID(expr): the counter row is created on first use
 * and incremented in a single statement, and the new value is read back from
 * the connection — no SELECT-then-UPDATE race.
 *
 * Should be called inside the same transaction as the row it labels so that a
 * rolled-back create also rolls back the counter advance.
 */
function sk_next_display_code(string $prefix): string
{
    $conn = get_db_connection();
    $prefix = strtoupper(trim($prefix));
    if ($prefix === '') {
        $prefix = 'USR';
    }

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO code_counters (prefix, last_value) VALUES (?, LAST_INSERT_ID(1))
         ON DUPLICATE KEY UPDATE last_value = LAST_INSERT_ID(last_value + 1)"
    );
    if ($stmt === false) {
        // Defensive: fall back to a time-based value so a create never hard-fails.
        error_log('sk_next_display_code prepare failed: ' . mysqli_error($conn));
        return $prefix . '-' . substr((string)time(), -4);
    }

    mysqli_stmt_bind_param($stmt, 's', $prefix);
    mysqli_stmt_execute($stmt);
    $n = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    // LAST_INSERT_ID() can return 0 on some drivers/paths; re-read the row.
    if ($n <= 0) {
        $row = mysqli_fetch_assoc(mysqli_query(
            $conn,
            "SELECT last_value FROM code_counters WHERE prefix = '" . mysqli_real_escape_string($conn, $prefix) . "'"
        ));
        $n = (int)($row['last_value'] ?? 1);
    }

    return $prefix . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

/** Convenience wrapper: allocate a staff code from a role id. */
function sk_next_staff_code(int $roleId): string
{
    return sk_next_display_code(sk_staff_prefix($roleId));
}

/** Fetch a staff member's code by user id (cached per request). */
function sk_staff_code_for_user(int $userId): string
{
    static $cache = [];
    if ($userId <= 0) {
        return '';
    }
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }
    $conn = get_db_connection();
    $code = '';
    $stmt = mysqli_prepare($conn, 'SELECT user_code FROM users WHERE id = ? LIMIT 1');
    if ($stmt === false) {
        error_log('sk_staff_code_for_user prepare failed: ' . mysqli_error($conn));
        return $cache[$userId] = '';
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res instanceof mysqli_result ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    $code = (string)($row['user_code'] ?? '');
    return $cache[$userId] = $code;
}

/** Fetch a parent's code by parent id (cached per request). */
function sk_parent_code_for_parent(int $parentId): string
{
    static $cache = [];
    if ($parentId <= 0) {
        return '';
    }
    if (array_key_exists($parentId, $cache)) {
        return $cache[$parentId];
    }
    $conn = get_db_connection();
    $code = '';
    $stmt = mysqli_prepare($conn, 'SELECT parent_code FROM parents WHERE id = ? LIMIT 1');
    if ($stmt === false) {
        error_log('sk_parent_code_for_parent prepare failed: ' . mysqli_error($conn));
        return $cache[$parentId] = '';
    }
    mysqli_stmt_bind_param($stmt, 'i', $parentId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res instanceof mysqli_result ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    $code = (string)($row['parent_code'] ?? '');
    return $cache[$parentId] = $code;
}

/** Render a code as a small monospace badge (empty string when no code). */
function sk_code_badge(?string $code, string $extraClass = ''): string
{
    $code = trim((string)$code);
    if ($code === '') {
        return '';
    }
    $cls = 'sk-code' . ($extraClass !== '' ? ' ' . $extraClass : '');
    return '<span class="' . $cls . '">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</span>';
}

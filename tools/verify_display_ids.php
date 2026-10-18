<?php

declare(strict_types=1);

/**
 * tools/verify_display_ids.php
 *
 * Read-only audit of the human-readable display-ID scheme:
 *
 *   users.user_code      -> ADM-0001 / NUT-0001 (staff)   [role_id 1, 2]
 *   parents.parent_code  -> PAR-0001
 *   children.child_code  -> CHD-0001 (pre-existing)
 *
 * Asserts, for each set:
 *   - no NULL / empty codes
 *   - no duplicate codes
 *   - every code matches PREFIX-0000 (4+ digits)
 *   - code_counters.last_value >= the number of codes issued for that prefix
 *     (catches a counter that was re-seeded too low and would collide)
 *
 * Only SELECTs run. Credentials come from CLI flags or the project .env.
 *
 * Usage:
 *   php tools/verify_display_ids.php [--host=.. --user=.. --pass=.. --db=..]
 * Exit 1 when any check fails.
 */

$root = getcwd();
$cli = ['host' => null, 'user' => null, 'pass' => null, 'db' => null];
foreach ($argv as $arg) {
    foreach (array_keys($cli) as $key) {
        if (str_starts_with($arg, '--' . $key . '=')) {
            $cli[$key] = substr($arg, strlen($key) + 3);
        }
    }
}

$envFile = $root . '/.env';
$env = [];
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $env[trim($parts[0])] = trim($parts[1]);
        }
    }
}

$host = $cli['host'] ?? $env['DB_HOST'] ?? 'localhost';
$user = $cli['user'] ?? $env['DB_USER'] ?? 'root';
$pass = $cli['pass'] ?? $env['DB_PASS'] ?? '';
$dbName = $cli['db'] ?? $env['DB_NAME'] ?? 'sukat_kalusugan';

$conn = mysqli_connect($host, $user, $pass, $dbName);
if ($conn === false) {
    fwrite(STDERR, 'Cannot connect to database: ' . mysqli_connect_error() . "\n");
    exit(2);
}

$issues = [];

/**
 * Run one verification over a (code-column, table, where, prefix) set.
 *
 * @param string      $label   Human label for output.
 * @param string      $table   Table name.
 * @param string      $column  Code column.
 * @param string|null $where   Optional WHERE fragment (already trusted, no user input).
 * @param string      $prefix  Expected prefix, e.g. "PAR".
 */
function check_code_set(mysqli $conn, array &$issues, string $label, string $table, string $column, ?string $where, ?string $prefix): void
{
    $whereSql = $where !== null ? " AND {$where}" : '';
    $patterns = [
        "NULL/empty" => "SELECT COUNT(*) AS n FROM `{$table}` WHERE (`{$column}` IS NULL OR `{$column}` = ''){$whereSql}",
        "duplicate"  => "SELECT `{$column}` AS v, COUNT(*) AS n FROM `{$table}` WHERE `{$column}` IS NOT NULL AND `{$column}` <> ''{$whereSql} GROUP BY `{$column}` HAVING n > 1",
    ];
    if ($prefix !== null) {
        $patterns['bad-format'] = "SELECT COUNT(*) AS n FROM `{$table}` WHERE `{$column}` IS NOT NULL AND `{$column}` <> '' AND `{$column}` NOT REGEXP '^{$prefix}-[0-9]{4,}$'{$whereSql}";
    }

    foreach ($patterns as $kind => $sql) {
        $res = mysqli_query($conn, $sql);
        if ($res === false) {
            $issues[] = "{$label}: query failed ({$kind}) — " . mysqli_error($conn);
            return;
        }
        if ($kind === 'duplicate') {
            while ($row = mysqli_fetch_assoc($res)) {
                $issues[] = "{$label}: duplicate code '{$row['v']}' x{$row['n']}";
            }
        } else {
            $row = mysqli_fetch_assoc($res);
            $n = (int)($row['n'] ?? 0);
            if ($n > 0) {
                $issues[] = "{$label}: {$n} row(s) with {$kind} code";
            }
        }
        mysqli_free_result($res);
    }

    $totalRes = mysqli_query($conn, "SELECT COUNT(*) AS n FROM `{$table}`" . ($where !== null ? " WHERE {$where}" : ''));
    $total = $totalRes !== false ? (int)(mysqli_fetch_assoc($totalRes)['n'] ?? 0) : 0;
    if ($totalRes !== false) {
        mysqli_free_result($totalRes);
    }
    echo sprintf("%-28s %5d row(s) checked (%s)\n", $label, $total, $prefix ?? 'role-aware');
}

check_code_set($conn, $issues, 'Staff (users.user_code)', 'users', 'user_code', null, null);
check_code_set($conn, $issues, 'Parents (parents.parent_code)', 'parents', 'parent_code', null, 'PAR');
check_code_set($conn, $issues, 'Children (children.child_code)', 'children', 'child_code', null, 'CHD');

// Staff format is role-dependent: ADM-* for role 1, NUT-* for role 2.
foreach ([1 => 'ADM', 2 => 'NUT'] as $roleId => $prefix) {
    $res = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS n FROM users
          WHERE role_id = {$roleId}
            AND user_code IS NOT NULL AND user_code <> ''
            AND user_code NOT REGEXP '^{$prefix}-[0-9]{4,}$'"
    );
    $n = $res !== false ? (int)(mysqli_fetch_assoc($res)['n'] ?? 0) : 0;
    if ($res !== false) {
        mysqli_free_result($res);
    }
    if ($n > 0) {
        $issues[] = "Staff format: {$n} role {$roleId} user(s) not matching {$prefix}-####.";
    }
}

// Counter sanity: last_value must be >= the highest issued number for the prefix.
$counterRows = mysqli_query($conn, 'SELECT prefix, last_value FROM code_counters');
$counters = [];
if ($counterRows !== false) {
    while ($row = mysqli_fetch_assoc($counterRows)) {
        $counters[(string)$row['prefix']] = (int)$row['last_value'];
    }
    mysqli_free_result($counterRows);
}

$issuedChecks = [
    'ADM' => ["SELECT MAX(CAST(SUBSTRING(user_code, 5) AS UNSIGNED)) AS mx FROM users WHERE user_code LIKE 'ADM-%'", null],
    'NUT' => ["SELECT MAX(CAST(SUBSTRING(user_code, 5) AS UNSIGNED)) AS mx FROM users WHERE user_code LIKE 'NUT-%'", null],
    'PAR' => ["SELECT MAX(CAST(SUBSTRING(parent_code, 5) AS UNSIGNED)) AS mx FROM parents WHERE parent_code LIKE 'PAR-%'", null],
    'CHD' => ["SELECT MAX(CAST(SUBSTRING(child_code, 5) AS UNSIGNED)) AS mx FROM children WHERE child_code LIKE 'CHD-%'", null],
];
foreach ($issuedChecks as $prefix => [$sql, $_unused]) {
    $res = mysqli_query($conn, $sql);
    $max = $res !== false ? (int)(mysqli_fetch_assoc($res)['mx'] ?? 0) : 0;
    if ($res !== false) {
        mysqli_free_result($res);
    }
    $last = $counters[$prefix] ?? 0;
    echo sprintf("%-28s counter=%d highest_issued=%d\n", "Counter ({$prefix})", $last, $max);
    if ($prefix !== 'CHD') { // CHD has no counter (legacy max+1 scheme).
        if ($last < $max) {
            $issues[] = "code_counters.{$prefix} last_value={$last} is below highest issued code {$max} (future collisions).";
        }
    }
}

// Staff codes must align with role: ADM-* -> role 1, NUT-* -> role 2.
$roleMismatch = mysqli_query(
    $conn,
    "SELECT u.id, u.user_code, u.role_id FROM users u
      WHERE u.user_code IS NOT NULL AND u.user_code <> ''
        AND ((u.user_code LIKE 'ADM-%' AND u.role_id <> 1) OR (u.user_code LIKE 'NUT-%' AND u.role_id <> 2))"
);
if ($roleMismatch !== false) {
    while ($row = mysqli_fetch_assoc($roleMismatch)) {
        $issues[] = "user #{$row['id']}: code {$row['user_code']} does not match role {$row['role_id']}.";
    }
    mysqli_free_result($roleMismatch);
}

echo "\n";
if ($issues === []) {
    echo "No display-ID issues: codes unique, well-formed, and counters in range.\n";
    exit(0);
}

echo '=== DISPLAY-ID ISSUES (' . count($issues) . ") ===\n";
foreach ($issues as $issue) {
    echo $issue . "\n";
}
exit(1);

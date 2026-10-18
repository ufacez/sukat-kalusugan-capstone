<?php

declare(strict_types=1);

/**
 * tools/backfill_audit_user_type.php
 *
 * One-off backfill: fill audit_logs.user_type for legacy rows written before
 * user_type was recorded, so the polymorphic actor join can resolve each row
 * to the right account and its display code (ADM-#### / NUT-#### / PAR-####).
 *
 * Rules (id spaces of `users` and `parents` are matched against a user_id):
 *   - user_id matches a users row  -> 'admin' (role_id 1) or 'nutritionist' (role 2)
 *   - else matches a parents row    -> 'parent'
 *   - matches nothing               -> left NULL (deleted account; shows as "System")
 *
 * Idempotent: only touches rows WHERE user_type IS NULL. Safe to re-run.
 * Credentials come from CLI flags or the project .env — never hardcoded.
 *
 * Usage:
 *   php tools/backfill_audit_user_type.php [--dry-run] [--host=.. --user=.. --pass=.. --db=..]
 */

$root = getcwd();
$cli = ['host' => null, 'user' => null, 'pass' => null, 'db' => null];
$dryRun = false;
foreach ($argv as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    }
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

$nullBefore = (int)(mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS n FROM audit_logs WHERE user_type IS NULL'))['n'] ?? 0);
echo "audit_logs rows with NULL user_type (before): {$nullBefore}\n";

if ($nullBefore === 0) {
    echo "Nothing to backfill.\n";
    exit(0);
}

$staffSql = "UPDATE audit_logs a
    INNER JOIN users u ON u.id = a.user_id
    SET a.user_type = CASE WHEN u.role_id = 2 THEN 'nutritionist' ELSE 'admin' END
    WHERE a.user_type IS NULL";
$parentSql = "UPDATE audit_logs a
    INNER JOIN parents p ON p.id = a.user_id
    SET a.user_type = 'parent'
    WHERE a.user_type IS NULL
      AND NOT EXISTS (SELECT 1 FROM users u2 WHERE u2.id = a.user_id)";

if ($dryRun) {
    echo "[dry-run] would set staff types via users join\n";
    echo "[dry-run] would set parent types via parents join\n";
    $remaining = (int)(mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS n FROM audit_logs WHERE user_type IS NULL'))['n'] ?? 0);
    echo "remaining NULL user_type: {$remaining}\n";
    exit(0);
}

mysqli_begin_transaction($conn);
$staffResult = mysqli_query($conn, $staffSql);
if ($staffResult === false) {
    mysqli_rollback($conn);
    fwrite(STDERR, 'Backfill failed (staff update): ' . mysqli_error($conn) . "\n");
    exit(1);
}
$staffAffected = mysqli_affected_rows($conn);
$parentResult = mysqli_query($conn, $parentSql);
if ($parentResult === false) {
    mysqli_rollback($conn);
    fwrite(STDERR, 'Backfill failed (parent update): ' . mysqli_error($conn) . "\n");
    exit(1);
}
$parentAffected = mysqli_affected_rows($conn);
mysqli_commit($conn);

$nullAfter = (int)(mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS n FROM audit_logs WHERE user_type IS NULL'))['n'] ?? 0);
echo "staff rows updated:  {$staffAffected}\n";
echo "parent rows updated: {$parentAffected}\n";
echo "audit_logs rows with NULL user_type (after): {$nullAfter}\n";

if ($nullAfter > 0) {
    echo "Note: {$nullAfter} row(s) still NULL — the referenced account no longer exists; they render as \"System\".\n";
}

exit(0);

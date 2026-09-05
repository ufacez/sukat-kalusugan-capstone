<?php

/**
 * login_throttle.php
 * Brute-force lockout for api/auth/login.php.
 *
 * Login attempts are tracked per *identifier* (whatever the person typed —
 * email or username), not per IP. That's deliberate: a school/clinic kiosk
 * or office often has many staff sharing one IP, so an IP-based lock would
 * lock out everyone on that network after one person mistypes a password a
 * few times. Locking by identifier only slows down someone guessing a
 * specific account's password, which is the actual threat this defends
 * against.
 */

require_once __DIR__ . '/db.php';

if (!defined('LOGIN_MAX_FAILED_ATTEMPTS')) {
    define('LOGIN_MAX_FAILED_ATTEMPTS', 5);
}

if (!defined('LOGIN_LOCKOUT_WINDOW_MINUTES')) {
    define('LOGIN_LOCKOUT_WINDOW_MINUTES', 5);
}

/**
 * Normalizes an identifier the same way for every call so
 * "Jane@Example.com" and "jane@example.com " count as the same account.
 */
function login_throttle_normalize(string $identifier): string
{
    return strtolower(trim($identifier));
}

/**
 * Records one login attempt (success or failure) for lockout accounting
 * and basic audit visibility. Best-effort: a logging failure here should
 * never block the login flow itself.
 */
function login_record_attempt(string $identifier, bool $success): void
{
    $conn = get_db_connection();
    $normalized = login_throttle_normalize($identifier);
    $ipAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $successInt = $success ? 1 : 0;

    $stmt = mysqli_prepare($conn, 'INSERT INTO login_attempts (identifier, ip_address, success) VALUES (?, ?, ?)');

    if ($stmt === false) {
        return;
    }

    mysqli_stmt_bind_param($stmt, 'ssi', $normalized, $ipAddress, $successInt);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    // Opportunistic cleanup so this table doesn't grow forever — no cron
    // needed for a capstone deployment. Runs on a small fraction of writes
    // rather than every single one.
    if (mt_rand(1, 20) === 1) {
        mysqli_query($conn, 'DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
    }
}

/**
 * Returns how many seconds remain before this identifier can try again,
 * or 0 if it isn't currently locked out.
 */
function login_lockout_seconds_remaining(string $identifier): int
{
    $conn = get_db_connection();
    $normalized = login_throttle_normalize($identifier);
    $windowMinutes = LOGIN_LOCKOUT_WINDOW_MINUTES;
    $maxAttempts = LOGIN_MAX_FAILED_ATTEMPTS;

    $stmt = mysqli_prepare(
        $conn,
        'SELECT COUNT(*) AS failures, MAX(attempted_at) AS last_attempt
         FROM login_attempts
         WHERE identifier = ?
           AND success = 0
           AND attempted_at >= (NOW() - INTERVAL ? MINUTE)'
    );

    if ($stmt === false) {
        return 0;
    }

    mysqli_stmt_bind_param($stmt, 'si', $normalized, $windowMinutes);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result instanceof mysqli_result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    if ($row === null || (int)$row['failures'] < $maxAttempts || empty($row['last_attempt'])) {
        return 0;
    }

    $lastAttemptTimestamp = strtotime((string)$row['last_attempt']);

    if ($lastAttemptTimestamp === false) {
        return 0;
    }

    $unlocksAt = $lastAttemptTimestamp + ($windowMinutes * 60);
    $remaining = $unlocksAt - time();

    return max(0, $remaining);
}

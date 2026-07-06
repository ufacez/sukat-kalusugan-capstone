<?php

/**
 * audit_logger.php
 * Writes entries to the audit_logs table.
 *
 * Functions to implement:
 *   log_action(?int $user_id, string $action, string $level, string $description): void
 */

require_once __DIR__ . '/db.php';

function log_action(?int $user_id, string $action, string $level, string $description): void
{
    $conn = get_db_connection();
    $normalizedLevel = match (strtolower($level)) {
        'warn' => 'warning',
        'warning' => 'warning',
        'danger' => 'danger',
        default => 'info',
    };
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO audit_logs (user_id, action, level, description, ip_address) VALUES (?, ?, ?, ?, ?)'
    );

    if ($stmt === false) {
        return;
    }

    mysqli_stmt_bind_param($stmt, 'issss', $user_id, $action, $normalizedLevel, $description, $ipAddress);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

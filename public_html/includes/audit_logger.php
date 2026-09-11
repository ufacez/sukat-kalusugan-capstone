<?php

/**
 * audit_logger.php
 * Writes entries to the audit_logs table.
 *
 * Functions to implement:
 *   log_action(?int $user_id, string $action, string $level, string $description, ?string $userType = null): void
 */

require_once __DIR__ . '/db.php';

function log_action(?int $user_id, string $action, string $level, string $description, ?string $userType = null): void
{
    $conn = get_db_connection();
    $normalizedLevel = match (strtolower($level)) {
        'warn' => 'warning',
        'warning' => 'warning',
        'danger' => 'danger',
        default => 'info',
    };
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

    // Auto-detect user_type from session if not provided
    if ($userType === null) {
        $session = $_SESSION ?? [];
        $sessionType = $session['type'] ?? '';
        if ($sessionType === 'staff') {
            $userType = $session['role'] ?? 'admin';
        } elseif ($sessionType === 'parent') {
            $userType = 'parent';
        }
    }

    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO audit_logs (user_id, action, level, description, ip_address, user_type) VALUES (?, ?, ?, ?, ?, ?)'
    );

    if ($stmt === false) {
        return;
    }

    // Audit writes must never break the request they observe — especially
    // auth flows. A failed log row (e.g. a legacy FK on user_id) is recorded
    // server-side and swallowed so the user-facing response stays intact.
    try {
        mysqli_stmt_bind_param($stmt, 'isssss', $user_id, $action, $normalizedLevel, $description, $ipAddress, $userType);
        $ok = mysqli_stmt_execute($stmt);
    } catch (Throwable $e) {
        error_log('log_action failed: ' . $e->getMessage());
        $ok = false;
    }

    mysqli_stmt_close($stmt);

    if (!$ok) {
        error_log('log_action failed for action ' . $action);
    }
}

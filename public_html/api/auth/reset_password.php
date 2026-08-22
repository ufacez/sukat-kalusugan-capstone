<?php

/**
 * api/auth/reset_password.php
 * Validates a reset token from the emailed link and, if still valid,
 * applies a new password to the matching staff or parent account.
 */

require_once __DIR__ . '/../../includes/auth_middleware.php';
require_once __DIR__ . '/../../includes/audit_logger.php';
require_once __DIR__ . '/../../includes/password_reset.php';

start_secure_session();

function reset_password_respond(bool $success, string $message, int $statusCode = 200, array $extra = []): void
{
    if (wants_json_response()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge([
            'success' => $success,
            'message' => $message,
        ], $extra));
        exit;
    }

    if ($success) {
        header('Location: ' . app_url('/auth/login.php?notice=' . urlencode($message)));
        exit;
    }

    $token = (string)($_POST['token'] ?? '');
    header('Location: ' . app_url('/auth/reset-password.php?token=' . urlencode($token) . '&error=' . urlencode($message)));
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    reset_password_respond(false, 'Method not allowed.', 405);
}

$rawToken = trim((string)($_POST['token'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$confirmPassword = (string)($_POST['confirm_password'] ?? '');

if ($rawToken === '') {
    reset_password_respond(false, 'This reset link is invalid.', 422);
}

if (strlen($password) < 8) {
    reset_password_respond(false, 'Password must be at least 8 characters long.', 422);
}

if ($password !== $confirmPassword) {
    reset_password_respond(false, 'Passwords do not match.', 422);
}

$tokenRow = password_reset_validate_token($rawToken);

if ($tokenRow === null) {
    reset_password_respond(false, 'This reset link is invalid or has expired. Please request a new one.', 400);
}

$newPasswordHash = password_hash($password, PASSWORD_DEFAULT);

$applied = password_reset_consume_token(
    $tokenRow['id'],
    $tokenRow['account_type'],
    $tokenRow['account_id'],
    $newPasswordHash
);

if (!$applied) {
    reset_password_respond(false, 'This reset link is invalid or has expired. Please request a new one.', 400);
}

$userIdForLog = $tokenRow['account_type'] === 'staff' ? $tokenRow['account_id'] : null;
log_action($userIdForLog, 'PASSWORD_RESET_COMPLETE', 'info', 'Password reset completed for ' . $tokenRow['account_type'] . ' account #' . $tokenRow['account_id']);

reset_password_respond(true, 'Your password has been reset. Please sign in with your new password.');

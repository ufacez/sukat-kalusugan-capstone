<?php

/**
 * api/auth/forgot_password.php
 * Accepts an email, and if it matches an active staff or parent account,
 * emails a reset link. Always responds with the same generic success
 * message regardless of whether the email matched — this is deliberate so
 * the endpoint can't be used to enumerate which emails have accounts.
 */

require_once __DIR__ . '/../../includes/auth_middleware.php';
require_once __DIR__ . '/../../includes/audit_logger.php';
require_once __DIR__ . '/../../includes/login_throttle.php';
require_once __DIR__ . '/../../includes/password_reset.php';

start_secure_session();

const FORGOT_PASSWORD_GENERIC_MESSAGE =
    'If that email is linked to an account, we\'ve sent a password reset link to it.';

function forgot_password_respond(bool $success, string $message, int $statusCode = 200): void
{
    if (wants_json_response()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => $success,
            'message' => $message,
        ]);
        exit;
    }

    $param = $success ? 'notice' : 'error';
    header('Location: ' . app_url('/auth/forgot-password.php?' . $param . '=' . urlencode($message)));
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    forgot_password_respond(false, 'Method not allowed.', 405);
}

$email = trim((string)($_POST['email'] ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    forgot_password_respond(false, 'Please enter a valid email address.', 422);
}

// Reuse the login lockout table so someone can't hammer this endpoint to
// spam an inbox with reset emails either — same identifier, same limits.
$lockoutSecondsRemaining = \login_lockout_seconds_remaining($email);

if ($lockoutSecondsRemaining > 0) {
    $minutesRemaining = (int)ceil($lockoutSecondsRemaining / 60);
    forgot_password_respond(
        false,
        'Too many attempts for this email. Please try again in ' . $minutesRemaining . ' minute(s).',
        429
    );
}

$account = password_reset_find_account($email);

if ($account !== null) {
    $rawToken = password_reset_create_token($account['type'], $account['id']);
    password_reset_send_email($account['email'], $account['name'], $rawToken);

    $userIdForLog = $account['type'] === 'staff' ? $account['id'] : null;
    log_action($userIdForLog, 'PASSWORD_RESET_REQUEST', 'info', 'Password reset requested for ' . $account['email']);
}

// Recorded as a "failure" purely so login_lockout_seconds_remaining() (which
// only counts failures) starts throttling repeated requests for the same
// email. This shares its counter with actual login attempts for that
// identifier, which is an acceptable trade-off here: it just means someone
// who spams "forgot password" for an address will also need to wait out
// the same window before signing in normally with it.
\login_record_attempt($email, false);

forgot_password_respond(true, FORGOT_PASSWORD_GENERIC_MESSAGE);

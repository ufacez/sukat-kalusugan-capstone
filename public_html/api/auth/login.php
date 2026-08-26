<?php

/**
 * api/auth/login.php
 * Authenticates staff (admin/nutritionist) and parent accounts.
 */

require_once __DIR__ . '/../../includes/auth_middleware.php';
require_once __DIR__ . '/../../includes/audit_logger.php';
require_once __DIR__ . '/../../includes/login_throttle.php';

start_secure_session();

function login_respond_error(string $message, int $statusCode = 401): void
{
    if (wants_json_response()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => $message,
        ]);
        exit;
    }

    header('Location: ' . app_url('/auth/login.php?error=' . urlencode($message)));
    exit;
}

function login_respond_success(array $authSession): void
{
    $redirectUrl = redirect_for_current_user($authSession);

    if (wants_json_response()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'message' => 'Signed in successfully.',
            'redirect_url' => $redirectUrl,
        ]);
        exit;
    }

    header('Location: ' . $redirectUrl);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    login_respond_error('Method not allowed.', 405);
}

$identifier = trim((string)($_POST['identifier'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$remember = (int)($_POST['remember'] ?? 0) === 1;

if ($identifier === '' || $password === '') {
    login_respond_error('Email/username and password are required.', 422);
}

if ($remember) {
    ini_set('session.cookie_lifetime', 30 * 24 * 60 * 60);
    ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
}

$lockoutSecondsRemaining = \login_lockout_seconds_remaining($identifier);

if ($lockoutSecondsRemaining > 0) {
    $minutesRemaining = (int)ceil($lockoutSecondsRemaining / 60);
    login_respond_error(
        'Too many failed sign-in attempts. Please try again in ' . $minutesRemaining . ' minute(s).',
        429
    );
}

$conn = get_db_connection();

// Attempt staff authentication by username or email.
$staffStmt = mysqli_prepare(
    $conn,
    'SELECT u.id, u.name, u.email, u.username, u.password_hash, u.status, u.role_id, u.barangay_id, r.name AS role
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE LOWER(u.email) = LOWER(?) OR LOWER(u.username) = LOWER(?)
     LIMIT 1'
);

if ($staffStmt === false) {
    login_respond_error('Unable to process sign in right now.', 500);
}

mysqli_stmt_bind_param($staffStmt, 'ss', $identifier, $identifier);
mysqli_stmt_execute($staffStmt);
$staffResult = mysqli_stmt_get_result($staffStmt);
$staff = $staffResult instanceof mysqli_result ? mysqli_fetch_assoc($staffResult) : null;
mysqli_stmt_close($staffStmt);

if (is_array($staff) && password_verify($password, (string)($staff['password_hash'] ?? ''))) {
    if (($staff['status'] ?? 'inactive') !== 'active') {
        login_respond_error('This account is inactive.', 403);
    }

    session_regenerate_id(true);

    $barangayId = $staff['barangay_id'];
    $_SESSION['auth'] = [
        'type' => 'staff',
        'id' => (int)$staff['id'],
        'name' => (string)$staff['name'],
        'email' => (string)$staff['email'],
        'username' => (string)$staff['username'],
        'role' => (string)$staff['role'],
        'role_id' => (int)$staff['role_id'],
        'barangay_id' => $barangayId === null ? null : (int)$barangayId,
        'status' => (string)$staff['status'],
    ];

    $lastLoginStmt = mysqli_prepare($conn, 'UPDATE users SET last_login = NOW() WHERE id = ? LIMIT 1');
    if ($lastLoginStmt !== false) {
        $staffId = (int)$staff['id'];
        mysqli_stmt_bind_param($lastLoginStmt, 'i', $staffId);
        mysqli_stmt_execute($lastLoginStmt);
        mysqli_stmt_close($lastLoginStmt);
    }

    log_action((int)$staff['id'], 'LOGIN', 'info', 'Staff login for ' . (string)$staff['email']);
    \login_record_attempt($identifier, true);
    login_respond_success($_SESSION['auth']);
}

// Fall back to parent authentication (email only).
$parentStmt = mysqli_prepare(
    $conn,
    'SELECT id, name, email, password_hash, parent_type, status, barangay_id
     FROM parents
     WHERE LOWER(email) = LOWER(?)
     LIMIT 1'
);

if ($parentStmt === false) {
    login_respond_error('Unable to process sign in right now.', 500);
}

mysqli_stmt_bind_param($parentStmt, 's', $identifier);
mysqli_stmt_execute($parentStmt);
$parentResult = mysqli_stmt_get_result($parentStmt);
$parent = $parentResult instanceof mysqli_result ? mysqli_fetch_assoc($parentResult) : null;
mysqli_stmt_close($parentStmt);

if (is_array($parent) && password_verify($password, (string)($parent['password_hash'] ?? ''))) {
    if (($parent['status'] ?? 'inactive') !== 'active') {
        login_respond_error('This account is inactive.', 403);
    }

    session_regenerate_id(true);

    $barangayId = $parent['barangay_id'];
    $_SESSION['auth'] = [
        'type' => 'parent',
        'id' => (int)$parent['id'],
        'name' => (string)$parent['name'],
        'email' => (string)$parent['email'],
        'role' => 'parent',
        'parent_type' => (string)($parent['parent_type'] ?? ''),
        'barangay_id' => $barangayId === null ? null : (int)$barangayId,
        'status' => (string)$parent['status'],
    ];

    log_action(null, 'LOGIN', 'info', 'Parent login for ' . (string)$parent['email']);
    \login_record_attempt($identifier, true);
    login_respond_success($_SESSION['auth']);
}

\login_record_attempt($identifier, false);
login_respond_error('Invalid email/username or password.', 401);

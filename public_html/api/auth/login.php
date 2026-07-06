<?php

/**
 * api/auth/login.php
 * Shared login handler for staff and parents.
 */

require_once __DIR__ . '/../../includes/auth_middleware.php';
require_once __DIR__ . '/../../includes/audit_logger.php';

start_secure_session();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.',
    ]);
    exit;
}

$identifier = trim((string)($_POST['identifier'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($identifier === '' || $password === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Email or username and password are required.',
    ]);
    exit;
}

$conn = get_db_connection();
$user = null;

$staffSql = '
	SELECT
		u.id,
		u.name,
		u.email,
		u.username,
		u.password_hash,
		u.phone,
		u.barangay,
		u.status,
		u.role_id,
		r.name AS role_name
	FROM users u
	INNER JOIN roles r ON r.id = u.role_id
	WHERE u.email = ? OR u.username = ?
	LIMIT 1
';
$staffStmt = mysqli_prepare($conn, $staffSql);

if ($staffStmt !== false) {
    mysqli_stmt_bind_param($staffStmt, 'ss', $identifier, $identifier);
    mysqli_stmt_execute($staffStmt);
    $staffResult = mysqli_stmt_get_result($staffStmt);

    if ($staffResult instanceof mysqli_result) {
        $user = mysqli_fetch_assoc($staffResult) ?: null;
    }

    mysqli_stmt_close($staffStmt);
}

$accountType = 'staff';

if ($user === null) {
    $parentSql = '
		SELECT id, name, email, password_hash, phone, address, status
		FROM parents
		WHERE email = ?
		LIMIT 1
	';
    $parentStmt = mysqli_prepare($conn, $parentSql);

    if ($parentStmt !== false) {
        mysqli_stmt_bind_param($parentStmt, 's', $identifier);
        mysqli_stmt_execute($parentStmt);
        $parentResult = mysqli_stmt_get_result($parentStmt);

        if ($parentResult instanceof mysqli_result) {
            $user = mysqli_fetch_assoc($parentResult) ?: null;
            $accountType = 'parent';
        }

        mysqli_stmt_close($parentStmt);
    }
}

if ($user === null || !password_verify($password, (string)$user['password_hash'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid email, username, or password.',
    ]);
    exit;
}

if (($user['status'] ?? 'active') !== 'active') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'This account is inactive.',
    ]);
    exit;
}

session_regenerate_id(true);

if ($accountType === 'staff') {
    $_SESSION['auth'] = [
        'type' => 'staff',
        'id' => (int)$user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'username' => $user['username'],
        'phone' => $user['phone'],
        'barangay' => $user['barangay'],
        'status' => $user['status'],
        'role_id' => (int)$user['role_id'],
        'role' => $user['role_name'],
    ];

    $updateStmt = mysqli_prepare($conn, 'UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?');

    if ($updateStmt !== false) {
        $userId = (int)$user['id'];
        mysqli_stmt_bind_param($updateStmt, 'i', $userId);
        mysqli_stmt_execute($updateStmt);
        mysqli_stmt_close($updateStmt);
    }

    log_action((int)$user['id'], 'LOGIN', 'info', 'Staff login from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
} else {
    $_SESSION['auth'] = [
        'type' => 'parent',
        'id' => (int)$user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'phone' => $user['phone'],
        'address' => $user['address'],
        'status' => $user['status'],
        'role' => 'parent',
    ];

    log_action(null, 'LOGIN', 'info', 'Parent login for ' . $user['email'] . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

$redirectUrl = redirect_for_current_user($_SESSION['auth']);

echo json_encode([
    'success' => true,
    'message' => 'Login successful.',
    'redirect_url' => $redirectUrl,
    'user' => current_user(),
]);

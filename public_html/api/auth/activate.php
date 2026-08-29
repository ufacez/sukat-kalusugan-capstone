<?php

require_once __DIR__ . '/../../includes/auth_middleware.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';
require_once __DIR__ . '/../../includes/audit_logger.php';

header('Content-Type: application/json; charset=utf-8');

$conn = get_db_connection();

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$payload = json_decode((string)file_get_contents('php://input'), true) ?? $_POST;
$code = strtoupper(trim((string)($payload['code'] ?? '')));
$password = (string)($payload['password'] ?? '');
$passwordConfirm = (string)($payload['password_confirm'] ?? '');

if ($code === '' || strlen($code) !== 6) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid 6-character activation code.']);
    exit;
}

if ($password === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Password is required.']);
    exit;
}

if (strlen($password) < 8) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
    exit;
}

if (!preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Password must contain at least one uppercase letter and one number.']);
    exit;
}

if ($password !== $passwordConfirm) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
    exit;
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT id, inviter_user_id, invitee_name, invitee_email, invitee_phone, invitee_address, barangay_id, role, method, status, expires_at
     FROM invitations
     WHERE code = ?
     LIMIT 1"
);

if ($stmt === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to process activation.']);
    exit;
}

mysqli_stmt_bind_param($stmt, 's', $code);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$invitation = $result instanceof mysqli_result ? mysqli_fetch_assoc($result) : null;
mysqli_stmt_close($stmt);

if ($invitation === null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid activation code.']);
    exit;
}

if ($invitation['status'] !== 'pending') {
    $msg = match ($invitation['status']) {
        'used' => 'This code has already been used.',
        'expired' => 'This code has expired.',
        'cancelled' => 'This code was cancelled by an administrator.',
        default => 'This code is no longer valid.',
    };
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

if (new DateTimeImmutable($invitation['expires_at']) < new DateTimeImmutable('now')) {
    admin_execute("UPDATE invitations SET status = 'expired' WHERE id = ?", 'i', [$invitation['id']]);
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'This code has expired. Please ask your administrator to generate a new one.']);
    exit;
}

$username = strtolower(preg_replace('/[^a-z0-9]/', '', preg_replace('/\s+/', '', $invitation['invitee_name'])));
$baseUsername = $username;
$counter = 1;
while (admin_fetch_one('SELECT id FROM users WHERE username = ? LIMIT 1', 's', [$username]) !== null) {
    $username = $baseUsername . $counter;
    $counter++;
}

$roleId = admin_find_role_id($invitation['role']);
if ($roleId <= 0) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Invalid role configuration.']);
    exit;
}

$displayName = trim($invitation['invitee_name']);
$invitationEmail = trim((string)($invitation['invitee_email'] ?? ''));
$email = $invitationEmail !== '' ? $invitationEmail : $username . '@sukat.kalusugan';
$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$barangayId = !empty($invitation['barangay_id']) ? (int)$invitation['barangay_id'] : null;
$phone = !empty($invitation['invitee_phone']) ? $invitation['invitee_phone'] : null;
$address = !empty($invitation['invitee_address']) ? $invitation['invitee_address'] : null;

$inviteStmt = mysqli_prepare(
    $conn,
    "INSERT INTO users (name, email, username, password_hash, role_id, barangay_id, phone, address, status, access_level)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', 'full')"
);

if ($inviteStmt === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not create account.']);
    exit;
}

mysqli_stmt_bind_param($inviteStmt, 'sssssiss', $displayName, $email, $username, $passwordHash, $roleId, $barangayId, $phone, $address);
$created = mysqli_stmt_execute($inviteStmt);

if (!$created) {
    mysqli_stmt_close($inviteStmt);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not create account. Please try again.']);
    exit;
}

$newUserId = (int)mysqli_insert_id($conn);
mysqli_stmt_close($inviteStmt);

admin_execute("UPDATE invitations SET status = 'used', used_at = NOW() WHERE id = ?", 'i', [$invitation['id']]);

log_action($newUserId, 'ACCOUNT_ACTIVATED', 'info', sprintf(
    'Account activated via code for %s (%s) — role: %s',
    $displayName,
    $email,
    $invitation['role']
));

echo json_encode([
    'success' => true,
    'message' => 'Account activated successfully! You can now sign in.',
    'redirect_url' => app_url('/auth/login.php?notice=' . urlencode('Account activated. You can now sign in.')),
]);

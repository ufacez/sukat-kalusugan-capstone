<?php

declare(strict_types=1);

// scan-ok: no-auth-gate — public invitation-code endpoint by design; the
// 6-character code is the credential, validated below (hashed comparison)
// before any write. The mobile number is delivery-only.
require_once __DIR__ . '/../../includes/auth_middleware.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';
require_once __DIR__ . '/../../includes/audit_logger.php';
require_once __DIR__ . '/../../includes/display_ids.php';

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

// Private-code schema (db/20261009_invitation_private_sms.sql). Fail closed
// with a generic message so pre-migration deploys never leak state.
$hasCodeHashCol = (int)admin_scalar(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invitations' AND COLUMN_NAME = 'code_hash'",
    '',
    [],
    0
);
if ($hasCodeHashCol <= 0) {
    error_log('[SukatKalusugan] Activation blocked: invitation code_hash migration not applied.');
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Activation is temporarily unavailable. Please try again later.']);
    exit;
}

$codeHash = sk_invitation_code_hash($code);

$hasUsernameCol = (int)admin_scalar(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invitations' AND COLUMN_NAME = 'invitee_username'",
    '',
    [],
    0
);
$hasUserCodeCol = (int)admin_scalar(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invitations' AND COLUMN_NAME = 'invitee_user_code'",
    '',
    [],
    0
);
$stmt = mysqli_prepare(
    $conn,
    'SELECT id, inviter_user_id, invitee_name, invitee_email, '
    . ($hasUsernameCol > 0 ? 'invitee_username, ' : '')
    . ($hasUserCodeCol > 0 ? 'invitee_user_code, ' : '')
    . 'invitee_phone, invitee_address, barangay_id, role, method, status, expires_at, code_hash
     FROM invitations
     WHERE code_hash = ?
     LIMIT 1'
);

if ($stmt === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to process activation.']);
    exit;
}

mysqli_stmt_bind_param($stmt, 's', $codeHash);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$invitation = $result instanceof mysqli_result ? mysqli_fetch_assoc($result) : null;
mysqli_stmt_close($stmt);

// Generic message on purpose: never reveal whether a code exists.
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

// The mobile number is no longer an activation factor. Decrypt it from the
// invitation only to carry it forward to the new user account.
$invitationPhone = sk_decrypt_value(trim((string)($invitation['invitee_phone'] ?? '')));
$normalizedPhone = $invitationPhone !== '' ? admin_normalize_ph_mobile($invitationPhone) : null;

$roleId = admin_find_role_id($invitation['role']);
if ($roleId <= 0) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Invalid role configuration.']);
    exit;
}

// Code reserved when the invitation was created (new flow). Legacy
// invitations predate the migration, so reserve one now to keep the
// fallback login on the same firstname+surname+suffix convention.
$reservedUserCode = trim((string)($invitation['invitee_user_code'] ?? ''));
if ($reservedUserCode === '') {
    $reservedUserCode = sk_next_staff_code($roleId);
}

// Prefer the username the admin set on the invitation form; fall back to
// the name-based generator when blank, invalid, or the column predates
// the migration. The uniqueness loop below covers takeovers in between.
$storedUsername = trim((string)($invitation['invitee_username'] ?? ''));
if ($storedUsername !== '' && preg_match('/^[A-Za-z0-9._]{3,30}$/', $storedUsername) === 1) {
    $username = $storedUsername;
} else {
    $displayForName = sk_decrypt_value(trim((string)($invitation['invitee_name'] ?? '')));
    $nameParts = admin_split_full_name($displayForName);
    $username = admin_make_staff_login($nameParts['first'], $nameParts['last'], $reservedUserCode)['username'];
    if ($username === '') {
        $username = 'staff';
    }
}
$baseUsername = $username;
$counter = 1;
while (admin_username_in_use($username)) {
    $username = $baseUsername . $counter;
    $counter++;
}

// Invitation PII is AES-encrypted at rest — decrypt for the new account,
// then re-encrypt on the users row (same key, new envelope).
$displayName = sk_decrypt_value(trim((string)($invitation['invitee_name'] ?? '')));
$invitationEmail = trim((string)($invitation['invitee_email'] ?? ''));
$email = $invitationEmail !== '' ? $invitationEmail : $username . '@sukat.kalusugan';
if (admin_email_in_use($email)) {
    if (admin_is_internal_login_email($email)) {
        // Auto login collision in the window between invite and activation
        // — append a counter instead of failing the invitee.
        $localBase = explode('@', $email)[0];
        $emailCounter = 1;
        do {
            $email = $localBase . $emailCounter . '@sukat.kalusugan';
            $emailCounter++;
        } while (admin_email_in_use($email));
    } else {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This email is already registered. Please ask your administrator for a new invitation with a different email.']);
        exit;
    }
}
$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$barangayId = !empty($invitation['barangay_id']) ? (int)$invitation['barangay_id'] : null;
$decryptedAddress = sk_decrypt_value((string)($invitation['invitee_address'] ?? ''));
// Carry the invitation's mobile number forward, encrypted at rest.
$phone = $normalizedPhone !== null ? (string)sk_encrypt_value($normalizedPhone) : null;
$address = $decryptedAddress !== '' ? (string)sk_encrypt_value($decryptedAddress) : null;

$inviteStmt = mysqli_prepare(
    $conn,
    "INSERT INTO users (user_code, name, email, username, password_hash, role_id, barangay_id, phone, address, status, access_level)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 'full')"
);

if ($inviteStmt === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not create account.']);
    exit;
}

// Consume the staff display code reserved at invitation time in the same
// transaction as the account creation + invitation consumption. Only
// allocate a fresh code if the reserved one was somehow already used.
mysqli_begin_transaction($conn);
$userCode = $reservedUserCode;
if ((int)admin_scalar('SELECT COUNT(*) FROM users WHERE user_code = ?', 's', [$userCode], 0) > 0) {
    $userCode = sk_next_staff_code($roleId);
}

mysqli_stmt_bind_param($inviteStmt, 'sssssiiss', $userCode, $displayName, $email, $username, $passwordHash, $roleId, $barangayId, $phone, $address);
$created = mysqli_stmt_execute($inviteStmt);

if (!$created) {
    mysqli_stmt_close($inviteStmt);
    mysqli_rollback($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not create account. Please try again.']);
    exit;
}

$newUserId = (int)mysqli_insert_id($conn);
mysqli_stmt_close($inviteStmt);

admin_execute("UPDATE invitations SET status = 'used', used_at = NOW() WHERE id = ?", 'i', [$invitation['id']]);
mysqli_commit($conn);

log_action($newUserId, 'ACCOUNT_ACTIVATED', 'info', sprintf(
    // Privacy: role only — never the name/email.
    'Staff account activated via invitation code (' . $userCode . ') — role: %s',
    $invitation['role']
));

echo json_encode([
    'success' => true,
    'message' => 'Account activated successfully! You can now sign in.',
    'redirect_url' => app_url('/auth/login.php?notice=' . urlencode('Account activated. You can now sign in.')),
]);

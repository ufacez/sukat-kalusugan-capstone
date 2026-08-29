<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_permission('users.create');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    admin_redirect('/admin/invitations.php', ['notice' => 'Method not allowed.', 'type' => 'error']);
}

$firstName = trim((string)($_POST['first_name'] ?? ''));
$middleName = trim((string)($_POST['middle_name'] ?? ''));
$lastName = trim((string)($_POST['last_name'] ?? ''));
$name = admin_combine_name($firstName, $middleName, $lastName);
$emailRaw = trim((string)($_POST['email'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$address = trim((string)($_POST['address'] ?? ''));
$role = trim((string)($_POST['role'] ?? ''));
$method = trim((string)($_POST['method'] ?? 'manual'));
$barangayIdRaw = trim((string)($_POST['barangay_id'] ?? ''));
$barangayId = $barangayIdRaw !== '' ? (int)$barangayIdRaw : null;

if ($firstName === '' || !admin_is_valid_name_part($firstName, true)) {
    admin_redirect('/admin/invitations.php', ['notice' => 'Enter a valid first name (letters only, at least 2 characters).', 'type' => 'error']);
}
if ($lastName === '' || !admin_is_valid_name_part($lastName, true)) {
    admin_redirect('/admin/invitations.php', ['notice' => 'Enter a valid surname (letters only, at least 2 characters).', 'type' => 'error']);
}

if (!in_array($role, ['admin', 'nutritionist'], true)) {
    admin_redirect('/admin/invitations.php', ['notice' => 'Invalid role selected.', 'type' => 'error']);
}

if (!in_array($method, ['email', 'manual'], true)) {
    $method = 'manual';
}

if ($method === 'email') {
    $email = $emailRaw !== '' ? $emailRaw : null;
    if ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        admin_redirect('/admin/invitations.php', ['notice' => 'A valid email address is required for email invitations.', 'type' => 'error']);
    }
    $existingEmail = admin_fetch_one('SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1', 's', [$email]);
    if ($existingEmail !== null) {
        admin_redirect('/admin/invitations.php', ['notice' => 'This email is already registered to an existing user.', 'type' => 'error']);
    }
} else {
    $email = $emailRaw !== '' ? $emailRaw . '@sukat.kalusugan' : null;
}

if ($phone !== '' && !admin_is_valid_ph_mobile($phone)) {
    admin_redirect('/admin/invitations.php', ['notice' => 'Please enter a valid Philippine mobile number (09XXXXXXXXX).', 'type' => 'error']);
}
$phone = $phone !== '' ? $phone : null;

if (mb_strlen($address) > 255) {
    admin_redirect('/admin/invitations.php', ['notice' => 'Address must be 255 characters or less.', 'type' => 'error']);
}
$address = $address !== '' ? htmlspecialchars($address, ENT_QUOTES, 'UTF-8') : null;

$conn = get_db_connection();
$pendingCount = admin_scalar(
    "SELECT COUNT(*) FROM invitations WHERE status = 'pending' AND expires_at > NOW()",
    '',
    [],
    0
);

if ($pendingCount >= 3) {
    admin_redirect('/admin/invitations.php', ['notice' => 'Maximum 3 pending invitations allowed. Cancel or wait for existing ones to expire.', 'type' => 'error']);
}

$actor = current_user();
$code = strtoupper(bin2hex(random_bytes(3)));
$expiresAt = date('Y-m-d H:i:s', time() + (48 * 60 * 60));

$stmt = mysqli_prepare(
    $conn,
    'INSERT INTO invitations (inviter_user_id, invitee_name, invitee_email, invitee_phone, invitee_address, barangay_id, role, code, method, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

if ($stmt === false) {
    admin_redirect('/admin/invitations.php', ['notice' => 'Unable to create invitation.', 'type' => 'error']);
}

$inviterId = (int)($actor['id'] ?? 0);
mysqli_stmt_bind_param($stmt, 'issssissss', $inviterId, $name, $email, $phone, $address, $barangayId, $role, $code, $method, $expiresAt);
$ok = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

if (!$ok) {
    admin_redirect('/admin/invitations.php', ['notice' => 'Unable to create invitation. Please try again.', 'type' => 'error']);
}

log_action($actor['id'] ?? null, 'CREATE_INVITATION', 'info', sprintf(
    'Generated %s invitation for %s (%s) — role: %s, code: %s',
    $method,
    $name,
    $email ?? 'no email',
    $role,
    $code
));

$noticeParam = 'Invitation created. ' . ($method === 'manual'
    ? 'Share this code with ' . $name . ': ' . $code
    : 'Activation email sent to ' . $email . '.');

admin_redirect('/admin/invitations.php', ['notice' => $noticeParam]);

<?php

declare(strict_types=1);

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
$usernameRaw = trim((string)($_POST['username'] ?? ''));
$phoneRaw = trim((string)($_POST['phone'] ?? ''));
$address = trim((string)($_POST['address'] ?? ''));
$role = trim((string)($_POST['role'] ?? ''));
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

// "All barangays" is admin-only; nutritionist invitations need a barangay.
if ($role !== 'admin' && $barangayId === null) {
    admin_redirect('/admin/invitations.php?invite=open', ['notice' => 'Select a barangay — nutritionists cannot have "All barangays" scope.', 'type' => 'error']);
}

// Optional staff username (3-30 chars). Blank = auto-generated now as
// firstname+surname+staff-code suffix. Must be unique when supplied.
$username = $usernameRaw !== '' ? $usernameRaw : null;
if ($username !== null) {
    if (preg_match('/^[A-Za-z0-9._]{3,30}$/', $username) !== 1) {
        admin_redirect('/admin/invitations.php?invite=open', ['notice' => 'Enter a valid username (3-30 characters: letters, numbers, dot, or underscore).', 'type' => 'error']);
    }
    if (admin_username_in_use($username)) {
        admin_redirect('/admin/invitations.php?invite=open', ['notice' => 'This username is already taken. Use a different username.', 'type' => 'error']);
    }
}

// Mobile number is REQUIRED — the code is always delivered by SMS.
if ($phoneRaw === '' || !admin_is_valid_ph_mobile($phoneRaw)) {
    admin_redirect('/admin/invitations.php?invite=open', ['notice' => 'A valid Philippine mobile number is required (e.g. 09171234567).', 'type' => 'error']);
}
$normalizedPhone = (string)admin_normalize_ph_mobile($phoneRaw);

// Email is OPTIONAL. Validate/unique-check only when provided. Blank email
// = auto-generated internal login address; it is never an SMTP destination
// and SMS remains the delivery channel.
$emailProvided = $emailRaw !== '';
$email = $emailProvided ? $emailRaw : null;
if ($email !== null) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        admin_redirect('/admin/invitations.php?invite=open', ['notice' => 'Enter a valid email address (or leave it blank to send by SMS only).', 'type' => 'error']);
    }
    if (admin_email_in_use($email)) {
        admin_redirect('/admin/invitations.php?invite=open', ['notice' => 'This email is already registered. Use a different email address.', 'type' => 'error']);
    }
}

// Derived delivery method: SMS always, email too only when supplied.
$method = $emailProvided ? 'both' : 'sms';

if (sk_strlen($address) > 255) {
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

$hasUsernameCol = (int)admin_scalar(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invitations' AND COLUMN_NAME = 'invitee_username'",
    '',
    [],
    0
);
$hasCodeHashCol = (int)admin_scalar(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invitations' AND COLUMN_NAME = 'code_hash'",
    '',
    [],
    0
);
$hasPhoneHashCol = (int)admin_scalar(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invitations' AND COLUMN_NAME = 'invitee_phone_hash'",
    '',
    [],
    0
);
$hasReservedCodeCol = (int)admin_scalar(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invitations' AND COLUMN_NAME = 'invitee_user_code'",
    '',
    [],
    0
);
if ($hasUsernameCol <= 0 || $hasCodeHashCol <= 0 || $hasPhoneHashCol <= 0 || $hasReservedCodeCol <= 0) {
    admin_redirect('/admin/invitations.php', ['notice' => 'Database migration required (db/20261009_invitation_private_sms.sql and db/20261020_invitation_reserved_user_code.sql).', 'type' => 'error']);
}

$actor = current_user();
// Private codes: plaintext lives only in this request; only the hash is stored.
$plainCode = strtoupper(bin2hex(random_bytes(3)));
$codeHash = sk_invitation_code_hash($plainCode);
$phoneHash = sk_phone_hash($normalizedPhone);
$expiresAt = date('Y-m-d H:i:s', time() + (48 * 60 * 60));

$encName = (string)sk_encrypt_value($name);
$encPhone = (string)sk_encrypt_value($normalizedPhone);
$encAddress = $address !== null ? (string)sk_encrypt_value($address) : null;

$tries = 0;
while ($tries < 5 && (int)admin_scalar('SELECT COUNT(*) FROM invitations WHERE code_hash = ?', 's', [$codeHash], 0) > 0) {
    $plainCode = strtoupper(bin2hex(random_bytes(3)));
    $codeHash = sk_invitation_code_hash($plainCode);
    $tries++;
}

// Reserve the staff display code in the same transaction as the
// invitation insert, so a failed insert never leaks a counter value.
$roleId = admin_find_role_id($role);
if ($roleId <= 0) {
    admin_redirect('/admin/invitations.php', ['notice' => 'Invalid role configuration.', 'type' => 'error']);
}

mysqli_begin_transaction($conn);
$reservedUserCode = sk_next_staff_code($roleId);

// Auto login = firstname + surname + code suffix (middle ignored).
$autoLogin = admin_make_staff_login($firstName, $lastName, $reservedUserCode);
if ($username === null) {
    $username = $autoLogin['username'];
}
if (!$emailProvided) {
    $email = $autoLogin['email'];
}

$stmt = mysqli_prepare(
    $conn,
    'INSERT INTO invitations (inviter_user_id, invitee_name, invitee_email, invitee_username, invitee_user_code, invitee_phone, invitee_phone_hash, invitee_address, barangay_id, role, code, code_hash, method, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)'
);

if ($stmt === false) {
    mysqli_rollback($conn);
    admin_redirect('/admin/invitations.php', ['notice' => 'Unable to create invitation.', 'type' => 'error']);
}

$inviterId = (int)($actor['id'] ?? 0);
mysqli_stmt_bind_param($stmt, 'isssssssissss', $inviterId, $encName, $email, $username, $reservedUserCode, $encPhone, $phoneHash, $encAddress, $barangayId, $role, $codeHash, $method, $expiresAt);
try {
    $ok = mysqli_stmt_execute($stmt);
} catch (mysqli_sql_exception $e) {
    error_log('[SukatKalusugan] Invitation create failed: ' . $e->getMessage());
    $ok = false;
}
mysqli_stmt_close($stmt);

if (!$ok) {
    mysqli_rollback($conn);
    admin_redirect('/admin/invitations.php', ['notice' => 'Unable to create invitation. Please try again.', 'type' => 'error']);
}
$newInvitationId = (int)$conn->insert_id;
mysqli_commit($conn);

log_action($actor['id'] ?? null, 'CREATE_INVITATION', 'info', sprintf(
    // Privacy: invitation id + role only — never the invitee
    // name/email, and never the activation code.
    'Generated %s invitation #%d — role: %s',
    $method,
    $newInvitationId,
    $role
));

// Deliver via the invitee's own channels only. SMS is always sent; email
// is sent only for a supplied address — never for the auto login id.
$emailSent = false;
$smsSent = false;
if ($emailProvided) {
    try {
        require_once __DIR__ . '/../../includes/mailer.php';
        require_once __DIR__ . '/../../includes/email_template.php';
        $activateUrl = app_absolute_url('/auth/activate.php');
        $emailSent = send_mail(
            $email,
            'Sukat Kalusugan — Activate Your Staff Account',
            sprintf(
                "Hello %s,\n\nAn administrator has invited you to join Sukat Kalusugan as a %s.\n\nYour activation code: %s\n\nActivate at:\n%s\n\nEnter the code on the activation page. Expires in 48 hours. Do not share.\n\n— Sukat Kalusugan System",
                $name,
                ucfirst($role),
                $plainCode,
                $activateUrl
            ),
            email_template_action(
                'You have been invited to join Sukat Kalusugan as ' . ucfirst($role) . '.',
                'Hello ' . $name . ',',
                'Activate your staff account',
                ['An administrator has invited you to join Sukat Kalusugan as a ' . ucfirst($role) . '.', 'Open the activation page, then enter your code.'],
                'Activate account',
                $activateUrl,
                'Your activation code',
                $plainCode,
                'This code expires in 48 hours. Do not share your code.'
            )
        );
    } catch (Throwable $e) {
        error_log('[SukatKalusugan] Invitation email failed: ' . $e->getMessage());
    }
}
try {
    require_once __DIR__ . '/../../includes/sms.php';
    $smsSent = send_invite_sms($normalizedPhone, $plainCode);
} catch (Throwable $e) {
    error_log('[SukatKalusugan] Invitation SMS failed: ' . $e->getMessage());
}
unset($plainCode);

$maskedPhone = substr($normalizedPhone, 0, 4) . '***' . substr($normalizedPhone, -2);
$target = $maskedPhone . ($emailProvided ? ' / ' . $email : '');
if (!$emailProvided) {
    $target .= ' — auto login: ' . $email;
}
// The code is NEVER included in the notice (private).
if ($smsSent && (!$emailProvided || $emailSent)) {
    $noticeParam = 'Invitation sent successfully to ' . $target . '. The code stays hidden for security.';
} else {
    $failedChannels = [];
    if (!$smsSent) {
        $failedChannels[] = 'SMS';
    }
    if ($emailProvided && !$emailSent) {
        $failedChannels[] = 'email';
    }
    $noticeParam = 'Invitation created for ' . $target . '. The '
        . implode(' and ', $failedChannels)
        . ' notification could not be delivered right now — tap Resend to send the code again.';
}

admin_redirect('/admin/invitations.php', ['notice' => $noticeParam]);

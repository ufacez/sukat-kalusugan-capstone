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

// Mobile number is REQUIRED — the code is always delivered by SMS.
if ($phoneRaw === '' || !admin_is_valid_ph_mobile($phoneRaw)) {
    admin_redirect('/admin/invitations.php?invite=open', ['notice' => 'A valid Philippine mobile number is required (09XXXXXXXXX or +639XXXXXXXXX).', 'type' => 'error']);
}
$normalizedPhone = (string)admin_normalize_ph_mobile($phoneRaw);

// Email is OPTIONAL. Validate/unique-check only when provided.
$email = $emailRaw !== '' ? $emailRaw : null;
if ($email !== null) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        admin_redirect('/admin/invitations.php?invite=open', ['notice' => 'Enter a valid email address (or leave it blank to send by SMS only).', 'type' => 'error']);
    }
    if (admin_email_in_use($email)) {
        admin_redirect('/admin/invitations.php?invite=open', ['notice' => 'This email is already registered. Use a different email address.', 'type' => 'error']);
    }
}

// Derived delivery method: SMS always, email too only when we have one.
$method = $email !== null ? 'both' : 'sms';

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
if ($hasCodeHashCol <= 0 || $hasPhoneHashCol <= 0) {
    admin_redirect('/admin/invitations.php', ['notice' => 'Database migration required (db/20261009_invitation_private_sms.sql).', 'type' => 'error']);
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

$stmt = mysqli_prepare(
    $conn,
    'INSERT INTO invitations (inviter_user_id, invitee_name, invitee_email, invitee_phone, invitee_phone_hash, invitee_address, barangay_id, role, code, code_hash, method, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)'
);

if ($stmt === false) {
    admin_redirect('/admin/invitations.php', ['notice' => 'Unable to create invitation.', 'type' => 'error']);
}

$inviterId = (int)($actor['id'] ?? 0);
mysqli_stmt_bind_param($stmt, 'isssssissss', $inviterId, $encName, $email, $encPhone, $phoneHash, $encAddress, $barangayId, $role, $codeHash, $method, $expiresAt);
$ok = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

if (!$ok) {
    admin_redirect('/admin/invitations.php', ['notice' => 'Unable to create invitation. Please try again.', 'type' => 'error']);
}

log_action($actor['id'] ?? null, 'CREATE_INVITATION', 'info', sprintf(
    // Privacy: invitation id + role only — never the invitee
    // name/email, and never the activation code.
    'Generated %s invitation #%d — role: %s',
    $method,
    (int)$conn->insert_id,
    $role
));

// Deliver via the invitee's own channels only. SMS is always sent; email
// is sent only when the optional address was supplied.
$emailSent = false;
$smsSent = false;
if ($email !== null) {
    try {
        require_once __DIR__ . '/../../includes/mailer.php';
        require_once __DIR__ . '/../../includes/email_template.php';
        $activateUrl = app_absolute_url('/auth/activate.php');
        $emailSent = send_mail(
            $email,
            'Sukat Kalusugan — Activate Your Staff Account',
            sprintf(
                "Hello %s,\n\nAn administrator has invited you to join Sukat Kalusugan as a %s.\n\nYour activation code: %s\n\nActivate at:\n%s\n\nEnter the code WITH your mobile number (%s). Expires in 48 hours. Do not share.\n\n— Sukat Kalusugan System",
                $name,
                ucfirst($role),
                $plainCode,
                $activateUrl,
                $normalizedPhone
            ),
            email_template_action(
                'You have been invited to join Sukat Kalusugan as ' . ucfirst($role) . '.',
                'Hello ' . $name . ',',
                'Activate your staff account',
                ['An administrator has invited you to join Sukat Kalusugan as a ' . ucfirst($role) . '.', 'Open the activation page, then enter your code WITH your mobile number.'],
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
    $smsSent = send_invite_sms($normalizedPhone, $plainCode, $role);
} catch (Throwable $e) {
    error_log('[SukatKalusugan] Invitation SMS failed: ' . $e->getMessage());
}
unset($plainCode);

$maskedPhone = substr($normalizedPhone, 0, 4) . '***' . substr($normalizedPhone, -2);
$target = $maskedPhone . ($email !== null ? ' / ' . $email : '');
// The code is NEVER included in the notice (private).
if ($smsSent && ($email === null || $emailSent)) {
    $noticeParam = 'Invitation sent successfully to ' . $target . '. The code stays hidden for security.';
} else {
    $failedChannels = [];
    if (!$smsSent) {
        $failedChannels[] = 'SMS';
    }
    if ($email !== null && !$emailSent) {
        $failedChannels[] = 'email';
    }
    $noticeParam = 'Invitation created for ' . $target . '. The '
        . implode(' and ', $failedChannels)
        . ' notification could not be delivered right now — tap Resend to send the code again.';
}

admin_redirect('/admin/invitations.php', ['notice' => $noticeParam]);

<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();
admin_require_access('users.create');

$conn = get_db_connection();

// Auto-expire stale invitations
mysqli_query($conn, "UPDATE invitations SET status = 'expired' WHERE status = 'pending' AND expires_at < NOW()");

// Handle POST — cancellations and creation both live here. The invite
// form is a modal on this page (no separate form page).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = (string)($_POST['action'] ?? '');

    if ($formAction === 'cancel') {
        $cancelId = (int)($_POST['id'] ?? 0);
        if ($cancelId > 0) {
            $inv = admin_fetch_one("SELECT id, status FROM invitations WHERE id = ? LIMIT 1", 'i', [$cancelId]);
            if ($inv !== null && $inv['status'] === 'pending') {
                $ok = admin_execute("UPDATE invitations SET status = 'cancelled' WHERE id = ? AND status = 'pending'", 'i', [$cancelId]);
                if ($ok) {
                    $actor = current_user();
                    log_action($actor['id'] ?? null, 'DELETE_INVITATION', 'danger', sprintf(
                        // Privacy: invitation id only — never the invitee
                        // name/email, and never the activation code.
                        'Cancelled invitation #%d',
                        $cancelId
                    ));
                }
                admin_redirect('/admin/invitations.php', ['notice' => $ok ? 'Invitation cancelled.' : 'Could not cancel invitation.', 'type' => $ok ? 'success' : 'error']);
            }
            admin_redirect('/admin/invitations.php', ['notice' => 'Invitation not found or already processed.', 'type' => 'error']);
        }
    }

    // Resend = rotate: the old code hash is replaced by a brand-new one
    // (the old code stops working) and the new code goes out via the
    // invitation's email + SMS channels. The plaintext is never shown.
    if ($formAction === 'resend') {
        $resendId = (int)($_POST['id'] ?? 0);
        if ($resendId <= 0) {
            admin_redirect('/admin/invitations.php', ['notice' => 'Invalid invitation.', 'type' => 'error']);
        }
        $inv = admin_fetch_one(
            'SELECT id, invitee_name, invitee_email, invitee_phone, invitee_address, role, method, status FROM invitations WHERE id = ? LIMIT 1',
            'i',
            [$resendId]
        );
        if ($inv === null || ($inv['status'] ?? '') !== 'pending') {
            admin_redirect('/admin/invitations.php', ['notice' => 'Invitation not found or already processed.', 'type' => 'error']);
        }
        $resendEmail = trim((string)($inv['invitee_email'] ?? ''));
        $resendPhone = trim((string)($inv['invitee_phone'] ?? ''));
        // invitee_phone is stored encrypted — admin_fetch_one decrypts it
        // via sk_decrypt_pii_row, so this is the normalized plaintext here.
        $resendNorm = admin_normalize_ph_mobile($resendPhone);
        // Mobile is the only required channel; email is optional.
        if ($resendNorm === null) {
            admin_redirect('/admin/invitations.php', ['notice' => 'This invitation has no usable mobile number — cancel it and create a new one.', 'type' => 'error']);
        }
        // Auto-generated @sukat.kalusugan addresses are login identifiers
        // only — never an SMTP destination.
        $resendHasEmail = $resendEmail !== ''
            && filter_var($resendEmail, FILTER_VALIDATE_EMAIL) !== false
            && !admin_is_internal_login_email($resendEmail);
        $resendMethod = $resendHasEmail ? 'both' : 'sms';
        $newPlain = strtoupper(bin2hex(random_bytes(3)));
        $newHash = sk_invitation_code_hash($newPlain);
        $newExpiry = date('Y-m-d H:i:s', time() + (48 * 60 * 60));
        $rotated = admin_execute(
            "UPDATE invitations SET code_hash = ?, code = NULL, expires_at = ?, method = ? WHERE id = ? AND status = 'pending'",
            'sssi',
            [$newHash, $newExpiry, $resendMethod, $resendId]
        );
        if (!$rotated) {
            admin_redirect('/admin/invitations.php', ['notice' => 'Could not resend invitation.', 'type' => 'error']);
        }
        $actor = current_user();
        log_action($actor['id'] ?? null, 'RESEND_INVITATION', 'info', sprintf(
            'Resent invitation #%d with a new code — role: %s',
            $resendId,
            (string)($inv['role'] ?? 'staff')
        ));
        $resendName = (string)($inv['invitee_name'] ?? '');
        $emailOk = false;
        $smsOk = false;
        if ($resendHasEmail) {
            try {
                require_once __DIR__ . '/../includes/mailer.php';
                require_once __DIR__ . '/../includes/email_template.php';
                $activateUrl = app_absolute_url('/auth/activate.php');
                $emailOk = send_mail(
                    $resendEmail,
                    'Sukat Kalusugan — Your New Activation Code',
                    sprintf(
                        "Hello %s,\n\nYour activation code was re-issued. Your NEW code: %s\n\nActivate at:\n%s\n\nEnter the code on the activation page. The old code no longer works. Expires in 48 hours. Do not share.\n\n— Sukat Kalusugan System",
                        $resendName,
                        $newPlain,
                        $activateUrl
                    ),
                    email_template_action(
                        'Your Sukat Kalusugan activation code was re-issued.',
                        'Hello ' . $resendName . ',',
                        'Your new activation code',
                        ['An administrator re-sent your invitation. Your old code no longer works — use the new one below.'],
                        'Activate account',
                        $activateUrl,
                        'Your new activation code',
                        $newPlain,
                        'Expires in 48 hours. Do not share your code.'
                    )
                );
            } catch (Throwable $e) {
                error_log('[SukatKalusugan] Invitation resend email failed: ' . $e->getMessage());
            }
        }
        try {
            require_once __DIR__ . '/../includes/sms.php';
            $smsOk = send_invite_sms($resendNorm, $newPlain);
        } catch (Throwable $e) {
            error_log('[SukatKalusugan] Invitation resend SMS failed: ' . $e->getMessage());
        }
        unset($newPlain);
        $masked = substr($resendNorm, 0, 4) . '***' . substr($resendNorm, -2);
        $resendTargets = $masked . ($resendHasEmail ? ' / ' . $resendEmail : '');
        admin_redirect('/admin/invitations.php', ['notice' => ($smsOk && (!$resendHasEmail || $emailOk))
            ? 'New code sent to ' . $resendTargets . '. The old code no longer works.'
            : 'New code created for ' . $resendTargets . ', but we could not deliver it right now — tap Resend to try again.']);
    }

    if ($formAction === 'create') {
        $firstName = trim((string)($_POST['first_name'] ?? ''));
        $middleName = trim((string)($_POST['middle_name'] ?? ''));
        $lastName = trim((string)($_POST['last_name'] ?? ''));
        $name = admin_combine_name($firstName, $middleName, $lastName);
        $emailRaw = trim((string)($_POST['email'] ?? ''));
        $usernameRaw = trim((string)($_POST['username'] ?? ''));
        $houseNo = trim((string)($_POST['house_no'] ?? ''));
        $role = trim((string)($_POST['role'] ?? ''));
        $barangayIdRaw = trim((string)($_POST['barangay_id'] ?? ''));
        $barangayId = $barangayIdRaw !== '' ? (int)$barangayIdRaw : null;

        // Validation errors reopen the modal with values preserved.
        $inviteBack = '/admin/invitations.php?invite=open';

        if ($firstName === '' || !admin_is_valid_name_part($firstName, true)) {
            admin_flash_form_state($_POST, 'first_name');
            admin_redirect($inviteBack, ['notice' => 'Enter a valid first name (letters only, at least 2 characters).', 'type' => 'error']);
        }
        if ($lastName === '' || !admin_is_valid_name_part($lastName, true)) {
            admin_flash_form_state($_POST, 'last_name');
            admin_redirect($inviteBack, ['notice' => 'Enter a valid surname (letters only, at least 2 characters).', 'type' => 'error']);
        }
        if ($houseNo === '') {
            admin_flash_form_state($_POST, 'house_no');
            admin_redirect($inviteBack, ['notice' => 'Enter a house number or street.', 'type' => 'error']);
        }

        if (!in_array($role, ['admin', 'nutritionist'], true)) {
            admin_flash_form_state($_POST, 'role');
            admin_redirect($inviteBack, ['notice' => 'Invalid role.', 'type' => 'error']);
        }

        // "All barangays" is an admin-only scope. Nutritionist invitations
        // must be tied to a single barangay (mirrors the JS toggle).
        if ($role !== 'admin' && $barangayId === null) {
            admin_flash_form_state($_POST, 'barangay_id');
            admin_redirect($inviteBack, ['notice' => 'Select a barangay — nutritionists cannot have "All barangays" scope.', 'type' => 'error']);
        }

        // Optional staff username (3-30 chars). Blank = auto-generated
        // now as firstname+surname+staff-code suffix. Must be unique
        // across existing staff accounts when supplied.
        $username = $usernameRaw !== '' ? $usernameRaw : null;
        if ($username !== null) {
            if (preg_match('/^[A-Za-z0-9._]{3,30}$/', $username) !== 1) {
                admin_flash_form_state($_POST, 'username');
                admin_redirect($inviteBack, ['notice' => 'Enter a valid username (3-30 characters: letters, numbers, dot, or underscore).', 'type' => 'error']);
            }
            if (admin_username_in_use($username)) {
                admin_flash_form_state($_POST, 'username');
                admin_redirect($inviteBack, ['notice' => 'This username is already taken. Use a different username.', 'type' => 'error']);
            }
        }

        // Mobile number is REQUIRED — not every nutritionist has an email,
        // so the code is always delivered by SMS. Email is optional: when
        // supplied, the code is also emailed (delivery method is derived
        // automatically, there is no manual/method choice in the UI).
        $phoneRaw = trim((string)($_POST['phone'] ?? ''));
        if ($phoneRaw === '' || !admin_is_valid_ph_mobile($phoneRaw)) {
            admin_flash_form_state($_POST, 'phone');
            admin_redirect($inviteBack, ['notice' => 'A valid Philippine mobile number is required (e.g. 09171234567).', 'type' => 'error']);
        }
        $normalizedPhone = (string)admin_normalize_ph_mobile($phoneRaw);

        // Email is optional. Validate/unique-check only when provided.
        // Blank email = auto-generated internal login address; it is never
        // an SMTP destination and SMS remains the delivery channel.
        $emailProvided = $emailRaw !== '';
        $email = $emailProvided ? $emailRaw : null;
        if ($email !== null) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                admin_flash_form_state($_POST, 'email');
                admin_redirect($inviteBack, ['notice' => 'Enter a valid email address (or leave it blank to send by SMS only).', 'type' => 'error']);
            }
            if (admin_email_in_use($email)) {
                admin_flash_form_state($_POST, 'email');
                admin_redirect($inviteBack, ['notice' => 'This email is already registered. Use a different email address.', 'type' => 'error']);
            }
        }

        // Derived delivery method: SMS always, email only when supplied.
        $method = $emailProvided ? 'both' : 'sms';

        $address = trim((string)($_POST['address'] ?? ''));
        if (sk_strlen($address) > 255) {
            admin_flash_form_state($_POST, 'address');
            admin_redirect($inviteBack, ['notice' => 'Address must be 255 characters or less.', 'type' => 'error']);
        }
        $address = $address !== '' ? htmlspecialchars($address, ENT_QUOTES, 'UTF-8') : null;

        $pendingCount = admin_scalar("SELECT COUNT(*) FROM invitations WHERE status = 'pending' AND expires_at > NOW()", '', [], 0);
        if ($pendingCount >= 3) {
            admin_clear_form_state();
            admin_redirect('/admin/invitations.php', ['notice' => 'Maximum 3 pending invitations. Cancel or wait for expiry.', 'type' => 'error']);
        }

        $actor = current_user();

        // Private codes: the plaintext lives only in this request (to send
        // by email + SMS). Only its SHA-256 hash is stored — the code can
        // never be recovered from the DB or the web UI.
        $plainCode = strtoupper(bin2hex(random_bytes(3)));
        $codeHash = sk_invitation_code_hash($plainCode);
        $phoneHash = sk_phone_hash($normalizedPhone);
        $expiresAt = date('Y-m-d H:i:s', time() + (48 * 60 * 60));

        // AES-256-GCM at-rest PII (passthrough while APP_ENCRYPTION_KEY is
        // unset). Email stays plaintext (login + UNIQUE lookup key).
        $encName = (string)sk_encrypt_value($name);
        $encPhone = (string)sk_encrypt_value($normalizedPhone);
        $encAddress = $address !== null ? (string)sk_encrypt_value($address) : null;

        // The username + hash columns arrive via dated migrations. Fail
        // closed when the private-code columns are missing so we never
        // fall back to storing a plaintext code.
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
            admin_flash_form_state($_POST);
            admin_redirect($inviteBack, ['notice' => 'Database migration required (db/20261009_invitation_private_sms.sql and db/20261020_invitation_reserved_user_code.sql). Ask your administrator to import them first.', 'type' => 'error']);
        }
        // Extremely unlikely, but never store a duplicate code hash.
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
            admin_flash_form_state($_POST);
            admin_redirect($inviteBack, ['notice' => 'Invalid role configuration.', 'type' => 'error']);
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
            admin_flash_form_state($_POST);
            admin_redirect($inviteBack, ['notice' => 'Unable to create invitation.', 'type' => 'error']);
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
            admin_flash_form_state($_POST);
            admin_redirect($inviteBack, ['notice' => 'Failed to create invitation.', 'type' => 'error']);
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

        // Deliver the code over the invitee's own channels only. The code
        // is NEVER echoed back to the admin UI. SMS is always sent (the
        // mobile number is required); email is sent only for a supplied
        // address — never for the auto @sukat.kalusugan login identifier.
        $emailSent = false;
        $smsSent = false;
        if ($emailProvided) {
            try {
                require_once __DIR__ . '/../includes/mailer.php';
                require_once __DIR__ . '/../includes/email_template.php';
                // Absolute live URL (APP_URL) so the emailed button works
                // from any host. No ?code= prefill — the invitee types the
                // code from their email/SMS.
                $activateUrl = app_absolute_url('/auth/activate.php');
                $subject = 'Sukat Kalusugan — Activate Your Staff Account';
                $body = sprintf(
                    "Hello %s,\n\n" .
                    "An administrator has invited you to join Sukat Kalusugan as a %s.\n\n" .
                    "Your activation code: %s\n\n" .
                    "To activate your account, visit:\n%s\n\n" .
                    "Enter the code above and set your password.\n\n" .
                    "This code expires in 48 hours. Do not share it.\n\n" .
                    "— Sukat Kalusugan System",
                    $name,
                    ucfirst($role),
                    $plainCode,
                    $activateUrl
                );
                $htmlBody = email_template_action(
                    'You have been invited to join Sukat Kalusugan as ' . ucfirst($role) . '.',
                    'Hello ' . $name . ',',
                    'Activate your staff account',
                    [
                        'An administrator has invited you to join Sukat Kalusugan as a ' . ucfirst($role) . '.',
                        'Click the button below to open the activation page, then enter your code.',
                    ],
                    'Activate account',
                    $activateUrl,
                    'Your activation code',
                    $plainCode,
                    'This code expires in 48 hours. Do not share your code.'
                );
                $emailSent = send_mail($email, $subject, $body, $htmlBody);
            } catch (Throwable $e) {
                error_log('[SukatKalusugan] Invitation email failed: ' . $e->getMessage());
            }
        }
        try {
            require_once __DIR__ . '/../includes/sms.php';
            $smsSent = send_invite_sms($normalizedPhone, $plainCode);
        } catch (Throwable $e) {
            error_log('[SukatKalusugan] Invitation SMS failed: ' . $e->getMessage());
        }
        // Forget the plaintext immediately — only the hash is stored.
        $plainCode = '';
        unset($plainCode);

        $maskedPhone = substr($normalizedPhone, 0, 4) . '***' . substr($normalizedPhone, -2);
        $noticeTarget = $maskedPhone . ($emailProvided ? ' / ' . $email : '');
        if (!$emailProvided) {
            $noticeTarget .= ' — auto login: ' . $email;
        }
        if ($smsSent && (!$emailProvided || $emailSent)) {
            $noticeParam = 'Invitation sent successfully to ' . $noticeTarget . '. The code stays hidden for security.';
        } else {
            $failedChannels = [];
            if (!$smsSent) {
                $failedChannels[] = 'SMS';
            }
            if ($emailProvided && !$emailSent) {
                $failedChannels[] = 'email';
            }
            $noticeParam = 'Invitation created for ' . $noticeTarget . '. The '
                . implode(' and ', $failedChannels)
                . ' notification could not be delivered right now — tap Resend to send the code again.';
        }
        admin_clear_form_state();
        admin_redirect('/admin/invitations.php', ['notice' => $noticeParam]);
    }

    admin_redirect('/admin/invitations.php', ['notice' => 'Unknown action.', 'type' => 'error']);
}

// Pagination of the Invitation History is now performed client-side by the
// shared admin.js paginator (which reads `data-page-size="5"` from the table).
// Loading the full list is fine because the table is admin-only and bounded by
// the 3-pending cap already enforced on creation, so even heavy usage stays
// well under a few hundred rows. This keeps the visual pagination controls
// consistent with every other admin-table in the system.
// Codes are hashed (code_hash) and never selected or displayed. The
// legacy `code` column is history-only and stays out of every query.
$invitations = admin_fetch_all(
            "SELECT i.id, i.invitee_name, i.invitee_email, i.invitee_phone, i.barangay_id, i.role, i.status, i.expires_at, i.used_at, i.created_at,
                   u.name AS inviter_name,
                   u.user_code AS inviter_code,
            b.name AS barangay_name
     FROM invitations i
     INNER JOIN users u ON u.id = i.inviter_user_id
     LEFT JOIN barangays b ON b.id = i.barangay_id
     ORDER BY i.created_at DESC",
    '', []
);

$pendingCount = (int)admin_scalar("SELECT COUNT(*) FROM invitations WHERE status = 'pending' AND expires_at > NOW()", '', [], 0);

$barangays = admin_barangay_options();
$formState = admin_take_form_state();
$old = $formState['old'];
$formErrorField = $formState['error_field'];
$formErrorNotice = trim((string)($_GET['notice'] ?? ''));
$inviteOpen = ($_GET['invite'] ?? '') === 'open';

$actions = '<a class="admin-btn-secondary" href="' . admin_e(app_url('/admin/users.php')) . '">' . admin_action_icon('back') . ' Users</a>'
    . ' <button class="admin-btn is-create" type="button" data-invite-open>' . admin_action_icon('add') . ' New Invitation</button>';

admin_layout_start('Staff Invitations', 'All staff invitations. Codes expire after 48 hours.', 'invitations', $actions);
?>
<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">Invitations</h2>
            <p class="admin-section-subtitle">Recent staff invitations and their status.</p>
        </div>
    </div>

    <div class="admin-table-wrap">
        <table class="admin-table" id="invitations-table" data-page-size="5">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Mobile</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Invited By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invitations as $invIndex => $inv): ?>
                    <tr<?php echo admin_paged_row_attr($invIndex, 5); ?>>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <span class="admin-avatar" style="background:<?php echo admin_avatar_color($inv['invitee_name']); ?>;width:32px;height:32px;font-size:0.7rem;"><?php echo admin_initials($inv['invitee_name']); ?></span>
                                <div>
                                    <div style="font-weight:700;"><?php echo admin_e($inv['invitee_name']); ?></div>
                                    <?php if ($inv['invitee_email'] !== null): ?>
                                        <div class="admin-mini"><?php echo admin_e($inv['invitee_email']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td><span class="admin-mini"><?php echo admin_e($inv['invitee_email'] ?? '—'); ?></span></td>
                        <td><span class="admin-mini"><?php
                            $ph = (string)($inv['invitee_phone'] ?? '');
                            echo $ph !== '' ? admin_e(substr($ph, 0, 4) . '***' . substr($ph, -2)) : '—';
                        ?></span></td>
                        <td><span class="admin-pill <?php echo $inv['role'] === 'admin' ? 'is-update' : 'is-create'; ?>"><?php echo admin_e(ucfirst($inv['role'])); ?></span></td>
                        <td>
                            <?php
                            // CRUD action-pill mapping for invitation status:
                            //   pending   → is-update  (orange, awaiting activation)
                            //   used      → is-create  (green,  successfully activated)
                            //   expired   → is-muted   (gray,   no longer valid)
                            //   cancelled → is-delete  (red,    operator cancelled it)
                            $statusClass = match ($inv['status']) {
                                'pending'   => 'is-update',
                                'used'      => 'is-create',
                                'expired'   => 'is-muted',
                                'cancelled' => 'is-delete',
                                default     => 'is-muted',
                            };
                            ?>
                            <span class="admin-pill <?php echo $statusClass; ?>"><?php echo admin_e(ucfirst($inv['status'])); ?></span>
                        </td>
                        <td><?php echo admin_e($inv['inviter_name']); ?> <?php echo sk_code_badge((string)($inv['inviter_code'] ?? '')); ?></td>
                        <td>
                            <?php if ($inv['status'] === 'pending'): ?>
                            <div class="admin-actions">
                                <form method="post" action="<?php echo admin_e(app_url('/admin/invitations.php')); ?>" data-admin-confirm="Send a NEW code for <?php echo admin_e($inv['invitee_name']); ?>? The old code stops working." style="display:inline;">
                                    <input type="hidden" name="action" value="resend">
                                    <input type="hidden" name="id" value="<?php echo (int)$inv['id']; ?>">
                                    <button class="admin-icon-btn is-update" title="Resend (new code via email + SMS)" type="submit" aria-label="Resend invitation with a new code">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
                                    </button>
                                </form>
                                <form method="post" action="<?php echo admin_e(app_url('/admin/invitations.php')); ?>" data-admin-confirm="Cancel invitation for <?php echo admin_e($inv['invitee_name']); ?>?" style="display:inline;">
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="id" value="<?php echo (int)$inv['id']; ?>">
                                    <button class="admin-icon-btn is-delete" title="Cancel" type="submit" aria-label="Cancel invitation">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                    </button>
                                </form>
                            </div>
                            <?php else: ?>
                            <?php
                            // Finished invitations (used / expired / cancelled):
                            // show a read-only details modal. Codes are NEVER
                            // included — only hashed and never displayed.
                            $infoPhone = (string)($inv['invitee_phone'] ?? '');
                            $infoPhone = $infoPhone !== '' ? substr($infoPhone, 0, 4) . '***' . substr($infoPhone, -2) : '—';
                            $fmtDate = static function ($dt): string {
                                $dt = (string)($dt ?? '');
                                if ($dt === '' || str_starts_with($dt, '0000')) {
                                    return '—';
                                }
                                $ts = strtotime($dt);
                                return $ts ? date('M j, Y g:i A', $ts) : '—';
                            };
                            $infoPayload = [
                                'name'     => (string)($inv['invitee_name'] ?? ''),
                                'email'    => (string)($inv['invitee_email'] ?? '') !== '' ? (string)$inv['invitee_email'] : '—',
                                'phone'    => $infoPhone,
                                'role'     => ucfirst((string)($inv['role'] ?? '')),
                                'barangay' => (string)($inv['barangay_name'] ?? '') !== '' ? (string)$inv['barangay_name'] : 'All barangays',
                                'status'   => ucfirst((string)($inv['status'] ?? '')),
                                'inviter'  => (string)($inv['inviter_name'] ?? ''),
                                'invited'  => $fmtDate($inv['created_at'] ?? null),
                                'expires'  => $fmtDate($inv['expires_at'] ?? null),
                                'used'     => $fmtDate($inv['used_at'] ?? null),
                            ];
                            ?>
                            <div class="admin-actions">
                                <button type="button" class="admin-icon-btn" title="View details" aria-label="View invitation details"
                                        data-invite-info
                                        data-invite="<?php echo admin_e(json_encode($infoPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?>">
                                    <?php echo admin_action_icon('view'); ?>
                                </button>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <!--
        Pagination for the Invitation History is handled by the shared
        admin.js paginator (see public_html/assets/js/admin.js) which
        reads data-page-size="5" from this table. This keeps the visual
        pagination consistent with every other admin-table in the system.
    -->
</section>

<?php if (count($invitations) === 0): ?>
<section class="admin-section">
    <p class="admin-section-subtitle" style="margin:0;">No invitations yet. Use the New Invitation button above to create one.</p>
</section>
<?php endif; ?>

<div class="admin-modal-overlay" id="invite-overlay"<?php echo $inviteOpen ? '' : ' hidden'; ?>>
    <div class="admin-modal admin-modal--form invite-modal" role="dialog" aria-modal="true" aria-labelledby="invite-title">
        <div class="admin-modal-head">
            <div>
                <h3 id="invite-title">Invite staff</h3>
                <p class="invite-modal-subtitle">They’ll get a link to finish setting up their own account.</p>
            </div>
            <button class="admin-modal-close" data-invite-close type="button" aria-label="Close">&times;</button>
        </div>
        <div class="admin-modal-body">
            <?php if ($pendingCount >= 3): ?>
            <div class="admin-flash is-error">Maximum 3 pending invitations reached. Cancel or wait for expiry before creating a new one.</div>
            <?php endif; ?>
            <form class="admin-form-grid" method="post" data-validate-form data-role-barangay-form action="<?php echo admin_e(app_url('/admin/invitations.php')); ?>">
                <input type="hidden" name="action" value="create">

                <div class="admin-field-wide admin-flash is-error" data-validate-banner style="display:none;"></div>

                <section class="invite-form-card admin-field-wide">
                    <h4 class="invite-form-card-title"><?php echo admin_sidebar_icon('users'); ?> General information</h4>
                    <div class="admin-field-row">
                        <label class="admin-field<?php echo $formErrorField === 'first_name' ? ' is-invalid' : ''; ?>">
                            <span>First name<span class="admin-required">*</span></span>
                            <input id="invite_first_name" name="first_name" required maxlength="60" data-validate="name" data-label="First name" placeholder="Enter first name" value="<?php echo admin_e(admin_old_value($old, 'first_name')); ?>">
                            <span class="admin-field-message"><?php echo $formErrorField === 'first_name' ? admin_e($formErrorNotice) : ''; ?></span>
                        </label>
                        <label class="admin-field">
                            <span>Middle name</span>
                            <input id="invite_middle_name" name="middle_name" maxlength="60" data-validate="name" data-label="Middle name" placeholder="Enter middle name" value="<?php echo admin_e(admin_old_value($old, 'middle_name')); ?>">
                            <span class="admin-field-message"></span>
                        </label>
                        <label class="admin-field<?php echo $formErrorField === 'last_name' ? ' is-invalid' : ''; ?>">
                            <span>Surname<span class="admin-required">*</span></span>
                            <input id="invite_last_name" name="last_name" required maxlength="60" data-validate="name" data-label="Surname" placeholder="Enter surname" value="<?php echo admin_e(admin_old_value($old, 'last_name')); ?>">
                            <span class="admin-field-message"><?php echo $formErrorField === 'last_name' ? admin_e($formErrorNotice) : ''; ?></span>
                        </label>
                    </div>
                </section>

                <section class="invite-form-card admin-field-wide">
                    <h4 class="invite-form-card-title"><?php echo admin_sidebar_icon('clipboard'); ?> Contact</h4>
                    <div class="admin-field-row invite-contact-row">
                    <label class="admin-field<?php echo $formErrorField === 'email' ? ' is-invalid' : ''; ?>">
                        <span>Email address</span>
                        <input name="email" id="invite-email-input" type="email" maxlength="150" placeholder="Enter email address" value="<?php echo admin_e(admin_old_value($old, 'email')); ?>" style="width:100%;">
                        <span class="admin-field-message"><?php echo $formErrorField === 'email' ? admin_e($formErrorNotice !== '' ? $formErrorNotice : 'Enter a valid email address.') : ''; ?></span>
                    </label>
                        <label class="admin-field<?php echo $formErrorField === 'phone' ? ' is-invalid' : ''; ?>">
                            <span>Mobile number<span class="admin-required">*</span></span>
                            <input name="phone" id="invite-phone" type="tel" required maxlength="14" inputmode="tel" placeholder="Enter mobile number" data-validate="phone-ph" value="<?php echo admin_e(admin_old_value($old, 'phone')); ?>">
                            <span class="admin-field-message"><?php echo $formErrorField === 'phone' ? admin_e($formErrorNotice) : ''; ?></span>
                        </label>
                    </div>
                </section>

                <section class="invite-form-card admin-field-wide">
                    <h4 class="invite-form-card-title"><?php echo admin_sidebar_icon('key'); ?> Access</h4>
                    <div class="admin-field-row">
                        <label class="admin-field<?php echo $formErrorField === 'username' ? ' is-invalid' : ''; ?>">
                            <span>Username</span>
                            <input name="username" id="invite-username" type="text" maxlength="30" autocomplete="off" placeholder="Enter username" data-validate="username" data-label="Username" value="<?php echo admin_e(admin_old_value($old, 'username')); ?>">
                            <span class="admin-field-message"><?php echo $formErrorField === 'username' ? admin_e($formErrorNotice !== '' ? $formErrorNotice : 'This username is already taken. Use a different username.') : ''; ?></span>
                        </label>
                        <label class="admin-field<?php echo $formErrorField === 'role' ? ' is-invalid' : ''; ?>">
                            <span>Role<span class="admin-required">*</span></span>
                            <select name="role" required>
                                <option value="nutritionist"<?php echo admin_old_value($old, 'role', 'nutritionist') === 'nutritionist' ? ' selected' : ''; ?>>Nutritionist</option>
                                <option value="admin"<?php echo admin_old_value($old, 'role', '') === 'admin' ? ' selected' : ''; ?>>Admin</option>
                            </select>
                            <span class="admin-field-message"><?php echo $formErrorField === 'role' ? admin_e($formErrorNotice) : ''; ?></span>
                        </label>
                    </div>
                    <div class="admin-field-row">
                        <label class="admin-field">
                            <span>Barangay scope<span class="admin-required">*</span></span>
                            <select name="barangay_id">
                                <option value="">-- All barangays --</option>
                                <?php foreach ($barangays as $b): ?>
                                    <option value="<?php echo (int)$b['id']; ?>"<?php echo admin_old_value($old, 'barangay_id', '') !== '' && (int)admin_old_value($old, 'barangay_id') === (int)$b['id'] ? ' selected' : ''; ?>><?php echo admin_e($b['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                </section>

                <section class="invite-form-card invite-address-card admin-field-wide">
                    <h4 class="invite-form-card-title"><?php echo admin_sidebar_icon('barangays'); ?> Home address</h4>
                    <div class="admin-address-picker" data-psgc-picker data-psgc-address-target="invite_address">
                        <label class="admin-field">
                            <span>Province</span>
                            <select data-psgc="province"><option value="">Loading provinces…</option></select>
                        </label>
                        <label class="admin-field">
                            <span>City / Municipality</span>
                            <select data-psgc="city" disabled><option value="">-- Select province first --</option></select>
                        </label>
                        <label class="admin-field">
                            <span>Barangay</span>
                            <select data-psgc="barangay" disabled><option value="">-- Select city/municipality first --</option></select>
                        </label>
                        <label class="admin-field">
                            <span>Local area / Purok<span class="admin-required">*</span></span>
                            <select data-psgc="area" disabled><option value="">-- Select barangay first --</option></select>
                        </label>
                    </div>
                    <label class="admin-field invite-house-field<?php echo $formErrorField === 'house_no' ? ' is-invalid' : ''; ?>" style="margin-top:10px;">
                        <span>House no. / street<span class="admin-required">*</span></span>
                        <input data-psgc="street" name="house_no" required placeholder="143 Mabini St.">
                        <span class="admin-field-message"><?php echo $formErrorField === 'house_no' ? admin_e($formErrorNotice) : ''; ?></span>
                    </label>
                    <div class="admin-address-status" data-psgc-status></div>
                    <div class="admin-address-preview" role="status" aria-live="polite">
                        <?php echo admin_sidebar_icon('barangays'); ?>
                        <div>
                            <span class="admin-address-preview-label">Full address</span>
                            <textarea id="invite_address" name="address" maxlength="255" rows="2" readonly placeholder="Your complete address will appear here."><?php echo admin_e(admin_old_value($old, 'address')); ?></textarea>
                        </div>
                    </div>
                </section>

                <div class="admin-field admin-field-wide invite-actions" style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;">
                    <button class="admin-btn-secondary" type="button" data-invite-close>Cancel</button>
                    <button class="admin-btn is-create" type="submit" <?php echo $pendingCount >= 3 ? 'disabled style="opacity:.5;cursor:not-allowed;"' : ''; ?>><?php echo admin_action_icon('add'); ?> Generate Invitation</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.invite-modal{max-width:min(760px,calc(100vw - 24px));border-radius:18px}
.invite-modal .admin-modal-head{padding:28px 30px 20px;align-items:flex-start}
.invite-modal .admin-modal-head h3{font-size:1.35rem;letter-spacing:-.02em}
.invite-modal-subtitle{margin:5px 0 0;color:var(--admin-muted);font-size:.9rem}
.invite-modal .admin-modal-body{padding:22px 30px 28px;background:var(--admin-page-gradient-start)}
.invite-modal .admin-form-grid{gap:18px}
.invite-form-card{padding:20px;border:1px solid var(--admin-border);border-radius:14px;background:var(--admin-surface)}
.invite-form-card-title{display:flex;align-items:center;gap:9px;margin:0 0 16px;font-size:.72rem;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:var(--admin-muted)}
.invite-form-card-title svg{width:17px;height:17px;color:var(--admin-primary)}
.invite-form-card .admin-field-row{gap:12px}
.invite-form-card .admin-field{min-width:0}
.invite-form-card input,.invite-form-card select,.invite-form-card textarea{border-radius:10px}
.invite-address-card .admin-address-picker{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.invite-address-card .admin-address-picker .admin-field:nth-child(3){grid-column:1}
.invite-address-card .admin-address-picker .admin-field:nth-child(4){grid-column:2}
.invite-address-card .admin-address-picker + .invite-house-field{margin-top:14px!important}
.invite-address-card textarea{min-height:0;height:1.35em}
.invite-modal .admin-field-message{font-size:.66rem;line-height:1.15;min-height:.75em}
.invite-actions{margin:4px -30px -28px;padding:16px 30px 18px;border-top:1px solid var(--admin-border);background:var(--admin-surface)}
@media(max-width:620px){
  .invite-modal .admin-modal-head{padding:22px 18px 16px}
  .invite-modal .admin-modal-body{padding:16px 18px 20px}
  .invite-form-card{padding:16px}
  .invite-actions{margin-left:-18px;margin-right:-18px;margin-bottom:-20px;padding-left:18px;padding-right:18px}
  .invite-address-card .admin-address-picker{grid-template-columns:minmax(0,1fr)}
  .invite-address-card .admin-address-picker .admin-field:nth-child(3),
  .invite-address-card .admin-address-picker .admin-field:nth-child(4){grid-column:auto}
}
.invite-info-section + .invite-info-section{margin-top:14px;padding-top:14px;border-top:1px solid var(--admin-border)}
.invite-info-heading{margin:0 0 10px;font-size:0.7rem;font-weight:800;letter-spacing:0.09em;text-transform:uppercase;color:var(--admin-muted)}
.invite-info-list{display:grid;grid-template-columns:auto minmax(0,1fr);gap:8px 18px;margin:0;font-size:0.87rem;align-items:baseline}
.invite-info-list dt{color:var(--admin-muted);font-weight:600;white-space:nowrap}
.invite-info-list dd{margin:0;color:var(--admin-text);word-break:break-word}
</style>

<div class="admin-modal-overlay" id="invite-info-overlay" hidden>
    <div class="admin-modal" style="max-width:460px;" role="dialog" aria-modal="true" aria-labelledby="invite-info-title">
        <div class="admin-modal-head">
            <h3 id="invite-info-title">Invitation details</h3>
            <button class="admin-modal-close" data-invite-info-close type="button" aria-label="Close">&times;</button>
        </div>
        <div class="admin-modal-body">
            <section class="invite-info-section">
                <h4 class="invite-info-heading">Invitee</h4>
                <dl class="invite-info-list">
                    <dt>Name</dt><dd id="ii-name">&mdash;</dd>
                    <dt>Email</dt><dd id="ii-email">&mdash;</dd>
                    <dt>Mobile</dt><dd id="ii-phone">&mdash;</dd>
                    <dt>Status</dt><dd id="ii-status">&mdash;</dd>
                </dl>
            </section>
            <section class="invite-info-section">
                <h4 class="invite-info-heading">Role &amp; access</h4>
                <dl class="invite-info-list">
                    <dt>Role</dt><dd id="ii-role">&mdash;</dd>
                    <dt>Barangay scope</dt><dd id="ii-barangay">&mdash;</dd>
                </dl>
            </section>
            <section class="invite-info-section">
                <h4 class="invite-info-heading">Timeline</h4>
                <dl class="invite-info-list">
                    <dt>Invited by</dt><dd id="ii-inviter">&mdash;</dd>
                    <dt>Invited on</dt><dd id="ii-invited">&mdash;</dd>
                    <dt>Expires on</dt><dd id="ii-expires">&mdash;</dd>
                    <dt>Activated on</dt><dd id="ii-used">&mdash;</dd>
                </dl>
            </section>
            <div style="display:flex;justify-content:flex-end;margin-top:16px;">
                <button class="admin-btn-secondary" data-invite-info-close type="button">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    var overlay = document.getElementById('invite-info-overlay');
    if (!overlay) return;
    function fill(id, value) {
        var el = document.getElementById(id);
        if (el) el.textContent = (value === undefined || value === null || value === '') ? '—' : value;
    }
    function openInfo(btn) {
        var d = {};
        try { d = JSON.parse(btn.getAttribute('data-invite') || '{}'); } catch (e) { d = {}; }
        fill('ii-name', d.name);
        fill('ii-email', d.email);
        fill('ii-phone', d.phone);
        fill('ii-role', d.role);
        fill('ii-barangay', d.barangay);
        fill('ii-status', d.status);
        fill('ii-inviter', d.inviter);
        fill('ii-invited', d.invited);
        fill('ii-expires', d.expires);
        fill('ii-used', d.used);
        overlay.hidden = false;
        document.body.style.overflow = 'hidden';
    }
    function closeInfo() {
        overlay.hidden = true;
        document.body.style.overflow = '';
    }
    document.querySelectorAll('[data-invite-info]').forEach(function(b) {
        b.addEventListener('click', function() { openInfo(b); });
    });
    overlay.querySelectorAll('[data-invite-info-close]').forEach(function(b) {
        b.addEventListener('click', closeInfo);
    });
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closeInfo();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && !overlay.hidden) closeInfo();
    });
})();
</script>

<script>
(function(){
    var overlay = document.getElementById('invite-overlay');
    if (!overlay) return;
    function openInvite() {
        overlay.hidden = false;
        document.body.style.overflow = 'hidden';
        var f = document.getElementById('invite_first_name');
        if (f) f.focus();
    }
    function closeInvite() {
        overlay.hidden = true;
        document.body.style.overflow = '';
    }
    document.querySelectorAll('[data-invite-open]').forEach(function(b) {
        b.addEventListener('click', openInvite);
    });
    overlay.querySelectorAll('[data-invite-close]').forEach(function(b) {
        b.addEventListener('click', closeInvite);
    });
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closeInvite();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && !overlay.hidden) closeInvite();
    });
})();
(function(){
    // Email is now a plain required email field (no manual-mode prefix
    // hack). Clear a server-rendered duplicate-email error once the user
    // edits the address so the submit guard does not keep blocking.
    var emailInput = document.getElementById('invite-email-input');
    if (emailInput) {
        emailInput.addEventListener('input', function(){
            var label = emailInput.closest('.admin-field');
            if (label && label.classList.contains('is-invalid')) {
                label.classList.remove('is-invalid');
                var msg = label.querySelector('.admin-field-message');
                if (msg) msg.textContent = '';
            }
        });
    }
})();
</script>

<?php
admin_layout_end();

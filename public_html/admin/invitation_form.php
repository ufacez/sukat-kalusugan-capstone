<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();
require_permission('users.create');

$conn = get_db_connection();

// Auto-expire stale invitations (same as index so the slots count stays accurate).
mysqli_query($conn, "UPDATE invitations SET status = 'expired' WHERE status = 'pending' AND expires_at < NOW()");

// Handle create POST — success lands back on the index table,
// validation errors stay on this form page.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $middleName = trim((string)($_POST['middle_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    $name = admin_combine_name($firstName, $middleName, $lastName);
    $emailRaw = trim((string)($_POST['email'] ?? ''));
    $role = trim((string)($_POST['role'] ?? ''));
    $method = trim((string)($_POST['method'] ?? 'manual'));
    $barangayIdRaw = trim((string)($_POST['barangay_id'] ?? ''));
    $barangayId = $barangayIdRaw !== '' ? (int)$barangayIdRaw : null;

    if ($firstName === '' || !admin_is_valid_name_part($firstName, true)) {
        admin_redirect('/admin/invitation_form.php', ['notice' => 'Enter a valid first name (letters only, at least 2 characters).', 'type' => 'error']);
    }
    if ($lastName === '' || !admin_is_valid_name_part($lastName, true)) {
        admin_redirect('/admin/invitation_form.php', ['notice' => 'Enter a valid surname (letters only, at least 2 characters).', 'type' => 'error']);
    }

    if (!in_array($role, ['admin', 'nutritionist'], true)) {
        admin_redirect('/admin/invitation_form.php', ['notice' => 'Invalid role.', 'type' => 'error']);
    }

    if (!in_array($method, ['email', 'manual'], true)) {
        $method = 'manual';
    }

    if ($method === 'email') {
        $email = $emailRaw !== '' ? $emailRaw : null;
        if ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            admin_redirect('/admin/invitation_form.php', ['notice' => 'A valid email address is required for email invitations.', 'type' => 'error']);
        }
        if (admin_email_in_use($email)) {
            admin_redirect('/admin/invitation_form.php', ['notice' => 'This email is already registered. Use a different email address.', 'type' => 'error']);
        }
    } else {
        $email = $emailRaw !== '' ? $emailRaw . '@sukat.kalusugan' : null;
    }

    $phone = trim((string)($_POST['phone'] ?? ''));
    if ($phone !== '' && !admin_is_valid_ph_mobile($phone)) {
        admin_redirect('/admin/invitation_form.php', ['notice' => 'Please enter a valid Philippine mobile number (09XXXXXXXXX).', 'type' => 'error']);
    }
    $phone = $phone !== '' ? $phone : null;

    $address = trim((string)($_POST['address'] ?? ''));
    if (mb_strlen($address) > 255) {
        admin_redirect('/admin/invitation_form.php', ['notice' => 'Address must be 255 characters or less.', 'type' => 'error']);
    }
    $address = $address !== '' ? htmlspecialchars($address, ENT_QUOTES, 'UTF-8') : null;

    $pendingCount = admin_scalar("SELECT COUNT(*) FROM invitations WHERE status = 'pending' AND expires_at > NOW()", '', [], 0);
    if ($pendingCount >= 3) {
        admin_redirect('/admin/invitations.php', ['notice' => 'Maximum 3 pending invitations. Cancel or wait for expiry.', 'type' => 'error']);
    }

    $actor = current_user();
    $code = strtoupper(bin2hex(random_bytes(3)));
    $expiresAt = date('Y-m-d H:i:s', time() + (48 * 60 * 60));

    $stmt = mysqli_prepare($conn, 'INSERT INTO invitations (inviter_user_id, invitee_name, invitee_email, invitee_phone, invitee_address, barangay_id, role, code, method, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if ($stmt === false) {
        admin_redirect('/admin/invitation_form.php', ['notice' => 'Unable to create invitation.', 'type' => 'error']);
    }
    $inviterId = (int)($actor['id'] ?? 0);
    mysqli_stmt_bind_param($stmt, 'issssissss', $inviterId, $name, $email, $phone, $address, $barangayId, $role, $code, $method, $expiresAt);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    if (!$ok) {
        admin_redirect('/admin/invitation_form.php', ['notice' => 'Failed to create invitation.', 'type' => 'error']);
    }

    log_action($actor['id'] ?? null, 'CREATE_INVITATION', 'info', sprintf('Generated %s invitation for %s (%s) — role: %s, code: %s', $method, $name, $email ?? 'no email', $role, $code));

    $emailSent = false;
    if ($method === 'email' && $email !== null) {
        try {
            require_once __DIR__ . '/../includes/mailer.php';
            $activateUrl = app_url('/auth/activate.php?code=' . $code);
            $subject = 'Sukat Kalusugan — Activate Your Staff Account';
            $body = sprintf(
                "Hello %s,\n\n" .
                "An administrator has invited you to join Sukat Kalusugan as a %s.\n\n" .
                "Your activation code: %s\n\n" .
                "To activate your account, visit:\n%s\n\n" .
                "Or go to the login page and click \"Have an activation code?\"\n" .
                "Enter the code above and set your password.\n\n" .
                "This code expires in 48 hours.\n\n" .
                "— Sukat Kalusugan System",
                $name,
                ucfirst($role),
                $code,
                $activateUrl
            );
            $emailSent = send_mail($email, $subject, $body);
        } catch (Throwable $e) {
            error_log('[SukatKalusugan] Invitation email failed: ' . $e->getMessage());
        }
    }

    $noticeParam = 'Invitation created. ' . ($method === 'manual'
        ? 'Share this code with ' . $name . ': ' . $code
        : ($emailSent ? 'Activation email sent to ' . $email . '.' : 'Invitation created. Share this code with ' . $name . ': ' . $code . ' (email could not be sent — share manually).'));
    admin_redirect('/admin/invitations.php', ['notice' => $noticeParam]);
}

$barangays = admin_barangay_options();
$pendingCount = (int)admin_scalar("SELECT COUNT(*) FROM invitations WHERE status = 'pending' AND expires_at > NOW()", '', [], 0);

$actions = '<a class="admin-btn-secondary" href="' . admin_e(app_url('/admin/invitations.php')) . '">' . admin_action_icon('back') . ' Invitations</a>';

admin_layout_start('New Invitation', 'Generate an activation code for a new staff member. Codes expire after 48 hours.', 'invitations', $actions);
?>
<?php if ($pendingCount >= 3): ?>
<div class="admin-flash is-error">Maximum 3 pending invitations reached. Cancel or wait for expiry before creating a new one.</div>
<?php endif; ?>

<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">Invite Staff</h2>
            <p class="admin-section-subtitle">Generate an activation code for a new staff member. Codes expire after 48 hours.</p>
        </div>
    </div>

    <form class="admin-form-grid" method="post" data-validate-form action="<?php echo admin_e(app_url('/admin/invitation_form.php')); ?>">

        <div class="admin-field-wide admin-flash is-error" data-validate-banner style="display:none;"></div>

        <div class="admin-field-wide">
            <div class="admin-field-row">
                <label class="admin-field">
                    <span>First name<span class="admin-required">*</span></span>
                    <input id="invite_first_name" name="first_name" required maxlength="60" data-validate="name" data-label="First name" placeholder="Juan" value="<?php echo admin_e((string)($_GET['first_name'] ?? '')); ?>">
                    <span class="admin-field-message"></span>
                </label>
                <label class="admin-field">
                    <span>Middle name</span>
                    <input id="invite_middle_name" name="middle_name" maxlength="60" data-validate="name" data-label="Middle name" placeholder="Santos">
                    <span class="admin-field-message"></span>
                </label>
                <label class="admin-field">
                    <span>Surname<span class="admin-required">*</span></span>
                    <input id="invite_last_name" name="last_name" required maxlength="60" data-validate="name" data-label="Surname" placeholder="Dela Cruz">
                    <span class="admin-field-message"></span>
                </label>
            </div>
        </div>

        <div class="admin-field-wide">
            <label class="admin-field">
                <span>Email address</span>
                <div id="invite-email-wrap" style="display:flex;align-items:stretch;border:1px solid var(--admin-border);border-radius:8px;overflow:hidden;background:var(--admin-surface);transition:border-color .15s,box-shadow .15s;">
                    <input name="email" id="invite-email-input" type="text" placeholder="auto-generated from name" style="flex:1;border:none;padding:10px 14px;background:transparent;font-size:0.85rem;min-width:0;outline:none;">
                    <span id="invite-email-domain" style="display:flex;align-items:center;padding:0 14px;color:var(--admin-muted);font-size:0.85rem;white-space:nowrap;background:var(--admin-search-bg);border-left:1px solid var(--admin-border);font-weight:600;letter-spacing:0.02em;">@sukat.kalusugan</span>
                </div>
                <span class="admin-field-message"></span>
            </label>
        </div>

        <div class="admin-field-wide">
            <div class="admin-field-row">
                <label class="admin-field">
                    <span>Mobile number</span>
                    <input name="phone" id="invite-phone" type="tel" maxlength="11" inputmode="numeric" placeholder="09XXXXXXXXX" data-validate="phone-ph">
                    <span class="admin-field-message"></span>
                </label>
                <label class="admin-field">
                    <span>Role<span class="admin-required">*</span></span>
                    <select name="role" required>
                        <option value="nutritionist">Nutritionist</option>
                        <option value="admin">Admin</option>
                    </select>
                </label>
            </div>
        </div>

        <div class="admin-field-wide">
            <span style="font-size:0.88rem;font-weight:700;color:var(--admin-text);">Home address</span>
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
            </div>
            <label class="admin-field" style="margin-top:10px;">
                <span>House no. / street</span>
                <input data-psgc="street" placeholder="143 Purok 6">
            </label>
            <div class="admin-address-status" data-psgc-status></div>
            <label class="admin-field" style="margin-top:10px;">
                <span>Full address</span>
                <textarea id="invite_address" name="address" maxlength="255" rows="2"></textarea>
            </label>
        </div>

        <div class="admin-field-wide">
            <div class="admin-field-row">
                <label class="admin-field">
                    <span>Barangay scope</span>
                    <select name="barangay_id">
                        <option value="">-- All barangays --</option>
                        <?php foreach ($barangays as $b): ?>
                            <option value="<?php echo (int)$b['id']; ?>"><?php echo admin_e($b['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="admin-field">
                    <span>Delivery method<span class="admin-required">*</span></span>
                    <select name="method" id="invite-method" required>
                        <option value="manual">Manual (share code in person)</option>
                        <option value="email">Email</option>
                    </select>
                </label>
            </div>
        </div>

        <div class="admin-field admin-field-wide" style="align-content:end;">
            <button class="admin-btn is-create" type="submit" <?php echo $pendingCount >= 3 ? 'disabled style="opacity:.5;cursor:not-allowed;"' : ''; ?>><?php echo admin_action_icon('add'); ?> Generate Invitation</button>
        </div>
    </form>
</section>

<script>
(function(){
    var firstNameInput = document.getElementById('invite_first_name');
    var lastNameInput = document.getElementById('invite_last_name');
    var emailInput = document.getElementById('invite-email-input');
    var emailDomain = document.getElementById('invite-email-domain');
    var methodSelect = document.getElementById('invite-method');

    function generateEmail(first, last) {
        var f = first.toLowerCase().replace(/[^a-z]/g, '');
        var l = last.toLowerCase().replace(/[^a-z]/g, '');
        if (f.length < 2 && l.length < 2) return '';
        return f + l;
    }

    if (firstNameInput && lastNameInput && emailInput) {
        var lastAutoValue = '';

        function autoFillEmail() {
            var generated = generateEmail(firstNameInput.value, lastNameInput.value);
            if (emailInput.value === '' || emailInput.value === lastAutoValue) {
                emailInput.value = generated;
                lastAutoValue = generated;
            }
        }

        firstNameInput.addEventListener('input', autoFillEmail);
        lastNameInput.addEventListener('input', autoFillEmail);
    }

    if (methodSelect && emailDomain && emailInput) {
        methodSelect.addEventListener('change', function(){
            if (methodSelect.value === 'email') {
                emailDomain.style.display = 'none';
                emailInput.placeholder = 'e.g. juan@gmail.com';
                emailInput.type = 'email';
                emailInput.required = true;
            } else {
                emailDomain.style.display = '';
                emailInput.placeholder = 'auto-generated from name';
                emailInput.type = 'text';
                emailInput.required = false;
            }
        });
    }
})();
</script>

<?php
admin_layout_end();

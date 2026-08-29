<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();
require_permission('users.create');

$conn = get_db_connection();

// Auto-expire stale invitations
mysqli_query($conn, "UPDATE invitations SET status = 'expired' WHERE status = 'pending' AND expires_at < NOW()");

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = (string)($_POST['action'] ?? '');

    if ($formAction === 'cancel') {
        $cancelId = (int)($_POST['id'] ?? 0);
        if ($cancelId > 0) {
            $inv = admin_fetch_one("SELECT id, invitee_name, code, status FROM invitations WHERE id = ? LIMIT 1", 'i', [$cancelId]);
            if ($inv !== null && $inv['status'] === 'pending') {
                $ok = admin_execute("UPDATE invitations SET status = 'cancelled' WHERE id = ? AND status = 'pending'", 'i', [$cancelId]);
                if ($ok) {
                    $actor = current_user();
                    log_action($actor['id'] ?? null, 'DELETE_INVITATION', 'info', sprintf('Cancelled invitation for %s (code: %s)', $inv['invitee_name'], $inv['code']));
                }
                admin_redirect('/admin/invitations.php', ['notice' => $ok ? 'Invitation cancelled.' : 'Could not cancel invitation.', 'type' => $ok ? 'success' : 'error']);
            }
            admin_redirect('/admin/invitations.php', ['notice' => 'Invitation not found or already processed.', 'type' => 'error']);
        }
    }

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
        admin_redirect('/admin/invitations.php', ['notice' => 'Enter a valid first name (letters only, at least 2 characters).', 'type' => 'error']);
    }
    if ($lastName === '' || !admin_is_valid_name_part($lastName, true)) {
        admin_redirect('/admin/invitations.php', ['notice' => 'Enter a valid surname (letters only, at least 2 characters).', 'type' => 'error']);
    }

    if (!in_array($role, ['admin', 'nutritionist'], true)) {
        admin_redirect('/admin/invitations.php', ['notice' => 'Invalid role.', 'type' => 'error']);
    }

    if (!in_array($method, ['email', 'manual'], true)) {
        $method = 'manual';
    }

    if ($method === 'email') {
        $email = $emailRaw !== '' ? $emailRaw : null;
        if ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            admin_redirect('/admin/invitations.php', ['notice' => 'A valid email address is required for email invitations.', 'type' => 'error']);
        }
        $existing = admin_fetch_one('SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1', 's', [$email]);
        if ($existing !== null) {
            admin_redirect('/admin/invitations.php', ['notice' => 'This email is already registered.', 'type' => 'error']);
        }
    } else {
        $email = $emailRaw !== '' ? $emailRaw . '@sukat.kalusugan' : null;
    }

    $phone = trim((string)($_POST['phone'] ?? ''));
    if ($phone !== '' && !admin_is_valid_ph_mobile($phone)) {
        admin_redirect('/admin/invitations.php', ['notice' => 'Please enter a valid Philippine mobile number (09XXXXXXXXX).', 'type' => 'error']);
    }
    $phone = $phone !== '' ? $phone : null;

    $address = trim((string)($_POST['address'] ?? ''));
    if (mb_strlen($address) > 255) {
        admin_redirect('/admin/invitations.php', ['notice' => 'Address must be 255 characters or less.', 'type' => 'error']);
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
        admin_redirect('/admin/invitations.php', ['notice' => 'Unable to create invitation.', 'type' => 'error']);
    }
    $inviterId = (int)($actor['id'] ?? 0);
    mysqli_stmt_bind_param($stmt, 'issssissss', $inviterId, $name, $email, $phone, $address, $barangayId, $role, $code, $method, $expiresAt);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    if (!$ok) {
        admin_redirect('/admin/invitations.php', ['notice' => 'Failed to create invitation.', 'type' => 'error']);
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

$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$totalCount = (int)admin_scalar(
    "SELECT COUNT(*) FROM invitations i
     INNER JOIN users u ON u.id = i.inviter_user_id
     LEFT JOIN barangays b ON b.id = i.barangay_id
     WHERE 1=1",
    '', [], 0
);
$totalPages = max(1, (int)ceil($totalCount / $perPage));

$invitations = admin_fetch_all(
    "SELECT i.id, i.invitee_name, i.invitee_email, i.barangay_id, i.role, i.code, i.method, i.status, i.expires_at, i.used_at, i.created_at,
            u.name AS inviter_name,
            b.name AS barangay_name
     FROM invitations i
     INNER JOIN users u ON u.id = i.inviter_user_id
     LEFT JOIN barangays b ON b.id = i.barangay_id
     ORDER BY i.created_at DESC
     LIMIT ? OFFSET ?",
    'ii', [$perPage, $offset]
);

$barangays = admin_barangay_options();
$pendingCount = (int)admin_scalar("SELECT COUNT(*) FROM invitations WHERE status = 'pending' AND expires_at > NOW()", '', [], 0);

$actions = '<a class="admin-btn-secondary" href="' . admin_e(app_url('/admin/users.php')) . '">' . admin_action_icon('back') . ' Users</a>';

admin_layout_start('Staff Invitations', 'Invite new staff members via activation code or email.', 'invitations', $actions);
?>
<section class="admin-grid-cards">
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM4 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 10.374 21c-2.331 0-4.512-.645-6.374-1.766Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Pending Invitations</div>
                <div class="admin-card-value"><?php echo $pendingCount; ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend"><?php echo 3 - $pendingCount; ?> slots remaining</span>
                </div>
            </div>
        </div>
    </article>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon is-success">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Activated</div>
                <?php
                $activatedCount = 0;
                foreach ($invitations as $inv) {
                    if ($inv['status'] === 'used') $activatedCount++;
                }
                ?>
                <div class="admin-card-value"><?php echo $activatedCount; ?></div>
            </div>
        </div>
    </article>
</section>

<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">Invite Staff</h2>
            <p class="admin-section-subtitle">Generate an activation code for a new staff member. Codes expire after 48 hours.</p>
        </div>
    </div>

    <form class="admin-form-grid" method="post" data-validate-form action="<?php echo admin_e(app_url('/admin/invitations.php')); ?>">

        <div class="admin-field-wide admin-flash is-error" data-validate-banner style="display:none;"></div>

        <div class="admin-field-wide">
            <div class="admin-field-row">
                <label class="admin-field">
                    <span>First name<span class="admin-required">*</span></span>
                    <input id="invite_first_name" name="first_name" required maxlength="60" data-validate="name" data-label="First name" placeholder="Juan">
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
            <button class="admin-btn" type="submit"><?php echo admin_action_icon('add'); ?> Generate Invitation</button>
        </div>
    </form>
</section>

<?php if (count($invitations) > 0): ?>
<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">Invitation History</h2>
            <p class="admin-section-subtitle">Recent staff invitations and their status.</p>
        </div>
    </div>

    <div class="admin-table-wrap">
        <table class="admin-table" id="invitations-table" data-no-paginate>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Barangay</th>
                    <th>Method</th>
                    <th>Code</th>
                    <th>Status</th>
                    <th>Invited By</th>
                    <th>Expires</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invitations as $inv): ?>
                    <tr>
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
                        <td><span class="admin-pill <?php echo $inv['role'] === 'admin' ? 'is-warn' : 'is-success'; ?>"><?php echo admin_e(ucfirst($inv['role'])); ?></span></td>
                        <td><?php echo admin_e((string)($inv['barangay_name'] ?? 'All barangays')); ?></td>
                        <td><span class="admin-pill <?php echo $inv['method'] === 'email' ? 'is-info' : ''; ?>"><?php echo admin_e(ucfirst($inv['method'])); ?></span></td>
                        <td><code style="font-weight:700;letter-spacing:0.1em;font-size:0.85rem;"><?php echo admin_e($inv['code']); ?></code></td>
                        <td>
                            <?php
                            $statusClass = match ($inv['status']) {
                                'pending' => 'is-warn',
                                'used' => 'is-success',
                                'expired' => 'is-muted',
                                'cancelled' => 'is-danger',
                                default => 'is-muted',
                            };
                            ?>
                            <span class="admin-pill <?php echo $statusClass; ?>"><?php echo admin_e(ucfirst($inv['status'])); ?></span>
                        </td>
                        <td><?php echo admin_e($inv['inviter_name']); ?></td>
                        <td>
                            <?php
                            $exp = (string)($inv['expires_at'] ?? '');
                            if ($exp !== '' && $inv['status'] === 'pending') {
                                $expTime = strtotime($exp);
                                $now = time();
                                if ($expTime > $now) {
                                    $hoursLeft = (int)ceil(($expTime - $now) / 3600);
                                    echo '<span style="font-weight:600;">' . $hoursLeft . 'h left</span>';
                                } else {
                                    echo '<span class="admin-pill is-muted">Expired</span>';
                                }
                            } elseif ($exp !== '') {
                                echo admin_e(date('M j Y', strtotime($exp)));
                            } else {
                                echo 'n/a';
                            }
                            ?>
                        </td>
                        <td>
                            <?php if ($inv['status'] === 'pending'): ?>
                            <div class="admin-actions">
                                <form method="post" action="<?php echo admin_e(app_url('/admin/invitations.php')); ?>" onsubmit="return confirm('Cancel invitation for <?php echo admin_e($inv['invitee_name']); ?>?');" style="display:inline;">
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="id" value="<?php echo (int)$inv['id']; ?>">
                                    <button class="admin-icon-btn admin-icon-btn-danger" title="Cancel" type="submit">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                    </button>
                                </form>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-top:1px solid var(--admin-border);">
        <span class="admin-pagination-status">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
        <div class="admin-pagination-numbers" style="gap:6px;">
            <?php
            $pParams = $_GET;
            unset($pParams['page']);
            $qs = http_build_query($pParams);
            $prefix = $qs ? $qs . '&' : '';
            if ($page > 1): ?>
                <a class="admin-icon-btn" href="?<?php echo admin_e($prefix . 'page=' . ($page - 1)); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
                </a>
            <?php endif;
            for ($i = 1; $i <= $totalPages; $i++): ?>
                <a class="admin-page-num<?php echo $i === $page ? ' is-active' : ''; ?>" href="?<?php echo admin_e($prefix . 'page=' . $i); ?>"><?php echo $i; ?></a>
            <?php endfor;
            if ($page < $totalPages): ?>
                <a class="admin-icon-btn" href="?<?php echo admin_e($prefix . 'page=' . ($page + 1)); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

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

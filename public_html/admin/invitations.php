<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();
require_permission('users.create');

$conn = get_db_connection();

// Auto-expire stale invitations
mysqli_query($conn, "UPDATE invitations SET status = 'expired' WHERE status = 'pending' AND expires_at < NOW()");

// Handle POST — this index page only handles cancellations.
// Creation lives on admin/invitation_form.php (separate page).
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

    // Any other POST (e.g. a stale create form pointing here) goes to the form page.
    admin_redirect('/admin/invitation_form.php', ['notice' => 'Use the New Invitation page to create invitations.', 'type' => 'error']);
}

// Pagination of the Invitation History is now performed client-side by the
// shared admin.js paginator (which reads `data-page-size="5"` from the table).
// Loading the full list is fine because the table is admin-only and bounded by
// the 3-pending cap already enforced on creation, so even heavy usage stays
// well under a few hundred rows. This keeps the visual pagination controls
// consistent with every other admin-table in the system.
$invitations = admin_fetch_all(
    "SELECT i.id, i.invitee_name, i.invitee_email, i.barangay_id, i.role, i.code, i.method, i.status, i.expires_at, i.used_at, i.created_at,
            u.name AS inviter_name,
            b.name AS barangay_name
     FROM invitations i
     INNER JOIN users u ON u.id = i.inviter_user_id
     LEFT JOIN barangays b ON b.id = i.barangay_id
     ORDER BY i.created_at DESC",
    '', []
);

$pendingCount = (int)admin_scalar("SELECT COUNT(*) FROM invitations WHERE status = 'pending' AND expires_at > NOW()", '', [], 0);

$actions = '<a class="admin-btn-secondary" href="' . admin_e(app_url('/admin/users.php')) . '">' . admin_action_icon('back') . ' Users</a>'
    . ' <a class="admin-btn is-create" href="' . admin_e(app_url('/admin/invitation_form.php')) . '">' . admin_action_icon('add') . ' New Invitation</a>';

admin_layout_start('Staff Invitations', 'All staff invitations. Create new ones from the New Invitation page.', 'invitations', $actions);
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
            <h2 class="admin-section-title">Invitations</h2>
            <p class="admin-section-subtitle">Recent staff invitations and their status. <a href="<?php echo admin_e(app_url('/admin/invitation_form.php')); ?>">Create a new invitation</a>.</p>
        </div>
        <div class="admin-section-actions">
            <a class="admin-btn is-create" href="<?php echo admin_e(app_url('/admin/invitation_form.php')); ?>"><?php echo admin_action_icon('add'); ?> New Invitation</a>
        </div>
    </div>

    <div class="admin-table-wrap">
        <table class="admin-table" id="invitations-table" data-page-size="5">
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
                        <td><span class="admin-pill <?php echo $inv['role'] === 'admin' ? 'is-update' : 'is-create'; ?>"><?php echo admin_e(ucfirst($inv['role'])); ?></span></td>
                        <td><?php echo admin_e((string)($inv['barangay_name'] ?? 'All barangays')); ?></td>
                        <td><span class="admin-pill is-read"><?php echo admin_e(ucfirst($inv['method'])); ?></span></td>
                        <td><code style="font-weight:700;letter-spacing:0.1em;font-size:0.85rem;"><?php echo admin_e($inv['code']); ?></code></td>
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
                                    <button class="admin-icon-btn is-delete" title="Cancel" type="submit" aria-label="Cancel invitation">
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
    <!--
        Pagination for the Invitation History is handled by the shared
        admin.js paginator (see public_html/assets/js/admin.js) which
        reads data-page-size="5" from this table. This keeps the visual
        pagination consistent with every other admin-table in the system.
    -->
</section>

<?php if (count($invitations) === 0): ?>
<section class="admin-section">
    <p class="admin-section-subtitle" style="margin:0;">No invitations yet. <a href="<?php echo admin_e(app_url('/admin/invitation_form.php')); ?>">Create the first invitation</a>.</p>
</section>
<?php endif; ?>

<?php
admin_layout_end();

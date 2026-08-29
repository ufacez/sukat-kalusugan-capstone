<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();
require_permission('users.delete');

$deleteId = (int)($_GET['delete'] ?? 0);

$users = admin_fetch_all(
    'SELECT u.id, u.name, u.email, u.username, u.phone, b.name AS barangay, u.status, r.name AS role_name
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     LEFT JOIN barangays b ON b.id = u.barangay_id
     WHERE u.status = \'inactive\'
     ORDER BY u.name ASC'
);

$actions = '<a class="admin-btn-secondary" href="' . admin_e(app_url('/admin/users.php')) . '">' . admin_action_icon('back') . ' Active users</a>';

admin_layout_start('Archived Users', 'Restore or permanently delete archived staff accounts.', 'users', $actions);
?>

<?php if ($deleteId > 0): ?>
<?php
$deleteTarget = admin_fetch_one('SELECT id, name, email FROM users WHERE id = ? AND status = \'inactive\' LIMIT 1', 'i', [$deleteId]);
?>
<?php if ($deleteTarget !== null): ?>
<section class="admin-section" style="margin-bottom:20px;">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title" style="color:var(--admin-danger,#d32f2f);">Permanent Deletion</h2>
            <p class="admin-section-subtitle">Type <strong>DELETE</strong> below to permanently remove <?php echo admin_e($deleteTarget['name']); ?>. This action cannot be undone.</p>
        </div>
    </div>
    <form method="post" action="<?php echo admin_e(app_url('/api/admin/users_hard_delete.php')); ?>" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <input type="hidden" name="id" value="<?php echo (int)$deleteTarget['id']; ?>">
        <label class="admin-field" style="margin:0;">
            <span>Type DELETE to confirm</span>
            <input name="confirm_delete" required pattern="DELETE" placeholder="DELETE" style="max-width:200px;font-family:monospace;font-weight:700;color:var(--admin-danger,#d32f2f);">
        </label>
        <button class="admin-btn" type="submit" style="background:var(--admin-danger,#d32f2f);color:#fff;" onclick="return confirm('This will permanently delete this user. Are you absolutely sure?');">
            <?php echo admin_action_icon('delete'); ?> Permanently delete
        </button>
        <a class="admin-btn-secondary" href="<?php echo admin_e(app_url('/admin/users_archived.php')); ?>">Cancel</a>
    </form>
</section>
<?php endif; ?>
<?php endif; ?>

<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">Archived Users</h2>
            <p class="admin-section-subtitle"><?php echo count($users); ?> archived staff account(s).</p>
        </div>
        <div class="admin-toolbar" style="margin:0;">
            <input class="admin-search" type="search" placeholder="Search archived users" data-admin-filter="#archived-users-table">
        </div>
    </div>

    <div class="admin-table-wrap">
        <table class="admin-table" id="archived-users-table">
            <thead>
                <tr>
                    <th>Role</th>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Barangay</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($users === []): ?>
                    <tr><td colspan="6" style="color:var(--admin-muted);text-align:center;padding:24px;">No archived users.</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                        <tr data-filter-text="<?php echo admin_e(strtolower($user['name'] . ' ' . $user['email'] . ' ' . $user['username'])); ?>">
                            <td><span class="admin-pill <?php echo $user['role_name'] === 'admin' ? 'is-warn' : 'is-success'; ?>"><?php echo admin_e(ucfirst($user['role_name'])); ?></span></td>
                            <td><?php echo admin_e($user['name']); ?></td>
                            <td><?php echo admin_e($user['username'] ?? ''); ?></td>
                            <td><?php echo admin_e($user['email']); ?></td>
                            <td><?php echo admin_e($user['barangay'] ?? 'All barangays'); ?></td>
                            <td>
                                <div class="admin-actions">
                                    <form method="post" action="<?php echo admin_e(app_url('/api/admin/users_restore.php')); ?>" style="display:inline;">
                                        <input type="hidden" name="id" value="<?php echo (int)$user['id']; ?>">
                                        <button class="admin-icon-btn" title="Restore" type="submit" style="color:var(--admin-primary,#0b6e4f);">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
                                        </button>
                                    </form>
                                    <a class="admin-icon-btn admin-icon-btn-danger" title="Delete permanently" href="<?php echo admin_e(app_url('/admin/users_archived.php?delete=' . (int)$user['id'])); ?>">
                                        <?php echo admin_action_icon('delete'); ?>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php
admin_layout_end();

<?php

require_once __DIR__ . '/../includes/admin_helpers.php';
require_once __DIR__ . '/../includes/who_calculator.php';

start_secure_session();
require_permission('children.delete');

$deleteId = (int)($_GET['delete'] ?? 0);

$children = admin_fetch_all(
    "SELECT
        c.id,
        c.child_code,
        c.first_name,
        c.middle_name,
        c.last_name,
        c.birthdate,
        c.sex,
        bg.name AS barangay,
        p.name AS parent_name,
        c.status
     FROM children c
     INNER JOIN parents p ON p.id = c.parent_id
     LEFT JOIN barangays bg ON bg.id = c.barangay_id
     WHERE c.status = 'inactive'
     ORDER BY c.last_name ASC, c.first_name ASC"
);

$actions = '<a class="admin-btn-secondary" href="' . admin_e(app_url('/admin/children.php')) . '">' . admin_action_icon('back') . ' Active children</a>';

admin_layout_start('Archived Children', 'Restore or permanently delete archived child profiles.', 'children', $actions, 'Archived');
?>

<?php if ($deleteId > 0): ?>
<?php
$deleteTarget = admin_fetch_one('SELECT id, child_code, first_name FROM children WHERE id = ? AND status = \'inactive\' LIMIT 1', 'i', [$deleteId]);
?>
<?php if ($deleteTarget !== null): ?>
<section class="admin-section" style="margin-bottom:20px;">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title" style="color:var(--admin-danger,#d32f2f);">Permanent Deletion</h2>
            <p class="admin-section-subtitle">Type <strong>DELETE</strong> below to permanently remove <?php echo admin_e($deleteTarget['child_code'] . ' · ' . $deleteTarget['first_name']); ?>. This action cannot be undone.</p>
        </div>
    </div>
    <form method="post" action="<?php echo admin_e(app_url('/api/admin/children_hard_delete.php')); ?>" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <input type="hidden" name="id" value="<?php echo (int)$deleteTarget['id']; ?>">
        <label class="admin-field" style="margin:0;">
            <span>Type DELETE to confirm</span>
            <input name="confirm_delete" required pattern="DELETE" placeholder="DELETE" style="max-width:200px;font-family:monospace;font-weight:700;color:var(--admin-danger,#d32f2f);">
        </label>
        <button class="admin-btn" type="submit" style="background:var(--admin-danger,#d32f2f);color:#fff;" onclick="return confirm('This will permanently delete this child. Are you absolutely sure?');">
            <?php echo admin_action_icon('delete'); ?> Permanently delete
        </button>
        <a class="admin-btn-secondary" href="<?php echo admin_e(app_url('/admin/children_archived.php')); ?>">Cancel</a>
    </form>
</section>
<?php endif; ?>
<?php endif; ?>

<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">Archived Children</h2>
            <p class="admin-section-subtitle"><?php echo count($children); ?> archived child profile(s).</p>
        </div>
        <div class="admin-toolbar" style="margin:0;">
            <input class="admin-search" type="search" placeholder="Search archived children" data-admin-filter="#archived-children-table">
        </div>
    </div>

    <div class="admin-table-wrap">
        <table class="admin-table" id="archived-children-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Age</th>
                    <th>Sex</th>
                    <th>Barangay</th>
                    <th>Parent</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($children === []): ?>
                    <tr><td colspan="7" style="color:var(--admin-muted);text-align:center;padding:24px;">No archived children.</td></tr>
                <?php else: ?>
                    <?php foreach ($children as $child): ?>
                        <?php $age = doh_age((string)$child['birthdate']) ?? ['days' => 0, 'months' => 0]; ?>
                        <tr data-filter-text="<?php echo admin_e(strtolower($child['child_code'] . ' ' . $child['first_name'] . ' ' . $child['last_name'])); ?>">
                            <td style="font-family:monospace;color:var(--admin-muted);"><?php echo admin_e($child['child_code']); ?></td>
                            <td>
                                <div style="font-weight:600;color:var(--admin-text);"><?php echo admin_e(trim($child['first_name'] . ' ' . ($child['middle_name'] ?? '') . ' ' . $child['last_name'])); ?></div>
                                <div class="admin-mini"><?php echo admin_e((string)$child['birthdate']); ?></div>
                            </td>
                            <td style="color:var(--admin-muted);"><?php echo (int)$age['days']; ?> d · <?php echo (int)$age['months']; ?> mo</td>
                            <td style="color:var(--admin-muted);"><?php echo admin_e((string)$child['sex']); ?></td>
                            <td style="color:var(--admin-muted);"><?php echo admin_e((string)($child['barangay'] ?? '')); ?></td>
                            <td style="color:var(--admin-muted);"><?php echo admin_e((string)$child['parent_name']); ?></td>
                            <td>
                                <div class="admin-actions">
                                    <form method="post" action="<?php echo admin_e(app_url('/api/admin/children_restore.php')); ?>" style="display:inline;">
                                        <input type="hidden" name="id" value="<?php echo (int)$child['id']; ?>">
                                        <button class="admin-icon-btn" title="Restore" type="submit" style="color:var(--admin-primary,#0b6e4f);">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
                                        </button>
                                    </form>
                                    <a class="admin-icon-btn admin-icon-btn-danger" title="Delete permanently" href="<?php echo admin_e(app_url('/admin/children_archived.php?delete=' . (int)$child['id'])); ?>">
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

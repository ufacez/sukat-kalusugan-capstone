<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();
require_permission('parents.delete');

$restoreId = (int)($_GET['restore'] ?? 0);

$parents = admin_fetch_all(
    "SELECT
        p.id,
        p.name,
        p.email,
        p.parent_type,
        p.phone,
        p.barangay_id,
        b.name AS barangay,
        p.status,
        COUNT(DISTINCT c.id) AS children_count
     FROM parents p
     LEFT JOIN barangays b ON b.id = p.barangay_id
     LEFT JOIN children c ON c.parent_id = p.id
     WHERE p.status = 'inactive'
     GROUP BY p.id, p.name, p.email, p.parent_type, p.phone, p.barangay_id, b.name, p.status
     ORDER BY p.id DESC"
);

$actions = '<a class="admin-btn-secondary" href="' . admin_e(app_url('/admin/users.php')) . '">' . admin_action_icon('back') . ' Active parents</a>';

admin_layout_start('Archived Parents', 'Restore archived parent accounts.', 'users', $actions, 'Archived');
?>

<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">Archived Parents</h2>
            <p class="admin-section-subtitle"><?php echo count($parents); ?> archived parent account(s).</p>
        </div>
        <div class="admin-toolbar" style="margin:0;">
            <input class="admin-search" type="search" placeholder="Search archived parents" data-admin-filter="#archived-parents-table">
        </div>
    </div>

    <div class="admin-table-wrap">
        <table class="admin-table" id="archived-parents-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Barangay</th>
                    <th>Children</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($parents === []): ?>
                    <tr><td colspan="7" style="color:var(--admin-muted);text-align:center;padding:24px;">No archived parents.</td></tr>
                <?php else: ?>
                    <?php foreach ($parents as $parentIndex => $parent): ?>
                        <tr<?php echo admin_paged_row_attr($parentIndex, 10); ?> data-filter-text="<?php echo admin_e(strtolower($parent['name'] . ' ' . $parent['email'])); ?>">
                            <td>
                                <div style="font-weight:600;color:var(--admin-text);"><?php echo admin_e($parent['name']); ?></div>
                            </td>
                            <td><span class="admin-pill is-muted"><?php echo admin_e($parent['parent_type']); ?></span></td>
                            <td style="color:var(--admin-muted);"><?php echo admin_e($parent['email']); ?></td>
                            <td style="color:var(--admin-muted);"><?php echo admin_e((string)($parent['phone'] ?? '')); ?></td>
                            <td style="color:var(--admin-muted);"><?php echo admin_e((string)($parent['barangay'] ?? '')); ?></td>
                            <td style="color:var(--admin-muted);"><?php echo (int)$parent['children_count']; ?></td>
                            <td>
                                <div class="admin-actions">
                                    <form method="post" action="<?php echo admin_e(app_url('/api/admin/parents_restore.php')); ?>" style="display:inline;">
                                        <input type="hidden" name="id" value="<?php echo (int)$parent['id']; ?>">
                                        <button class="admin-icon-btn" title="Restore" type="submit" style="color:var(--admin-primary,#0b6e4f);">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
                                        </button>
                                    </form>
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

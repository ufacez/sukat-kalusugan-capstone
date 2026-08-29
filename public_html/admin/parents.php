<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();
require_permission('parents.view');

$editId = (int)($_GET['edit'] ?? 0);

if ($editId > 0) {
    admin_redirect('/admin/parent_form.php?id=' . $editId);
}

$parents = admin_fetch_all(
    "SELECT
        p.id,
        p.name,
        p.email,
        p.parent_type,
        p.phone,
        p.address,
        p.barangay_id,
        b.name AS barangay,
        p.status,
        COUNT(DISTINCT c.id) AS children_count
     FROM parents p
     LEFT JOIN barangays b ON b.id = p.barangay_id
     LEFT JOIN children c ON c.parent_id = p.id AND c.status = 'active'
     WHERE p.status = 'active'
     GROUP BY p.id, p.name, p.email, p.parent_type, p.phone, p.address, p.barangay_id, b.name, p.status
     ORDER BY p.name ASC"
);

$totalChildren = 0;
foreach ($parents as $parent) {
    $totalChildren += (int)$parent['children_count'];
}

$archivedCountRow = admin_fetch_one("SELECT COUNT(*) AS cnt FROM parents WHERE status = 'inactive'");
$archivedCount = (int)($archivedCountRow['cnt'] ?? 0);

$actions = '';
if (has_permission('parents.create')) {
    $actions .= '<a class="admin-btn" href="' . admin_e(app_url('/admin/parent_form.php')) . '">' . admin_action_icon('add') . ' Add parent</a>';
}

admin_layout_start('Parents', 'Manage parent and guardian accounts across all barangays.', 'parents', $actions);
?>
<section class="admin-grid-cards">
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Active Parents</div>
                <div class="admin-card-value"><?php echo count($parents); ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-up">Currently active accounts</span>
                </div>
            </div>
        </div>
    </article>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Children Linked</div>
                <div class="admin-card-value"><?php echo $totalChildren; ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend">Across all households</span>
                </div>
            </div>
        </div>
    </article>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon is-muted">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5m6 4.125l2.25 2.25m0 0l2.25 2.25M12 13.875l2.25-2.25M12 13.875l-2.25 2.25M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Archived</div>
                <div class="admin-card-value"><?php echo $archivedCount; ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend"><a href="<?php echo admin_e(app_url('/admin/parents_archived.php')); ?>" style="color:var(--admin-primary);text-decoration:underline;">View archived parents</a></span>
                </div>
            </div>
        </div>
    </article>
</section>

<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">Parent Directory</h2>
            <p class="admin-section-subtitle">Search, update, and review parent/guardian records.</p>
        </div>
        <div class="admin-toolbar" style="margin:0;">
            <input class="admin-search" type="search" placeholder="Search parents" data-admin-filter="#parents-table">
        </div>
    </div>

    <div class="admin-table-wrap">
        <table class="admin-table" id="parents-table">
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
                <?php foreach ($parents as $parent): ?>
                    <tr data-filter-text="<?php echo admin_e(strtolower($parent['name'] . ' ' . $parent['parent_type'] . ' ' . $parent['email'] . ' ' . $parent['phone'])); ?>">
                        <td>
                            <div style="font-weight:600;color:var(--admin-text);"><?php echo admin_e($parent['name']); ?></div>
                            <div class="admin-mini"><?php echo admin_e((string)($parent['address'] ?? '')); ?></div>
                        </td>
                        <td><span class="admin-pill is-muted"><?php echo admin_e($parent['parent_type']); ?></span></td>
                        <td style="color:var(--admin-muted);"><?php echo admin_e($parent['email']); ?></td>
                        <td style="color:var(--admin-muted);"><?php echo admin_e((string)($parent['phone'] ?? '')); ?></td>
                        <td style="color:var(--admin-muted);"><?php echo admin_e((string)($parent['barangay'] ?? '')); ?></td>
                        <td style="color:var(--admin-muted);"><?php echo (int)$parent['children_count']; ?></td>
                        <td>
                            <div class="admin-actions">
                                <?php if (has_permission('parents.update')): ?>
                                    <a class="admin-icon-btn" title="Edit" href="<?php echo admin_e(app_url('/admin/parent_form.php?id=' . (int)$parent['id'])); ?>"><?php echo admin_action_icon('edit'); ?></a>
                                <?php endif; ?>
                                <?php if (has_permission('parents.delete')): ?>
                                    <form method="post" action="<?php echo admin_e(app_url('/api/admin/parents_archive.php')); ?>" onsubmit="return confirm('Archive <?php echo admin_e($parent['name']); ?>?');" style="display:inline;">
                                        <input type="hidden" name="id" value="<?php echo (int)$parent['id']; ?>">
                                        <button class="admin-icon-btn admin-icon-btn-danger" title="Archive" type="submit">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5m6 4.125l2.25 2.25m0 0l2.25 2.25M12 13.875l2.25-2.25M12 13.875l-2.25 2.25M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php
admin_layout_end();

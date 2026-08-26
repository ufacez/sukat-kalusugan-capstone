<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();
require_permission('barangays.view');

$editId = (int)($_GET['edit'] ?? 0);

if ($editId > 0) {
    admin_redirect('/admin/barangay_form.php?id=' . $editId);
}

$barangays = admin_fetch_all(
    "SELECT
        b.id,
        b.name,
        b.city_municipality,
        b.status,
        b.created_at,
        (SELECT COUNT(*) FROM children c WHERE c.barangay_id = b.id) AS children_count,
        (SELECT COUNT(*) FROM parents p WHERE p.barangay_id = b.id) AS parents_count,
        (SELECT COUNT(*) FROM users u WHERE u.barangay_id = b.id) AS nutritionists_count,
        (SELECT COUNT(*) FROM devices d WHERE d.barangay_id = b.id) AS kiosks_count
     FROM barangays b
     ORDER BY b.name ASC"
);

$activeCount = count(array_filter($barangays, static fn(array $b): bool => (string)$b['status'] === 'active'));
$totalChildren = array_sum(array_map(static fn(array $b): int => (int)$b['children_count'], $barangays));
$totalKiosks = array_sum(array_map(static fn(array $b): int => (int)$b['kiosks_count'], $barangays));

$actions = '<a class="admin-btn" href="' . admin_e(app_url('/admin/barangay_form.php')) . '">' . admin_action_icon('add') . ' Add barangay</a>';

admin_layout_start('Barangays', 'The master list every child, parent, nutritionist, and kiosk is scoped to.', 'barangays', $actions);
?>
<section class="admin-grid-cards">
    <article class="admin-card">
        <div class="admin-stat-label">Barangays</div>
        <div class="admin-stat-value"><?php echo count($barangays); ?></div>
        <div class="admin-stat-note"><?php echo $activeCount; ?> active</div>
    </article>
    <article class="admin-card">
        <div class="admin-stat-label">Children covered</div>
        <div class="admin-stat-value"><?php echo $totalChildren; ?></div>
        <div class="admin-stat-note">Linked via barangay_id</div>
    </article>
    <article class="admin-card">
        <div class="admin-stat-label">Kiosks assigned</div>
        <div class="admin-stat-value"><?php echo $totalKiosks; ?></div>
        <div class="admin-stat-note">Devices scoped to a barangay</div>
    </article>
    <article class="admin-card">
        <div class="admin-stat-label">Latest sync</div>
        <div class="admin-stat-value">Live</div>
        <div class="admin-stat-note">Data is read directly from MySQL</div>
    </article>
</section>


<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">Barangay Directory</h2>
            <p class="admin-section-subtitle">Every child, parent, nutritionist account, and kiosk scoped to each barangay.</p>
        </div>
        <input class="admin-search" data-admin-filter="#barangays-table" type="search" placeholder="Search barangays">
    </div>

    <div class="admin-table-wrap">
        <table class="admin-table" id="barangays-table">
            <thead>
                <tr>
                    <th>Barangay</th>
                    <th>City / Municipality</th>
                    <th>Children</th>
                    <th>Parents</th>
                    <th>Nutritionists</th>
                    <th>Kiosks</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($barangays as $barangay): ?>
                    <tr data-filter-text="<?php echo admin_e(strtolower($barangay['name'] . ' ' . (string)($barangay['city_municipality'] ?? ''))); ?>">
                        <td style="font-weight:700;color:var(--admin-text);"><?php echo admin_e($barangay['name']); ?></td>
                        <td style="color:var(--admin-muted);"><?php echo admin_e((string)($barangay['city_municipality'] ?? '')); ?></td>
                        <td><?php echo (int)$barangay['children_count']; ?></td>
                        <td><?php echo (int)$barangay['parents_count']; ?></td>
                        <td><?php echo (int)$barangay['nutritionists_count']; ?></td>
                        <td><?php echo (int)$barangay['kiosks_count']; ?></td>
                        <td><span class="admin-pill <?php echo (string)$barangay['status'] === 'active' ? 'is-success' : 'is-muted'; ?>"><?php echo admin_e(ucfirst((string)$barangay['status'])); ?></span></td>
                        <td>
                            <div class="admin-actions">
                                <a class="admin-icon-btn" title="Edit" href="<?php echo admin_e(app_url('/admin/barangay_form.php?id=' . (int)$barangay['id'])); ?>"><?php echo admin_action_icon('edit'); ?></a>
                                <form method="post" action="<?php echo admin_e(app_url('/api/admin/barangays_delete.php')); ?>" onsubmit="return confirm('Delete <?php echo admin_e($barangay['name']); ?>? Records linked to it will keep their history but lose the barangay assignment.');" style="display:inline;">
                                    <input type="hidden" name="id" value="<?php echo (int)$barangay['id']; ?>">
                                    <button class="admin-icon-btn admin-icon-btn-danger" title="Delete" type="submit"><?php echo admin_action_icon('delete'); ?></button>
                                </form>
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

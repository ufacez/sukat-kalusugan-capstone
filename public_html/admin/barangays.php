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

$actions = has_permission('barangays.manage') ? '<a class="admin-btn" href="' . admin_e(app_url('/admin/barangay_form.php')) . '">' . admin_action_icon('add') . ' Add barangay</a>' : '';

admin_layout_start('Barangays', 'The master list every child, parent, nutritionist, and kiosk is scoped to.', 'barangays', $actions);
?>
<section class="admin-grid-cards">
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Barangays</div>
                <div class="admin-card-value"><?php echo count($barangays); ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-up"><?php echo $activeCount; ?> active</span>
                </div>
            </div>
        </div>
    </article>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon is-success">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Children covered</div>
                <div class="admin-card-value"><?php echo $totalChildren; ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-up">Linked via barangay_id</span>
                </div>
            </div>
        </div>
    </article>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M5.25 14.25h13.5m-13.5 0a3 3 0 0 1-3-3m3 3a3 3 0 1 0 0 6h13.5a3 3 0 1 0 0-6m-16.5-3a3 3 0 0 1 3-3h13.5a3 3 0 0 1 3 3m-19.5 0a4.5 4.5 0 0 1 .9-2.7L5.737 5.1a3.375 3.375 0 0 1 2.7-1.35h7.126c1.062 0 2.062.5 2.7 1.35l2.587 3.45a4.5 4.5 0 0 1 .9 2.7m0 0a3 3 0 0 1-3 3m0 3h.008v.008h-.008v-.008Zm0-6h.008v.008h-.008v-.008Zm-3 6h.008v.008h-.008v-.008Zm0-6h.008v.008h-.008v-.008Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Kiosks assigned</div>
                <div class="admin-card-value"><?php echo $totalKiosks; ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-up">Devices scoped to a barangay</span>
                </div>
            </div>
        </div>
    </article>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Latest sync</div>
                <div class="admin-card-value admin-card-value--text">Live</div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-up">Data is read directly from MySQL</span>
                </div>
            </div>
        </div>
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
                            <?php if (has_permission('barangays.view')): ?>
                            <div class="admin-actions">
                                <a class="admin-icon-btn" title="Local Areas" href="<?php echo admin_e(app_url('/admin/local_areas.php?barangay_id=' . (int)$barangay['id'])); ?>">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 2.499 4.012-3.749a1.125 1.125 0 0 1 1.538-.028l3.499 3.25a1.125 1.125 0 0 1-.05 1.664l-3.499 2a1.125 1.125 0 0 1-1.588-.5V6.75a1.125 1.125 0 0 1 .503-.999Z"/></svg>
                                </a>
                                <?php if (has_permission('barangays.manage')): ?>
                                <a class="admin-icon-btn" title="Edit" href="<?php echo admin_e(app_url('/admin/barangay_form.php?id=' . (int)$barangay['id'])); ?>"><?php echo admin_action_icon('edit'); ?></a>
                                <form method="post" action="<?php echo admin_e(app_url('/api/admin/barangays_delete.php')); ?>" onsubmit="return confirm('Delete <?php echo admin_e($barangay['name']); ?>? Records linked to it will keep their history but lose the barangay assignment.');" style="display:inline;">
                                    <input type="hidden" name="id" value="<?php echo (int)$barangay['id']; ?>">
                                    <button class="admin-icon-btn admin-icon-btn-danger" title="Delete" type="submit"><?php echo admin_action_icon('delete'); ?></button>
                                </form>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php
admin_layout_end();

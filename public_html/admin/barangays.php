<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();
require_permission('barangays.view');

$editingId = (int)($_GET['edit'] ?? 0);
$editingBarangay = $editingId > 0 ? admin_fetch_one(
    'SELECT id, name, city_municipality, status FROM barangays WHERE id = ? LIMIT 1',
    'i',
    [$editingId]
) : null;

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

$actions = '<a class="admin-btn" href="#barangay-form">Add barangay</a>';

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

<section class="admin-section" id="barangay-form">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title"><?php echo $editingBarangay ? 'Edit Barangay' : 'Add Barangay'; ?></h2>
            <p class="admin-section-subtitle"><?php echo $editingBarangay ? 'Update the barangay record used across children, parents, nutritionists, and kiosks.' : 'Create a new barangay backed by the barangays table.'; ?></p>
        </div>
    </div>

    <form class="admin-form-grid" method="post" action="<?php echo admin_e(app_url($editingBarangay ? '/api/admin/barangays_update.php' : '/api/admin/barangays_create.php')); ?>">
        <?php if ($editingBarangay): ?>
            <input type="hidden" name="id" value="<?php echo (int)$editingBarangay['id']; ?>">
        <?php endif; ?>
        <div class="admin-field-wide">
            <div class="admin-csfp-picker" data-csfp-barangay-picker>
                <label class="admin-field">
                    <span>City / Municipality</span>
                    <select disabled><option>City of San Fernando, Pampanga</option></select>
                </label>
                <label class="admin-field">
                    <span>Barangay</span>
                    <select data-csfp="barangay" <?php echo $editingBarangay ? '' : 'required'; ?> disabled><option value="">Loading barangays...</option></select>
                </label>
            </div>
            <div class="admin-address-status" data-csfp-status></div>
            <input type="hidden" name="name" value="<?php echo admin_e($editingBarangay['name'] ?? ''); ?>">
            <input type="hidden" name="city_municipality" value="<?php echo admin_e($editingBarangay['city_municipality'] ?? 'City of San Fernando, Pampanga'); ?>">
            <?php if ($editingBarangay): ?>
                <div class="admin-field-hint">Current saved barangay: <?php echo admin_e($editingBarangay['name']); ?>, <?php echo admin_e($editingBarangay['city_municipality'] ?? ''); ?>. Select a new official location to replace it.</div>
            <?php endif; ?>
        </div>
        <label class="admin-field">
            <span>Status</span>
            <select name="status" required>
                <option value="active" <?php echo (($editingBarangay['status'] ?? 'active') === 'active') ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo (($editingBarangay['status'] ?? '') === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </label>
        <div class="admin-field" style="align-content:end;">
            <span>&nbsp;</span>
            <div class="admin-actions">
                <?php if ($editingBarangay): ?>
                    <a class="admin-btn-secondary" href="<?php echo admin_e(app_url('/admin/barangays.php')); ?>">Cancel</a>
                <?php endif; ?>
                <button class="admin-btn" type="submit"><?php echo $editingBarangay ? 'Save changes' : 'Create barangay'; ?></button>
            </div>
        </div>
    </form>
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
                                <a class="admin-btn-secondary" href="<?php echo admin_e(app_url('/admin/barangays.php?edit=' . (int)$barangay['id']) . '#barangay-form'); ?>">Edit</a>
                                <form method="post" action="<?php echo admin_e(app_url('/api/admin/barangays_delete.php')); ?>" onsubmit="return confirm('Delete <?php echo admin_e($barangay['name']); ?>? Records linked to it will keep their history but lose the barangay assignment.');">
                                    <input type="hidden" name="id" value="<?php echo (int)$barangay['id']; ?>">
                                    <button class="admin-btn-danger" type="submit">Delete</button>
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

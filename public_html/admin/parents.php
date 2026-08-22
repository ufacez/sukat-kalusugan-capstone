<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();
require_permission('parents.view');

$parentTypes = ['Father', 'Mother', 'Guardian', 'Grandparent', 'Other'];
$barangays = admin_barangay_options();
$editingId = (int)($_GET['edit'] ?? 0);
$editingParent = $editingId > 0 ? admin_fetch_one(
    'SELECT id, name, email, parent_type, phone, address, barangay_id, status
     FROM parents
     WHERE id = ?
     LIMIT 1',
    'i',
    [$editingId]
) : null;

$parents = admin_fetch_all(
    'SELECT
        p.id,
        p.name,
        p.email,
        p.parent_type,
        p.phone,
        p.address,
        p.barangay_id,
        b.name AS barangay,
        p.status,
        p.created_at,
        COUNT(DISTINCT c.id) AS children_count,
        COUNT(DISTINCT a.id) AS appointment_count,
        MAX(m.measurement_date) AS latest_measurement
     FROM parents p
     LEFT JOIN barangays b ON b.id = p.barangay_id
     LEFT JOIN children c ON c.parent_id = p.id
     LEFT JOIN appointments a ON a.parent_id = p.id
     LEFT JOIN measurements m ON m.child_id = c.id
     GROUP BY p.id, p.name, p.email, p.parent_type, p.phone, p.address, p.barangay_id, b.name, p.status, p.created_at
     ORDER BY p.created_at DESC, p.id DESC'
);

$activeCount = count(array_filter($parents, static fn(array $parent): bool => (string)$parent['status'] === 'active'));
$totalChildren = array_sum(array_map(static fn(array $parent): int => (int)$parent['children_count'], $parents));
$totalAppointments = array_sum(array_map(static fn(array $parent): int => (int)$parent['appointment_count'], $parents));

$actions = '<a class="admin-btn-secondary" href="#parent-form">Add parent</a>'
    . ' <a class="admin-btn-secondary" href="' . admin_e(app_url('/admin/users.php')) . '">Users</a>';

admin_layout_start('Parents', 'Parent accounts and linked child records.', 'parents', $actions);
?>
<section class="admin-grid-cards">
    <article class="admin-card">
        <div class="admin-stat-label">Parents</div>
        <div class="admin-stat-value"><?php echo count($parents); ?></div>
        <div class="admin-stat-note"><?php echo $activeCount; ?> active accounts</div>
    </article>
    <article class="admin-card">
        <div class="admin-stat-label">Children linked</div>
        <div class="admin-stat-value"><?php echo $totalChildren; ?></div>
        <div class="admin-stat-note">All household children</div>
    </article>
    <article class="admin-card">
        <div class="admin-stat-label">Appointments</div>
        <div class="admin-stat-value"><?php echo $totalAppointments; ?></div>
        <div class="admin-stat-note">Parent-requested and nutritionist-created visits</div>
    </article>
    <article class="admin-card">
        <div class="admin-stat-label">Latest sync</div>
        <div class="admin-stat-value">Live</div>
        <div class="admin-stat-note">Data is read directly from MySQL</div>
    </article>
</section>

<section class="admin-section" id="parent-form">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title"><?php echo $editingParent ? 'Edit Parent' : 'Add Parent'; ?></h2>
            <p class="admin-section-subtitle"><?php echo $editingParent ? 'Update this parent account.' : 'Create a new parent account backed by the parents table.'; ?></p>
        </div>
    </div>

    <form class="admin-form-grid" method="post" action="<?php echo admin_e(app_url($editingParent ? '/api/admin/parents_update.php' : '/api/admin/parents_create.php')); ?>">
        <?php if ($editingParent): ?>
            <input type="hidden" name="id" value="<?php echo (int)$editingParent['id']; ?>">
        <?php endif; ?>
        <label class="admin-field">
            <span>Full name</span>
            <input name="name" required value="<?php echo admin_e($editingParent['name'] ?? ''); ?>" placeholder="Juan Dela Cruz">
        </label>
        <label class="admin-field">
            <span>Email</span>
            <input type="email" name="email" required value="<?php echo admin_e($editingParent['email'] ?? ''); ?>" placeholder="juan@example.com">
        </label>
        <label class="admin-field">
            <span>Phone</span>
            <input name="phone" value="<?php echo admin_e($editingParent['phone'] ?? ''); ?>" placeholder="0917...">
        </label>
        <label class="admin-field">
            <span>Relationship</span>
            <select name="parent_type" required>
                <?php foreach ($parentTypes as $type): ?>
                    <option value="<?php echo admin_e($type); ?>" <?php echo (($editingParent['parent_type'] ?? 'Guardian') === $type) ? 'selected' : ''; ?>><?php echo admin_e($type); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="admin-field">
            <span>Barangay</span>
            <select name="barangay_id">
                <option value="">-- Not set --</option>
                <?php foreach ($barangays as $barangay): ?>
                    <option value="<?php echo (int)$barangay['id']; ?>" <?php echo (int)($editingParent['barangay_id'] ?? 0) === (int)$barangay['id'] ? 'selected' : ''; ?>><?php echo admin_e($barangay['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="admin-field admin-field-wide">
            <span>Address</span>
            <input name="address" value="<?php echo admin_e($editingParent['address'] ?? ''); ?>" placeholder="House no., street, barangay">
        </label>
        <label class="admin-field">
            <span>Status</span>
            <select name="status" required>
                <option value="active" <?php echo (($editingParent['status'] ?? 'active') === 'active') ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo (($editingParent['status'] ?? 'active') === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </label>
        <label class="admin-field">
            <span><?php echo $editingParent ? 'New password (optional)' : 'Password'; ?></span>
            <input type="password" name="password" <?php echo $editingParent ? '' : 'required'; ?> placeholder="<?php echo $editingParent ? 'Leave blank to keep current password' : 'Create a strong password'; ?>">
        </label>
        <div class="admin-field" style="align-content:end;">
            <span>&nbsp;</span>
            <button class="admin-btn" type="submit"><?php echo $editingParent ? 'Save changes' : 'Create parent'; ?></button>
        </div>
    </form>
</section>

<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">Parent Directory</h2>
            <p class="admin-section-subtitle">View parent accounts and the child records linked to each account.</p>
        </div>
        <input class="admin-search" data-admin-filter="#parents-table" type="search" placeholder="Search parents">
    </div>

    <div class="admin-table-wrap">
        <table class="admin-table" id="parents-table">
            <thead>
                <tr>
                    <th>Parent</th>
                    <th>Type</th>
                    <th>Email</th>
                    <th>Barangay</th>
                    <th>Children</th>
                    <th>Appointments</th>
                    <th>Latest measurement</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($parents as $parent): ?>
                    <tr data-filter-text="<?php echo admin_e(strtolower($parent['name'] . ' ' . $parent['email'] . ' ' . $parent['parent_type'] . ' ' . $parent['address'])); ?>">
                        <td>
                            <div style="font-weight:700;color:var(--admin-text);"><?php echo admin_e($parent['name']); ?></div>
                            <div class="admin-mini"><?php echo admin_e((string)($parent['phone'] ?? '')); ?> · <?php echo admin_e((string)($parent['address'] ?? '')); ?></div>
                        </td>
                        <td><span class="admin-pill is-muted"><?php echo admin_e((string)$parent['parent_type']); ?></span></td>
                        <td><?php echo admin_e((string)$parent['email']); ?></td>
                        <td><?php echo admin_e((string)($parent['barangay'] ?? '')); ?></td>
                        <td><?php echo (int)$parent['children_count']; ?></td>
                        <td><?php echo (int)$parent['appointment_count']; ?></td>
                        <td><?php echo admin_e((string)($parent['latest_measurement'] ?? 'n/a')); ?></td>
                        <td><span class="admin-pill <?php echo (string)$parent['status'] === 'active' ? 'is-success' : 'is-muted'; ?>"><?php echo admin_e(ucfirst((string)$parent['status'])); ?></span></td>
                        <td>
                            <div class="admin-actions">
                                <a class="admin-btn-secondary" href="<?php echo admin_e(app_url('/admin/parents.php?edit=' . (int)$parent['id'])); ?>#parent-form">Edit</a>
                                <form method="post" action="<?php echo admin_e(app_url('/api/admin/parents_delete.php')); ?>" onsubmit="return confirm('Delete <?php echo admin_e($parent['name']); ?>?');">
                                    <input type="hidden" name="id" value="<?php echo (int)$parent['id']; ?>">
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

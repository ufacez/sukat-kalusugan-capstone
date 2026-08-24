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
$editingNameParts = admin_split_full_name($editingParent['name'] ?? '');

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

    <form class="admin-form-grid" method="post" data-validate-form action="<?php echo admin_e(app_url($editingParent ? '/api/admin/parents_update.php' : '/api/admin/parents_create.php')); ?>">
        <?php if ($editingParent): ?>
            <input type="hidden" name="id" value="<?php echo (int)$editingParent['id']; ?>">
        <?php endif; ?>

        <div class="admin-field-wide admin-flash is-error" data-validate-banner style="display:none;"></div>

        <div class="admin-field-wide">
            <div class="admin-field-row">
                <label class="admin-field">
                    <span>First name<span class="admin-required">*</span></span>
                    <input id="parent_first_name" name="first_name" required maxlength="60" data-validate="name" data-label="First name" value="<?php echo admin_e($editingNameParts['first']); ?>" placeholder="Juan">
                    <span class="admin-field-message"></span>
                </label>
                <label class="admin-field">
                    <span>Middle name</span>
                    <input id="parent_middle_name" name="middle_name" maxlength="60" data-validate="name" data-label="Middle name" value="<?php echo admin_e($editingNameParts['middle']); ?>" placeholder="Reyes">
                    <span class="admin-field-message"></span>
                </label>
                <label class="admin-field">
                    <span>Surname<span class="admin-required">*</span></span>
                    <input id="parent_last_name" name="last_name" required maxlength="60" data-validate="name" data-label="Surname" value="<?php echo admin_e($editingNameParts['last']); ?>" placeholder="Dela Cruz">
                    <span class="admin-field-message"></span>
                </label>
            </div>
        </div>

        <label class="admin-field">
            <span>Email<span class="admin-required">*</span></span>
            <input id="parent_email" type="email" name="email" required data-validate="email" value="<?php echo admin_e($editingParent['email'] ?? ''); ?>" placeholder="juan@example.com">
            <span class="admin-field-message"></span>
        </label>
        <div class="admin-field-wide">
            <div class="admin-field-row">
                <label class="admin-field">
                    <span>Mobile number<span class="admin-required">*</span></span>
                    <input id="parent_phone" name="phone" required data-validate="phone-ph" value="<?php echo admin_e($editingParent['phone'] ?? ''); ?>" placeholder="09171234567">
                    <span class="admin-field-message"></span>
                <label class="admin-field">
                    <span>Relationship<span class="admin-required">*</span></span>
                    <select name="parent_type" required>
                        <?php foreach ($parentTypes as $type): ?>
                            <option value="<?php echo admin_e($type); ?>" <?php echo (($editingParent['parent_type'] ?? 'Guardian') === $type) ? 'selected' : ''; ?>><?php echo admin_e($type); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
        </div>
        <label class="admin-field">
            <span>Barangay</span>
            <select name="barangay_id">
                <option value="">-- Not set --</option>
                <?php foreach ($barangays as $barangay): ?>
                    <option value="<?php echo (int)$barangay['id']; ?>" <?php echo (int)($editingParent['barangay_id'] ?? 0) === (int)$barangay['id'] ? 'selected' : ''; ?>><?php echo admin_e($barangay['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <div class="admin-field-wide">
            <span style="font-size:0.88rem;font-weight:700;color:var(--admin-text);">Home address</span>
            <div class="admin-address-picker" data-psgc-picker data-psgc-address-target="parent_address">
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
                <span>House no. / street / purok</span>
                <input data-psgc="street" placeholder="143 Purok 6">
            </label>
            <div class="admin-address-status" data-psgc-status></div>
            <label class="admin-field" style="margin-top:10px;">
                <span>Full address</span>
                <textarea id="parent_address" name="address"><?php echo admin_e($editingParent['address'] ?? ''); ?></textarea>
                <span class="admin-field-hint">Auto-filled from the picker above; you can still edit it directly.</span>
            </label>
        </div>

        <label class="admin-field">
            <span>Status<span class="admin-required">*</span></span>
            <select name="status" required>
                <option value="active" <?php echo (($editingParent['status'] ?? 'active') === 'active') ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo (($editingParent['status'] ?? 'active') === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </label>

        <label class="admin-field admin-field-wide">
            <span><?php echo $editingParent ? 'New password (optional)' : 'Password'; ?><?php echo $editingParent ? '' : '<span class="admin-required">*</span>'; ?></span>
            <input id="parent_password" type="password" name="password" <?php echo $editingParent ? '' : 'required'; ?> data-validate="password" autocomplete="new-password" placeholder="<?php echo $editingParent ? 'Leave blank to keep current password' : 'Create a strong password'; ?>">
            <span class="admin-field-message"></span>
            <ul class="admin-pw-checklist" data-pw-checklist-for="parent_password">
                <li data-pw-rule="length">At least 8 characters</li>
                <li data-pw-rule="upper">One uppercase letter</li>
                <li data-pw-rule="lower">One lowercase letter</li>
                <li data-pw-rule="number">One number</li>
                <li data-pw-rule="special">One special character</li>
            </ul>
            <div class="admin-pw-strength" data-pw-strength-for="parent_password">
                <div class="admin-pw-strength-track"><div class="admin-pw-strength-fill"></div></div>
                <div class="admin-pw-strength-label"></div>
            </div>
        </label>
        <label class="admin-field admin-field-wide">
            <span><?php echo $editingParent ? 'Confirm new password' : 'Confirm password'; ?><?php echo $editingParent ? '' : '<span class="admin-required">*</span>'; ?></span>
            <input id="parent_password_confirm" type="password" name="password_confirm" <?php echo $editingParent ? '' : 'required'; ?> data-validate="confirm-password" data-match="parent_password" autocomplete="new-password" placeholder="Re-type the password">
            <span class="admin-field-message"></span>
        </label>

        <div class="admin-field admin-field-wide" style="align-content:end;">
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

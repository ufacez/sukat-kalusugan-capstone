<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();
require_permission('barangays.view');

$editId = (int)($_GET['id'] ?? ($_GET['edit'] ?? 0));

$editingBarangay = $editId > 0 ? admin_fetch_one(
    'SELECT id, name, city_municipality, status FROM barangays WHERE id = ? LIMIT 1',
    'i',
    [$editId]
) : null;

if ($editId > 0 && $editingBarangay === null) {
    admin_redirect(
        '/admin/barangays.php',
        [
            'notice' => 'Barangay not found.',
            'type' => 'error'
        ]
    );
}

$actions = '<a class="admin-btn-secondary" href="'
    . admin_e(app_url('/admin/barangays.php'))
    . '">' . admin_action_icon('back') . ' Barangays</a>';

admin_layout_start(
    $editingBarangay ? 'Edit Barangay' : 'Add Barangay',
    $editingBarangay ? 'Update the barangay record used across children, parents, nutritionists, and kiosks.' : 'Create a new barangay backed by the barangays table.',
    'barangays',
    $actions
);
?>
<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title"><?php echo $editingBarangay ? 'Edit Barangay' : 'Add Barangay'; ?></h2>
            <p class="admin-section-subtitle"><?php echo $editingBarangay ? admin_e((string)$editingBarangay['name']) . ', ' . admin_e((string)($editingBarangay['city_municipality'] ?? '')) : 'Pick the official barangay from the City of San Fernando master list.'; ?></p>
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
<?php
admin_layout_end();

<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();

$editId = (int)($_GET['id'] ?? ($_GET['edit'] ?? 0));

if ($editId > 0) {
    require_permission('parents.update');
} else {
    require_permission('parents.create');
}

$parentTypes = ['Father', 'Mother', 'Guardian', 'Grandparent', 'Other'];
$barangays = admin_barangay_options();

$editId = (int)($_GET['id'] ?? 0);
$editingParent = null;

if ($editId > 0) {
    $editingParent = admin_fetch_one(
        'SELECT id, name, email, parent_type, phone, address, barangay_id, local_area_id, status
         FROM parents WHERE id = ? LIMIT 1',
        'i',
        [$editId]
    );

    if ($editingParent === null) {
        admin_redirect('/admin/parents.php', ['notice' => 'Parent not found.', 'type' => 'error']);
    }
}

$editingNameParts = admin_split_full_name($editingParent['name'] ?? '');

$actions = '<a class="admin-btn-secondary" href="' . admin_e(app_url('/admin/parents.php')) . '">' . admin_action_icon('back') . ' Parents</a>';

admin_layout_start(
    $editingParent ? 'Edit Parent' : 'Add Parent',
    $editingParent ? 'Update this parent account.' : 'Create a new guardian record.',
    'parents',
    $actions,
    $editingParent ? 'Edit Parent' : 'Add Parent'
);
?>
<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title"><?php echo $editingParent ? 'Edit Parent' : 'Add Parent'; ?></h2>
            <p class="admin-section-subtitle"><?php echo $editingParent ? admin_e((string)$editingParent['name']) : 'Create a new guardian record.'; ?></p>
        </div>
    </div>

    <form class="admin-form-grid" method="post" data-validate-form action="<?php echo admin_e(app_url($editingParent ? '/api/admin/parents_update.php' : '/api/admin/parents_create.php')); ?>">
        <input type="hidden" name="action" value="<?php echo $editingParent ? 'update' : 'create'; ?>">
        <?php if ($editingParent): ?>
            <input type="hidden" name="id" value="<?php echo (int)$editingParent['id']; ?>">
        <?php endif; ?>

        <div class="admin-field-wide admin-flash is-error" data-validate-banner style="display:none;"></div>

        <div class="admin-field-wide">
            <div class="admin-field-row">
                <label class="admin-field">
                    <span>First name<span class="admin-required">*</span></span>
                    <input id="ap_first_name" name="first_name" required maxlength="60" data-validate="name" data-label="First name" value="<?php echo admin_e($editingNameParts['first']); ?>" placeholder="Juan">
                    <span class="admin-field-message"></span>
                </label>
                <label class="admin-field">
                    <span>Middle name</span>
                    <input id="ap_middle_name" name="middle_name" maxlength="60" data-validate="name" data-label="Middle name" value="<?php echo admin_e($editingNameParts['middle']); ?>" placeholder="Reyes">
                    <span class="admin-field-message"></span>
                </label>
                <label class="admin-field">
                    <span>Surname<span class="admin-required">*</span></span>
                    <input id="ap_last_name" name="last_name" required maxlength="60" data-validate="name" data-label="Surname" value="<?php echo admin_e($editingNameParts['last']); ?>" placeholder="Dela Cruz">
                    <span class="admin-field-message"></span>
                </label>
            </div>
        </div>

        <label class="admin-field">
            <span>Email<span class="admin-required">*</span></span>
            <input id="ap_email" type="email" name="email" required data-validate="email" value="<?php echo admin_e($editingParent['email'] ?? ''); ?>" placeholder="juan@example.com">
            <span class="admin-field-message"></span>
        </label>
        <div class="admin-field-wide">
            <div class="admin-field-row">
                <label class="admin-field">
                    <span>Mobile number<span class="admin-required">*</span></span>
                    <input id="ap_phone" name="phone" required data-validate="phone-ph" value="<?php echo admin_e($editingParent['phone'] ?? ''); ?>" placeholder="09171234567">
                    <span class="admin-field-message"></span>
                </label>
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
            <span>Assigned Barangay</span>
            <select name="barangay_id" id="ap-barangay-select">
                <option value="">-- Select Barangay --</option>
                <?php foreach ($barangays as $barangay): ?>
                    <option value="<?php echo (int)$barangay['id']; ?>" <?php echo (int)($editingParent['barangay_id'] ?? 0) === (int)$barangay['id'] ? 'selected' : ''; ?>><?php echo admin_e($barangay['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <small style="display:block;margin-top:5px;color:var(--admin-muted);font-size:11px;">Children will inherit this barangay.</small>
        </label>

        <label class="admin-field">
            <span>Local Area / Purok</span>
            <select name="local_area_id" id="ap-local-area-select" data-current-area="<?php echo (int)($editingParent['local_area_id'] ?? 0); ?>">
                <option value="">-- Select Local Area --</option>
            </select>
        </label>

        <label class="admin-field admin-field-wide">
            <span>Home address</span>
            <textarea id="ap_address" name="address"><?php echo admin_e($editingParent['address'] ?? ''); ?></textarea>
        </label>

        <label class="admin-field">
            <span>Status<span class="admin-required">*</span></span>
            <select name="status" required>
                <option value="active" <?php echo (($editingParent['status'] ?? 'active') === 'active') ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo (($editingParent['status'] ?? 'active') === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </label>

        <label class="admin-field admin-field-wide">
            <span><?php echo $editingParent ? 'New password (optional)' : 'Password'; ?><?php echo $editingParent ? '' : '<span class="admin-required">*</span>'; ?></span>
            <input id="ap_password" type="password" name="password" <?php echo $editingParent ? '' : 'required'; ?> data-validate="password" autocomplete="new-password" placeholder="<?php echo $editingParent ? 'Leave blank to keep current password' : 'Create a strong password'; ?>">
            <span class="admin-field-message"></span>
            <ul class="admin-pw-checklist" data-pw-checklist-for="ap_password">
                <li data-pw-rule="length">At least 8 characters</li>
                <li data-pw-rule="upper">One uppercase letter</li>
                <li data-pw-rule="lower">One lowercase letter</li>
                <li data-pw-rule="number">One number</li>
                <li data-pw-rule="special">One special character</li>
            </ul>
            <div class="admin-pw-strength" data-pw-strength-for="ap_password">
                <div class="admin-pw-strength-track"><div class="admin-pw-strength-fill"></div></div>
                <div class="admin-pw-strength-label"></div>
            </div>
        </label>
        <label class="admin-field admin-field-wide">
            <span><?php echo $editingParent ? 'Confirm new password' : 'Confirm password'; ?><?php echo $editingParent ? '' : '<span class="admin-required">*</span>'; ?></span>
            <input id="ap_password_confirm" type="password" name="password_confirm" <?php echo $editingParent ? '' : 'required'; ?> data-validate="confirm-password" data-match="ap_password" autocomplete="new-password" placeholder="Re-type the password">
            <span class="admin-field-message"></span>
        </label>

        <div class="admin-field admin-field-wide" style="align-content:end;">
            <button class="admin-btn" type="submit"><?php echo admin_action_icon('save') . ' ' . ($editingParent ? 'Save changes' : 'Create parent'); ?></button>
            <?php if ($editingParent): ?>
                <a class="admin-btn-secondary" href="<?php echo admin_e(app_url('/admin/parents.php')); ?>" style="margin-left:8px;"><?php echo admin_action_icon('cancel'); ?> Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<script>
(function() {
    var barangaySelect = document.getElementById('ap-barangay-select');
    var areaSelect = document.getElementById('ap-local-area-select');
    var currentAreaId = parseInt(areaSelect.getAttribute('data-current-area') || '0', 10);
    var apiBase = '<?php echo app_url("/api/admin/local_areas.php"); ?>';

    function loadAreas(barangayId, selectedId) {
        areaSelect.innerHTML = '<option value="">-- Select Local Area --</option>';
        if (!barangayId || barangayId <= 0) return;
        areaSelect.innerHTML += '<option value="" disabled>Loading...</option>';
        fetch(apiBase + '?barangay_id=' + barangayId)
            .then(function(r) { return r.json(); })
            .then(function(res) {
                areaSelect.innerHTML = '<option value="">-- Select Local Area --</option>';
                if (!res.success || !res.data || res.data.length === 0) {
                    areaSelect.innerHTML += '<option value="" disabled>No local areas registered</option>';
                    return;
                }
                res.data.forEach(function(area) {
                    var opt = document.createElement('option');
                    opt.value = area.id;
                    opt.textContent = area.area_type.charAt(0).toUpperCase() + area.area_type.slice(1) + ': ' + area.area_name;
                    if (selectedId && parseInt(opt.value, 10) === selectedId) opt.selected = true;
                    areaSelect.appendChild(opt);
                });
            })
            .catch(function() {
                areaSelect.innerHTML = '<option value="">-- Select Local Area --</option><option value="" disabled>Failed to load</option>';
            });
    }

    barangaySelect.addEventListener('change', function() {
        currentAreaId = 0;
        loadAreas(parseInt(barangaySelect.value || '0', 10), 0);
    });

    var initial = parseInt(barangaySelect.value || '0', 10);
    if (initial > 0) loadAreas(initial, currentAreaId);
})();
</script>

<?php
admin_layout_end();

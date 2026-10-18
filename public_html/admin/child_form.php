<?php

require_once __DIR__ . '/../includes/admin_helpers.php';
require_once __DIR__ . '/../includes/who_calculator.php';

start_secure_session();

$editId = (int)($_GET['id'] ?? ($_GET['edit'] ?? 0));

// Edit-only page. Creating records lives in the unified Add Family form, so
// stale create POSTs bounce back to the list instead of forking a second
// create path that could drift out of sync with the family form handler.
if ($editId <= 0) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        admin_redirect('/admin/children.php', ['notice' => 'Use the Add Family form to create records.', 'type' => 'error']);
    }
    admin_redirect('/admin/children.php', ['notice' => 'Select a child to edit.', 'type' => 'error']);
}

admin_require_access('children.update');

$editChild = admin_fetch_one(
    "SELECT c.id, c.child_code, c.first_name, c.middle_name, c.last_name, c.birthdate, c.sex,
            c.is_ip, c.has_disability, c.parent_id, c.local_area_id,
            p.name AS parent_name, bg.name AS barangay
     FROM children c
     INNER JOIN parents p ON p.id = c.parent_id
     LEFT JOIN barangays bg ON bg.id = c.barangay_id
     WHERE c.id = ? LIMIT 1",
    'i',
    [$editId]
);

if ($editChild === null) {
    admin_redirect('/admin/children.php', ['notice' => 'Child not found.', 'type' => 'error']);
}

// Previously submitted values after a validation error win over the DB values
// so the form is never wiped (same contract as user_form.php).
$formState = admin_take_form_state();
$old = $formState['old'];
$formErrorField = $formState['error_field'];
$formErrorNotice = trim((string)($_GET['notice'] ?? ''));
$hasState = $old !== [];

$formFirstName = admin_old_value($old, 'first_name', $editChild['first_name'] ?? '');
$formMiddleName = admin_old_value($old, 'middle_name', $editChild['middle_name'] ?? '');
$formLastName = admin_old_value($old, 'last_name', $editChild['last_name'] ?? '');
$formBirthdate = admin_old_value($old, 'birthdate', (string)($editChild['birthdate'] ?? ''));
$formSex = admin_old_value($old, 'sex', (string)($editChild['sex'] ?? 'Male'));
$formParentId = (int)admin_old_value($old, 'parent_id', (string)($editChild['parent_id'] ?? 0));
$formLocalAreaId = (int)admin_old_value($old, 'local_area_id', (string)($editChild['local_area_id'] ?? 0));
$formIsIp = $hasState ? !empty($old['is_ip']) : !empty($editChild['is_ip']);
$formHasDisability = $hasState ? !empty($old['has_disability']) : !empty($editChild['has_disability']);

$parents = admin_fetch_all(
    "SELECT p.id, p.name, p.barangay_id, bg.name AS barangay
     FROM parents p LEFT JOIN barangays bg ON bg.id = p.barangay_id
     ORDER BY p.name ASC"
);

$childName = trim(($editChild['first_name'] ?? '') . ' ' . ($editChild['middle_name'] ?? '') . ' ' . ($editChild['last_name'] ?? ''));
$fieldMessage = static fn (string $field): string => $formErrorField === $field && $formErrorNotice !== '' ? admin_e($formErrorNotice) : '';
$fieldError = static fn (string $field): string => $formErrorField === $field ? ' is-invalid' : '';

$actions = '<a class="admin-btn-secondary" href="' . admin_e(app_url('/admin/children.php')) . '">' . admin_action_icon('back') . ' Children</a>';

admin_layout_start(
    'Edit Child',
    'Update the child profile.',
    'children',
    $actions,
    'Edit Child'
);
?>
<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">Edit Child</h2>
            <p class="admin-section-subtitle"><?php echo admin_e($editChild['child_code'] . ' · ' . $childName); ?></p>
        </div>
    </div>

    <div class="admin-edit-layout">
        <form class="admin-form-grid" method="post" data-validate-form action="<?php echo admin_e(app_url('/api/admin/children_update.php')); ?>">
            <input type="hidden" name="id" value="<?php echo (int)$editChild['id']; ?>">

            <div class="admin-field-wide admin-flash is-error" data-validate-banner style="display:none;"></div>

            <section class="admin-edit-card admin-field-wide">
                <h3><?php echo admin_sidebar_icon('children'); ?> Personal information</h3>
                <p>The name and details shown on records and reports.</p>
                <div class="admin-field-row">
                    <label class="admin-field<?php echo $fieldError('first_name'); ?>">
                        <span>First name<span class="admin-required">*</span></span>
                        <input name="first_name" required maxlength="60" data-validate="name" data-label="First name" value="<?php echo admin_e($formFirstName); ?>" placeholder="Juan">
                        <span class="admin-field-message"><?php echo $fieldMessage('first_name'); ?></span>
                    </label>
                    <label class="admin-field">
                        <span>Middle name</span>
                        <input name="middle_name" maxlength="60" data-validate="name" data-label="Middle name" value="<?php echo admin_e($formMiddleName); ?>" placeholder="Santos">
                        <span class="admin-field-message"></span>
                    </label>
                    <label class="admin-field">
                        <span>Surname<span class="admin-required">*</span></span>
                        <input name="last_name" required maxlength="60" data-validate="name" data-label="Surname" value="<?php echo admin_e($formLastName); ?>" placeholder="Dela Cruz">
                        <span class="admin-field-message"></span>
                    </label>
                </div>
                <div class="admin-field-row" style="margin-top:10px;">
                    <label class="admin-field<?php echo $fieldError('birthdate'); ?>">
                        <span>Birthdate<span class="admin-required">*</span></span>
                        <input type="date" name="birthdate" required max="<?php echo admin_e(date('Y-m-d')); ?>" value="<?php echo admin_e($formBirthdate); ?>">
                        <span class="admin-field-message"><?php echo $fieldMessage('birthdate'); ?></span>
                    </label>
                    <label class="admin-field">
                        <span>Sex<span class="admin-required">*</span></span>
                        <select name="sex" required>
                            <option value="Male" <?php echo $formSex === 'Male' ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo $formSex === 'Female' ? 'selected' : ''; ?>>Female</option>
                        </select>
                    </label>
                </div>
            </section>

            <section class="admin-edit-card admin-field-wide">
                <h3><?php echo admin_sidebar_icon('parents'); ?> Guardian &amp; location</h3>
                <p>Who the child belongs to and where they live.</p>
                <label class="admin-field<?php echo $fieldError('parent_id'); ?>">
                    <span>Parent / guardian<span class="admin-required">*</span></span>
                    <select name="parent_id" data-child-parent required>
                        <option value="">-- Select parent / guardian --</option>
                        <?php foreach ($parents as $parent): ?>
                            <option value="<?php echo (int)$parent['id']; ?>" data-barangay-id="<?php echo (int)($parent['barangay_id'] ?? 0); ?>" data-barangay-name="<?php echo admin_e($parent['barangay'] ?? ''); ?>" <?php echo $formParentId === (int)$parent['id'] ? 'selected' : ''; ?>><?php echo admin_e($parent['name'] . ' · Barangay: ' . ($parent['barangay'] ?? 'Not assigned')); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="admin-field-message"><?php echo $fieldMessage('parent_id'); ?></span>
                </label>

                <label class="admin-field" style="margin-top:10px;">
                    <span>Barangay</span>
                    <input type="text" data-child-barangay readonly value="<?php echo admin_e($editChild['barangay'] ?? 'Not assigned'); ?>" style="background:var(--admin-surface-alt);">
                    <small class="admin-edit-hint">Automatically matches the selected parent.</small>
                </label>

                <label class="admin-field<?php echo $fieldError('local_area_id'); ?>" style="margin-top:10px;">
                    <span>Local area / Purok<span class="admin-required">*</span></span>
                    <select name="local_area_id" data-child-area data-current-area="<?php echo $formLocalAreaId; ?>" required>
                        <option value="">-- Select Local Area --</option>
                    </select>
                    <span class="admin-field-message"><?php echo $fieldMessage('local_area_id'); ?></span>
                </label>
            </section>

            <section class="admin-edit-card admin-field-wide">
                <h3><?php echo admin_sidebar_icon('clipboard'); ?> Additional details</h3>
                <p>Used for program eligibility and reports.</p>
                <div class="admin-edit-access-options child-edit-checks" style="grid-template-columns:repeat(2,minmax(0,1fr));">
                    <label class="admin-edit-access-option<?php echo $formIsIp ? ' is-selected' : ''; ?>">
                        <input type="checkbox" name="is_ip" value="1" <?php echo $formIsIp ? 'checked' : ''; ?>>
                        <strong>Indigenous People (IP)</strong>
                        <small>Child belongs to an indigenous group.</small>
                    </label>
                    <label class="admin-edit-access-option<?php echo $formHasDisability ? ' is-selected' : ''; ?>">
                        <input type="checkbox" name="has_disability" value="1" <?php echo $formHasDisability ? 'checked' : ''; ?>>
                        <strong>With disability</strong>
                        <small>Child has a documented disability.</small>
                    </label>
                </div>
            </section>

            <div class="admin-edit-actions admin-field-wide">
                <a class="admin-btn-secondary" href="<?php echo admin_e(app_url('/admin/children.php')); ?>"><?php echo admin_action_icon('cancel'); ?> Cancel</a>
                <button class="admin-btn" type="submit"><?php echo admin_action_icon('save'); ?> Save changes</button>
            </div>
        </form>

        <aside class="admin-edit-sidebar">
            <section class="admin-edit-card admin-user-summary">
                <div class="admin-user-summary-head">
                    <span class="admin-avatar" style="background:<?php echo admin_avatar_color($childName); ?>;"><?php echo admin_initials($childName); ?></span>
                    <div>
                        <strong><?php echo admin_e($childName); ?></strong>
                        <span>Child record</span>
                    </div>
                </div>
                <div class="admin-user-summary-status">
                    <?php echo sk_code_badge((string)($editChild['child_code'] ?? '')); ?>
                </div>
                <dl>
                    <dt>Child ID</dt><dd><?php echo admin_e((string)($editChild['child_code'] ?? '')); ?></dd>
                    <dt>Parent</dt><dd><?php echo admin_e((string)($editChild['parent_name'] ?? 'Not set')); ?></dd>
                    <dt>Barangay</dt><dd><?php echo admin_e((string)($editChild['barangay'] ?? 'Not assigned')); ?></dd>
                </dl>
            </section>

            <?php if (has_permission('children.delete')): ?>
            <section class="admin-edit-card admin-edit-archive">
                <h3>Archive child</h3>
                <p>Archived children are removed from active lists. Their measurements are kept.</p>
                <form method="post" action="<?php echo admin_e(app_url('/api/admin/children_archive.php')); ?>" data-admin-confirm="Archive <?php echo admin_e($childName); ?>?">
                    <input type="hidden" name="id" value="<?php echo (int)$editChild['id']; ?>">
                    <button class="admin-btn-danger" type="submit"><?php echo admin_action_icon('archive'); ?> Archive child</button>
                </form>
            </section>
            <?php endif; ?>
        </aside>
    </div>
</section>

<script>
(function () {
    var parentSelect = document.querySelector('[data-child-parent]');
    var barangayInput = document.querySelector('[data-child-barangay]');
    var areaSelect = document.querySelector('[data-child-area]');
    if (!parentSelect || !areaSelect) return;

    var currentAreaId = parseInt(areaSelect.getAttribute('data-current-area') || '0', 10);
    var apiBase = '<?php echo app_url("/api/admin/local_areas.php"); ?>';

    function selectedParent() {
        var opt = parentSelect.options[parentSelect.selectedIndex];
        return {
            id: opt ? parseInt(opt.getAttribute('data-barangay-id') || '0', 10) : 0,
            name: opt ? (opt.getAttribute('data-barangay-name') || '') : ''
        };
    }

    function loadAreas(barangayId, selectedId) {
        areaSelect.innerHTML = '<option value="">-- Select Local Area --</option>';
        if (!barangayId || barangayId <= 0) {
            areaSelect.disabled = true;
            return;
        }
        areaSelect.disabled = false;
        areaSelect.innerHTML += '<option value="" disabled>Loading…</option>';
        fetch(apiBase + '?barangay_id=' + encodeURIComponent(barangayId))
            .then(function (r) { return r.json(); })
            .then(function (res) {
                areaSelect.innerHTML = '<option value="">-- Select Local Area --</option>';
                if (!res.success || !res.data || res.data.length === 0) {
                    areaSelect.innerHTML += '<option value="" disabled>No local areas registered</option>';
                    return;
                }
                res.data.forEach(function (area) {
                    if (parseInt(area.is_active, 10) !== 1 && parseInt(area.id, 10) !== selectedId) return;
                    var opt = document.createElement('option');
                    opt.value = area.id;
                    opt.textContent = area.area_type.charAt(0).toUpperCase() + area.area_type.slice(1) + ': ' + area.area_name;
                    if (selectedId && parseInt(opt.value, 10) === selectedId) opt.selected = true;
                    areaSelect.appendChild(opt);
                });
            })
            .catch(function () {
                areaSelect.innerHTML = '<option value="">-- Select Local Area --</option><option value="" disabled>Failed to load</option>';
            });
    }

    parentSelect.addEventListener('change', function () {
        currentAreaId = 0;
        if (barangayInput) barangayInput.value = selectedParent().name || 'Not assigned';
        loadAreas(selectedParent().id, 0);
    });

    var info = selectedParent();
    if (info.id > 0) loadAreas(info.id, currentAreaId);
})();
</script>

<?php
admin_layout_end();

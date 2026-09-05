<?php

require_once __DIR__ . '/../includes/admin_helpers.php';
require_once __DIR__ . '/../includes/who_calculator.php';

start_secure_session();

$editId = (int)($_GET['id'] ?? ($_GET['edit'] ?? 0));

if ($editId > 0) {
    require_permission('children.update');
} else {
    require_permission('children.create');
}

function admin_next_child_code(): string
{
    $row = admin_fetch_one('SELECT child_code FROM children ORDER BY id DESC LIMIT 1');
    $lastCode = (string)($row['child_code'] ?? 'CHD-0000');

    if (preg_match('/(\d+)$/', $lastCode, $matches) !== 1) {
        return 'CHD-0001';
    }

    return 'CHD-' . str_pad((string)(((int)$matches[1]) + 1), 4, '0', STR_PAD_LEFT);
}

$editId = (int)($_GET['id'] ?? 0);
$editChild = null;

if ($editId > 0) {
    $editChild = admin_fetch_one(
        "SELECT c.id, c.child_code, c.first_name, c.middle_name, c.last_name, c.birthdate, c.sex,
                c.is_ip, c.has_disability, c.parent_id, c.local_area_id,
                p.name AS parent_name, bg.name AS barangay, bg.id AS barangay_id_resolved,
                la.area_name AS local_area_name
         FROM children c
         INNER JOIN parents p ON p.id = c.parent_id
         LEFT JOIN barangays bg ON bg.id = c.barangay_id
         LEFT JOIN local_areas la ON la.id = c.local_area_id
         WHERE c.id = ? LIMIT 1",
        'i',
        [$editId]
    );

    if ($editChild === null) {
        admin_redirect('/admin/children.php', ['notice' => 'Child not found.', 'type' => 'error']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $childId = (int)($_POST['id'] ?? 0);

    if ($action === 'create' && !has_permission('children.create')) {
        admin_redirect('/admin/children.php', ['notice' => 'You do not have permission to create children.', 'type' => 'error']);
    }

    if ($action === 'update' && !has_permission('children.update')) {
        admin_redirect('/admin/children.php', ['notice' => 'You do not have permission to update children.', 'type' => 'error']);
    }

    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $middleName = trim((string)($_POST['middle_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    $birthdate = trim((string)($_POST['birthdate'] ?? ''));
    $sex = trim((string)($_POST['sex'] ?? 'Male'));
    $isIp = isset($_POST['is_ip']) ? 1 : 0;
    $hasDisability = isset($_POST['has_disability']) ? 1 : 0;
    $parentId = (int)($_POST['parent_id'] ?? 0);
    $localAreaId = (int)($_POST['local_area_id'] ?? 0);

    $errorBackUrl = '/admin/child_form.php' . ($childId > 0 ? '?id=' . $childId : '');

    if (
        !admin_is_valid_name_part($firstName, true)
        || !admin_is_valid_name_part($middleName, false)
        || !admin_is_valid_name_part($lastName, true)
        || $birthdate === ''
        || $parentId <= 0
    ) {
        admin_redirect($errorBackUrl, ['notice' => 'First name, last name, birthdate, and parent are required.', 'type' => 'error']);
    }

    $parent = admin_fetch_one('SELECT id, barangay_id FROM parents WHERE id = ? LIMIT 1', 'i', [$parentId]);

    if (!$parent) {
        admin_redirect($errorBackUrl, ['notice' => 'Selected parent/guardian could not be found.', 'type' => 'error']);
    }

    $barangayId = !empty($parent['barangay_id']) ? (int)$parent['barangay_id'] : null;

    if ($barangayId === null) {
        admin_redirect($errorBackUrl, ['notice' => 'The selected parent/guardian does not have a Barangay assigned.', 'type' => 'error']);
    }

    $validatedLocalAreaId = null;
    if ($localAreaId > 0) {
        $localArea = admin_fetch_one(
            'SELECT id FROM local_areas WHERE id = ? AND barangay_id = ? AND is_active = 1 LIMIT 1',
            'ii',
            [$localAreaId, $barangayId]
        );
        if ($localArea) {
            $validatedLocalAreaId = (int)$localArea['id'];
        }
    }

    // Form-level age validation: 0-5 years inclusive (the eOPT Plus
    // scope). The day count is the canonical check now -- 60 months
    // is roughly 1825 days, so we use that as the upper bound.
    $registrationAgeDays = doh_age_in_days($birthdate);

    if ($registrationAgeDays === null || $registrationAgeDays > 1825) {
        admin_redirect($errorBackUrl, ['notice' => 'Birthdate must be valid and the child must be 5 years (~1825 days) old or younger.', 'type' => 'error']);
    }

    if (!in_array($sex, ['Male', 'Female'], true)) {
        $sex = 'Male';
    }

    if ($action === 'update' && $childId > 0) {
        $current = admin_fetch_one('SELECT child_code FROM children WHERE id = ? LIMIT 1', 'i', [$childId]);
        $childCode = (string)($current['child_code'] ?? admin_next_child_code());

        $ok = admin_execute(
            'UPDATE children SET child_code = ?, first_name = ?, middle_name = ?, last_name = ?, birthdate = ?, sex = ?, barangay_id = ?, local_area_id = ?, is_ip = ?, has_disability = ?, parent_id = ? WHERE id = ?',
            'ssssssssiiii',
            [$childCode, $firstName, $middleName, $lastName, $birthdate, $sex, $barangayId, $validatedLocalAreaId ?? 0, $isIp, $hasDisability, $parentId, $childId]
        );

        if ($ok) {
            $actor = current_user();
            log_action($actor['id'] ?? null, 'UPDATE_CHILD', 'info', 'Updated child ' . $childCode . ' (' . $childId . ')');
        }

        admin_redirect('/admin/children.php', $ok ? ['notice' => 'Child updated successfully.'] : ['notice' => 'Child could not be updated.', 'type' => 'error']);
    }

    if ($action === 'create') {
        $childCode = admin_next_child_code();

        $ok = admin_execute(
            'INSERT INTO children (child_code, first_name, middle_name, last_name, birthdate, sex, barangay_id, local_area_id, is_ip, has_disability, parent_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'ssssssssiii',
            [$childCode, $firstName, $middleName, $lastName, $birthdate, $sex, $barangayId, $validatedLocalAreaId ?? 0, $isIp, $hasDisability, $parentId]
        );

        if ($ok) {
            $actor = current_user();
            log_action($actor['id'] ?? null, 'CREATE_CHILD', 'info', 'Created child ' . $childCode);
        }

        admin_redirect('/admin/children.php', $ok ? ['notice' => 'Child added successfully.'] : ['notice' => 'Child could not be added.', 'type' => 'error']);
    }

    admin_redirect('/admin/children.php', ['notice' => 'No action was performed.', 'type' => 'error']);
}

$parents = admin_fetch_all(
    "SELECT p.id, p.name, p.parent_type, p.status, p.barangay_id, bg.name AS barangay
     FROM parents p LEFT JOIN barangays bg ON bg.id = p.barangay_id
     ORDER BY p.name ASC"
);

$actions = '<a class="admin-btn-secondary" href="' . admin_e(app_url('/admin/children.php')) . '">' . admin_action_icon('back') . ' Children</a>';

admin_layout_start(
    $editChild !== null ? 'Edit Child' : 'Add Child',
    $editChild !== null ? 'Update the child profile.' : 'Create a new child record.',
    'children',
    $actions,
    $editChild !== null ? 'Edit Child' : 'Add Child'
);
?>
<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title"><?php echo $editChild !== null ? 'Edit Child' : 'Add Child'; ?></h2>
            <p class="admin-section-subtitle"><?php echo $editChild !== null ? admin_e($editChild['child_code'] . ' · ' . trim($editChild['first_name'] . ' ' . ($editChild['middle_name'] ?? '') . ' ' . $editChild['last_name'])) : 'Create a new child record.'; ?></p>
        </div>
    </div>

    <form class="admin-form-grid" method="post" data-validate-form>
        <input type="hidden" name="action" value="<?php echo $editChild !== null ? 'update' : 'create'; ?>">
        <?php if ($editChild !== null): ?>
            <input type="hidden" name="id" value="<?php echo (int)$editChild['id']; ?>">
        <?php endif; ?>

        <div class="admin-field-wide admin-flash is-error" data-validate-banner style="display:none;"></div>

        <div class="admin-field-wide">
            <div class="admin-field-row">
                <label class="admin-field">
                    <span>First name<span class="admin-required">*</span></span>
                    <input name="first_name" required maxlength="60" data-validate="name" data-label="First name" value="<?php echo admin_e($editChild['first_name'] ?? ''); ?>" placeholder="Juan">
                    <span class="admin-field-message"></span>
                </label>
                <label class="admin-field">
                    <span>Middle name</span>
                    <input name="middle_name" maxlength="60" data-validate="name" data-label="Middle name" value="<?php echo admin_e($editChild['middle_name'] ?? ''); ?>" placeholder="Santos">
                    <span class="admin-field-message"></span>
                </label>
                <label class="admin-field">
                    <span>Surname<span class="admin-required">*</span></span>
                    <input name="last_name" required maxlength="60" data-validate="name" data-label="Surname" value="<?php echo admin_e($editChild['last_name'] ?? ''); ?>" placeholder="Dela Cruz">
                    <span class="admin-field-message"></span>
                </label>
            </div>
        </div>

        <label class="admin-field">
            <span>Birthdate<span class="admin-required">*</span></span>
            <input type="date" name="birthdate" required max="<?php echo admin_e(date('Y-m-d')); ?>" value="<?php echo admin_e($editChild['birthdate'] ?? ''); ?>">
        </label>

        <label class="admin-field">
            <span>Sex<span class="admin-required">*</span></span>
            <select name="sex" required>
                <option value="Male" <?php echo (($editChild['sex'] ?? 'Male') === 'Male') ? 'selected' : ''; ?>>Male</option>
                <option value="Female" <?php echo (($editChild['sex'] ?? '') === 'Female') ? 'selected' : ''; ?>>Female</option>
            </select>
        </label>

        <label class="admin-field">
            <span>Parent/Guardian<span class="admin-required">*</span></span>
            <select name="parent_id" id="ac-parent-select" required>
                <option value="">-- Select Parent --</option>
                <?php foreach ($parents as $parent): ?>
                    <option value="<?php echo (int)$parent['id']; ?>" data-barangay-id="<?php echo (int)($parent['barangay_id'] ?? 0); ?>" <?php echo ((int)($editChild['parent_id'] ?? 0) === (int)$parent['id']) ? 'selected' : ''; ?>>
                        <?php echo admin_e($parent['name'] . ' · Barangay: ' . ($parent['barangay'] ?? 'Not assigned')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small style="display:block;margin-top:5px;color:var(--admin-muted);font-size:11px;">The child's Barangay will match the selected parent.</small>
        </label>

        <label class="admin-field">
            <span>Barangay</span>
            <input type="text" value="<?php echo admin_e($editChild['barangay'] ?? 'Automatically assigned from parent'); ?>" readonly style="background:var(--admin-surface-alt);">
        </label>

        <label class="admin-field">
            <span>Local Area / Purok</span>
            <select name="local_area_id" id="ac-local-area-select" data-current-area="<?php echo (int)($editChild['local_area_id'] ?? 0); ?>">
                <option value="">-- Select Local Area --</option>
            </select>
        </label>

        <label class="admin-field admin-field-checkbox">
            <input type="checkbox" name="is_ip" value="1" <?php echo !empty($editChild['is_ip']) ? 'checked' : ''; ?>>
            <span>Belongs to IP (Indigenous Peoples) group</span>
        </label>

        <label class="admin-field admin-field-checkbox">
            <input type="checkbox" name="has_disability" value="1" <?php echo !empty($editChild['has_disability']) ? 'checked' : ''; ?>>
            <span>Has a disability</span>
        </label>

        <div class="admin-field admin-field-wide" style="align-content:end;">
            <button class="admin-btn" type="submit"><?php echo admin_action_icon('save') . ' ' . ($editChild !== null ? 'Save changes' : 'Create child'); ?></button>
            <?php if ($editChild !== null): ?>
                <a class="admin-btn-secondary" href="<?php echo admin_e(app_url('/admin/children.php')); ?>" style="margin-left:8px;"><?php echo admin_action_icon('cancel'); ?> Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<script>
(function() {
    var parentSelect = document.getElementById('ac-parent-select');
    var areaSelect = document.getElementById('ac-local-area-select');
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

    function getParentBarangayId() {
        var selected = parentSelect.options[parentSelect.selectedIndex];
        if (!selected || !selected.value) return 0;
        return parseInt(selected.getAttribute('data-barangay-id') || '0', 10);
    }

    parentSelect.addEventListener('change', function() {
        currentAreaId = 0;
        loadAreas(getParentBarangayId(), 0);
    });

    var initialBarangayId = getParentBarangayId();
    if (initialBarangayId > 0) loadAreas(initialBarangayId, currentAreaId);
})();
</script>

<?php
admin_layout_end();

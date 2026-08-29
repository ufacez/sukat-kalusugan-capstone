<?php

require_once __DIR__ . '/../includes/parent_helpers.php';
require_once __DIR__ . '/../includes/who_calculator.php';

$user = parent_require_access();

$parentId = (int)$user['id'];
$childId = (int)($_GET['id'] ?? 0);

if ($childId <= 0) {
    admin_redirect('/parent/children.php', ['notice' => 'Invalid child id.', 'type' => 'error']);
}

// Load the child — only if it belongs to this parent
$editChild = admin_fetch_one(
    "SELECT c.id, c.child_code, c.first_name, c.middle_name, c.last_name, c.birthdate, c.sex,
            c.is_ip, c.has_disability, c.barangay_id, c.local_area_id,
            bg.name AS barangay, la.area_name AS local_area_name
     FROM children c
     LEFT JOIN barangays bg ON bg.id = c.barangay_id
     LEFT JOIN local_areas la ON la.id = c.local_area_id
     WHERE c.id = ? AND c.parent_id = ?
     LIMIT 1",
    'ii',
    [$childId, $parentId]
);

if ($editChild === null) {
    admin_redirect('/parent/children.php', ['notice' => 'Child not found.', 'type' => 'error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $middleName = trim((string)($_POST['middle_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    $birthdate = trim((string)($_POST['birthdate'] ?? ''));
    $sex = trim((string)($_POST['sex'] ?? 'Male'));
    $isIp = isset($_POST['is_ip']) ? 1 : 0;
    $hasDisability = isset($_POST['has_disability']) ? 1 : 0;

    if (
        !admin_is_valid_name_part($firstName, true)
        || !admin_is_valid_name_part($middleName, false)
        || !admin_is_valid_name_part($lastName, true)
        || $birthdate === ''
    ) {
        admin_redirect('/parent/child_edit.php?id=' . $childId, ['notice' => 'First name, last name, and birthdate are required.', 'type' => 'error']);
    }

    if (!in_array($sex, ['Male', 'Female'], true)) {
        $sex = 'Male';
    }

    $ok = admin_execute(
        'UPDATE children SET first_name = ?, middle_name = ?, last_name = ?, birthdate = ?, sex = ?, is_ip = ?, has_disability = ? WHERE id = ? AND parent_id = ?',
        'ssssssiii',
        [$firstName, $middleName, $lastName, $birthdate, $sex, $isIp, $hasDisability, $childId, $parentId]
    );

    if ($ok) {
        $actor = current_user();
        log_action($actor['id'] ?? null, 'UPDATE_CHILD', 'info', 'Parent updated child ' . $editChild['child_code'] . ' (' . $childId . ')');
    }

    admin_redirect('/parent/children.php', $ok ? ['notice' => 'Child profile updated.'] : ['notice' => 'Child profile could not be updated.', 'type' => 'error']);
}

$editingNameParts = admin_split_full_name($editChild['name'] ?? $editChild['first_name'] . ' ' . ($editChild['middle_name'] ?? '') . ' ' . $editChild['last_name']);
$editingNameParts = [
    'first' => $editChild['first_name'] ?? '',
    'middle' => $editChild['middle_name'] ?? '',
    'last' => $editChild['last_name'] ?? '',
];

$actions = '<a class="admin-btn-secondary" href="' . parent_e(app_url('/parent/children.php')) . '">' . admin_action_icon('back') . ' Children</a>';

parent_layout_start(
    'Edit Child',
    'Update basic information for ' . $editChild['child_code'] . '.',
    'children',
    $actions
);
?>
<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">Edit Child</h2>
            <p class="admin-section-subtitle"><?php echo parent_e($editChild['child_code'] . ' · ' . trim($editChild['first_name'] . ' ' . ($editChild['middle_name'] ?? '') . ' ' . $editChild['last_name'])); ?></p>
        </div>
    </div>

    <form class="admin-form-grid" method="post" data-validate-form>
        <div class="admin-field-wide admin-flash is-error" data-validate-banner style="display:none;"></div>

        <div class="admin-field-wide">
            <div class="admin-field-row">
                <label class="admin-field">
                    <span>First name<span class="admin-required">*</span></span>
                    <input name="first_name" required maxlength="60" data-validate="name" data-label="First name" value="<?php echo parent_e($editChild['first_name']); ?>" placeholder="Juan">
                    <span class="admin-field-message"></span>
                </label>
                <label class="admin-field">
                    <span>Middle name</span>
                    <input name="middle_name" maxlength="60" data-validate="name" data-label="Middle name" value="<?php echo parent_e($editChild['middle_name'] ?? ''); ?>" placeholder="Santos">
                    <span class="admin-field-message"></span>
                </label>
                <label class="admin-field">
                    <span>Surname<span class="admin-required">*</span></span>
                    <input name="last_name" required maxlength="60" data-validate="name" data-label="Surname" value="<?php echo parent_e($editChild['last_name']); ?>" placeholder="Dela Cruz">
                    <span class="admin-field-message"></span>
                </label>
            </div>
        </div>

        <label class="admin-field">
            <span>Birthdate<span class="admin-required">*</span></span>
            <input type="date" name="birthdate" required max="<?php echo parent_e(date('Y-m-d')); ?>" value="<?php echo parent_e($editChild['birthdate']); ?>">
        </label>

        <label class="admin-field">
            <span>Sex<span class="admin-required">*</span></span>
            <select name="sex" required>
                <option value="Male" <?php echo ($editChild['sex'] === 'Male') ? 'selected' : ''; ?>>Male</option>
                <option value="Female" <?php echo ($editChild['sex'] === 'Female') ? 'selected' : ''; ?>>Female</option>
            </select>
        </label>

        <label class="admin-field">
            <span>Barangay</span>
            <input type="text" value="<?php echo parent_e($editChild['barangay'] ?? ''); ?>" readonly style="background:var(--admin-surface-alt);">
            <small style="display:block;margin-top:5px;color:var(--admin-muted);font-size:11px;">Barangay is assigned from your account and cannot be changed here.</small>
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
            <button class="admin-btn" type="submit"><?php echo admin_action_icon('save'); ?> Save changes</button>
            <a class="admin-btn-secondary" href="<?php echo parent_e(app_url('/parent/children.php')); ?>" style="margin-left:8px;"><?php echo admin_action_icon('cancel'); ?> Cancel</a>
        </div>
    </form>
</section>

<?php
parent_layout_end();

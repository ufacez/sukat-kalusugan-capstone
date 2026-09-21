<?php

require_once __DIR__ . '/../includes/admin_helpers.php';
require_once __DIR__ . '/../includes/who_calculator.php';

start_secure_session();
require_permission('children.view');

$canAddChild = has_permission('children.create');

// Handle create POST — the Add Child form is a modal on this page.
// Validation errors reopen the modal with values preserved.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    if (!$canAddChild) {
        admin_redirect('/admin/children.php', ['notice' => 'You do not have permission to create children.', 'type' => 'error']);
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

    $childBack = '/admin/children.php?modal=child';

    if (
        !admin_is_valid_name_part($firstName, true)
        || !admin_is_valid_name_part($middleName, false)
        || !admin_is_valid_name_part($lastName, true)
        || $birthdate === ''
        || $parentId <= 0
    ) {
        admin_flash_form_state($_POST, 'first_name');
        admin_redirect($childBack, ['notice' => 'First name, last name, birthdate, and parent are required.', 'type' => 'error']);
    }

    $parent = admin_fetch_one('SELECT id, barangay_id FROM parents WHERE id = ? LIMIT 1', 'i', [$parentId]);

    if (!$parent) {
        admin_flash_form_state($_POST, 'parent_id');
        admin_redirect($childBack, ['notice' => 'Selected parent/guardian could not be found.', 'type' => 'error']);
    }

    $barangayId = !empty($parent['barangay_id']) ? (int)$parent['barangay_id'] : null;

    if ($barangayId === null) {
        admin_flash_form_state($_POST, 'parent_id');
        admin_redirect($childBack, ['notice' => 'The selected parent/guardian does not have a Barangay assigned.', 'type' => 'error']);
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
        } else {
            admin_flash_form_state($_POST, 'local_area_id');
            admin_redirect($childBack, ['notice' => 'Selected Local Area is inactive or does not belong to the selected Barangay.', 'type' => 'error']);
        }
    }

    // Form-level age validation: 0-5 years inclusive (the eOPT Plus
    // scope). The day count is the canonical check now -- 60 months
    // is roughly 1825 days, so we use that as the upper bound.
    $registrationAgeDays = doh_age_in_days($birthdate);

    if ($registrationAgeDays === null || $registrationAgeDays > 1825) {
        admin_flash_form_state($_POST, 'birthdate');
        admin_redirect($childBack, ['notice' => 'Birthdate must be valid and the child must be 5 years (~1825 days) old or younger.', 'type' => 'error']);
    }

    if (!in_array($sex, ['Male', 'Female'], true)) {
        $sex = 'Male';
    }

    $childCode = admin_next_child_code();

    $ok = admin_execute(
        'INSERT INTO children (child_code, first_name, middle_name, last_name, birthdate, sex, barangay_id, local_area_id, is_ip, has_disability, parent_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        'ssssssssiii',
        [$childCode, $firstName, $middleName, $lastName, $birthdate, $sex, $barangayId, $validatedLocalAreaId ?? null, $isIp, $hasDisability, $parentId]
    );

    if ($ok) {
        $actor = current_user();
        log_action($actor['id'] ?? null, 'CREATE_CHILD', 'info', 'Created child ' . $childCode);
    }

    admin_clear_form_state();
    admin_redirect('/admin/children.php', $ok ? ['notice' => 'Child added successfully.'] : ['notice' => 'Child could not be added.', 'type' => 'error']);
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

$editId = (int)($_GET['edit'] ?? 0);

if ($editId > 0) {
    admin_redirect('/admin/child_form.php?id=' . $editId);
}

$filterBarangay = (int)($_GET['barangay_id'] ?? 0);

$where = "WHERE c.status = 'active'";
$params = [];
$types = '';

if ($filterBarangay > 0) {
    $where .= " AND c.barangay_id = ?";
    $params[] = $filterBarangay;
    $types = 'i';
}

$children = admin_fetch_all(
    "SELECT
        c.id,
        c.child_code,
        c.first_name,
        c.middle_name,
        c.last_name,
        c.birthdate,
        c.sex,
        c.barangay_id,
        bg.name AS barangay,
        c.parent_id,
        p.name AS parent_name,
        lm.measurement_date,
        lm.created_at AS measured_at,
        lm.height_cm,
        lm.weight_kg,
        lm.nutritional_status
     FROM children c
     INNER JOIN parents p ON p.id = c.parent_id
     LEFT JOIN barangays bg ON bg.id = c.barangay_id
     LEFT JOIN measurements lm ON lm.id = (
        SELECT m.id FROM measurements m WHERE m.child_id = c.id
        ORDER BY m.measurement_date DESC, m.id DESC LIMIT 1
     )
     $where
     ORDER BY c.id DESC",
    $types,
    $params
);

$archivedCountRow = admin_fetch_one("SELECT COUNT(*) AS cnt FROM children WHERE status = 'inactive'");
$archivedCount = (int)($archivedCountRow['cnt'] ?? 0);

$barangays = admin_fetch_all("SELECT id, name FROM barangays WHERE status = 'active' ORDER BY name ASC");

$parentOptions = [];
$cold = [];
$cFormErrorField = null;
$cFormErrorNotice = trim((string)($_GET['notice'] ?? ''));
$childModalOpen = false;

if ($canAddChild) {
    $parentOptions = admin_fetch_all(
        "SELECT p.id, p.name, p.parent_type, p.status, p.barangay_id, bg.name AS barangay
         FROM parents p LEFT JOIN barangays bg ON bg.id = p.barangay_id
         ORDER BY p.name ASC"
    );
    $cfState = admin_take_form_state();
    $cold = $cfState['old'];
    $cFormErrorField = $cfState['error_field'];
    $childModalOpen = ($_GET['modal'] ?? '') === 'child';
}

$actions = '';
if ($canAddChild) {
    $actions .= '<button class="admin-btn" type="button" data-child-open>' . admin_action_icon('add') . ' Add child</button>';
}

admin_layout_start('Children', 'Registered child profiles, growth status, and nutritional tracking.', 'children', $actions);
?>
<section class="admin-grid-cards">
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Active Children</div>
                <div class="admin-card-value"><?php echo count($children); ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-up">Currently active profiles</span>
                </div>
            </div>
        </div>
    </article>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon is-muted">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5m6 4.125l2.25 2.25m0 0l2.25 2.25M12 13.875l2.25-2.25M12 13.875l-2.25 2.25M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Archived</div>
                <div class="admin-card-value"><?php echo $archivedCount; ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend"><a href="<?php echo admin_e(app_url('/admin/children_archived.php')); ?>" style="color:var(--admin-primary);text-decoration:underline;">View archived children</a></span>
                </div>
            </div>
        </div>
    </article>
</section>

<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">Children Directory</h2>
            <p class="admin-section-subtitle">All active children<?php echo $filterBarangay > 0 ? ' in filtered barangay' : ' across all barangays'; ?>.</p>
        </div>
        <div class="admin-toolbar" style="margin:0;gap:8px;">
            <select class="admin-select" id="barangay-filter" onchange="window.location.href='<?php echo admin_e(app_url('/admin/children.php')); ?>?barangay_id='+this.value">
                <option value="0">All Barangays</option>
                <?php foreach ($barangays as $b): ?>
                    <option value="<?php echo (int)$b['id']; ?>" <?php echo $filterBarangay === (int)$b['id'] ? 'selected' : ''; ?>><?php echo admin_e($b['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <input class="admin-search" type="search" placeholder="Search children" data-admin-filter="#children-table" style="flex:1;min-width:0;">
        </div>
    </div>

    <div class="admin-table-wrap">
        <table class="admin-table" id="children-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Parent</th>
                    <th>Barangay</th>
                    <th>Last Measurement</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($children as $childIndex => $child): ?>
                    <tr<?php echo admin_paged_row_attr($childIndex, 10); ?> data-filter-text="<?php echo admin_e(strtolower($child['child_code'] . ' ' . $child['first_name'] . ' ' . ($child['middle_name'] ?? '') . ' ' . $child['last_name'] . ' ' . $child['parent_name'] . ' ' . (string)($child['barangay'] ?? ''))); ?>">
                        <td style="font-family:monospace;color:var(--admin-muted);"><?php echo admin_e($child['child_code']); ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <span class="admin-avatar" style="background:<?php echo admin_avatar_color($child['first_name'] . ' ' . $child['last_name']); ?>;width:32px;height:32px;font-size:0.7rem;"><?php echo admin_initials($child['first_name'] . ' ' . $child['last_name']); ?></span>
                                <div>
                                    <div style="font-weight:700;"><?php echo admin_e(trim($child['first_name'] . ' ' . ($child['middle_name'] ?? '') . ' ' . $child['last_name'])); ?></div>
                                    <div class="admin-mini">
                                        <?php
                                            $age = doh_age((string)$child['birthdate']) ?? ['days' => 0, 'months' => 0];
                                            echo admin_e((string)$child['sex']);
                                            echo ' &middot; ';
                                            echo (int)$age['days']; ?> day<?php echo (int)$age['days'] === 1 ? '' : 's'; ?> · <?php echo (int)$age['months']; ?> months
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td style="color:var(--admin-muted);"><?php echo admin_e((string)$child['parent_name']); ?></td>
                        <td style="color:var(--admin-muted);"><?php echo admin_e((string)($child['barangay'] ?? '')); ?></td>
                        <td>
                            <?php if (!empty($child['measurement_date'])): ?>
                                <?php $md = (string)$child['measurement_date']; ?>
                                <?php $measuredAt = !empty($child['measured_at']) ? (string)$child['measured_at'] : null; ?>
                                <div style="font-weight:600;font-size:0.82rem;"><?php echo admin_e(date('M j Y', strtotime($md))); ?></div>
                                <div class="admin-mini"><?php echo $measuredAt !== null ? admin_e(date('H:i', strtotime($measuredAt))) : '—'; ?></div>
                            <?php else: ?>
                                <span style="color:var(--admin-muted);">n/a</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (has_permission('children.update') || has_permission('children.delete')): ?>
                            <div class="admin-actions">
                                <?php if (has_permission('children.update')): ?>
                                    <a class="admin-icon-btn" title="Edit" href="<?php echo admin_e(app_url('/admin/child_form.php?id=' . (int)$child['id'])); ?>"><?php echo admin_action_icon('edit'); ?></a>
                                <?php endif; ?>
                                <?php if (has_permission('children.delete')): ?>
                                    <form method="post" action="<?php echo admin_e(app_url('/api/admin/children_archive.php')); ?>" data-admin-confirm="Archive <?php echo admin_e($child['first_name']); ?>?" style="display:inline;">
                                        <input type="hidden" name="id" value="<?php echo (int)$child['id']; ?>">
                                        <button class="admin-icon-btn admin-icon-btn-danger" title="Archive" type="submit">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5m6 4.125l2.25 2.25m0 0l2.25 2.25M12 13.875l2.25-2.25M12 13.875l-2.25 2.25M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>
                                        </button>
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

<?php if ($canAddChild): ?>
<div class="admin-modal-overlay" id="child-overlay"<?php echo $childModalOpen ? '' : ' hidden'; ?>>
    <div class="admin-modal admin-modal--form" role="dialog" aria-modal="true" aria-labelledby="child-modal-title">
        <div class="admin-modal-head">
            <h3 id="child-modal-title">Add Child</h3>
            <button class="admin-modal-close" data-child-close type="button" aria-label="Close">&times;</button>
        </div>
        <div class="admin-modal-body">
            <p class="admin-section-subtitle" style="margin:0 0 14px;">Create a new child record. The barangay is inherited from the selected parent. <span class="admin-required">*</span> Required field.</p>
            <form class="admin-form-grid" method="post" data-validate-form action="<?php echo admin_e(app_url('/admin/children.php')); ?>">
                <input type="hidden" name="action" value="create">

                <div class="admin-field-wide admin-flash is-error" data-validate-banner style="display:none;"></div>

                <div class="admin-field-wide">
                    <div class="admin-field-row">
                        <label class="admin-field<?php echo $cFormErrorField === 'first_name' ? ' is-invalid' : ''; ?>">
                            <span>First name<span class="admin-required">*</span></span>
                            <input name="first_name" required maxlength="60" data-validate="name" data-label="First name" value="<?php echo admin_e(admin_old_value($cold, 'first_name')); ?>" placeholder="Juan">
                            <span class="admin-field-message"><?php echo $cFormErrorField === 'first_name' ? admin_e($cFormErrorNotice) : ''; ?></span>
                        </label>
                        <label class="admin-field">
                            <span>Middle name</span>
                            <input name="middle_name" maxlength="60" data-validate="name" data-label="Middle name" value="<?php echo admin_e(admin_old_value($cold, 'middle_name')); ?>" placeholder="Santos">
                            <span class="admin-field-message"></span>
                        </label>
                        <label class="admin-field">
                            <span>Surname<span class="admin-required">*</span></span>
                            <input name="last_name" required maxlength="60" data-validate="name" data-label="Surname" value="<?php echo admin_e(admin_old_value($cold, 'last_name')); ?>" placeholder="Dela Cruz">
                            <span class="admin-field-message"></span>
                        </label>
                    </div>
                </div>

                <label class="admin-field<?php echo $cFormErrorField === 'birthdate' ? ' is-invalid' : ''; ?>">
                    <span>Birthdate<span class="admin-required">*</span></span>
                    <input type="date" name="birthdate" required max="<?php echo admin_e(date('Y-m-d')); ?>" value="<?php echo admin_e(admin_old_value($cold, 'birthdate')); ?>">
                    <span class="admin-field-message"><?php echo $cFormErrorField === 'birthdate' ? admin_e($cFormErrorNotice) : ''; ?></span>
                </label>

                <label class="admin-field">
                    <span>Sex<span class="admin-required">*</span></span>
                    <select name="sex" required>
                        <option value="Male" <?php echo admin_old_value($cold, 'sex', 'Male') === 'Male' ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo admin_old_value($cold, 'sex', '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                    </select>
                </label>

                <label class="admin-field<?php echo $cFormErrorField === 'parent_id' ? ' is-invalid' : ''; ?>">
                    <span>Parent/Guardian<span class="admin-required">*</span></span>
                    <select name="parent_id" id="cm-parent-select" required>
                        <option value="">-- Select Parent --</option>
                        <?php foreach ($parentOptions as $parent): ?>
                            <option value="<?php echo (int)$parent['id']; ?>" data-barangay-id="<?php echo (int)($parent['barangay_id'] ?? 0); ?>" <?php echo admin_old_value($cold, 'parent_id', '') !== '' && (int)admin_old_value($cold, 'parent_id') === (int)$parent['id'] ? 'selected' : ''; ?>>
                                <?php echo admin_e($parent['name'] . ' · Barangay: ' . ($parent['barangay'] ?? 'Not assigned')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="admin-field-message"><?php echo $cFormErrorField === 'parent_id' ? admin_e($cFormErrorNotice) : ''; ?></span>
                    <small style="display:block;margin-top:5px;color:var(--admin-muted);font-size:11px;">The child's Barangay will match the selected parent.</small>
                </label>

                <label class="admin-field<?php echo $cFormErrorField === 'local_area_id' ? ' is-invalid' : ''; ?>">
                    <span>Local Area / Purok</span>
                    <select name="local_area_id" id="cm-local-area-select" data-current-area="<?php echo (int)admin_old_value($cold, 'local_area_id', '0'); ?>">
                        <option value="">-- Select Local Area --</option>
                    </select>
                    <span class="admin-field-message"><?php echo $cFormErrorField === 'local_area_id' ? admin_e($cFormErrorNotice) : ''; ?></span>
                </label>

                <label class="admin-field admin-field-checkbox">
                    <input type="checkbox" name="is_ip" value="1" <?php echo admin_old_value($cold, 'is_ip', '') !== '' ? 'checked' : ''; ?>>
                    <span>Belongs to IP (Indigenous Peoples) group</span>
                </label>

                <label class="admin-field admin-field-checkbox">
                    <input type="checkbox" name="has_disability" value="1" <?php echo admin_old_value($cold, 'has_disability', '') !== '' ? 'checked' : ''; ?>>
                    <span>Has a disability</span>
                </label>

                <div class="admin-field admin-field-wide" style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;">
                    <button class="admin-btn-secondary" type="button" data-child-close>Cancel</button>
                    <button class="admin-btn" type="submit"><?php echo admin_action_icon('save'); ?> Create child</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function(){
    var overlay = document.getElementById('child-overlay');
    if (!overlay) return;
    function openChild() {
        overlay.hidden = false;
        document.body.style.overflow = 'hidden';
    }
    function closeChild() {
        overlay.hidden = true;
        document.body.style.overflow = '';
    }
    document.querySelectorAll('[data-child-open]').forEach(function(b) {
        b.addEventListener('click', openChild);
    });
    overlay.querySelectorAll('[data-child-close]').forEach(function(b) {
        b.addEventListener('click', closeChild);
    });
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closeChild();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && !overlay.hidden) closeChild();
    });
})();
(function() {
    var parentSelect = document.getElementById('cm-parent-select');
    var areaSelect = document.getElementById('cm-local-area-select');
    if (!parentSelect || !areaSelect) return;
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
                    if (parseInt(area.is_active, 10) !== 1 && parseInt(area.id, 10) !== selectedId) return;
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
<?php endif; ?>

<?php
admin_layout_end();

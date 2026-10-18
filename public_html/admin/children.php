<?php

require_once __DIR__ . '/../includes/admin_helpers.php';
require_once __DIR__ . '/../includes/who_calculator.php';

start_secure_session();
admin_require_access('children.view');

$canAddChild = has_permission('children.create');


$filterBarangay = (int)($_GET['barangay_id'] ?? 0);
$filterSex = in_array(($_GET['sex'] ?? ''), ['Male', 'Female'], true) ? (string)$_GET['sex'] : '';

$where = "WHERE c.status = 'active'";
$params = [];
$types = '';

if ($filterBarangay > 0) {
    $where .= " AND c.barangay_id = ?";
    $params[] = $filterBarangay;
    $types = 'i';
}
if ($filterSex !== '') {
    $where .= " AND c.sex = ?";
    $params[] = $filterSex;
    $types .= 's';
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
        c.local_area_id,
        la.area_name AS local_area_name,
        c.is_ip,
        c.has_disability,
        bg.name AS barangay,
        c.parent_id,
        p.name AS parent_name,
        lm.measurement_date,
        lm.created_at AS measured_at,
        lm.height_cm,
        lm.weight_kg,
        lm.nutritional_status
     FROM children c
     LEFT JOIN parents p ON p.id = c.parent_id
     LEFT JOIN barangays bg ON bg.id = c.barangay_id
     LEFT JOIN local_areas la ON la.id = c.local_area_id
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

$canEditChild = has_permission('children.update');

// Parent options for the in-page edit modal (mirrors the users.php edit
// modal: one in-page form on the list page instead of a separate iframe).
$parents = $canEditChild
    ? admin_fetch_all(
        "SELECT p.id, p.name, p.barangay_id, bg.name AS barangay
         FROM parents p LEFT JOIN barangays bg ON bg.id = p.barangay_id
         ORDER BY p.name ASC"
    )
    : [];

// Children are created together with their parent/guardian through the
// unified Add Family form (/admin/family_form.php), matching the
// nutritionist workflow. The old standalone "Add Child" modal was removed.
$actions = '';
if ($canAddChild && has_permission('parents.create')) {
    $actions .= '<a class="admin-btn" href="' . admin_e(app_url('/admin/family_form.php')) . '">' . admin_action_icon('add') . ' Add family</a>';
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
                    <span class="admin-card-trend"><a href="<?php echo admin_e(app_url('/admin/archive.php?tab=archived#archive-table-wrap')); ?>" style="color:var(--admin-primary);text-decoration:underline;">View archived children</a></span>
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
            <select class="admin-select" id="sex-filter" aria-label="Filter by sex" onchange="window.location.href='<?php echo admin_e(app_url('/admin/children.php')); ?>?barangay_id=<?php echo (int)$filterBarangay; ?>&sex='+this.value">
                <option value="" <?php echo $filterSex === '' ? 'selected' : ''; ?>>All sexes</option>
                <option value="Male" <?php echo $filterSex === 'Male' ? 'selected' : ''; ?>>Male</option>
                <option value="Female" <?php echo $filterSex === 'Female' ? 'selected' : ''; ?>>Female</option>
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
                    <th>Sex</th>
                    <th>Parent</th>
                    <th>Barangay</th>
                    <th>Last Measurement</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($children as $childIndex => $child): ?>
                    <tr<?php echo admin_paged_row_attr($childIndex, 10); ?> data-filter-text="<?php echo admin_e(strtolower((string)$child['child_code'] . ' ' . (string)$child['first_name'] . ' ' . (string)($child['middle_name'] ?? '') . ' ' . (string)$child['last_name'] . ' ' . (string)($child['parent_name'] ?? '') . ' ' . (string)($child['barangay'] ?? ''))); ?>">
                        <td style="font-family:monospace;"><?php echo admin_e($child['child_code']); ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <span class="admin-avatar" style="background:var(--admin-primary);color:var(--admin-text-on-primary);width:32px;height:32px;font-size:0.7rem;"><?php echo admin_initials($child['first_name'] . ' ' . $child['last_name']); ?></span>
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
                        <td><?php echo admin_e((string)$child['sex']); ?></td>
                        <td><?php echo admin_e((string)$child['parent_name']); ?></td>
                        <td><?php echo admin_e((string)($child['barangay'] ?? '')); ?></td>
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
                                    <button class="admin-icon-btn" title="Edit" type="button"
                                        data-child-edit
                                        data-child-id="<?php echo (int)$child['id']; ?>"
                                        data-first-name="<?php echo admin_e($child['first_name']); ?>"
                                        data-middle-name="<?php echo admin_e($child['middle_name'] ?? ''); ?>"
                                        data-last-name="<?php echo admin_e($child['last_name']); ?>"
                                        data-birthdate="<?php echo admin_e((string)$child['birthdate']); ?>"
                                        data-sex="<?php echo admin_e((string)$child['sex']); ?>"
                                        data-is-ip="<?php echo !empty($child['is_ip']) ? '1' : '0'; ?>"
                                        data-has-disability="<?php echo !empty($child['has_disability']) ? '1' : '0'; ?>"
                                        data-parent-id="<?php echo (int)$child['parent_id']; ?>"
                                        data-parent-name="<?php echo admin_e($child['parent_name'] ?? ''); ?>"
                                        data-barangay="<?php echo admin_e(($child['barangay'] ?? '')); ?>"
                                        data-local-area-id="<?php echo (int)($child['local_area_id'] ?? 0); ?>"
                                        data-local-area-name="<?php echo admin_e((string)($child['local_area_name'] ?? '')); ?>"><?php echo admin_action_icon('edit'); ?></button>
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

<?php if ($canEditChild): ?>
<div class="admin-modal-overlay" id="child-edit-overlay" hidden>
    <form class="admin-modal admin-modal--form child-edit-modal" method="post" data-validate-form action="<?php echo admin_e(app_url('/api/admin/children_update.php')); ?>">
        <div class="admin-modal-head">
            <div>
                <h3>Edit child</h3>
                <p class="child-edit-subtitle">Update the selected child record.</p>
            </div>
            <button class="admin-modal-close" type="button" data-child-edit-close aria-label="Close">&times;</button>
        </div>
        <div class="child-edit-body">
            <input type="hidden" name="id" value="">
            <input type="hidden" name="parent_id" value="">
            <input type="hidden" name="local_area_id" value="">
            <section class="invite-form-card child-edit-card">
                <h4 class="invite-form-card-title"><?php echo admin_sidebar_icon('children'); ?> Personal information</h4>
                <p>The child's name and birth details.</p>
                <div class="admin-field-row">
                    <label class="admin-field"><span>First name<span class="admin-required">*</span></span><input name="first_name" required data-validate="name"><span class="admin-field-message"></span></label>
                    <label class="admin-field"><span>Middle name</span><input name="middle_name" data-validate="name"><span class="admin-field-message"></span></label>
                    <label class="admin-field"><span>Surname<span class="admin-required">*</span></span><input name="last_name" required data-validate="name"><span class="admin-field-message"></span></label>
                </div>
                <div class="admin-field-row" style="margin-top:10px;">
                    <label class="admin-field"><span>Birthdate<span class="admin-required">*</span></span><input type="date" name="birthdate" required><span class="admin-field-message"></span></label>
                    <label class="admin-field"><span>Sex<span class="admin-required">*</span></span><select name="sex" required><option value="Male">Male</option><option value="Female">Female</option></select></label>
                </div>
            </section>
            <section class="invite-form-card child-edit-full child-edit-card">
                <h4 class="invite-form-card-title"><?php echo admin_sidebar_icon('children'); ?> Additional details</h4>
                <div class="admin-edit-access-options child-edit-checks" style="grid-template-columns:repeat(2,minmax(0,1fr));">
                    <label class="admin-edit-access-option"><input type="checkbox" name="is_ip" value="1"><strong>Indigenous People (IP)</strong><small>Child belongs to an indigenous group.</small></label>
                    <label class="admin-edit-access-option"><input type="checkbox" name="has_disability" value="1"><strong>With disability</strong><small>Child has a documented disability.</small></label>
                </div>
            </section>
            <div class="admin-edit-actions"><button class="admin-btn-secondary" type="button" data-child-edit-close>Cancel</button><button class="admin-btn" type="submit" data-child-edit-save hidden><?php echo admin_action_icon('save'); ?> Save changes</button></div>
        </div>
    </form>
</div>
<?php endif; ?>
<style>
body.admin-page .child-edit-modal{width:min(720px,calc(100vw - 40px)) !important;max-width:min(720px,calc(100vw - 40px)) !important;max-height:calc(100dvh - 32px);border-radius:18px;overflow:hidden}
.child-edit-modal .admin-modal-head{padding:20px 24px 14px;align-items:flex-start}
.child-edit-modal .admin-modal-head h3{font-size:1.35rem;letter-spacing:-.02em}
.child-edit-subtitle{margin:3px 0 0;color:var(--admin-muted);font-size:.82rem}
.child-edit-body{display:grid;grid-template-columns:minmax(0,1fr);align-items:start;gap:10px;padding:14px 24px 16px;background:var(--admin-page-gradient-start);max-height:calc(94vh - 66px);overflow-y:auto}
.child-edit-body .invite-form-card{align-self:start;padding:12px 14px;border:1px solid var(--admin-border);border-radius:14px;background:var(--admin-surface)}
.child-edit-body .invite-form-card-title{margin:0 0 7px;line-height:1.15}
.child-edit-body .invite-form-card > p{margin:0 0 7px;color:var(--admin-muted);font-size:.66rem;line-height:1.2}
.child-edit-body .invite-form-card .admin-field-row{gap:8px}
.child-edit-body .invite-form-card .admin-field{gap:.25rem}
.child-edit-body .invite-form-card input,
.child-edit-body .invite-form-card select{height:46px;padding:.65rem .8rem;border-radius:10px}
.child-edit-body .invite-form-card-title svg{width:17px;height:17px;flex:0 0 17px}
.child-edit-body .admin-field-message{font-size:.66rem;line-height:1.15;min-height:.75em}
.child-edit-body .admin-edit-access-options{gap:6px}
.child-edit-body .admin-edit-access-option{min-height:58px;padding:8px}
.child-edit-body .admin-edit-actions{margin:2px -24px -16px;padding:12px 24px 14px;border-top:1px solid var(--admin-border);background:var(--admin-surface)}
@media(max-width:620px){
  body.admin-page .child-edit-modal{width:calc(100vw - 24px) !important;max-width:calc(100vw - 24px) !important}
  .child-edit-body{padding:12px 16px 16px}
  .child-edit-body .admin-edit-actions{margin:2px -16px -16px;padding:10px 16px 12px}
}
</style>
<script>
(function(){
    var overlay = document.getElementById('child-edit-overlay');
    if (!overlay) return;
    var form = overlay.querySelector('form');
    var saveBtn = form.querySelector('[data-child-edit-save]');
    var checkOptions = overlay.querySelectorAll('.admin-edit-access-option');
    var hiddenParentId = form.querySelector('[name="parent_id"]');
    var hiddenAreaId = form.querySelector('[name="local_area_id"]');
    var baseline = '';

    function field(name){ return form.querySelector('[name="' + name + '"]'); }
    function set(name, value){ var f = field(name); if (f) f.value = value || ''; }

    function syncChecks(){
        checkOptions.forEach(function (option) {
            var cb = option.querySelector("input[type='checkbox']");
            option.classList.toggle('is-selected', !!cb && cb.checked);
        });
    }

    function snapshot(){
        return Array.prototype.slice.call(form.querySelectorAll('input,select,textarea'))
            .filter(function (f) { return f.type !== 'hidden' && f.name; })
            .map(function (f) { return f.name + '=' + (f.type === 'checkbox' ? (f.checked ? '1' : '0') : f.value); })
            .join('&');
    }
    function refresh(){ if (!saveBtn) return; var dirty = snapshot() !== baseline; saveBtn.hidden = !dirty; saveBtn.disabled = !dirty; }

    function open(button){
        set('id', button.dataset.childId);
        set('first_name', button.dataset.firstName);
        set('middle_name', button.dataset.middleName);
        set('last_name', button.dataset.lastName);
        set('birthdate', button.dataset.birthdate);
        set('sex', button.dataset.sex || 'Male');
        hiddenParentId.value = button.dataset.parentId || '';
        hiddenAreaId.value = button.dataset.localAreaId || '';
        field('is_ip').checked = button.dataset.isIp === '1';
        field('has_disability').checked = button.dataset.hasDisability === '1';
        syncChecks();
        baseline = snapshot();
        refresh();
        overlay.hidden = false;
        document.body.classList.add('admin-modal-open');
        var first = field('first_name');
        if (first) first.focus();
    }

    function close(){
        overlay.hidden = true;
        document.body.classList.remove('admin-modal-open');
    }

    form.addEventListener('input', refresh);
    form.addEventListener('change', refresh);

    document.querySelectorAll('[data-child-edit]').forEach(function (b) {
        b.addEventListener('click', function () { open(this); });
    });
    overlay.querySelectorAll('[data-child-edit-close]').forEach(function (b) {
        b.addEventListener('click', close);
    });
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !overlay.hidden) close(); });
})();
</script>

<?php
admin_layout_end();

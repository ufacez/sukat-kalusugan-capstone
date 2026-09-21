<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/who_calculator.php';

$user = nutritionist_require_access();

/*
 * Filter / view params
 */
$localAreaFilter = (int)($_GET['local_area_id'] ?? 0);
$validTabs = ['active', 'graduated', 'archived'];
$tab = in_array(($_GET['tab'] ?? ''), $validTabs, true) ? ($_GET['tab'] ?? '') : 'active';

// Archive / restore a single child (same behavior as the admin endpoints,
// but scoped to the nutritionist's barangay).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $childId = (int)($_POST['id'] ?? 0);

    if (($action === 'archive' || $action === 'restore') && $childId > 0) {
        nutritionist_require_write('children.delete');

        $target = admin_fetch_one('SELECT id, child_code, barangay_id, status FROM children WHERE id = ? LIMIT 1', 'i', [$childId]);

        if ($target === null) {
            admin_redirect('/nutritionist/children.php', ['notice' => 'Child not found.', 'type' => 'error']);
        }

        if (($user['role'] ?? '') !== 'admin' && (int)($target['barangay_id'] ?? 0) !== (int)($user['barangay_id'] ?? 0)) {
            admin_redirect('/nutritionist/children.php', ['notice' => 'You can only manage children within your assigned barangay.', 'type' => 'error']);
        }

        $newStatus = $action === 'archive' ? 'inactive' : 'active';

        if (($target['status'] ?? '') === $newStatus) {
            admin_redirect('/nutritionist/children.php', ['notice' => $action === 'archive' ? 'Child is already archived.' : 'Child is already active.', 'type' => 'error']);
        }

        $ok = admin_execute('UPDATE children SET status = ? WHERE id = ?', 'si', [$newStatus, $childId]);

        if ($ok) {
            $actor = current_user();
            $actionLabel = $newStatus === 'inactive' ? 'Archived' : 'Restored';
            log_action($actor['id'] ?? null, 'UPDATE_CHILD', 'warning', $actionLabel . ' child ' . $target['child_code'] . ' (' . $childId . ')');
        }

        $backTab = $newStatus === 'inactive' ? '?tab=archived' : '';
        admin_redirect(
            '/nutritionist/children.php' . $backTab,
            $ok
                ? ['notice' => 'Child ' . ($newStatus === 'inactive' ? 'archived' : 'restored') . ' successfully.']
                : ['notice' => 'Child could not be updated.', 'type' => 'error']
        );
    }
}

$childrenParams = [];
$childrenScope = nutritionist_scope_fragment($user, 'c.barangay_id', $childrenParams);

$where = [$childrenScope];
$types = str_repeat('i', count($childrenParams));
$filterParams = $childrenParams;

if ($localAreaFilter > 0) {
    $where[] = 'c.local_area_id = ?';
    $types .= 'i';
    $filterParams[] = $localAreaFilter;
}
$where[] = 'c.status = ?';
$types .= 's';
$filterParams[] = $tab === 'archived' ? 'inactive' : 'active';
if ($tab === 'graduated') {
    $where[] = 'TIMESTAMPDIFF(MONTH, c.birthdate, CURDATE()) >= 60';
} elseif ($tab === 'active') {
    $where[] = 'TIMESTAMPDIFF(MONTH, c.birthdate, CURDATE()) <= 59';
}
// Archived has no age filter: it includes manually archived records and
// 60+ month children auto-archived by the auto-archive tool.
$whereSql = implode(' AND ', $where);

/*
 * Full fetch — the per-nutritionist dataset is bounded so we filter
 * status in PHP only when needed. The children page doesn't filter by
 * status, so we slice directly.
 *
 * NOTE: the live `children` table does not have `address` or `purok`
 * columns (they only exist in the schema.sql file). Address info is
 * pulled from the parent's `address` field instead.
 */
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
        c.is_ip,
        c.has_disability,
        c.parent_id,
        c.household_id,
        bg.name AS barangay,
        la.area_name AS local_area,
        la.area_type,
        p.name AS parent_name,
        p.parent_type AS parent_kind,
        p.phone AS parent_phone,
        p.email AS parent_email,
        p.address AS parent_address,
        h.household_code AS household_code,
        h.address AS household_address,
        h.lat AS household_lat,
        h.lng AS household_lng,
        lm.measurement_date AS last_measurement_date,
        lm.weight_kg AS last_weight,
        lm.height_cm AS last_height,
        lm.nutritional_status AS last_nutritional_status,
        lm.wfa_status AS last_wfa,
        lm.hfa_status AS last_hfa,
        lm.wfh_status AS last_wfh
     FROM children c
     INNER JOIN parents p ON p.id = c.parent_id
     LEFT JOIN barangays bg ON bg.id = c.barangay_id
     LEFT JOIN local_areas la ON la.id = c.local_area_id
     LEFT JOIN households h ON h.id = c.household_id AND h.status = 'active'
     LEFT JOIN measurements lm ON lm.id = (
        SELECT m2.id FROM measurements m2
        WHERE m2.child_id = c.id
        ORDER BY m2.measurement_date DESC, m2.id DESC
        LIMIT 1
     )
     WHERE {$whereSql}
     ORDER BY c.id DESC",
    $types,
    $filterParams
);

/*
 * No server-side pagination here on purpose: the full filtered list is
 * rendered and assets/js/admin.js paginates client-side (5/page) so the
 * search box filters across ALL rows, not just the current page.
 * Newest child (highest id / latest child_code like CH0015) is first
 * via ORDER BY c.id DESC above, so a newly added child shows on page 1.
 */
$totalAll = count($children);
$pageChildren = $children;

/*
 * Count children for each tab (badge numbers).
 */
$countActiveRows = admin_fetch_all(
    "SELECT COUNT(*) AS cnt FROM children c WHERE c.status = 'active' AND TIMESTAMPDIFF(MONTH, c.birthdate, CURDATE()) <= 59 AND {$childrenScope}",
    str_repeat('i', count($childrenParams)),
    $childrenParams
);
$countActive = (int)(($countActiveRows[0]['cnt'] ?? 0));
$countGraduatedRows = admin_fetch_all(
    "SELECT COUNT(*) AS cnt FROM children c WHERE c.status = 'active' AND TIMESTAMPDIFF(MONTH, c.birthdate, CURDATE()) >= 60 AND {$childrenScope}",
    str_repeat('i', count($childrenParams)),
    $childrenParams
);
$countGraduated = (int)(($countGraduatedRows[0]['cnt'] ?? 0));
$countArchivedRows = admin_fetch_all(
    "SELECT COUNT(*) AS cnt FROM children c WHERE c.status = 'inactive' AND {$childrenScope}",
    str_repeat('i', count($childrenParams)),
    $childrenParams
);
$countArchived = (int)(($countArchivedRows[0]['cnt'] ?? 0));

/*
 * Local area list for the filter dropdown. The list is restricted to
 * the user's barangay scope so a Dela Paz Norte nutritionist can only
 * filter to local areas inside Dela Paz Norte.
 */
$localAreaParams = [];
$localAreaScope = nutritionist_scope_fragment($user, 'la.barangay_id', $localAreaParams);
$localAreaList = admin_fetch_all(
    "SELECT la.id, la.area_name, la.area_type, la.barangay_id, bg.name AS barangay
     FROM local_areas la
     INNER JOIN barangays bg ON bg.id = la.barangay_id
     WHERE la.is_active = 1 AND {$localAreaScope}
     ORDER BY bg.name ASC, la.area_name ASC",
    str_repeat('i', count($localAreaParams)),
    $localAreaParams
);

function nutritionist_children_url(array $params): string
{
    global $tab;
    $base = app_url('/nutritionist/children.php');
    $params['tab'] = $params['tab'] ?? $tab;
    $merged = array_filter($params, static fn($v) => $v !== '' && $v !== null);
    return $merged === [] ? $base : $base . '?' . http_build_query($merged);
}

function nchild_full_address(?string $barangay, ?string $localArea, ?string $address, ?string $purok): string
{
    $parts = [];
    if ($purok !== null && trim($purok) !== '') {
        $parts[] = trim($purok);
    }
    if ($localArea !== null && trim($localArea) !== '') {
        $parts[] = trim($localArea);
    }
    if ($address !== null && trim($address) !== '') {
        $parts[] = trim($address);
    }
    if ($barangay !== null && trim($barangay) !== '') {
        $parts[] = trim($barangay);
    }
    return $parts === [] ? '—' : implode(', ', $parts);
}

function nchild_short_address(?string $localArea, ?string $barangay): string
{
    $local = trim((string)($localArea ?? ''));
    $brgy = trim((string)($barangay ?? ''));
    if ($local !== '' && $brgy !== '') {
        return $local . ' · ' . $brgy;
    }
    return $local !== '' ? $local : ($brgy !== '' ? $brgy : '—');
}

$actions = '<div class="admin-actions">'
    . (nutritionist_can_write('children.create')
        ? '<a class="admin-btn" href="' . nutritionist_e(app_url('/nutritionist/family_form.php')) . '">' . admin_action_icon('add') . ' Add family</a>'
        : '')
    . ((nutritionist_can_write('parents.create') && nutritionist_can_write('children.create'))
        ? '<a class="admin-btn-secondary" href="' . nutritionist_e(app_url('/nutritionist/family_import.php')) . '">' . admin_action_icon('export') . ' Master-list import</a>'
        : '')
    . (nutritionist_can_write()
        ? '<a class="admin-btn-secondary" href="' . nutritionist_e(app_url('/nutritionist/measurement_record.php')) . '">' . admin_action_icon('measure') . ' Add measurement</a>'
        : '')
    . '</div>';

nutritionist_layout_start(
    'Children',
    'Registered child profiles. Click any row to view the child information card.',
    'children',
    $actions
);
?>
<style>
.rp-tabs{display:flex;gap:0;border-bottom:2px solid var(--admin-border);margin:0 0 14px}
.rp-tab{display:inline-flex;align-items:center;gap:6px;padding:10px 18px;font-size:13px;font-weight:600;color:var(--admin-muted);text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;transition:color .15s,border-color .15s,background .15s;border-radius:8px 8px 0 0}
.rp-tab:hover{color:var(--admin-text);background:var(--admin-surface-alt)}
.rp-tab.is-active{color:var(--admin-primary);border-bottom-color:var(--admin-primary);background:transparent}
.rp-tab span{font-size:11px;opacity:.6}
.children-toolbar{display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;align-items:center}
.children-toolbar .admin-search{flex:1;min-width:220px}
.children-toolbar .admin-select{min-width:200px;max-width:260px}

.children-table .child-name-cell{display:flex;align-items:center;gap:10px;min-width:0}
.children-table .child-name-cell .avatar{width:34px;height:34px;border-radius:50%;background:#94a3b8;color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.children-table .child-name-cell .text{min-width:0}
.children-table .child-name-cell .text .name{font-weight:600;color:var(--admin-text);font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.children-table .child-name-cell .text .sub{font-size:10px;color:var(--admin-muted);margin-top:1px}

.children-table .address-cell{max-width:240px}
.children-table .address-cell .primary{font-size:12px;color:var(--admin-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.children-table .address-cell .sub{font-size:10px;color:var(--admin-muted);margin-top:1px}

.children-table .row-link{cursor:pointer;transition:background-color .12s}
.children-table .row-link:hover td{background:var(--admin-surface-alt)}

.children-empty{padding:32px 18px;color:var(--admin-muted);font-size:13px;background:var(--admin-surface-alt);border-radius:10px;border:1px dashed var(--admin-border);text-align:center;display:flex;flex-direction:column;align-items:center;gap:10px}
.children-empty .empty-title{font-weight:700;color:var(--admin-text);font-size:14px}
.children-empty .empty-sub{color:var(--admin-muted);max-width:420px;line-height:1.45}

.children-pagination{display:flex;align-items:center;justify-content:space-between;padding:12px 4px 0;flex-wrap:wrap;gap:8px}
.children-pagination .status{font-size:11px;color:var(--admin-muted)}
.children-pagination .pages{display:flex;align-items:center;gap:4px;flex-wrap:wrap}
.children-pagination .page-btn{display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:30px;padding:0 8px;border:1px solid var(--admin-border);border-radius:7px;background:var(--admin-surface);color:var(--admin-text);font-size:11px;font-weight:600;text-decoration:none}
.children-pagination .page-btn.is-active{background:var(--admin-primary);border-color:var(--admin-primary);color:#fff}
.children-pagination .page-btn.is-disabled{opacity:.4;pointer-events:none}

/* Child detail modal — landscape, senior-friendly (large text, grouped columns) */
.cc-overlay{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:center;justify-content:center;z-index:1000;padding:20px}
.cc-overlay.is-open{display:flex}
.cc-modal{background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:14px;width:100%;max-width:1000px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.18);display:flex;flex-direction:column}
.cc-head{display:flex;align-items:center;gap:14px;padding:18px 22px;border-bottom:1px solid var(--admin-border);position:sticky;top:0;background:var(--admin-surface);z-index:1}
.cc-head .avatar{width:60px;height:60px;border-radius:50%;background:#94a3b8;color:#fff;font-weight:700;font-size:20px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.cc-head .meta{min-width:0;flex:1}
.cc-head .name{font-size:19px;font-weight:700;color:var(--admin-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cc-head .sub{font-size:13px;color:var(--admin-muted);margin-top:2px}
.cc-head .close{background:none;border:none;color:var(--admin-muted);font-size:28px;line-height:1;cursor:pointer;padding:8px 14px;border-radius:8px;min-width:48px;min-height:48px}
.cc-head .close:hover{background:var(--admin-surface-alt);color:var(--admin-text)}

.cc-body{padding:20px 22px}
.cc-measure{background:var(--admin-primary-soft);border:1px solid var(--admin-border);border-radius:12px;padding:14px 16px;margin-bottom:16px}
.cc-measure .m-title{font-size:13px;font-weight:700;color:var(--admin-text);text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px}
.cc-measure .m-grid{display:flex;gap:18px;flex-wrap:wrap;align-items:center}
.cc-measure .m-stat{display:flex;flex-direction:column;gap:2px;min-width:90px}
.cc-measure .m-stat .k{font-size:12px;color:var(--admin-muted);font-weight:600}
.cc-measure .m-stat .v{font-size:17px;font-weight:700;color:var(--admin-text)}
.cc-measure .m-pills{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-left:auto}
.cc-measure .m-pills .admin-pill{font-size:13px;padding:5px 12px}
.cc-measure .m-empty{font-size:14px;color:var(--admin-muted);font-style:italic}
.cc-cols{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
.cc-card{background:var(--admin-surface-alt);border:1px solid var(--admin-border);border-radius:12px;padding:14px 16px;min-width:0}
.cc-section{font-weight:700;font-size:13px;color:var(--admin-text);text-transform:uppercase;letter-spacing:.05em;margin:0 0 6px}
.cc-row{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:9px 0;border-bottom:1px solid var(--admin-border);font-size:14px}
.cc-row:last-child{border-bottom:none}
.cc-row .label{color:var(--admin-muted);font-weight:500;flex-shrink:0}
.cc-row .value{font-weight:600;color:var(--admin-text);text-align:right;flex:1;min-width:0;word-break:break-word}
.cc-foot{display:flex;justify-content:flex-end;gap:8px;padding:14px 22px;border-top:1px solid var(--admin-border);background:var(--admin-surface-alt);border-bottom-left-radius:14px;border-bottom-right-radius:14px}
.cc-foot .admin-btn,.cc-foot .admin-btn-secondary{min-height:44px;display:inline-flex;align-items:center;font-size:14px}

@media (max-width: 860px) {
  .cc-cols{grid-template-columns:1fr}
  .cc-measure .m-pills{margin-left:0}
}
@media (max-width: 560px) {
  .children-toolbar{flex-direction:column;align-items:stretch}
  .children-toolbar .admin-search{min-width:0;flex:1}
  .children-toolbar .admin-select{min-width:0;max-width:100%;width:100%}
  .cc-modal{max-width:calc(100vw - 16px);max-height:85vh}
  .cc-row{flex-direction:column;gap:4px}
  .cc-row .value{text-align:left}
  .cc-head{padding:14px 16px}
  .cc-body{padding:14px 16px}
  .cc-foot{padding:12px 16px}
}
</style>

<section class="nutritionist-panel">
    <div class="nutritionist-form-head" style="margin-bottom:14px;">
        <div>
            <h2 class="admin-section-title" style="margin-bottom:2px;">Children directory</h2>
            <p class="admin-section-subtitle">
                Registered children in your scope. Click a row to view the child information card, or use the action buttons.
            </p>
        </div>
    </div>

    <div class="children-toolbar">
        <input
            class="admin-search"
            data-admin-filter="#children-table"
            type="search"
            placeholder="Search by name, code, guardian, or address..."
        >
        <select
            class="admin-select"
            id="local-area-filter"
            onchange="window.location.href=this.value"
        >
            <option value="<?php echo nutritionist_e(nutritionist_children_url([])); ?>">All local areas</option>
            <?php foreach ($localAreaList as $la): ?>
                <option
                    value="<?php echo nutritionist_e(nutritionist_children_url(['local_area_id' => (int)$la['id']])); ?>"
                    <?php echo $localAreaFilter === (int)$la['id'] ? 'selected' : ''; ?>
                ><?php
                    $label = ucfirst((string)$la['area_type']) . ': ' . $la['area_name'];
                    if (($user['role'] ?? '') === 'admin' && !empty($la['barangay'])) {
                        $label .= ' · ' . $la['barangay'];
                    }
                    echo nutritionist_e($label);
                ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="rp-tabs">
        <a class="rp-tab <?php echo $tab === 'active' ? 'is-active' : ''; ?>" href="<?php echo nutritionist_e(nutritionist_children_url(['tab' => 'active'])); ?>">Active (0–59 mo) <span>(<?php echo (int)$countActive; ?>)</span></a>
        <a class="rp-tab <?php echo $tab === 'graduated' ? 'is-active' : ''; ?>" href="<?php echo nutritionist_e(nutritionist_children_url(['tab' => 'graduated'])); ?>">Graduated (60+ mo) <span>(<?php echo (int)$countGraduated; ?>)</span></a>
        <a class="rp-tab <?php echo $tab === 'archived' ? 'is-active' : ''; ?>" href="<?php echo nutritionist_e(nutritionist_children_url(['tab' => 'archived'])); ?>">Archived <span>(<?php echo (int)$countArchived; ?>)</span></a>
    </div>

    <div class="nutritionist-table-wrap">
        <table class="nutritionist-table children-table" id="children-table" data-page-size="5">
            <thead>
                <tr>
                    <th>Full name of child</th>
                    <th>Date</th>
                    <th>Weight (kg)</th>
                    <th>Height (cm)</th>
                    <th>Nutritional status (WFA · HFA · WFH)</th>
                    <th>Age (months)</th>
                    <th>Age (days)</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($pageChildren === []): ?>
                    <tr><td colspan="8">
                        <div class="children-empty">
                            <?php if ($totalAll === 0 && $tab === 'active'): ?>
                                <div class="empty-title">No children registered yet</div>
                                <div class="empty-sub">Your scope doesn't have any registered children. Once children are added, they will appear in this list.</div>
                                <?php if (nutritionist_can_write('children.create')): ?>
                                    <a class="admin-btn" href="<?php echo nutritionist_e(app_url('/nutritionist/family_form.php')); ?>"><?php echo admin_action_icon('add'); ?> Add the first child</a>
                                <?php endif; ?>
                            <?php elseif ($tab === 'graduated'): ?>
                                <div class="empty-title">No graduated children</div>
                                <div class="empty-sub">No children in your scope have reached 60+ months of age yet.</div>
                            <?php elseif ($tab === 'archived'): ?>
                                <div class="empty-title">No archived children</div>
                                <div class="empty-sub">Manually archived records and 60+ month auto-archived children in your scope will appear here.</div>
                            <?php else: ?>
                                <div class="empty-title">No children in this view</div>
                                <div class="empty-sub">Your current local area filter doesn't include any of the <?php echo (int)$totalAll; ?> children in your scope. Clear the filter to see all of them.</div>
                                <a class="admin-btn-secondary" href="<?php echo nutritionist_e(nutritionist_children_url([])); ?>">Clear filter</a>
                            <?php endif; ?>
                        </div>
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($pageChildren as $childIndex => $child): ?>
                    <?php
                    $age = doh_age((string)$child['birthdate']) ?? ['days' => 0, 'months' => 0];
                    $fullName = trim($child['first_name'] . ' ' . ($child['middle_name'] ?? '') . ' ' . $child['last_name']);
                    $profileUrl = nutritionist_e(app_url('/nutritionist/child_view.php?id=' . (int)$child['id']));
                    $editUrl = nutritionist_e(app_url('/nutritionist/child_form.php?id=' . (int)$child['id']));
                    $recordUrl = nutritionist_e(app_url('/nutritionist/measurement_record.php?child=' . (int)$child['id']));
                    $parentAddress = (string)($child['parent_address'] ?? '');
                    $lastDate = $child['last_measurement_date'] ?? null;
                    ?>
                    <tr<?php echo admin_paged_row_attr($childIndex, 5); ?>
                        class="row-link"
                        data-filter-text="<?php echo nutritionist_e(strtolower($child['child_code'] . ' ' . $fullName . ' ' . ($child['parent_name'] ?? '') . ' ' . ($child['barangay'] ?? '') . ' ' . ($child['local_area'] ?? '') . ' ' . $parentAddress)); ?>"
                        data-child-id="<?php echo (int)$child['id']; ?>"
                        data-child-name="<?php echo nutritionist_e($fullName); ?>"
                        data-child-code="<?php echo nutritionist_e((string)$child['child_code']); ?>"
                        data-child-sex="<?php echo nutritionist_e((string)$child['sex']); ?>"
                        data-child-birthdate="<?php echo nutritionist_e((string)$child['birthdate']); ?>"
                        data-child-age="<?php echo (int)$age['months']; ?>"
                        data-child-localarea="<?php echo nutritionist_e((string)($child['local_area'] ?? '')); ?>"
                        data-child-areatype="<?php echo nutritionist_e((string)($child['area_type'] ?? '')); ?>"
                        data-child-address="<?php echo nutritionist_e($parentAddress); ?>"
                        data-child-barangay="<?php echo nutritionist_e((string)($child['barangay'] ?? '')); ?>"
                        data-child-ip="<?php echo !empty($child['is_ip']) ? '1' : '0'; ?>"
                        data-child-disability="<?php echo !empty($child['has_disability']) ? '1' : '0'; ?>"
                        data-meas-date="<?php echo ($lastDate !== null && $lastDate !== '') ? nutritionist_e((string)$lastDate) : ''; ?>"
                        data-meas-weight="<?php echo $child['last_weight'] !== null ? nutritionist_e((string)(float)$child['last_weight']) : ''; ?>"
                        data-meas-height="<?php echo $child['last_height'] !== null ? nutritionist_e((string)(float)$child['last_height']) : ''; ?>"
                        data-meas-wfa="<?php echo nutritionist_e((string)($child['last_wfa'] ?? '')); ?>"
                        data-meas-hfa="<?php echo nutritionist_e((string)($child['last_hfa'] ?? '')); ?>"
                        data-meas-wfh="<?php echo nutritionist_e((string)($child['last_wfh'] ?? '')); ?>"
                        data-parent-name="<?php echo nutritionist_e((string)($child['parent_name'] ?? '')); ?>"
                        data-parent-kind="<?php echo nutritionist_e((string)($child['parent_kind'] ?? '')); ?>"
                        data-parent-phone="<?php echo nutritionist_e((string)($child['parent_phone'] ?? '')); ?>"
                        data-parent-email="<?php echo nutritionist_e((string)($child['parent_email'] ?? '')); ?>"
                        data-household-id="<?php echo (int)($child['household_id'] ?? 0); ?>"
                        data-household-code="<?php echo nutritionist_e((string)($child['household_code'] ?? '')); ?>"
                        data-household-address="<?php echo nutritionist_e((string)($child['household_address'] ?? '')); ?>"
                        data-household-lat="<?php echo $child['household_lat'] !== null ? nutritionist_e((string)$child['household_lat']) : ''; ?>"
                        data-household-lng="<?php echo $child['household_lng'] !== null ? nutritionist_e((string)$child['household_lng']) : ''; ?>"
                    >
                        <td>
                            <div class="child-name-cell">
                                <span class="avatar" style="background:<?php echo nutritionist_e(child_avatar_color((string)($child['sex'] ?? ''))); ?>;"><?php echo nutritionist_e(admin_initials($fullName)); ?></span>
                                <div class="text">
                                    <div class="name"><?php echo nutritionist_e($fullName); ?></div>
                                    <div class="sub"><?php echo nutritionist_e((string)$child['child_code']); ?> · <?php echo nutritionist_e((string)$child['sex']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td style="white-space:nowrap;">
                            <?php if ($lastDate !== null && $lastDate !== ''): ?>
                                <?php echo nutritionist_e(date('M j, Y', strtotime((string)$lastDate))); ?>
                            <?php else: ?>
                                <span style="color:var(--admin-muted);font-style:italic;">Not yet</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;font-weight:600;">
                            <?php echo $child['last_weight'] !== null ? number_format((float)$child['last_weight'], 2) : '<span style="color:var(--admin-muted);font-weight:400;">—</span>'; ?>
                        </td>
                        <td style="white-space:nowrap;font-weight:600;">
                            <?php echo $child['last_height'] !== null ? number_format((float)$child['last_height'], 1) : '<span style="color:var(--admin-muted);font-weight:400;">—</span>'; ?>
                        </td>
                        <td>
                            <?php if ($lastDate !== null && $lastDate !== ''): ?>
                                <?php
                                $wfaCode = (string)($child['last_wfa'] ?? '—');
                                $hfaCode = (string)($child['last_hfa'] ?? '—');
                                $wfhRaw = (string)($child['last_wfh'] ?? '');
                                $wfhCode = $wfhRaw !== '' ? wfh_display_short($wfhRaw) : '—';
                                $short = static fn(string $code): string => match ($code) {
                                    'Normal' => 'N',
                                    'Tall' => 'T',
                                    default => $code,
                                };
                                ?>
                                <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                    <span class="admin-pill <?php echo nutritionist_status_class($wfaCode); ?>" title="Weight-for-Age: <?php echo nutritionist_e($wfaCode); ?>"><?php echo nutritionist_e($short($wfaCode)); ?></span>
                                    <span class="admin-pill <?php echo nutritionist_status_class($hfaCode); ?>" title="Height-for-Age: <?php echo nutritionist_e($hfaCode); ?>"><?php echo nutritionist_e($short($hfaCode)); ?></span>
                                    <span class="admin-pill <?php echo nutritionist_status_class($wfhCode); ?>" title="Weight-for-Length/Height: <?php echo nutritionist_e($wfhCode); ?>"><?php echo nutritionist_e($short($wfhCode)); ?></span>
                                </div>
                            <?php else: ?>
                                <span class="admin-pill is-muted">Not yet</span>
                            <?php endif; ?>
                        </td>
                        <td style="color:var(--admin-muted);white-space:nowrap;font-weight:600;">
                            <?php echo (int)$age['months']; ?> m
                        </td>
                        <td style="color:var(--admin-muted);white-space:nowrap;font-weight:600;">
                            <?php echo (int)$age['days']; ?> d
                        </td>
                        <td>
                            <div class="admin-actions" onclick="event.stopPropagation();">
                                <button type="button" class="admin-icon-btn admin-icon-btn-primary" title="View child card" data-view-card="<?php echo (int)$child['id']; ?>"><?php echo admin_action_icon('view'); ?></button>
                                <?php if (nutritionist_can_write()): ?>
                                <a class="admin-icon-btn" title="Record measurement" href="<?php echo $recordUrl; ?>"><?php echo admin_action_icon('measure'); ?></a>
                                <?php endif; ?>
                                <?php if (nutritionist_can_write('children.update')): ?>
                                <a class="admin-icon-btn" title="Edit profile" href="<?php echo $editUrl; ?>"><?php echo admin_action_icon('edit'); ?></a>
                                <?php endif; ?>
                                <?php if (nutritionist_can_write('children.delete')): ?>
                                    <?php if ($tab === 'archived'): ?>
                                    <form method="post" action="<?php echo nutritionist_e(app_url('/nutritionist/children.php')); ?>" data-admin-confirm="Restore <?php echo nutritionist_e($fullName); ?>?" style="display:inline;" onclick="event.stopPropagation();">
                                        <input type="hidden" name="action" value="restore">
                                        <input type="hidden" name="id" value="<?php echo (int)$child['id']; ?>">
                                        <button class="admin-icon-btn" title="Restore" type="submit" style="color:var(--admin-primary,#0b6e4f);"><?php echo admin_action_icon('sync'); ?></button>
                                    </form>
                                    <?php else: ?>
                                    <form method="post" action="<?php echo nutritionist_e(app_url('/nutritionist/children.php')); ?>" data-admin-confirm="Archive <?php echo nutritionist_e($fullName); ?>?" data-admin-confirm-danger style="display:inline;" onclick="event.stopPropagation();">
                                        <input type="hidden" name="action" value="archive">
                                        <input type="hidden" name="id" value="<?php echo (int)$child['id']; ?>">
                                        <button class="admin-icon-btn admin-icon-btn-danger" title="Archive" type="submit"><?php echo admin_action_icon('archive'); ?></button>
                                    </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php /* Pagination + global search handled client-side by assets/js/admin.js (5/page). */ ?>
</section>

<!--
    Child information card modal. Opened from the row's "view" button or
    clicking anywhere on the row. Latest measurement strip on top, then
    grouped Child / Parent-guardian / Address columns (no growth history).
-->
<div class="cc-overlay" id="cc-overlay" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="cc-modal">
        <div class="cc-head">
            <div class="avatar" id="cc-avatar">--</div>
            <div class="meta">
                <div class="name" id="cc-name">Child name</div>
                <div class="sub" id="cc-sub">—</div>
            </div>
            <button type="button" class="close" id="cc-close" aria-label="Close">×</button>
        </div>
        <div class="cc-body">
            <div class="cc-measure">
                <div class="m-title">Current measurement (latest)</div>
                <div class="m-grid" id="cc-measure-grid">
                    <div class="m-stat"><span class="k">Weight</span><span class="v" id="cc-m-weight">—</span></div>
                    <div class="m-stat"><span class="k">Height</span><span class="v" id="cc-m-height">—</span></div>
                    <div class="m-stat"><span class="k">Date</span><span class="v" id="cc-m-date" style="font-size:15px;">—</span></div>
                    <div class="m-pills" id="cc-m-pills"></div>
                </div>
                <div class="m-empty" id="cc-m-empty" style="display:none;">Not yet weighed — no measurement on record.</div>
            </div>
            <div class="cc-cols">
                <div class="cc-card">
                    <div class="cc-section">Child information</div>
                    <div class="cc-row"><span class="label">Child code</span><span class="value" id="cc-code">—</span></div>
                    <div class="cc-row"><span class="label">Sex</span><span class="value" id="cc-sex">—</span></div>
                    <div class="cc-row"><span class="label">Birthdate</span><span class="value" id="cc-birthdate">—</span></div>
                    <div class="cc-row"><span class="label">Age</span><span class="value" id="cc-age">—</span></div>
                    <div class="cc-row"><span class="label">IP group</span><span class="value" id="cc-ip">—</span></div>
                    <div class="cc-row"><span class="label">With disability</span><span class="value" id="cc-disability">—</span></div>
                </div>
                <div class="cc-card">
                    <div class="cc-section">Parent / guardian</div>
                    <div class="cc-row"><span class="label">Name</span><span class="value" id="cc-parent-name">—</span></div>
                    <div class="cc-row"><span class="label">Relationship</span><span class="value" id="cc-parent-kind">—</span></div>
                    <div class="cc-row"><span class="label">Phone</span><span class="value" id="cc-parent-phone">—</span></div>
                    <div class="cc-row"><span class="label">Email</span><span class="value" id="cc-parent-email">—</span></div>
                </div>
                <div class="cc-card">
                    <div class="cc-section">Address</div>
                    <div class="cc-row"><span class="label">Local area</span><span class="value" id="cc-localarea">—</span></div>
                    <div class="cc-row"><span class="label">Barangay</span><span class="value" id="cc-barangay">—</span></div>
                    <div class="cc-row"><span class="label">Household code</span><span class="value" id="cc-household-code">—</span></div>
                    <div class="cc-row"><span class="label">Household address</span><span class="value" id="cc-household-address">—</span></div>
                </div>
            </div>
        </div>
        <div class="cc-foot">
            <a class="admin-btn-secondary" id="cc-edit" href="#">Edit profile</a>
            <button type="button" class="admin-btn" id="cc-close-2">Close</button>
        </div>
    </div>
</div>

<script>
(function () {
    var overlay = document.getElementById('cc-overlay');
    if (!overlay) return;

    function val(id) { return document.getElementById(id); }
    function text(id, v) { var el = val(id); if (el) el.textContent = (v === null || v === undefined || v === '') ? '—' : v; }
    function ccEsc(s) {
        return String(s).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    // Mirrors PHP nutritionist_status_class() for the short status codes.
    function ccStatusClass(s) {
        var x = String(s || '').toLowerCase().trim();
        if (x === 'normal' || x === 'n' || x === 'tall' || x === 't') return 'is-success';
        if (x === 'muw' || x === 'mst' || x === 'mw' || x === 'mw/mam' || x === 'mw(mam)' || x === 'mam' || x.indexOf('moderately') === 0) return 'is-warn';
        if (x === 'ow' || x === 'ob' || x === 'overweight' || x === 'obese') return 'is-orange';
        if (x === 'suw' || x === 'sst' || x === 'sw' || x === 'sw/sam' || x === 'sw(sam)' || x === 'sam' || x.indexOf('severely') === 0) return 'is-danger';
        if (x.indexOf('refer') !== -1) return 'is-info';
        return 'is-muted';
    }

    function ccWfhShort(code) {
        var x = String(code || '').toLowerCase().trim();
        if (x === 'sw' || x === 'sw/sam' || x === 'sw(sam)' || x === 'sam') return 'SW/SAM';
        if (x === 'mw' || x === 'mw/mam' || x === 'mw(mam)' || x === 'mam') return 'MW/MAM';
        return String(code || '');
    }

    function ccFormatDate(iso) {
        var p = String(iso || '').split('-');
        if (p.length < 3) return String(iso || '');
        var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        var mi = parseInt(p[1], 10) - 1;
        if (mi < 0 || mi > 11) return String(iso || '');
        return months[mi] + ' ' + parseInt(p[2], 10) + ', ' + p[0];
    }

    function ccPill(axisLabel, fullCode, displayCode) {
        return '<span class="admin-pill ' + ccStatusClass(fullCode) + '" title="' + ccEsc(axisLabel) + ': ' + ccEsc(fullCode) + '">'
            + ccEsc(axisLabel) + ': ' + ccEsc(displayCode) + '</span>';
    }

    function renderMeasureStrip(row) {
        var getM = function (k) { return row.getAttribute('data-meas-' + k) || ''; };
        var mDate = getM('date');
        var hasMeas = mDate !== '';
        var grid = val('cc-measure-grid');
        var empty = val('cc-m-empty');
        if (grid) grid.style.display = hasMeas ? '' : 'none';
        if (empty) empty.style.display = hasMeas ? 'none' : '';
        if (!hasMeas) {
            var pills = val('cc-m-pills');
            if (pills) pills.innerHTML = '';
            return;
        }
        var mW = getM('weight');
        var mH = getM('height');
        text('cc-m-weight', mW !== '' ? (parseFloat(mW).toFixed(2) + ' kg') : '—');
        text('cc-m-height', mH !== '' ? (parseFloat(mH).toFixed(1) + ' cm') : '—');
        text('cc-m-date', ccFormatDate(mDate));
        var wfa = getM('wfa');
        var hfa = getM('hfa');
        var wfhRaw = getM('wfh');
        var wfh = wfhRaw !== '' ? ccWfhShort(wfhRaw) : '';
        var html = [];
        if (wfa) html.push(ccPill('WFA', wfa, wfa));
        if (hfa) html.push(ccPill('HFA', hfa, hfa));
        if (wfh) html.push(ccPill('WFH', wfhRaw, wfh));
        var pillsEl = val('cc-m-pills');
        if (pillsEl) pillsEl.innerHTML = html.join('');
    }

    function openCard(row) {
        if (!row) return;
        var get = function (k) { return row.getAttribute('data-child-' + k) || ''; };
        var getP = function (k) { return row.getAttribute('data-parent-' + k) || ''; };
        var getH = function (k) { return row.getAttribute('data-household-' + k) || ''; };

        var name = get('name');
        var code = get('code');
        var initials = (name.split(' ').filter(Boolean).slice(0, 2).map(function (s) { return s.charAt(0).toUpperCase(); }).join('')) || '--';

        val('cc-avatar').textContent = initials;
        val('cc-avatar').style.background = (function () {
            // Match the in-row avatar color the page assigns.
            var rowAvatar = row.querySelector('.child-name-cell .avatar');
            return rowAvatar ? rowAvatar.style.background : '#94a3b8';
        })();

        text('cc-name', name);
        text('cc-sub', code + ' · ' + (get('sex') || '—'));

        text('cc-code', code);
        text('cc-sex', get('sex'));
        text('cc-birthdate', get('birthdate'));
        text('cc-age', get('age') + ' m');
        text('cc-ip', get('ip') === '1' ? 'Yes' : 'No');
        text('cc-disability', get('disability') === '1' ? 'Yes' : 'No');

        var localArea = get('localarea');
        var areaType = get('areatype');
        text('cc-localarea', (areaType && localArea) ? (areaType.charAt(0).toUpperCase() + areaType.slice(1) + ': ' + localArea) : (localArea || '—'));
        text('cc-barangay', get('barangay'));

        text('cc-parent-name', getP('name'));
        text('cc-parent-kind', getP('kind'));
        text('cc-parent-phone', getP('phone'));
        text('cc-parent-email', getP('email'));

        var hhCode = getH('code');
        var hhAddress = getH('address');
        text('cc-household-code', hhCode || '—');
        text('cc-household-address', hhAddress || '—');

        renderMeasureStrip(row);

        var editLink = val('cc-edit');
        if (editLink) {
            editLink.setAttribute('href', '<?php echo nutritionist_e(app_url('/nutritionist/child_form.php')); ?>?id=' + (row.getAttribute('data-child-id') || ''));
        }

        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeCard() {
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    document.querySelectorAll('[data-view-card]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var row = btn.closest('tr.row-link');
            openCard(row);
        });
    });

    document.querySelectorAll('#children-table tr.row-link').forEach(function (row) {
        row.addEventListener('click', function () { openCard(row); });
    });

    val('cc-close').addEventListener('click', closeCard);
    val('cc-close-2').addEventListener('click', closeCard);
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeCard();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeCard();
    });
})();
</script>

<?php
nutritionist_layout_end();

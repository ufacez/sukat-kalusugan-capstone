<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/who_calculator.php';

$user = nutritionist_require_access();

/*
 * Filter / view params
 */
$localAreaFilter = (int)($_GET['local_area_id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;

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
        h.lng AS household_lng
     FROM children c
     INNER JOIN parents p ON p.id = c.parent_id
     LEFT JOIN barangays bg ON bg.id = c.barangay_id
     LEFT JOIN local_areas la ON la.id = c.local_area_id
     LEFT JOIN households h ON h.id = c.household_id AND h.status = 'active'
     WHERE {$whereSql}
     ORDER BY c.last_name ASC, c.first_name ASC",
    $types,
    $filterParams
);

$totalAll = count($children);
$totalPages = max(1, (int)ceil($totalAll / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$pageChildren = array_slice($children, $offset, $perPage);

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
    $base = app_url('/nutritionist/children.php');
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
    . '<a class="admin-btn-secondary" href="' . nutritionist_e(app_url('/nutritionist/measurements.php')) . '">' . admin_action_icon('clipboard') . ' Measurements</a>'
    . (nutritionist_can_write('children.create')
        ? '<a class="admin-btn" href="' . nutritionist_e(app_url('/nutritionist/child_form.php')) . '">' . admin_action_icon('add') . ' Add child</a>'
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

/* Child detail modal (just child information, no measurements) */
.cc-overlay{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:center;justify-content:center;z-index:1000;padding:20px}
.cc-overlay.is-open{display:flex}
.cc-modal{background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:14px;width:100%;max-width:640px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.18);display:flex;flex-direction:column}
.cc-head{display:flex;align-items:center;gap:14px;padding:18px 20px;border-bottom:1px solid var(--admin-border);position:sticky;top:0;background:var(--admin-surface);z-index:1}
.cc-head .avatar{width:56px;height:56px;border-radius:50%;background:#94a3b8;color:#fff;font-weight:700;font-size:18px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.cc-head .meta{min-width:0;flex:1}
.cc-head .name{font-size:16px;font-weight:700;color:var(--admin-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cc-head .sub{font-size:11px;color:var(--admin-muted);margin-top:2px}
.cc-head .close{background:none;border:none;color:var(--admin-muted);font-size:20px;line-height:1;cursor:pointer;padding:6px 10px;border-radius:6px}
.cc-head .close:hover{background:var(--admin-surface-alt);color:var(--admin-text)}

.cc-body{padding:18px 20px}
.cc-section{font-weight:700;font-size:11px;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.06em;margin:14px 0 8px}
.cc-section:first-child{margin-top:0}
.cc-row{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:8px 0;border-bottom:1px solid var(--admin-border);font-size:12px}
.cc-row:last-child{border-bottom:none}
.cc-row .label{color:var(--admin-muted);font-weight:500;flex-shrink:0;width:120px}
.cc-row .value{font-weight:600;color:var(--admin-text);text-align:right;flex:1;min-width:0;word-break:break-word}
.cc-foot{display:flex;justify-content:flex-end;gap:8px;padding:14px 20px;border-top:1px solid var(--admin-border);background:var(--admin-surface-alt);border-bottom-left-radius:14px;border-bottom-right-radius:14px}

@media (max-width: 560px) {
  .children-toolbar{flex-direction:column;align-items:stretch}
  .children-toolbar .admin-search{min-width:0;flex:1}
  .children-toolbar .admin-select{min-width:0;max-width:100%;width:100%}
  .cc-modal{max-width:calc(100vw - 16px);max-height:85vh}
  .cc-row{flex-direction:column;gap:4px}
  .cc-row .label{width:auto;flex-shrink:0}
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
            <option value="<?php echo nutritionist_e(nutritionist_children_url(['page' => 1])); ?>">All local areas</option>
            <?php foreach ($localAreaList as $la): ?>
                <option
                    value="<?php echo nutritionist_e(nutritionist_children_url(['local_area_id' => (int)$la['id'], 'page' => 1])); ?>"
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

    <div class="nutritionist-table-wrap">
        <table class="nutritionist-table children-table" id="children-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Address (Local area · Barangay)</th>
                    <th>Name of Guardian</th>
                    <th>Full name of child</th>
                    <th>Sex</th>
                    <th>Age (months)</th>
                    <th>Age (days)</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($pageChildren === []): ?>
                    <tr><td colspan="8">
                        <div class="children-empty">
                            <?php if ($totalAll === 0): ?>
                                <div class="empty-title">No children registered yet</div>
                                <div class="empty-sub">Your scope doesn't have any registered children. Once children are added, they will appear in this list.</div>
                                <?php if (nutritionist_can_write('children.create')): ?>
                                    <a class="admin-btn" href="<?php echo nutritionist_e(app_url('/nutritionist/child_form.php')); ?>"><?php echo admin_action_icon('add'); ?> Add the first child</a>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="empty-title">No children in this view</div>
                                <div class="empty-sub">Your current local area filter doesn't include any of the <?php echo (int)$totalAll; ?> children in your scope. Clear the filter to see all of them.</div>
                                <a class="admin-btn-secondary" href="<?php echo nutritionist_e(nutritionist_children_url([])); ?>">Clear filter</a>
                            <?php endif; ?>
                        </div>
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($pageChildren as $child): ?>
                    <?php
                    $age = doh_age((string)$child['birthdate']) ?? ['days' => 0, 'months' => 0];
                    $fullName = trim($child['first_name'] . ' ' . ($child['middle_name'] ?? '') . ' ' . $child['last_name']);
                    $profileUrl = nutritionist_e(app_url('/nutritionist/child_view.php?id=' . (int)$child['id']));
                    $editUrl = nutritionist_e(app_url('/nutritionist/child_form.php?id=' . (int)$child['id']));
                    $parentAddress = (string)($child['parent_address'] ?? '');
                    ?>
                    <tr
                        class="row-link"
                        data-filter-text="<?php echo nutritionist_e(strtolower($child['child_code'] . ' ' . $fullName . ' ' . ($child['parent_name'] ?? '') . ' ' . ($child['barangay'] ?? '') . ' ' . ($child['local_area'] ?? '') . ' ' . $parentAddress)); ?>"
                        data-child-id="<?php echo (int)$child['id']; ?>"
                        data-child-name="<?php echo nutritionist_e($fullName); ?>"
                        data-child-code="<?php echo nutritionist_e((string)$child['child_code']); ?>"
                        data-child-sex="<?php echo nutritionist_e((string)$child['sex']); ?>"
                        data-child-birthdate="<?php echo nutritionist_e((string)$child['birthdate']); ?>"
                        data-child-age="<?php echo (int)$age['days']; ?>"
                        data-child-localarea="<?php echo nutritionist_e((string)($child['local_area'] ?? '')); ?>"
                        data-child-areatype="<?php echo nutritionist_e((string)($child['area_type'] ?? '')); ?>"
                        data-child-address="<?php echo nutritionist_e($parentAddress); ?>"
                        data-child-barangay="<?php echo nutritionist_e((string)($child['barangay'] ?? '')); ?>"
                        data-child-ip="<?php echo !empty($child['is_ip']) ? '1' : '0'; ?>"
                        data-child-disability="<?php echo !empty($child['has_disability']) ? '1' : '0'; ?>"
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
                        <td style="font-family:monospace;color:var(--admin-muted);white-space:nowrap;"><?php echo nutritionist_e($child['child_code']); ?></td>
                        <td class="address-cell">
                            <div class="primary"><?php echo nutritionist_e(nchild_short_address($child['local_area'] ?? null, $child['barangay'] ?? null)); ?></div>
                            <?php if ($parentAddress !== ''): ?>
                                <div class="sub" title="<?php echo nutritionist_e($parentAddress); ?>"><?php echo nutritionist_e(mb_strimwidth($parentAddress, 0, 48, '…')); ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="min-width:0;">
                            <div style="font-weight:600;color:var(--admin-text);font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px;"><?php echo nutritionist_e((string)($child['parent_name'] ?? '—')); ?></div>
                            <?php if (!empty($child['parent_kind'])): ?>
                                <div class="admin-mini"><?php echo nutritionist_e((string)$child['parent_kind']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="child-name-cell">
                                <span class="avatar" style="background:<?php echo nutritionist_e(admin_avatar_color($fullName)); ?>;"><?php echo nutritionist_e(admin_initials($fullName)); ?></span>
                                <div class="text">
                                    <div class="name"><?php echo nutritionist_e($fullName); ?></div>
                                </div>
                            </div>
                        </td>
                        <td style="color:var(--admin-muted);"><?php echo nutritionist_e((string)$child['sex']); ?></td>
                        <td style="color:var(--admin-muted);white-space:nowrap;font-weight:600;">
                            <?php echo (int)$age['months']; ?> mo
                        </td>
                        <td style="color:var(--admin-muted);white-space:nowrap;font-weight:600;">
                            <?php echo (int)$age['days']; ?> d
                        </td>
                        <td>
                            <div class="admin-actions" onclick="event.stopPropagation();">
                                <button type="button" class="admin-icon-btn admin-icon-btn-primary" title="View child card" data-view-card="<?php echo (int)$child['id']; ?>"><?php echo admin_action_icon('view'); ?></button>
                                <?php if (nutritionist_can_write('children.update')): ?>
                                <a class="admin-icon-btn" title="Edit profile" href="<?php echo $editUrl; ?>"><?php echo admin_action_icon('edit'); ?></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <?php
        $prevPage = max(1, $page - 1);
        $nextPage = min($totalPages, $page + 1);
        $firstItem = $offset + 1;
        $lastItem = min($totalAll, $offset + $perPage);
        ?>
        <div class="children-pagination">
            <span class="status">Showing <?php echo (int)$firstItem; ?>–<?php echo (int)$lastItem; ?> of <?php echo (int)$totalAll; ?> children</span>
            <div class="pages">
                <a class="page-btn<?php echo $page <= 1 ? ' is-disabled' : ''; ?>" href="<?php echo nutritionist_e(nutritionist_children_url(['local_area_id' => $localAreaFilter > 0 ? $localAreaFilter : null, 'page' => $prevPage])); ?>">‹ Prev</a>
                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                if ($start > 1) {
                    echo '<a class="page-btn" href="' . nutritionist_e(nutritionist_children_url(['local_area_id' => $localAreaFilter > 0 ? $localAreaFilter : null, 'page' => 1])) . '">1</a>';
                    if ($start > 2) {
                        echo '<span class="status" style="padding:0 4px;">…</span>';
                    }
                }
                for ($i = $start; $i <= $end; $i++) {
                    echo '<a class="page-btn' . ($i === $page ? ' is-active' : '') . '" href="' . nutritionist_e(nutritionist_children_url(['local_area_id' => $localAreaFilter > 0 ? $localAreaFilter : null, 'page' => $i])) . '">' . $i . '</a>';
                }
                if ($end < $totalPages) {
                    if ($end < $totalPages - 1) {
                        echo '<span class="status" style="padding:0 4px;">…</span>';
                    }
                    echo '<a class="page-btn" href="' . nutritionist_e(nutritionist_children_url(['local_area_id' => $localAreaFilter > 0 ? $localAreaFilter : null, 'page' => $totalPages])) . '">' . $totalPages . '</a>';
                }
                ?>
                <a class="page-btn<?php echo $page >= $totalPages ? ' is-disabled' : ''; ?>" href="<?php echo nutritionist_e(nutritionist_children_url(['local_area_id' => $localAreaFilter > 0 ? $localAreaFilter : null, 'page' => $nextPage])); ?>">Next ›</a>
            </div>
        </div>
    <?php endif; ?>
</section>

<!--
    Child information card modal. Opened from the row's "view" button or
    clicking anywhere on the row. Shows ONLY child information (no
    measurements, no growth history) per the spec.
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
            <div class="cc-section">Child information</div>
            <div class="cc-row"><span class="label">Child code</span><span class="value" id="cc-code">—</span></div>
            <div class="cc-row"><span class="label">Sex</span><span class="value" id="cc-sex">—</span></div>
            <div class="cc-row"><span class="label">Birthdate</span><span class="value" id="cc-birthdate">—</span></div>
            <div class="cc-row"><span class="label">Age</span><span class="value" id="cc-age">—</span></div>
            <div class="cc-row"><span class="label">IP group</span><span class="value" id="cc-ip">—</span></div>
            <div class="cc-row"><span class="label">With disability</span><span class="value" id="cc-disability">—</span></div>

            <div class="cc-section">Address</div>
            <div class="cc-row"><span class="label">Local area</span><span class="value" id="cc-localarea">—</span></div>
            <div class="cc-row"><span class="label">Street address</span><span class="value" id="cc-address">—</span></div>
            <div class="cc-row"><span class="label">Barangay</span><span class="value" id="cc-barangay">—</span></div>

            <div class="cc-section">Parent / guardian</div>
            <div class="cc-row"><span class="label">Name</span><span class="value" id="cc-parent-name">—</span></div>
            <div class="cc-row"><span class="label">Type</span><span class="value" id="cc-parent-kind">—</span></div>
            <div class="cc-row"><span class="label">Phone</span><span class="value" id="cc-parent-phone">—</span></div>
            <div class="cc-row"><span class="label">Email</span><span class="value" id="cc-parent-email">—</span></div>

            <div class="cc-section">Household / Spot</div>
            <div class="cc-row"><span class="label">Household code</span><span class="value" id="cc-household-code">—</span></div>
            <div class="cc-row"><span class="label">Address</span><span class="value" id="cc-household-address">—</span></div>
            <div class="cc-row"><span class="label">Coordinates</span><span class="value" id="cc-household-coords" style="font-family:monospace;font-size:11px;">—</span></div>
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
        text('cc-age', get('age') + ' months');
        text('cc-ip', get('ip') === '1' ? 'Yes' : 'No');
        text('cc-disability', get('disability') === '1' ? 'Yes' : 'No');

        var localArea = get('localarea');
        var areaType = get('areatype');
        text('cc-localarea', (areaType && localArea) ? (areaType.charAt(0).toUpperCase() + areaType.slice(1) + ': ' + localArea) : (localArea || '—'));
        text('cc-address', get('address'));
        text('cc-barangay', get('barangay'));

        text('cc-parent-name', getP('name'));
        text('cc-parent-kind', getP('kind'));
        text('cc-parent-phone', getP('phone'));
        text('cc-parent-email', getP('email'));

        var hhCode = getH('code');
        var hhAddress = getH('address');
        var hhLat = getH('lat');
        var hhLng = getH('lng');
        text('cc-household-code', hhCode ? ('HH-' + String(getH('id') || '0').padStart(4, '0') + ' · ' + hhCode) : '—');
        text('cc-household-address', hhAddress || '—');
        if (hhLat && hhLng) {
            text('cc-household-coords', parseFloat(hhLat).toFixed(7) + ', ' + parseFloat(hhLng).toFixed(7));
        } else {
            text('cc-household-coords', '—');
        }

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

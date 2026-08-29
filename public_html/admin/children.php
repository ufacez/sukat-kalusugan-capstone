<?php

require_once __DIR__ . '/../includes/admin_helpers.php';
require_once __DIR__ . '/../includes/who_calculator.php';

start_secure_session();
require_permission('children.view');

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
     ORDER BY c.last_name ASC, c.first_name ASC",
    $types,
    $params
);

$archivedCountRow = admin_fetch_one("SELECT COUNT(*) AS cnt FROM children WHERE status = 'inactive'");
$archivedCount = (int)($archivedCountRow['cnt'] ?? 0);

$totalAtRisk = count(array_filter($children, static fn(array $c): bool => in_array(strtolower((string)($c['nutritional_status'] ?? '')), ['severely underweight', 'severely stunted', 'severely wasted', 'moderately underweight', 'moderately stunted', 'moderately wasted'], true)));

$barangays = admin_fetch_all("SELECT id, name FROM barangays WHERE status = 'active' ORDER BY name ASC");

$actions = '';
if (has_permission('children.create')) {
    $actions .= '<a class="admin-btn" href="' . admin_e(app_url('/admin/child_form.php')) . '">' . admin_action_icon('add') . ' Add child</a>';
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
            <div class="admin-card-icon is-danger">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">At Risk</div>
                <div class="admin-card-value"><?php echo $totalAtRisk; ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend">Moderate to severe malnutrition</span>
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
        <div class="admin-toolbar" style="margin:0;">
            <select class="admin-search" id="barangay-filter" onchange="window.location.href='<?php echo admin_e(app_url('/admin/children.php')); ?>?barangay_id='+this.value" style="max-width:200px;cursor:pointer;">
                <option value="0">All Barangays</option>
                <?php foreach ($barangays as $b): ?>
                    <option value="<?php echo (int)$b['id']; ?>" <?php echo $filterBarangay === (int)$b['id'] ? 'selected' : ''; ?>><?php echo admin_e($b['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <input class="admin-search" type="search" placeholder="Search children" data-admin-filter="#children-table">
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
                <?php foreach ($children as $child): ?>
                    <tr data-filter-text="<?php echo admin_e(strtolower($child['child_code'] . ' ' . $child['first_name'] . ' ' . ($child['middle_name'] ?? '') . ' ' . $child['last_name'] . ' ' . $child['parent_name'] . ' ' . (string)($child['barangay'] ?? ''))); ?>">
                        <td style="font-family:monospace;color:var(--admin-muted);"><?php echo admin_e($child['child_code']); ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <span class="admin-avatar" style="background:<?php echo admin_avatar_color($child['first_name'] . ' ' . $child['last_name']); ?>;width:32px;height:32px;font-size:0.7rem;"><?php echo admin_initials($child['first_name'] . ' ' . $child['last_name']); ?></span>
                                <div>
                                    <div style="font-weight:700;"><?php echo admin_e(trim($child['first_name'] . ' ' . ($child['middle_name'] ?? '') . ' ' . $child['last_name'])); ?></div>
                                    <div class="admin-mini"><?php echo admin_e((string)$child['sex']); ?> &middot; <?php echo (int)(doh_age_in_months((string)$child['birthdate']) ?? 0); ?> mo</div>
                                </div>
                            </div>
                        </td>
                        <td style="color:var(--admin-muted);"><?php echo admin_e((string)$child['parent_name']); ?></td>
                        <td style="color:var(--admin-muted);"><?php echo admin_e((string)($child['barangay'] ?? '')); ?></td>
                        <td>
                            <?php if (!empty($child['measurement_date'])): ?>
                                <?php $md = (string)$child['measurement_date']; ?>
                                <div style="font-weight:600;font-size:0.82rem;"><?php echo admin_e(date('M j Y', strtotime($md))); ?></div>
                                <div class="admin-mini"><?php echo admin_e(date('H:i', strtotime($md))); ?></div>
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
                                    <form method="post" action="<?php echo admin_e(app_url('/api/admin/children_archive.php')); ?>" onsubmit="return confirm('Archive <?php echo admin_e($child['first_name']); ?>?');" style="display:inline;">
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

<?php
admin_layout_end();

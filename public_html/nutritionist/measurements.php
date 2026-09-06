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
 * Per-child latest measurement snapshot.
 * This is the canonical "measurement card" source: one row per child,
 * joining the latest measurement by id-subquery so we always get the
 * correct row even if measurement_date is the same day.
 *
 * NOTE: the live `children` table does not have `address` / `purok`
 * columns (only in the schema.sql file). Address info is pulled from
 * the parent's `address` field instead.
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
        bg.name AS barangay,
        la.area_name AS local_area,
        la.area_type,
        c.parent_id,
        p.name AS parent_name,
        p.parent_type AS parent_kind,
        p.phone AS parent_phone,
        p.email AS parent_email,
        p.address AS parent_address,
        lm.id AS measurement_id,
        lm.measurement_date,
        lm.height_cm,
        lm.weight_kg,
        lm.waz,
        lm.haz,
        lm.whz,
        COALESCE(lm.nutritional_status, CASE
            WHEN lm.waz < -3 THEN 'Severely Underweight'
            WHEN lm.haz < -3 THEN 'Severely Stunted'
            WHEN lm.whz < -3 THEN 'Severely Wasted'
            WHEN lm.waz < -2 THEN 'Moderately Underweight'
            WHEN lm.haz < -2 THEN 'Moderately Stunted'
            WHEN lm.whz < -2 THEN 'Moderately Wasted'
            WHEN lm.whz > 3 THEN 'Obese'
            WHEN lm.whz > 2 THEN 'Overweight'
            ELSE 'Normal'
        END) AS nutritional_status,
        CASE WHEN lm.waz < -3 THEN 'SUW' WHEN lm.waz < -2 THEN 'MUW' WHEN lm.waz > 2 THEN 'Refer to WFL/H' ELSE 'Normal' END AS wfa_status,
        CASE WHEN lm.haz < -3 THEN 'SSt' WHEN lm.haz < -2 THEN 'MSt' WHEN lm.haz > 2 THEN 'Tall' ELSE 'Normal' END AS hfa_status,
        CASE WHEN lm.whz < -3 THEN 'SW' WHEN lm.whz < -2 THEN 'MW' WHEN lm.whz > 3 THEN 'Ob' WHEN lm.whz > 2 THEN 'OW' ELSE 'Normal' END AS wfh_status
     FROM children c
     INNER JOIN parents p ON p.id = c.parent_id
     LEFT JOIN barangays bg ON bg.id = c.barangay_id
     LEFT JOIN local_areas la ON la.id = c.local_area_id
     LEFT JOIN measurements lm ON lm.id = (
         SELECT m.id FROM measurements m
         WHERE m.child_id = c.id
         ORDER BY m.measurement_date DESC, m.id DESC
         LIMIT 1
     )
     WHERE {$whereSql}
     ORDER BY COALESCE(lm.measurement_date, '0000-00-00') DESC, c.last_name ASC, c.first_name ASC",
    $types,
    $filterParams
);

/*
 * Build history lookup so the card modal can pull the full chronology
 * for the clicked child without an extra round-trip. Small dataset —
 * fetch all rows for the in-scope children keyed by child id.
 */
$historyParams = [];
$historyScope = nutritionist_scope_fragment($user, 'c.barangay_id', $historyParams);
$historyRows = admin_fetch_all(
    "SELECT
        m.id,
        m.child_id,
        m.measurement_date,
        m.height_cm,
        m.weight_kg,
        m.waz,
        m.haz,
        m.whz,
        COALESCE(m.nutritional_status, CASE
            WHEN m.waz < -3 THEN 'Severely Underweight'
            WHEN m.haz < -3 THEN 'Severely Stunted'
            WHEN m.whz < -3 THEN 'Severely Wasted'
            WHEN m.waz < -2 THEN 'Moderately Underweight'
            WHEN m.haz < -2 THEN 'Moderately Stunted'
            WHEN m.whz < -2 THEN 'Moderately Wasted'
            WHEN m.whz > 3 THEN 'Obese'
            WHEN m.whz > 2 THEN 'Overweight'
            ELSE 'Normal'
        END) AS nutritional_status,
        CASE WHEN m.waz < -3 THEN 'SUW' WHEN m.waz < -2 THEN 'MUW' WHEN m.waz > 2 THEN 'Refer to WFL/H' ELSE 'Normal' END AS wfa_status,
        CASE WHEN m.haz < -3 THEN 'SSt' WHEN m.haz < -2 THEN 'MSt' WHEN m.haz > 2 THEN 'Tall' ELSE 'Normal' END AS hfa_status,
        CASE WHEN m.whz < -3 THEN 'SW' WHEN m.whz < -2 THEN 'MW' WHEN m.whz > 3 THEN 'Ob' WHEN m.whz > 2 THEN 'OW' ELSE 'Normal' END AS wfh_status,
        m.source_type,
        m.is_flagged
     FROM measurements m
     INNER JOIN children c ON c.id = m.child_id
     WHERE {$historyScope}
     ORDER BY m.measurement_date DESC, m.id DESC",
    str_repeat('i', count($historyParams)),
    $historyParams
);

$historyByChild = [];
foreach ($historyRows as $row) {
    $historyByChild[(int)$row['child_id']][] = $row;
}

/*
 * Pagination
 */
$totalAll = count($children);
$totalPages = max(1, (int)ceil($totalAll / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$pageChildren = array_slice($children, $offset, $perPage);

/*
 * Local area list for the filter dropdown, scoped to the user's
 * barangay (so a Dela Paz Norte nutritionist only sees Dela Paz Norte
 * local areas).
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

function nutritionist_measurements_url(array $params): string
{
    $base = app_url('/nutritionist/measurements.php');
    $merged = array_filter($params, static fn($v) => $v !== '' && $v !== null);
    return $merged === [] ? $base : $base . '?' . http_build_query($merged);
}

function nmeas_short_address(?string $localArea, ?string $barangay): string
{
    $local = trim((string)($localArea ?? ''));
    $brgy = trim((string)($barangay ?? ''));
    if ($local !== '' && $brgy !== '') return $local . ' · ' . $brgy;
    return $local !== '' ? $local : ($brgy !== '' ? $brgy : '—');
}

/*
 * Stat cards reflect the full filtered set.
 */
$atRiskCount = count(array_filter($children, static function (array $c): bool {
    $s = strtolower((string)($c['nutritional_status'] ?? 'pending'));
    return $s !== 'normal' && $s !== 'tall' && $s !== 'pending' && $s !== '';
}));
$pendingCount = count(array_filter($children, static fn(array $c): bool => empty($c['measurement_id'])));
$totalMeasurements = array_sum(array_map(static fn(array $c): int => count($historyByChild[(int)$c['id']] ?? []), $children));

$actions = '<div class="admin-actions">'
    . '<a class="admin-btn-secondary" href="' . nutritionist_e(app_url('/nutritionist/children.php')) . '">' . admin_action_icon('children') . ' Children</a>'
    . (nutritionist_can_write()
        ? '<a class="admin-btn" href="' . nutritionist_e(app_url('/nutritionist/measurement_record.php')) . '">' . admin_action_icon('add') . ' New measurement</a>'
        : '')
    . '</div>';

nutritionist_layout_start(
    'Measurements',
    'One row per child with the latest measurement. Open the card to view the WHO assessment, growth chart, and full history.',
    'measurements',
    $actions
);
?>
<style>
.measurements-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px}
@media(max-width:900px){.measurements-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
.measurements-stats .admin-card{padding:14px}
.measurements-stats .admin-card-icon{width:36px;height:36px;border-radius:9px}
.measurements-stats .admin-card-icon svg{width:18px;height:18px}
.measurements-stats .admin-card-value{font-size:20px}

.measurements-toolbar{display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;align-items:center}
.measurements-toolbar .admin-search{flex:1;min-width:220px}
.measurements-toolbar .admin-select{min-width:200px;max-width:260px}

.measurements-table .child-name-cell{display:flex;align-items:center;gap:10px;min-width:0}
.measurements-table .child-name-cell .avatar{width:32px;height:32px;border-radius:50%;background:#94a3b8;color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.measurements-table .child-name-cell .text{min-width:0}
.measurements-table .child-name-cell .text .name{font-weight:600;color:var(--admin-text);font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

.measurements-table .address-cell{max-width:220px}
.measurements-table .address-cell .primary{font-size:12px;color:var(--admin-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.measurements-table .address-cell .sub{font-size:10px;color:var(--admin-muted);margin-top:1px}

.measurements-table .row-link{cursor:pointer;transition:background-color .12s}
.measurements-table .row-link:hover td{background:var(--admin-surface-alt)}

.measurements-table .who-cell{display:flex;flex-direction:column;gap:3px;font-size:10px;line-height:1.3}
.measurements-table .who-cell .ax{color:var(--admin-muted);font-weight:600;letter-spacing:.04em;text-transform:uppercase;font-size:9px}
.measurements-table .who-cell .val{font-weight:700;color:var(--admin-text)}

.children-empty{padding:32px 18px;color:var(--admin-muted);font-size:13px;background:var(--admin-surface-alt);border-radius:10px;border:1px dashed var(--admin-border);text-align:center;display:flex;flex-direction:column;align-items:center;gap:10px}
.children-empty .empty-title{font-weight:700;color:var(--admin-text);font-size:14px}
.children-empty .empty-sub{color:var(--admin-muted);max-width:420px;line-height:1.45}

.children-pagination{display:flex;align-items:center;justify-content:space-between;padding:12px 4px 0;flex-wrap:wrap;gap:8px}
.children-pagination .status{font-size:11px;color:var(--admin-muted)}
.children-pagination .pages{display:flex;align-items:center;gap:4px;flex-wrap:wrap}
.children-pagination .page-btn{display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:30px;padding:0 8px;border:1px solid var(--admin-border);border-radius:7px;background:var(--admin-surface);color:var(--admin-text);font-size:11px;font-weight:600;text-decoration:none}
.children-pagination .page-btn.is-active{background:var(--admin-primary);border-color:var(--admin-primary);color:#fff}
.children-pagination .page-btn.is-disabled{opacity:.4;pointer-events:none}

/* ============================================================
   Measurement card modal
   ============================================================ */
.mc-overlay{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:center;justify-content:center;z-index:1000;padding:20px}
.mc-overlay.is-open{display:flex}
.mc-modal{background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:14px;width:100%;max-width:720px;max-height:90vh;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.18);display:flex;flex-direction:column}
.mc-head{display:flex;align-items:center;gap:14px;padding:18px 20px;border-bottom:1px solid var(--admin-border);background:var(--admin-surface);position:sticky;top:0;z-index:1}
.mc-head .avatar{width:56px;height:56px;border-radius:50%;background:#94a3b8;color:#fff;font-weight:700;font-size:18px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.mc-head .meta{min-width:0;flex:1}
.mc-head .name{font-size:16px;font-weight:700;color:var(--admin-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mc-head .sub{font-size:11px;color:var(--admin-muted);margin-top:2px}
.mc-head .close{background:none;border:none;color:var(--admin-muted);font-size:20px;line-height:1;cursor:pointer;padding:6px 10px;border-radius:6px}
.mc-head .close:hover{background:var(--admin-surface-alt);color:var(--admin-text)}

.mc-body{padding:18px 20px;overflow-y:auto;flex:1}

.mc-vitals{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:16px}
.mc-vitals .vital-card{background:var(--admin-surface-alt);border:1px solid var(--admin-border);border-radius:12px;padding:14px;text-align:center}
.mc-vitals .vital-card .label{font-size:10px;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.04em;font-weight:600}
.mc-vitals .vital-card .value{font-size:24px;font-weight:800;color:var(--admin-text);margin:6px 0 2px;line-height:1.1}
.mc-vitals .vital-card .unit{font-size:11px;color:var(--admin-muted)}
.mc-vitals .vital-card.is-empty .value{font-size:13px;color:var(--admin-muted);font-weight:600}

.mc-section-title{font-weight:700;font-size:11px;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.06em;margin:18px 0 10px;display:flex;align-items:center;gap:8px}
.mc-section-title::after{content:"";flex:1;height:1px;background:var(--admin-border)}
.mc-section-title:first-child{margin-top:0}

.mc-assessment{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.mc-assessment .axis-card{background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:10px;padding:12px}
.mc-assessment .axis-card .axis{font-size:10px;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.04em;font-weight:600}
.mc-assessment .axis-card .status-line{display:flex;align-items:center;justify-content:space-between;margin-top:6px;gap:8px}
.mc-assessment .axis-card .axis-status{font-weight:700;font-size:13px;color:var(--admin-text)}
.mc-assessment .axis-card .zscore{font-family:"Inter",monospace;font-weight:700;font-size:14px;color:var(--admin-primary)}
.mc-assessment .axis-card .zscore.is-warn{color:var(--admin-danger)}

.mc-chart-wrap{margin-top:6px;padding:14px;border:1px solid var(--admin-border);border-radius:10px;background:var(--admin-surface)}
.mc-chart-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px}
.mc-chart-title{font-size:12px;font-weight:700;color:var(--admin-text);text-transform:uppercase;letter-spacing:.04em}
.mc-chart-legend{display:flex;gap:10px;font-size:10px;color:var(--admin-muted);flex-wrap:wrap}
.mc-chart-legend .swatch{display:inline-block;width:8px;height:8px;border-radius:2px;margin-right:4px;vertical-align:middle}
.mc-tabs{display:flex;gap:4px;flex-wrap:wrap;margin-bottom:8px}
.mc-tab{padding:5px 10px;border:1px solid var(--admin-border);border-radius:7px;background:var(--admin-surface);color:var(--admin-muted);font-size:10px;font-weight:600;cursor:pointer}
.mc-tab.is-active{background:var(--admin-primary);border-color:var(--admin-primary);color:#fff}

.mc-history-wrap{overflow-x:auto;border:1px solid var(--admin-border);border-radius:10px;margin-top:6px}
.mc-history{min-width:520px;width:100%;border-collapse:collapse;background:var(--admin-table-bg)}
.mc-history th,.mc-history td{padding:9px 12px;text-align:left;border-bottom:1px solid var(--admin-border);font-size:12px;vertical-align:middle}
.mc-history th{background:var(--admin-surface-alt);color:var(--admin-muted);font-size:10px;text-transform:uppercase;letter-spacing:.05em;font-weight:600;white-space:nowrap}
.mc-history tr:last-child td{border-bottom:none}
.mc-history tr.is-flagged td{background:rgba(224,49,49,0.05)}

.mc-foot{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:14px 20px;border-top:1px solid var(--admin-border);background:var(--admin-surface-alt);flex-wrap:wrap}
.mc-foot .left{font-size:11px;color:var(--admin-muted)}
.mc-foot .right{display:flex;gap:8px;flex-wrap:wrap}

.mc-empty{text-align:center;color:var(--admin-muted);font-size:12px;padding:18px;background:var(--admin-surface-alt);border-radius:10px;border:1px dashed var(--admin-border)}

@media (max-width: 560px) {
  .measurements-toolbar{flex-direction:column;align-items:stretch}
  .measurements-toolbar .admin-search{min-width:0;flex:1}
  .measurements-toolbar .admin-select{min-width:0;max-width:100%;width:100%}
  .mc-modal{max-width:calc(100vw - 16px);max-height:85vh}
  .mc-vitals{grid-template-columns:1fr 1fr}
  .mc-assessment{grid-template-columns:1fr}
  .mc-head{padding:14px 16px}
  .mc-body{padding:14px 16px}
  .mc-foot{padding:12px 16px}
}
</style>

<section class="measurements-stats">
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Children in scope</div>
                <div class="admin-card-value"><?php echo (int)$totalAll; ?></div>
                <div class="admin-card-meta"><span class="admin-card-trend">All registered</span></div>
            </div>
        </div>
    </article>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon is-danger">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">At risk</div>
                <div class="admin-card-value"><?php echo (int)$atRiskCount; ?></div>
                <div class="admin-card-meta"><span class="admin-card-trend">Latest reading flagged</span></div>
            </div>
        </div>
    </article>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon is-warn">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">No measurement</div>
                <div class="admin-card-value"><?php echo (int)$pendingCount; ?></div>
                <div class="admin-card-meta"><span class="admin-card-trend">Needs first weigh-in</span></div>
            </div>
        </div>
    </article>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon is-success">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Total records</div>
                <div class="admin-card-value"><?php echo (int)$totalMeasurements; ?></div>
                <div class="admin-card-meta"><span class="admin-card-trend is-up">All measurements in scope</span></div>
            </div>
        </div>
    </article>
</section>

<section class="nutritionist-panel">
    <div class="nutritionist-form-head" style="margin-bottom:14px;">
        <div>
            <h2 class="admin-section-title" style="margin-bottom:2px;">Children &amp; latest measurements</h2>
            <p class="admin-section-subtitle">
                One row per child. Click a row to open the measurement card with WHO assessment, growth chart, and full history.
            </p>
        </div>
    </div>

    <div class="measurements-toolbar">
        <input
            class="admin-search"
            data-admin-filter="#measurements-table"
            type="search"
            placeholder="Search by name, code, guardian, or address..."
        >
        <select
            class="admin-select"
            id="local-area-filter"
            onchange="window.location.href=this.value"
        >
            <option value="<?php echo nutritionist_e(nutritionist_measurements_url(['page' => 1])); ?>">All local areas</option>
            <?php foreach ($localAreaList as $la): ?>
                <option
                    value="<?php echo nutritionist_e(nutritionist_measurements_url(['local_area_id' => (int)$la['id'], 'page' => 1])); ?>"
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
        <table class="nutritionist-table measurements-table" id="measurements-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Address (Local area · Barangay)</th>
                    <th>Name of Guardian</th>
                    <th>Full name of child</th>
                    <th>Sex</th>
                    <th>Age (months)</th>
                    <th>Age (days)</th>
                    <th>WFA</th>
                    <th>HFA</th>
                    <th>WFH</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($pageChildren === []): ?>
                    <tr><td colspan="11">
                        <div class="children-empty">
                            <?php if ($totalAll === 0): ?>
                                <div class="empty-title">No children registered yet</div>
                                <div class="empty-sub">Your scope doesn't have any registered children. Once children are added, you'll see their latest measurement here.</div>
                                <?php if (nutritionist_can_write('children.create')): ?>
                                    <a class="admin-btn" href="<?php echo nutritionist_e(app_url('/nutritionist/child_form.php')); ?>"><?php echo admin_action_icon('add'); ?> Add the first child</a>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="empty-title">No children in this view</div>
                                <div class="empty-sub">Your current local area filter doesn't include any of the <?php echo (int)$totalAll; ?> children in your scope. Clear the filter to see all of them.</div>
                                <a class="admin-btn-secondary" href="<?php echo nutritionist_e(nutritionist_measurements_url([])); ?>">Clear filter</a>
                            <?php endif; ?>
                        </div>
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($pageChildren as $child): ?>
                    <?php
                    $ageMonths = doh_age_in_months((string)$child['birthdate']) ?? 0;
                    $ageDays = doh_age_in_days((string)$child['birthdate']) ?? 0;
                    $fullName = trim($child['first_name'] . ' ' . ($child['middle_name'] ?? '') . ' ' . $child['last_name']);
                    $hasMeasurement = !empty($child['measurement_id']);
                    $wfa = (string)($child['wfa_status'] ?? '');
                    $hfa = (string)($child['hfa_status'] ?? '');
                    $wfh = (string)($child['wfh_status'] ?? '');
                    $status = (string)($child['nutritional_status'] ?? 'Pending');
                    $pillClass = nutritionist_status_class($status);

                    // Encode the entire history for this child so the
                    // modal can render the WHO chart + history without
                    // an extra round-trip.
                    $childHistory = $historyByChild[(int)$child['id']] ?? [];
                    $historyJson = json_encode($childHistory, JSON_UNESCAPED_UNICODE);

                    // Each axis status is rendered as its own pill so
                    // empty / Normal / SUW / MUW / OW / SSt / MSt /
                    // Tall / SW / MW / Ob are all distinguishable at a
                    // glance.
                    $wfaDisplay = $wfa !== '' ? $wfa : '—';
                    $hfaDisplay = $hfa !== '' ? $hfa : '—';
                    $wfhDisplay = $wfh !== '' ? $wfh : '—';
                    $wfaPill = $wfa !== '' ? nutritionist_status_class($wfa) : 'is-muted';
                    $hfaPill = $hfa !== '' ? nutritionist_status_class($hfa) : 'is-muted';
                    $wfhPill = $wfh !== '' ? nutritionist_status_class($wfh) : 'is-muted';
                    $parentAddress = (string)($child['parent_address'] ?? '');
                    ?>
                    <tr
                        class="row-link"
                        data-filter-text="<?php echo nutritionist_e(strtolower($child['child_code'] . ' ' . $fullName . ' ' . ($child['parent_name'] ?? '') . ' ' . ($child['barangay'] ?? '') . ' ' . ($child['local_area'] ?? '') . ' ' . $parentAddress . ' ' . $wfa . ' ' . $hfa . ' ' . $wfh . ' ' . $status)); ?>"
                        data-history="<?php echo nutritionist_e($historyJson); ?>"
                        data-child-name="<?php echo nutritionist_e($fullName); ?>"
                        data-child-code="<?php echo nutritionist_e((string)$child['child_code']); ?>"
                        data-child-sex="<?php echo nutritionist_e((string)$child['sex']); ?>"
                        data-child-age="<?php echo (int)$ageMonths; ?>"
                        data-child-status="<?php echo nutritionist_e($status); ?>"
                        data-child-status-class="<?php echo nutritionist_e($pillClass); ?>"
                        data-child-wfa="<?php echo nutritionist_e($wfa); ?>"
                        data-child-hfa="<?php echo nutritionist_e($hfa); ?>"
                        data-child-wfh="<?php echo nutritionist_e($wfh); ?>"
                        data-child-waz="<?php echo nutritionist_e(number_format((float)($child['waz'] ?? 0), 2)); ?>"
                        data-child-haz="<?php echo nutritionist_e(number_format((float)($child['haz'] ?? 0), 2)); ?>"
                        data-child-whz="<?php echo nutritionist_e(number_format((float)($child['whz'] ?? 0), 2)); ?>"
                        data-child-measurement-date="<?php echo nutritionist_e((string)($child['measurement_date'] ?? '')); ?>"
                        data-child-height="<?php echo nutritionist_e((string)($child['height_cm'] ?? '')); ?>"
                        data-child-weight="<?php echo nutritionist_e((string)($child['weight_kg'] ?? '')); ?>"
                        data-child-has-measurement="<?php echo $hasMeasurement ? '1' : '0'; ?>"
                        data-child-id="<?php echo (int)$child['id']; ?>"
                    >
                        <td style="font-family:monospace;color:var(--admin-muted);white-space:nowrap;"><?php echo nutritionist_e($child['child_code']); ?></td>
                        <td class="address-cell">
                            <div class="primary"><?php echo nutritionist_e(nmeas_short_address($child['local_area'] ?? null, $child['barangay'] ?? null)); ?></div>
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
                        <td style="color:var(--admin-muted);white-space:nowrap;font-weight:600;"><?php echo (int)$ageMonths; ?></td>
                        <td style="color:var(--admin-muted);white-space:nowrap;font-weight:600;"><?php echo (int)$ageDays; ?></td>
                        <td style="text-align:center;white-space:nowrap;">
                            <?php if ($hasMeasurement): ?>
                                <span class="admin-pill <?php echo nutritionist_e($wfaPill); ?>" style="font-size:10px;padding:2px 7px;"><?php echo nutritionist_e($wfaDisplay); ?></span>
                            <?php else: ?>
                                <span style="color:var(--admin-muted);font-size:10px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;white-space:nowrap;">
                            <?php if ($hasMeasurement): ?>
                                <span class="admin-pill <?php echo nutritionist_e($hfaPill); ?>" style="font-size:10px;padding:2px 7px;"><?php echo nutritionist_e($hfaDisplay); ?></span>
                            <?php else: ?>
                                <span style="color:var(--admin-muted);font-size:10px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;white-space:nowrap;">
                            <?php if ($hasMeasurement): ?>
                                <span class="admin-pill <?php echo nutritionist_e($wfhPill); ?>" style="font-size:10px;padding:2px 7px;"><?php echo nutritionist_e($wfhDisplay); ?></span>
                            <?php else: ?>
                                <span style="color:var(--admin-muted);font-size:10px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="admin-actions" onclick="event.stopPropagation();">
                                <button type="button" class="admin-icon-btn admin-icon-btn-primary" title="View measurement card" data-view-card="<?php echo (int)$child['id']; ?>"><?php echo admin_action_icon('view'); ?></button>
                                <a class="admin-icon-btn" title="Add measurement" href="<?php echo nutritionist_e(app_url('/nutritionist/measurement_record.php?child=' . (int)$child['id'])); ?>"><?php echo admin_action_icon('add'); ?></a>
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
                <a class="page-btn<?php echo $page <= 1 ? ' is-disabled' : ''; ?>" href="<?php echo nutritionist_e(nutritionist_measurements_url(['local_area_id' => $localAreaFilter > 0 ? $localAreaFilter : null, 'page' => $prevPage])); ?>">‹ Prev</a>
                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                if ($start > 1) {
                    echo '<a class="page-btn" href="' . nutritionist_e(nutritionist_measurements_url(['local_area_id' => $localAreaFilter > 0 ? $localAreaFilter : null, 'page' => 1])) . '">1</a>';
                    if ($start > 2) echo '<span class="status" style="padding:0 4px;">…</span>';
                }
                for ($i = $start; $i <= $end; $i++) {
                    echo '<a class="page-btn' . ($i === $page ? ' is-active' : '') . '" href="' . nutritionist_e(nutritionist_measurements_url(['local_area_id' => $localAreaFilter > 0 ? $localAreaFilter : null, 'page' => $i])) . '">' . $i . '</a>';
                }
                if ($end < $totalPages) {
                    if ($end < $totalPages - 1) echo '<span class="status" style="padding:0 4px;">…</span>';
                    echo '<a class="page-btn" href="' . nutritionist_e(nutritionist_measurements_url(['local_area_id' => $localAreaFilter > 0 ? $localAreaFilter : null, 'page' => $totalPages])) . '">' . $totalPages . '</a>';
                }
                ?>
                <a class="page-btn<?php echo $page >= $totalPages ? ' is-disabled' : ''; ?>" href="<?php echo nutritionist_e(nutritionist_measurements_url(['local_area_id' => $localAreaFilter > 0 ? $localAreaFilter : null, 'page' => $nextPage])); ?>">Next ›</a>
            </div>
        </div>
    <?php endif; ?>
</section>

<!--
    Measurement card modal. Renders the WHO assessment, growth chart,
    and full measurement history for the clicked child. Tabs allow
    switching between WAZ / HAZ / WHZ trend views.
-->
<div class="mc-overlay" id="mc-overlay" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="mc-modal">
        <div class="mc-head">
            <div class="avatar" id="mc-avatar">--</div>
            <div class="meta">
                <div class="name" id="mc-name">Child name</div>
                <div class="sub" id="mc-sub">—</div>
            </div>
            <button type="button" class="close" id="mc-close" aria-label="Close">×</button>
        </div>
        <div class="mc-body">
            <div class="mc-vitals" id="mc-vitals">
                <div class="vital-card is-empty" id="mc-card-weight">
                    <div class="label">Weight</div>
                    <div class="value" id="mc-weight">—</div>
                    <div class="unit">kg</div>
                </div>
                <div class="vital-card is-empty" id="mc-card-height">
                    <div class="label">Height</div>
                    <div class="value" id="mc-height">—</div>
                    <div class="unit">cm</div>
                </div>
            </div>

            <div class="mc-section-title">Nutrition assessment</div>
            <div class="mc-assessment" id="mc-assessment">
                <div class="axis-card" data-axis="wfa">
                    <div class="axis">Weight-for-Age</div>
                    <div class="status-line">
                        <span class="axis-status" id="mc-wfa-status">—</span>
                        <span class="zscore" id="mc-waz">—</span>
                    </div>
                </div>
                <div class="axis-card" data-axis="hfa">
                    <div class="axis">Height-for-Age</div>
                    <div class="status-line">
                        <span class="axis-status" id="mc-hfa-status">—</span>
                        <span class="zscore" id="mc-haz">—</span>
                    </div>
                </div>
                <div class="axis-card" data-axis="wfh">
                    <div class="axis">Weight-for-Height</div>
                    <div class="status-line">
                        <span class="axis-status" id="mc-wfh-status">—</span>
                        <span class="zscore" id="mc-whz">—</span>
                    </div>
                </div>
            </div>

            <div class="mc-section-title">Growth progress</div>
            <div class="mc-chart-wrap">
                <div class="mc-chart-head">
                    <div class="mc-chart-title" id="mc-chart-title">Weight-for-Age trend</div>
                    <div class="mc-chart-legend">
                        <span><span class="swatch" id="mc-legend-dot" style="background:#16a34a;"></span><span id="mc-legend-label">WAZ</span></span>
                    </div>
                </div>
                <div class="mc-tabs" id="mc-tabs">
                    <button type="button" class="mc-tab is-active" data-metric="waz" data-color="#16a34a">Weight-for-Age</button>
                    <button type="button" class="mc-tab" data-metric="haz" data-color="#4a9fd5">Height-for-Age</button>
                    <button type="button" class="mc-tab" data-metric="whz" data-color="#0d8871">Weight-for-Height</button>
                </div>
                <canvas id="mc-chart" height="200" style="width:100%;height:200px;display:block;"></canvas>
            </div>

            <div class="mc-section-title">Measurement history</div>
            <div id="mc-history-host"></div>
        </div>
        <div class="mc-foot">
            <span class="left" id="mc-foot-left">—</span>
            <div class="right">
                <a class="admin-btn-secondary" id="mc-edit-profile" href="#">Edit profile</a>
                <a class="admin-btn" id="mc-add-measurement" href="#">+ New measurement</a>
                <button type="button" class="admin-btn-secondary" id="mc-close-2">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var overlay = document.getElementById('mc-overlay');
    if (!overlay) return;

    function val(id) { return document.getElementById(id); }
    function text(id, v) { var el = val(id); if (el) el.textContent = (v === null || v === undefined || v === '') ? '—' : v; }
    function html(id, v) { var el = val(id); if (el) el.innerHTML = v; }
    function num(v) { var n = parseFloat(v); return isNaN(n) ? null : n; }
    function signed(v) { var n = num(v); if (n === null) return '—'; return (n > 0 ? '+' : '') + n.toFixed(2); }
    function absWarn(v) { var n = num(v); return n !== null && Math.abs(n) > 2; }

    function formatDate(iso) {
        if (!iso) return '—';
        var d = new Date(String(iso) + 'T00:00:00');
        if (isNaN(d.getTime())) return '—';
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
    }

    function formatAge(months) {
        if (months === null || months === undefined || isNaN(parseInt(months, 10))) return '—';
        var m = parseInt(months, 10);
        var y = Math.floor(m / 12);
        var rem = m % 12;
        if (y === 0) return m + ' months';
        if (rem === 0) return y + (y === 1 ? ' year' : ' years');
        return y + ' year' + (y === 1 ? '' : 's') + ' ' + rem + ' months';
    }

    var currentHistory = [];
    var currentMetric = 'waz';

    function statusPillClass(s) {
        var x = String(s || '').toLowerCase();
        if (x === 'normal' || x === 'tall') return 'is-success';
        if (x === 'overweight' || x === 'obese' || x === 'ow' || x === 'ob') return 'is-orange';
        if (x.indexOf('moderately') !== -1 || x === 'muw' || x === 'mst' || x === 'mw') return 'is-warn';
        if (x === 'suw' || x === 'sst' || x === 'sw' || x.indexOf('severely') !== -1) return 'is-danger';
        // WFA overflow: WAZ > +2 is a redirect, not a real WFA label.
        if (x.indexOf('refer') !== -1 || x === 'ref') return 'is-info';
        if (!x) return 'is-muted';
        return 'is-danger';
    }

    function openCard(row) {
        if (!row) return;
        var get = function (k) { return row.getAttribute('data-child-' + k) || ''; };

        var name = get('name');
        var code = get('code');
        var initials = (name.split(' ').filter(Boolean).slice(0, 2).map(function (s) { return s.charAt(0).toUpperCase(); }).join('')) || '--';

        val('mc-avatar').textContent = initials;
        var rowAvatar = row.querySelector('.child-name-cell .avatar');
        val('mc-avatar').style.background = rowAvatar ? rowAvatar.style.background : '#94a3b8';

        text('mc-name', name);
        text('mc-sub', code + ' · ' + formatAge(get('age')) + ' · ' + (get('sex') || '—'));

        var hasMeas = get('has-measurement') === '1';
        var w = num(get('weight'));
        var h = num(get('height'));
        text('mc-weight', w !== null ? w.toFixed(2) : '—');
        text('mc-height', h !== null ? h.toFixed(1) : '—');
        ['mc-card-weight','mc-card-height'].forEach(function (id) { val(id).classList.add('is-empty'); });
        if (w !== null) val('mc-card-weight').classList.remove('is-empty');
        if (h !== null) val('mc-card-height').classList.remove('is-empty');

        text('mc-wfa-status', get('wfa') || '—');
        text('mc-hfa-status', get('hfa') || '—');
        text('mc-wfh-status', get('wfh') || '—');

        var wazEl = val('mc-waz');
        var hazEl = val('mc-haz');
        var whzEl = val('mc-whz');
        wazEl.textContent = signed(get('waz')); wazEl.className = 'zscore' + (absWarn(get('waz')) ? ' is-warn' : '');
        hazEl.textContent = signed(get('haz')); hazEl.className = 'zscore' + (absWarn(get('haz')) ? ' is-warn' : '');
        whzEl.textContent = signed(get('whz')); whzEl.className = 'zscore' + (absWarn(get('whz')) ? ' is-warn' : '');

        // Colour the WFA/HFA/WFH status text by class so the status
        // reads as a "pill" even when there's no actual pill element.
        ['mc-wfa-status','mc-hfa-status','mc-wfh-status'].forEach(function (id) {
            var el = val(id);
            el.className = 'axis-status';
            el.style.color = '';
            var st = el.textContent;
            var cls = statusPillClass(st);
            el.style.color = ({
                'is-success': 'var(--admin-primary)',
                'is-warn': '#b6791f',
                'is-orange': '#b96a1a',
                'is-danger': '#E03131',
                'is-muted': 'var(--admin-muted)'
            })[cls] || 'var(--admin-text)';
        });

        // History JSON
        var raw = row.getAttribute('data-history') || '[]';
        try { currentHistory = JSON.parse(raw) || []; }
        catch (e) { currentHistory = []; }

        renderHistory();
        renderChart('waz');

        // Default tab
        Array.prototype.forEach.call(document.querySelectorAll('#mc-tabs .mc-tab'), function (b) { b.classList.remove('is-active'); });
        var defTab = document.querySelector('#mc-tabs .mc-tab[data-metric="waz"]');
        if (defTab) defTab.classList.add('is-active');
        currentMetric = 'waz';

        // Footer
        var childId = row.getAttribute('data-child-id') || '';
        val('mc-edit-profile').setAttribute('href', '<?php echo nutritionist_e(app_url('/nutritionist/child_form.php')); ?>?id=' + childId);
        val('mc-add-measurement').setAttribute('href', '<?php echo nutritionist_e(app_url('/nutritionist/measurement_record.php')); ?>?child=' + childId);
        text('mc-foot-left', hasMeas
            ? 'Latest reading ' + formatDate(get('measurement-date'))
            : 'No measurement on record yet');

        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeCard() {
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function renderHistory() {
        if (!currentHistory || currentHistory.length === 0) {
            html('mc-history-host', '<div class="mc-empty">No measurements recorded yet for this child.</div>');
            return;
        }
        var rows = currentHistory.map(function (m) {
            var d = formatDate(m.measurement_date);
            var w = num(m.weight_kg);
            var h = num(m.height_cm);
            var flagged = !!parseInt(m.is_flagged || '0', 10);
            var waz = num(m.waz), haz = num(m.haz), whz = num(m.whz);
            var wfa = String(m.wfa_status || 'Normal');
            var hfa = String(m.hfa_status || 'Normal');
            var wfh = String(m.wfh_status || 'Normal');
            var abn = [];
            if (wfa !== 'Normal') abn.push(wfa);
            if (hfa !== 'Normal' && hfa !== 'Tall') abn.push(hfa);
            if (hfa === 'Tall') abn.push('Tall');
            if (wfh !== 'Normal') abn.push(wfh);
            var status = abn.length > 0 ? abn.join(' + ') : 'Normal';
            var statusCls = statusPillClass(status);
            return ''
                + '<tr' + (flagged ? ' class="is-flagged"' : '') + '>'
                + '<td style="white-space:nowrap;">' + d + '</td>'
                + '<td style="color:var(--admin-muted);">' + (w !== null ? w.toFixed(2) + ' kg' : '—') + '</td>'
                + '<td style="color:var(--admin-muted);">' + (h !== null ? h.toFixed(1) + ' cm' : '—') + '</td>'
                + '<td style="color:var(--admin-primary);font-weight:600;">' + signed(waz) + '</td>'
                + '<td style="color:#4a9fd5;font-weight:600;">' + signed(haz) + '</td>'
                + '<td style="color:#0d8871;font-weight:600;">' + signed(whz) + '</td>'
                + '<td><span class="admin-pill ' + statusCls + '">' + status + '</span></td>'
                + '</tr>';
        }).join('');
        html('mc-history-host', ''
            + '<div class="mc-history-wrap"><table class="mc-history">'
            + '<thead><tr><th>Date</th><th>Weight</th><th>Height</th><th>WAZ</th><th>HAZ</th><th>WHZ</th><th>Status</th></tr></thead>'
            + '<tbody>' + rows + '</tbody></table></div>');
    }

    function renderChart(metric) {
        currentMetric = metric;
        var canvas = val('mc-chart');
        if (!canvas) return;
        var dpr = window.devicePixelRatio || 1;

        // Series: oldest → newest, so the line reads left-to-right.
        var points = (currentHistory || []).slice().reverse().map(function (m) {
            return { date: m.measurement_date, v: num(m[metric]) };
        }).filter(function (p) { return p.v !== null; });

        var labelEl = val('mc-legend-label');
        var dotEl = val('mc-legend-dot');
        var titleEl = val('mc-chart-title');
        if (labelEl) labelEl.textContent = metric.toUpperCase();
        var tabBtn = document.querySelector('#mc-tabs .mc-tab[data-metric="' + metric + '"]');
        if (dotEl && tabBtn) dotEl.style.background = tabBtn.getAttribute('data-color');
        if (titleEl) {
            titleEl.textContent = ({
                'waz': 'Weight-for-Age trend',
                'haz': 'Height-for-Age trend',
                'whz': 'Weight-for-Height trend'
            })[metric] || metric.toUpperCase();
        }

        var parent = canvas.parentElement;
        var rect = parent.getBoundingClientRect();
        var W = Math.max(rect.width - 28, 220);
        var H = 200;
        canvas.width = W * dpr;
        canvas.height = H * dpr;
        canvas.style.width = W + 'px';
        canvas.style.height = H + 'px';
        var ctx = canvas.getContext('2d');
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.scale(dpr, dpr);

        var padL = 36, padR = 10, padT = 12, padB = 24;
        var cW = W - padL - padR;
        var cH = H - padT - padB;

        // Background grid
        var border = (getComputedStyle(document.documentElement).getPropertyValue('--admin-border') || '#d7e4dc').trim();
        ctx.strokeStyle = border; ctx.lineWidth = 0.5;
        for (var g = 0; g <= 4; g++) {
            var y = padT + (cH * g) / 4;
            ctx.beginPath(); ctx.moveTo(padL, y); ctx.lineTo(W - padR, y); ctx.stroke();
        }

        if (points.length === 0) {
            ctx.fillStyle = '#94a3b8';
            ctx.font = '12px Inter, sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('No data to plot yet', padL + cW / 2, padT + cH / 2);
            return;
        }

        var maxVal = Math.max.apply(null, points.map(function (p) { return p.v; }).concat([3]));
        var minVal = Math.min.apply(null, points.map(function (p) { return p.v; }).concat([-3]));
        var span = (maxVal - minVal) || 1;

        function yFor(v) { return padT + cH - ((v - minVal) / span) * cH; }
        function xFor(i) { return padL + (points.length > 1 ? (i / (points.length - 1)) * cW : cW / 2); }

        // Zero line
        ctx.strokeStyle = 'rgba(0,0,0,0.15)'; ctx.setLineDash([3, 3]);
        var zeroY = yFor(0);
        ctx.beginPath(); ctx.moveTo(padL, zeroY); ctx.lineTo(W - padR, zeroY); ctx.stroke();
        ctx.setLineDash([]);

        // Y labels
        ctx.fillStyle = '#94a3b8';
        ctx.font = '9px Inter, sans-serif';
        ctx.textAlign = 'right';
        [maxVal, 0, minVal].forEach(function (v) {
            ctx.fillText((v > 0 ? '+' : '') + v.toFixed(0), padL - 4, yFor(v) + 3);
        });

        // Line + dots
        var color = (tabBtn && tabBtn.getAttribute('data-color')) || '#16a34a';
        ctx.strokeStyle = color;
        ctx.lineWidth = 2;
        ctx.beginPath();
        points.forEach(function (p, i) {
            var x = xFor(i), y = yFor(p.v);
            if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
        });
        ctx.stroke();

        // Smooth area fill under the line
        ctx.lineTo(xFor(points.length - 1), padT + cH);
        ctx.lineTo(xFor(0), padT + cH);
        ctx.closePath();
        ctx.fillStyle = color.replace(')', ', 0.10)').replace('rgb', 'rgba');
        if (ctx.fillStyle === color) {
            // Fallback: hex to rgba at 0.10 alpha
            var m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(color);
            if (m) ctx.fillStyle = 'rgba(' + parseInt(m[1], 16) + ',' + parseInt(m[2], 16) + ',' + parseInt(m[3], 16) + ',0.10)';
        }
        ctx.fill();

        // Dots
        ctx.strokeStyle = color;
        points.forEach(function (p, i) {
            ctx.fillStyle = color;
            ctx.beginPath(); ctx.arc(xFor(i), yFor(p.v), 3, 0, Math.PI * 2); ctx.fill();
            ctx.fillStyle = '#fff';
            ctx.beginPath(); ctx.arc(xFor(i), yFor(p.v), 1.4, 0, Math.PI * 2); ctx.fill();
        });

        // X labels
        ctx.fillStyle = '#94a3b8';
        ctx.textAlign = 'center';
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        points.forEach(function (p, i) {
            if (i % Math.max(1, Math.floor(points.length / 6)) === 0 || i === points.length - 1) {
                var d = new Date(p.date + 'T00:00:00');
                if (!isNaN(d.getTime())) {
                    ctx.fillText(months[d.getMonth()] + ' ' + d.getFullYear().toString().slice(2), xFor(i), H - 6);
                }
            }
        });
    }

    document.querySelectorAll('[data-view-card]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var row = btn.closest('tr.row-link');
            openCard(row);
        });
    });

    document.querySelectorAll('#measurements-table tr.row-link').forEach(function (row) {
        row.addEventListener('click', function () { openCard(row); });
    });

    document.querySelectorAll('#mc-tabs .mc-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            document.querySelectorAll('#mc-tabs .mc-tab').forEach(function (b) { b.classList.remove('is-active'); });
            tab.classList.add('is-active');
            renderChart(tab.getAttribute('data-metric'));
        });
    });

    val('mc-close').addEventListener('click', closeCard);
    val('mc-close-2').addEventListener('click', closeCard);
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

<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/monitoring_periods.php';
require_once __DIR__ . '/../includes/who_calculator.php';
require_once __DIR__ . '/../includes/export_dropdown.php';

$user = nutritionist_require_access();

// ── Params ──
$view = (string)($_GET['view'] ?? 'monthly');
if (!in_array($view, ['monthly', 'quarterly'], true)) {
    $view = 'monthly';
}

$year = (int)($_GET['year'] ?? (int)date('Y'));
if ($year < 2020 || $year > 2100) {
    $year = (int)date('Y');
}

$nowMonth = (int)date('n');
$nowQuarter = (int)ceil($nowMonth / 3);

$month = (int)($_GET['month'] ?? $nowMonth);
if ($month < 1 || $month > 12) {
    $month = $nowMonth;
}

$quarter = (int)($_GET['quarter'] ?? $nowQuarter);
if ($quarter < 1 || $quarter > 4) {
    $quarter = $nowQuarter;
}

$search = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 5;

// ── Active period ──
if ($view === 'monthly') {
    $period = monitoring_month_range($year, $month);
    $periodMonths = [$month];
} else {
    $period = monitoring_quarter_range($year, $quarter);
    $periodMonths = $period['months'];
}

// ── Roster ──
$roster = monitoring_fetch_list($user, $view, $period['start'], $period['end']);

// ── Period coverage (full roster, unaffected by search) ──
$coverageTotal = count($roster);
$coverageMeasured = 0;
foreach ($roster as $covRow) {
    if (!empty($covRow['measured_in_period'])) {
        $coverageMeasured++;
    }
}
$coveragePct = $coverageTotal > 0 ? (int)round(($coverageMeasured / $coverageTotal) * 100) : 0;

if ($search !== '') {
    $needle = mb_strtolower($search);
    $roster = array_values(array_filter($roster, static function (array $row) use ($needle): bool {
        $haystack = mb_strtolower($row['first_name'] . ' ' . $row['last_name'] . ' ' . $row['child_code']);
        return mb_strpos($haystack, $needle) !== false;
    }));
}

$totalRows = count($roster);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;
$pageRows = array_slice($roster, $offset, $perPage);

// ── Measurement history for the modal (last 5 readings per child on this page) ──
$monHistoryJson = [];
$pageChildIds = array_values(array_unique(array_map(static fn(array $r): int => (int)$r['id'], $pageRows)));
if ($pageChildIds !== []) {
    $inPlaceholders = implode(',', array_fill(0, count($pageChildIds), '?'));
    $histRows = admin_fetch_all(
        "SELECT child_id, measurement_date, weight_kg, height_cm,
            waz, haz, whz, wfa_status, hfa_status, wfh_status,
            nutritional_status, measurement_type
         FROM measurements
         WHERE child_id IN ({$inPlaceholders})
           AND measurement_type IN ('ROUTINE','OVERRIDE','RECHECK')
         ORDER BY measurement_date DESC, id DESC",
        str_repeat('i', count($pageChildIds)),
        $pageChildIds
    );
    $histByChild = [];
    foreach ($histRows as $hr) {
        $histByChild[(int)$hr['child_id']][] = $hr;
    }
    foreach ($pageRows as $entry) {
        $cid = (int)$entry['id'];
        $fullName = trim($entry['first_name'] . ' ' . $entry['last_name']);
        $hist = array_slice($histByChild[$cid] ?? [], 0, 5);
        $monHistoryJson[$cid] = [
            'id' => $cid,
            'name' => $fullName,
            'code' => (string)($entry['child_code'] ?? ''),
            'sex' => (string)($entry['sex'] ?? ''),
            'barangay' => (string)($entry['barangay_name'] ?? ''),
            'parent' => (string)($entry['parent_name'] ?? ''),
            'avatar_bg' => child_avatar_color((string)($entry['sex'] ?? '')),
            'initials' => admin_initials($fullName),
            'record_url' => app_url('/nutritionist/measurement_record.php?child=' . $cid),
            'history' => array_map(static function (array $m): array {
                $wfa = (string)($m['wfa_status'] ?? '—');
                $hfa = (string)($m['hfa_status'] ?? '—');
                $wfhRaw = (string)($m['wfh_status'] ?? '');
                $wfh = $wfhRaw !== '' ? wfh_display_short($wfhRaw) : '—';
                $fmtZ = static fn($v): string => $v === null ? '—' : number_format((float)$v, 2);
                return [
                    'date' => date('M j, Y', strtotime((string)$m['measurement_date'])),
                    'weight' => $m['weight_kg'] !== null ? number_format((float)$m['weight_kg'], 2) . ' kg' : '—',
                    'height' => $m['height_cm'] !== null ? number_format((float)$m['height_cm'], 1) . ' cm' : '—',
                    'waz' => $fmtZ($m['waz'] ?? null),
                    'haz' => $fmtZ($m['haz'] ?? null),
                    'whz' => $fmtZ($m['whz'] ?? null),
                    'wfa' => $wfa,
                    'hfa' => $hfa,
                    'wfh' => $wfh,
                    'wfa_class' => nutritionist_status_class($wfa),
                    'hfa_class' => nutritionist_status_class($hfa),
                    'wfh_class' => nutritionist_status_class($wfh),
                ];
            }, $hist),
        ];
    }
}

// ── Link builders (preserve view state) ──
$baseParams = ['view' => $view, 'year' => $year];
if ($view === 'monthly') {
    $baseParams['month'] = $month;
} else {
    $baseParams['quarter'] = $quarter;
}
if ($search !== '') $baseParams['q'] = $search;

$viewLink = static function (string $v) use ($year): string {
    return app_url('/nutritionist/monitoring.php?' . http_build_query(['view' => $v, 'year' => $year]));
};
$subLink = static function (int $n) use ($view, $year): string {
    $key = $view === 'monthly' ? 'month' : 'quarter';
    return app_url('/nutritionist/monitoring.php?' . http_build_query(['view' => $view, 'year' => $year, $key => $n]));
};
$pageLink = static function (int $p) use ($baseParams): string {
    $params = $baseParams;
    if ($p > 1) $params['page'] = $p;
    return app_url('/nutritionist/monitoring.php?' . http_build_query($params));
};

/*
 * Server-side twin of admin.js buildPageNumbers(): identical DOM and
 * classes (.admin-pagination-*) so this server-paginated roster looks
 * exactly like the client-paginated Children/Parents tables. Anchors
 * stand in for the JS buttons; the active page is a non-link span.
 */
$monPageNumbers = static function (int $current, int $total) use ($pageLink): string {
    $link = static function (int $p) use ($pageLink): string {
        return '<a class="admin-page-num" href="' . nutritionist_e($pageLink($p)) . '">' . $p . '</a>';
    };
    $active = static fn(int $p): string => '<span class="admin-page-num is-active">' . $p . '</span>';

    if ($total <= 7) {
        $out = '';
        for ($p = 1; $p <= $total; $p++) {
            $out .= $p === $current ? $active($p) : $link($p);
        }
        return $out;
    }

    $parts = [1];
    if ($current > 3) {
        $parts[] = '…';
    }
    for ($p = max(2, $current - 1); $p <= min($total - 1, $current + 1); $p++) {
        $parts[] = $p;
    }
    if ($current < $total - 2) {
        $parts[] = '…';
    }
    $parts[] = $total;

    $out = '';
    foreach ($parts as $p) {
        if ($p === '…') {
            $out .= '<span class="admin-page-ellipsis">…</span>';
            continue;
        }
        $out .= $p === $current ? $active($p) : $link($p);
    }
    return $out;
};

$monChevronLeft = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>';
$monChevronRight = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>';

$monthNames = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'];

$actions = '';

nutritionist_layout_start('Monitoring List', 'Track quarterly and monthly monitoring of children.', 'monitoring', $actions);
?>

<style>
.rp-tabs{display:flex;gap:0;border-bottom:2px solid var(--admin-border);margin:0 0 14px}
.rp-tab{display:inline-flex;align-items:center;gap:6px;padding:12px 20px;min-height:44px;font-size:14px;font-weight:600;color:var(--admin-muted);text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;transition:color .15s,border-color .15s,background .15s;border-radius:8px 8px 0 0}
.rp-tab:hover{color:var(--admin-text);background:var(--admin-surface-alt)}
.rp-tab.is-active{color:var(--admin-primary);border-bottom-color:var(--admin-primary);background:transparent}
.mon-subtabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.mon-subtab{font-size:14px;font-weight:700;padding:10px 18px;min-height:44px;display:inline-flex;align-items:center;border-radius:999px;border:2px solid var(--admin-border);background:var(--admin-surface);color:var(--admin-text);text-decoration:none;transition:all .15s}
.mon-subtab:hover{border-color:var(--admin-primary);color:var(--admin-primary)}
.mon-subtab.is-active{background:var(--admin-primary);color:#fff;border-color:var(--admin-primary)}
.mon-subtab.is-active span{opacity:.85;}
.mon-card{background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:14px;padding:18px;margin-bottom:18px}
.children-toolbar{display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;align-items:center}
.children-toolbar .admin-search{flex:0 1 280px;max-width:280px;min-width:200px;min-height:44px;font-size:14px}
.children-toolbar .admin-select{min-width:200px;max-width:260px;min-height:44px;font-size:14px}
.children-toolbar .mon-year{min-width:110px;max-width:130px}
.children-toolbar .admin-btn-secondary{min-height:44px;font-size:14px;display:inline-flex;align-items:center}
.children-toolbar .mon-export{margin-left:auto;display:flex;align-items:center}
.children-toolbar .admin-field{display:flex;flex-direction:column;gap:4px;font-size:12px;color:var(--admin-muted);font-weight:600}
.nutritionist-table-wrap{overflow-x:auto}
.children-table .child-name-cell{display:flex;align-items:center;gap:10px;min-width:0}
.children-table .child-name-cell .avatar{width:34px;height:34px;border-radius:50%;background:#94a3b8;color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.children-table .child-name-cell .text{min-width:0}
.children-table .child-name-cell .text .name{font-weight:600;color:var(--admin-text);font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.children-table .child-name-cell .text .sub{font-size:10px;color:var(--admin-muted);margin-top:1px}
.children-empty{padding:32px 18px;color:var(--admin-muted);font-size:13px;background:var(--admin-surface-alt);border-radius:10px;border:1px dashed var(--admin-border);text-align:center;display:flex;flex-direction:column;align-items:center;gap:10px}
.children-empty .empty-title{font-weight:700;color:var(--admin-text);font-size:14px}
.children-empty .empty-sub{color:var(--admin-muted);max-width:420px;line-height:1.45}
.mon-coverage{flex:1 1 260px;max-width:340px;min-width:220px;margin:0;padding:10px 12px;background:var(--admin-surface-alt);border:1px solid var(--admin-border);border-radius:10px}
.mon-coverage-head{display:flex;justify-content:space-between;align-items:baseline;gap:8px;flex-wrap:wrap;margin-bottom:8px}
.mon-coverage-label{font-size:12px;font-weight:700;color:var(--admin-text);text-transform:uppercase;letter-spacing:.05em}
.mon-coverage-count{font-size:12px;color:var(--admin-muted);font-weight:600}
.mon-coverage-track{height:10px;border-radius:999px;background:var(--admin-border);overflow:hidden}
.mon-coverage-fill{display:block;height:100%;border-radius:999px;background:var(--admin-primary);transition:width .3s}
@media (max-width: 560px) {
  .children-toolbar{flex-direction:column;align-items:stretch}
  .children-toolbar .admin-search{min-width:0;flex:1;max-width:100%}
  .children-toolbar .admin-select{min-width:0;max-width:100%;width:100%}
  .children-toolbar .mon-export{margin-left:0}
  .children-toolbar .mon-coverage{max-width:100%;flex:1 1 auto;min-width:0}
}
</style>

<!-- ============ VIEW TABS ============ -->
<div class="rp-tabs">
    <a class="rp-tab <?php echo $view === 'monthly' ? 'is-active' : ''; ?>" href="<?php echo nutritionist_e($viewLink('monthly')); ?>">Monthly</a>
    <a class="rp-tab <?php echo $view === 'quarterly' ? 'is-active' : ''; ?>" href="<?php echo nutritionist_e($viewLink('quarterly')); ?>">Quarterly</a>
</div>

<!-- ============ SUB TABS ============ -->
<div class="mon-subtabs">
    <?php if ($view === 'monthly'): ?>
        <?php foreach ($monthNames as $mNo => $mName): ?>
            <a class="mon-subtab <?php echo $month === $mNo ? 'is-active' : ''; ?>" href="<?php echo nutritionist_e($subLink($mNo)); ?>"><?php echo $mName; ?></a>
        <?php endforeach; ?>
    <?php else: ?>
        <?php for ($qNo = 1; $qNo <= 4; $qNo++): $qr = monitoring_quarter_range($year, $qNo); ?>
            <?php
            $qMonthNames = array_map(static fn(int $m): string => $monthNames[$m], $qr['months']);
            ?>
            <a class="mon-subtab <?php echo $quarter === $qNo ? 'is-active' : ''; ?>" href="<?php echo nutritionist_e($subLink($qNo)); ?>"><?php echo implode(' - ', $qMonthNames); ?></a>
        <?php endfor; ?>
    <?php endif; ?>
</div>

<!-- ============ ROSTER TABLE (filters + table in one card to save space) ============ -->
<div class="mon-card">
<form method="get" class="children-toolbar" id="mon-filter-form">
    <input type="hidden" name="view" value="<?php echo nutritionist_e($view); ?>">
    <?php if ($view === 'monthly'): ?>
        <input type="hidden" name="month" value="<?php echo $month; ?>">
    <?php else: ?>
        <input type="hidden" name="quarter" value="<?php echo $quarter; ?>">
    <?php endif; ?>
    <input
        id="mon-search"
        class="admin-search"
        type="search"
        name="q"
        placeholder="Search by name, code, guardian, or address..."
        aria-label="Search monitoring roster"
        value="<?php echo nutritionist_e($search); ?>"
        autocomplete="off"
    >
    <select
        class="admin-select mon-year"
        name="year"
        aria-label="Filter by year"
        onchange="this.form.submit()"
    >
        <?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 4; $y--): ?>
            <option value="<?php echo $y; ?>" <?php echo $year === $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
        <?php endfor; ?>
    </select>
    <div class="mon-coverage">
        <div class="mon-coverage-head">
            <span class="mon-coverage-label">Children Measured</span>
            <span class="mon-coverage-count"><?php echo (int)$coverageMeasured; ?> of <?php echo (int)$coverageTotal; ?> (<?php echo (int)$coveragePct; ?>%)</span>
        </div>
        <div class="mon-coverage-track" role="progressbar" aria-valuenow="<?php echo (int)$coveragePct; ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Children measured this period">
            <span class="mon-coverage-fill" style="width:<?php echo (int)$coveragePct; ?>%;"></span>
        </div>
    </div>
    <?php if ($search !== ''): ?>
    <div style="display:flex;gap:6px;align-items:center;">
            <a class="admin-btn-secondary" href="<?php echo nutritionist_e($view === 'monthly' ? $subLink($month) : $subLink($quarter)); ?>">Clear</a>
    </div>
    <?php endif; ?>
    <div class="mon-export">
        <?php
        $expBase = ['view' => $view, 'year' => $year];
        if ($view === 'monthly') {
            $expBase['month'] = $month;
        } else {
            $expBase['quarter'] = $quarter;
        }
        if ($search !== '') {
            $expBase['q'] = $search;
        }
        echo export_dropdown(
            app_url('/nutritionist/monitoring_export.php?' . http_build_query(array_merge($expBase, ['format' => 'xlsx']))),
            app_url('/nutritionist/monitoring_export.php?' . http_build_query(array_merge($expBase, ['format' => 'csv']))),
            app_url('/nutritionist/monitoring_export.php?' . http_build_query(array_merge($expBase, ['format' => 'pdf']))),
            'Export'
        );
        ?>
    </div>
</form>

    <?php if (empty($pageRows)): ?>
        <div class="children-empty">
            <div class="empty-title">No children in this roster</div>
            <div class="empty-sub">No children in your scope fall into this roster for the selected period.</div>
        </div>
    <?php else: ?>
    <div class="nutritionist-table-wrap">
        <table class="nutritionist-table children-table" data-no-paginate>
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
                <?php
                $monShort = static fn(string $code): string => match ($code) {
                    'Normal' => 'N',
                    'Tall' => 'T',
                    default => $code,
                };
                ?>
                <?php foreach ($pageRows as $entry):
                    $fullName = trim($entry['first_name'] . ' ' . $entry['last_name']);
                    $measured = $entry['measured_in_period'];
                    $wfaCode = (string)($entry['wfa_status'] ?? '—');
                    $hfaCode = (string)($entry['hfa_status'] ?? '—');
                    $wfhRaw = (string)($entry['wfh_status'] ?? '');
                    $wfhCode = $wfhRaw !== '' ? wfh_display_short($wfhRaw) : '—';
                    $ageDays = doh_age((string)$entry['birthdate']) ?? ['days' => 0, 'months' => 0];
                    $hasMeasurement = $measured && $entry['period_measurement_date'];
                    $recordUrl = nutritionist_e(app_url('/nutritionist/measurement_record.php?child=' . (int)$entry['id']));
                ?>
                <tr>
                    <td>
                        <div class="child-name-cell">
                            <span class="avatar" style="background:<?php echo nutritionist_e(child_avatar_color((string)($entry['sex'] ?? ''))); ?>;"><?php echo nutritionist_e(admin_initials($fullName)); ?></span>
                            <div class="text">
                                <div class="name"><?php echo nutritionist_e($fullName); ?></div>
                                <div class="sub"><?php echo nutritionist_e((string)$entry['child_code']); ?> · <?php echo nutritionist_e((string)$entry['sex']); ?></div>
                            </div>
                        </div>
                    </td>
                    <td style="white-space:nowrap;">
                        <?php if ($measured && $entry['period_measurement_date']): ?>
                            <?php echo nutritionist_e(date('M j, Y', strtotime((string)$entry['period_measurement_date']))); ?>
                        <?php else: ?>
                            <span style="color:var(--admin-muted);font-style:italic;">Not yet</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;font-weight:600;">
                        <?php echo $hasMeasurement && $entry['last_weight'] !== null ? number_format((float)$entry['last_weight'], 2) : '<span style="color:var(--admin-muted);font-weight:400;">—</span>'; ?>
                    </td>
                    <td style="white-space:nowrap;font-weight:600;">
                        <?php echo $hasMeasurement && $entry['last_height'] !== null ? number_format((float)$entry['last_height'], 1) : '<span style="color:var(--admin-muted);font-weight:400;">—</span>'; ?>
                    </td>
                    <td>
                        <?php if ($hasMeasurement): ?>
                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                            <span class="admin-pill <?php echo nutritionist_status_class($wfaCode); ?>" title="Weight-for-Age: <?php echo nutritionist_e($wfaCode); ?>"><?php echo nutritionist_e($monShort($wfaCode)); ?></span>
                            <span class="admin-pill <?php echo nutritionist_status_class($hfaCode); ?>" title="Height-for-Age: <?php echo nutritionist_e($hfaCode); ?>"><?php echo nutritionist_e($monShort($hfaCode)); ?></span>
                            <span class="admin-pill <?php echo nutritionist_status_class($wfhCode); ?>" title="Weight-for-Length/Height: <?php echo nutritionist_e($wfhCode); ?>"><?php echo nutritionist_e($monShort($wfhCode)); ?></span>
                        </div>
                        <?php else: ?>
                            <span class="admin-pill is-muted">Not yet</span>
                        <?php endif; ?>
                    </td>
                    <td style="color:var(--admin-muted);white-space:nowrap;font-weight:600;">
                        <?php echo (int)$ageDays['months']; ?> m
                    </td>
                    <td style="color:var(--admin-muted);white-space:nowrap;font-weight:600;">
                        <?php echo (int)$ageDays['days']; ?> d
                    </td>
                    <td>
                        <div class="admin-actions" onclick="event.stopPropagation();">
                            <a class="admin-icon-btn admin-icon-btn-primary" title="Record measurement" href="<?php echo $recordUrl; ?>"><?php echo admin_action_icon('measure'); ?></a>
                            <button type="button" class="admin-icon-btn" title="View measurement history" data-view-history="<?php echo (int)$entry['id']; ?>"><?php echo admin_action_icon('view'); ?></button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="admin-pagination">
            <span class="admin-pagination-status">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
            <div class="admin-pagination-actions">
                <a class="admin-icon-btn admin-pagination-prev" title="Previous" href="<?php echo nutritionist_e($pageLink(max(1, $page - 1))); ?>" <?php echo $totalPages <= 1 ? 'style="display:none;"' : ($page <= 1 ? 'style="opacity:.4;pointer-events:none;" aria-disabled="true" tabindex="-1"' : ''); ?>><?php echo $monChevronLeft; ?></a>
                <div class="admin-pagination-numbers"><?php echo $monPageNumbers($page, $totalPages); ?></div>
                <a class="admin-icon-btn admin-pagination-next" title="Next" href="<?php echo nutritionist_e($pageLink(min($totalPages, $page + 1))); ?>" <?php echo $totalPages <= 1 ? 'style="display:none;"' : ($page >= $totalPages ? 'style="opacity:.4;pointer-events:none;" aria-disabled="true" tabindex="-1"' : ''); ?>><?php echo $monChevronRight; ?></a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
#monHistoryModal{display:none;}
#monHistoryModal.is-open{display:flex;}
.mon-hist-sub{font-size:13px;color:var(--admin-muted);margin-top:2px}
.mon-hist-latest{background:var(--admin-primary-soft);border:1px solid var(--admin-border);border-radius:12px;padding:12px 14px;margin-bottom:14px}
.mon-hist-latest .m-title{font-size:12px;font-weight:700;color:var(--admin-text);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px}
.mon-hist-grid{display:flex;gap:16px;flex-wrap:wrap;align-items:center}
.mon-hist-stat{display:flex;flex-direction:column;gap:2px;min-width:80px}
.mon-hist-stat .k{font-size:12px;color:var(--admin-muted);font-weight:600}
.mon-hist-stat .v{font-size:16px;font-weight:700;color:var(--admin-text)}
.mon-hist-pills{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-left:auto}
.mon-hist-table{width:100%;border-collapse:collapse;font-size:13px}
.mon-hist-table th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--admin-muted);text-align:left;padding:8px 6px;border-bottom:2px solid var(--admin-border);white-space:nowrap}
.mon-hist-table td{padding:9px 6px;border-bottom:1px solid var(--admin-border);color:var(--admin-text);white-space:nowrap}
.mon-hist-table tr:last-child td{border-bottom:none}
.mon-hist-empty{padding:20px;text-align:center;color:var(--admin-muted);font-size:14px;font-style:italic}
</style>

<!-- ============ MEASUREMENT HISTORY MODAL (last 5 readings + z-scores) ============ -->
<div class="admin-modal-overlay" id="monHistoryModal">
    <div class="admin-modal" style="max-width:640px;" role="dialog" aria-modal="true" aria-label="Measurement history">
        <div class="admin-modal-head">
            <div style="display:flex;align-items:center;gap:12px;min-width:0;">
                <span class="avatar" id="monHistAvatar" style="width:44px;height:44px;border-radius:50%;background:#94a3b8;color:#fff;font-size:13px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;">--</span>
                <div style="min-width:0;">
                    <h3 id="monHistName" style="margin:0;font-size:16px;">Child name</h3>
                    <div class="mon-hist-sub" id="monHistSub">—</div>
                </div>
            </div>
            <button class="admin-modal-close" id="monHistClose" type="button" aria-label="Close" style="min-width:44px;min-height:44px;font-size:22px;">&times;</button>
        </div>
        <div style="padding:16px 20px;">
            <div class="mon-hist-latest" id="monHistLatest"></div>
            <div style="overflow-x:auto;">
                <table class="mon-hist-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Weight</th>
                            <th>Height</th>
                            <th>WAZ</th>
                            <th>HAZ</th>
                            <th>WHZ</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="monHistBody"></tbody>
                </table>
            </div>
            <div class="admin-actions" style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px;flex-wrap:wrap;">
                <a class="admin-btn-secondary" id="monHistRecord" href="#" style="min-height:44px;display:inline-flex;align-items:center;font-size:14px;">Record measurement</a>
                <button class="admin-btn" type="button" id="monHistClose2" style="min-height:44px;font-size:14px;">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
var monHistory = <?php echo json_encode($monHistoryJson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
(function () {
    var overlay = document.getElementById('monHistoryModal');
    if (!overlay) return;

    function escapeHtml(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Same short codes as the roster table (Normal→N, Tall→T); full axis
    // name stays in the title tooltip for clarity.
    function monShort(code) {
        if (code === 'Normal') return 'N';
        if (code === 'Tall') return 'T';
        return code;
    }

    function openHistory(childId) {
        var c = monHistory[childId];
        if (!c) return;
        var avatar = document.getElementById('monHistAvatar');
        avatar.textContent = c.initials || '--';
        avatar.style.background = c.avatar_bg || '#94a3b8';
        document.getElementById('monHistName').textContent = c.name || 'Child';
        document.getElementById('monHistSub').textContent =
            (c.code || '') + (c.sex ? ' · ' + c.sex : '') +
            (c.barangay ? ' · ' + c.barangay : '') +
            (c.parent ? ' · ' + c.parent : '');

        var body = document.getElementById('monHistBody');
        var latest = document.getElementById('monHistLatest');
        var hist = c.history || [];
        if (hist.length === 0) {
            latest.innerHTML = '<div class="mon-hist-empty" style="padding:6px;">No measurements yet.</div>';
            body.innerHTML = '<tr><td colspan="7"><div class="mon-hist-empty">No recent measurements yet.</div></td></tr>';
        } else {
            var first = hist[0];
            latest.innerHTML =
                '<div class="m-title">Latest reading · ' + escapeHtml(first.date) + '</div>' +
                '<div class="mon-hist-grid">' +
                '<div class="mon-hist-stat"><span class="k">Weight</span><span class="v">' + escapeHtml(first.weight) + '</span></div>' +
                '<div class="mon-hist-stat"><span class="k">Height</span><span class="v">' + escapeHtml(first.height) + '</span></div>' +
                '<div class="mon-hist-pills">' +
                '<span class="admin-pill ' + escapeHtml(first.wfa_class) + '" title="Weight-for-Age: ' + escapeHtml(first.wfa) + '">' + escapeHtml(monShort(first.wfa)) + '</span>' +
                '<span class="admin-pill ' + escapeHtml(first.hfa_class) + '" title="Height-for-Age: ' + escapeHtml(first.hfa) + '">' + escapeHtml(monShort(first.hfa)) + '</span>' +
                '<span class="admin-pill ' + escapeHtml(first.wfh_class) + '" title="Weight-for-Length/Height: ' + escapeHtml(first.wfh) + '">' + escapeHtml(monShort(first.wfh)) + '</span>' +
                '</div></div>';
            body.innerHTML = hist.map(function (h) {
                return '<tr><td>' + escapeHtml(h.date) + '</td>' +
                    '<td>' + escapeHtml(h.weight) + '</td>' +
                    '<td>' + escapeHtml(h.height) + '</td>' +
                    '<td>' + escapeHtml(h.waz) + '</td>' +
                    '<td>' + escapeHtml(h.haz) + '</td>' +
                    '<td>' + escapeHtml(h.whz) + '</td>' +
                    '<td><span class="admin-pill ' + escapeHtml(h.wfa_class) + '" title="Weight-for-Age: ' + escapeHtml(h.wfa) + '">' + escapeHtml(monShort(h.wfa)) + '</span> ' +
                    '<span class="admin-pill ' + escapeHtml(h.hfa_class) + '" title="Height-for-Age: ' + escapeHtml(h.hfa) + '">' + escapeHtml(monShort(h.hfa)) + '</span> ' +
                    '<span class="admin-pill ' + escapeHtml(h.wfh_class) + '" title="Weight-for-Length/Height: ' + escapeHtml(h.wfh) + '">' + escapeHtml(monShort(h.wfh)) + '</span></td></tr>';
            }).join('');
        }

        var recLink = document.getElementById('monHistRecord');
        if (recLink) recLink.setAttribute('href', c.record_url || '#');

        overlay.classList.add('is-open');
        document.body.style.overflow = 'hidden';
    }

    function closeHistory() {
        overlay.classList.remove('is-open');
        document.body.style.overflow = '';
    }

    document.querySelectorAll('[data-view-history]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            openHistory(btn.getAttribute('data-view-history'));
        });
    });

    ['monHistClose', 'monHistClose2'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('click', closeHistory);
    });
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeHistory();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeHistory();
    });

    // Auto-filter: submit ~500ms after typing stops; native search-clear submits at once.
    var form = document.getElementById('mon-filter-form');
    var search = document.getElementById('mon-search');
    if (form && search) {
        var t = null;
        search.addEventListener('input', function () {
            if (t) clearTimeout(t);
            t = setTimeout(function () { form.submit(); }, 500);
        });
        search.addEventListener('search', function () {
            if (t) clearTimeout(t);
            if (search.value === '') form.submit();
        });
    }
})();
</script>

<?php nutritionist_layout_end(); ?>

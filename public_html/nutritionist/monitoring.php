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

$rosterTitle = $view === 'monthly'
    ? 'Monthly Monitoring — ' . $period['label']
    : 'Quarterly Monitoring — ' . $period['label'] . ' ' . $year;

$actions = '<a class="admin-btn-secondary" href="'
    . nutritionist_e(app_url('/nutritionist/appointments.php'))
    . '">' . admin_action_icon('back') . ' Appointments</a>';

nutritionist_layout_start('Monitoring List', '', 'monitoring', $actions);
?>

<style>
.rp-tabs{display:flex;gap:0;border-bottom:2px solid var(--admin-border);margin:0 0 14px}
.rp-tab{display:inline-flex;align-items:center;gap:6px;padding:10px 18px;font-size:13px;font-weight:600;color:var(--admin-muted);text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;transition:color .15s,border-color .15s,background .15s;border-radius:8px 8px 0 0}
.rp-tab:hover{color:var(--admin-text);background:var(--admin-surface-alt)}
.rp-tab.is-active{color:var(--admin-primary);border-bottom-color:var(--admin-primary);background:transparent}
.mon-subtabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.mon-subtab{font-size:14px;font-weight:700;padding:9px 18px;border-radius:999px;border:2px solid var(--admin-border);background:var(--admin-surface);color:var(--admin-text);text-decoration:none;transition:all .15s}
.mon-subtab:hover{border-color:var(--admin-primary);color:var(--admin-primary)}
.mon-subtab.is-active{background:var(--admin-primary);color:#fff;border-color:var(--admin-primary)}
.mon-subtab.is-active span{opacity:.85;}
.mon-card{background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:14px;padding:18px;margin-bottom:18px}
.mon-card-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px}
.mon-card-title{font-size:14px;font-weight:700;color:var(--admin-text);margin:0}
.mon-card-sub{font-size:12px;color:var(--admin-muted);margin-top:2px}
.children-toolbar{display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;align-items:center}
.children-toolbar .admin-search{flex:1;min-width:220px}
.children-toolbar .admin-select{min-width:200px;max-width:260px}
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
@media (max-width: 560px) {
  .children-toolbar{flex-direction:column;align-items:stretch}
  .children-toolbar .admin-search{min-width:0;flex:1}
  .children-toolbar .admin-select{min-width:0;max-width:100%;width:100%}
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
            <a class="mon-subtab <?php echo $quarter === $qNo ? 'is-active' : ''; ?>" href="<?php echo nutritionist_e($subLink($qNo)); ?>">Q<?php echo $qNo; ?> <span style="font-weight:600;"><?php echo implode(' - ', $qMonthNames); ?></span></a>
        <?php endfor; ?>
    <?php endif; ?>
</div>

<!-- ============ FILTER BAR ============ -->
<form method="get" class="children-toolbar">
    <input type="hidden" name="view" value="<?php echo nutritionist_e($view); ?>">
    <?php if ($view === 'monthly'): ?>
        <input type="hidden" name="month" value="<?php echo $month; ?>">
    <?php else: ?>
        <input type="hidden" name="quarter" value="<?php echo $quarter; ?>">
    <?php endif; ?>
    <input
        class="admin-search"
        type="search"
        name="q"
        placeholder="Search by name, code, guardian, or address..."
        value="<?php echo nutritionist_e($search); ?>"
    >
    <select
        class="admin-select"
        name="year"
        onchange="this.form.submit()"
    >
        <?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 4; $y--): ?>
            <option value="<?php echo $y; ?>" <?php echo $year === $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
        <?php endfor; ?>
    </select>
    <div style="display:flex;gap:6px;align-items:center;">
        <button class="admin-btn-secondary" type="submit">Filter</button>
        <?php if ($search !== ''): ?>
            <a class="admin-btn-secondary" href="<?php echo nutritionist_e($view === 'monthly' ? $subLink($month) : $subLink($quarter)); ?>">Clear</a>
        <?php endif; ?>
    </div>
</form>

<!-- ============ ROSTER TABLE ============ -->
<div class="mon-card">
    <div class="mon-card-head">
        <div>
            <h3 class="mon-card-title"><?php echo nutritionist_e($rosterTitle); ?></h3>
        </div>
        <div>
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
    </div>

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
                                <div class="sub" style="margin-top:3px;"><span class="admin-pill <?php echo $measured ? 'is-success' : 'is-warn'; ?>"><?php echo $measured ? 'Measured' : 'Pending'; ?></span></div>
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
                            <a class="admin-icon-btn" title="View child" href="<?php echo nutritionist_e(app_url('/nutritionist/children.php')); ?>"><?php echo admin_action_icon('view'); ?></a>
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

<?php nutritionist_layout_end(); ?>

<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/monitoring_periods.php';

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
$perPage = 10;

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

$countMeasured = 0;
foreach ($roster as $row) {
    if ($row['measured_in_period']) $countMeasured++;
}
$countPending = $totalRows - $countMeasured;

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

$monthNames = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'];

$rosterTitle = $view === 'monthly'
    ? 'Monthly Monitoring — ' . $period['label']
    : 'Quarterly Monitoring — ' . $period['label'] . ' ' . $year;
$rosterSub = $view === 'monthly'
    ? 'All children 0–23 months plus 24–59 months with an abnormal WHO indicator. Any measurement within the month counts.'
    : 'Children 24–59 months classified Normal on all axes. Any measurement within the quarter counts.';

$actions = '<a class="admin-btn-secondary" href="'
    . nutritionist_e(app_url('/nutritionist/appointments.php'))
    . '">' . admin_action_icon('back') . ' Appointments</a>';

nutritionist_layout_start('Monitoring List', 'Period-based weighing rosters — no exact due dates, anytime within the period counts.', 'monitoring', $actions);
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
.mon-filter{display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-bottom:14px}
.mon-filter label{display:flex;flex-direction:column;gap:4px;font-size:12px;color:var(--admin-muted);font-weight:600}
.mon-filter select,.mon-filter input[type="search"]{padding:7px 10px;border-radius:8px;border:1px solid var(--admin-border);background:var(--admin-field-bg);color:var(--admin-text);font-size:13px;font-weight:500}
.mon-table{width:100%;border-collapse:collapse;font-size:13px}
.mon-table th{text-align:left;padding:9px 10px;border-bottom:2px solid var(--admin-border);color:var(--admin-muted);font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.04em}
.mon-table td{padding:8px 10px;border-bottom:1px solid var(--admin-border);vertical-align:middle}
.mon-table tr:hover td{background:var(--admin-surface-alt)}
.mon-pill{display:inline-block;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:700;line-height:1.6}
.mon-pill.measured{background:rgba(22,163,74,.12);color:#16a34a}
.mon-pill.pending{background:rgba(217,119,6,.12);color:#d97706}
.mon-pagination{display:flex;justify-content:space-between;align-items:center;padding:12px 0;font-size:12px;color:var(--admin-muted)}
</style>

<!-- ============ STAT CARDS ============ -->
<section style="margin-bottom:18px;">
    <div class="admin-grid-cards">
        <article class="admin-card">
            <div class="admin-card-row">
                <div class="admin-card-icon"><?php echo admin_action_icon('children'); ?></div>
                <div class="admin-card-content">
                    <div class="admin-card-label">In Roster</div>
                    <div class="admin-card-value"><?php echo $totalRows; ?></div>
                </div>
            </div>
        </article>
        <article class="admin-card">
            <div class="admin-card-row">
                <div class="admin-card-icon is-success"><?php echo admin_action_icon('verify'); ?></div>
                <div class="admin-card-content">
                    <div class="admin-card-label">Measured (<?php echo nutritionist_e($period['label'] . ($view === 'quarterly' ? ' ' . $year : '')); ?>)</div>
                    <div class="admin-card-value"><?php echo $countMeasured; ?></div>
                </div>
            </div>
        </article>
        <article class="admin-card">
            <div class="admin-card-row">
                <div class="admin-card-icon" style="background:rgba(217,119,6,.12);color:#d97706;"><?php echo admin_action_icon('bell'); ?></div>
                <div class="admin-card-content">
                    <div class="admin-card-label">Pending</div>
                    <div class="admin-card-value" style="<?php echo $countPending > 0 ? 'color:#d97706;' : ''; ?>"><?php echo $countPending; ?></div>
                </div>
            </div>
        </article>
    </div>
</section>

<!-- ============ VIEW TABS ============ -->
<div class="rp-tabs">
    <a class="rp-tab <?php echo $view === 'monthly' ? 'is-active' : ''; ?>" href="<?php echo nutritionist_e($viewLink('monthly')); ?>">Monthly <span>(0–23 + 24–59 abnormal)</span></a>
    <a class="rp-tab <?php echo $view === 'quarterly' ? 'is-active' : ''; ?>" href="<?php echo nutritionist_e($viewLink('quarterly')); ?>">Quarterly <span>(24–59 normal)</span></a>
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
<form method="get" class="mon-filter">
    <input type="hidden" name="view" value="<?php echo nutritionist_e($view); ?>">
    <?php if ($view === 'monthly'): ?>
        <input type="hidden" name="month" value="<?php echo $month; ?>">
    <?php else: ?>
        <input type="hidden" name="quarter" value="<?php echo $quarter; ?>">
    <?php endif; ?>
    <label>Year
        <select name="year" onchange="this.form.submit()">
            <?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 4; $y--): ?>
                <option value="<?php echo $y; ?>" <?php echo $year === $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
            <?php endfor; ?>
        </select>
    </label>
    <label>Search
        <input type="search" name="q" placeholder="Name or child code" value="<?php echo nutritionist_e($search); ?>">
    </label>
    <div style="display:flex;gap:6px;align-items:end;">
        <button class="admin-btn-secondary" type="submit">Filter</button>
        <?php if ($search !== ''): ?>
            <a class="admin-btn-secondary" href="<?php echo nutritionist_e($view === 'monthly' ? $subLink($month) : $subLink($quarter)); ?>">Clear</a>
        <?php endif; ?>
    </div>
    <div style="flex:1;"></div>
</form>

<!-- ============ ROSTER TABLE ============ -->
<div class="mon-card">
    <div class="mon-card-head">
        <div>
            <h3 class="mon-card-title"><?php echo nutritionist_e($rosterTitle); ?></h3>
            <p class="mon-card-sub"><?php echo nutritionist_e($rosterSub); ?></p>
        </div>
    </div>

    <?php if (empty($pageRows)): ?>
        <div style="text-align:center;padding:24px;color:var(--admin-muted);">No children in this roster for the selected period.</div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="mon-table">
            <thead>
                <tr>
                    <th style="width:160px;">Child</th>
                    <th style="width:50px;">Age</th>
                    <th style="width:110px;">Barangay</th>
                    <th style="width:120px;">Parent</th>
                    <th style="width:100px;">Last Measured</th>
                    <th style="width:110px;">Category</th>
                    <th style="width:90px;">Period Status</th>
                    <th style="width:90px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pageRows as $entry):
                    $fullName = $entry['first_name'] . ' ' . $entry['last_name'];
                    $lastMeasured = $entry['last_measurement_date'] ? date('M j, Y', strtotime($entry['last_measurement_date'])) : '<span style="color:var(--admin-muted);">Never</span>';
                    $measured = $entry['measured_in_period'];
                    $periodDate = $entry['period_measurement_date'] ? date('M j', strtotime($entry['period_measurement_date'])) : '';
                    $catLabel = monitoring_category_label($entry['category']);
                ?>
                <tr>
                    <td>
                        <strong><?php echo nutritionist_e($fullName); ?></strong>
                        <div style="font-size:10px;color:var(--admin-muted);"><?php echo nutritionist_e($entry['child_code']); ?></div>
                    </td>
                    <td><?php echo $entry['age_months']; ?> mo</td>
                    <td><?php echo nutritionist_e($entry['barangay_name']); ?></td>
                    <td>
                        <?php echo nutritionist_e($entry['parent_name'] !== '' ? $entry['parent_name'] : '—'); ?>
                        <?php if ($entry['parent_phone'] !== ''): ?>
                            <div style="font-size:10px;color:var(--admin-muted);"><?php echo nutritionist_e($entry['parent_phone']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $lastMeasured; ?></td>
                    <td><span class="admin-pill <?php echo nutritionist_status_class($catLabel); ?>"><?php echo nutritionist_e($entry['category'] === 'Normal' ? 'Normal' : $entry['category']); ?></span></td>
                    <td>
                        <?php if ($measured): ?>
                            <span class="mon-pill measured">Measured<?php echo $periodDate !== '' ? ' · ' . $periodDate : ''; ?></span>
                        <?php else: ?>
                            <span class="mon-pill pending">Pending</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="admin-actions" onclick="event.stopPropagation();">
                            <a class="admin-icon-btn admin-icon-btn-primary" title="Record measurement" href="<?php echo nutritionist_e(app_url('/nutritionist/measurement_record.php?child=' . $entry['id'])); ?>"><?php echo admin_action_icon('add'); ?></a>
                            <a class="admin-icon-btn" title="View measurements" href="<?php echo nutritionist_e(app_url('/nutritionist/measurements.php')); ?>"><?php echo admin_action_icon('view'); ?></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="mon-pagination">
        <span>Showing <?php echo ($offset + 1); ?>–<?php echo min($offset + $perPage, $totalRows); ?> of <?php echo $totalRows; ?></span>
        <div style="display:flex;gap:4px;align-items:center;">
            <a class="admin-btn-secondary" href="<?php echo nutritionist_e($pageLink($page - 1)); ?>" <?php echo $page <= 1 ? 'style="pointer-events:none;opacity:.4;"' : ''; ?>>Prev</a>
            <span style="padding:4px 8px;">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
            <a class="admin-btn-secondary" href="<?php echo nutritionist_e($pageLink($page + 1)); ?>" <?php echo $page >= $totalPages ? 'style="pointer-events:none;opacity:.4;"' : ''; ?>>Next</a>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php nutritionist_layout_end(); ?>

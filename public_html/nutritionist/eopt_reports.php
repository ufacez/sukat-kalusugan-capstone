<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/followup_scheduler.php';

$user = nutritionist_require_access();

$view = (string)($_GET['view'] ?? 'monthly');
if (!in_array($view, ['monthly', 'quarterly'], true)) {
	$view = 'monthly';
}

$year = (int)($_GET['year'] ?? date('Y'));
if ($year < 2020 || $year > 2100) {
	$year = (int)date('Y');
}

$currentMonth = (int)date('n');
$month = (int)($_GET['month'] ?? ($currentMonth >= 4 && $currentMonth <= 12 ? $currentMonth : 4));
if ($month < 4 || $month > 12) {
	$month = 4;
}

$defaultCheckupMonth = 7;
foreach (FOLLOWUP_QUARTER_MONTHS as $candidateRound) {
	if ((int)date('n') <= $candidateRound) {
		$defaultCheckupMonth = $candidateRound;
		break;
	}
}
$checkupMonth = (int)($_GET['checkup_month'] ?? $defaultCheckupMonth);
if (!in_array($checkupMonth, FOLLOWUP_QUARTER_MONTHS, true)) {
	$checkupMonth = 7;
}

$barangayFilter = (int)($_GET['barangay_id'] ?? 0);
$scopeParams = [];
$scope = nutritionist_scope_fragment($user, 'c.barangay_id', $scopeParams);

$barangayFilterSql = '';
$barangayFilterParams = [];
if ($barangayFilter > 0) {
	$barangayFilterSql = ' AND c.barangay_id = ?';
	$barangayFilterParams[] = $barangayFilter;
}

$barangays = admin_barangay_options();
$barangayName = 'All barangays within scope';
if ($barangayFilter > 0) {
	$brgyRow = admin_fetch_one('SELECT name FROM barangays WHERE id = ? LIMIT 1', 'i', [$barangayFilter]);
	$barangayName = (string)($brgyRow['name'] ?? '');
}

try {
	$anchorDate = new DateTimeImmutable(sprintf('%04d-%02d-t', $year, $month));
} catch (Exception) {
	$anchorDate = new DateTimeImmutable('today');
}

$monthsList = [4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];
$roundsList = [4 => 'April Round', 7 => 'July Round', 10 => 'October Round'];

$periodLabel = $view === 'monthly'
	? $monthsList[$month] . ' ' . $year
	: $roundsList[$checkupMonth] . ' ' . $year;

$listCodes = [
	'0-23' => [
		'title' => 'MONITORING LIST FOR CHILDREN 0-23 MONTHS OLD',
		'description' => 'All children below 24 months weighed monthly regardless of status.',
		'axis' => 'All children (monthly weighing)',
		'condition' => '1=1',
		'age_min' => 0, 'age_max' => 23,
	],
	'MW' => [
		'title' => 'List_MW — Moderately Wasted (MAM)',
		'description' => 'Children with Weight-for-Height status MW (11.5-12.4).',
		'axis' => 'Weight-for-Height',
		'condition' => "lm.wfh_status = 'MW'",
		'age_min' => 0, 'age_max' => 59,
	],
	'SW' => [
		'title' => 'List_SW — Severely Wasted (SAM)',
		'description' => 'Children with Weight-for-Height status SW (<11.5).',
		'axis' => 'Weight-for-Height',
		'condition' => "lm.wfh_status = 'SW'",
		'age_min' => 0, 'age_max' => 59,
	],
	'MSt_SSt' => [
		'title' => 'List_MSt&SSt — Moderately or Severely Stunted',
		'description' => 'Children with Height-for-Age below -2SD.',
		'axis' => 'Height-for-Age',
		'condition' => "lm.hfa_status IN ('MSt','SSt')",
		'age_min' => 0, 'age_max' => 59,
	],
	'OW_Ob' => [
		'title' => 'List_OW&Ob — Overweight or Obese',
		'description' => 'Children with Weight-for-Age OW or WFH OW/Ob.',
		'axis' => 'Weight-for-Age / Weight-for-Height',
		'condition' => "(lm.wfa_status = 'OW' OR lm.wfh_status IN ('OW','Ob'))",
		'age_min' => 0, 'age_max' => 59,
	],
	'MUW' => [
		'title' => 'List_MUW — Moderately Underweight',
		'description' => 'Children with Weight-for-Age status MUW.',
		'axis' => 'Weight-for-Age',
		'condition' => "lm.wfa_status = 'MUW'",
		'age_min' => 0, 'age_max' => 59,
	],
	'SUW_MSt_SSt' => [
		'title' => 'List_SUW, MSt&SSt — Severely Underweight + Stunted',
		'description' => 'Children with SUW and MSt/SSt simultaneously.',
		'axis' => 'Weight-for-Age + Height-for-Age',
		'condition' => "(lm.wfa_status = 'SUW' AND lm.hfa_status IN ('MSt','SSt'))",
		'age_min' => 0, 'age_max' => 59,
	],
	'MSt_SSt_MW_SW' => [
		'title' => 'List_MSt,SSt,MW&SW — Stunted + Wasted',
		'description' => 'Children with MSt/SSt and MW/SW simultaneously.',
		'axis' => 'Height-for-Age + Weight-for-Height',
		'condition' => "(lm.hfa_status IN ('MSt','SSt') AND lm.wfh_status IN ('MW','SW'))",
		'age_min' => 0, 'age_max' => 59,
	],
	'MSt_SSt_OW_Ob' => [
		'title' => 'List_MSt,SSt,OW&Ob — Stunted + Overweight/Obese',
		'description' => 'Children with MSt/SSt and OW/Ob simultaneously.',
		'axis' => 'Height-for-Age + Weight-for-Height',
		'condition' => "(lm.hfa_status IN ('MSt','SSt') AND (lm.wfa_status = 'OW' OR lm.wfh_status IN ('OW','Ob')))",
		'age_min' => 0, 'age_max' => 59,
	],
];

$listParamRaw = trim((string)($_GET['list'] ?? ''));
$listParamKey = strtolower($listParamRaw);
$listCodeLowerMap = [];
foreach ($listCodes as $code => $spec) {
	$listCodeLowerMap[strtolower($code)] = $code;
}
$isSingleList = $listParamKey !== '' && isset($listCodeLowerMap[$listParamKey]);
$listParam = $isSingleList ? $listCodeLowerMap[$listParamKey] : $listParamRaw;

if ($isSingleList) {
	$spec = $listCodes[$listParam];
	$listAnchorDate = null;
	try {
		$listAnchorDate = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->modify('last day of this month');
	} catch (Exception) {
		$listAnchorDate = new DateTimeImmutable('today');
	}

	$listParamTypes = str_repeat('i', count($scopeParams) + count($barangayFilterParams));
	$listParams = array_merge($scopeParams, $barangayFilterParams, [$listAnchorDate->format('Y-m-d')]);

	$listRows = admin_fetch_all(
		"SELECT
		c.id, c.child_code, c.first_name, c.middle_name, c.last_name,
		c.sex, c.birthdate, c.purok AS address,
		bg.name AS barangay, p.name AS parent_name, p.phone AS parent_phone,
		lm.measurement_date, lm.age_months, lm.age_days, lm.height_cm, lm.weight_kg,
		lm.wfa_status, lm.hfa_status, lm.wfh_status, lm.waz, lm.haz, lm.whz, lm.is_flagged
		FROM children c
		INNER JOIN parents p ON p.id = c.parent_id
		LEFT JOIN barangays bg ON bg.id = c.barangay_id
		INNER JOIN measurements lm ON lm.id = (
			SELECT m2.id FROM measurements m2 WHERE m2.child_id = c.id
			ORDER BY m2.measurement_date DESC, m2.id DESC LIMIT 1
		)
		WHERE {$scope}{$barangayFilterSql}
		  AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN {$spec['age_min']} AND {$spec['age_max']}
		  AND {$spec['condition']}
		ORDER BY c.last_name ASC, c.first_name ASC",
		$listParamTypes . 's',
		$listParams
	);
}

$baseTypes = str_repeat('i', count($scopeParams) + count($barangayFilterParams)) . 's';
$baseParams = array_merge($scopeParams, $barangayFilterParams, [$anchorDate->format('Y-m-d')]);

$infantCount = admin_scalar(
	"SELECT COUNT(DISTINCT c.id) FROM children c
	 INNER JOIN measurements m ON m.child_id = c.id
	 WHERE {$scope}{$barangayFilterSql}
	   AND m.measurement_date <= ?
	   AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 23",
	$baseTypes . 's',
	array_merge($baseParams, [$anchorDate->format('Y-m-d')])
);

$totalAssessed = admin_scalar(
	"SELECT COUNT(DISTINCT c.id) FROM children c
	 INNER JOIN measurements m ON m.child_id = c.id
	 WHERE {$scope}{$barangayFilterSql}
	   AND m.measurement_date <= ?",
	$baseTypes,
	$baseParams
);

$latestJoin = " INNER JOIN measurements lm ON lm.id = (
	SELECT m2.id FROM measurements m2 WHERE m2.child_id = c.id
	ORDER BY m2.measurement_date DESC, m2.id DESC LIMIT 1
)";

$malnourishedCount = admin_scalar(
	"SELECT COUNT(DISTINCT c.id) FROM children c {$latestJoin}
	 WHERE {$scope}{$barangayFilterSql}
	   AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 59
	   AND (lm.wfa_status IN ('SUW','MUW') OR lm.hfa_status IN ('SSt','MSt') OR lm.wfh_status IN ('SW','MW'))",
	$baseTypes,
	$baseParams
);

$affectedCount = admin_scalar(
	"SELECT COUNT(DISTINCT c.id) FROM children c {$latestJoin}
	 WHERE {$scope}{$barangayFilterSql}
	   AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 59
	   AND (lm.wfa_status IN ('SUW','MUW') OR lm.hfa_status IN ('SSt','MSt') OR lm.wfh_status IN ('SW','MW') OR lm.wfa_status = 'OW' OR lm.wfh_status IN ('OW','Ob'))",
	$baseTypes,
	$baseParams
);

$dqIssueCount = 0;
$dqDupCount = count(admin_fetch_all(
	"SELECT 1 FROM children c1 WHERE c1.first_name != '' AND c1.last_name != '' AND c1.birthdate IS NOT NULL
	 GROUP BY c1.first_name, c1.last_name, c1.birthdate HAVING COUNT(*) > 1", '', []
));
$dqMissingSex = admin_scalar("SELECT COUNT(*) FROM children c WHERE c.sex IS NULL AND {$scope}{$barangayFilterSql}",
	str_repeat('i', count($scopeParams) + count($barangayFilterParams)), array_merge($scopeParams, $barangayFilterParams));
$dqMissingDob = admin_scalar("SELECT COUNT(*) FROM children c WHERE c.birthdate IS NULL AND {$scope}{$barangayFilterSql}",
	str_repeat('i', count($scopeParams) + count($barangayFilterParams)), array_merge($scopeParams, $barangayFilterParams));
$dqOverAge = admin_scalar("SELECT COUNT(*) FROM children c WHERE c.birthdate IS NOT NULL AND TIMESTAMPDIFF(YEAR, c.birthdate, CURDATE()) > 4 AND {$scope}{$barangayFilterSql}",
	str_repeat('i', count($scopeParams) + count($barangayFilterParams)), array_merge($scopeParams, $barangayFilterParams));
$dqHeightNoWeight = admin_scalar("SELECT COUNT(DISTINCT c.id) FROM children c INNER JOIN measurements m ON m.child_id = c.id WHERE m.height_cm IS NOT NULL AND m.weight_kg IS NULL AND {$scope}{$barangayFilterSql}",
	str_repeat('i', count($scopeParams) + count($barangayFilterParams)), array_merge($scopeParams, $barangayFilterParams));
$dqWeightNoHeight = admin_scalar("SELECT COUNT(DISTINCT c.id) FROM children c INNER JOIN measurements m ON m.child_id = c.id WHERE m.weight_kg IS NOT NULL AND m.height_cm IS NULL AND {$scope}{$barangayFilterSql}",
	str_repeat('i', count($scopeParams) + count($barangayFilterParams)), array_merge($scopeParams, $barangayFilterParams));

$dqIssueCount = $dqDupCount + $dqMissingSex + $dqMissingDob + $dqOverAge + $dqHeightNoWeight + $dqWeightNoHeight;

$listCountRows = admin_fetch_all(
	"SELECT lm.wfa_status, lm.hfa_status, lm.wfh_status
	 FROM children c {$latestJoin}
	 WHERE {$scope}{$barangayFilterSql}
	   AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 59",
	$baseTypes,
	$baseParams
);

$listCounts = array_fill_keys(array_keys($listCodes), 0);
foreach ($listCountRows as $cr) {
	$wfa = $cr['wfa_status'] ?? null;
	$hfa = $cr['hfa_status'] ?? null;
	$wfh = $cr['wfh_status'] ?? null;

	if ($wfh === 'MW') $listCounts['MW']++;
	if ($wfh === 'SW') $listCounts['SW']++;
	if (in_array($hfa, ['MSt', 'SSt'], true)) $listCounts['MSt_SSt']++;
	if ($wfa === 'OW' || in_array($wfh, ['OW', 'Ob'], true)) $listCounts['OW_Ob']++;
	if ($wfa === 'MUW') $listCounts['MUW']++;
	if ($wfa === 'SUW' && in_array($hfa, ['MSt', 'SSt'], true)) $listCounts['SUW_MSt_SSt']++;
	if (in_array($hfa, ['MSt', 'SSt'], true) && in_array($wfh, ['MW', 'SW'], true)) $listCounts['MSt_SSt_MW_SW']++;
	if (in_array($hfa, ['MSt', 'SSt'], true) && ($wfa === 'OW' || in_array($wfh, ['OW', 'Ob'], true))) $listCounts['MSt_SSt_OW_Ob']++;
}
$listCounts['0-23'] = (int)$infantCount;

$actions = '<a class="admin-btn" href="' . nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=form1a&' . http_build_query(['year' => $year, 'view' => $view, 'month' => $month, 'checkup_month' => $checkupMonth, 'barangay_id' => $barangayFilter]))) . '">' . admin_action_icon('export') . ' Generate Report</a>'
	. '<a class="admin-btn-secondary" href="' . nutritionist_e(app_url('/nutritionist/eopt_reports_export.php?' . http_build_query(['view' => $view, 'year' => $year, 'month' => $month, 'checkup_month' => $checkupMonth, 'barangay_id' => $barangayFilter]))) . '">' . admin_action_icon('export') . ' Export Data</a>';

nutritionist_layout_start('Reports', 'Generate and manage eOPT Plus monitoring, nutrition, analysis, and data-quality reports.', 'eopt_reports', $actions);
?>

<style>
	.rp-filter-bar { display:flex; gap:10px; flex-wrap:wrap; align-items:end; padding:16px 20px; background:var(--admin-surface); border:1px solid var(--admin-border); border-radius:14px; margin-bottom:18px; }
	.rp-filter-bar label { display:flex; flex-direction:column; gap:4px; font-size:12px; color:var(--admin-muted); font-weight:600; }
	.rp-filter-bar select { padding:8px 10px; border-radius:8px; border:1px solid var(--admin-border); background:var(--admin-field-bg); color:var(--admin-text); font-size:13px; font-weight:500; }
	.rp-filter-bar select:focus { border-color:var(--admin-primary); outline:none; }
	.rp-active-filters { font-size:12px; color:var(--admin-primary); font-weight:600; padding:8px 0 0; }
	.rp-section { margin-bottom:24px; }
	.rp-section-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; flex-wrap:wrap; gap:8px; }
	.rp-section-title { font-size:15px; font-weight:700; color:var(--admin-text); letter-spacing:-0.02em; }
	.rp-section-subtitle { font-size:12px; color:var(--admin-muted); margin-top:2px; }
	.rp-stat-grid { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:14px; margin-bottom:24px; }
	.rp-stat-card { background:var(--admin-surface); border:1px solid var(--admin-border); border-radius:14px; padding:18px 20px; transition:all 0.15s ease; }
	.rp-stat-card:hover { border-color:rgba(11,110,79,0.2); box-shadow:0 2px 12px rgba(11,110,79,0.06); }
	.rp-stat-label { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:var(--admin-muted); margin-bottom:6px; }
	.rp-stat-value { font-size:28px; font-weight:800; letter-spacing:-0.03em; color:var(--admin-text); line-height:1; }
	.rp-stat-meta { font-size:11px; color:var(--admin-muted); margin-top:6px; font-weight:500; }
	.rp-form-grid { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:14px; }
	.rp-form-card { background:var(--admin-surface); border:1px solid var(--admin-border); border-radius:16px; padding:22px; transition:all 0.15s ease; position:relative; overflow:hidden; }
	.rp-form-card:hover { border-color:var(--admin-primary); box-shadow:0 4px 16px rgba(11,110,79,0.08); }
	.rp-form-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:var(--admin-primary); }
	.rp-form-name { font-size:15px; font-weight:700; color:var(--admin-text); margin-bottom:4px; }
	.rp-form-desc { font-size:12px; color:var(--admin-muted); line-height:1.5; margin-bottom:12px; }
	.rp-form-meta { display:flex; gap:12px; align-items:center; margin-bottom:14px; flex-wrap:wrap; }
	.rp-form-count { font-size:13px; font-weight:600; color:var(--admin-text); }
	.rp-form-actions { display:flex; gap:8px; flex-wrap:wrap; }
	.rp-form-actions .admin-btn, .rp-form-actions .admin-btn-secondary, .rp-form-actions .admin-icon-btn { font-size:12px; min-height:34px; padding:0 12px; }
	.rp-monitor-grid { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:12px; }
	.rp-monitor-card { background:var(--admin-surface); border:1px solid var(--admin-border); border-radius:12px; padding:16px; transition:all 0.15s ease; }
	.rp-monitor-card:hover { border-color:rgba(11,110,79,0.2); box-shadow:0 2px 10px rgba(11,110,79,0.05); }
	.rp-monitor-name { font-size:13px; font-weight:700; color:var(--admin-text); margin-bottom:3px; }
	.rp-monitor-desc { font-size:11px; color:var(--admin-muted); line-height:1.4; margin-bottom:10px; }
	.rp-monitor-footer { display:flex; justify-content:space-between; align-items:center; }
	.rp-monitor-count { font-size:18px; font-weight:800; color:var(--admin-primary); }
	.rp-monitor-actions { display:flex; gap:6px; }
	.rp-monitor-actions .admin-icon-btn { width:30px; height:30px; min-height:30px; border-radius:8px; }
	.rp-table-section { background:var(--admin-surface); border:1px solid var(--admin-border); border-radius:16px; padding:18px; box-shadow:var(--admin-shadow); margin-bottom:18px; }
	.rp-table-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px; }
	.rp-table-title { font-size:14px; font-weight:700; color:var(--admin-text); }
	.rp-table-subtitle { font-size:12px; color:var(--admin-muted); }
	.rp-breadcrumb { display:flex; gap:8px; align-items:center; font-size:13px; color:var(--admin-muted); margin-bottom:16px; }
	.rp-breadcrumb a { color:var(--admin-text); text-decoration:none; font-weight:600; }
	.rp-breadcrumb a:hover { color:var(--admin-primary); }
	.rp-chart-grid { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:16px; }
	.rp-chart-card { background:var(--admin-surface); border:1px solid var(--admin-border); border-radius:14px; padding:18px; }
	.rp-chart-title { font-size:13px; font-weight:700; color:var(--admin-text); margin-bottom:12px; }
	.rp-dqc-grid { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:10px; }
	.rp-dqc-card { background:var(--admin-surface); border:1px solid var(--admin-border); border-radius:12px; padding:14px; text-align:center; transition:all 0.15s ease; }
	.rp-dqc-card:hover { border-color:rgba(11,110,79,0.2); }
	.rp-dqc-card.is-ok { border-left:3px solid var(--admin-primary); }
	.rp-dqc-card.is-warn { border-left:3px solid #d97706; }
	.rp-dqc-card.is-danger { border-left:3px solid var(--admin-danger); }
	.rp-dqc-count { font-size:22px; font-weight:800; color:var(--admin-text); }
	.rp-dqc-label { font-size:11px; color:var(--admin-muted); font-weight:600; margin-top:4px; }
	.rp-followup-status-row { display:flex; gap:16px; margin-bottom:14px; flex-wrap:wrap; }
	.rp-followup-pill { display:flex; align-items:center; gap:6px; font-size:12px; font-weight:600; padding:6px 12px; border-radius:8px; background:var(--admin-surface-alt); border:1px solid var(--admin-border); }
	.rp-followup-pill .count { font-size:16px; font-weight:800; }
	.rp-export-grid { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:14px; }
	.rp-export-card { background:var(--admin-surface); border:1px solid var(--admin-border); border-radius:14px; padding:20px; }
	@media print {
		.nutritionist-sidebar, .nutritionist-topbar, .rp-filter-bar, .rp-form-actions, .rp-monitor-actions, .rp-chart-card:last-child { display:none !important; }
		.rp-stat-grid, .rp-form-grid, .rp-monitor-grid, .rp-dqc-grid { grid-template-columns:repeat(2, 1fr); }
	}
	@media (max-width:1200px) {
		.rp-stat-grid { grid-template-columns:repeat(2, 1fr); }
		.rp-form-grid { grid-template-columns:1fr; }
		.rp-monitor-grid { grid-template-columns:repeat(2, 1fr); }
		.rp-dqc-grid { grid-template-columns:repeat(2, 1fr); }
	}
	@media (max-width:720px) {
		.rp-stat-grid { grid-template-columns:1fr; }
		.rp-monitor-grid { grid-template-columns:1fr; }
		.rp-dqc-grid { grid-template-columns:1fr; }
		.rp-chart-grid { grid-template-columns:1fr; }
	}
</style>

<?php if ($isSingleList): ?>
<?php
	$spec = $listCodes[$listParam];
	$listExportUrl = app_url('/nutritionist/eopt_reports_export.php') . '?' . http_build_query([
		'list' => $listParam, 'year' => $year, 'barangay_id' => $barangayFilter, 'view' => $view,
	]);
	$listPdfUrl = app_url('/nutritionist/eopt_pdf_generate.php') . '?' . http_build_query([
		'report_type' => 'list', 'list_code' => $listParam, 'year' => $year, 'barangay_id' => $barangayFilter, 'view' => $view,
	]);
?>

<div class="rp-breadcrumb">
	<a href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_reports.php?' . http_build_query(['view' => $view, 'year' => $year, 'barangay_id' => $barangayFilter]))); ?>">&larr; Back to Reports</a>
	<span>/</span>
	<span><?php echo nutritionist_e($spec['title']); ?></span>
</div>

<div class="rp-table-section">
	<div class="rp-table-head">
		<div>
			<div class="rp-table-title"><?php echo nutritionist_e($spec['title']); ?></div>
			<div class="rp-table-subtitle"><?php echo nutritionist_e($spec['axis']); ?> &middot; Age <?php echo $spec['age_min']; ?>-<?php echo $spec['age_max']; ?> months &middot; Year <?php echo $year; ?></div>
		</div>
		<div style="display:flex;gap:8px;">
			<a class="admin-btn-secondary" href="<?php echo nutritionist_e($listPdfUrl); ?>" style="font-size:12px;min-height:34px;">PDF</a>
			<a class="admin-btn" href="<?php echo nutritionist_e($listExportUrl); ?>" style="font-size:12px;min-height:34px;"><?php echo admin_action_icon('export'); ?> Excel</a>
		</div>
	</div>

	<?php if (empty($listRows)): ?>
		<div class="admin-mini" style="padding:24px;text-align:center;color:var(--admin-muted);">No children currently match this monitoring list for the selected filters.</div>
	<?php else: ?>
		<div class="nutritionist-table-wrap" style="overflow-x:auto;">
			<table class="nutritionist-table" style="min-width:900px;">
				<thead>
					<tr>
						<th>No.</th>
						<th>Address</th>
						<th>Mother / Caregiver</th>
						<th>Full Name of Child</th>
						<th>Sex</th>
						<th>Birthdate</th>
						<th>Height (cm)</th>
						<th>Weight (kg)</th>
						<th>WFA</th>
						<th>HFA</th>
						<th>WFH</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($listRows as $i => $row): ?>
						<tr>
							<td><?php echo $i + 1; ?></td>
							<td><?php echo nutritionist_e((string)($row['address'] ?? '')); ?></td>
							<td><?php echo nutritionist_e((string)$row['parent_name']); ?></td>
							<td>
								<div style="font-weight:600;color:var(--admin-text);"><?php echo nutritionist_e(trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? ''))); ?></div>
								<div class="admin-mini"><?php echo nutritionist_e((string)$row['child_code']); ?></div>
							</td>
							<td><?php echo nutritionist_e((string)$row['sex']); ?></td>
							<td><?php echo nutritionist_e((string)$row['birthdate']); ?></td>
							<td><?php echo $row['height_cm'] !== null ? number_format((float)$row['height_cm'], 1) : '—'; ?></td>
							<td><?php echo $row['weight_kg'] !== null ? number_format((float)$row['weight_kg'], 2) : '—'; ?></td>
							<td><span class="admin-pill <?php echo nutritionist_status_class($row['wfa_status'] ?? ''); ?>"><?php echo nutritionist_e((string)($row['wfa_status'] ?? '—')); ?></span></td>
							<td><span class="admin-pill <?php echo nutritionist_status_class($row['hfa_status'] ?? ''); ?>"><?php echo nutritionist_e((string)($row['hfa_status'] ?? '—')); ?></span></td>
							<td><span class="admin-pill <?php echo nutritionist_status_class($row['wfh_status'] ?? ''); ?>"><?php echo nutritionist_e((string)($row['wfh_status'] ?? '—')); ?></span></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<div class="admin-mini" style="margin-top:12px;"><?php echo count($listRows); ?> children in this list</div>
	<?php endif; ?>
</div>

<?php else: ?>

<div class="rp-filter-bar">
	<form method="get" id="rp-filter-form" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;width:100%;">
		<label>
			View
			<select name="view" onchange="this.form.submit()">
				<option value="monthly" <?php echo $view === 'monthly' ? 'selected' : ''; ?>>Monthly Monitoring</option>
				<option value="quarterly" <?php echo $view === 'quarterly' ? 'selected' : ''; ?>>Quarterly Rounds</option>
			</select>
		</label>
		<?php if ($view === 'monthly'): ?>
			<label>
				Month
				<select name="month" onchange="this.form.submit()">
					<?php foreach ($monthsList as $mNo => $mName): ?>
						<option value="<?php echo $mNo; ?>" <?php echo $month === $mNo ? 'selected' : ''; ?>><?php echo nutritionist_e($mName); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		<?php else: ?>
			<label>
				Checkup Round
				<select name="checkup_month" onchange="this.form.submit()">
					<?php foreach ($roundsList as $rNo => $rName): ?>
						<option value="<?php echo $rNo; ?>" <?php echo $checkupMonth === $rNo ? 'selected' : ''; ?>><?php echo nutritionist_e($rName); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		<?php endif; ?>
		<label>
			Year
			<select name="year" onchange="this.form.submit()">
				<?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 4; $y--): ?>
					<option value="<?php echo $y; ?>" <?php echo $year === $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
				<?php endfor; ?>
			</select>
		</label>
		<label>
			Barangay
			<select name="barangay_id" onchange="this.form.submit()">
				<option value="0">All (within your scope)</option>
				<?php foreach ($barangays as $b): ?>
					<option value="<?php echo (int)$b['id']; ?>" <?php echo $barangayFilter === (int)$b['id'] ? 'selected' : ''; ?>><?php echo nutritionist_e($b['name']); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<div style="flex:1;"></div>
		<div class="rp-active-filters">
			Currently: <?php echo nutritionist_e($periodLabel); ?> &middot; <?php echo nutritionist_e($barangayName); ?>
		</div>
	</form>
</div>

<div class="rp-stat-grid">
	<div class="rp-stat-card">
		<div class="rp-stat-label">Total Children Assessed</div>
		<div class="rp-stat-value"><?php echo (int)$totalAssessed; ?></div>
		<div class="rp-stat-meta">0–59 months with at least one measurement</div>
	</div>
	<div class="rp-stat-card">
		<div class="rp-stat-label">Infants &amp; Toddlers (0–23 mo)</div>
		<div class="rp-stat-value" style="color:var(--admin-primary);"><?php echo (int)$infantCount; ?></div>
		<div class="rp-stat-meta">Monthly weighing regardless of status</div>
	</div>
	<div class="rp-stat-card">
		<div class="rp-stat-label">Malnourished Children</div>
		<div class="rp-stat-value" style="color:#b45309;"><?php echo (int)$malnourishedCount; ?></div>
		<div class="rp-stat-meta">Underweight / Stunted / Wasted (0–59 mo)</div>
	</div>
	<div class="rp-stat-card">
		<div class="rp-stat-label">Data Quality Issues</div>
		<div class="rp-stat-value" style="<?php echo $dqIssueCount > 0 ? 'color:var(--admin-danger);' : ''; ?>"><?php echo (int)$dqIssueCount; ?></div>
		<div class="rp-stat-meta"><?php echo $dqIssueCount > 0 ? 'Records requiring review' : 'No issues found'; ?></div>
	</div>
</div>

<section class="rp-section">
	<div class="rp-section-head">
		<div>
			<div class="rp-section-title">EOPT Plus Reports</div>
			<div class="rp-section-subtitle">Core Operation Timbang Plus report forms for the selected reporting period</div>
		</div>
	</div>
	<div class="rp-form-grid">
		<div class="rp-form-card">
			<div class="rp-form-name">OPT Plus Form 1A</div>
			<div class="rp-form-desc">Master list of all measured children aged 0–59 months for the reporting period.</div>
			<div class="rp-form-meta">
				<span class="admin-pill is-success">Ready</span>
				<span class="rp-form-count"><?php echo (int)$totalAssessed; ?> records</span>
			</div>
			<div class="rp-form-actions">
				<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_reports.php?view=' . $view . '&year=' . $year . '&month=' . $month . '&barangay_id=' . $barangayFilter)); ?>">View</a>
				<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=form1a&' . http_build_query(['year' => $year, 'view' => $view, 'month' => $month, 'checkup_month' => $checkupMonth, 'barangay_id' => $barangayFilter]))); ?>">PDF</a>
				<a class="admin-btn" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_reports_export.php?' . http_build_query(['view' => $view, 'year' => $year, 'month' => $month, 'checkup_month' => $checkupMonth, 'barangay_id' => $barangayFilter]))); ?>"><?php echo admin_action_icon('export'); ?> Excel</a>
			</div>
		</div>
		<div class="rp-form-card">
			<div class="rp-form-name">OPT Plus Form 1B</div>
			<div class="rp-form-desc">Consolidated nutritional assessment summary by sex and age group (WFA, HFA, WFH).</div>
			<div class="rp-form-meta">
				<span class="admin-pill is-success">Ready</span>
				<span class="rp-form-count">Summary report</span>
			</div>
			<div class="rp-form-actions">
				<a class="admin-btn-secondary" href="#nutrition-analysis">View</a>
				<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=form1b&' . http_build_query(['year' => $year, 'view' => $view, 'month' => $month, 'checkup_month' => $checkupMonth, 'barangay_id' => $barangayFilter]))); ?>">PDF</a>
				<a class="admin-btn" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_reports_export.php?' . http_build_query(['view' => $view, 'year' => $year, 'month' => $month, 'checkup_month' => $checkupMonth, 'barangay_id' => $barangayFilter]))); ?>"><?php echo admin_action_icon('export'); ?> Excel</a>
			</div>
		</div>
		<div class="rp-form-card">
			<div class="rp-form-name">OPT Plus Form 1C</div>
			<div class="rp-form-desc">List of affected or at-risk children aged 0–59 months (malnourished + overweight/obese).</div>
			<div class="rp-form-meta">
				<span class="admin-pill is-success">Ready</span>
				<span class="rp-form-count"><?php echo (int)$affectedCount; ?> children</span>
			</div>
			<div class="rp-form-actions">
				<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=form1c&' . http_build_query(['year' => $year, 'view' => $view, 'month' => $month, 'checkup_month' => $checkupMonth, 'barangay_id' => $barangayFilter]))); ?>">View / PDF</a>
				<a class="admin-btn" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_reports_export.php?' . http_build_query(['view' => $view, 'year' => $year, 'month' => $month, 'checkup_month' => $checkupMonth, 'barangay_id' => $barangayFilter]))); ?>"><?php echo admin_action_icon('export'); ?> Excel</a>
			</div>
		</div>
	</div>
</section>

<section class="rp-section">
	<div class="rp-section-head">
		<div>
			<div class="rp-section-title">Monitoring Reports</div>
			<div class="rp-section-subtitle">Official eOPT Plus monitoring lists for the selected period. Click to view full roster.</div>
		</div>
	</div>
	<div class="rp-monitor-grid">
		<?php foreach ($listCodes as $code => $spec): ?>
			<?php
				$count = $listCounts[$code] ?? 0;
				$viewLink = app_url('/nutritionist/eopt_reports.php') . '?' . http_build_query([
					'list' => $code, 'view' => $view, 'year' => $year, 'month' => $month, 'checkup_month' => $checkupMonth, 'barangay_id' => $barangayFilter,
				]);
				$pdfLink = app_url('/nutritionist/eopt_pdf_generate.php') . '?' . http_build_query([
					'report_type' => 'list', 'list_code' => $code, 'year' => $year, 'barangay_id' => $barangayFilter, 'view' => $view,
				]);
				$exportLink = app_url('/nutritionist/eopt_reports_export.php') . '?' . http_build_query([
					'list' => $code, 'year' => $year, 'barangay_id' => $barangayFilter, 'view' => $view,
				]);
				$statusClass = $count > 0 ? 'is-success' : 'is-muted';
				$statusLabel = $count > 0 ? 'Ready' : 'No Data';
			?>
			<div class="rp-monitor-card">
				<div class="rp-monitor-name"><?php echo nutritionist_e($spec['title']); ?></div>
				<div class="rp-monitor-desc"><?php echo nutritionist_e($spec['description']); ?></div>
				<div class="rp-monitor-footer">
					<div class="rp-monitor-count"><?php echo $count; ?></div>
					<div style="display:flex;gap:4px;align-items:center;">
						<span class="admin-pill <?php echo $statusClass; ?>" style="font-size:10px;"><?php echo $statusLabel; ?></span>
						<div class="rp-monitor-actions">
							<a class="admin-icon-btn" title="View List" href="<?php echo nutritionist_e($viewLink); ?>"><?php echo admin_action_icon('view'); ?></a>
							<a class="admin-icon-btn" title="PDF" href="<?php echo nutritionist_e($pdfLink); ?>"><?php echo admin_action_icon('print'); ?></a>
							<a class="admin-icon-btn admin-icon-btn-primary" title="Excel" href="<?php echo nutritionist_e($exportLink); ?>"><?php echo admin_action_icon('export'); ?></a>
						</div>
					</div>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
</section>

<section class="rp-section" id="nutrition-analysis">
	<div class="rp-section-head">
		<div>
			<div class="rp-section-title">Nutrition Analysis</div>
			<div class="rp-section-subtitle">Barangay Nutritional Status Summary — sex-aggregated classifications for 0–23 and 0–59 month children</div>
		</div>
		<div style="display:flex;gap:8px;">
			<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=summary&' . http_build_query(['year' => $year, 'view' => $view, 'month' => $month, 'checkup_month' => $checkupMonth, 'barangay_id' => $barangayFilter]))); ?>">Generate PDF</a>
		</div>
	</div>

	<?php
	$summaryRows = admin_fetch_all(
		"SELECT
			c.sex,
			CASE WHEN TIMESTAMPDIFF(MONTH, c.birthdate, LAST_DAY(?)) < 24 THEN '0-23' ELSE '24-59' END AS age_band,
			m.wfa_status, m.hfa_status, m.wfh_status
		 FROM children c
		 INNER JOIN measurements m ON m.id = (
			SELECT m2.id FROM measurements m2 WHERE m2.child_id = c.id
			ORDER BY m2.measurement_date DESC, m2.id DESC LIMIT 1
		 )
		 WHERE {$scope}{$barangayFilterSql}
		   AND TIMESTAMPDIFF(MONTH, c.birthdate, LAST_DAY(?)) BETWEEN 0 AND 59",
		's' . str_repeat('i', count($scopeParams) + count($barangayFilterParams)) . 's',
		array_merge([$anchorDate->format('Y-m-d')], $scopeParams, $barangayFilterParams, [$anchorDate->format('Y-m-d')])
	);

	$bucket = static fn(): array => [
		'Male' => ['0-23' => 0, '24-59' => 0, 'total' => 0],
		'Female' => ['0-23' => 0, '24-59' => 0, 'total' => 0],
		'Total' => ['0-23' => 0, '24-59' => 0, 'total' => 0],
	];

	$wfaS = ['SUW' => $bucket(), 'MUW' => $bucket(), 'Normal' => $bucket(), 'OW' => $bucket()];
	$hfaS = ['SSt' => $bucket(), 'MSt' => $bucket(), 'Normal' => $bucket(), 'Tall' => $bucket()];
	$wfhS = ['SW' => $bucket(), 'MW' => $bucket(), 'Normal' => $bucket(), 'OW' => $bucket(), 'Ob' => $bucket()];

	foreach ($summaryRows as $row) {
		$sexLabel = (string)$row['sex'] === 'Male' ? 'Male' : 'Female';
		$ageBand = (string)$row['age_band'];
		foreach ([['wfa_status', &$wfaS], ['hfa_status', &$hfaS], ['wfh_status', &$wfhS]] as [$field, &$ref]) {
			$val = $row[$field] ?? null;
			if ($val === null || !isset($ref[$val])) continue;
			$ref[$val][$sexLabel][$ageBand]++;
			$ref[$val][$sexLabel]['total']++;
			$ref[$val]['Total'][$ageBand]++;
			$ref[$val]['Total']['total']++;
		}
		unset($ref);
	}

	$renderSummaryTable = function(string $title, array $data) use ($scopeParams, $barangayFilterParams, $barangayFilterSql, $scope): void {
		$totalAll = 0;
		foreach ($data as $d) { $totalAll += (int)$d['Total']['total']; }
	?>
		<div class="rp-table-section" style="margin-bottom:12px;">
			<div class="rp-table-title" style="margin-bottom:10px;"><?php echo nutritionist_e($title); ?></div>
			<div class="nutritionist-table-wrap" style="overflow-x:auto;">
				<table class="nutritionist-table" style="min-width:750px;">
					<thead>
						<tr>
							<th>Status</th>
							<th>Male 0-23</th>
							<th>Male 24-59</th>
							<th>Male Total</th>
							<th>Female 0-23</th>
							<th>Female 24-59</th>
							<th>Female Total</th>
							<th>Grand Total</th>
							<th>%</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($data as $status => $counts): ?>
							<?php $gt = (int)$counts['Total']['total']; ?>
							<tr>
								<td><strong><?php echo nutritionist_e($status); ?></strong></td>
								<td><?php echo (int)$counts['Male']['0-23']; ?></td>
								<td><?php echo (int)$counts['Male']['24-59']; ?></td>
								<td><?php echo (int)$counts['Male']['total']; ?></td>
								<td><?php echo (int)$counts['Female']['0-23']; ?></td>
								<td><?php echo (int)$counts['Female']['24-59']; ?></td>
								<td><?php echo (int)$counts['Female']['total']; ?></td>
								<td><strong><?php echo $gt; ?></strong></td>
								<td><?php echo $totalAll > 0 ? number_format(($gt / $totalAll) * 100, 1) . '%' : '0.0%'; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	<?php
	};

	$renderSummaryTable('WEIGHT-FOR-AGE (WFA)', $wfaS);
	$renderSummaryTable('HEIGHT-FOR-AGE (HFA)', $hfaS);
	$renderSummaryTable('WEIGHT-FOR-LENGTH/HEIGHT (WFH)', $wfhS);
	?>
</section>

<section class="rp-section" id="prevalence-graphs">
	<div class="rp-section-head">
		<div>
			<div class="rp-section-title">Prevalence and Graphs</div>
			<div class="rp-section-subtitle">Community-level prevalence of malnutrition indicators for children 0–59 months</div>
		</div>
		<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=prevalence&' . http_build_query(['year' => $year, 'view' => $view, 'month' => $month, 'checkup_month' => $checkupMonth, 'barangay_id' => $barangayFilter]))); ?>">Generate PDF Report</a>
	</div>

	<?php
	$prevBaseParams = array_merge($scopeParams, $barangayFilterParams, [$anchorDate->format('Y-m-d')]);
	$allPrev = admin_fetch_all(
		"SELECT lm.wfa_status, lm.hfa_status, lm.wfh_status, lm.weight_kg, lm.height_cm
		 FROM children c {$latestJoin}
		 WHERE {$scope}{$barangayFilterSql}
		   AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 59",
		$baseTypes,
		$prevBaseParams
	);

	$prevTotal = count($allPrev);
	$prevCounts = ['wasted' => 0, 'stunted' => 0, 'ow_ob' => 0, 'underweight' => 0, 'uw_or_stunted' => 0, 'stunted_or_owob' => 0];
	$muacC = ['normal' => 0, 'mam' => 0, 'sam' => 0];

	foreach ($allPrev as $c) {
		$wfa = $c['wfa_status'] ?? null;
		$hfa = $c['hfa_status'] ?? null;
		$wfh = $c['wfh_status'] ?? null;
		$w = $c['weight_kg'] !== null ? (float)$c['weight_kg'] : null;
		$h = $c['height_cm'] !== null ? (float)$c['height_cm'] : null;

		if (in_array($wfh, ['MW', 'SW'], true)) $prevCounts['wasted']++;
		if (in_array($hfa, ['MSt', 'SSt'], true)) $prevCounts['stunted']++;
		if ($wfa === 'OW' || in_array($wfh, ['OW', 'Ob'], true)) $prevCounts['ow_ob']++;
		if (in_array($wfa, ['MUW', 'SUW'], true)) $prevCounts['underweight']++;
		if (in_array($wfa, ['MUW', 'SUW'], true) || in_array($hfa, ['MSt', 'SSt'], true)) $prevCounts['uw_or_stunted']++;
		if (in_array($hfa, ['MSt', 'SSt'], true) || ($wfa === 'OW' || in_array($wfh, ['OW', 'Ob'], true))) $prevCounts['stunted_or_owob']++;

		if ($w !== null && $h !== null && $h > 0) {
			$muacEst = ($w / $h) * 10;
			if ($muacEst >= 12.5) $muacC['normal']++;
			elseif ($muacEst >= 11.5) $muacC['mam']++;
			else $muacC['sam']++;
		}
	}
	?>

	<div class="rp-table-section">
		<div class="nutritionist-table-wrap" style="overflow-x:auto;">
			<table class="nutritionist-table" style="min-width:600px;">
				<thead>
					<tr>
						<th>Indicator</th>
						<th style="text-align:right;">Number</th>
						<th style="text-align:right;">Prevalence</th>
					</tr>
				</thead>
				<tbody>
					<?php
					$indicators = [
						['Wasted (MW + SW)', $prevCounts['wasted']],
						['Stunted (MSt + SSt)', $prevCounts['stunted']],
						['Overweight / Obese', $prevCounts['ow_ob']],
						['Underweight (MUW + SUW)', $prevCounts['underweight']],
						['Underweight and/or Stunted', $prevCounts['uw_or_stunted']],
						['Stunted and/or OW/Obese', $prevCounts['stunted_or_owob']],
					];
					foreach ($indicators as [$label, $count]):
						$pctVal = $prevTotal > 0 ? number_format(($count / $prevTotal) * 100, 1) . '%' : '0.0%';
					?>
						<tr>
							<td><?php echo nutritionist_e($label); ?></td>
							<td style="text-align:right;font-weight:600;"><?php echo $count; ?></td>
							<td style="text-align:right;font-weight:600;color:var(--admin-primary);"><?php echo $pctVal; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<div class="rp-chart-grid">
		<div class="rp-chart-card">
			<div class="rp-chart-title">Malnutrition Prevalence</div>
			<canvas id="rp-prevalence-chart" height="220"></canvas>
		</div>
		<div class="rp-chart-card">
			<div class="rp-chart-title">MUAC Status Distribution</div>
			<canvas id="rp-muac-chart" height="220"></canvas>
		</div>
	</div>
</section>

<section class="rp-section" id="data-quality">
	<div class="rp-section-head">
		<div>
			<div class="rp-section-title">Data Quality Check</div>
			<div class="rp-section-subtitle">Summary of data completeness and quality issues across all child records</div>
		</div>
		<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=dqc&' . http_build_query(['year' => $year, 'barangay_id' => $barangayFilter]))); ?>">Export DQC Report</a>
	</div>

	<div class="rp-dqc-grid">
		<?php
		$dqcIssues = [
			['Repeated name and birthdate', $dqDupCount, $dqDupCount > 0 ? 'warn' : 'ok', 'duplicate_name_dob'],
			['Missing sex', (int)$dqMissingSex, (int)$dqMissingSex > 0 ? 'danger' : 'ok', 'missing_sex'],
			['Missing date of birth', (int)$dqMissingDob, (int)$dqMissingDob > 0 ? 'danger' : 'ok', 'missing_birthdate'],
			['No parent or address', 0, 'ok', 'no_parent'],
			['Children older than 59 months', (int)$dqOverAge, (int)$dqOverAge > 0 ? 'warn' : 'ok', 'over_59_months'],
			['Height but no weight', (int)$dqHeightNoWeight, (int)$dqHeightNoWeight > 0 ? 'warn' : 'ok', 'height_no_weight'],
			['Weight but no height', (int)$dqWeightNoHeight, (int)$dqWeightNoHeight > 0 ? 'warn' : 'ok', 'weight_no_height'],
			['No MUAC where applicable', 0, 'ok', 'no_muac'],
		];
		foreach ($dqcIssues as [$label, $count, $severity, $code]):
			$link = $count > 0 ? app_url('/nutritionist/eopt_dqc_records.php?issue=' . $code . '&year=' . $year . '&barangay_id=' . $barangayFilter) : '#';
		?>
			<a href="<?php echo nutritionist_e($link); ?>" class="rp-dqc-card is-<?php echo $severity; ?>" style="text-decoration:none;color:inherit;<?php echo $count === 0 ? 'opacity:0.6;' : ''; ?>">
				<div class="rp-dqc-count" style="color:<?php echo $severity === 'ok' ? 'var(--admin-primary)' : ($severity === 'warn' ? '#d97706' : 'var(--admin-danger)'); ?>;"><?php echo $count; ?></div>
				<div class="rp-dqc-label"><?php echo nutritionist_e($label); ?></div>
			</a>
		<?php endforeach; ?>
	</div>
</section>

<section class="rp-section" id="followup-referral">
	<div class="rp-section-head">
		<div>
			<div class="rp-section-title">Follow-up and Referral</div>
			<div class="rp-section-subtitle">Children requiring follow-up monitoring and referral management</div>
		</div>
	</div>

	<?php
	$followupRows = admin_fetch_all(
		"SELECT
			c.id AS child_id, c.child_code, c.first_name, c.middle_name, c.last_name,
			c.sex, c.birthdate, p.name AS parent_name, bg.name AS barangay,
			lm.measurement_date, lm.weight_kg, lm.height_cm,
			lm.wfa_status, lm.hfa_status, lm.wfh_status, lm.nutritional_status,
			a.id AS appt_id, a.scheduled_at, a.status AS appt_status,
			a.followup_track, a.followup_category,
			TIMESTAMPDIFF(MONTH, c.birthdate, CURDATE()) AS age_months
		 FROM appointments a
		 INNER JOIN children c ON c.id = a.child_id
		 INNER JOIN parents p ON p.id = c.parent_id
		 LEFT JOIN barangays bg ON bg.id = c.barangay_id
		 LEFT JOIN measurements lm ON lm.id = (
			SELECT m2.id FROM measurements m2 WHERE m2.child_id = c.id
			ORDER BY m2.measurement_date DESC, m2.id DESC LIMIT 1
		 )
		 WHERE a.appointment_type = 'followup' AND a.status IN ('pending','scheduled')
		   AND {$scope}{$barangayFilterSql}
		 ORDER BY a.scheduled_at ASC LIMIT 20",
		str_repeat('i', count($scopeParams) + count($barangayFilterParams)),
		array_merge($scopeParams, $barangayFilterParams)
	);

	$followupCounts = ['pending' => 0, 'scheduled' => 0, 'completed' => 0, 'referred' => 0];
	$fcRows = admin_fetch_all(
		"SELECT a.status, COUNT(*) AS cnt FROM appointments a
		 INNER JOIN children c ON c.id = a.child_id
		 WHERE a.appointment_type = 'followup' AND {$scope}{$barangayFilterSql}
		 GROUP BY a.status",
		str_repeat('i', count($scopeParams) + count($barangayFilterParams)),
		array_merge($scopeParams, $barangayFilterParams)
	);
	foreach ($fcRows as $fr) {
		$st = (string)$fr['status'];
		if (isset($followupCounts[$st])) $followupCounts[$st] = (int)$fr['cnt'];
	}
	?>

	<div class="rp-followup-status-row">
		<div class="rp-followup-pill"><span class="count" style="color:#d97706;"><?php echo $followupCounts['pending']; ?></span> Pending</div>
		<div class="rp-followup-pill"><span class="count" style="color:var(--admin-primary);"><?php echo $followupCounts['scheduled']; ?></span> Scheduled</div>
		<div class="rp-followup-pill"><span class="count" style="color:var(--admin-primary);"><?php echo $followupCounts['completed']; ?></span> Completed</div>
		<div class="rp-followup-pill"><span class="count" style="color:var(--admin-danger);"><?php echo $followupCounts['referred']; ?></span> Referred</div>
	</div>

	<?php if (empty($followupRows)): ?>
		<div class="rp-table-section">
			<div class="admin-mini" style="padding:20px;text-align:center;color:var(--admin-muted);">No children currently require follow-up for the selected filters.</div>
		</div>
	<?php else: ?>
		<div class="rp-table-section">
			<div class="nutritionist-table-wrap" style="overflow-x:auto;">
				<table class="nutritionist-table" style="min-width:900px;">
					<thead>
						<tr>
							<th>Child</th>
							<th>Age</th>
							<th>Status</th>
							<th>Last Measurement</th>
							<th>Weight</th>
							<th>Height</th>
							<th>Follow-up</th>
							<th>Scheduled</th>
							<th>Action</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($followupRows as $i => $row): ?>
							<?php
							$abnormal = followup_abnormal_codes($row['wfa_status'] ?? null, $row['hfa_status'] ?? null, $row['wfh_status'] ?? null);
							$catLabel = $abnormal ? followup_category_label(implode('+', $abnormal)) : 'Normal';
							?>
							<tr>
								<td>
									<div style="font-weight:600;"><?php echo nutritionist_e(trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? ''))); ?></div>
									<div class="admin-mini"><?php echo nutritionist_e((string)$row['child_code']); ?></div>
								</td>
								<td><?php echo (int)$row['age_months']; ?> mo</td>
								<td><span class="admin-pill <?php echo nutritionist_status_class($row['wfa_status'] ?? ''); ?>"><?php echo nutritionist_e($catLabel); ?></span></td>
								<td><?php echo nutritionist_e((string)($row['measurement_date'] ?? '—')); ?></td>
								<td><?php echo $row['weight_kg'] !== null ? number_format((float)$row['weight_kg'], 2) . ' kg' : '—'; ?></td>
								<td><?php echo $row['height_cm'] !== null ? number_format((float)$row['height_cm'], 1) . ' cm' : '—'; ?></td>
								<td><span class="admin-pill <?php echo (string)$row['appt_status'] === 'pending' ? 'is-warn' : 'is-info'; ?>"><?php echo nutritionist_e(ucfirst((string)$row['appt_status'])); ?></span></td>
								<td><?php echo nutritionist_e((string)($row['scheduled_at'] ?? '—')); ?></td>
								<td>
									<a class="admin-icon-btn" title="Referral PDF" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=referral&child_id=' . (int)$row['child_id'])); ?>"><?php echo admin_action_icon('print'); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	<?php endif; ?>
</section>

<section class="rp-section" id="export-center">
	<div class="rp-section-head">
		<div>
			<div class="rp-section-title">Export Center</div>
			<div class="rp-section-subtitle">Export complete eOPT Plus data for consolidation and reporting</div>
		</div>
	</div>
	<div class="rp-export-grid">
		<div class="rp-export-card">
			<div style="font-weight:700;font-size:14px;margin-bottom:6px;">EOPT Workbook (.xlsx)</div>
			<div style="font-size:12px;color:var(--admin-muted);margin-bottom:14px;">Full workbook with summary sheet and all 8 monitoring lists in DOH format.</div>
			<a class="admin-btn" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_reports_export.php?' . http_build_query(['view' => $view, 'year' => $year, 'month' => $month, 'checkup_month' => $checkupMonth, 'barangay_id' => $barangayFilter]))); ?>"><?php echo admin_action_icon('export'); ?> Download Excel Workbook</a>
		</div>
		<div class="rp-export-card">
			<div style="font-weight:700;font-size:14px;margin-bottom:6px;">Formal PDF Reports</div>
			<div style="font-size:12px;color:var(--admin-muted);margin-bottom:14px;">Printable official report forms with headers, signatures, and DOH formatting.</div>
			<div style="display:flex;gap:8px;flex-wrap:wrap;">
				<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=form1a&' . http_build_query(['year' => $year, 'view' => $view, 'month' => $month, 'checkup_month' => $checkupMonth, 'barangay_id' => $barangayFilter]))); ?>">Form 1A PDF</a>
				<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=form1b&' . http_build_query(['year' => $year, 'view' => $view, 'month' => $month, 'checkup_month' => $checkupMonth, 'barangay_id' => $barangayFilter]))); ?>">Form 1B PDF</a>
				<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=form1c&' . http_build_query(['year' => $year, 'view' => $view, 'month' => $month, 'checkup_month' => $checkupMonth, 'barangay_id' => $barangayFilter]))); ?>">Form 1C PDF</a>
				<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=summary&' . http_build_query(['year' => $year, 'view' => $view, 'month' => $month, 'checkup_month' => $checkupMonth, 'barangay_id' => $barangayFilter]))); ?>">Summary PDF</a>
				<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=prevalence&' . http_build_query(['year' => $year, 'view' => $view, 'month' => $month, 'checkup_month' => $checkupMonth, 'barangay_id' => $barangayFilter]))); ?>">Prevalence PDF</a>
				<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=dqc&' . http_build_query(['year' => $year, 'barangay_id' => $barangayFilter]))); ?>">DQC PDF</a>
			</div>
		</div>
	</div>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function() {
	const prevData = <?php echo json_encode([
		'indicators' => array_map(fn($item) => ['label' => $item[0], 'count' => $item[1], 'prevalence' => $prevTotal > 0 ? round(($item[1] / $prevTotal) * 100, 1) : 0], $indicators),
		'muac' => [
			['label' => 'Normal', 'count' => $muacC['normal']],
			['label' => 'MAM', 'count' => $muacC['mam']],
			['label' => 'SAM', 'count' => $muacC['sam']],
		],
	], JSON_UNESCAPED_UNICODE); ?>;

	const greenPalette = ['#0b6e4f','#16a34a','#22c55e','#86efac','#bbf7d0'];
	const statusColors = {
		'Wasted (MW + SW)': '#dc2626',
		'Stunted (MSt + SSt)': '#d97706',
		'Overweight / Obese': '#ea580c',
		'Underweight (MUW + SUW)': '#b91c1c',
		'Underweight and/or Stunted': '#92400e',
		'Stunted and/or OW/Obese': '#c2410c',
	};

	const prevCanvas = document.getElementById('rp-prevalence-chart');
	if (prevCanvas) {
		new Chart(prevCanvas, {
			type: 'bar',
			data: {
				labels: prevData.indicators.map(d => d.label),
				datasets: [{
					label: 'Prevalence (%)',
					data: prevData.indicators.map(d => d.prevalence),
					backgroundColor: prevData.indicators.map(d => statusColors[d.label] || '#0b6e4f'),
					borderRadius: 4,
					barThickness: 28,
				}]
			},
			options: {
				indexAxis: 'y',
				responsive: true,
				maintainAspectRatio: false,
				plugins: {
					legend: { display: false },
					tooltip: {
						callbacks: {
							label: function(ctx) { return ctx.parsed.x + '% (' + prevData.indicators[ctx.dataIndex].count + ' children)'; }
						}
					}
				},
				scales: {
					x: {
						beginAtZero: true,
						max: 100,
						ticks: { callback: function(v) { return v + '%'; }, font: { size: 11 } },
						grid: { color: 'rgba(0,0,0,0.05)' }
					},
					y: {
						ticks: { font: { size: 11 } },
						grid: { display: false }
					}
				}
			}
		});
	}

	const muacCanvas = document.getElementById('rp-muac-chart');
	if (muacCanvas) {
		const muacTotal = prevData.muac.reduce((s, d) => s + d.count, 0);
		new Chart(muacCanvas, {
			type: 'doughnut',
			data: {
				labels: prevData.muac.map(d => d.label + ' (' + d.count + ')'),
				datasets: [{
					data: prevData.muac.map(d => d.count),
					backgroundColor: ['#16a34a', '#d97706', '#dc2626'],
					borderWidth: 2,
					borderColor: '#fff',
				}]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				plugins: {
					legend: { position: 'bottom', labels: { font: { size: 11 }, padding: 12 } },
					tooltip: {
						callbacks: {
							label: function(ctx) {
								const pct = muacTotal > 0 ? ((ctx.parsed / muacTotal) * 100).toFixed(1) : 0;
								return ctx.label + ': ' + pct + '%';
							}
						}
					}
				}
			}
		});
	}
})();
</script>

<?php endif; ?>

<?php
nutritionist_layout_end();

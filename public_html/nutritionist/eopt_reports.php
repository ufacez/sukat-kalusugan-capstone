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

$userBarangayId = (int)($user['barangay_id'] ?? 0);
$barangayFilterSql = '';
$barangayFilterParams = [];

$barangayName = 'All barangays within scope';
$assignedBarangay = null;
if ($userBarangayId > 0) {
	$assignedBarangay = admin_fetch_one('SELECT id, name FROM barangays WHERE id = ? LIMIT 1', 'i', [$userBarangayId]);
	$barangayName = (string)($assignedBarangay['name'] ?? '');
}
$barangays = $assignedBarangay !== null ? [$assignedBarangay] : admin_barangay_options();

try {
	$anchorDate = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->modify('last day of this month');
} catch (Exception) {
	$anchorDate = new DateTimeImmutable('today');
}

$monthsList = [4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];
$roundsList = [4 => 'April Round', 7 => 'July Round', 10 => 'October Round'];

$periodLabel = $view === 'monthly'
	? $monthsList[$month] . ' ' . $year
	: $roundsList[$checkupMonth] . ' ' . $year;

$activeTab = (string)($_GET['tab'] ?? 'overview');
$validTabs = ['overview', 'eopt_forms', 'nutrition', 'monitoring', 'dqc'];
if (!in_array($activeTab, $validTabs, true)) {
	$activeTab = 'overview';
}

$filterParams = ['view' => $view, 'year' => $year, 'month' => $month, 'checkup_month' => $checkupMonth, 'barangay_id' => $barangayFilter];

$listCodes = [
	'0-23' => ['title' => 'List_0-23 — Children 0-23 Months', 'desc' => 'All children below 24 months weighed monthly.', 'axis' => 'All children', 'cond' => '1=1', 'age_min' => 0, 'age_max' => 23],
	'MW' => ['title' => 'List_MW — Moderately Wasted (MAM)', 'desc' => 'WFH status MW (11.5-12.4 cm).', 'axis' => 'Weight-for-Height', 'cond' => "lm.wfh_status = 'MW'", 'age_min' => 0, 'age_max' => 59],
	'SW' => ['title' => 'List_SW — Severely Wasted (SAM)', 'desc' => 'WFH status SW (<11.5 cm).', 'axis' => 'Weight-for-Height', 'cond' => "lm.wfh_status = 'SW'", 'age_min' => 0, 'age_max' => 59],
	'MSt_SSt' => ['title' => 'List_MSt&SSt — Stunted', 'desc' => 'HFA below -2SD.', 'axis' => 'Height-for-Age', 'cond' => "lm.hfa_status IN ('MSt','SSt')", 'age_min' => 0, 'age_max' => 59],
	'OW_Ob' => ['title' => 'List_OW&Ob — Overweight/Obese', 'desc' => 'WFA OW or WFH OW/Ob.', 'axis' => 'WFA/WFH', 'cond' => "(lm.wfa_status = 'OW' OR lm.wfh_status IN ('OW','Ob'))", 'age_min' => 0, 'age_max' => 59],
	'MUW' => ['title' => 'List_MUW — Moderately Underweight', 'desc' => 'WFA status MUW.', 'axis' => 'Weight-for-Age', 'cond' => "lm.wfa_status = 'MUW'", 'age_min' => 0, 'age_max' => 59],
	'MUW_SUW_MSt_SSt' => ['title' => 'List_MUW,SUW,MSt&SSt — Underweight+Stunted', 'desc' => 'MUW or SUW with MSt/SSt.', 'axis' => 'WFA + HFA', 'cond' => "(lm.wfa_status IN ('MUW','SUW') AND lm.hfa_status IN ('MSt','SSt'))", 'age_min' => 0, 'age_max' => 59],
	'MSt_SSt_MW_SW' => ['title' => 'List_MSt,SSt,MW&SW — Stunted+Wasted', 'desc' => 'MSt/SSt with MW/SW.', 'axis' => 'HFA + WFH', 'cond' => "(lm.hfa_status IN ('MSt','SSt') AND lm.wfh_status IN ('MW','SW'))", 'age_min' => 0, 'age_max' => 59],
	'MSt_SSt_OW_Ob' => ['title' => 'List_MSt,SSt,OW&Ob — Stunted+OW/Ob', 'desc' => 'MSt/SSt with OW/Ob.', 'axis' => 'HFA + WFH', 'cond' => "(lm.hfa_status IN ('MSt','SSt') AND (lm.wfa_status = 'OW' OR lm.wfh_status IN ('OW','Ob')))", 'age_min' => 0, 'age_max' => 59],
];

$listRows = [];
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
	$listRows = [];
	$listAnchor = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->modify('last day of this month');
	$listParams = array_merge($scopeParams, [$listAnchor->format('Y-m-d')]);
	$listRows = admin_fetch_all(
		"SELECT c.id, c.child_code, c.first_name, c.middle_name, c.last_name,
			c.sex, c.birthdate, la.area_name AS address, p.name AS parent_name,
			lm.measurement_date, lm.age_months, lm.height_cm, lm.weight_kg,
			lm.wfa_status, lm.hfa_status, lm.wfh_status
		 FROM children c
		 INNER JOIN parents p ON p.id = c.parent_id
		 LEFT JOIN local_areas la ON la.id = c.local_area_id
		 LEFT JOIN measurements lm ON lm.id = (
			SELECT m2.id FROM measurements m2 WHERE m2.child_id = c.id
			ORDER BY m2.measurement_date DESC, m2.id DESC LIMIT 1
		 )
		 WHERE {$scope}
		   AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN {$spec['age_min']} AND {$spec['age_max']}
		   AND {$spec['cond']}
		 ORDER BY c.last_name, c.first_name",
		str_repeat('i', count($scopeParams)) . 's',
		$listParams
	);
}

$latestJoin = " INNER JOIN measurements lm ON lm.id = (
	SELECT m2.id FROM measurements m2 WHERE m2.child_id = c.id
	ORDER BY m2.measurement_date DESC, m2.id DESC LIMIT 1
)";

$baseTypes = str_repeat('i', count($scopeParams)) . 's';
$baseParams = array_merge($scopeParams, [$anchorDate->format('Y-m-d')]);

$totalAssessed = admin_scalar(
	"SELECT COUNT(DISTINCT c.id) FROM children c INNER JOIN measurements m ON m.child_id = c.id
	 WHERE {$scope} AND m.measurement_date <= ?",
	$baseTypes, $baseParams
);

$infantCount = admin_scalar(
	"SELECT COUNT(DISTINCT c.id) FROM children c INNER JOIN measurements m ON m.child_id = c.id
	 WHERE {$scope} AND m.measurement_date <= ?
	   AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 23",
	$baseTypes . 's', array_merge($baseParams, [$anchorDate->format('Y-m-d')])
);

$malnourishedCount = admin_scalar(
	"SELECT COUNT(DISTINCT c.id) FROM children c {$latestJoin}
	 WHERE {$scope}
	   AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 59
	   AND (lm.wfa_status IN ('SUW','MUW') OR lm.hfa_status IN ('SSt','MSt') OR lm.wfh_status IN ('SW','MW'))",
	$baseTypes, $baseParams
);

$affectedCount = admin_scalar(
	"SELECT COUNT(DISTINCT c.id) FROM children c {$latestJoin}
	 WHERE {$scope}
	   AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 59
	   AND (lm.wfa_status IN ('SUW','MUW') OR lm.hfa_status IN ('SSt','MSt') OR lm.wfh_status IN ('SW','MW') OR lm.wfa_status = 'OW' OR lm.wfh_status IN ('OW','Ob'))",
	$baseTypes, $baseParams
);

$dqDupCount = count(admin_fetch_all(
	"SELECT 1 FROM children c1 WHERE c1.first_name != '' AND c1.last_name != '' AND c1.birthdate IS NOT NULL
	 GROUP BY c1.first_name, c1.last_name, c1.birthdate HAVING COUNT(*) > 1", '', []
));
$baseIntParams = array_merge($scopeParams, $barangayFilterParams);
$baseIntTypes = str_repeat('i', count($baseIntParams));
$dqMissingSex = admin_scalar("SELECT COUNT(*) FROM children c WHERE c.sex IS NULL AND {$scope}", $baseIntTypes, $baseIntParams);
$dqMissingDob = admin_scalar("SELECT COUNT(*) FROM children c WHERE c.birthdate IS NULL AND {$scope}", $baseIntTypes, $baseIntParams);
$dqMissingInformation = admin_scalar("SELECT COUNT(*) FROM children c WHERE (COALESCE(c.first_name, '') = '' OR COALESCE(c.last_name, '') = '' OR c.birthdate IS NULL) AND {$scope}", $baseIntTypes, $baseIntParams);
$dqNoParentAddress = admin_scalar("SELECT COUNT(*) FROM children c LEFT JOIN parents p ON p.id = c.parent_id WHERE (p.id IS NULL OR COALESCE(p.name, '') = '' OR (c.local_area_id IS NULL AND COALESCE(p.address, '') = '')) AND {$scope}", $baseIntTypes, $baseIntParams);
$dqOverAge = admin_scalar("SELECT COUNT(*) FROM children c WHERE c.birthdate IS NOT NULL AND TIMESTAMPDIFF(YEAR, c.birthdate, CURDATE()) > 4 AND {$scope}", $baseIntTypes, $baseIntParams);
$dqHeightNoWeight = admin_scalar("SELECT COUNT(DISTINCT c.id) FROM children c INNER JOIN measurements m ON m.child_id = c.id WHERE m.height_cm IS NOT NULL AND m.weight_kg IS NULL AND {$scope}", $baseIntTypes, $baseIntParams);
$dqWeightNoHeight = admin_scalar("SELECT COUNT(DISTINCT c.id) FROM children c INNER JOIN measurements m ON m.child_id = c.id WHERE m.weight_kg IS NOT NULL AND m.height_cm IS NULL AND {$scope}", $baseIntTypes, $baseIntParams);
$dqIssueCount = $dqDupCount + $dqMissingInformation + $dqNoParentAddress + $dqMissingSex + $dqMissingDob + $dqOverAge + $dqHeightNoWeight + $dqWeightNoHeight;

$listCountRows = admin_fetch_all(
	"SELECT lm.wfa_status, lm.hfa_status, lm.wfh_status FROM children c {$latestJoin}
	 WHERE {$scope} AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 59",
	$baseTypes, $baseParams
);
$listCounts = array_fill_keys(array_keys($listCodes), 0);
foreach ($listCountRows as $cr) {
	$wfa = $cr['wfa_status'] ?? null; $hfa = $cr['hfa_status'] ?? null; $wfh = $cr['wfh_status'] ?? null;
	if ($wfh === 'MW') $listCounts['MW']++;
	if ($wfh === 'SW') $listCounts['SW']++;
	if (in_array($hfa, ['MSt', 'SSt'], true)) $listCounts['MSt_SSt']++;
	if ($wfa === 'OW' || in_array($wfh, ['OW', 'Ob'], true)) $listCounts['OW_Ob']++;
	if ($wfa === 'MUW') $listCounts['MUW']++;
	if (in_array($wfa, ['MUW', 'SUW'], true) && in_array($hfa, ['MSt', 'SSt'], true)) $listCounts['MUW_SUW_MSt_SSt']++;
	if (in_array($hfa, ['MSt', 'SSt'], true) && in_array($wfh, ['MW', 'SW'], true)) $listCounts['MSt_SSt_MW_SW']++;
	if (in_array($hfa, ['MSt', 'SSt'], true) && ($wfa === 'OW' || in_array($wfh, ['OW', 'Ob'], true))) $listCounts['MSt_SSt_OW_Ob']++;
}
$listCounts['0-23'] = (int)$infantCount;

$actions = '<details class="rp-export-menu"><summary class="admin-btn">' . admin_action_icon('export') . ' Export Data</summary>'
	. '<div class="rp-export-popover">'
	. '<a href="' . nutritionist_e(app_url('/nutritionist/eopt_reports_export.php?' . http_build_query($filterParams))) . '">Export Excel</a>'
	. '<a href="' . nutritionist_e(app_url('/nutritionist/eopt_reports_export.php?' . http_build_query(array_merge($filterParams, ['format' => 'csv']))) ) . '">Export CSV</a>'
	. '<a href="' . nutritionist_e(app_url('/nutritionist/eopt_reports_export.php?' . http_build_query(array_merge($filterParams, ['consolidation' => 1]))) ) . '">Export for Consolidation</a>'
	. '</div></details>';

nutritionist_layout_start('Reports', 'Generate and manage eOPT Plus monitoring, nutrition, analysis, and data-quality reports.', 'eopt_reports', $actions);
?>

<style>
.rp-filter-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:end;padding:14px 18px;background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:14px;margin-bottom:16px}
.rp-filter-bar label{display:flex;flex-direction:column;gap:4px;font-size:12px;color:var(--admin-muted);font-weight:600}
.rp-filter-bar select{padding:7px 10px;border-radius:8px;border:1px solid var(--admin-border);background:var(--admin-field-bg);color:var(--admin-text);font-size:13px;font-weight:500}
.rp-filter-bar select:focus{border-color:var(--admin-primary);outline:none}
.rp-active{font-size:12px;color:var(--admin-primary);font-weight:600;padding:8px 0 0}
.rp-tabs{display:flex;gap:6px;margin-bottom:18px;flex-wrap:wrap}
.rp-tab{font-size:12px;font-weight:600;padding:7px 14px;border-radius:999px;border:1px solid var(--admin-border);background:var(--admin-surface);color:var(--admin-muted);cursor:pointer;transition:all 0.2s cubic-bezier(0.4,0,0.2,1);white-space:nowrap;text-decoration:none}
.rp-tab:hover{border-color:rgba(11,110,79,0.35);color:var(--admin-primary);transform:translateY(-1px);box-shadow:0 2px 8px rgba(11,110,79,0.12)}
.rp-tab.is-active{background:var(--admin-primary-soft);color:var(--admin-primary);border-color:rgba(11,110,79,0.35);box-shadow:0 2px 10px rgba(11,110,79,0.18)}
.rp-export-menu{position:relative;margin-left:8px}.rp-export-menu summary{list-style:none;cursor:pointer}.rp-export-menu summary::-webkit-details-marker{display:none}.rp-export-popover{position:absolute;right:0;top:calc(100% + 6px);z-index:5;min-width:190px;padding:6px;background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:8px;box-shadow:var(--admin-shadow)}.rp-export-popover a{display:block;padding:8px 10px;color:var(--admin-text);font-size:12px;text-decoration:none;border-radius:5px}.rp-export-popover a:hover{background:var(--admin-surface-alt);color:var(--admin-primary)}
.rp-panel{display:none}
.rp-panel.is-active{display:block}
.rp-stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:20px}
.rp-stat-card{background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:10px;padding:12px 14px;transition:all 0.15s ease;min-width:0}
.rp-stat-card:hover{border-color:rgba(11,110,79,0.2);box-shadow:0 2px 12px rgba(11,110,79,0.06)}
.rp-stat-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;color:var(--admin-muted);margin-bottom:5px}
.rp-stat-value{font-size:24px;font-weight:800;letter-spacing:-0.03em;color:var(--admin-text);line-height:1}
.rp-stat-meta{font-size:11px;color:var(--admin-muted);margin-top:5px;font-weight:500}
.rp-form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
.rp-form-card{background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:14px;padding:20px;position:relative;overflow:hidden;transition:all 0.15s ease}
.rp-form-card:hover{border-color:var(--admin-primary);box-shadow:0 4px 16px rgba(11,110,79,0.08)}
.rp-form-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--admin-primary)}
.rp-form-name{font-size:14px;font-weight:700;color:var(--admin-text);margin-bottom:4px}
.rp-form-desc{font-size:12px;color:var(--admin-muted);line-height:1.5;margin-bottom:10px}
.rp-form-meta{display:flex;gap:10px;align-items:center;margin-bottom:12px;flex-wrap:wrap}
.rp-form-count{font-size:13px;font-weight:600;color:var(--admin-text)}
.rp-form-actions{display:flex;gap:8px;flex-wrap:wrap}
.rp-form-actions .admin-btn,.rp-form-actions .admin-btn-secondary{font-size:12px;min-height:32px;padding:0 12px}
.rp-monitor-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.rp-monitor-card{background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:12px;padding:14px;transition:all 0.15s ease}
.rp-monitor-card:hover{border-color:rgba(11,110,79,0.2);box-shadow:0 2px 10px rgba(11,110,79,0.05)}
.rp-monitor-name{font-size:12px;font-weight:700;color:var(--admin-text);margin-bottom:3px}
.rp-monitor-desc{font-size:11px;color:var(--admin-muted);line-height:1.4;margin-bottom:8px}
.rp-monitor-footer{display:flex;justify-content:space-between;align-items:center}
.rp-monitor-count{font-size:17px;font-weight:800;color:var(--admin-primary)}
.rp-monitor-actions{display:flex;gap:4px}
.rp-monitor-actions .admin-icon-btn{width:28px;height:28px;min-height:28px;border-radius:7px;font-size:11px}
.rp-section{margin-bottom:20px}
.rp-section-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px}
.rp-shortcut{font-size:12px;color:var(--admin-primary);font-weight:700;text-decoration:none}.rp-shortcut:hover{text-decoration:underline}
.rp-section-title{font-size:15px;font-weight:700;color:var(--admin-text);letter-spacing:-0.02em}
.rp-section-sub{font-size:12px;color:var(--admin-muted);margin-top:2px}
.rp-table-section{background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:14px;padding:16px;box-shadow:var(--admin-shadow);margin-bottom:14px;overflow:hidden}
.rp-table-title{font-size:13px;font-weight:700;color:var(--admin-text);margin-bottom:10px}
.rp-breadcrumb{display:flex;gap:8px;align-items:center;font-size:13px;color:var(--admin-muted);margin-bottom:14px}
.rp-breadcrumb a{color:var(--admin-text);text-decoration:none;font-weight:600}
.rp-breadcrumb a:hover{color:var(--admin-primary)}
.rp-chart-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.rp-chart-card{background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:14px;padding:16px;position:relative;min-height:320px}
.rp-chart-title{font-size:13px;font-weight:700;color:var(--admin-text);margin-bottom:10px}
.rp-dqc-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.rp-dqc-card{background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:12px;padding:14px;text-align:center;text-decoration:none;color:inherit;transition:all 0.15s ease}
.rp-dqc-card:hover{border-color:rgba(11,110,79,0.2)}
.rp-dqc-card.is-ok{border-left:3px solid var(--admin-primary)}
.rp-dqc-card.is-warn{border-left:3px solid #d97706}
.rp-dqc-card.is-danger{border-left:3px solid var(--admin-danger)}
.rp-dqc-count{font-size:20px;font-weight:800;color:var(--admin-text)}
.rp-dqc-label{font-size:11px;color:var(--admin-muted);font-weight:600;margin-top:4px}
.rp-followup-row{display:flex;gap:14px;margin-bottom:14px;flex-wrap:wrap}
.rp-followup-pill{display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;padding:6px 12px;border-radius:8px;background:var(--admin-surface-alt);border:1px solid var(--admin-border)}
.rp-followup-pill .count{font-size:16px;font-weight:800}
.rp-export-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.rp-export-card{background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:14px;padding:18px}
@media(max-width:1200px){.rp-form-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.rp-monitor-grid{grid-template-columns:repeat(2,1fr)}.rp-dqc-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:720px){.rp-stat-grid{grid-template-columns:1fr}.rp-monitor-grid{grid-template-columns:1fr}.rp-dqc-grid{grid-template-columns:1fr}.rp-chart-grid{grid-template-columns:1fr}}
@media print{.nutritionist-sidebar,.nutritionist-topbar,.rp-filter-bar,.rp-tabs,.rp-form-actions,.rp-monitor-actions{display:none!important}.rp-panel{display:block!important}}
</style>

<?php if ($isSingleList): ?>
<?php
	$spec = $listCodes[$listParam];
	$listExportUrl = app_url('/nutritionist/eopt_reports_export.php') . '?' . http_build_query(array_merge($filterParams, ['list' => $listParam]));
	$listPdfUrl = app_url('/nutritionist/eopt_pdf_generate.php') . '?' . http_build_query(array_merge($filterParams, ['report_type' => 'list', 'list_code' => $listParam]));
?>
<div class="rp-breadcrumb">
	<a href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_reports.php?' . http_build_query($filterParams))); ?>">&larr; Back to Reports</a>
	<span>/</span>
	<span><?php echo nutritionist_e($spec['title']); ?></span>
</div>
<div class="rp-table-section">
	<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
		<div>
			<div class="rp-table-title"><?php echo nutritionist_e($spec['title']); ?></div>
			<div style="font-size:12px;color:var(--admin-muted);"><?php echo nutritionist_e($spec['axis']); ?> &middot; Age <?php echo $spec['age_min']; ?>-<?php echo $spec['age_max']; ?> mo &middot; Year <?php echo $year; ?> &middot; <?php echo count($listRows); ?> children</div>
		</div>
		<div style="display:flex;gap:8px;">
			<a class="admin-btn-secondary" href="<?php echo nutritionist_e($listPdfUrl); ?>" style="font-size:12px;min-height:32px;">PDF</a>
			<a class="admin-btn" href="<?php echo nutritionist_e($listExportUrl); ?>" style="font-size:12px;min-height:32px;"><?php echo admin_action_icon('export'); ?> Excel</a>
		</div>
	</div>
	<?php if (empty($listRows)): ?>
		<div style="padding:24px;text-align:center;color:var(--admin-muted);font-size:13px;">No children match this monitoring list for the selected filters.</div>
	<?php else: ?>
		<div class="nutritionist-table-wrap" style="overflow-x:auto;">
			<table class="nutritionist-table" style="min-width:850px;">
				<thead><tr><th>No.</th><th>Address</th><th>Mother/Caregiver</th><th>Child Name</th><th>Sex</th><th>Birthdate</th><th>Height</th><th>Weight</th><th>WFA</th><th>HFA</th><th>WFH</th></tr></thead>
				<tbody>
					<?php foreach ($listRows as $i => $row): ?>
						<tr>
							<td><?php echo $i + 1; ?></td>
							<td><?php echo nutritionist_e((string)($row['address'] ?? '')); ?></td>
							<td><?php echo nutritionist_e((string)$row['parent_name']); ?></td>
							<td><div style="font-weight:600;"><?php echo nutritionist_e(trim(($row['last_name']??'').', '.($row['first_name']??'').' '.($row['middle_name']??''))); ?></div><div style="font-size:11px;color:var(--admin-muted);"><?php echo nutritionist_e((string)$row['child_code']); ?></div></td>
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
	<?php endif; ?>
</div>

<?php else: ?>

<div class="rp-filter-bar">
	<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;width:100%;">
		<input type="hidden" name="tab" value="<?php echo nutritionist_e($activeTab); ?>">
		<label>View<select name="view" onchange="this.form.submit()">
			<option value="monthly" <?php echo $view === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
			<option value="quarterly" <?php echo $view === 'quarterly' ? 'selected' : ''; ?>>Quarterly</option>
		</select></label>
		<?php if ($view === 'monthly'): ?>
			<label>Month<select name="month" onchange="this.form.submit()">
				<?php foreach ($monthsList as $mNo => $mName): ?>
					<option value="<?php echo $mNo; ?>" <?php echo $month === $mNo ? 'selected' : ''; ?>><?php echo nutritionist_e($mName); ?></option>
				<?php endforeach; ?>
			</select></label>
		<?php else: ?>
			<label>Round<select name="checkup_month" onchange="this.form.submit()">
				<?php foreach ($roundsList as $rNo => $rName): ?>
					<option value="<?php echo $rNo; ?>" <?php echo $checkupMonth === $rNo ? 'selected' : ''; ?>><?php echo nutritionist_e($rName); ?></option>
				<?php endforeach; ?>
			</select></label>
		<?php endif; ?>
		<label>Year<select name="year" onchange="this.form.submit()">
			<?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 4; $y--): ?>
				<option value="<?php echo $y; ?>" <?php echo $year === $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
			<?php endfor; ?>
		</select></label>
		<label>Barangay<select name="barangay_id" onchange="this.form.submit()">
			<?php foreach ($barangays as $b): ?>
				<option value="<?php echo (int)$b['id']; ?>" <?php echo $barangayFilter === (int)$b['id'] ? 'selected' : ''; ?>><?php echo nutritionist_e($b['name']); ?></option>
			<?php endforeach; ?>
		</select></label>
		<div style="flex:1;"></div>
		<div class="rp-active"><?php echo nutritionist_e($periodLabel); ?> &middot; <?php echo nutritionist_e($barangayName); ?></div>
	</form>
</div>

<?php
$tabUrl = function(string $tab) use ($filterParams): string {
	return app_url('/nutritionist/eopt_reports.php') . '?' . http_build_query(array_merge($filterParams, ['tab' => $tab]));
};
?>
<div class="rp-tabs" id="rpTabs">
	<a class="rp-tab <?php echo $activeTab === 'overview' ? 'is-active' : ''; ?>" data-tab="overview" href="<?php echo nutritionist_e($tabUrl('overview')); ?>">Overview</a>
	<a class="rp-tab <?php echo $activeTab === 'eopt_forms' ? 'is-active' : ''; ?>" data-tab="eopt_forms" href="<?php echo nutritionist_e($tabUrl('eopt_forms')); ?>">EOPT Forms</a>
	<a class="rp-tab <?php echo $activeTab === 'nutrition' ? 'is-active' : ''; ?>" data-tab="nutrition" href="<?php echo nutritionist_e($tabUrl('nutrition')); ?>">Nutrition Status</a>
	<a class="rp-tab <?php echo $activeTab === 'monitoring' ? 'is-active' : ''; ?>" data-tab="monitoring" href="<?php echo nutritionist_e($tabUrl('monitoring')); ?>">Monitoring Lists</a>
	<a class="rp-tab <?php echo $activeTab === 'dqc' ? 'is-active' : ''; ?>" data-tab="dqc" href="<?php echo nutritionist_e($tabUrl('dqc')); ?>">Data Quality</a>
</div>

<?php if ($activeTab === 'overview'): ?>
<div class="rp-panel is-active" data-panel="overview">
	<div class="rp-stat-grid">
		<div class="rp-stat-card"><div class="rp-stat-label">Total Assessed</div><div class="rp-stat-value"><?php echo (int)$totalAssessed; ?></div><div class="rp-stat-meta">0–59 mo with measurement</div></div>
		<div class="rp-stat-card"><div class="rp-stat-label">0–23 Months</div><div class="rp-stat-value" style="color:var(--admin-primary);"><?php echo (int)$infantCount; ?></div><div class="rp-stat-meta">Children assessed</div></div>
		<div class="rp-stat-card"><div class="rp-stat-label">Affected Children</div><div class="rp-stat-value" style="color:#b45309;"><?php echo (int)$affectedCount; ?></div><div class="rp-stat-meta">Wasted, stunted, underweight, or overweight</div></div>
		<div class="rp-stat-card"><div class="rp-stat-label">Data Quality Issues</div><div class="rp-stat-value" style="<?php echo $dqIssueCount > 0 ? 'color:var(--admin-danger);' : ''; ?>"><?php echo (int)$dqIssueCount; ?></div><div class="rp-stat-meta"><?php echo $dqIssueCount > 0 ? 'Needs review' : 'No issues found'; ?></div></div>
	</div>
	<div class="rp-section">
		<div class="rp-section-head"><div><div class="rp-section-title">Report Categories</div><div class="rp-section-sub">Open a report category for the selected period</div></div></div>
		<div class="rp-form-grid">
			<div class="rp-form-card">
				<div class="rp-form-name">EOPT Forms</div><div class="rp-form-desc">OPT Plus Form 1A, 1B, and 1C.</div><div class="rp-form-actions"><a class="admin-btn" href="<?php echo nutritionist_e($tabUrl('eopt_forms')); ?>">Open Forms</a></div>
			</div>
			<div class="rp-form-card">
				<div class="rp-form-name">Nutrition Status</div><div class="rp-form-desc">NutStatusTool roster and NutStatusBrgy summary.</div><div class="rp-form-actions"><a class="admin-btn" href="<?php echo nutritionist_e($tabUrl('nutrition')); ?>">Open Nutrition Status</a></div>
			</div>
			<div class="rp-form-card">
				<div class="rp-form-name">Monitoring Lists</div><div class="rp-form-desc">Prepared child lists and risk-specific monitoring rosters.</div><div class="rp-form-actions"><a class="admin-btn" href="<?php echo nutritionist_e($tabUrl('monitoring')); ?>">Open Lists</a></div>
			</div>
		</div>
	</div>
</div>

<?php elseif ($activeTab === 'eopt_forms'): ?>
<div class="rp-panel is-active" data-panel="eopt_forms">
	<div class="rp-section"><div class="rp-section-head"><div><div class="rp-section-title">EOPT Forms</div><div class="rp-section-sub">OPT Plus forms generated from the selected reporting period and barangay.</div></div></div><div class="rp-form-grid">
		<div class="rp-form-card"><div class="rp-form-name">OPT Plus Form 1A</div><div class="rp-form-desc">Master list of preschool children aged 0–59 months.</div><div class="rp-form-meta"><span class="admin-pill is-success">Ready</span><span class="rp-form-count"><?php echo (int)$totalAssessed; ?> children</span></div><div class="rp-form-actions"><a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=form1a&' . http_build_query($filterParams))); ?>">PDF</a><a class="admin-btn" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_reports_export.php?' . http_build_query(array_merge($filterParams, ['report' => 'form1a'])))); ?>">Excel</a></div></div>
		<div class="rp-form-card"><div class="rp-form-name">OPT Plus Form 1B</div><div class="rp-form-desc">Consolidated nutrition assessment results.</div><div class="rp-form-meta"><span class="admin-pill is-success">Ready</span><span class="rp-form-count">Consolidated results</span></div><div class="rp-form-actions"><a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=form1b&' . http_build_query($filterParams))); ?>">PDF</a><a class="admin-btn" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_reports_export.php?' . http_build_query(array_merge($filterParams, ['report' => 'form1b'])))); ?>">Excel</a></div></div>
		<div class="rp-form-card"><div class="rp-form-name">OPT Plus Form 1C</div><div class="rp-form-desc">Affected and at-risk children aged 0–59 months.</div><div class="rp-form-meta"><span class="admin-pill is-success">Ready</span><span class="rp-form-count"><?php echo (int)$affectedCount; ?> children</span></div><div class="rp-form-actions"><a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=form1c&' . http_build_query($filterParams))); ?>">PDF</a><a class="admin-btn" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_reports_export.php?' . http_build_query(array_merge($filterParams, ['report' => 'form1c'])))); ?>">Excel</a></div></div>
	</div></div>
</div>

<?php elseif ($activeTab === 'monitoring'): ?>
<div class="rp-panel is-active" data-panel="monitoring">
	<div class="rp-section">
		<div class="rp-section-head"><div><div class="rp-section-title">Monitoring Reports</div><div class="rp-section-sub">Official eOPT Plus monitoring lists. Click View to see the full roster.</div></div></div>
		<div class="rp-monitor-grid">
			<?php foreach ($listCodes as $code => $spec):
				$count = $listCounts[$code] ?? 0;
				$vLink = app_url('/nutritionist/eopt_reports.php') . '?' . http_build_query(array_merge($filterParams, ['list' => $code]));
				$pLink = app_url('/nutritionist/eopt_pdf_generate.php') . '?' . http_build_query(array_merge($filterParams, ['report_type' => 'list', 'list_code' => $code]));
				$eLink = app_url('/nutritionist/eopt_reports_export.php') . '?' . http_build_query(array_merge($filterParams, ['list' => $code]));
			?>
				<div class="rp-monitor-card">
					<div class="rp-monitor-name"><?php echo nutritionist_e($spec['title']); ?></div>
					<div class="rp-monitor-desc"><?php echo nutritionist_e($spec['desc']); ?></div>
					<div class="rp-monitor-footer">
						<div class="rp-monitor-count"><?php echo $count; ?></div>
						<div style="display:flex;gap:4px;align-items:center;">
							<span class="admin-pill <?php echo $count > 0 ? 'is-success' : 'is-muted'; ?>" style="font-size:10px;"><?php echo $count > 0 ? 'Ready' : 'Empty'; ?></span>
							<div class="rp-monitor-actions">
								<a class="admin-icon-btn" title="View" href="<?php echo nutritionist_e($vLink); ?>"><?php echo admin_action_icon('view'); ?></a>
								<a class="admin-icon-btn" title="PDF" href="<?php echo nutritionist_e($pLink); ?>"><?php echo admin_action_icon('print'); ?></a>
								<a class="admin-icon-btn admin-icon-btn-primary" title="Excel" href="<?php echo nutritionist_e($eLink); ?>"><?php echo admin_action_icon('export'); ?></a>
							</div>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
</div>

<?php elseif ($activeTab === 'nutrition'): ?>
<div class="rp-panel is-active" data-panel="nutrition">
	<div class="rp-section"><div class="rp-section-head"><div><div class="rp-section-title">Nutrition Status Reports</div><div class="rp-section-sub">Detailed child status and barangay-level nutrition summaries.</div></div></div><div class="rp-form-grid">
		<div class="rp-form-card"><div class="rp-form-name">NutStatusTool</div><div class="rp-form-desc">Detailed nutrition-status dataset with child, measurement, and WFA/HFA/WFL-H indicators.</div><div class="rp-form-meta"><span class="admin-pill is-success">Ready</span><span class="rp-form-count"><?php echo (int)$totalAssessed; ?> children</span></div><div class="rp-form-actions"><a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?' . http_build_query(array_merge($filterParams, ['report_type' => 'nutstatus'])))); ?>">PDF</a><a class="admin-btn" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_reports_export.php?' . http_build_query(array_merge($filterParams, ['report' => 'nutstatus'])))); ?>">Excel</a></div></div>
		<div class="rp-form-card"><div class="rp-form-name">NutStatusBrgy</div><div class="rp-form-desc">Barangay-level sex-disaggregated summary for 0–23 and 0–59 months.</div><div class="rp-form-meta"><span class="admin-pill is-success">Ready</span><span class="rp-form-count">0–23 &amp; 0–59 months</span></div><div class="rp-form-actions"><a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?' . http_build_query(array_merge($filterParams, ['report_type' => 'nutstatusbrgy'])))); ?>">PDF</a><a class="admin-btn" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_reports_export.php?' . http_build_query(array_merge($filterParams, ['report' => 'nutstatusbrgy'])))); ?>">Excel</a></div></div>
	</div></div>
	<?php
	$summaryRows = admin_fetch_all(
		"SELECT c.sex,
			CASE WHEN TIMESTAMPDIFF(MONTH, c.birthdate, LAST_DAY(?)) < 24 THEN '0-23' ELSE '24-59' END AS age_band,
			m.wfa_status, m.hfa_status, m.wfh_status
		 FROM children c INNER JOIN measurements m ON m.id = (
			SELECT m2.id FROM measurements m2 WHERE m2.child_id = c.id ORDER BY m2.measurement_date DESC, m2.id DESC LIMIT 1
		 )
		 WHERE {$scope} AND TIMESTAMPDIFF(MONTH, c.birthdate, LAST_DAY(?)) BETWEEN 0 AND 59",
		's' . str_repeat('i', count($scopeParams) + count($barangayFilterParams)) . 's',
		array_merge([$anchorDate->format('Y-m-d')], $scopeParams, $barangayFilterParams, [$anchorDate->format('Y-m-d')])
	);
	$bucket = static fn(): array => ['Male' => ['0-23' => 0, '24-59' => 0, 'total' => 0], 'Female' => ['0-23' => 0, '24-59' => 0, 'total' => 0], 'Total' => ['0-23' => 0, '24-59' => 0, 'total' => 0]];
	$wfaS = ['SUW' => $bucket(), 'MUW' => $bucket(), 'Normal' => $bucket(), 'OW' => $bucket()];
	$hfaS = ['SSt' => $bucket(), 'MSt' => $bucket(), 'Normal' => $bucket(), 'Tall' => $bucket()];
	$wfhS = ['SW' => $bucket(), 'MW' => $bucket(), 'Normal' => $bucket(), 'OW' => $bucket(), 'Ob' => $bucket()];
	foreach ($summaryRows as $row) {
		$sl = (string)$row['sex'] === 'Male' ? 'Male' : 'Female';
		$ab = (string)$row['age_band'];
		foreach ([['wfa_status', &$wfaS], ['hfa_status', &$hfaS], ['wfh_status', &$wfhS]] as [$f, &$r]) {
			$v = $row[$f] ?? null;
			if ($v === null || !isset($r[$v])) continue;
			$r[$v][$sl][$ab]++; $r[$v][$sl]['total']++; $r[$v]['Total'][$ab]++; $r[$v]['Total']['total']++;
		}
	}
	$renderTable = function(string $title, array $data): void {
		$totalAll = 0; foreach ($data as $d) $totalAll += (int)$d['Total']['total'];
	?>
		<div class="rp-table-section">
			<div class="rp-table-title"><?php echo nutritionist_e($title); ?></div>
			<div class="nutritionist-table-wrap" style="overflow-x:auto;">
				<table class="nutritionist-table" style="min-width:700px;">
					<thead><tr><th>Status</th><th>Male 0-23</th><th>Male 24-59</th><th>Male Total</th><th>Female 0-23</th><th>Female 24-59</th><th>Female Total</th><th>Grand Total</th><th>%</th></tr></thead>
					<tbody>
						<?php foreach ($data as $status => $c): $gt = (int)$c['Total']['total']; ?>
							<tr><td><strong><?php echo nutritionist_e($status); ?></strong></td>
							<td><?php echo (int)$c['Male']['0-23']; ?></td><td><?php echo (int)$c['Male']['24-59']; ?></td><td><?php echo (int)$c['Male']['total']; ?></td>
							<td><?php echo (int)$c['Female']['0-23']; ?></td><td><?php echo (int)$c['Female']['24-59']; ?></td><td><?php echo (int)$c['Female']['total']; ?></td>
							<td><strong><?php echo $gt; ?></strong></td>
							<td><?php echo $totalAll > 0 ? number_format(($gt / $totalAll) * 100, 1) . '%' : '0.0%'; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	<?php };
	$renderTable('WEIGHT-FOR-AGE (WFA)', $wfaS);
	$renderTable('HEIGHT-FOR-AGE (HFA)', $hfaS);
	$renderTable('WEIGHT-FOR-LENGTH/HEIGHT (WFH)', $wfhS);
	?>
	<div style="margin-top:12px;">
		<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=summary&' . http_build_query($filterParams))); ?>">Generate PDF</a>
	</div>
</div>

<?php elseif ($activeTab === 'prevalence'): ?>
<div class="rp-panel is-active" data-panel="prevalence">
	<?php
	$prevBaseParams = array_merge($scopeParams, $barangayFilterParams, [$anchorDate->format('Y-m-d')]);
	$allPrev = admin_fetch_all(
		"SELECT lm.wfa_status, lm.hfa_status, lm.wfh_status, lm.weight_kg, lm.height_cm
		 FROM children c {$latestJoin}
		 WHERE {$scope} AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 59",
		$baseTypes, $prevBaseParams
	);
	$prevTotal = count($allPrev);
	$pc = ['wasted' => 0, 'stunted' => 0, 'ow_ob' => 0, 'underweight' => 0, 'uw_or_stunted' => 0, 'stunted_or_owob' => 0];
	foreach ($allPrev as $c) {
		$wfa = $c['wfa_status'] ?? null; $hfa = $c['hfa_status'] ?? null; $wfh = $c['wfh_status'] ?? null;
		if (in_array($wfh, ['MW', 'SW'], true)) $pc['wasted']++;
		if (in_array($hfa, ['MSt', 'SSt'], true)) $pc['stunted']++;
		if ($wfa === 'OW' || in_array($wfh, ['OW', 'Ob'], true)) $pc['ow_ob']++;
		if (in_array($wfa, ['MUW', 'SUW'], true)) $pc['underweight']++;
		if (in_array($wfa, ['MUW', 'SUW'], true) || in_array($hfa, ['MSt', 'SSt'], true)) $pc['uw_or_stunted']++;
		if (in_array($hfa, ['MSt', 'SSt'], true) || ($wfa === 'OW' || in_array($wfh, ['OW', 'Ob'], true))) $pc['stunted_or_owob']++;
	}
	$indicators = [['Wasted (MW + SW)', $pc['wasted']], ['Stunted (MSt + SSt)', $pc['stunted']], ['Overweight / Obese', $pc['ow_ob']], ['Underweight (MUW + SUW)', $pc['underweight']], ['Underweight and/or Stunted', $pc['uw_or_stunted']], ['Stunted and/or OW/Obese', $pc['stunted_or_owob']]];
	?>
	<div class="rp-table-section">
		<div class="rp-table-title">Community-Level Prevalence (0–59 months)</div>
		<div class="nutritionist-table-wrap" style="overflow-x:auto;">
			<table class="nutritionist-table" style="min-width:500px;">
				<thead><tr><th>Indicator</th><th style="text-align:right;">Count</th><th style="text-align:right;">Prevalence</th></tr></thead>
				<tbody>
					<?php foreach ($indicators as [$label, $count]):
						$pct = $prevTotal > 0 ? number_format(($count / $prevTotal) * 100, 1) . '%' : '0.0%'; ?>
						<tr><td><?php echo nutritionist_e($label); ?></td><td style="text-align:right;font-weight:600;"><?php echo $count; ?></td><td style="text-align:right;font-weight:600;color:var(--admin-primary);"><?php echo $pct; ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
	<div class="rp-chart-grid">
		<div class="rp-chart-card"><div class="rp-chart-title">Malnutrition Prevalence</div><div style="position:relative;height:280px;"><canvas id="rp-prevalence-chart"></canvas></div></div>
	</div>
	<div style="margin-top:14px;">
		<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=prevalence&' . http_build_query($filterParams))); ?>">Generate PDF Report</a>
	</div>
</div>

<?php elseif ($activeTab === 'dqc'): ?>
<div class="rp-panel is-active" data-panel="dqc">
	<div class="rp-section">
		<div class="rp-section-head"><div><div class="rp-section-title">Data Quality Check</div><div class="rp-section-sub">Completeness and accuracy audit of all child records</div></div>
			<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=dqc&' . http_build_query($filterParams))); ?>">Export DQC PDF</a>
		</div>
		<div class="rp-dqc-grid">
			<?php
			$dqcItems = [
				['Repeated name/birthdate', $dqDupCount, $dqDupCount > 0 ? 'warn' : 'ok', 'duplicate_name_dob'],
				['Missing information', (int)$dqMissingInformation, (int)$dqMissingInformation > 0 ? 'danger' : 'ok', 'missing_information'],
				['No parent/address', (int)$dqNoParentAddress, (int)$dqNoParentAddress > 0 ? 'danger' : 'ok', 'no_parent'],
				['Missing sex', (int)$dqMissingSex, (int)$dqMissingSex > 0 ? 'danger' : 'ok', 'missing_sex'],
				['Missing birthdate', (int)$dqMissingDob, (int)$dqMissingDob > 0 ? 'danger' : 'ok', 'missing_birthdate'],
				['No parent/address', 0, 'ok', 'no_parent'],
				['Older than 59 mo', (int)$dqOverAge, (int)$dqOverAge > 0 ? 'warn' : 'ok', 'over_59_months'],
				['Height, no weight', (int)$dqHeightNoWeight, (int)$dqHeightNoWeight > 0 ? 'warn' : 'ok', 'height_no_weight'],
				['Weight, no height', (int)$dqWeightNoHeight, (int)$dqWeightNoHeight > 0 ? 'warn' : 'ok', 'weight_no_height'],
			];
			foreach ($dqcItems as [$label, $count, $sev, $code]):
				$link = $count > 0 ? app_url('/nutritionist/eopt_dqc_records.php?issue=' . $code . '&' . http_build_query($filterParams)) : '#';
			?>
				<a href="<?php echo nutritionist_e($link); ?>" class="rp-dqc-card is-<?php echo $sev; ?>" <?php echo $count === 0 ? 'style="opacity:0.5;"' : ''; ?>>
					<div class="rp-dqc-count" style="color:<?php echo $sev === 'ok' ? 'var(--admin-primary)' : ($sev === 'warn' ? '#d97706' : 'var(--admin-danger)'); ?>;"><?php echo $count; ?></div>
					<div class="rp-dqc-label"><?php echo nutritionist_e($label); ?></div>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
</div>

<?php elseif ($activeTab === 'followup'): ?>
<div class="rp-panel is-active" data-panel="followup">
	<?php
	$followupRows = admin_fetch_all(
		"SELECT c.id AS child_id, c.child_code, c.first_name, c.middle_name, c.last_name,
			c.sex, c.birthdate, p.name AS parent_name, lm.measurement_date, lm.weight_kg, lm.height_cm,
			lm.wfa_status, lm.hfa_status, lm.wfh_status, lm.nutritional_status,
			a.id AS appt_id, a.scheduled_at, a.status AS appt_status, a.followup_category,
			TIMESTAMPDIFF(MONTH, c.birthdate, CURDATE()) AS age_months
		 FROM appointments a
		 INNER JOIN children c ON c.id = a.child_id
		 INNER JOIN parents p ON p.id = c.parent_id
		 LEFT JOIN measurements lm ON lm.id = (SELECT m2.id FROM measurements m2 WHERE m2.child_id = c.id ORDER BY m2.measurement_date DESC, m2.id DESC LIMIT 1)
		 WHERE a.appointment_type = 'followup' AND a.status IN ('pending','scheduled') AND {$scope}
		 ORDER BY a.scheduled_at ASC LIMIT 25",
		str_repeat('i', count($scopeParams) + count($barangayFilterParams)),
		array_merge($scopeParams, $barangayFilterParams)
	);
	$fcRows = admin_fetch_all(
		"SELECT a.status, COUNT(*) AS cnt FROM appointments a INNER JOIN children c ON c.id = a.child_id
		 WHERE a.appointment_type = 'followup' AND {$scope} GROUP BY a.status",
		str_repeat('i', count($scopeParams) + count($barangayFilterParams)),
		array_merge($scopeParams, $barangayFilterParams)
	);
	$fCounts = ['pending' => 0, 'scheduled' => 0, 'completed' => 0, 'referred' => 0];
	foreach ($fcRows as $fr) { $st = (string)$fr['status']; if (isset($fCounts[$st])) $fCounts[$st] = (int)$fr['cnt']; }
	?>
	<div class="rp-followup-row">
		<div class="rp-followup-pill"><span class="count" style="color:#d97706;"><?php echo $fCounts['pending']; ?></span> Pending</div>
		<div class="rp-followup-pill"><span class="count" style="color:var(--admin-primary);"><?php echo $fCounts['scheduled']; ?></span> Scheduled</div>
		<div class="rp-followup-pill"><span class="count" style="color:var(--admin-primary);"><?php echo $fCounts['completed']; ?></span> Completed</div>
		<div class="rp-followup-pill"><span class="count" style="color:var(--admin-danger);"><?php echo $fCounts['referred']; ?></span> Referred</div>
	</div>
	<?php if (empty($followupRows)): ?>
		<div class="rp-table-section"><div style="padding:20px;text-align:center;color:var(--admin-muted);font-size:13px;">No children currently require follow-up.</div></div>
	<?php else: ?>
		<div class="rp-table-section">
			<div class="nutritionist-table-wrap" style="overflow-x:auto;">
				<table class="nutritionist-table" style="min-width:850px;">
					<thead><tr><th>Child</th><th>Age</th><th>Status</th><th>Last Measured</th><th>Weight</th><th>Height</th><th>Appt</th><th>Scheduled</th><th>Action</th></tr></thead>
					<tbody>
						<?php foreach ($followupRows as $i => $row):
							$ab = followup_abnormal_codes($row['wfa_status'] ?? null, $row['hfa_status'] ?? null, $row['wfh_status'] ?? null);
							$catLabel = $ab ? followup_category_label(implode('+', $ab)) : 'Normal';
						?>
							<tr>
								<td><div style="font-weight:600;"><?php echo nutritionist_e(trim(($row['last_name']??'').', '.($row['first_name']??'').' '.($row['middle_name']??''))); ?></div><div style="font-size:11px;color:var(--admin-muted);"><?php echo nutritionist_e((string)$row['child_code']); ?></div></td>
								<td><?php echo (int)$row['age_months']; ?> mo</td>
								<td><span class="admin-pill <?php echo nutritionist_status_class($row['wfa_status'] ?? ''); ?>"><?php echo nutritionist_e($catLabel); ?></span></td>
								<td><?php echo nutritionist_e((string)($row['measurement_date'] ?? '—')); ?></td>
								<td><?php echo $row['weight_kg'] !== null ? number_format((float)$row['weight_kg'], 2) . ' kg' : '—'; ?></td>
								<td><?php echo $row['height_cm'] !== null ? number_format((float)$row['height_cm'], 1) . ' cm' : '—'; ?></td>
								<td><span class="admin-pill <?php echo (string)$row['appt_status'] === 'pending' ? 'is-warn' : 'is-info'; ?>"><?php echo nutritionist_e(ucfirst((string)$row['appt_status'])); ?></span></td>
								<td><?php echo nutritionist_e((string)($row['scheduled_at'] ?? '—')); ?></td>
								<td><a class="admin-icon-btn" title="Referral PDF" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=referral&child_id=' . (int)$row['child_id'])); ?>"><?php echo admin_action_icon('print'); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	<?php endif; ?>
</div>

<?php elseif ($activeTab === 'export'): ?>
<div class="rp-panel is-active" data-panel="export">
	<div class="rp-export-grid">
		<div class="rp-export-card">
			<div style="font-weight:700;font-size:14px;margin-bottom:6px;">EOPT Workbook (.xlsx)</div>
			<div style="font-size:12px;color:var(--admin-muted);margin-bottom:14px;">Full workbook with summary sheet and all monitoring lists in DOH format.</div>
			<a class="admin-btn" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_reports_export.php?' . http_build_query($filterParams))); ?>"><?php echo admin_action_icon('export'); ?> Download Excel Workbook</a>
			<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_reports_export.php?' . http_build_query(array_merge($filterParams, ['report' => 'nutstatus'])))); ?>"><?php echo admin_action_icon('export'); ?> NutStatusTool Excel</a>
			<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_reports_export.php?' . http_build_query(array_merge($filterParams, ['report' => 'nutstatusbrgy'])))); ?>"><?php echo admin_action_icon('export'); ?> NutStatusBrgy Excel</a>
		</div>
		<div class="rp-export-card">
			<div style="font-weight:700;font-size:14px;margin-bottom:6px;">Formal PDF Reports</div>
			<div style="font-size:12px;color:var(--admin-muted);margin-bottom:14px;">Printable official report forms with DOH headers and signatures.</div>
			<div style="display:flex;gap:8px;flex-wrap:wrap;">
				<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=form1a&' . http_build_query($filterParams))); ?>">Form 1A PDF</a>
				<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=form1b&' . http_build_query($filterParams))); ?>">Form 1B PDF</a>
				<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=form1c&' . http_build_query($filterParams))); ?>">Form 1C PDF</a>
				<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=nutstatus&' . http_build_query($filterParams))); ?>">NutStatusTool PDF</a>
				<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=nutstatusbrgy&' . http_build_query($filterParams))); ?>">NutStatusBrgy PDF</a>
				<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=summary&' . http_build_query($filterParams))); ?>">Summary PDF</a>
				<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=prevalence&' . http_build_query($filterParams))); ?>">Prevalence PDF</a>
				<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_pdf_generate.php?report_type=dqc&' . http_build_query($filterParams))); ?>">DQC PDF</a>
			</div>
		</div>
	</div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.querySelectorAll('#rpTabs').forEach(function(row){
	row.addEventListener('click', function(e){
		var tab = e.target.closest('.rp-tab');
		if (!tab) return;
		row.querySelectorAll('.rp-tab').forEach(function(t){ t.classList.remove('is-active'); });
		tab.classList.add('is-active');
	});
});
</script>

<?php endif; ?>

<?php nutritionist_layout_end(); ?>

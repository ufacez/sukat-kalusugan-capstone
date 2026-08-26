<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/followup_scheduler.php';

$user = nutritionist_require_access();

/**
 * ==========================================================================
 * EOPT REPORTS — Operation Timbang Plus monitoring rosters
 * --------------------------------------------------------------------------
 * MONTHLY view (April to December):
 *   Roster 1 · ALL children aged 0-23 months, regardless of status;
 *   Roster 2 · older children 24-59 months who are MALNOURISHED
 *              (Obese / Overweight / Moderately Underweight / Severely Underweight /
 *              Moderately Stunted / Severely Stunted / Moderately Wasted / Severely Wasted);
 *   Roster 3 · older children 24-59 months still without any measurement
 *              (they must be found and baselined).
 *
 * QUARTERLY view (rounds every April / July / October):
 *   Older children 24-59 months classified NORMAL during the annual OPT
 *   baseline round (Q1 = January-March) — re-checked once per quarter.
 *
 * Ages are evaluated at the END of the selected reporting month, matching
 * how DOH enumerators decide which band a preschool child belongs to.
 * ==========================================================================
 */

$view = (string)($_GET['view'] ?? 'monthly');
if (!in_array($view, ['monthly', 'quarterly'], true)) {
	$view = 'monthly';
}

$year = (int)($_GET['year'] ?? date('Y'));
if ($year < 2020 || $year > 2100) {
	$year = (int)date('Y');
}

$currentMonth = (int)date('n');

/*
 | Monthly runs April-December. Default to the current month when it is a
 | monitoring month; otherwise start with April.
 */
$month = (int)($_GET['month'] ?? ($currentMonth >= 4 && $currentMonth <= 12 ? $currentMonth : 4));
if ($month < 4 || $month > 12) {
	$month = 4;
}

/*
 | Quarterly rounds happen in April, July and October. Default to the
 | upcoming round relative to today.
 */
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

/*
 |--------------------------------------------------------------------------
 | Shared SELECT fragments. `lm` is the child's LATEST measurement ever
 | (current standing); `q1` is the LATEST measurement inside the Q1 window.
 |--------------------------------------------------------------------------
 */
$baseSelect = "SELECT
	c.id,
	c.child_code,
	c.first_name,
	c.middle_name,
	c.last_name,
	c.sex,
	c.birthdate,
	c.purok,
	bg.name AS barangay,
	p.name AS parent_name,
	p.phone AS parent_phone";

$latestJoin = " INNER JOIN measurements lm ON lm.id = (
	SELECT m2.id FROM measurements m2
	WHERE m2.child_id = c.id
	ORDER BY m2.measurement_date DESC, m2.id DESC
	LIMIT 1
 )";

$q1WindowStart = sprintf('%04d-01-01', $year);
$q1WindowEnd = sprintf('%04d-03-31', $year);
$quarterLabel = sprintf('Q%d', intdiv($checkupMonth - 1, 3));

/*
 |--------------------------------------------------------------------------
 | MONTHLY VIEW QUERIES
 |--------------------------------------------------------------------------
 */
$infantRows = [];
$malnourishedRows = [];
$needsBaselineRows = [];

if ($view === 'monthly') {
	try {
		$anchorDate = new DateTimeImmutable(sprintf('%04d-%02d-t', $year, $month));
	} catch (Exception) {
		$anchorDate = new DateTimeImmutable('today');
	}

	// Scope/barangay params bind as ints; the anchor date binds as a string.
	$intTypes = str_repeat('i', count($scopeParams) + count($barangayFilterParams));

	$params = array_merge($scopeParams, $barangayFilterParams, [$anchorDate->format('Y-m-d')]);

	// Roster 1 — ALL children aged 0-23 months at month-end, any status.
	$infantRows = admin_fetch_all(
		"{$baseSelect},
		lm.measurement_date,
		lm.age_months,
		lm.height_cm,
		lm.weight_kg,
		lm.wfa_status,
		lm.hfa_status,
		lm.wfh_status,
		lm.is_flagged
		FROM children c
		INNER JOIN parents p ON p.id = c.parent_id
		LEFT JOIN barangays bg ON bg.id = c.barangay_id
		LEFT JOIN measurements lm ON lm.id = (
			SELECT m2.id FROM measurements m2
			WHERE m2.child_id = c.id
			ORDER BY m2.measurement_date DESC, m2.id DESC
			LIMIT 1
		)
		WHERE {$scope}{$barangayFilterSql}
		  AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 23
		ORDER BY c.last_name ASC, c.first_name ASC",
		$intTypes . 's',
		$params
	);

	// Roster 2 — malnourished 24-59 months (any axis outside Normal).
	$params = array_merge($scopeParams, $barangayFilterParams, [$anchorDate->format('Y-m-d')]);

	$malnourishedRows = admin_fetch_all(
		"{$baseSelect},
		lm.measurement_date,
		lm.age_months,
		lm.height_cm,
		lm.weight_kg,
		lm.waz,
		lm.haz,
		lm.whz,
		lm.wfa_status,
		lm.hfa_status,
		lm.wfh_status,
		lm.is_flagged,
		lm.flag_reason
		FROM children c
		INNER JOIN parents p ON p.id = c.parent_id
		LEFT JOIN barangays bg ON bg.id = c.barangay_id
		{$latestJoin}
		WHERE {$scope}{$barangayFilterSql}
		  AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 24 AND 59
		AND (
			lm.wfa_status IN ('SUW','MUW','OW')
			OR lm.hfa_status IN ('SSt','MSt')
			OR lm.wfh_status IN ('SW','MW','OW','Ob')
		  )
		ORDER BY c.last_name ASC, c.first_name ASC",
		$intTypes . 's',
		$params
	);

	// Roster 3 — 24-59 months with NO measurement on record yet.
	$params = array_merge($scopeParams, $barangayFilterParams, [$anchorDate->format('Y-m-d')]);

	$needsBaselineRows = admin_fetch_all(
		"{$baseSelect}
		FROM children c
		INNER JOIN parents p ON p.id = c.parent_id
		LEFT JOIN barangays bg ON bg.id = c.barangay_id
		WHERE {$scope}{$barangayFilterSql}
		  AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 24 AND 59
		  AND NOT EXISTS (
			SELECT 1 FROM measurements m3 WHERE m3.child_id = c.id
		  )
		ORDER BY c.last_name ASC, c.first_name ASC",
		$intTypes . 's',
		$params
	);
}

/*
 |--------------------------------------------------------------------------
 | QUARTERLY VIEW QUERY — normal-in-Q1 cohort, aged 24-59 mo at round end
 |--------------------------------------------------------------------------
 */
$quarterlyRows = [];

if ($view === 'quarterly') {
	try {
		$anchorDate = new DateTimeImmutable(sprintf('%04d-%02d-t', $year, $checkupMonth));
	} catch (Exception) {
		$anchorDate = new DateTimeImmutable('today');
	}

	$intTypes = str_repeat('i', count($scopeParams) + count($barangayFilterParams));
	$params = array_merge($scopeParams, $barangayFilterParams, [$q1WindowStart, $q1WindowEnd, $anchorDate->format('Y-m-d')]);

	$quarterlyRows = admin_fetch_all(
		"{$baseSelect},
		q1.measurement_date AS q1_measured,
		q1.wfa_status AS q1_wfa,
		q1.hfa_status AS q1_hfa,
		q1.wfh_status AS q1_wfh,
		q1.weight_kg AS q1_weight,
		q1.height_cm AS q1_height,
		lm.measurement_date AS latest_measured,
		lm.wfa_status AS latest_wfa,
		lm.hfa_status AS latest_hfa,
		lm.wfh_status AS latest_wfh
		FROM children c
		INNER JOIN parents p ON p.id = c.parent_id
		LEFT JOIN barangays bg ON bg.id = c.barangay_id
		INNER JOIN measurements q1 ON q1.id = (
			SELECT m2.id FROM measurements m2
			WHERE m2.child_id = c.id
			  AND m2.measurement_date BETWEEN ? AND ?
			ORDER BY m2.measurement_date DESC, m2.id DESC
			LIMIT 1
		)
		LEFT JOIN measurements lm ON lm.id = (
			SELECT m2.id FROM measurements m2
			WHERE m2.child_id = c.id
			ORDER BY m2.measurement_date DESC, m2.id DESC
			LIMIT 1
		)
		WHERE {$scope}{$barangayFilterSql}
		  AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 24 AND 59
		  AND q1.wfa_status = 'Normal'
		  AND q1.hfa_status = 'Normal'
		  AND q1.wfh_status = 'Normal'
		  AND q1.is_flagged = 0
		ORDER BY c.last_name ASC, c.first_name ASC",
		$intTypes . 'sss',
		$params
	);
}

$monthsList = [4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];
$roundsList = [4 => 'April Round', 7 => 'July Round', 10 => 'October Round'];

$totalMonitored = count($infantRows) + count($malnourishedRows) + count($needsBaselineRows);
$flaggedCount = count(array_filter(array_merge($infantRows, $malnourishedRows), static fn(array $row): bool => !empty($row['is_flagged'])));

$recheckedCount = count(array_filter($quarterlyRows, static function (array $row): bool {
	return !empty($row['latest_measured']) && (string)$row['latest_measured'] > $q1WindowEnd;
}));

$exportUrl = app_url('/nutritionist/eopt_reports_export.php') . '?' . http_build_query([
	'view' => $view,
	'year' => $year,
	'month' => $month,
	'checkup_month' => $checkupMonth,
	'barangay_id' => $barangayFilter,
]);

/*
 |--------------------------------------------------------------------------
 | SINGLE-LIST VIEW MODE — V2 combined monitoring lists
 | Renders the roster for one monitoring list and an Export button.
 |--------------------------------------------------------------------------
 */
$listCodes = [
	'0-23' => [
		'sheet' => 'List_0_23',
		'title' => 'MONITORING LIST FOR CHILDREN 0-23 MONTHS OLD',
		'axis' => 'All children (monthly weighing)',
		'condition' => '1=1',
		'age_min' => 0,
		'age_max' => 23,
		'is_infant' => true,
	],
	'MW' => [
		'sheet' => 'List_MW',
		'title' => 'MONITORING LIST FOR MODERATELY WASTED CHILDREN 0-59 MONTHS OLD',
		'axis' => 'Weight-for-Height',
		'condition' => "lm.wfh_status = 'MW'",
		'age_min' => 0,
		'age_max' => 59,
		'is_infant' => false,
	],
	'SW' => [
		'sheet' => 'List_SW',
		'title' => 'MONITORING LIST FOR SEVERELY WASTED CHILDREN 0-59 MONTHS OLD',
		'axis' => 'Weight-for-Height',
		'condition' => "lm.wfh_status = 'SW'",
		'age_min' => 0,
		'age_max' => 59,
		'is_infant' => false,
	],
	'MSt_SSt' => [
		'sheet' => 'List_MSt_SSt',
		'title' => 'MONITORING LIST FOR MODERATELY OR SEVERELY STUNTED CHILDREN 0-59 MONTHS OLD',
		'axis' => 'Height-for-Age',
		'condition' => "lm.hfa_status IN ('St','SSt')",
		'age_min' => 0,
		'age_max' => 59,
		'is_infant' => false,
	],
	'OW_Ob' => [
		'sheet' => 'List_OW_Ob',
		'title' => 'MONITORING LIST FOR OVERWEIGHT OR OBESE CHILDREN 0-59 MONTHS OLD',
		'axis' => 'Weight-for-Age / Weight-for-Height',
		'condition' => "(lm.wfa_status = 'OW' OR lm.wfh_status IN ('OW','Ob'))",
		'age_min' => 0,
		'age_max' => 59,
		'is_infant' => false,
	],
	'MUW_SUW_MSt_SSt' => [
		'sheet' => 'List_MUW_SUW_MSt_SSt',
		'title' => 'MODERATELY/SEVERELY UNDERWEIGHT + MODERATELY/SEVERELY STUNTED 0-59 MONTHS OLD',
		'axis' => 'Weight-for-Age + Height-for-Age',
		'condition' => "(lm.wfa_status IN ('UW','SUW') AND lm.hfa_status IN ('St','SSt'))",
		'age_min' => 0,
		'age_max' => 59,
		'is_infant' => false,
	],
	'MSt_SSt_MW_SW' => [
		'sheet' => 'List_MSt_SSt_MW_SW',
		'title' => 'MODERATELY/SEVERELY STUNTED + MODERATELY/SEVERELY WASTED 0-59 MONTHS OLD',
		'axis' => 'Height-for-Age + Weight-for-Height',
		'condition' => "(lm.hfa_status IN ('St','SSt') AND lm.wfh_status IN ('MW','SW'))",
		'age_min' => 0,
		'age_max' => 59,
		'is_infant' => false,
	],
	'MSt_SSt_OW_Ob' => [
		'sheet' => 'List_MSt_SSt_OW_Ob',
		'title' => 'MODERATELY/SEVERELY STUNTED + OVERWEIGHT OR OBESE 0-59 MONTHS OLD',
		'axis' => 'Height-for-Age + Weight-for-Height',
		'condition' => "(lm.hfa_status IN ('St','SSt') AND (lm.wfa_status = 'OW' OR lm.wfh_status IN ('OW','Ob')))",
		'age_min' => 0,
		'age_max' => 59,
		'is_infant' => false,
	],
];
// Case-insensitive lookup so ?list=ob, ?list=OB, ?list=Ob all work.
$listParamRaw = trim((string)($_GET['list'] ?? ''));
$listParamKey = strtolower($listParamRaw);
$listCodeLowerMap = [];
foreach ($listCodes as $code => $spec) { $listCodeLowerMap[strtolower($code)] = $code; }
$isSingleList = $listParamKey !== '' && isset($listCodeLowerMap[$listParamKey]);
$listParam = $isSingleList ? $listCodeLowerMap[$listParamKey] : $listParamRaw;
$listRows = [];

if ($isSingleList) {
	$spec = $listCodes[$listParam];

	$listView = (string)($_GET['view'] ?? 'monthly');
	if (!in_array($listView, ['monthly', 'quarterly'], true)) {
		$listView = 'monthly';
	}
	$listMonth = (int)($_GET['month'] ?? $month);
	$listCheckup = (int)($_GET['checkup_month'] ?? $checkupMonth);

	$listAnchorDate = null;
	try {
		if ($listView === 'monthly') {
			$listAnchorDate = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $listMonth)))->modify('last day of this month');
		} else {
			$listAnchorDate = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $listCheckup)))->modify('last day of this month');
		}
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
		lm.measurement_date, lm.age_months, lm.height_cm, lm.weight_kg,
		lm.wfa_status, lm.hfa_status, lm.wfh_status, lm.waz, lm.haz, lm.whz, lm.is_flagged
		FROM children c
		INNER JOIN parents p ON p.id = c.parent_id
		LEFT JOIN barangays bg ON bg.id = c.barangay_id
		{$latestJoin}
		WHERE {$scope}{$barangayFilterSql}
		  AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN {$spec['age_min']} AND {$spec['age_max']}
		  AND {$spec['condition']}
		ORDER BY c.last_name ASC, c.first_name ASC",
		$listParamTypes . 's',
		$listParams
	);
}

$actions = '<a class="admin-btn" href="' . nutritionist_e($exportUrl) . '">Export eOPT Workbook (.xlsx)</a>'
	. '<button type="button" class="admin-btn-secondary" onclick="window.print()">Print / Save as PDF</button>';

nutritionist_layout_start('EOPT Reports', 'Operation Timbang Plus monitoring rosters — monthly and quarterly views following the DOH schedule.', 'eopt_reports', $actions);
?>
<style>
	@media print {
		.nutritionist-sidebar, .nutritionist-topbar, .admin-search, .eopt-report-controls, .nutritionist-page-actions { display: none !important; }
		.nutritionist-content { margin: 0 !important; padding: 0 !important; }
		body { background: #fff !important; }
	}
	.eopt-report-controls { display: flex; gap: 12px; flex-wrap: wrap; align-items: end; margin-bottom: 20px; }
	.eopt-report-controls label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; color: var(--admin-muted); }
	.eopt-report-controls select { padding: 8px 10px; border-radius: 8px; border: 1px solid var(--admin-border); }
	.eopt-print-header { display: none; }
	@media print { .eopt-print-header { display: block; margin-bottom: 16px; } }
</style>

<?php if ($isSingleList): ?>
<?php
	$spec = $listCodes[$listParam];
	$listExportUrl = app_url('/nutritionist/eopt_reports_export.php') . '?' . http_build_query([
		'list'        => $listParam,
		'year'        => $year,
		'barangay_id' => $barangayFilter,
		'view'        => (string)($_GET['view'] ?? 'monthly'),
	]);
?>
<style>
	.eopt-list-breadcrumbs { display:flex; gap:8px; align-items:center; font-size:13px; color:var(--admin-muted); margin-bottom:16px; }
	.eopt-list-breadcrumbs a { color:var(--admin-text); }
	.eopt-list-header { display:flex; justify-content:space-between; align-items:flex-end; gap:16px; flex-wrap:wrap; margin-bottom:16px; }
	.eopt-list-header h2 { margin:0; }
	.eopt-list-export { padding: 10px 18px; background: #1f6feb; color:#fff; border-radius: 8px; text-decoration:none; font-weight:600; }
	.eopt-list-export:hover { background: #1857c4; }
</style>

<div class="eopt-list-breadcrumbs">
	<a href="<?php echo nutritionist_e(app_url('/nutritionist/eopt_reports.php?view=' . urlencode((string)($_GET['view'] ?? 'monthly')) . '&year=' . (int)$year . '&barangay_id=' . (int)$barangayFilter)); ?>">← Back to EOPT Reports</a>
</div>

<div class="eopt-list-header">
	<div>
		<h2 style="margin:0;font-size:20px;"><?php echo nutritionist_e($spec['title']); ?></h2>
		<div style="color:var(--admin-muted);font-size:13px;margin-top:4px;">
			<?php echo nutritionist_e($spec['axis']); ?>
			&nbsp;·&nbsp; Age 0–59 months
			&nbsp;·&nbsp; Year <?php echo (int)$year; ?>
			<?php if ($barangayFilter > 0): ?>
				&nbsp;·&nbsp; Barangay #<?php echo (int)$barangayFilter; ?>
			<?php endif; ?>
		</div>
	</div>
	<a class="eopt-list-export" href="<?php echo nutritionist_e($listExportUrl); ?>">⬇ Export this list (.xlsx)</a>
</div>

<?php if ($listRows === []): ?>
	<div class="admin-mini" style="margin-top:8px;">No children currently match this monitoring list for the selected filters.</div>
<?php else: ?>
	<?php
	$visitFrom = ($listAnchorDate ?? $anchorDate)->modify('-5 months')->format('Y-m-d');
	$visitTo = ($listAnchorDate ?? $anchorDate)->format('Y-m-d');
	?>
	<div class="nutritionist-table-wrap" style="overflow-x:auto;">
		<table class="nutritionist-table" style="min-width:1400px;">
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
					<?php for ($m = 1; $m <= 6; $m++): ?>
						<th colspan="3" style="text-align:center;background:#e8f0fe;">Month #<?php echo $m; ?></th>
					<?php endfor; ?>
				</tr>
				<tr>
					<th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th>
					<?php for ($m = 1; $m <= 6; $m++): ?>
						<th style="font-weight:400;font-size:10px;">Date</th>
						<th style="font-weight:400;font-size:10px;">Intervention</th>
						<th style="font-weight:400;font-size:10px;">Status</th>
					<?php endfor; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($listRows as $i => $row): ?>
					<?php
						$visits = followup_fetch_visits((int)$row['id'], $visitFrom, $visitTo, 6);
						$visitMap = [];
						foreach ($visits as $v) {
							$visitMap[] = $v;
						}
					?>
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
						<td><?php echo nutritionist_e((string)($row['wfa_status'] ?? '—')); ?></td>
						<td><?php echo nutritionist_e((string)($row['hfa_status'] ?? '—')); ?></td>
						<td><?php echo nutritionist_e((string)($row['wfh_status'] ?? '—')); ?></td>
						<?php for ($m = 0; $m < 6; $m++): ?>
							<?php $visit = $visitMap[$m] ?? null; ?>
							<td style="font-size:11px;"><?php echo $visit ? nutritionist_e(date('m-d-y', strtotime((string)$visit['scheduled_at']))) : ''; ?></td>
							<td style="font-size:11px;"><?php echo $visit ? nutritionist_e((string)($visit['intervention_notes'] ?? $visit['intervention_type'] ?? '')) : ''; ?></td>
							<td style="font-size:11px;"><?php echo $visit ? nutritionist_e((string)($visit['nutritional_status'] ?? '')) : ''; ?></td>
						<?php endfor; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>

<div class="admin-mini" style="margin-top:16px;">
	This is one of the eight official V2 monitoring lists. The matching <code><?php echo nutritionist_e($spec['sheet']); ?></code> sheet uses the same DOH-formatted template with follow-up visit columns as the rest of the workbook.
</div>

<?php else: ?>

<div class="eopt-print-header">
	<h2 style="margin:0;">EOPT Plus Report — <?php echo nutritionist_e($view === 'monthly' ? ($monthsList[$month] . ' ' . $year . ' Monitoring') : ($roundsList[$checkupMonth] . ' ' . $year)); ?></h2>
	<div style="color:#666;font-size:13px;">Generated <?php echo nutritionist_e(date('F j, Y')); ?> · City Health Office — Nutrition Program</div>
</div>

<form class="eopt-report-controls" method="get">
	<label>
		View
		<select name="view" onchange="this.form.submit()">
			<option value="monthly" <?php echo $view === 'monthly' ? 'selected' : ''; ?>>Monthly Monitoring (April–December)</option>
			<option value="quarterly" <?php echo $view === 'quarterly' ? 'selected' : ''; ?>>Quarterly Rounds (Apr / Jul / Oct)</option>
		</select>
	</label>
	<?php if ($view === 'monthly'): ?>
		<label>
			Month
			<select name="month" onchange="this.form.submit()">
				<?php foreach ($monthsList as $monthNo => $monthName): ?>
					<option value="<?php echo (int)$monthNo; ?>" <?php echo $month === (int)$monthNo ? 'selected' : ''; ?>><?php echo nutritionist_e($monthName); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
	<?php else: ?>
		<label>
			Checkup Round
			<select name="checkup_month" onchange="this.form.submit()">
				<?php foreach ($roundsList as $monthNo => $roundName): ?>
					<option value="<?php echo (int)$monthNo; ?>" <?php echo $checkupMonth === (int)$monthNo ? 'selected' : ''; ?>><?php echo nutritionist_e($roundName); ?></option>
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
			<?php foreach ($barangays as $barangay): ?>
				<option value="<?php echo (int)$barangay['id']; ?>" <?php echo $barangayFilter === (int)$barangay['id'] ? 'selected' : ''; ?>><?php echo nutritionist_e($barangay['name']); ?></option>
			<?php endforeach; ?>
		</select>
	</label>
</form>

<section class="admin-grid-cards" style="margin-bottom:20px;">
	<?php if ($view === 'monthly'): ?>
		<article class="admin-card">
			<div class="admin-stat-label">Children monitored this month</div>
			<div class="admin-stat-value"><?php echo $totalMonitored; ?></div>
			<div class="admin-stat-note">All 0–23 mo + malnourished/unmeasured 24–59 mo</div>
		</article>
		<article class="admin-card">
			<div class="admin-stat-label">Infants &amp; toddlers (0–23 mo)</div>
			<div class="admin-stat-value"><?php echo count($infantRows); ?></div>
			<div class="admin-stat-note">Monthly weighing regardless of status</div>
		</article>
		<article class="admin-card">
			<div class="admin-stat-label">Malnourished (24–59 mo)</div>
			<div class="admin-stat-value"><?php echo count($malnourishedRows); ?></div>
			<div class="admin-stat-note">Monthly follow-up until rehabilitated</div>
		</article>
		<article class="admin-card">
			<div class="admin-stat-label">Flagged measurements</div>
			<div class="admin-stat-value" style="<?php echo $flaggedCount > 0 ? 'color:#E03131;' : ''; ?>"><?php echo $flaggedCount; ?></div>
			<div class="admin-stat-note">Implausible values — re-measure before reporting</div>
		</article>
	<?php else: ?>
		<article class="admin-card">
			<div class="admin-stat-label">Q1-normal cohort (24–59 mo)</div>
			<div class="admin-stat-value"><?php echo count($quarterlyRows); ?></div>
			<div class="admin-stat-note">Baseline Jan–Mar <?php echo $year; ?> · due in the <?php echo nutritionist_e(explode(' ', $roundsList[$checkupMonth])[0]); ?> round</div>
		</article>
		<article class="admin-card">
			<div class="admin-stat-label">Re-checked this round</div>
			<div class="admin-stat-value"><?php echo $recheckedCount; ?></div>
			<div class="admin-stat-note">New measurement recorded after Mar 31</div>
		</article>
		<article class="admin-card">
			<div class="admin-stat-label">Still normal</div>
			<div class="admin-stat-value"><?php echo count(array_filter($quarterlyRows, static fn(array $row): bool => followup_abnormal_codes($row['latest_wfa'] ?? null, $row['latest_hfa'] ?? null, $row['latest_wfh'] ?? null) === [] && !empty($row['latest_measured']))); ?></div>
			<div class="admin-stat-note">Latest measurement shows no deterioration</div>
		</article>
		<article class="admin-card">
			<div class="admin-stat-label">Moved to monthly track</div>
			<div class="admin-stat-value" style="color:#B08900;"><?php echo count(array_filter($quarterlyRows, static fn(array $row): bool => followup_abnormal_codes($row['latest_wfa'] ?? null, $row['latest_hfa'] ?? null, $row['latest_wfh'] ?? null) !== [])); ?></div>
			<div class="admin-stat-note">Deteriorated after Q1 — now monitored monthly</div>
		</article>
	<?php endif; ?>
</section>

<?php
/*
 |--------------------------------------------------------------------------
 | MONITORING LISTS HUB — V2 combined lists
 | Counts pulled live from the same population used by the export.
 |--------------------------------------------------------------------------
 */
$listCountRows = admin_fetch_all(
	"SELECT
	 lm.wfa_status, lm.hfa_status, lm.wfh_status
	 FROM children c
	 {$latestJoin}
	 WHERE {$scope}{$barangayFilterSql}
	   AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 59",
	str_repeat('i', count($scopeParams) + count($barangayFilterParams)) . 's',
	array_merge($scopeParams, $barangayFilterParams, [$anchorDate->format('Y-m-d')])
);

$listCounts = array_fill_keys(array_keys($listCodes), 0);
foreach ($listCountRows as $cr) {
	$wfa = $cr['wfa_status'] ?? null;
	$hfa = $cr['hfa_status'] ?? null;
	$wfh = $cr['wfh_status'] ?? null;

	if ($wfh === 'MW') { $listCounts['MW']++; }
	if ($wfh === 'SW') { $listCounts['SW']++; }
	if (in_array($hfa, ['St', 'SSt'], true)) { $listCounts['MSt_SSt']++; }
	if ($wfa === 'OW' || in_array($wfh, ['OW', 'Ob'], true)) { $listCounts['OW_Ob']++; }
	if (in_array($wfa, ['UW', 'SUW'], true) && in_array($hfa, ['St', 'SSt'], true)) { $listCounts['MUW_SUW_MSt_SSt']++; }
	if (in_array($hfa, ['St', 'SSt'], true) && in_array($wfh, ['MW', 'SW'], true)) { $listCounts['MSt_SSt_MW_SW']++; }
	if (in_array($hfa, ['St', 'SSt'], true) && ($wfa === 'OW' || in_array($wfh, ['OW', 'Ob'], true))) { $listCounts['MSt_SSt_OW_Ob']++; }
}

$infantCountRows = admin_fetch_all(
	"SELECT COUNT(*) AS cnt FROM children c
	 LEFT JOIN measurements lm ON lm.id = (
		SELECT m2.id FROM measurements m2 WHERE m2.child_id = c.id
		ORDER BY m2.measurement_date DESC, m2.id DESC LIMIT 1
	 )
	 WHERE {$scope}{$barangayFilterSql}
	   AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 23",
	str_repeat('i', count($scopeParams) + count($barangayFilterParams)) . 's',
	array_merge($scopeParams, $barangayFilterParams, [$anchorDate->format('Y-m-d')])
);
$listCounts['0-23'] = (int)($infantCountRows[0]['cnt'] ?? 0);
?>
?>
<section class="nutritionist-card" style="margin-bottom:20px;">
	<header class="nutritionist-card-head">
		<h3>Monitoring Lists — V2 Combined View &amp; Export</h3>
		<p class="admin-mini" style="margin:0;">Eight official V2 monitoring lists for the selected reporting period. Click <strong>View</strong> for the on-screen roster, or <strong>Export .xlsx</strong> to download the DOH-formatted sheet.</p>
	</header>
	<div class="nutritionist-table-wrap" style="padding:0;">
		<table class="nutritionist-table" style="margin:0;">
			<thead>
				<tr>
					<th style="width:28%;">List</th>
					<th style="width:32%;">Axis / Definition</th>
					<th style="width:8%;text-align:right;">Children</th>
					<th style="width:32%;">Actions</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($listCodes as $code => $spec): ?>
					<?php
						$viewLink = app_url('/nutritionist/eopt_reports.php') . '?' . http_build_query([
							'list'        => $code,
							'view'        => $view,
							'year'        => $year,
							'month'       => $month,
							'checkup_month' => $checkupMonth,
							'barangay_id' => $barangayFilter,
						]);
						$exportLink = app_url('/nutritionist/eopt_reports_export.php') . '?' . http_build_query([
							'list'        => $code,
							'year'        => $year,
							'barangay_id' => $barangayFilter,
							'view'        => $view,
						]);
					?>
					<tr>
						<td><strong><?php echo nutritionist_e($spec['sheet']); ?></strong><div class="admin-mini"><?php echo nutritionist_e($spec['title']); ?></div></td>
						<td><?php echo nutritionist_e($spec['axis']); ?></td>
						<td style="text-align:right;font-weight:600;"><?php echo (int)($listCounts[$code] ?? 0); ?></td>
						<td>
							<div class="admin-actions">
								<a class="admin-icon-btn" title="View" href="<?php echo nutritionist_e($viewLink); ?>"><?php echo admin_action_icon('view'); ?></a>
								<a class="admin-icon-btn admin-icon-btn-primary" title="Export .xlsx" href="<?php echo nutritionist_e($exportLink); ?>"><?php echo admin_action_icon('export'); ?></a>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>

<?php if ($view === 'monthly'): ?>

	<section class="nutritionist-panel" style="margin-bottom:20px;">
		<div class="admin-section-title" style="margin-bottom:2px;">Roster 1 — All Infants &amp; Toddlers (0–23 Months)</div>
		<div class="admin-mini" style="margin-bottom:12px;">Every child below 24 months is weighed EVERY month regardless of nutritional status · <?php echo count($infantRows); ?> children · ages as of <?php echo nutritionist_e($anchorDate->format('M j, Y')); ?></div>
		<?php
		$rosterVisitFrom = $anchorDate->modify('-5 months')->format('Y-m-d');
		$rosterVisitTo = $anchorDate->format('Y-m-d');
		?>
		<div class="nutritionist-table-wrap" style="overflow-x:auto;">
			<table class="nutritionist-table" style="min-width:1400px;">
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
						<?php for ($m = 1; $m <= 6; $m++): ?>
							<th colspan="3" style="text-align:center;background:#e8f0fe;">Month #<?php echo $m; ?></th>
						<?php endfor; ?>
					</tr>
					<tr>
						<th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th>
						<?php for ($m = 1; $m <= 6; $m++): ?>
							<th style="font-weight:400;font-size:10px;">Date</th>
							<th style="font-weight:400;font-size:10px;">Intervention</th>
							<th style="font-weight:400;font-size:10px;">Status</th>
						<?php endfor; ?>
					</tr>
				</thead>
				<tbody>
					<?php if ($infantRows === []): ?>
						<tr><td colspan="29" style="color:var(--admin-muted);text-align:center;padding:24px;">No children aged 0–23 months in scope.</td></tr>
					<?php endif; ?>
					<?php foreach ($infantRows as $i => $row): ?>
						<?php
						$visits = followup_fetch_visits((int)$row['id'], $rosterVisitFrom, $rosterVisitTo, 6);
						$visitMap = [];
						foreach ($visits as $v) { $visitMap[] = $v; }
						?>
						<tr style="<?php echo !empty($row['is_flagged']) ? 'background:rgba(224,49,49,0.06);' : ''; ?>">
							<td><?php echo $i + 1; ?></td>
							<td><?php echo nutritionist_e((string)($row['purok'] ?? '')); ?></td>
							<td><?php echo nutritionist_e((string)$row['parent_name']); ?></td>
							<td>
								<div style="font-weight:600;color:var(--admin-text);"><?php echo nutritionist_e(trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? ''))); ?></div>
								<div class="admin-mini">
									<?php echo nutritionist_e((string)$row['child_code']); ?>
									<?php if (!empty($row['is_flagged'])): ?> · <span style="color:#E03131;">⚠ flagged</span><?php endif; ?>
								</div>
							</td>
							<td><?php echo nutritionist_e((string)$row['sex']); ?></td>
							<td><?php echo nutritionist_e((string)$row['birthdate']); ?></td>
							<td><?php echo $row['height_cm'] !== null ? nutritionist_e((string)$row['height_cm']) : '—'; ?></td>
							<td><?php echo $row['weight_kg'] !== null ? nutritionist_e((string)$row['weight_kg']) : '—'; ?></td>
							<td><span class="admin-pill <?php echo nutritionist_status_class($row['wfa_status'] ?? ''); ?>"><?php echo nutritionist_e((string)($row['wfa_status'] ?? 'Not measured')); ?></span></td>
							<td><span class="admin-pill <?php echo nutritionist_status_class($row['hfa_status'] ?? ''); ?>"><?php echo nutritionist_e((string)($row['hfa_status'] ?? 'Not measured')); ?></span></td>
							<td><span class="admin-pill <?php echo nutritionist_status_class($row['wfh_status'] ?? ''); ?>"><?php echo nutritionist_e((string)($row['wfh_status'] ?? 'Not measured')); ?></span></td>
							<?php for ($m = 0; $m < 6; $m++): ?>
								<?php $visit = $visitMap[$m] ?? null; ?>
								<td style="font-size:11px;"><?php echo $visit ? nutritionist_e(date('m-d-y', strtotime((string)$visit['scheduled_at']))) : ''; ?></td>
								<td style="font-size:11px;"><?php echo $visit ? nutritionist_e((string)($visit['intervention_notes'] ?? $visit['intervention_type'] ?? '')) : ''; ?></td>
								<td style="font-size:11px;"><?php echo $visit ? nutritionist_e((string)($visit['nutritional_status'] ?? '')) : ''; ?></td>
							<?php endfor; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>

	<section class="nutritionist-panel" style="margin-bottom:20px;">
		<div class="admin-section-title" style="margin-bottom:2px;">Roster 2 — Malnourished Older Children (24–59 Months)</div>
		<div class="admin-mini" style="margin-bottom:12px;">Children with abnormal WFA/HFA/WFH — followed up EVERY month until recovery · <?php echo count($malnourishedRows); ?> children</div>
		<?php
		$roster2VisitFrom = $anchorDate->modify('-5 months')->format('Y-m-d');
		$roster2VisitTo = $anchorDate->format('Y-m-d');
		?>
		<div class="nutritionist-table-wrap" style="overflow-x:auto;">
			<table class="nutritionist-table" style="min-width:1400px;">
				<thead>
					<tr>
						<th>No.</th>
						<th>Address</th>
						<th>Mother / Caregiver</th>
						<th>Full Name of Child</th>
						<th>Sex</th>
						<th>Age</th>
						<th>Height (cm)</th>
						<th>Weight (kg)</th>
						<th>WFA</th>
						<th>HFA</th>
						<th>WFH</th>
						<th>Category</th>
						<?php for ($m = 1; $m <= 6; $m++): ?>
							<th colspan="3" style="text-align:center;background:#e8f0fe;">Month #<?php echo $m; ?></th>
						<?php endfor; ?>
					</tr>
					<tr>
						<th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th>
						<?php for ($m = 1; $m <= 6; $m++): ?>
							<th style="font-weight:400;font-size:10px;">Date</th>
							<th style="font-weight:400;font-size:10px;">Intervention</th>
							<th style="font-weight:400;font-size:10px;">Status</th>
						<?php endfor; ?>
					</tr>
				</thead>
				<tbody>
					<?php if ($malnourishedRows === []): ?>
						<tr><td colspan="29" style="color:var(--admin-muted);text-align:center;padding:24px;">No malnourished children aged 24–59 months — great news.</td></tr>
					<?php endif; ?>
					<?php foreach ($malnourishedRows as $i => $row): ?>
						<?php
						$categoryCodes = implode('+', followup_abnormal_codes($row['wfa_status'] ?? null, $row['hfa_status'] ?? null, $row['wfh_status'] ?? null));
						$visits = followup_fetch_visits((int)$row['id'], $roster2VisitFrom, $roster2VisitTo, 6);
						$visitMap = [];
						foreach ($visits as $v) { $visitMap[] = $v; }
						?>
						<tr style="<?php echo !empty($row['is_flagged']) ? 'background:rgba(224,49,49,0.06);' : ''; ?>">
							<td><?php echo $i + 1; ?></td>
							<td><?php echo nutritionist_e((string)($row['purok'] ?? '')); ?></td>
							<td><?php echo nutritionist_e((string)$row['parent_name']); ?></td>
							<td>
								<div style="font-weight:600;color:var(--admin-text);"><?php echo nutritionist_e(trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? ''))); ?></div>
								<div class="admin-mini">
									<?php echo nutritionist_e((string)$row['child_code']); ?>
									<?php if (!empty($row['is_flagged'])): ?> · <span style="color:#E03131;">⚠ flagged</span><?php endif; ?>
								</div>
							</td>
							<td><?php echo nutritionist_e((string)$row['sex']); ?></td>
							<td><?php echo (int)($row['age_months'] ?? 0); ?> mo</td>
							<td><?php echo nutritionist_e((string)$row['height_cm']); ?></td>
							<td><?php echo nutritionist_e((string)$row['weight_kg']); ?></td>
							<td><?php echo nutritionist_e((string)($row['wfa_status'] ?? '—')); ?></td>
							<td><?php echo nutritionist_e((string)($row['hfa_status'] ?? '—')); ?></td>
							<td><?php echo nutritionist_e((string)($row['wfh_status'] ?? '—')); ?></td>
							<td><span class="admin-pill is-danger"><?php echo nutritionist_e(followup_category_label($categoryCodes) ?: 'At risk'); ?></span></td>
							<?php for ($m = 0; $m < 6; $m++): ?>
								<?php $visit = $visitMap[$m] ?? null; ?>
								<td style="font-size:11px;"><?php echo $visit ? nutritionist_e(date('m-d-y', strtotime((string)$visit['scheduled_at']))) : ''; ?></td>
								<td style="font-size:11px;"><?php echo $visit ? nutritionist_e((string)($visit['intervention_notes'] ?? $visit['intervention_type'] ?? '')) : ''; ?></td>
								<td style="font-size:11px;"><?php echo $visit ? nutritionist_e((string)($visit['nutritional_status'] ?? '')) : ''; ?></td>
							<?php endfor; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>

	<?php if ($needsBaselineRows !== []): ?>
		<section class="nutritionist-panel">
			<div class="admin-section-title" style="margin-bottom:2px;">Roster 3 — Older Children Without Baseline Measurement (24–59 Months)</div>
			<div class="admin-mini" style="margin-bottom:12px;">These children have never been weighed or measured. Locate them and record an OPT baseline immediately · <?php echo count($needsBaselineRows); ?> children</div>
			<div class="nutritionist-table-wrap">
				<table class="nutritionist-table">
					<thead>
						<tr>
							<th>No.</th>
							<th>Child</th>
							<th>Sex</th>
							<th>Barangay</th>
							<th>Parent / Guardian</th>
							<th>Contact</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($needsBaselineRows as $i => $row): ?>
							<tr>
								<td><?php echo $i + 1; ?></td>
								<td>
									<div style="font-weight:600;color:var(--admin-text);"><?php echo nutritionist_e(trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? ''))); ?></div>
									<div class="admin-mini"><?php echo nutritionist_e((string)$row['child_code']); ?></div>
								</td>
								<td><?php echo nutritionist_e((string)$row['sex']); ?></td>
								<td><?php echo nutritionist_e((string)($row['barangay'] ?? '')); ?></td>
								<td><?php echo nutritionist_e((string)$row['parent_name']); ?></td>
								<td><?php echo nutritionist_e((string)($row['parent_phone'] ?? '—')); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
	<?php endif; ?>

<?php else: ?>

	<section class="nutritionist-panel">
		<div class="admin-section-title" style="margin-bottom:2px;">Quarterly Round — <?php echo nutritionist_e($roundsList[$checkupMonth] . ' ' . $year); ?></div>
		<div class="admin-mini" style="margin-bottom:12px;">
			Cohort: children aged 24–59 months classified NORMAL during the Q1 (January–March <?php echo $year; ?>) OPT baseline round.
			Malnourished cases found in Q1 are excluded — they belong to the MONTHLY monitoring lists.
			· <?php echo count($quarterlyRows); ?> children in cohort · <?php echo $recheckedCount; ?> already re-checked.
		</div>
		<div class="nutritionist-table-wrap">
			<table class="nutritionist-table">
				<thead>
					<tr>
						<th>No.</th>
						<th>Child</th>
						<th>Sex</th>
						<th>Age</th>
						<th>Barangay</th>
						<th>Parent / Guardian</th>
						<th>Q1 Measured</th>
						<th>Q1 WFA</th>
						<th>Q1 HFA</th>
						<th>Q1 WFH</th>
						<th>Latest Measured</th>
						<th>Current Standing</th>
						<th>Round Compliance</th>
					</tr>
				</thead>
				<tbody>
					<?php if ($quarterlyRows === []): ?>
						<tr><td colspan="13" style="color:var(--admin-muted);text-align:center;padding:24px;">No Q1-normal children aged 24–59 months in this round.</td></tr>
					<?php endif; ?>
					<?php foreach ($quarterlyRows as $i => $row): ?>
						<?php
						$hasRecheck = !empty($row['latest_measured']) && (string)$row['latest_measured'] > $q1WindowEnd;
						$abnormalNow = followup_abnormal_codes($row['latest_wfa'] ?? null, $row['latest_hfa'] ?? null, $row['latest_wfh'] ?? null);
						$roundPassed = $anchorDate < new DateTimeImmutable('today');

						if (!$hasRecheck && $roundPassed) {
							$complianceLabel = 'Missed round';
							$complianceClass = 'is-danger';
						} elseif ($hasRecheck) {
							$complianceLabel = 'Re-checked';
							$complianceClass = 'is-success';
						} else {
							$complianceLabel = 'Awaiting re-check';
							$complianceClass = 'is-warn';
						}
						?>
						<tr>
							<td><?php echo $i + 1; ?></td>
							<td>
								<div style="font-weight:600;color:var(--admin-text);"><?php echo nutritionist_e(trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? ''))); ?></div>
								<div class="admin-mini"><?php echo nutritionist_e((string)$row['child_code']); ?></div>
							</td>
							<td><?php echo nutritionist_e((string)$row['sex']); ?></td>
							<td><?php echo nutritionist_e(followup_age_months((string)$row['birthdate'], $anchorDate)); ?> mo</td>
							<td><?php echo nutritionist_e((string)($row['barangay'] ?? '')); ?></td>
							<td><?php echo nutritionist_e((string)$row['parent_name']); ?></td>
							<td><?php echo nutritionist_e((string)($row['q1_measured'] ?? '—')); ?></td>
							<td><?php echo nutritionist_e((string)($row['q1_wfa'] ?? '—')); ?></td>
							<td><?php echo nutritionist_e((string)($row['q1_hfa'] ?? '—')); ?></td>
							<td><?php echo nutritionist_e((string)($row['q1_wfh'] ?? '—')); ?></td>
							<td><?php echo nutritionist_e((string)($row['latest_measured'] ?? '—')); ?></td>
							<td>
								<?php if (!empty($row['latest_measured'])): ?>
									<span class="admin-pill <?php echo $abnormalNow === [] ? 'is-success' : 'is-danger'; ?>">
										<?php echo nutritionist_e($abnormalNow === [] ? 'Still normal' : followup_category_label(implode('+', $abnormalNow))); ?>
									</span>
								<?php else: ?>
									<span class="admin-mini">No data</span>
								<?php endif; ?>
							</td>
							<td><span class="admin-pill <?php echo $complianceClass; ?>"><?php echo nutritionist_e($complianceLabel); ?></span></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>

<?php endif; ?>

<div class="admin-mini" style="margin-top:16px;">
	Export generates the official V2 format Excel workbook: one sheet per monitoring list (List_0_23 · List_MW · List_SW · List_MSt_SSt · List_OW_Ob · List_MUW_SUW_MSt_SSt · List_MSt_SSt_MW_SW · List_MSt_SSt_OW_Ob) plus the barangay summary — with follow-up visit columns on every sheet. Each list can also be exported on its own via the Monitoring Lists panel above, or viewed at <code>?list=MW</code>.
</div>

<?php endif; ?>

<?php
nutritionist_layout_end();

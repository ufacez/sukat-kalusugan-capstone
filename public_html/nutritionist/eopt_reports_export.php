<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/followup_scheduler.php';
require_once __DIR__ . '/../includes/xlsx_lite.php';

$statusToStyle = [
	'Normal' => 'cell_green', 'MUW' => 'cell_yellow', 'SUW' => 'cell_red',
	'MSt' => 'cell_yellow', 'SSt' => 'cell_red',
	'MW' => 'cell_yellow', 'SW' => 'cell_red',
	'OW' => 'cell_orange', 'Ob' => 'cell_orange', 'Tall' => 'cell_blue',
	'Use the WFL/H column' => 'cell_gray', 'Use WFL/H column' => 'cell_gray',
];

function xlsx_status_style(string $code): string {
	global $statusToStyle;
	return $statusToStyle[$code] ?? 'cell_center';
}

ob_start();

$user = nutritionist_require_access();

/**
 * ==========================================================================
 * EOPT REPORT EXPORT — official-format multi-sheet workbook
 * --------------------------------------------------------------------------
 * Produces one sheet per monitoring list using THE SAME grid template,
 * differing only in which children appear on it:
 *
 *   List_SUW · List_UW · List_SSt · List_St · List_SW · List_W(MW)
 *   (+ a Summary sheet with sex-disaggregated barangay counts)
 *
 * Lists are cut across the whole 0-59 month population of the selected
 * period/barangay, matching how the national eOPT Plus tool publishes its
 * per-status monitoring lists.
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
$scope = nutritionist_scope_fragment($user, 'c.barangay_id', $scopeParams) . ' AND c.status = \'active\'';

$userBarangayId = (int)($user['barangay_id'] ?? 0);
$barangayFilterSql = '';
$barangayFilterParams = [];
$barangayName = 'All barangays';

if ($userBarangayId > 0) {
	$barangayRow = admin_fetch_one('SELECT name FROM barangays WHERE id = ? LIMIT 1', 'i', [$userBarangayId]);
	$barangayName = (string)($barangayRow['name'] ?? '');
	} elseif ($barangayFilter > 0) {
	$scope .= ' AND c.barangay_id = ?';
	$scopeParams[] = $barangayFilter;
	$barangayRow = admin_fetch_one('SELECT name FROM barangays WHERE id = ? LIMIT 1', 'i', [$barangayFilter]);
	$barangayName = (string)($barangayRow['name'] ?? $barangayName);
}

/*
 |--------------------------------------------------------------------------
 | Anchor date: ages on every roster/list are evaluated at the END of the
 | reporting month (monthly view) or check-up round month (quarterly).
 |--------------------------------------------------------------------------
 */
$anchorMonth = $view === 'monthly' ? $month : $checkupMonth;
try {
	$anchorDate = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $anchorMonth)))->modify('last day of this month');
} catch (Exception) {
	$anchorDate = new DateTimeImmutable('today');
}

$anchorParam = $anchorDate->format('Y-m-d');

$monthsList = [4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];
$roundsList = [4 => 'APRIL ROUND', 7 => 'JULY ROUND', 10 => 'OCTOBER ROUND'];

$periodLabel = $view === 'monthly'
	? strtoupper($monthsList[$month] . ' ' . $year . ' MONTHLY MONITORING')
	: ($roundsList[$checkupMonth] . ' ' . $year . ' QUARTERLY CHECK-UP');

/*
 |--------------------------------------------------------------------------
 | Roster fetcher shared by every list sheet — identical columns/template,
 | only the status condition changes.
 |--------------------------------------------------------------------------
 */
function eopt_fetch_list(
	string $scope,
	array $scopeParams,
	string $conditionSql,
	string $anchorParam,
	int $ageMin = 0,
	int $ageMax = 59
): array {
	$params = array_merge([$anchorParam, $anchorParam, $anchorParam], $scopeParams, [$anchorParam]);
	$types = 'sss' . str_repeat('i', count($scopeParams)) . 's';

	return admin_fetch_all(
		"SELECT
			c.id,
			c.child_code,
			c.first_name,
			c.middle_name,
			c.last_name,
			c.sex,
			c.birthdate,
			c.is_ip,
			c.has_disability,
			la.area_name AS address,
			bg.name AS barangay,
			p.name AS parent_name,
			lm.id AS measurement_id,
			lm.measurement_date,
			DATEDIFF(?, c.birthdate) AS age_days,
			TIMESTAMPDIFF(MONTH, c.birthdate, ?) AS age_months,
			lm.height_cm,
			lm.weight_kg,
			lm.wfa_status,
			lm.hfa_status,
			lm.wfh_status,
			lm.is_flagged
		 FROM children c
		 INNER JOIN parents p ON p.id = c.parent_id
		 LEFT JOIN local_areas la ON la.id = c.local_area_id
		 LEFT JOIN barangays bg ON bg.id = c.barangay_id
		 INNER JOIN measurements lm ON lm.id = (
			SELECT m2.id FROM measurements m2
			WHERE m2.child_id = c.id AND m2.measurement_date <= ?
			ORDER BY m2.measurement_date DESC, m2.id DESC
			LIMIT 1
		 )
		 WHERE {$scope}
		   AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN {$ageMin} AND {$ageMax}
		   AND {$conditionSql}
		 ORDER BY c.last_name ASC, c.first_name ASC",
		$types,
		$params
	);
}

/*
 |--------------------------------------------------------------------------
 | The eight V2 monitoring lists — combined categories
 | Each list can also be exported on its own via &list=CODE.
 |--------------------------------------------------------------------------
 */
$listsSpec = [
	['code' => '0-23', 'sheet' => 'List_0_23', 'title' => 'CHILDREN 0-23 MONTHS OLD', 'axis' => 'All children (monthly weighing)',
	 'condition' => '1=1', 'age_min' => 0, 'age_max' => 23, 'is_infant' => true],
	['code' => 'MW', 'sheet' => 'List_MW', 'title' => 'MODERATELY WASTED', 'axis' => 'Weight-for-Height',
	 'condition' => "lm.wfh_status = 'MW'", 'age_min' => 0, 'age_max' => 59, 'is_infant' => false],
	['code' => 'SW', 'sheet' => 'List_SW', 'title' => 'SEVERELY WASTED', 'axis' => 'Weight-for-Height',
	 'condition' => "lm.wfh_status = 'SW'", 'age_min' => 0, 'age_max' => 59, 'is_infant' => false],
	['code' => 'MSt_SSt', 'sheet' => 'List_MSt_SSt', 'title' => 'MODERATELY OR SEVERELY STUNTED', 'axis' => 'Height-for-Age',
	 'condition' => "lm.hfa_status IN ('MSt','SSt')", 'age_min' => 0, 'age_max' => 59, 'is_infant' => false],
	['code' => 'OW_Ob', 'sheet' => 'List_OW_Ob', 'title' => 'OVERWEIGHT OR OBESE', 'axis' => 'Weight-for-Age / Weight-for-Height',
	 'condition' => "(lm.wfa_status = 'OW' OR lm.wfh_status IN ('OW','Ob'))", 'age_min' => 0, 'age_max' => 59, 'is_infant' => false],
	['code' => 'MUW_SUW_MSt_SSt', 'sheet' => 'List_MUW_SUW_MSt_SSt', 'title' => 'MODERATELY/SEVERELY UNDERWEIGHT + MODERATELY/SEVERELY STUNTED', 'axis' => 'Weight-for-Age + Height-for-Age',
	 'condition' => "(lm.wfa_status IN ('MUW','SUW') AND lm.hfa_status IN ('MSt','SSt'))", 'age_min' => 0, 'age_max' => 59, 'is_infant' => false],
	['code' => 'MSt_SSt_MW_SW', 'sheet' => 'List_MSt_SSt_MW_SW', 'title' => 'MODERATELY/SEVERELY STUNTED + MODERATELY/SEVERELY WASTED', 'axis' => 'Height-for-Age + Weight-for-Height',
	 'condition' => "(lm.hfa_status IN ('MSt','SSt') AND lm.wfh_status IN ('MW','SW'))", 'age_min' => 0, 'age_max' => 59, 'is_infant' => false],
	['code' => 'MSt_SSt_OW_Ob', 'sheet' => 'List_MSt_SSt_OW_Ob', 'title' => 'MODERATELY/SEVERELY STUNTED + OVERWEIGHT OR OBESE', 'axis' => 'Height-for-Age + Weight-for-Height',
	 'condition' => "(lm.hfa_status IN ('MSt','SSt') AND (lm.wfa_status = 'OW' OR lm.wfh_status IN ('OW','Ob')))", 'age_min' => 0, 'age_max' => 59, 'is_infant' => false],
];

/*
 | Single-list export mode: &list=0-23 exports ONLY that one list sheet
 | (same template), skipping the Summary sheet. Case-insensitive so
 | codes like mst_sst work regardless of URL casing. Unknown codes fall
 | back to the full multi-sheet workbook.
 */
$listParamRaw = trim((string)($_GET['list'] ?? ''));
$reportParam = strtolower(trim((string)($_GET['report'] ?? '')));
$isNutStatus = $reportParam === 'nutstatus';
$isNutStatusBrgy = $reportParam === 'nutstatusbrgy';
$isForm1A = $reportParam === 'form1a';
$isForm1B = $reportParam === 'form1b';
$isForm1C = $reportParam === 'form1c';
$exportFormat = strtolower(trim((string)($_GET['format'] ?? 'xlsx')));
$listParamKey = strtolower($listParamRaw);
$codeLowerMap = [];
foreach ($listsSpec as $spec) {
	$codeLowerMap[strtolower($spec['code'])] = $spec;
}
$isSingleList = $listParamKey !== '' && isset($codeLowerMap[$listParamKey]);
$listParam = $isSingleList ? $codeLowerMap[$listParamKey]['code'] : $listParamRaw;
$activeSpecs = $isNutStatus || $isNutStatusBrgy ? [] : ($isSingleList ? [$codeLowerMap[$listParamKey]] : $listsSpec);
if ($isForm1A || $isForm1B || $isForm1C) {
	$activeSpecs = [];
}
if ($isForm1A) {
	$activeSpecs = [[
		'code' => 'form1a',
		'sheet' => 'Form_1A',
		'title' => 'PRE-PRINTED LIST OF PRESCHOOL CHILDREN IN THE BARANGAY',
		'axis' => 'Weight-for-Length/Height nutritional status',
		'condition' => '1=1',
		'age_min' => 0,
		'age_max' => 59,
		'is_infant' => false,
	]];
}

$listColumns = ['No.', 'Address', 'Mother/Caregiver', 'Full Name of Child', 'Sex', 'Birthdate', 'Height (cm)', 'Weight (kg)', 'WFA', 'HFA', 'WFH'];
$listWidths = [6, 18, 26, 38, 9, 14, 11, 11, 9, 9, 9];

$nutStatusColumns = [
	'Child ID',
	'Address or Location of Child\'s Residence',
	'Name of Mother or Guardian',
	'Full Name of Child',
	'Belongs to IP Group?',
	'Sex',
	'Date of Birth',
	'Date of Measurement',
	'Weight (kg)',
	'Height (cm)',
	'Age in Months',
	'Age in Days',
	'Weight-for-Age Status',
	'Height/Length-for-Age Status',
	'Weight-for-Length/Height Status',
	'Disability',
];
$nutStatusWidths = [14, 28, 26, 32, 14, 9, 14, 16, 12, 12, 12, 12, 18, 22, 24, 12];

if ($isForm1A) {
	$listColumns = ['Child ID', 'Address / Location', 'Mother / Guardian', 'Full Name of Child', 'IP?', 'Sex', 'Date of Birth', 'Date of Measurement', 'Weight (kg)', 'Height (cm)', 'Age in Months', 'Age in Days', 'Nutritional Status (WFL/H)', 'Disability'];
	$listWidths = [14, 25, 29, 42, 10, 10, 17, 20, 16, 16, 15, 15, 32, 15];
}

$sheets = [];

if ($isNutStatusBrgy) {
	$summaryRows = admin_fetch_all(
		"SELECT c.birthdate, c.sex, c.parent_id, m.wfa_status, m.hfa_status, m.wfh_status
		 FROM children c
		 INNER JOIN measurements m ON m.id = (
			SELECT m2.id FROM measurements m2
			WHERE m2.child_id = c.id AND m2.measurement_date <= ?
			ORDER BY m2.measurement_date DESC, m2.id DESC LIMIT 1
		 )
		 WHERE {$scope}",
		's' . str_repeat('i', count($scopeParams)),
		array_merge([$anchorParam], $scopeParams)
	);
	$definitions = [
		'WFA' => ['Normal' => 'Normal', 'MUW' => 'Moderately Underweight', 'SUW' => 'Severely Underweight'],
		'HFA' => ['Normal' => 'Normal', 'Tall' => 'Tall', 'MSt' => 'Moderately Stunted', 'SSt' => 'Severely Stunted'],
		'WFL/H' => ['Normal' => 'Normal', 'OW' => 'Overweight', 'Ob' => 'Obese', 'MW' => 'Moderately Wasted / MAM', 'SW' => 'Severely Wasted / SAM'],
	];
	$summary = [];
	$denominators = [];
	foreach ($definitions as $axis => $statuses) {
		foreach ($statuses as $code => $_label) {
			$summary[$axis][$code] = ['0-23' => ['Boys' => 0, 'Girls' => 0], '0-59' => ['Boys' => 0, 'Girls' => 0]];
		}
		$denominators[$axis] = ['0-23' => 0, '0-59' => 0];
	}
	$caregivers = ['0-23' => [], '0-59' => []];
	foreach ($summaryRows as $row) {
		try {
			$ageDiff = (new DateTimeImmutable((string)$row['birthdate']))->diff($anchorDate);
			$ageMonths = ($ageDiff->y * 12) + $ageDiff->m;
		} catch (Exception) { continue; }
		if ($ageMonths < 0 || $ageMonths > 59) continue;
		$sex = (string)$row['sex'] === 'Male' ? 'Boys' : 'Girls';
		$groups = ['0-59'];
		if ($ageMonths <= 23) $groups[] = '0-23';
		foreach ([['WFA', $row['wfa_status']], ['HFA', $row['hfa_status']], ['WFL/H', $row['wfh_status']]] as [$axis, $status]) {
			if ($status === null || $status === '') continue;
			foreach ($groups as $group) {
				$denominators[$axis][$group]++;
				if (isset($summary[$axis][$status])) $summary[$axis][$status][$group][$sex]++;
			}
		}
		if (in_array($row['wfa_status'], ['MUW', 'SUW'], true) || in_array($row['hfa_status'], ['MSt', 'SSt'], true) || in_array($row['wfh_status'], ['MW', 'SW'], true)) {
			$caregivers['0-59'][(int)$row['parent_id']] = true;
			if ($ageMonths <= 23) $caregivers['0-23'][(int)$row['parent_id']] = true;
		}
	}
	$rowsOut = [];
	$addRow = static function (array &$output, array $values, string $style = 'default'): void {
		$output[] = array_map(static fn($value) => ['v' => $value, 's' => $style], $values);
	};
	$addRow($rowsOut, ['NUTSTATUSBRGY - NUTRITIONAL STATUS OF CHILDREN 0-23 AND 0-59 MONTHS OLD', '', '', '', '', '', '', '', ''], 'title');
	$addRow($rowsOut, ['Year:', $year, '', 'Barangay:', $barangayName, '', 'Municipality/City:', 'City of San Fernando', 'Province: Pampanga'], 'label');
	$addRow($rowsOut, ['Region:', 'III - Central Luzon', '', 'PSGC:', 'Not configured', '', 'Period:', $periodLabel, ''], 'label');
	$addRow($rowsOut, ['Total children assessed:', count($summaryRows), '', '0-59 caregivers affected:', count($caregivers['0-59']), '', '0-23 caregivers affected:', count($caregivers['0-23']), ''], 'label');
	$addRow($rowsOut, array_fill(0, 9, ''));
	foreach ($summary as $axis => $statuses) {
		$addRow($rowsOut, [$axis === 'HFA' ? 'HEIGHT / LENGTH FOR AGE' : ($axis === 'WFL/H' ? 'WEIGHT FOR LENGTH / HEIGHT' : 'WEIGHT FOR AGE'), '', '', '', '', '', '', '', ''], 'label');
		$addRow($rowsOut, ['Classification', '0-23 Boys', '0-23 Girls', '0-23 Total', '0-23 Prev', '0-59 Boys', '0-59 Girls', '0-59 Total', '0-59 Prev'], 'header');
		foreach ($statuses as $code => $label) {
			$early = $summary[$axis][$code]['0-23'];
			$all = $summary[$axis][$code]['0-59'];
			$earlyTotal = $early['Boys'] + $early['Girls'];
			$allTotal = $all['Boys'] + $all['Girls'];
			$addRow($rowsOut, [$label, $early['Boys'], $early['Girls'], $earlyTotal, $denominators[$axis]['0-23'] > 0 ? number_format($earlyTotal / $denominators[$axis]['0-23'] * 100, 2) . '%' : '0.00%', $all['Boys'], $all['Girls'], $allTotal, $denominators[$axis]['0-59'] > 0 ? number_format($allTotal / $denominators[$axis]['0-59'] * 100, 2) . '%' : '0.00%']);
		}
		$addRow($rowsOut, array_fill(0, 9, ''));
	}
	$addRow($rowsOut, ['Total Number of Mothers/Caregivers of Children 0-59 Months Affected by Undernutrition', count($caregivers['0-59']), '', '', '', '', '', '', ''], 'label');
	$addRow($rowsOut, ['Total Number of Mothers/Caregivers of Children 0-23 Months Affected by Undernutrition', count($caregivers['0-23']), '', '', '', '', '', '', ''], 'label');
	$sheets[] = ['name' => 'NutStatusBrgy', 'widths' => [38, 13, 13, 13, 14, 13, 13, 13, 14], 'merges' => ['A1:I1'], 'rows' => $rowsOut];
}

if ($isForm1C) {
	$form1cRows = admin_fetch_all(
		"SELECT
			c.first_name, c.middle_name, c.last_name, c.sex, c.birthdate,
			la.area_name AS address, p.name AS parent_name,
			lm.wfa_status, lm.hfa_status, lm.wfh_status,
			TIMESTAMPDIFF(MONTH, c.birthdate, ?) AS age_months
		 FROM children c
		 INNER JOIN parents p ON p.id = c.parent_id
		 LEFT JOIN local_areas la ON la.id = c.local_area_id
		 INNER JOIN measurements lm ON lm.id = (
			SELECT m2.id FROM measurements m2
			WHERE m2.child_id = c.id AND m2.measurement_date <= ?
			ORDER BY m2.measurement_date DESC, m2.id DESC LIMIT 1
		 )
		 WHERE {$scope}
		   AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 59
		   AND (lm.wfa_status IN ('SUW','MUW') OR lm.hfa_status IN ('SSt','MSt') OR lm.wfh_status IN ('SW','MW','OW','Ob'))
		 ORDER BY c.last_name ASC, c.first_name ASC, c.middle_name ASC",
		'ss' . str_repeat('i', count($scopeParams)) . 's',
		array_merge([$anchorParam, $anchorParam], $scopeParams, [$anchorParam])
	);
	$form1cCounts = ['MUW' => 0, 'SUW' => 0, 'MSt' => 0, 'SSt' => 0, 'MW/MAM' => 0, 'SW/SAM' => 0, 'OW' => 0, 'Ob' => 0, 'undernutrition' => 0, 'overweight' => 0];
	foreach ($form1cRows as $row) {
		foreach ([['MUW', $row['wfa_status'] === 'MUW'], ['SUW', $row['wfa_status'] === 'SUW'], ['MSt', $row['hfa_status'] === 'MSt'], ['SSt', $row['hfa_status'] === 'SSt'], ['MW/MAM', $row['wfh_status'] === 'MW'], ['SW/SAM', $row['wfh_status'] === 'SW'], ['OW', $row['wfh_status'] === 'OW'], ['Ob', $row['wfh_status'] === 'Ob']] as [$key, $matches]) {
			if ($matches) $form1cCounts[$key]++;
		}
		if (in_array($row['wfa_status'], ['MUW', 'SUW'], true) || in_array($row['hfa_status'], ['MSt', 'SSt'], true) || in_array($row['wfh_status'], ['MW', 'SW'], true)) $form1cCounts['undernutrition']++;
		if (in_array($row['wfh_status'], ['OW', 'Ob'], true)) $form1cCounts['overweight']++;
	}
	$form1cRowsOut = [];
	$addForm1cRow = static function (array &$output, array $values, string $style = 'default'): void {
		$output[] = array_map(static fn($value) => ['v' => $value, 's' => $style], $values);
	};
	$addForm1cRow($form1cRowsOut, ['OPT PLUS FORM 1C: LIST OF AFFECTED / AT-RISK 0-59 MONTH-OLD CHILDREN', '', '', '', '', '', '', ''], 'title');
	$addForm1cRow($form1cRowsOut, ['Year:', $year, '', 'Region:', 'III - Central Luzon', '', 'Province:', 'Pampanga'], 'label');
	$addForm1cRow($form1cRowsOut, ['Barangay:', $barangayName, '', 'Municipality/City:', 'City of San Fernando', '', 'Period:', $periodLabel], 'label');
	$addForm1cRow($form1cRowsOut, ['Total affected/at-risk:', count($form1cRows), '', 'MUW:', $form1cCounts['MUW'], 'SUW:', $form1cCounts['SUW'], 'MSt: ' . $form1cCounts['MSt']], 'label');
	$addForm1cRow($form1cRowsOut, ['SSt:', $form1cCounts['SSt'], '', 'MW/MAM:', $form1cCounts['MW/MAM'], 'SW/SAM:', $form1cCounts['SW/SAM'], 'OW/Ob:', $form1cCounts['OW'] + $form1cCounts['Ob']], 'label');
	$addForm1cRow($form1cRowsOut, ['Affected by undernutrition:', $form1cCounts['undernutrition'], '', 'Overweight or obesity:', $form1cCounts['overweight'], '', '', ''], 'label');
	$addForm1cRow($form1cRowsOut, array_fill(0, 8, ''));
	$addForm1cRow($form1cRowsOut, ['Address / Purok / Local Area', 'Mother / Caregiver', 'Full Name of Child', 'Sex', 'Age in Months', 'WFA', 'HFA', 'WFL/H'], 'header');
	foreach ($form1cRows as $row) {
		$fullName = trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? ''));
		$wfa = $row['wfa_status'] === 'Refer to WFL/H' ? 'Use the WFL/H column' : (string)($row['wfa_status'] ?? 'Normal');
		$hfa = (string)($row['hfa_status'] ?? 'Normal');
		$wfh = (string)($row['wfh_status'] ?? 'Normal');
		$form1cRowsOut[] = [
			['v' => (string)($row['address'] ?? ''), 's' => 'cell'],
			['v' => (string)($row['parent_name'] ?? ''), 's' => 'cell'],
			['v' => $fullName, 's' => 'cell'],
			['v' => (string)$row['sex'], 's' => 'cell_center'],
			['v' => (int)$row['age_months'], 's' => 'cell_center'],
			['v' => $wfa, 's' => xlsx_status_style($wfa)],
			['v' => $hfa, 's' => xlsx_status_style($hfa)],
			['v' => $wfh, 's' => xlsx_status_style($wfh)],
		];
	}
	$sheets[] = [
		'name' => 'Form_1C',
		'widths' => [28, 28, 38, 10, 14, 24, 20, 22],
		'merges' => ['A1:H1'],
		'rows' => $form1cRowsOut,
	];
}

if ($isForm1B) {
	$form1bRows = admin_fetch_all(
		"SELECT c.id, c.parent_id, c.birthdate, c.sex, c.is_ip, c.has_disability, m.wfa_status, m.hfa_status, m.wfh_status
		 FROM children c
		 INNER JOIN measurements m ON m.id = (
			SELECT m2.id FROM measurements m2
			WHERE m2.child_id = c.id
			ORDER BY m2.measurement_date DESC, m2.id DESC
		 )
		 WHERE {$scope}",
		str_repeat('i', count($scopeParams)),
		$scopeParams
	);

	$ageGroups = ['0-5' => [0, 5], '6-11' => [6, 11], '12-23' => [12, 23], '24-35' => [24, 35], '36-47' => [36, 47], '48-59' => [48, 59]];
	$statusGroups = [
		'WFA' => ['Normal' => 'Normal', 'Ob' => 'Ob', 'OW' => 'OW', 'MUW' => 'MUW', 'SUW' => 'SUW'],
		'HFA' => ['Normal' => 'Normal', 'Tall' => 'Tall', 'MSt' => 'MSt', 'SSt' => 'SSt'],
		'WFL/H' => ['Normal' => 'Normal', 'OW' => 'OW', 'Ob' => 'Ob', 'MW' => 'MW/MAM', 'SW' => 'SW/SAM'],
	];
	$form1bSummary = [];
	foreach ($statusGroups as $axis => $statuses) {
		foreach ($statuses as $code => $_label) {
			$form1bSummary[$axis][$code] = [
				'Boys' => 0, 'Girls' => 0, 'Total' => 0,
				'ages' => array_fill_keys(array_keys($ageGroups), 0),
				'age_sex' => array_fill_keys(array_keys($ageGroups), ['Boys' => 0, 'Girls' => 0]),
				'ip_boys' => 0, 'ip_girls' => 0,
			];
		}
	}
	$totalAssessed = 0;
	$f1kTotal = 0;
	$disabilityCount = 0;
	$ipCount = 0;
	$f1bWsChildren59 = [];
	$f1bWsChildren2459 = [];
	$f1bOwObChildren59 = [];
	$f1bWsChildren23 = [];
	$f1bAgeCount029 = 0;
	$f1bAgeCount3059 = 0;
	$f1bAgeCount2459 = 0;
	$f1bMcIds59 = [];
	$f1bMcWsIds59 = [];
	$f1bMcOwObIds59 = [];
	$f1bMcIds23 = [];
	$f1bMcWsIds23 = [];
	$anchor = $anchorDate;
	foreach ($form1bRows as $row) {
		try {
			$birthdate = new DateTimeImmutable((string)$row['birthdate']);
			$age = $birthdate->diff($anchor);
			$ageMonths = ($age->y * 12) + $age->m;
		} catch (Exception) {
			continue;
		}
		if ($ageMonths < 0 || $ageMonths > 59) {
			continue;
		}
		$totalAssessed++;
		if ($ageMonths <= 23) {
			$f1kTotal++;
		}
		$sex = (string)$row['sex'] === 'Male' ? 'Boys' : 'Girls';
		$ageGroup = '48-59';
		foreach ($ageGroups as $group => [$min, $max]) {
			if ($ageMonths >= $min && $ageMonths <= $max) {
				$ageGroup = $group;
				break;
			}
		}
		foreach ([['WFA', $row['wfa_status']], ['HFA', $row['hfa_status']], ['WFL/H', $row['wfh_status']]] as [$axis, $code]) {
			if (isset($form1bSummary[$axis][$code])) {
				$form1bSummary[$axis][$code][$sex]++;
				$form1bSummary[$axis][$code]['Total']++;
				$form1bSummary[$axis][$code]['ages'][$ageGroup]++;
				$form1bSummary[$axis][$code]['age_sex'][$ageGroup][$sex]++;
				if (!empty($row['is_ip'])) {
					$form1bSummary[$axis][$code]['ip_' . strtolower($sex)]++;
				}
			}
		}
		if (!empty($row['has_disability'])) {
			$disabilityCount++;
		}
		if (!empty($row['is_ip'])) {
			$ipCount++;
		}
		$childId = (int)$row['id'];
		$parentId = (int)$row['parent_id'];
		$isWs = in_array($row['wfh_status'], ['MW', 'SW'], true) || in_array($row['hfa_status'], ['MSt', 'SSt'], true);
		$isOwOb = in_array($row['wfh_status'], ['OW', 'Ob'], true);
		$f1bMcIds59[$parentId] = true;
		if ($isWs) { $f1bWsChildren59[$childId] = true; $f1bMcWsIds59[$parentId] = true; }
		if ($isOwOb) { $f1bOwObChildren59[$childId] = true; $f1bMcOwObIds59[$parentId] = true; }
		if ($ageMonths >= 24 && $ageMonths <= 59) {
			$f1bAgeCount2459++;
			if ($isWs) { $f1bWsChildren2459[$childId] = true; }
		}
		if ($ageMonths <= 29) { $f1bAgeCount029++; } else { $f1bAgeCount3059++; }
		if ($ageMonths <= 23) {
			$f1bMcIds23[$parentId] = true;
			if ($isWs) { $f1bWsChildren23[$childId] = true; $f1bMcWsIds23[$parentId] = true; }
		}
	}

	$form1bOutput = [];
	$addForm1bRow = static function (array &$output, array $values, string $style = 'default'): void {
		$output[] = array_map(static fn($value) => ['v' => $value, 's' => $style], $values);
	};
	$addForm1bRow($form1bOutput, ['OPT PLUS FORM 1B: SUMMARY SHEET OF NUTRITIONAL STATUS', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''], 'title');
	$addForm1bRow($form1bOutput, ['Barangay:', $barangayName, '', 'Municipality:', 'City of San Fernando', '', 'Province:', 'Pampanga', '', 'Total assessed:', $totalAssessed, '', 'IP children:', $ipCount, '', 'Disability:', $disabilityCount, '', 'Coverage:', '0-59 months', '', '', '', '', '', ''], 'label');
	$addForm1bRow($form1bOutput, array_fill(0, 26, ''));

	$ageGroupLabels = ['0-5', '6-11', '12-23', '24-35', '36-47', '48-59'];
	$subHeaders = ['Boys', 'Girls', 'Total'];

	$row1 = ['ACRONYMS & ABBREVIATIONS'];
	foreach ($ageGroupLabels as $gl) {
		$row1[] = $gl . ' Months';
		for ($pad = 1; $pad < 3; $pad++) {
			$row1[] = '';
		}
	}
	$row1[] = 'Birth to 5 Years (0-59 Months)';
	$row1[] = '';
	$row1[] = 'F1K (0-23 Months)';
	$row1[] = '';
	$row1[] = '# IP Children';
	$row1[] = '';
	$row1[] = '';
	$addForm1bRow($form1bOutput, $row1, 'header');

	$row2 = [''];
	foreach ($ageGroupLabels as $gl) {
		foreach ($subHeaders as $sh) {
			$row2[] = $sh;
		}
	}
	$row2[] = 'Total';
	$row2[] = 'Prev';
	$row2[] = 'Total';
	$row2[] = 'Prev';
	$row2[] = 'Boys';
	$row2[] = 'Girls';
	$row2[] = 'Total';
	$addForm1bRow($form1bOutput, $row2, 'header');

	$owMessage = 'No Obese/Overweight classification in the WFA. Following international standards, we use WL/HZ to classify overweight and obesity in children.';
	$dataRowIndex = 0;
	$owMsgRowNum = null;
	foreach ($form1bSummary as $axis => $summaryTable) {
		foreach ($summaryTable as $code => $counts) {
			$label = $axis . ' - ' . $statusGroups[$axis][$code];

			if ($axis === 'WFA' && ($code === 'Ob' || $code === 'OW')) {
				if ($code === 'OW') {
					$msgRow = ['', $owMessage];
					while (count($msgRow) < 26) {
						$msgRow[] = '';
					}
					$addForm1bRow($form1bOutput, $msgRow, 'cell_center');
					$owMsgRowNum = count($form1bOutput);
					$dataRowIndex++;
				}
				continue;
			}

			$zeroToTwentyThree = array_sum(array_slice($counts['ages'], 0, 3));
			$ipTotal = $counts['ip_boys'] + $counts['ip_girls'];
			$values = [$label];
			foreach ($ageGroupLabels as $group) {
				$values[] = $counts['age_sex'][$group]['Boys'];
				$values[] = $counts['age_sex'][$group]['Girls'];
				$values[] = $counts['ages'][$group];
			}
			$values[] = $counts['Total'];
			$values[] = $totalAssessed > 0 ? number_format(($counts['Total'] / $totalAssessed) * 100, 1) . '%' : '0.0%';
			$values[] = $zeroToTwentyThree;
			$values[] = $f1kTotal > 0 ? number_format(($zeroToTwentyThree / $f1kTotal) * 100, 1) . '%' : '0.0%';
			$values[] = $counts['ip_boys'];
			$values[] = $counts['ip_girls'];
			$values[] = $ipTotal;
			$addForm1bRow($form1bOutput, $values);
			$dataRowIndex++;
		}
	}

	$addForm1bRow($form1bOutput, array_fill(0, 26, ''));
	$addForm1bRow($form1bOutput, ['SUMMARY', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''], 'label');
	$addForm1bRow($form1bOutput, ['Summary of Children covered by e-OPT Plus', '', '', '', 'Mothers/Caregivers Summary', '', '', '', 'Data Inaccuracy', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''], 'header');
	$addForm1bRow($form1bOutput, ['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '']);

	$qualitySource = admin_fetch_all(
		"SELECT c.first_name, c.last_name, c.birthdate, c.sex, c.local_area_id,
			p.name AS parent_name, p.address AS parent_address, m.height_cm, m.weight_kg
		 FROM children c
		 LEFT JOIN parents p ON p.id = c.parent_id
		 LEFT JOIN measurements m ON m.id = (
			SELECT m2.id FROM measurements m2 WHERE m2.child_id = c.id
			ORDER BY m2.measurement_date DESC, m2.id DESC LIMIT 1
		 )
		 WHERE {$scope}",
		str_repeat('i', count($scopeParams)),
		$scopeParams
	);
	$duplicateKeys = [];
	$missingInfo = $noParentAddr = $noSex = $noDob = $older59 = $htNoWt = $wtNoHt = 0;
	foreach ($qualitySource as $qr) {
		$key = strtolower(trim((string)$qr['first_name'] . '|' . (string)$qr['last_name'] . '|' . (string)$qr['birthdate']));
		$duplicateKeys[$key] = ($duplicateKeys[$key] ?? 0) + 1;
		if (trim((string)$qr['first_name']) === '' || trim((string)$qr['last_name']) === '' || trim((string)$qr['birthdate']) === '') { $missingInfo++; }
		if (trim((string)$qr['parent_name']) === '' || ((int)($qr['local_area_id'] ?? 0) === 0 && trim((string)$qr['parent_address']) === '')) { $noParentAddr++; }
		if (trim((string)$qr['sex']) === '') { $noSex++; }
		try {
			$qAge = (new DateTimeImmutable((string)$qr['birthdate']))->diff($anchor);
			if (($qAge->y * 12) + $qAge->m > 59) { $older59++; }
		} catch (Exception) { $noDob++; }
		if ($qr['height_cm'] !== null && $qr['weight_kg'] === null) { $htNoWt++; }
		if ($qr['weight_kg'] !== null && $qr['height_cm'] === null) { $wtNoHt++; }
	}
	$repeatedChildren = count(array_filter($duplicateKeys, static fn($c) => $c > 1));

	$f1bSumChildren = [
		'ws59' => count($f1bWsChildren59),
		'ws2459' => count($f1bWsChildren2459),
		'owOb59' => count($f1bOwObChildren59),
		'total23' => $f1kTotal,
		'ws23' => count($f1bWsChildren23),
		'age029' => $f1bAgeCount029,
		'age3059' => $f1bAgeCount3059,
		'age2459' => $f1bAgeCount2459,
	];
	$f1bSumMC = [
		'total59' => count($f1bMcIds59),
		'ws59' => count($f1bMcWsIds59),
		'owOb59' => count($f1bMcOwObIds59),
		'total23' => count($f1bMcIds23),
		'ws23' => count($f1bMcWsIds23),
	];

	$summaryRows = [
		['# Children 0-59 mos. Wasted/Stunted', $f1bSumChildren['ws59'], '', 'Total Number of M/Cs 0-59 mos. old', $f1bSumMC['total59'], '', '# Children with names and birthdate repeated', $repeatedChildren],
		['# Children 24-59 mos. Wasted/Stunted', $f1bSumChildren['ws2459'], '', '# M/Cs of 0-59 mos. affected by W/S', $f1bSumMC['ws59'], '', '# Children with missing information', $missingInfo],
		['# Children 0-59 mos. Overweight/Obese', $f1bSumChildren['owOb59'], '', '# M/Cs of 0-59 mos. Overweight/Obese', $f1bSumMC['owOb59'], '', '# Children with no parent/address', $noParentAddr],
		['Total Children 0-23 mos.', $f1bSumChildren['total23'], '', 'Total M/Cs 0-23 mos.', $f1bSumMC['total23'], '', '# Children with no sex', $noSex],
		['# Children 0-23 mos. Wasted/Stunted', $f1bSumChildren['ws23'], '', '# M/Cs 0-23 mos. affected by W/S', $f1bSumMC['ws23'], '', '# Children with no DOB', $noDob],
		['Children 0-29 mos.', $f1bSumChildren['age029'], '', '', '', '', '# Children >59 mos.', $older59],
		['Children 30-59 mos.', $f1bSumChildren['age3059'], '', '', '', '', '# Length/height no weight', $htNoWt],
		['Children 24-59 mos.', $f1bSumChildren['age2459'], '', '', '', '', '# Weight no ht/length', $wtNoHt],
	];
	foreach ($summaryRows as $sr) {
		$padded = $sr;
		while (count($padded) < 26) { $padded[] = ''; }
		$addForm1bRow($form1bOutput, $padded);
	}

	$form1bColCount = 26;
	$form1bLastCol = '';
	$ci = $form1bColCount;
	while ($ci > 0) {
		$ci--;
		$form1bLastCol = chr(65 + ($ci % 26)) . $form1bLastCol;
		$ci = intdiv($ci, 26);
	}

	// Actual sheet layout: row 1 title, row 2 meta, row 3 blank,
	// row 4 header group labels, row 5 sub-headers, row 6+ data.
	$form1bTitleRow = 1;
	$form1bHeaderRow1 = 4;
	$form1bHeaderRow2 = 5;
	$form1bFirstDataRow = 6;

	$merges = [
		"A{$form1bTitleRow}:{$form1bLastCol}{$form1bTitleRow}",
		"A{$form1bHeaderRow1}:A{$form1bHeaderRow2}",
	];

	$colPos = 2;
	foreach ($ageGroupLabels as $gl) {
		$startCol = '';
		$ci = $colPos;
		while ($ci > 0) { $ci--; $startCol = chr(65 + ($ci % 26)) . $startCol; $ci = intdiv($ci, 26); }
		$endCol = '';
		$ci = $colPos + 2;
		while ($ci > 0) { $ci--; $endCol = chr(65 + ($ci % 26)) . $endCol; $ci = intdiv($ci, 26); }
		$merges[] = "{$startCol}{$form1bHeaderRow1}:{$endCol}{$form1bHeaderRow1}";
		$colPos += 3;
	}
	for ($g = 0; $g < 2; $g++) {
		$startCol = '';
		$ci = $colPos;
		while ($ci > 0) { $ci--; $startCol = chr(65 + ($ci % 26)) . $startCol; $ci = intdiv($ci, 26); }
		$endCol = '';
		$ci = $colPos + 1;
		while ($ci > 0) { $ci--; $endCol = chr(65 + ($ci % 26)) . $endCol; $ci = intdiv($ci, 26); }
		$merges[] = "{$startCol}{$form1bHeaderRow1}:{$endCol}{$form1bHeaderRow1}";
		$colPos += 2;
	}
	$startCol = '';
	$ci = $colPos;
	while ($ci > 0) { $ci--; $startCol = chr(65 + ($ci % 26)) . $startCol; $ci = intdiv($ci, 26); }
	$endCol = '';
	$ci = $colPos + 2;
	while ($ci > 0) { $ci--; $endCol = chr(65 + ($ci % 26)) . $endCol; $ci = intdiv($ci, 26); }
	$merges[] = "{$startCol}{$form1bHeaderRow1}:{$endCol}{$form1bHeaderRow1}";

	// Merge the OW notice row across the data columns (tracked at emit time
	// so it always lands on the message row, never on a data row).
	if ($owMsgRowNum !== null) {
		$merges[] = "B{$owMsgRowNum}:{$form1bLastCol}{$owMsgRowNum}";
	}

	$sheets[] = [
		'name' => 'Form_1B',
		'widths' => array_merge([22], array_fill(0, 18, 7), array_fill(0, 4, 9), array_fill(0, 3, 7)),
		'merges' => $merges,
		'rows' => $form1bOutput,
	];
}

if ($isNutStatus) {
	$nutStatusRows = admin_fetch_all(
		"SELECT
			c.child_code,
			c.first_name,
			c.middle_name,
			c.last_name,
			c.sex,
			c.birthdate,
			c.is_ip,
			c.has_disability,
			la.area_name AS address,
			p.name AS parent_name,
			lm.measurement_date,
			lm.height_cm,
			lm.weight_kg,
			lm.wfa_status,
			lm.hfa_status,
			lm.wfh_status,
			DATEDIFF(?, c.birthdate) AS age_days,
			TIMESTAMPDIFF(MONTH, c.birthdate, ?) AS age_months
		 FROM children c
		 INNER JOIN parents p ON p.id = c.parent_id
		 LEFT JOIN local_areas la ON la.id = c.local_area_id
		 INNER JOIN measurements lm ON lm.id = (
			SELECT m2.id FROM measurements m2
			WHERE m2.child_id = c.id
			ORDER BY m2.measurement_date DESC, m2.id DESC
			LIMIT 1
		 )
		 WHERE {$scope}
		   AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 59
		 ORDER BY c.last_name ASC, c.first_name ASC",
		'ss' . str_repeat('i', count($scopeParams)) . 's',
		array_merge([$anchorParam, $anchorParam], $scopeParams, [$anchorParam])
	);

	$outRows = [];
	$titleLines = [
		'COMMUNITY LEVEL e-OPT PLUS TOOL',
		'WEIGHT FOR AGE, HEIGHT FOR AGE, WEIGHT FOR LENGTH/HEIGHT STATUS',
		'REGION: III - CENTRAL LUZON',
		'PROVINCE: PAMPANGA',
		'MUNICIPALITY/CITY: CITY OF SAN FERNANDO',
		'YEAR: ' . $year,
		'BARANGAY: ' . strtoupper($barangayName),
	];

	foreach ($titleLines as $lineIdx => $lineText) {
		$styleKey = $lineIdx < 2 ? 'title' : ($lineIdx === 5 ? 'subtitle' : 'org');
		$rowCells = [];
		foreach ($nutStatusColumns as $columnIdx => $_columnTitle) {
			$rowCells[] = ['v' => $columnIdx === 0 ? $lineText : '', 's' => $styleKey];
		}
		$outRows[] = $rowCells;
	}

	$outRows[] = array_map(static fn($value) => ['v' => $value], array_fill(0, count($nutStatusColumns), ''));
	$outRows[] = array_map(static fn($value) => ['v' => $value, 's' => 'header'], $nutStatusColumns);

	foreach ($nutStatusRows as $row) {
		$fullName = trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? ''));
		$wfaStatus = (string)($row['wfa_status'] ?? '');
		if ($wfaStatus === 'Refer to WFL/H') {
			$wfaStatus = 'Use the WFL/H column';
		}

		$outRows[] = [
			['v' => (string)($row['child_code'] ?? ''), 's' => 'cell'],
			['v' => (string)($row['address'] ?? ''), 's' => 'cell'],
			['v' => (string)($row['parent_name'] ?? ''), 's' => 'cell'],
			['v' => $fullName, 's' => 'cell'],
			['v' => !empty($row['is_ip']) ? 'YES' : 'NO', 's' => 'cell_center'],
			['v' => (string)($row['sex'] ?? ''), 's' => 'cell_center'],
			['v' => (string)($row['birthdate'] ?? ''), 's' => 'cell_center'],
			['v' => (string)($row['measurement_date'] ?? ''), 's' => 'cell_center'],
			['v' => $row['weight_kg'] !== null ? (float)$row['weight_kg'] : '', 's' => 'cell_num'],
			['v' => $row['height_cm'] !== null ? (float)$row['height_cm'] : '', 's' => 'cell_num'],
			['v' => (int)$row['age_months'], 's' => 'cell_num'],
			['v' => (int)$row['age_days'], 's' => 'cell_num'],
			['v' => $wfaStatus, 's' => xlsx_status_style($wfaStatus)],
			['v' => (string)($row['hfa_status'] ?? ''), 's' => xlsx_status_style((string)($row['hfa_status'] ?? ''))],
			['v' => (string)($row['wfh_status'] ?? ''), 's' => xlsx_status_style((string)($row['wfh_status'] ?? ''))],
			['v' => !empty($row['has_disability']) ? 'YES' : 'NO', 's' => 'cell_center'],
		];
	}

	$sheets[] = [
		'name' => 'NutStatusTool',
		'widths' => $nutStatusWidths,
		'merges' => array_map(
			fn($rowNumber) => 'A' . $rowNumber . ':P' . $rowNumber,
			range(1, count($titleLines))
		),
		'rows' => $outRows,
	];
}

/*
 | Sheet 1 — Summary (NutStatus-style sex-disaggregated counts).
 | Skipped in single-list export mode.
 */

if (!$isSingleList && !$isNutStatus && !$isNutStatusBrgy && !$isForm1A && !$isForm1B && !$isForm1C) {
$summaryRows = admin_fetch_all(
	"SELECT
		c.sex,
		CASE WHEN TIMESTAMPDIFF(MONTH, c.birthdate, LAST_DAY(?)) < 24 THEN '0-23' ELSE '24-59' END AS age_band,
		m.wfa_status,
		m.hfa_status,
		m.wfh_status
	 FROM children c
	 INNER JOIN measurements m ON m.id = (
		SELECT m2.id FROM measurements m2
		WHERE m2.child_id = c.id
		ORDER BY m2.measurement_date DESC, m2.id DESC
		LIMIT 1
	 )
	 WHERE {$scope}
	   AND TIMESTAMPDIFF(MONTH, c.birthdate, LAST_DAY(?)) BETWEEN 0 AND 59",
	's' . str_repeat('i', count($scopeParams)) . 's',
	array_merge([$anchorParam], $scopeParams, [$anchorParam])
);

$bucket = static fn(): array => [
	'Boys' => ['0-23' => 0, '24-59' => 0, 'Total' => 0],
	'Girls' => ['0-23' => 0, '24-59' => 0, 'Total' => 0],
	'Total' => ['0-23' => 0, '24-59' => 0, 'Total' => 0],
];

$wfaSummary = ['SUW' => $bucket(), 'MUW' => $bucket(), 'Normal' => $bucket(), 'OW' => $bucket()];
$hfaSummary = ['SSt' => $bucket(), 'MSt' => $bucket(), 'Normal' => $bucket(), 'Tall' => $bucket()];
$wfhSummary = ['SW' => $bucket(), 'MW' => $bucket(), 'Normal' => $bucket(), 'OW' => $bucket(), 'Ob' => $bucket()];

foreach ($summaryRows as $row) {
	$sexLabel = (string)$row['sex'] === 'Male' ? 'Boys' : 'Girls';
	$ageBandKey = (string)$row['age_band'];

	foreach ([['wfa_status', &$wfaSummary], ['hfa_status', &$hfaSummary], ['wfh_status', &$wfhSummary]] as [$field, &$summaryRef]) {
		$value = $row[$field] ?? null;

		if ($value === null || !isset($summaryRef[$value])) {
			continue;
		}

		$summaryRef[$value][$sexLabel][$ageBandKey]++;
		$summaryRef[$value][$sexLabel]['Total']++;
		$summaryRef[$value]['Total'][$ageBandKey]++;
		$summaryRef[$value]['Total']['Total']++;
	}
	unset($summaryRef);
}

$summaryRowsOut = [];

foreach ([['Republic of the Philippines', 'org'], ['Department of Health', 'org'], ['National Nutrition Council', 'org'], ['OPERATION TIMBANG (OPT) PLUS — ' . $year, 'title'], ['NUTRITIONAL STATUS SUMMARY BY BARANGAY — ' . $periodLabel, 'subtitle']] as [$lineText, $lineStyle]) {
	$rowCells = [];
	foreach (range(1, 8) as $colIdx) {
		$rowCells[] = ['v' => $colIdx === 1 ? $lineText : '', 's' => $lineStyle];
	}
	$summaryRowsOut[] = $rowCells;
}

$summaryRowsOut[] = array_map(static fn($v) => ['v' => $v], ['', '']);

$fieldRow = static function (array $pairs): array {
	return array_map(static fn($v) => ['v' => $v, 's' => 'label'], $pairs);
};

$summaryRowsOut[] = $fieldRow(['Barangay:', $barangayName, '', 'Period:', $periodLabel]);
$summaryRowsOut[] = $fieldRow(['Municipality/City:', 'City of San Fernando, Pampanga', '', 'Generated:', date('F j, Y')]);
$summaryRowsOut[] = array_map(static fn($v) => ['v' => $v], ['', '']);

foreach ([
	'WEIGHT-FOR-AGE (WFA)' => $wfaSummary,
	'HEIGHT-FOR-AGE (HFA)' => $hfaSummary,
	'WEIGHT-FOR-LENGTH/HEIGHT (WFH)' => $wfhSummary,
] as $axisTitle => $summaryTable) {
	$summaryRowsOut[] = [['v' => $axisTitle, 's' => 'label']];

	$headerRow = [];
	foreach (['Status', 'Boys 0-23', 'Boys 24-59', 'Boys Total', 'Girls 0-23', 'Girls 24-59', 'Girls Total', 'All Total'] as $headerCell) {
		$headerRow[] = ['v' => $headerCell, 's' => 'header'];
	}
	$summaryRowsOut[] = $headerRow;

	foreach ($summaryTable as $statusLabel => $counts) {
		$summaryRowsOut[] = [
			['v' => $statusLabel, 's' => 'header_left'],
			['v' => (int)$counts['Boys']['0-23']],
			['v' => (int)$counts['Boys']['24-59']],
			['v' => (int)$counts['Boys']['Total']],
			['v' => (int)$counts['Girls']['0-23']],
			['v' => (int)$counts['Girls']['24-59']],
			['v' => (int)$counts['Girls']['Total']],
			['v' => (int)$counts['Total']['Total']],
		];
	}

	$summaryRowsOut[] = array_map(static fn($v) => ['v' => $v], ['', '']);
}

$sheets[] = [
	'name' => 'Summary',
	'widths' => [16, 12, 12, 12, 12, 12, 12, 12],
	'merges' => ['A1:H1', 'A2:H2', 'A3:H3', 'A4:H4', 'A5:H5'],
	'rows' => $summaryRowsOut,
];
}

/*
 |--------------------------------------------------------------------------
 | Sheets 2-9 — the eight monitoring lists, one shared template
 |--------------------------------------------------------------------------
 */
foreach ($activeSpecs as $listIndex => $spec) {
	$rows = eopt_fetch_list(
		$scope,
		$scopeParams,
		$spec['condition'],
		$anchorParam,
		$spec['age_min'] ?? 0,
		$spec['age_max'] ?? 59
	);

	// List_0-23 carries 6 sequence-based follow-up columns:
	// Month#N = the child's Nth follow-up appointment (scheduled_at ASC).
	$isInfantSheet = (($spec['code'] ?? '') === '0-23') || !empty($spec['is_infant']);
	$activeListColumns = $listColumns;
	$activeListWidths = $listWidths;
	if ($isInfantSheet && !$isForm1A) {
		for ($mh = 1; $mh <= 6; $mh++) {
			$activeListColumns[] = 'Month#' . $mh;
			$activeListWidths[] = 16;
		}
	}
	$followupSeqMap = ($isInfantSheet && !$isForm1A && !empty($rows))
		? eopt_fetch_followup_sequence_map(array_column($rows, 'id'))
		: [];

	$totalCols = count($activeListColumns);
	$outRows = [];

	$titleText = $isForm1A
		? 'OPT PLUS FORM 1A - PRE-PRINTED LIST OF PRESCHOOL CHILDREN — ' . strtoupper($barangayName) . ' — ' . $year
		: strtoupper($spec['title']) . ' — ' . strtoupper($barangayName) . ' — ' . $year;

	$titleRow = [];
	for ($c = 0; $c < $totalCols; $c++) {
		$titleRow[] = ['v' => $c === 0 ? $titleText : '', 's' => 'title'];
	}
	$outRows[] = $titleRow;

	$periodText = $view === 'monthly' ? $monthsList[$month] . ' ' . $year : $roundsList[$checkupMonth] . ' ' . $year;
	$metaRow = [];
	$metaTexts = [
		'Barangay: ' . $barangayName,
		'Period: ' . $periodText,
		'Axis: ' . $spec['axis'],
		'Generated: ' . date('F j, Y'),
	];
	foreach ($metaTexts as $metaText) {
		$metaRow[] = ['v' => $metaText, 's' => 'label'];
	}
	while (count($metaRow) < $totalCols) {
		$metaRow[] = ['v' => '', 's' => 'default'];
	}
	$outRows[] = $metaRow;

	$headerRow = [];
	foreach ($activeListColumns as $columnTitle) {
		$headerRow[] = ['v' => $columnTitle, 's' => 'header'];
	}
	$outRows[] = $headerRow;

	foreach ($rows as $seq => $row) {
		$fullName = trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? ''));

		$dataRow = $isForm1A
			? [
				['v' => (string)($row['child_code'] ?? ''), 's' => 'cell'],
				['v' => (string)($row['address'] ?? ''), 's' => 'cell'],
				['v' => (string)($row['parent_name'] ?? ''), 's' => 'cell'],
				['v' => $fullName, 's' => 'cell'],
				['v' => !empty($row['is_ip']) ? 'YES' : 'NO', 's' => 'cell_center'],
				['v' => (string)($row['sex'] ?? ''), 's' => 'cell_center'],
				['v' => (string)($row['birthdate'] ?? ''), 's' => 'cell_center'],
				['v' => (string)($row['measurement_date'] ?? ''), 's' => 'cell_center'],
				['v' => $row['weight_kg'] !== null ? (float)$row['weight_kg'] : '', 's' => 'cell_num'],
				['v' => $row['height_cm'] !== null ? (float)$row['height_cm'] : '', 's' => 'cell_num'],
				['v' => (int)$row['age_months'], 's' => 'cell_num'],
				['v' => (int)$row['age_days'], 's' => 'cell_num'],
				['v' => (string)($row['wfh_status'] ?? ''), 's' => xlsx_status_style((string)($row['wfh_status'] ?? ''))],
				['v' => !empty($row['has_disability']) ? 'YES' : 'NO', 's' => 'cell_center'],
			]
			: [
				['v' => $seq + 1, 's' => 'cell_center'],
				['v' => (string)($row['address'] ?? ''), 's' => 'cell'],
				['v' => (string)$row['parent_name'], 's' => 'cell'],
				['v' => $fullName, 's' => 'cell'],
				['v' => (string)$row['sex'], 's' => 'cell_center'],
				['v' => (string)$row['birthdate'], 's' => 'cell_center'],
				['v' => $row['height_cm'] !== null ? (float)$row['height_cm'] : '', 's' => 'cell_num'],
				['v' => $row['weight_kg'] !== null ? (float)$row['weight_kg'] : '', 's' => 'cell_num'],
				['v' => (string)($row['wfa_status'] ?? ''), 's' => xlsx_status_style((string)($row['wfa_status'] ?? ''))],
				['v' => (string)($row['hfa_status'] ?? ''), 's' => xlsx_status_style((string)($row['hfa_status'] ?? ''))],
				['v' => (string)($row['wfh_status'] ?? ''), 's' => xlsx_status_style((string)($row['wfh_status'] ?? ''))],
			];

		if ($isInfantSheet && !$isForm1A) {
			$seqVisits = $followupSeqMap[(int)($row['id'] ?? 0)] ?? [];
			for ($mn = 1; $mn <= 6; $mn++) {
				$visit = $seqVisits[$mn - 1] ?? null;
				if ($visit === null || ($visit['scheduled_at'] ?? '') === '') {
					$dataRow[] = ['v' => '', 's' => 'cell_center'];
				} else {
					try {
						$cellText = (new DateTimeImmutable((string)$visit['scheduled_at']))->format('M j, Y');
					} catch (Exception) {
						$cellText = '';
					}
					$dataRow[] = ['v' => $cellText, 's' => 'cell_center'];
				}
			}
		}

		$outRows[] = $dataRow;
	}

	if ($isForm1A) {
		for ($blankRow = 0; $blankRow < 5; $blankRow++) {
			$outRows[] = array_map(static fn($value) => ['v' => $value, 's' => 'cell'], array_fill(0, $totalCols, ''));
		}
	}

	// Totals row — part of the standard form footer.
	$totalRow = [];
	$totalRow[] = ['v' => 'TOTAL NUMBER OF CHILDREN IN THIS LIST:', 's' => 'total_label'];
	for ($c = 1; $c < $totalCols - 1; $c++) {
		$totalRow[] = ['v' => '', 's' => 'total_label'];
	}
	$totalRow[] = ['v' => count($rows), 's' => 'total'];
	$outRows[] = $totalRow;

	$outRows[] = array_map(static fn($v) => ['v' => $v], array_fill(0, $totalCols, ''));

	$signatureRows = [
		['Prepared by:', '(Nutrition Officer / Nutritionist)'],
		['Certified correct:', '(City/Municipal Nutrition Action Officer)'],
	];

	foreach ($signatureRows as [$signLabel, $signRole]) {
		$signRow = [
			['v' => $signLabel, 's' => 'label'],
			['v' => '', 's' => 'default'],
			['v' => '', 's' => 'default'],
			['v' => $signRole, 's' => 'note'],
		];
		while (count($signRow) < $totalCols) {
			$signRow[] = ['v' => '', 's' => 'default'];
		}
		$outRows[] = $signRow;
	}

	// Title block spans the full grid width on every list sheet.
	// Convert column count to Excel letter (1=A, 27=AA, etc.)
	$colIdx = $totalCols;
	$lastCol = '';
	while ($colIdx > 0) {
		$colIdx--;
		$lastCol = chr(65 + ($colIdx % 26)) . $lastCol;
		$colIdx = intdiv($colIdx, 26);
	}
	$merges = array_map(
		fn($r) => "A{$r}:{$lastCol}{$r}",
		range(1, 2)
	);

	$sheets[] = [
		'name' => $spec['sheet'],
		'widths' => $activeListWidths,
		'merges' => $merges,
		'rows' => $outRows,
	];
}

$csvRequested = $exportFormat === 'csv' || isset($_GET['consolidation']);
if ($csvRequested) {
	ob_end_clean();
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="eopt-consolidation-' . date('Y-m-d') . '.csv"');
	header('Cache-Control: no-store');
	$output = fopen('php://output', 'w');
	foreach ($sheets as $sheet) {
		fputcsv($output, [$sheet['name']]);
		foreach ($sheet['rows'] as $row) {
			fputcsv($output, array_map(static fn($cell) => $cell['v'] ?? '', $row));
		}
		fputcsv($output, []);
	}
	fclose($output);
	exit;
}

$tmpBase = tempnam(sys_get_temp_dir(), 'eopt_report_');
$tmpPath = $tmpBase . '.xlsx';
@unlink($tmpBase);

if (!xlsx_lite_write_workbook($tmpPath, $sheets)) {
	$redirectParams = $isSingleList
		? ['list' => $listParam, 'year' => $year, 'barangay_id' => $barangayFilter, 'notice' => 'The EOPT list could not be generated.', 'type' => 'error']
		: ['view' => $view, 'year' => $year, 'month' => $month, 'checkup_month' => $checkupMonth, 'barangay_id' => $barangayFilter, 'notice' => 'The EOPT Excel workbook could not be generated.', 'type' => 'error'];
	$redirectUrl = $isSingleList
		? app_url('/nutritionist/eopt_reports.php')
		: app_url('/nutritionist/eopt_reports.php');
	admin_redirect($redirectUrl, $redirectParams);
}

if ($isNutStatus) {
	log_action((int)$user['id'], 'EOPT_NUTSTATUS_EXPORT', 'info', sprintf('Exported NutStatusTool roster (%s, %s).', $view, $periodLabel));
	$downloadName = 'nutstatustool-' . strtolower($view) . '-' . $year . '-' . date('Y-m-d') . '.xlsx';
} elseif ($isNutStatusBrgy) {
	log_action((int)$user['id'], 'EOPT_NUTSTATUSBRGY_EXPORT', 'info', sprintf('Exported NutStatusBrgy summary (%s, %s).', $view, $periodLabel));
	$downloadName = 'nutstatusbrgy-' . strtolower($view) . '-' . $year . '-' . date('Y-m-d') . '.xlsx';
} elseif ($isForm1A) {
	log_action((int)$user['id'], 'EOPT_FORM1A_EXPORT', 'info', sprintf('Exported Form 1A roster (%s, %s).', $view, $periodLabel));
	$downloadName = 'eopt-form1a-' . strtolower($view) . '-' . $year . '-' . date('Y-m-d') . '.xlsx';
} elseif ($isForm1B) {
	log_action((int)$user['id'], 'EOPT_FORM1B_EXPORT', 'info', sprintf('Exported Form 1B consolidation (%s, %s).', $view, $periodLabel));
	$downloadName = 'eopt-form1b-' . strtolower($view) . '-' . $year . '-' . date('Y-m-d') . '.xlsx';
} elseif ($isForm1C) {
	log_action((int)$user['id'], 'EOPT_FORM1C_EXPORT', 'info', sprintf('Exported Form 1C affected-child list (%s, %s).', $view, $periodLabel));
	$downloadName = 'eopt-form1c-' . strtolower($view) . '-' . $year . '-' . date('Y-m-d') . '.xlsx';
} elseif ($isSingleList) {
	log_action((int)$user['id'], 'EOPT_LIST_EXPORT', 'info', sprintf('Exported EOPT list %s covering %d row(s).', $listParam, count($sheets[0]['rows'])));
	$downloadName = sprintf('eopt-list-%s-%04d-%s.xlsx', strtolower($listParam), $year, date('Y-m-d'));
} else {
	log_action((int)$user['id'], 'EOPT_EXPORT', 'info', sprintf('Exported EOPT workbook (%s, %s) covering %d list sheets.', $view, $periodLabel, count($listsSpec)));
	$downloadSlug = $view === 'monthly'
		? sprintf('monthly-%02d%04d', $month, $year)
		: sprintf('quarterly-%02d%04d', $checkupMonth, $year);
	$downloadName = 'eopt-report-' . $downloadSlug . '-' . date('Y-m-d') . '.xlsx';
}

ob_end_clean();

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . (string)filesize($tmpPath));
header('Cache-Control: no-store');

readfile($tmpPath);
unlink($tmpPath);
exit;

<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/followup_scheduler.php';
require_once __DIR__ . '/../includes/xlsx_lite.php';

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
$scope = nutritionist_scope_fragment($user, 'c.barangay_id', $scopeParams);

$barangayFilterSql = '';
$barangayFilterParams = [];
$barangayName = 'All barangays';

if ($barangayFilter > 0) {
	$barangayFilterSql = ' AND c.barangay_id = ?';
	$barangayFilterParams[] = $barangayFilter;

	$barangayRow = admin_fetch_one('SELECT name FROM barangays WHERE id = ? LIMIT 1', 'i', [$barangayFilter]);
	$barangayName = (string)($barangayRow['name'] ?? '');
}

/*
 |--------------------------------------------------------------------------
 | Anchor date: ages on every roster/list are evaluated at the END of the
 | reporting month (monthly view) or check-up round month (quarterly).
 |--------------------------------------------------------------------------
 */
try {
	$anchorDate = new DateTimeImmutable(sprintf('%04d-%02d-t', $year, $view === 'monthly' ? $month : $checkupMonth));
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
	string $barangayFilterSql,
	array $barangayFilterParams,
	string $conditionSql,
	string $anchorParam
): array {
	// The anchor date appears TWICE in the statement — once in the SELECT
	// age snapshot and once in the WHERE band filter — so it leads and
	// trails the parameter list (both as strings).
	$params = array_merge([$anchorParam], $scopeParams, $barangayFilterParams, [$anchorParam]);
	$types = 's' . str_repeat('i', count($scopeParams) + count($barangayFilterParams)) . 's';

	return admin_fetch_all(
		"SELECT
			c.child_code,
			c.first_name,
			c.middle_name,
			c.last_name,
			c.sex,
			c.birthdate,
			bg.name AS barangay,
			p.name AS parent_name,
			lm.id AS measurement_id,
			lm.measurement_date,
			TIMESTAMPDIFF(MONTH, c.birthdate, ?) AS age_months,
			lm.height_cm,
			lm.weight_kg,
			lm.wfa_status,
			lm.hfa_status,
			lm.wfh_status,
			lm.is_flagged
		 FROM children c
		 INNER JOIN parents p ON p.id = c.parent_id
		 LEFT JOIN barangays bg ON bg.id = c.barangay_id
		 INNER JOIN measurements lm ON lm.id = (
			SELECT m2.id FROM measurements m2
			WHERE m2.child_id = c.id
			ORDER BY m2.measurement_date DESC, m2.id DESC
			LIMIT 1
		 )
		 WHERE {$scope}{$barangayFilterSql}
		   AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 59
		   AND {$conditionSql}
		 ORDER BY c.last_name ASC, c.first_name ASC",
		$types,
		$params
	);
}

/*
 |--------------------------------------------------------------------------
 | The eight official monitoring lists — SAME TEMPLATE, DIFFERENT LIST.
 | Each list can also be exported on its own via &list=CODE.
 |--------------------------------------------------------------------------
 */
$listsSpec = [
	['code' => 'SUW', 'sheet' => 'List_SUW', 'title' => 'SEVERELY UNDERWEIGHT (SUW)', 'axis' => 'Weight-for-Age', 'condition' => "lm.wfa_status = 'SUW'"],
	['code' => 'MUW', 'sheet' => 'List_MUW', 'title' => 'MODERATELY UNDERWEIGHT (MUW)', 'axis' => 'Weight-for-Age', 'condition' => "lm.wfa_status IN ('UW','MUW')"],
	['code' => 'SSt', 'sheet' => 'List_SSt', 'title' => 'SEVERELY STUNTED (SSt)', 'axis' => 'Height-for-Age', 'condition' => "lm.hfa_status = 'SSt'"],
	['code' => 'MSt', 'sheet' => 'List_MSt', 'title' => 'MODERATELY STUNTED (MSt)', 'axis' => 'Height-for-Age', 'condition' => "lm.hfa_status IN ('St','MSt')"],
	['code' => 'SW',  'sheet' => 'List_SW',  'title' => 'SEVERELY WASTED (SW)',     'axis' => 'Weight-for-Length/Height', 'condition' => "lm.wfh_status = 'SW'"],
	['code' => 'MW',  'sheet' => 'List_MW',  'title' => 'MODERATELY WASTED (MW)',   'axis' => 'Weight-for-Length/Height', 'condition' => "lm.wfh_status = 'MW'"],
	['code' => 'OW',  'sheet' => 'List_OW',  'title' => 'OVERWEIGHT (OW)',          'axis' => 'Weight-for-Age / Weight-for-Height', 'condition' => "(lm.wfa_status = 'OW' OR lm.wfh_status = 'OW')"],
	['code' => 'Ob',  'sheet' => 'List_Ob',  'title' => 'OBESE (Ob)',               'axis' => 'Weight-for-Length/Height', 'condition' => "lm.wfh_status = 'Ob'"],
];

/*
 | Single-list export mode: &list=SUW exports ONLY that one list sheet
 | (same template), skipping the Summary sheet. Case-insensitive so
 | Ob/SSt/St work regardless of URL casing.
 */
$listParamRaw = trim((string)($_GET['list'] ?? ''));
$listParamKey = strtolower($listParamRaw);
$codeLowerMap = [];
foreach ($listsSpec as $spec) {
	$codeLowerMap[strtolower($spec['code'])] = $spec;
}
$isSingleList = $listParamKey !== '' && isset($codeLowerMap[$listParamKey]);
$listParam = $isSingleList ? $codeLowerMap[$listParamKey]['code'] : $listParamRaw;
$activeSpecs = $isSingleList ? [$codeLowerMap[$listParamKey]] : $listsSpec;

$listColumns = ['No.', 'Name of Child (Surname, First Name, M.I.)', 'Sex', 'Date of Birth', 'Age (mos)', 'Barangay', 'Parent/Guardian', 'Weight (kg)', 'Height (cm)', 'WFA', 'HFA', 'WFH', 'Date Measured', 'Monitoring Track'];
$listWidths = [6, 38, 9, 14, 10, 20, 26, 11, 11, 9, 9, 9, 15, 22];

$sheets = [];

/*
 |--------------------------------------------------------------------------
 | Sheet 1 — Summary (NutStatusBrgy-style sex-disaggregated counts)
 |--------------------------------------------------------------------------
 */
/*
 |--------------------------------------------------------------------------
 | Sheet 1 — Summary (NutStatusBrgy-style sex-disaggregated counts).
 | Skipped in single-list export mode.
 |--------------------------------------------------------------------------
 */
$sheets = [];

if (!$isSingleList) {
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
	 WHERE {$scope}{$barangayFilterSql}
	   AND TIMESTAMPDIFF(MONTH, c.birthdate, LAST_DAY(?)) BETWEEN 0 AND 59",
	's' . str_repeat('i', count($scopeParams) + count($barangayFilterParams)) . 's',
	array_merge([$anchorParam], $scopeParams, $barangayFilterParams, [$anchorParam])
);

$bucket = static fn(): array => [
	'Boys' => ['0-23' => 0, '24-59' => 0, 'Total' => 0],
	'Girls' => ['0-23' => 0, '24-59' => 0, 'Total' => 0],
	'Total' => ['0-23' => 0, '24-59' => 0, 'Total' => 0],
];

$wfaSummary = ['SUW' => $bucket(), 'MUW' => $bucket(), 'Normal' => $bucket(), 'OW' => $bucket()];
$hfaSummary = ['SSt' => $bucket(), 'MSt' => $bucket(), 'Normal' => $bucket(), 'T' => $bucket()];
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
		$barangayFilterSql,
		$barangayFilterParams,
		$spec['condition'],
		$anchorParam
	);

	$totalCols = count($listColumns);
	$outRows = [];

	$titleLines = [
		'Republic of the Philippines',
		'Department of Health',
		'National Nutrition Council',
		'OPERATION TIMBANG (OPT) PLUS — ' . $year,
		'MONITORING LIST FOR ' . $spec['title'] . ' PRESCHOOL CHILDREN (0-59 MONTHS OLD)',
	];

	foreach ($titleLines as $lineIdx => $lineText) {
		$styleKey = ['org', 'org', 'org', 'title', 'subtitle'][$lineIdx];
		$rowCells = [];

		for ($c = 0; $c < $totalCols; $c++) {
			$rowCells[] = ['v' => $c === 0 ? $lineText : '', 's' => $styleKey];
		}

		$outRows[] = $rowCells;
	}

	$outRows[] = array_map(static fn($v) => ['v' => $v], array_fill(0, $totalCols, ''));

	$metaTexts = [
		'Barangay: ' . $barangayName,
		'Period: ' . ($view === 'monthly' ? $monthsList[$month] . ' ' . $year : $roundsList[$checkupMonth] . ' ' . $year),
		'Axis: ' . $spec['axis'],
		'Generated: ' . date('F j, Y'),
	];

	$metaRow = [];
	foreach ($metaTexts as $metaText) {
		$metaRow[] = ['v' => $metaText, 's' => 'label'];
		for ($pad = 0; $pad < 3; $pad++) {
			$metaRow[] = ['v' => '', 's' => 'default'];
		}
	}
	while (count($metaRow) > $totalCols) {
		array_pop($metaRow);
	}
	$outRows[] = $metaRow;

	$outRows[] = array_map(static fn($v) => ['v' => $v], array_fill(0, $totalCols, ''));

	$headerRow = [];
	foreach ($listColumns as $columnTitle) {
		$headerRow[] = ['v' => $columnTitle, 's' => 'header'];
	}
	$outRows[] = $headerRow;

	foreach ($rows as $seq => $row) {
		$classifTrack = followup_classify_child([
			'birthdate' => (string)$row['birthdate'],
			'measurement_date' => (string)$row['measurement_date'],
			'wfa_status' => $row['wfa_status'],
			'hfa_status' => $row['hfa_status'],
			'wfh_status' => $row['wfh_status'],
		], $anchorDate)['track'];

		$trackLabel = $classifTrack === 'quarterly' ? 'Quarterly (Apr/Jul/Oct)' : ($classifTrack === 'monthly' ? 'Monthly' : '—');

		$fullName = trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? ''));

		$outRows[] = [
			['v' => $seq + 1, 's' => 'cell_center'],
			['v' => $fullName, 's' => 'cell'],
			['v' => (string)$row['sex'], 's' => 'cell_center'],
			['v' => (string)$row['birthdate'], 's' => 'cell_center'],
			['v' => (int)$row['age_months'], 's' => 'cell_center'],
			['v' => (string)($row['barangay'] ?? ''), 's' => 'cell'],
			['v' => (string)$row['parent_name'], 's' => 'cell'],
			['v' => (float)$row['weight_kg'], 's' => 'cell_num'],
			['v' => (float)$row['height_cm'], 's' => 'cell_num'],
			['v' => (string)($row['wfa_status'] ?? ''), 's' => 'cell_center'],
			['v' => (string)($row['hfa_status'] ?? ''), 's' => 'cell_center'],
			['v' => (string)($row['wfh_status'] ?? ''), 's' => 'cell_center'],
			['v' => (string)$row['measurement_date'], 's' => 'cell_center'],
			['v' => $trackLabel, 's' => 'cell'],
		];
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
		$outRows[] = [
			['v' => $signLabel, 's' => 'label'],
			['v' => '', 's' => 'default'],
			['v' => '', 's' => 'default'],
			['v' => $signRole, 's' => 'note'],
		];
	}

	// Title block spans the full grid width on every list sheet.
	$merges = ['A1:N1', 'A2:N2', 'A3:N3', 'A4:N4', 'A5:N5'];

	$sheets[] = [
		'name' => $spec['sheet'],
		'widths' => $listWidths,
		'merges' => $merges,
		'rows' => $outRows,
	];
}

$tmpPath = tempnam(sys_get_temp_dir(), 'eopt_report_') . '.xlsx';

if (!xlsx_lite_write_workbook($tmpPath, $sheets)) {
	$redirectParams = $isSingleList
		? ['list' => $listParam, 'year' => $year, 'barangay_id' => $barangayFilter, 'notice' => 'The EOPT list could not be generated.', 'type' => 'error']
		: ['view' => $view, 'year' => $year, 'month' => $month, 'checkup_month' => $checkupMonth, 'barangay_id' => $barangayFilter, 'notice' => 'The EOPT Excel workbook could not be generated.', 'type' => 'error'];
	$redirectUrl = $isSingleList
		? app_url('/nutritionist/eopt_reports.php')
		: app_url('/nutritionist/eopt_reports.php');
	admin_redirect($redirectUrl, $redirectParams);
}

if ($isSingleList) {
	log_action((int)$user['id'], 'EOPT_LIST_EXPORT', 'info', sprintf('Exported EOPT list %s covering %d row(s).', $listParam, count($sheets[0]['rows'])));
	$downloadName = sprintf('eopt-list-%s-%04d-%s.xlsx', strtolower($listParam), $year, date('Y-m-d'));
} else {
	log_action((int)$user['id'], 'EOPT_EXPORT', 'info', sprintf('Exported EOPT workbook (%s, %s) covering %d list sheets.', $view, $periodLabel, count($listsSpec)));
	$downloadSlug = $view === 'monthly'
		? sprintf('monthly-%02d%04d', $month, $year)
		: sprintf('quarterly-%02d%04d', $checkupMonth, $year);
	$downloadName = 'eopt-report-' . $downloadSlug . '-' . date('Y-m-d') . '.xlsx';
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . (string)filesize($tmpPath));
header('Cache-Control: no-store');

readfile($tmpPath);
unlink($tmpPath);
exit;

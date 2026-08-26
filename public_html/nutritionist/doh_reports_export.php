<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/xlsx_lite.php';

$user = nutritionist_require_access();

/**
 * Kept in sync with doh_reports.php on purpose (same categories, same
 * conditions) so the exported workbook always matches what's on screen.
 */
$categories = [
	'sw_sam' => ['label' => 'Severely Wasted (SAM)', 'condition' => "m.wfh_status = 'SW/SAM'"],
	'mw_mam' => ['label' => 'Moderately Wasted (MAM)', 'condition' => "m.wfh_status = 'MW/MAM'"],
	'stunted' => ['label' => 'Stunted (Moderate & Severe)', 'condition' => "m.hfa_status IN ('MSt','SSt')"],
	'ow_ob' => ['label' => 'Overweight & Obese', 'condition' => "m.wfh_status IN ('OW','Ob')"],
	'underweight_stunted' => ['label' => 'Underweight + Stunted (double burden)', 'condition' => "m.wfa_status IN ('MUW','SUW') AND m.hfa_status IN ('MSt','SSt')"],
	'stunted_wasted' => ['label' => 'Stunted + Wasted (double burden)', 'condition' => "m.hfa_status IN ('MSt','SSt') AND m.wfh_status IN ('MW/MAM','SW/SAM')"],
	'stunted_overweight' => ['label' => 'Stunted + Overweight/Obese (double burden)', 'condition' => "m.hfa_status IN ('MSt','SSt') AND m.wfh_status IN ('OW','Ob')"],
	'flagged' => ['label' => 'Flagged for Review', 'condition' => "m.is_flagged = 1"],
];

$reportType = (string)($_GET['report'] ?? 'summary');
$ageBand = (string)($_GET['age_band'] ?? 'all');
$barangayFilter = (int)($_GET['barangay_id'] ?? 0);

$scopeParams = [];
$scope = nutritionist_scope_fragment($user, 'c.barangay_id', $scopeParams);

$barangayFilterSql = '';
$barangayFilterParams = [];
$barangayName = '';

if ($barangayFilter > 0) {
	$barangayFilterSql = ' AND c.barangay_id = ?';
	$barangayFilterParams[] = $barangayFilter;

	$barangayRow = admin_fetch_one('SELECT name FROM barangays WHERE id = ? LIMIT 1', 'i', [$barangayFilter]);
	$barangayName = $barangayRow['name'] ?? '';
}

$ageBandSql = '';
$ageBandLabel = '0-59 MONTHS';

if ($ageBand === '0-23') {
	$ageBandSql = ' AND m.age_months < 24';
	$ageBandLabel = '0-23 MONTHS';
} elseif ($ageBand === '24-59') {
	$ageBandSql = ' AND m.age_months >= 24';
	$ageBandLabel = '24-59 MONTHS';
}

/*
|--------------------------------------------------------------------------
| Header block — mirrors the DOH / National Nutrition Council "Monitoring
| List" template (title, Barangay/Municipality/Province line, then the
| column headers) so the workbook opens looking like the official form.
|
| Note: the xlsx writer this project uses (includes/xlsx_lite.php) is a
| small dependency-free reader/writer, not a full library — it can't embed
| the DOH/NNC letterhead logos or merge cells the way the original template
| does. This gets you the same title, fields, and column layout in a plain
| worksheet that opens directly in Excel (no PDF, no print dialog).
|--------------------------------------------------------------------------
*/
$rows = [];
$rows[] = ['Republic of the Philippines'];
$rows[] = ['Department of Health'];
$rows[] = ['National Nutrition Council'];

if ($reportType === 'summary') {
	$rows[] = ['NUTRITIONAL STATUS SUMMARY BY BARANGAY (' . $ageBandLabel . ')'];
} else {
	$categoryLabel = $categories[$reportType]['label'] ?? 'Children';
	$rows[] = ['MONITORING LIST FOR ' . strtoupper($categoryLabel) . ' CHILDREN ' . $ageBandLabel . ' OLD'];
}

$rows[] = [];
$rows[] = ['Barangay:', $barangayName !== '' ? $barangayName : 'All barangays', '', 'Municipality:', '', '', 'Province:', ''];
$rows[] = ['Generated:', date('F j, Y')];
$rows[] = [];

if ($reportType === 'summary') {
	/*
	|----------------------------------------------------------------------
	| Barangay summary export — same three axis tables shown on screen
	| (WFA / HFA / WFH), each broken down by sex and age band.
	|----------------------------------------------------------------------
	*/
	$params = array_merge($scopeParams, $barangayFilterParams);

	$summaryRows = admin_fetch_all(
		"SELECT
			c.sex,
			CASE WHEN m.age_months < 24 THEN '0-23' ELSE '24-59' END AS age_band,
			m.wfa_status,
			m.hfa_status,
			m.wfh_status,
			m.is_flagged
		 FROM children c
		 INNER JOIN measurements m ON m.id = (
			SELECT m2.id FROM measurements m2
			WHERE m2.child_id = c.id
			ORDER BY m2.measurement_date DESC, m2.id DESC
			LIMIT 1
		 )
		 WHERE {$scope}{$barangayFilterSql}{$ageBandSql}",
		str_repeat('i', count($params)),
		$params
	);

	$bucket = static fn (): array => [
		'Boys' => ['0-23' => 0, '24-59' => 0, 'Total' => 0],
		'Girls' => ['0-23' => 0, '24-59' => 0, 'Total' => 0],
		'Total' => ['0-23' => 0, '24-59' => 0, 'Total' => 0],
	];

	$wfaSummary = ['SUW' => $bucket(), 'MUW' => $bucket(), 'Normal' => $bucket(), 'OW' => $bucket()];
	$hfaSummary = ['SSt' => $bucket(), 'MSt' => $bucket(), 'Normal' => $bucket(), 'Tall' => $bucket()];
	$wfhSummary = ['SW/SAM' => $bucket(), 'MW/MAM' => $bucket(), 'Normal' => $bucket(), 'OW' => $bucket(), 'Ob' => $bucket()];

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

	$summaryTables = [
		'Weight-for-Age (WFA)' => $wfaSummary,
		'Height-for-Age (HFA)' => $hfaSummary,
		'Weight-for-Height (WFH)' => $wfhSummary,
	];

	foreach ($summaryTables as $axisTitle => $summaryTable) {
		$rows[] = [$axisTitle];
		$rows[] = ['Status', 'Boys 0-23', 'Boys 24-59', 'Boys Total', 'Girls 0-23', 'Girls 24-59', 'Girls Total', 'All Total'];

		foreach ($summaryTable as $statusLabel => $counts) {
			$rows[] = [
				$statusLabel,
				$counts['Boys']['0-23'],
				$counts['Boys']['24-59'],
				$counts['Boys']['Total'],
				$counts['Girls']['0-23'],
				$counts['Girls']['24-59'],
				$counts['Girls']['Total'],
				$counts['Total']['Total'],
			];
		}

		$rows[] = [];
	}

	$downloadSlug = 'summary-' . $ageBand;
} else {
	/*
	|----------------------------------------------------------------------
	| Category roster export — same columns as the NNC "Monitoring List"
	| (Child Seq / Address-Purok / Name of Mother / Full Name of Child /
	| Sex / Birthdate / Height / Weight / status columns). Purok is left
	| blank because this system no longer stores a child's address (it was
	| intentionally removed — see db/20260824_remove_child_address_purok.sql)
	| and MUAC is left out because it isn't a field this system records.
	|----------------------------------------------------------------------
	*/
	if (!isset($categories[$reportType])) {
		$reportType = 'mw_mam';
	}

	$categoryCondition = $categories[$reportType]['condition'];
	$params = array_merge($scopeParams, $barangayFilterParams);

	$rosterRows = admin_fetch_all(
		"SELECT
			c.child_code,
			c.first_name,
			c.last_name,
			c.sex,
			c.birthdate,
			c.is_ip,
			c.has_disability,
			bg.name AS barangay,
			p.name AS parent_name,
			p.phone AS parent_phone,
			m.measurement_date,
			m.age_months,
			m.height_cm,
			m.weight_kg,
			m.wfa_status,
			m.hfa_status,
			m.wfh_status,
			m.is_flagged
		 FROM children c
		 INNER JOIN parents p ON p.id = c.parent_id
		 LEFT JOIN barangays bg ON bg.id = c.barangay_id
		 INNER JOIN measurements m ON m.id = (
			SELECT m2.id FROM measurements m2
			WHERE m2.child_id = c.id
			ORDER BY m2.measurement_date DESC, m2.id DESC
			LIMIT 1
		 )
		 WHERE {$scope}{$barangayFilterSql}{$ageBandSql} AND {$categoryCondition}
		 ORDER BY c.last_name ASC, c.first_name ASC",
		str_repeat('i', count($params)),
		$params
	);

	$rows[] = [
		'Child Seq.',
		'Barangay',
		'Name of Mother / Caregiver',
		'Full Name of Child',
		'Sex',
		'Birthdate',
		'Age (months)',
		'Height/Length (cm)',
		'Weight (kg)',
		'WFA Status',
		'HFA Status',
		'WFH Status',
		'Last Measured',
		'Flagged',
	];

	foreach ($rosterRows as $i => $row) {
		$rows[] = [
			$i + 1,
			(string)($row['barangay'] ?? ''),
			(string)$row['parent_name'],
			$row['last_name'] . ', ' . $row['first_name'],
			(string)$row['sex'],
			(string)$row['birthdate'],
			(int)$row['age_months'],
			(float)$row['height_cm'],
			(float)$row['weight_kg'],
			(string)($row['wfa_status'] ?? ''),
			(string)($row['hfa_status'] ?? ''),
			(string)($row['wfh_status'] ?? ''),
			(string)$row['measurement_date'],
			!empty($row['is_flagged']) ? 'Yes' : '',
		];
	}

	$downloadSlug = $reportType . '-' . $ageBand;
}

$headerRow = array_shift($rows);
$dataRows = $rows;

$tmpPath = tempnam(sys_get_temp_dir(), 'doh_report_') . '.xlsx';
$sheetName = $reportType === 'summary' ? 'Barangay Summary' : $categories[$reportType]['label'];

if (!xlsx_lite_write($tmpPath, $headerRow, $dataRows, $sheetName)) {
	admin_redirect('/nutritionist/doh_reports.php', [
		'report' => $reportType,
		'age_band' => $ageBand,
		'barangay_id' => $barangayFilter,
		'notice' => 'The Excel export could not be generated.',
		'type' => 'error',
	]);
}

$downloadName = 'doh-report-' . $downloadSlug . '-' . date('Y-m-d') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . (string)filesize($tmpPath));
header('Cache-Control: no-store');

readfile($tmpPath);
unlink($tmpPath);
exit;
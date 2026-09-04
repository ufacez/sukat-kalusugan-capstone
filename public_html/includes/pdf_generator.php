<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/nutritionist_helpers.php';
require_once __DIR__ . '/followup_scheduler.php';
require_once __DIR__ . '/who_calculator.php';

function pdf_base(string $title, string $orientation = 'Portrait'): TCPDF {
	$pdf = new TCPDF($orientation, 'mm', 'A4', true, 'UTF-8', false);

	$pdf->SetCreator('Sukat Kalusugan');
	$pdf->SetAuthor('Sukat Kalusugan Nutrition System');
	$pdf->SetTitle($title);
	$pdf->SetHeaderData('', 0, '', '');
	$pdf->setHeaderFont(['helvetica', '', 7]);
	$pdf->setFooterFont(['helvetica', '', 7]);
	$pdf->SetMargins(12, 15, 12);
	$pdf->SetHeaderMargin(5);
	$pdf->SetFooterMargin(10);
	$pdf->SetAutoPageBreak(true, 20);
	$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

	return $pdf;
}

function pdf_header_block(TCPDF $pdf, int $year, string $periodLabel, string $barangayName): void {
	$pdf->SetFont('helvetica', 'B', 11);
	$pdf->Cell(0, 6, 'Republic of the Philippines', 0, 1, 'C');
	$pdf->SetFont('helvetica', '', 9);
	$pdf->Cell(0, 5, 'Department of Health', 0, 1, 'C');
	$pdf->Cell(0, 5, 'National Nutrition Council', 0, 1, 'C');
	$pdf->Ln(4);

	$pdf->SetFont('helvetica', 'B', 12);
	$pdf->Cell(0, 7, 'OPERATION TIMBANG (OPT) PLUS -- ' . $year, 0, 1, 'C');
	$pdf->Ln(3);
}

function pdf_metadata_row(TCPDF $pdf, string $barangayName, string $periodLabel, string $generatedDate): void {
	$pdf->SetFont('helvetica', '', 8);

	$pdf->Cell(25, 5, 'Barangay:', 0, 0);
	$pdf->Cell(65, 5, $barangayName, 0, 0);
	$pdf->Cell(25, 5, 'Period:', 0, 0);
	$pdf->Cell(65, 5, $periodLabel, 0, 1);

	$pdf->Cell(25, 5, 'Municipality:', 0, 0);
	$pdf->Cell(65, 5, 'City of San Fernando, Pampanga', 0, 0);
	$pdf->Cell(25, 5, 'Generated:', 0, 0);
	$pdf->Cell(65, 5, $generatedDate, 0, 1);

	$pdf->Ln(3);
}

function pdf_table_header(TCPDF $pdf, array $columns, array $widths, string $fillColor = '106E4F'): void {
	$r = hexdec(substr($fillColor, 0, 2));
	$g = hexdec(substr($fillColor, 2, 2));
	$b = hexdec(substr($fillColor, 4, 2));

	$pdf->SetFillColor($r, $g, $b);
	$pdf->SetTextColor(255, 255, 255);
	$pdf->SetFont('helvetica', 'B', 7);

	$height = 8;
	for ($i = 0; $i < count($columns); $i++) {
		$pdf->Cell($widths[$i], $height, $columns[$i], 1, 0, 'C', true);
	}
	$pdf->Ln();

	$pdf->SetTextColor(0, 0, 0);
	$pdf->SetFont('helvetica', '', 7);
}

function pdf_data_row(TCPDF $pdf, array $values, array $widths, bool $isAlt = false, array $aligns = []): void {
	if ($isAlt) {
		$pdf->SetFillColor(240, 248, 244);
	} else {
		$pdf->SetFillColor(255, 255, 255);
	}

	$maxH = 6;
	for ($i = 0; $i < count($values); $i++) {
		$align = $aligns[$i] ?? 'L';
		$pdf->Cell($widths[$i], $maxH, (string)$values[$i], 1, 0, $align, true);
	}
	$pdf->Ln();
}

function pdf_totals_row(TCPDF $pdf, string $label, int $count, array $widths): void {
	$pdf->SetFont('helvetica', 'B', 7);
	$pdf->SetFillColor(230, 240, 235);

	$totalWidth = array_sum($widths);
	$pdf->Cell($totalWidth - 15, 7, $label, 1, 0, 'R', true);
	$pdf->Cell(15, 7, (string)$count, 1, 1, 'C', true);

	$pdf->SetFont('helvetica', '', 7);
}

function pdf_signature_block(TCPDF $pdf): void {
	$pdf->Ln(8);
	$pdf->SetFont('helvetica', '', 8);

	$pdf->Cell(40, 5, 'Prepared by:', 0, 0);
	$pdf->Cell(60, 5, '', 0, 0);
	$pdf->Cell(40, 5, 'Certified correct:', 0, 1);

	$pdf->SetFont('helvetica', 'I', 7);
	$pdf->Cell(40, 5, '(Nutrition Officer / Nutritionist)', 0, 0);
	$pdf->Cell(60, 5, '', 0, 0);
	$pdf->Cell(40, 5, '(City/Municipal Nutrition Action Officer)', 0, 1);
}

function pdf_scope_and_filter(): array {
	$user = nutritionist_require_access();

	$year = (int)($_GET['year'] ?? date('Y'));
	$view = (string)($_GET['view'] ?? 'monthly');
	if (!in_array($view, ['monthly', 'quarterly'], true)) {
		$view = 'monthly';
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
	$barangayName = 'All barangays within scope';

	if ($barangayFilter > 0) {
		$barangayFilterSql = ' AND c.barangay_id = ?';
		$barangayFilterParams[] = $barangayFilter;
		$brgyRow = admin_fetch_one('SELECT name FROM barangays WHERE id = ? LIMIT 1', 'i', [$barangayFilter]);
		$barangayName = (string)($brgyRow['name'] ?? '');
	}

	try {
		$anchorDate = new DateTimeImmutable(sprintf('%04d-%02d-t', $year, $view === 'monthly' ? $month : $checkupMonth));
	} catch (Exception) {
		$anchorDate = new DateTimeImmutable('today');
	}

	$monthsList = [4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];
	$roundsList = [4 => 'APRIL ROUND', 7 => 'JULY ROUND', 10 => 'OCTOBER ROUND'];

	$periodLabel = $view === 'monthly'
		? strtoupper($monthsList[$month] . ' ' . $year . ' MONTHLY MONITORING')
		: ($roundsList[$checkupMonth] . ' ' . $year . ' QUARTERLY CHECK-UP');

	return [
		'year' => $year,
		'view' => $view,
		'month' => $month,
		'checkup_month' => $checkupMonth,
		'barangay_id' => $barangayFilter,
		'barangay_name' => $barangayName,
		'scope' => $scope,
		'scope_params' => $scopeParams,
		'barangay_filter_sql' => $barangayFilterSql,
		'barangay_filter_params' => $barangayFilterParams,
		'anchor_date' => $anchorDate,
		'anchor_param' => $anchorDate->format('Y-m-d'),
		'period_label' => $periodLabel,
	];
}

function pdf_fetch_list(array $f, string $conditionSql, int $ageMin = 0, int $ageMax = 59): array {
	$params = array_merge([$f['anchor_param']], $f['scope_params'], $f['barangay_filter_params'], [$f['anchor_param']]);
	$types = 's' . str_repeat('i', count($f['scope_params']) + count($f['barangay_filter_params'])) . 's';

	return admin_fetch_all(
		"SELECT
			c.id, c.child_code, c.first_name, c.middle_name, c.last_name,
			c.sex, c.birthdate, c.purok AS address,
			bg.name AS barangay, p.name AS parent_name,
			lm.measurement_date, lm.height_cm, lm.weight_kg,
			lm.wfa_status, lm.hfa_status, lm.wfh_status, lm.is_flagged
		 FROM children c
		 INNER JOIN parents p ON p.id = c.parent_id
		 LEFT JOIN barangays bg ON bg.id = c.barangay_id
		 INNER JOIN measurements lm ON lm.id = (
			SELECT m2.id FROM measurements m2
			WHERE m2.child_id = c.id
			ORDER BY m2.measurement_date DESC, m2.id DESC
			LIMIT 1
		 )
		 WHERE {$f['scope']}{$f['barangay_filter_sql']}
		   AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN {$ageMin} AND {$ageMax}
		   AND {$conditionSql}
		 ORDER BY c.last_name ASC, c.first_name ASC",
		$types,
		$params
	);
}

function pdf_render_list_table(TCPDF $pdf, array $rows, bool $showCategory = false): void {
	$cols = ['No.', 'Address', 'Mother/Caregiver', 'Full Name of Child', 'Sex', 'Birthdate', 'Height (cm)', 'Weight (kg)', 'WFA', 'HFA', 'WFH'];
	$widths = [10, 22, 28, 40, 12, 18, 16, 16, 12, 12, 12];

	if ($showCategory) {
		$cols[] = 'Category';
		$widths[] = 30;
	}

	$pdf->AddPage();
	pdf_table_header($pdf, $cols, $widths);

	$count = 0;
	foreach ($rows as $i => $row) {
		if ($pdf->GetY() > 260) {
			$pdf->AddPage();
			pdf_table_header($pdf, $cols, $widths);
		}

		$fullName = trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? ''));
		$values = [
			$i + 1,
			(string)($row['address'] ?? ''),
			(string)$row['parent_name'],
			$fullName,
			(string)$row['sex'],
			(string)$row['birthdate'],
			$row['height_cm'] !== null ? number_format((float)$row['height_cm'], 1) : '',
			$row['weight_kg'] !== null ? number_format((float)$row['weight_kg'], 2) : '',
			(string)($row['wfa_status'] ?? ''),
			(string)($row['hfa_status'] ?? ''),
			(string)($row['wfh_status'] ?? ''),
		];

		if ($showCategory) {
			$catCodes = followup_abnormal_codes($row['wfa_status'] ?? null, $row['hfa_status'] ?? null, $row['wfh_status'] ?? null);
			$values[] = followup_category_label(implode('+', $catCodes)) ?: '';
		}

		pdf_data_row($pdf, $values, $widths, $i % 2 === 0);
		$count++;
	}

	pdf_totals_row($pdf, 'TOTAL NUMBER OF CHILDREN IN THIS LIST:', $count, $widths);
	pdf_signature_block($pdf);
}

function pdf_generate_form1a(array $f): TCPDF {
	$pdf = pdf_base('OPT Plus Form 1A - Master List of Measured Children', 'Landscape');
	$pdf->AddPage();
	pdf_header_block($pdf, $f['year'], $f['period_label'], $f['barangay_name']);

	$pdf->SetFont('helvetica', 'B', 11);
	$pdf->Cell(0, 7, 'OPT PLUS FORM 1A: MASTER LIST OF MEASURED CHILDREN 0-59 MONTHS OLD', 0, 1, 'C');
	$pdf->Ln(2);
	pdf_metadata_row($pdf, $f['barangay_name'], $f['period_label'], date('F j, Y'));

	$allRows = pdf_fetch_list($f, '1=1', 0, 59);

	$cols = ['No.', 'Address', 'Mother/Caregiver', 'Full Name of Child', 'Sex', 'Birthdate', 'Height (cm)', 'Weight (kg)', 'WFA', 'HFA', 'WFH'];
	$widths = [10, 25, 30, 45, 12, 20, 18, 18, 12, 12, 12];

	pdf_table_header($pdf, $cols, $widths);

	$count = 0;
	foreach ($allRows as $i => $row) {
		if ($pdf->GetY() > 175) {
			$pdf->AddPage();
			pdf_table_header($pdf, $cols, $widths);
		}

		$fullName = trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? ''));
		pdf_data_row($pdf, [
			$count + 1,
			(string)($row['address'] ?? ''),
			(string)$row['parent_name'],
			$fullName,
			(string)$row['sex'],
			(string)$row['birthdate'],
			$row['height_cm'] !== null ? number_format((float)$row['height_cm'], 1) : '',
			$row['weight_kg'] !== null ? number_format((float)$row['weight_kg'], 2) : '',
			(string)($row['wfa_status'] ?? ''),
			(string)($row['hfa_status'] ?? ''),
			(string)($row['wfh_status'] ?? ''),
		], $widths, $i % 2 === 0);
		$count++;
	}

	pdf_totals_row($pdf, 'TOTAL NUMBER OF CHILDREN:', $count, $widths);
	pdf_signature_block($pdf);

	return $pdf;
}

function pdf_generate_form1b(array $f): TCPDF {
	$pdf = pdf_base('OPT Plus Form 1B - Nutritional Status Summary');
	$pdf->AddPage();
	pdf_header_block($pdf, $f['year'], $f['period_label'], $f['barangay_name']);

	$pdf->SetFont('helvetica', 'B', 11);
	$pdf->Cell(0, 7, 'OPT PLUS FORM 1B: NUTRITIONAL STATUS SUMMARY', 0, 1, 'C');
	$pdf->Ln(2);
	pdf_metadata_row($pdf, $f['barangay_name'], $f['period_label'], date('F j, Y'));

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
		 WHERE {$f['scope']}{$f['barangay_filter_sql']}
		   AND TIMESTAMPDIFF(MONTH, c.birthdate, LAST_DAY(?)) BETWEEN 0 AND 59",
		's' . str_repeat('i', count($f['scope_params']) + count($f['barangay_filter_params'])) . 's',
		array_merge([$f['anchor_param']], $f['scope_params'], $f['barangay_filter_params'], [$f['anchor_param']])
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

		foreach ([['wfa_status', &$wfaSummary], ['hfa_status', &$hfaSummary], ['wfh_status', &$wfhSummary]] as [$field, &$ref]) {
			$value = $row[$field] ?? null;
			if ($value === null || !isset($ref[$value])) {
				continue;
			}
			$ref[$value][$sexLabel][$ageBandKey]++;
			$ref[$value][$sexLabel]['Total']++;
			$ref[$value]['Total'][$ageBandKey]++;
			$ref[$value]['Total']['Total']++;
		}
		unset($ref);
	}

	foreach ([
		'WEIGHT-FOR-AGE (WFA)' => $wfaSummary,
		'HEIGHT-FOR-AGE (HFA)' => $hfaSummary,
		'WEIGHT-FOR-LENGTH/HEIGHT (WFH)' => $wfhSummary,
	] as $axisTitle => $summaryTable) {
		$pdf->SetFont('helvetica', 'B', 9);
		$pdf->Cell(0, 6, $axisTitle, 0, 1);
		$pdf->Ln(1);

		$cols = ['Status', 'Boys 0-23', 'Boys 24-59', 'Boys Total', 'Girls 0-23', 'Girls 24-59', 'Girls Total', 'All Total'];
		$widths = [30, 25, 25, 25, 25, 25, 25, 25];
		pdf_table_header($pdf, $cols, $widths);

		$i = 0;
		foreach ($summaryTable as $statusLabel => $counts) {
			pdf_data_row($pdf, [
				$statusLabel,
				(int)$counts['Boys']['0-23'],
				(int)$counts['Boys']['24-59'],
				(int)$counts['Boys']['Total'],
				(int)$counts['Girls']['0-23'],
				(int)$counts['Girls']['24-59'],
				(int)$counts['Girls']['Total'],
				(int)$counts['Total']['Total'],
			], $widths, $i % 2 === 0, ['L', 'C', 'C', 'C', 'C', 'C', 'C', 'C']);
			$i++;
		}

		$pdf->Ln(6);
	}

	pdf_signature_block($pdf);

	return $pdf;
}

function pdf_generate_form1c(array $f): TCPDF {
	$pdf = pdf_base('OPT Plus Form 1C - Affected Children List', 'Landscape');
	$pdf->AddPage();
	pdf_header_block($pdf, $f['year'], $f['period_label'], $f['barangay_name']);

	$pdf->SetFont('helvetica', 'B', 11);
	$pdf->Cell(0, 7, 'OPT PLUS FORM 1C: LIST OF AFFECTED / AT-RISK CHILDREN 0-59 MONTHS OLD', 0, 1, 'C');
	$pdf->Ln(2);
	pdf_metadata_row($pdf, $f['barangay_name'], $f['period_label'], date('F j, Y'));

	$affectedCondition = "(lm.wfa_status IN ('SUW','MUW') OR lm.hfa_status IN ('SSt','MSt') OR lm.wfh_status IN ('SW','MW') OR lm.wfa_status = 'OW' OR lm.wfh_status IN ('OW','Ob'))";
	$rows = pdf_fetch_list($f, $affectedCondition, 0, 59);

	pdf_render_list_table($pdf, $rows, true);

	return $pdf;
}

function pdf_generate_monitoring_list(string $listCode, array $f): TCPDF {
	$listSpecs = [
		'0-23' => ['title' => 'MONITORING LIST FOR CHILDREN 0-23 MONTHS OLD', 'axis' => 'All children (monthly weighing)', 'condition' => '1=1', 'age_min' => 0, 'age_max' => 23],
		'MUAC' => ['title' => 'MONITORING LIST FOR MUAC STATUS', 'axis' => 'MUAC Assessment', 'condition' => '1=1', 'age_min' => 0, 'age_max' => 59],
		'MW' => ['title' => 'MONITORING LIST FOR MODERATELY WASTED CHILDREN (MAM)', 'axis' => 'Weight-for-Height', 'condition' => "lm.wfh_status = 'MW'", 'age_min' => 0, 'age_max' => 59],
		'SW' => ['title' => 'MONITORING LIST FOR SEVERELY WASTED CHILDREN (SAM)', 'axis' => 'Weight-for-Height', 'condition' => "lm.wfh_status = 'SW'", 'age_min' => 0, 'age_max' => 59],
		'MSt_SSt' => ['title' => 'MONITORING LIST FOR MODERATELY OR SEVERELY STUNTED CHILDREN', 'axis' => 'Height-for-Age', 'condition' => "lm.hfa_status IN ('MSt','SSt')", 'age_min' => 0, 'age_max' => 59],
		'OW_Ob' => ['title' => 'MONITORING LIST FOR OVERWEIGHT OR OBESE CHILDREN', 'axis' => 'Weight-for-Age / Weight-for-Height', 'condition' => "(lm.wfa_status = 'OW' OR lm.wfh_status IN ('OW','Ob'))", 'age_min' => 0, 'age_max' => 59],
		'MUW' => ['title' => 'MONITORING LIST FOR MODERATELY UNDERWEIGHT CHILDREN', 'axis' => 'Weight-for-Age', 'condition' => "lm.wfa_status = 'MUW'", 'age_min' => 0, 'age_max' => 59],
		'SUW_MSt_SSt' => ['title' => 'MONITORING LIST FOR SEVERELY UNDERWEIGHT + STUNTED', 'axis' => 'Weight-for-Age + Height-for-Age', 'condition' => "(lm.wfa_status = 'SUW' AND lm.hfa_status IN ('MSt','SSt'))", 'age_min' => 0, 'age_max' => 59],
		'MSt_SSt_MW_SW' => ['title' => 'MONITORING LIST FOR STUNTED + WASTED CHILDREN', 'axis' => 'Height-for-Age + Weight-for-Height', 'condition' => "(lm.hfa_status IN ('MSt','SSt') AND lm.wfh_status IN ('MW','SW'))", 'age_min' => 0, 'age_max' => 59],
		'MSt_SSt_OW_Ob' => ['title' => 'MONITORING LIST FOR STUNTED + OVERWEIGHT/OBESE', 'axis' => 'Height-for-Age + Weight-for-Height', 'condition' => "(lm.hfa_status IN ('MSt','SSt') AND (lm.wfa_status = 'OW' OR lm.wfh_status IN ('OW','Ob')))", 'age_min' => 0, 'age_max' => 59],
	];

	$spec = $listSpecs[$listCode] ?? null;
	if (!$spec) {
		return pdf_base('List Not Found');
	}

	$pdf = pdf_base($spec['title'], 'Landscape');
	$pdf->AddPage();
	pdf_header_block($pdf, $f['year'], $f['period_label'], $f['barangay_name']);

	$pdf->SetFont('helvetica', 'B', 10);
	$pdf->Cell(0, 7, $spec['title'], 0, 1, 'C');
	$pdf->SetFont('helvetica', '', 8);
	$pdf->Cell(0, 5, $spec['axis'] . ' | Age ' . $spec['age_min'] . '-' . $spec['age_max'] . ' months | Year ' . $f['year'], 0, 1, 'C');
	$pdf->Ln(2);
	pdf_metadata_row($pdf, $f['barangay_name'], $f['period_label'], date('F j, Y'));

	$rows = pdf_fetch_list($f, $spec['condition'], $spec['age_min'], $spec['age_max']);
	pdf_render_list_table($pdf, $rows, $listCode !== '0-23' && $listCode !== 'MUAC');

	return $pdf;
}

function pdf_generate_prevalence(array $f): TCPDF {
	$pdf = pdf_base('Prevalence and Graphs Report');
	$pdf->AddPage();
	pdf_header_block($pdf, $f['year'], $f['period_label'], $f['barangay_name']);

	$pdf->SetFont('helvetica', 'B', 11);
	$pdf->Cell(0, 7, 'COMMUNITY-LEVEL PREVALENCE AND NUMBER OF MALNOURISHED CHILDREN', 0, 1, 'C');
	$pdf->Ln(2);
	pdf_metadata_row($pdf, $f['barangay_name'], $f['period_label'], date('F j, Y'));

	$baseTypes = str_repeat('i', count($f['scope_params']) + count($f['barangay_filter_params'])) . 's';
	$baseParams = array_merge($f['scope_params'], $f['barangay_filter_params'], [$f['anchor_param']]);

	$latestJoin = " INNER JOIN measurements lm ON lm.id = (
		SELECT m2.id FROM measurements m2 WHERE m2.child_id = c.id
		ORDER BY m2.measurement_date DESC, m2.id DESC LIMIT 1
	)";

	$allChildren = admin_fetch_all(
		"SELECT c.id, lm.wfa_status, lm.hfa_status, lm.wfh_status, lm.weight_kg, lm.height_cm
		 FROM children c {$latestJoin}
		 WHERE {$f['scope']}{$f['barangay_filter_sql']}
		   AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 59",
		$baseTypes,
		$baseParams
	);

	$total = count($allChildren);

	$counts = [
		'wasted' => 0, 'stunted' => 0, 'ow_ob' => 0,
		'underweight' => 0, 'uw_or_stunted' => 0, 'stunted_or_owob' => 0,
		'muac_normal' => 0, 'muac_mam' => 0, 'muac_sam' => 0,
	];

	foreach ($allChildren as $c) {
		$wfa = $c['wfa_status'] ?? null;
		$hfa = $c['hfa_status'] ?? null;
		$wfh = $c['wfh_status'] ?? null;
		$w = $c['weight_kg'] !== null ? (float)$c['weight_kg'] : null;
		$h = $c['height_cm'] !== null ? (float)$c['height_cm'] : null;

		if (in_array($wfh, ['MW', 'SW'], true)) $counts['wasted']++;
		if (in_array($hfa, ['MSt', 'SSt'], true)) $counts['stunted']++;
		if ($wfa === 'OW' || in_array($wfh, ['OW', 'Ob'], true)) $counts['ow_ob']++;
		if (in_array($wfa, ['MUW', 'SUW'], true)) $counts['underweight']++;
		if (in_array($wfa, ['MUW', 'SUW'], true) || in_array($hfa, ['MSt', 'SSt'], true)) $counts['uw_or_stunted']++;
		if (in_array($hfa, ['MSt', 'SSt'], true) || ($wfa === 'OW' || in_array($wfh, ['OW', 'Ob'], true))) $counts['stunted_or_owob']++;

		if ($w !== null && $h !== null && $h > 0) {
			$muacEst = ($w / $h) * 10;
			if ($muacEst >= 12.5) $counts['muac_normal']++;
			elseif ($muacEst >= 11.5) $counts['muac_mam']++;
			else $counts['muac_sam']++;
		}
	}

	$pct = $total > 0 ? fn(int $n): string => number_format(($n / $total) * 100, 1) . '%' : fn(int $n): string => '0.0%';

	$indicators = [
		['Wasted (MW + SW)', $counts['wasted']],
		['Stunted (MSt + SSt)', $counts['stunted']],
		['Overweight / Obese', $counts['ow_ob']],
		['Underweight (MUW + SUW)', $counts['underweight']],
		['Underweight and/or Stunted', $counts['uw_or_stunted']],
		['Stunted and/or OW/Obese', $counts['stunted_or_owob']],
	];

	$cols = ['Indicator', 'Number', 'Prevalence'];
	$widths = [80, 30, 30];
	pdf_table_header($pdf, $cols, $widths);

	$i = 0;
	foreach ($indicators as [$label, $count]) {
		pdf_data_row($pdf, [$label, $count, $pct($count)], $widths, $i % 2 === 0, ['L', 'C', 'C']);
		$i++;
	}

	$pdf->Ln(6);
	$pdf->SetFont('helvetica', 'B', 9);
	$pdf->Cell(0, 6, 'MUAC STATUS DISTRIBUTION', 0, 1);
	$pdf->Ln(1);

	$muacTotal = $counts['muac_normal'] + $counts['muac_mam'] + $counts['muac_sam'];
	$muacPct = $muacTotal > 0 ? fn(int $n): string => number_format(($n / $muacTotal) * 100, 1) . '%' : fn(int $n): string => '0.0%';

	pdf_table_header($pdf, ['MUAC Status', 'Number', 'Percentage'], $widths);
	pdf_data_row($pdf, ['Normal (>=12.5 cm)', $counts['muac_normal'], $muacPct($counts['muac_normal'])], $widths, true, ['L', 'C', 'C']);
	pdf_data_row($pdf, ['MAM (11.5-12.4 cm)', $counts['muac_mam'], $muacPct($counts['muac_mam'])], $widths, false, ['L', 'C', 'C']);
	pdf_data_row($pdf, ['SAM (<11.5 cm)', $counts['muac_sam'], $muacPct($counts['muac_sam'])], $widths, true, ['L', 'C', 'C']);

	$pdf->Ln(4);
	$pdf->SetFont('helvetica', '', 8);
	$pdf->Cell(0, 5, 'Total children assessed (0-59 months): ' . $total, 0, 1);

	pdf_signature_block($pdf);

	return $pdf;
}

function pdf_generate_dqc(array $f): TCPDF {
	$pdf = pdf_base('Data Quality Check Report');
	$pdf->AddPage();
	pdf_header_block($pdf, $f['year'], $f['period_label'], $f['barangay_name']);

	$pdf->SetFont('helvetica', 'B', 11);
	$pdf->Cell(0, 7, 'DATA QUALITY CHECK (DQC) SUMMARY', 0, 1, 'C');
	$pdf->Ln(2);
	pdf_metadata_row($pdf, $f['barangay_name'], $f['period_label'], date('F j, Y'));

	$totalRecords = admin_scalar(
		"SELECT COUNT(*) FROM children c WHERE 1=1 {$f['barangay_filter_sql']}",
		str_repeat('i', count($f['barangay_filter_params'])),
		$f['barangay_filter_params']
	);

	$completeRecords = admin_scalar(
		"SELECT COUNT(DISTINCT c.id) FROM children c
		 INNER JOIN parents p ON p.id = c.parent_id
		 INNER JOIN measurements m ON m.child_id = c.id
		 WHERE c.sex IS NOT NULL AND c.birthdate IS NOT NULL
		   AND c.first_name != '' AND c.last_name != ''
		   AND p.name IS NOT NULL AND p.name != ''
		   AND m.height_cm IS NOT NULL AND m.weight_kg IS NOT NULL
		   AND {$f['scope']}{$f['barangay_filter_sql']}",
		str_repeat('i', count($f['scope_params']) + count($f['barangay_filter_params'])),
		array_merge($f['scope_params'], $f['barangay_filter_params'])
	);

	$dqIssues = [
		['Repeated name and birthdate', count(admin_fetch_all(
			"SELECT 1 FROM children c1 GROUP BY c1.first_name, c1.last_name, c1.birthdate HAVING COUNT(*) > 1", '', []
		))],
		['Missing sex', admin_scalar("SELECT COUNT(*) FROM children c WHERE c.sex IS NULL AND {$f['scope']}{$f['barangay_filter_sql']}",
			str_repeat('i', count($f['scope_params']) + count($f['barangay_filter_params'])),
			array_merge($f['scope_params'], $f['barangay_filter_params']))],
		['Missing date of birth', admin_scalar("SELECT COUNT(*) FROM children c WHERE c.birthdate IS NULL AND {$f['scope']}{$f['barangay_filter_sql']}",
			str_repeat('i', count($f['scope_params']) + count($f['barangay_filter_params'])),
			array_merge($f['scope_params'], $f['barangay_filter_params']))],
		['No parent or address information', admin_scalar(
			"SELECT COUNT(*) FROM children c LEFT JOIN parents p ON p.id = c.parent_id
			 WHERE (p.id IS NULL OR p.name IS NULL OR p.name = '') AND {$f['scope']}{$f['barangay_filter_sql']}",
			str_repeat('i', count($f['scope_params']) + count($f['barangay_filter_params'])),
			array_merge($f['scope_params'], $f['barangay_filter_params']))],
		['Children older than 59 months', admin_scalar(
			"SELECT COUNT(*) FROM children c WHERE c.birthdate IS NOT NULL
			 AND TIMESTAMPDIFF(YEAR, c.birthdate, CURDATE()) > 4 AND {$f['scope']}{$f['barangay_filter_sql']}",
			str_repeat('i', count($f['scope_params']) + count($f['barangay_filter_params'])),
			array_merge($f['scope_params'], $f['barangay_filter_params']))],
		['Height recorded but no weight', admin_scalar(
			"SELECT COUNT(DISTINCT c.id) FROM children c INNER JOIN measurements m ON m.child_id = c.id
			 WHERE m.height_cm IS NOT NULL AND m.weight_kg IS NULL AND {$f['scope']}{$f['barangay_filter_sql']}",
			str_repeat('i', count($f['scope_params']) + count($f['barangay_filter_params'])),
			array_merge($f['scope_params'], $f['barangay_filter_params']))],
		['Weight recorded but no height/length', admin_scalar(
			"SELECT COUNT(DISTINCT c.id) FROM children c INNER JOIN measurements m ON m.child_id = c.id
			 WHERE m.weight_kg IS NOT NULL AND m.height_cm IS NULL AND {$f['scope']}{$f['barangay_filter_sql']}",
			str_repeat('i', count($f['scope_params']) + count($f['barangay_filter_params'])),
			array_merge($f['scope_params'], $f['barangay_filter_params']))],
	];

	$pdf->SetFont('helvetica', 'B', 9);
	$pdf->Cell(0, 6, 'SUMMARY', 0, 1);
	$pdf->Ln(1);

	$pdf->SetFont('helvetica', '', 8);
	$pdf->Cell(50, 5, 'Total records:', 0, 0);
	$pdf->Cell(20, 5, (string)$totalRecords, 0, 1);
	$pdf->Cell(50, 5, 'Complete records:', 0, 0);
	$pdf->Cell(20, 5, (string)$completeRecords, 0, 1);
	$pdf->Cell(50, 5, 'Records with issues:', 0, 0);
	$pdf->Cell(20, 5, (string)($totalRecords - $completeRecords), 0, 1);
	$pdf->Ln(4);

	$cols = ['Data Quality Issue', 'Count', 'Severity'];
	$widths = [100, 25, 25];
	pdf_table_header($pdf, $cols, $widths);

	$i = 0;
	$totalIssues = 0;
	foreach ($dqIssues as [$label, $count]) {
		$severity = $count > 0 ? ($count > 5 ? 'High' : 'Medium') : 'OK';
		pdf_data_row($pdf, [$label, $count, $severity], $widths, $i % 2 === 0, ['L', 'C', 'C']);
		$totalIssues += $count;
		$i++;
	}

	pdf_totals_row($pdf, 'TOTAL ISSUES:', $totalIssues, $widths);
	pdf_signature_block($pdf);

	return $pdf;
}

function pdf_generate_nutrition_summary(array $f): TCPDF {
	$pdf = pdf_base('Barangay Nutritional Status Summary');
	$pdf->AddPage();
	pdf_header_block($pdf, $f['year'], $f['period_label'], $f['barangay_name']);

	$pdf->SetFont('helvetica', 'B', 11);
	$pdf->Cell(0, 7, 'BARANGAY NUTRITIONAL STATUS SUMMARY', 0, 1, 'C');
	$pdf->Ln(2);
	pdf_metadata_row($pdf, $f['barangay_name'], $f['period_label'], date('F j, Y'));

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
		 WHERE {$f['scope']}{$f['barangay_filter_sql']}
		   AND TIMESTAMPDIFF(MONTH, c.birthdate, LAST_DAY(?)) BETWEEN 0 AND 59",
		's' . str_repeat('i', count($f['scope_params']) + count($f['barangay_filter_params'])) . 's',
		array_merge([$f['anchor_param']], $f['scope_params'], $f['barangay_filter_params'], [$f['anchor_param']])
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
	}

	$renderAxis = function(string $title, array $data) use ($pdf): void {
		$pdf->SetFont('helvetica', 'B', 9);
		$pdf->Cell(0, 6, $title, 0, 1);
		$pdf->Ln(1);

		$cols = ['Status', 'Male 0-23', 'Male 24-59', 'Male Total', 'Female 0-23', 'Female 24-59', 'Female Total', 'Grand Total'];
		$widths = [28, 22, 22, 22, 22, 22, 22, 22];
		pdf_table_header($pdf, $cols, $widths);

		$i = 0;
		$totalPop = 0;
		foreach ($data as $status => $counts) {
			$gt = (int)$counts['Total']['total'];
			$totalPop += $gt;
			pdf_data_row($pdf, [
				$status,
				(int)$counts['Male']['0-23'], (int)$counts['Male']['24-59'], (int)$counts['Male']['total'],
				(int)$counts['Female']['0-23'], (int)$counts['Female']['24-59'], (int)$counts['Female']['total'],
				$gt,
			], $widths, $i % 2 === 0, ['L','C','C','C','C','C','C','C']);
			$i++;
		}

		$pdf->Ln(4);
	};

	$renderAxis('WEIGHT-FOR-AGE (WFA)', $wfaS);
	$renderAxis('HEIGHT-FOR-AGE (HFA)', $hfaS);
	$renderAxis('WEIGHT-FOR-LENGTH/HEIGHT (WFH)', $wfhS);

	pdf_signature_block($pdf);

	return $pdf;
}

function pdf_generate_referral(int $childId): TCPDF {
	$pdf = pdf_base('OPT Plus Referral Form');

	$child = admin_fetch_one(
		"SELECT c.*, p.name AS parent_name, p.phone AS parent_phone, p.address AS parent_address,
			bg.name AS barangay_name
		 FROM children c
		 INNER JOIN parents p ON p.id = c.parent_id
		 LEFT JOIN barangays bg ON bg.id = c.barangay_id
		 WHERE c.id = ? LIMIT 1",
		'i',
		[$childId]
	);

	if (!$child) {
		$pdf->AddPage();
		$pdf->SetFont('helvetica', 'B', 14);
		$pdf->Cell(0, 10, 'Child not found', 0, 1, 'C');
		return $pdf;
	}

	$latestMeasurement = admin_fetch_one(
		"SELECT * FROM measurements WHERE child_id = ? ORDER BY measurement_date DESC, id DESC LIMIT 1",
		'i',
		[$childId]
	);

	$pdf->AddPage();
	pdf_header_block($pdf, (int)date('Y'), '', '');

	$pdf->SetFont('helvetica', 'B', 12);
	$pdf->Cell(0, 8, 'OPT PLUS REFERRAL FORM', 0, 1, 'C');
	$pdf->Ln(4);

	$pdf->SetFont('helvetica', 'B', 9);
	$pdf->Cell(40, 6, 'CHILD INFORMATION', 0, 1);
	$pdf->SetFont('helvetica', '', 8);

	$fields = [
		['Child Name:', trim(($child['last_name'] ?? '') . ', ' . ($child['first_name'] ?? '') . ' ' . ($child['middle_name'] ?? ''))],
		['Child Code:', (string)($child['child_code'] ?? '')],
		['Sex:', (string)$child['sex']],
		['Birthdate:', (string)$child['birthdate']],
		['Age:', doh_age_in_months((string)$child['birthdate']) . ' months'],
		['Barangay:', (string)($child['barangay_name'] ?? '')],
		['Municipality/City:', 'City of San Fernando, Pampanga'],
		['Parent/Caregiver:', (string)$child['parent_name']],
		['Contact:', (string)($child['parent_phone'] ?? '')],
		['Address:', (string)($child['parent_address'] ?? '')],
	];

	foreach ($fields as [$label, $value]) {
		$pdf->Cell(35, 5, $label, 0, 0);
		$pdf->Cell(120, 5, $value, 0, 1);
	}

	$pdf->Ln(4);

	if ($latestMeasurement) {
		$pdf->SetFont('helvetica', 'B', 9);
		$pdf->Cell(40, 6, 'NUTRITIONAL ASSESSMENT', 0, 1);
		$pdf->SetFont('helvetica', '', 8);

		$mFields = [
			['Measurement Date:', (string)$latestMeasurement['measurement_date']],
			['Weight (kg):', $latestMeasurement['weight_kg'] !== null ? number_format((float)$latestMeasurement['weight_kg'], 2) : 'N/A'],
			['Height/Length (cm):', $latestMeasurement['height_cm'] !== null ? number_format((float)$latestMeasurement['height_cm'], 1) : 'N/A'],
			['MUAC (estimated):', ($latestMeasurement['weight_kg'] !== null && $latestMeasurement['height_cm'] !== null && (float)$latestMeasurement['height_cm'] > 0)
				? number_format(((float)$latestMeasurement['weight_kg'] / (float)$latestMeasurement['height_cm']) * 10, 1) . ' cm'
				: 'N/A'],
			['WFA:', (string)($latestMeasurement['wfa_status'] ?? 'N/A')],
			['HFA:', (string)($latestMeasurement['hfa_status'] ?? 'N/A')],
			['WFH/WFL-H:', (string)($latestMeasurement['wfh_status'] ?? 'N/A')],
			['Nutritional Status:', (string)($latestMeasurement['nutritional_status'] ?? 'N/A')],
		];

		foreach ($mFields as [$label, $value]) {
			$pdf->Cell(35, 5, $label, 0, 0);
			$pdf->Cell(120, 5, $value, 0, 1);
		}

		$wfa = $latestMeasurement['wfa_status'] ?? null;
		$hfa = $latestMeasurement['hfa_status'] ?? null;
		$wfh = $latestMeasurement['wfh_status'] ?? null;

		$pdf->Ln(2);
		$pdf->SetFont('helvetica', 'B', 8);
		$pdf->Cell(35, 5, 'Classification:', 0, 0);
		$codes = followup_abnormal_codes($wfa, $hfa, $wfh);
		$pdf->Cell(120, 5, $codes ? followup_category_label(implode('+', $codes)) : 'Normal', 0, 1);
	}

	$pdf->Ln(6);
	$pdf->SetFont('helvetica', 'B', 9);
	$pdf->Cell(40, 6, 'REFERRAL INFORMATION', 0, 1);
	$pdf->SetFont('helvetica', '', 8);

	$pdf->Cell(35, 5, 'Date of Referral:', 0, 0);
	$pdf->Cell(120, 5, date('F j, Y'), 0, 1);
	$pdf->Cell(35, 5, 'Referring Officer:', 0, 0);
	$pdf->Cell(120, 5, '________________________________', 0, 1);
	$pdf->Cell(35, 5, 'Receiving Facility:', 0, 0);
	$pdf->Cell(120, 5, '________________________________', 0, 1);
	$pdf->Cell(35, 5, 'Receiving Officer:', 0, 0);
	$pdf->Cell(120, 5, '________________________________', 0, 1);

	$pdf->Ln(4);
	$pdf->SetFont('helvetica', 'B', 9);
	$pdf->Cell(40, 6, 'REMARKS', 0, 1);
	$pdf->SetFont('helvetica', '', 8);
	$pdf->MultiCell(0, 5, '____________________________________________________________________________________________________');
	$pdf->MultiCell(0, 5, '____________________________________________________________________________________________________');

	$pdf->Ln(6);
	$pdf->SetFont('helvetica', 'B', 9);
	$pdf->Cell(40, 6, 'RETURN SLIP', 0, 1);
	$pdf->SetFont('helvetica', '', 8);
	$pdf->Cell(35, 5, 'Date Received:', 0, 0);
	$pdf->Cell(120, 5, '________________________________', 0, 1);
	$pdf->Cell(35, 5, 'Received By:', 0, 0);
	$pdf->Cell(120, 5, '________________________________', 0, 1);
	$pdf->Cell(35, 5, 'Action Taken:', 0, 0);
	$pdf->Cell(120, 5, '________________________________', 0, 1);

	pdf_signature_block($pdf);

	return $pdf;
}

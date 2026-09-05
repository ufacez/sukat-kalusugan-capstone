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

	$userBarangayId = (int)($user['barangay_id'] ?? 0);
	if ($userBarangayId > 0) {
		$brgyRow = admin_fetch_one('SELECT name FROM barangays WHERE id = ? LIMIT 1', 'i', [$userBarangayId]);
		$barangayName = (string)($brgyRow['name'] ?? '');
	} elseif ($barangayFilter > 0) {
		$barangayFilterSql = ' AND c.barangay_id = ?';
		$barangayFilterParams[] = $barangayFilter;
		$brgyRow = admin_fetch_one('SELECT name FROM barangays WHERE id = ? LIMIT 1', 'i', [$barangayFilter]);
		$barangayName = (string)($brgyRow['name'] ?? $barangayName);
	}

	try {
		$anchorDate = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $view === 'monthly' ? $month : $checkupMonth)))->modify('last day of this month');
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
	$params = array_merge([$f['anchor_param']], $f['scope_params'], [$f['anchor_param']]);
	$types = 's' . str_repeat('i', count($f['scope_params'])) . 's';

	return admin_fetch_all(
		"SELECT
			c.id, c.child_code, c.first_name, c.middle_name, c.last_name,
			c.sex, c.birthdate, la.area_name AS address,
			bg.name AS barangay, p.name AS parent_name,
			lm.measurement_date, lm.height_cm, lm.weight_kg,
			lm.wfa_status, lm.hfa_status, lm.wfh_status, lm.is_flagged
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
		 WHERE {$f['scope']}
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
	$pdf = pdf_base('OPT Plus Form 1A - Preschool Master List', 'Landscape');
	$pdf->AddPage();
	pdf_header_block($pdf, $f['year'], $f['period_label'], $f['barangay_name']);

	$pdf->SetFont('helvetica', 'B', 10);
	$pdf->Cell(0, 6, 'OPT PLUS FORM 1A: PRE-PRINTED LIST OF PRESCHOOL CHILDREN IN THE BARANGAY', 0, 1, 'C');
	$pdf->SetFont('helvetica', '', 7);
	$pdf->Cell(0, 5, 'Names are alphabetically arranged. Add new or previously unlisted children at the end of this list.', 0, 1, 'C');
	pdf_metadata_row($pdf, $f['barangay_name'], $f['period_label'], date('F j, Y'));

	$allRows = admin_fetch_all(
		"SELECT
			c.child_code, c.first_name, c.middle_name, c.last_name, c.sex,
			c.birthdate, c.is_ip, c.has_disability, la.area_name AS address,
			p.name AS parent_name, lm.measurement_date, lm.height_cm, lm.weight_kg,
			lm.wfh_status,
			DATEDIFF(?, c.birthdate) AS age_days,
			TIMESTAMPDIFF(MONTH, c.birthdate, ?) AS age_months
		 FROM children c
		 INNER JOIN parents p ON p.id = c.parent_id
		 LEFT JOIN local_areas la ON la.id = c.local_area_id
		 INNER JOIN measurements lm ON lm.id = (
			SELECT m2.id FROM measurements m2
			WHERE m2.child_id = c.id AND m2.measurement_date <= ?
			ORDER BY m2.measurement_date DESC, m2.id DESC
			LIMIT 1
		 )
		 WHERE {$f['scope']}{$f['barangay_filter_sql']}
		   AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 59
		 ORDER BY c.last_name ASC, c.first_name ASC, c.middle_name ASC",
		'ssss' . str_repeat('i', count($f['scope_params']) + count($f['barangay_filter_params'])),
		array_merge([$f['anchor_param'], $f['anchor_param'], $f['anchor_param'], $f['anchor_param']], $f['scope_params'], $f['barangay_filter_params'])
	);

	$cols = ['Child ID', 'Address / Location', 'Mother / Guardian', 'Full Name of Child', 'IP?', 'Sex', 'Date of Birth', 'Date of Measurement', 'Weight (kg)', 'Height (cm)', 'Age in Months', 'Age in Days', 'Nutritional Status (WFL/H)', 'Disability'];
	$widths = [14, 24, 29, 41, 10, 10, 17, 20, 16, 16, 15, 15, 31, 15];

	pdf_table_header($pdf, $cols, $widths);

	foreach ($allRows as $i => $row) {
		if ($pdf->GetY() > 175) {
			$pdf->AddPage();
			pdf_table_header($pdf, $cols, $widths);
		}

		$fullName = trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? ''));
		pdf_data_row($pdf, [
			(string)($row['child_code'] ?? ''),
			(string)($row['address'] ?? ''),
			(string)($row['parent_name'] ?? ''),
			$fullName,
			!empty($row['is_ip']) ? 'YES' : 'NO',
			(string)($row['sex'] ?? ''),
			(string)($row['birthdate'] ?? ''),
			(string)($row['measurement_date'] ?? ''),
			$row['weight_kg'] !== null ? number_format((float)$row['weight_kg'], 2) : '',
			$row['height_cm'] !== null ? number_format((float)$row['height_cm'], 1) : '',
			(int)$row['age_months'],
			(int)$row['age_days'],
			(string)($row['wfh_status'] ?? ''),
			!empty($row['has_disability']) ? 'YES' : 'NO',
		], $widths, $i % 2 === 0, array_fill(0, count($cols), 'C'));
	}

	for ($blankRow = 0; $blankRow < 5; $blankRow++) {
		pdf_data_row($pdf, array_fill(0, count($cols), ''), $widths, false);
	}

	pdf_totals_row($pdf, 'TOTAL NUMBER OF CHILDREN:', count($allRows), $widths);
	pdf_signature_block($pdf);

	return $pdf;
}

function pdf_generate_nutstatus(array $f): TCPDF {
	$pdf = pdf_base('NutStatusTool - Community Level e-OPT Plus Tool', 'Landscape');
	$pdf->AddPage();
	pdf_header_block($pdf, $f['year'], $f['period_label'], $f['barangay_name']);

	$pdf->SetFont('helvetica', 'B', 10);
	$pdf->Cell(0, 7, 'COMMUNITY LEVEL e-OPT PLUS TOOL: NUTRITIONAL STATUS', 0, 1, 'C');
	$pdf->SetFont('helvetica', '', 7);
	$pdf->Cell(0, 5, 'Region III - Central Luzon | Province: Pampanga | Municipality/City: City of San Fernando', 0, 1, 'C');
	$pdf->Ln(2);
	pdf_metadata_row($pdf, $f['barangay_name'], $f['period_label'], date('F j, Y'));

	$rows = admin_fetch_all(
		"SELECT
			c.child_code, c.first_name, c.middle_name, c.last_name, c.sex,
			c.birthdate, c.is_ip, c.has_disability, la.area_name AS address,
			p.name AS parent_name, lm.measurement_date, lm.height_cm, lm.weight_kg,
			lm.wfa_status, lm.hfa_status, lm.wfh_status,
			DATEDIFF(?, c.birthdate) AS age_days,
			TIMESTAMPDIFF(MONTH, c.birthdate, ?) AS age_months
		 FROM children c
		 INNER JOIN parents p ON p.id = c.parent_id
		 LEFT JOIN local_areas la ON la.id = c.local_area_id
		 INNER JOIN measurements lm ON lm.id = (
			SELECT m2.id FROM measurements m2
			WHERE m2.child_id = c.id AND m2.measurement_date <= ?
			ORDER BY m2.measurement_date DESC, m2.id DESC
			LIMIT 1
		 )
		 WHERE {$f['scope']}{$f['barangay_filter_sql']}
		   AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 59
		 ORDER BY c.last_name ASC, c.first_name ASC",
		'ssss' . str_repeat('i', count($f['scope_params']) + count($f['barangay_filter_params'])),
		array_merge([$f['anchor_param'], $f['anchor_param'], $f['anchor_param'], $f['anchor_param']], $f['scope_params'], $f['barangay_filter_params'])
	);

	$columns = [
		'Child ID', 'Address / Location', 'Mother / Guardian', 'Full Name', 'IP?', 'Sex',
		'Date of Birth', 'Date Measured', 'Weight kg', 'Height cm', 'Age mo.', 'Age days',
		'WFA Status', 'HFA Status', 'WFL/H Status', 'Disability',
	];
	$widths = [13, 22, 25, 31, 10, 10, 16, 18, 14, 14, 12, 13, 22, 20, 24, 14];
	pdf_table_header($pdf, $columns, $widths);

	foreach ($rows as $index => $row) {
		if ($pdf->GetY() > 180) {
			$pdf->AddPage();
			pdf_table_header($pdf, $columns, $widths);
		}

		$fullName = trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? ''));
		$wfaStatus = (string)($row['wfa_status'] ?? '');
		if ($wfaStatus === 'Refer to WFL/H') {
			$wfaStatus = 'Use WFL/H column';
		}

		pdf_data_row($pdf, [
			(string)($row['child_code'] ?? ''),
			(string)($row['address'] ?? ''),
			(string)($row['parent_name'] ?? ''),
			$fullName,
			!empty($row['is_ip']) ? 'YES' : 'NO',
			(string)($row['sex'] ?? ''),
			(string)($row['birthdate'] ?? ''),
			(string)($row['measurement_date'] ?? ''),
			$row['weight_kg'] !== null ? number_format((float)$row['weight_kg'], 2) : '',
			$row['height_cm'] !== null ? number_format((float)$row['height_cm'], 1) : '',
			(int)$row['age_months'],
			(int)$row['age_days'],
			$wfaStatus,
			(string)($row['hfa_status'] ?? ''),
			(string)($row['wfh_status'] ?? ''),
			!empty($row['has_disability']) ? 'YES' : 'NO',
		], $widths, $index % 2 === 0, array_fill(0, count($columns), 'C'));
	}

	pdf_totals_row($pdf, 'TOTAL NUMBER OF CHILDREN:', count($rows), $widths);
	pdf_signature_block($pdf);

	return $pdf;
}

function pdf_generate_form1b(array $f): TCPDF {
	$pdf = pdf_base('OPT Plus Form 1B - Nutritional Status Consolidation', 'Landscape');
	$pdf->AddPage();
	pdf_header_block($pdf, $f['year'], $f['period_label'], $f['barangay_name']);

	$pdf->SetFont('helvetica', 'B', 10);
	$pdf->Cell(0, 7, 'OPT PLUS FORM 1B: SUMMARY SHEET OF THE NUTRITIONAL STATUS OF 0-59 MONTH-OLD CHILDREN', 0, 1, 'C');
	$pdf->SetFont('helvetica', '', 7);
	$pdf->Cell(0, 5, 'Automated consolidation from SukatKalusugan database | WFA > +2 SD is referred to WFL/H for weight-related classification.', 0, 1, 'C');
	$pdf->Ln(2);
	pdf_metadata_row($pdf, $f['barangay_name'], $f['period_label'], date('F j, Y'));

	$summaryRows = admin_fetch_all(
		"SELECT
			c.first_name, c.last_name, c.birthdate, c.sex, c.is_ip, c.has_disability,
			m.wfa_status, m.hfa_status, m.wfh_status,
			m.height_cm, m.weight_kg
		 FROM children c
		 INNER JOIN measurements m ON m.id = (
			SELECT m2.id FROM measurements m2 WHERE m2.child_id = c.id
			ORDER BY m2.measurement_date DESC, m2.id DESC LIMIT 1
		 )
		 WHERE {$f['scope']}{$f['barangay_filter_sql']}",
		str_repeat('i', count($f['scope_params']) + count($f['barangay_filter_params'])),
		array_merge($f['scope_params'], $f['barangay_filter_params'])
	);

	$ageGroups = ['0-5' => [0, 5], '6-11' => [6, 11], '12-23' => [12, 23], '24-35' => [24, 35], '36-47' => [36, 47], '48-59' => [48, 59]];
	$statusGroups = [
		'WFA' => ['Normal' => 'Normal', 'MUW' => 'Underweight', 'SUW' => 'Severe Underweight', 'Refer to WFL/H' => 'Referred to WFL/H'],
		'HFA' => ['Normal' => 'Normal', 'Tall' => 'Tall', 'MSt' => 'Stunted / MSt', 'SSt' => 'Severely Stunted / SSt'],
		'WFL/H' => ['Normal' => 'Normal', 'OW' => 'Overweight', 'Ob' => 'Obese', 'MW' => 'Wasted / MAM', 'SW' => 'Wasted / SAM'],
	];
	$summary = [];
	foreach ($statusGroups as $axis => $statuses) {
		foreach ($statuses as $code => $label) {
			$summary[$axis][$code] = ['Boys' => 0, 'Girls' => 0, 'Total' => 0, 'ages' => array_fill_keys(array_keys($ageGroups), 0), 'ip_boys' => 0, 'ip_girls' => 0];
		}
	}
	$totalAssessed = 0;
	$disabilityCount = 0;
	$ipCount = 0;
	$anchor = $f['anchor_date'];
	foreach ($summaryRows as $row) {
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
		$sex = (string)$row['sex'] === 'Male' ? 'Boys' : 'Girls';
		$ageGroup = null;
		foreach ($ageGroups as $group => [$min, $max]) {
			if ($ageMonths >= $min && $ageMonths <= $max) {
				$ageGroup = $group;
				break;
			}
		}
		foreach ([['WFA', $row['wfa_status']], ['HFA', $row['hfa_status']], ['WFL/H', $row['wfh_status']]] as [$axis, $code]) {
			if (isset($summary[$axis][$code])) {
				$summary[$axis][$code][$sex]++;
				$summary[$axis][$code]['Total']++;
				$summary[$axis][$code]['ages'][$ageGroup]++;
				if (!empty($row['is_ip'])) {
					$summary[$axis][$code]['ip_' . strtolower($sex)]++;
				}
			}
		}
		if (!empty($row['has_disability'])) {
			$disabilityCount++;
		}
		if (!empty($row['is_ip'])) {
			$ipCount++;
		}
	}

	$pdf->SetFont('helvetica', 'B', 8);
	$pdf->Cell(0, 6, 'Coverage and prevalence information', 0, 1);
	$pdf->SetFont('helvetica', '', 8);
	$pdf->Cell(65, 5, 'Barangay:', 0, 0); $pdf->Cell(70, 5, $f['barangay_name'], 0, 0);
	$pdf->Cell(65, 5, 'Municipality / Province:', 0, 0); $pdf->Cell(70, 5, 'City of San Fernando, Pampanga', 0, 1);
	$pdf->Cell(65, 5, 'PSGC:', 0, 0); $pdf->Cell(70, 5, 'Not configured', 0, 0);
	$pdf->Cell(65, 5, 'Reporting year:', 0, 0); $pdf->Cell(70, 5, (string)$f['year'], 0, 1);
	$pdf->Cell(65, 5, 'Estimated population (0-59 months):', 0, 0); $pdf->Cell(70, 5, 'Not configured', 0, 0);
	$pdf->Cell(65, 5, 'OPT Plus coverage:', 0, 0); $pdf->Cell(70, 5, (string)$totalAssessed . ' assessed', 0, 1);
	$pdf->Cell(65, 5, 'Total children assessed (0-59 months):', 0, 0); $pdf->Cell(70, 5, (string)$totalAssessed, 0, 0);
	$pdf->Cell(65, 5, 'Indigenous preschool children:', 0, 0); $pdf->Cell(70, 5, (string)$ipCount, 0, 1);
	$pdf->Cell(65, 5, 'Children with disability:', 0, 0); $pdf->Cell(70, 5, (string)$disabilityCount, 0, 1);
	$pdf->Ln(3);

	$cols = ['Classification', 'Boys', 'Girls', 'Total', '0-5', '6-11', '12-23', '24-35', '36-47', '48-59', 'Birth-5 Total', 'Birth-5 %', '0-23 Total', '0-23 %', 'IP Boys', 'IP Girls', 'IP Total'];
	$widths = [34, 9, 9, 9, 9, 9, 9, 9, 9, 9, 14, 12, 14, 12, 9, 9, 9];
	$pdf->SetFont('helvetica', 'B', 8);
	$pdf->Cell(0, 6, 'NUTRITIONAL STATUS CONSOLIDATION TABLE', 0, 1);
	pdf_table_header($pdf, $cols, $widths);
	$rowIndex = 0;
	foreach ($summary as $axis => $summaryTable) {
		foreach ($summaryTable as $code => $counts) {
			$birthToFive = $counts['Total'];
			$zeroToTwentyThree = array_sum(array_slice($counts['ages'], 0, 3));
			$ipTotal = $counts['ip_boys'] + $counts['ip_girls'];
			$values = [$axis . ' - ' . $statusGroups[$axis][$code], $counts['Boys'], $counts['Girls'], $counts['Total']];
			foreach (array_keys($ageGroups) as $group) {
				$values[] = $counts['ages'][$group];
			}
			$values[] = $birthToFive;
			$values[] = $totalAssessed > 0 ? number_format(($birthToFive / $totalAssessed) * 100, 2) . '%' : '0.00%';
			$values[] = $zeroToTwentyThree;
			$values[] = $totalAssessed > 0 ? number_format(($zeroToTwentyThree / $totalAssessed) * 100, 2) . '%' : '0.00%';
			$values[] = $counts['ip_boys'];
			$values[] = $counts['ip_girls'];
			$values[] = $ipTotal;
			pdf_data_row($pdf, $values, $widths, $rowIndex % 2 === 0, array_merge(['L'], array_fill(0, 16, 'C')));
			$rowIndex++;
		}
	}

	$pdf->SetFont('helvetica', 'B', 9);
	$pdf->Cell(0, 6, 'Required nutrition and data-quality summaries', 0, 1);
	$pdf->SetFont('helvetica', '', 8);
	$qualityRowsSource = admin_fetch_all(
		"SELECT c.first_name, c.last_name, c.birthdate, c.sex, c.local_area_id,
			p.name AS parent_name, p.address AS parent_address,
			m.height_cm, m.weight_kg
		 FROM children c
		 LEFT JOIN parents p ON p.id = c.parent_id
		 LEFT JOIN measurements m ON m.id = (
			SELECT m2.id FROM measurements m2 WHERE m2.child_id = c.id
			ORDER BY m2.measurement_date DESC, m2.id DESC LIMIT 1
		 )
		 WHERE {$f['scope']}{$f['barangay_filter_sql']}",
		str_repeat('i', count($f['scope_params']) + count($f['barangay_filter_params'])),
		array_merge($f['scope_params'], $f['barangay_filter_params'])
	);
	$duplicateKeys = [];
	$missingInformation = 0;
	$noParentAddress = 0;
	$noSex = 0;
	$olderThan59 = 0;
	$heightWithoutWeight = 0;
	$weightWithoutHeight = 0;
	foreach ($qualityRowsSource as $qualityRow) {
		$key = strtolower(trim((string)$qualityRow['first_name'] . '|' . (string)$qualityRow['last_name'] . '|' . (string)$qualityRow['birthdate']));
		$duplicateKeys[$key] = ($duplicateKeys[$key] ?? 0) + 1;
		if (trim((string)$qualityRow['first_name']) === '' || trim((string)$qualityRow['last_name']) === '' || trim((string)$qualityRow['birthdate']) === '') {
			$missingInformation++;
		}
		if (trim((string)$qualityRow['parent_name']) === '' || (int)($qualityRow['local_area_id'] ?? 0) === 0 && trim((string)$qualityRow['parent_address']) === '') {
			$noParentAddress++;
		}
		if (trim((string)$qualityRow['sex']) === '') {
			$noSex++;
		}
		try {
			$qualityAge = (new DateTimeImmutable((string)$qualityRow['birthdate']))->diff($anchor);
			if (($qualityAge->y * 12) + $qualityAge->m > 59) {
				$olderThan59++;
			}
		} catch (Exception) {
			$missingInformation++;
		}
		if ($qualityRow['height_cm'] !== null && $qualityRow['weight_kg'] === null) {
			$heightWithoutWeight++;
		}
		if ($qualityRow['weight_kg'] !== null && $qualityRow['height_cm'] === null) {
			$weightWithoutHeight++;
		}
	}
	$repeatedChildren = count(array_filter($duplicateKeys, static fn($count) => $count > 1));
	$qualityRows = [
		['Total children assessed', $totalAssessed],
		['Children with names and birthdate repeated', $repeatedChildren],
		['Children with missing information', $missingInformation],
		['Children with no parent/address', $noParentAddress],
		['Children with no sex data', $noSex],
		['Children older than 59 months', $olderThan59],
		['Children with length/height but no weight', $heightWithoutWeight],
		['Children with weight but no length/height', $weightWithoutHeight],
	];
	foreach ($qualityRows as [$label, $value]) {
		$pdf->Cell(115, 5, $label, 1, 0, 'L');
		$pdf->Cell(25, 5, (string)$value, 1, 1, 'C');
	}

	pdf_signature_block($pdf);

	return $pdf;
}

function pdf_generate_nutstatusbrgy(array $f): TCPDF {
	$pdf = pdf_base('NutStatusBrgy - Barangay Nutritional Status Summary', 'Landscape');
	$pdf->AddPage();
	pdf_header_block($pdf, $f['year'], $f['period_label'], $f['barangay_name']);
	$pdf->SetFont('helvetica', 'B', 10);
	$pdf->Cell(0, 7, 'NUTRITIONAL STATUS OF CHILDREN 0-23 AND 0-59 MONTHS OLD', 0, 1, 'C');
	$pdf->SetFont('helvetica', '', 7);
	$pdf->Cell(0, 5, 'SEX-DISAGGREGATED SUMMARY TABLES FOR PRESENTATION | Region III - Central Luzon | Pampanga | City of San Fernando', 0, 1, 'C');
	pdf_metadata_row($pdf, $f['barangay_name'], $f['period_label'], date('F j, Y'));

	$rows = admin_fetch_all(
		"SELECT c.birthdate, c.sex, c.parent_id, m.wfa_status, m.hfa_status, m.wfh_status
		 FROM children c
		 INNER JOIN measurements m ON m.id = (
			SELECT m2.id FROM measurements m2
			WHERE m2.child_id = c.id AND m2.measurement_date <= ?
			ORDER BY m2.measurement_date DESC, m2.id DESC LIMIT 1
		 )
		 WHERE {$f['scope']}{$f['barangay_filter_sql']}",
		's' . str_repeat('i', count($f['scope_params']) + count($f['barangay_filter_params'])),
		array_merge([$f['anchor_param']], $f['scope_params'], $f['barangay_filter_params'])
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
	$affectedParents = ['0-23' => [], '0-59' => []];
	foreach ($rows as $row) {
		try {
			$ageDiff = (new DateTimeImmutable((string)$row['birthdate']))->diff($f['anchor_date']);
			$ageMonths = ($ageDiff->y * 12) + $ageDiff->m;
		} catch (Exception) {
			continue;
		}
		if ($ageMonths < 0 || $ageMonths > 59) continue;
		$sex = (string)$row['sex'] === 'Male' ? 'Boys' : 'Girls';
		$groups = ['0-59'];
		if ($ageMonths <= 23) $groups[] = '0-23';
		foreach ([['WFA', $row['wfa_status']], ['HFA', $row['hfa_status']], ['WFL/H', $row['wfh_status']]] as [$axis, $status]) {
			if ($status !== null && $status !== '') {
				foreach ($groups as $group) {
					$denominators[$axis][$group]++;
					if (isset($summary[$axis][$status])) $summary[$axis][$status][$group][$sex]++;
				}
			}
		}
		$undernutrition = in_array($row['wfa_status'], ['MUW', 'SUW'], true) || in_array($row['hfa_status'], ['MSt', 'SSt'], true) || in_array($row['wfh_status'], ['MW', 'SW'], true);
		if ($undernutrition) {
			$affectedParents['0-59'][(int)$row['parent_id']] = true;
			if ($ageMonths <= 23) $affectedParents['0-23'][(int)$row['parent_id']] = true;
		}
	}

	$pdf->SetFont('helvetica', 'B', 8);
	$pdf->Cell(0, 5, 'Year: ' . $f['year'] . '    Barangay: ' . $f['barangay_name'] . '    Municipality/City: City of San Fernando    Province: Pampanga    PSGC: Not configured', 0, 1, 'C');
	foreach ($summary as $axis => $statuses) {
		$pdf->SetFont('helvetica', 'B', 9);
		$pdf->Cell(0, 6, $axis === 'WFL/H' ? 'WEIGHT FOR LENGTH/HEIGHT' : ($axis === 'HFA' ? 'HEIGHT / LENGTH FOR AGE' : 'WEIGHT FOR AGE'), 0, 1);
		$columns = ['Classification', 'Boys', 'Girls', 'Total', 'Prev', 'Boys', 'Girls', 'Total', 'Prev'];
		$widths = [50, 18, 18, 18, 20, 18, 18, 18, 20];
		pdf_table_header($pdf, $columns, $widths);
		$i = 0;
		foreach ($statuses as $code => $label) {
			$earlyTotal = $summary[$axis][$code]['0-23']['Boys'] + $summary[$axis][$code]['0-23']['Girls'];
			$allTotal = $summary[$axis][$code]['0-59']['Boys'] + $summary[$axis][$code]['0-59']['Girls'];
			$earlyPrev = $denominators[$axis]['0-23'] > 0 ? number_format(($earlyTotal / $denominators[$axis]['0-23']) * 100, 2) . '%' : '0.00%';
			$allPrev = $denominators[$axis]['0-59'] > 0 ? number_format(($allTotal / $denominators[$axis]['0-59']) * 100, 2) . '%' : '0.00%';
			$statusLabel = $definitions[$axis][$code] ?? $code;
			pdf_data_row($pdf, [$statusLabel, $summary[$axis][$code]['0-23']['Boys'], $summary[$axis][$code]['0-23']['Girls'], $earlyTotal, $earlyPrev, $summary[$axis][$code]['0-59']['Boys'], $summary[$axis][$code]['0-59']['Girls'], $allTotal, $allPrev], $widths, $i % 2 === 0, ['L', 'C', 'C', 'C', 'C', 'C', 'C', 'C', 'C']);
			$i++;
		}
		$pdf->Ln(3);
	}
	$pdf->SetFont('helvetica', 'B', 8);
	$pdf->Cell(105, 8, 'TOTAL NUMBER OF MOTHERS/CAREGIVERS OF CHILDREN 0-59 MONTHS AFFECTED BY UNDERNUTRITION', 1, 0, 'C');
	$pdf->Cell(25, 8, (string)count($affectedParents['0-59']), 1, 0, 'C');
	$pdf->Cell(105, 8, 'TOTAL NUMBER OF MOTHERS/CAREGIVERS OF CHILDREN 0-23 MONTHS AFFECTED BY UNDERNUTRITION', 1, 0, 'C');
	$pdf->Cell(25, 8, (string)count($affectedParents['0-23']), 1, 1, 'C');
	pdf_signature_block($pdf);

	return $pdf;
}

function pdf_generate_form1c(array $f): TCPDF {
	$pdf = pdf_base('OPT Plus Form 1C - Affected / At-Risk Children', 'Landscape');
	$pdf->AddPage();
	pdf_header_block($pdf, $f['year'], $f['period_label'], $f['barangay_name']);

	$rows = admin_fetch_all(
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
		 WHERE {$f['scope']}{$f['barangay_filter_sql']}
		   AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 59
		   AND (lm.wfa_status IN ('SUW','MUW') OR lm.hfa_status IN ('SSt','MSt') OR lm.wfh_status IN ('SW','MW','OW','Ob'))
		 ORDER BY c.last_name ASC, c.first_name ASC, c.middle_name ASC",
		'ss' . str_repeat('i', count($f['scope_params']) + count($f['barangay_filter_params'])) . 's',
		array_merge([$f['anchor_param'], $f['anchor_param']], $f['scope_params'], $f['barangay_filter_params'], [$f['anchor_param']])
	);

	$counts = ['MUW' => 0, 'SUW' => 0, 'MSt' => 0, 'SSt' => 0, 'MW/MAM' => 0, 'SW/SAM' => 0, 'OW' => 0, 'Ob' => 0, 'undernutrition' => 0, 'overweight' => 0];
	foreach ($rows as $row) {
		$wfaAffected = in_array($row['wfa_status'], ['MUW', 'SUW'], true);
		$hfaAffected = in_array($row['hfa_status'], ['MSt', 'SSt'], true);
		$wfhAffected = in_array($row['wfh_status'], ['MW', 'SW', 'OW', 'Ob'], true);
		foreach ([['MUW', $row['wfa_status'] === 'MUW'], ['SUW', $row['wfa_status'] === 'SUW'], ['MSt', $row['hfa_status'] === 'MSt'], ['SSt', $row['hfa_status'] === 'SSt'], ['MW/MAM', $row['wfh_status'] === 'MW'], ['SW/SAM', $row['wfh_status'] === 'SW'], ['OW', $row['wfh_status'] === 'OW'], ['Ob', $row['wfh_status'] === 'Ob']] as [$key, $matches]) {
			if ($matches) $counts[$key]++;
		}
		if ($wfaAffected || $hfaAffected || in_array($row['wfh_status'], ['MW', 'SW'], true)) $counts['undernutrition']++;
		if (in_array($row['wfh_status'], ['OW', 'Ob'], true)) $counts['overweight']++;
	}

	$pdf->SetFont('helvetica', 'B', 10);
	$pdf->Cell(0, 7, 'OPT PLUS FORM 1C: LIST OF AFFECTED / AT-RISK 0-59 MONTH-OLD CHILDREN', 0, 1, 'C');
	$pdf->SetFont('helvetica', '', 7);
	$pdf->Cell(0, 5, 'Region III - Central Luzon | Province: Pampanga | Municipality/City: City of San Fernando', 0, 1, 'C');
	pdf_metadata_row($pdf, $f['barangay_name'], $f['period_label'], date('F j, Y'));

	$summary = 'Total affected/at-risk: ' . count($rows) . '    MUW: ' . $counts['MUW'] . '    SUW: ' . $counts['SUW'] . '    MSt: ' . $counts['MSt'] . '    SSt: ' . $counts['SSt'] . '    MW/MAM: ' . $counts['MW/MAM'] . '    SW/SAM: ' . $counts['SW/SAM'] . '    OW: ' . $counts['OW'] . '    Ob: ' . $counts['Ob'];
	$pdf->SetFont('helvetica', 'B', 7);
	$pdf->MultiCell(0, 6, $summary, 1, 'C', false, 1);
	$pdf->SetFont('helvetica', '', 7);
	$pdf->Cell(0, 5, 'Affected by undernutrition: ' . $counts['undernutrition'] . '    Overweight or obesity: ' . $counts['overweight'], 1, 1, 'C');
	$pdf->Ln(2);

	$columns = ['Address / Purok / Local Area', 'Mother / Caregiver', 'Full Name of Child', 'Sex', 'Age in Months', 'WFA', 'HFA', 'WFL/H'];
	$widths = [37, 36, 46, 13, 17, 29, 29, 30];
	pdf_table_header($pdf, $columns, $widths);
	foreach ($rows as $index => $row) {
		if ($pdf->GetY() > 180) {
			$pdf->AddPage();
			pdf_table_header($pdf, $columns, $widths);
		}
		$fullName = trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? ''));
		$wfa = $row['wfa_status'] === 'Refer to WFL/H' ? 'Use the WFL/H column' : (string)($row['wfa_status'] ?? 'Normal');
		$hfa = (string)($row['hfa_status'] ?? 'Normal');
		$wfh = (string)($row['wfh_status'] ?? 'Normal');
		pdf_data_row($pdf, [(string)($row['address'] ?? ''), (string)($row['parent_name'] ?? ''), $fullName, (string)$row['sex'], (int)$row['age_months'], $wfa, $hfa, $wfh], $widths, $index % 2 === 0, ['L', 'L', 'L', 'C', 'C', 'C', 'C', 'C']);
	}
	pdf_signature_block($pdf);

	return $pdf;
}

function pdf_generate_monitoring_list(string $listCode, array $f): TCPDF {
	$listSpecs = [
		'0-23' => ['title' => 'MONITORING LIST FOR CHILDREN 0-23 MONTHS OLD', 'axis' => 'All children (monthly weighing)', 'condition' => '1=1', 'age_min' => 0, 'age_max' => 23],
		'MW' => ['title' => 'MONITORING LIST FOR MODERATELY WASTED CHILDREN (MAM)', 'axis' => 'Weight-for-Height', 'condition' => "lm.wfh_status = 'MW'", 'age_min' => 0, 'age_max' => 59],
		'SW' => ['title' => 'MONITORING LIST FOR SEVERELY WASTED CHILDREN (SAM)', 'axis' => 'Weight-for-Height', 'condition' => "lm.wfh_status = 'SW'", 'age_min' => 0, 'age_max' => 59],
		'MSt_SSt' => ['title' => 'MONITORING LIST FOR MODERATELY OR SEVERELY STUNTED CHILDREN', 'axis' => 'Height-for-Age', 'condition' => "lm.hfa_status IN ('MSt','SSt')", 'age_min' => 0, 'age_max' => 59],
		'OW_Ob' => ['title' => 'MONITORING LIST FOR OVERWEIGHT OR OBESE CHILDREN', 'axis' => 'Weight-for-Age / Weight-for-Height', 'condition' => "(lm.wfa_status = 'OW' OR lm.wfh_status IN ('OW','Ob'))", 'age_min' => 0, 'age_max' => 59],
		'MUW' => ['title' => 'MONITORING LIST FOR MODERATELY UNDERWEIGHT CHILDREN', 'axis' => 'Weight-for-Age', 'condition' => "lm.wfa_status = 'MUW'", 'age_min' => 0, 'age_max' => 59],
		'MUW_SUW_MSt_SSt' => ['title' => 'MONITORING LIST FOR UNDERWEIGHT + STUNTED', 'axis' => 'Weight-for-Age + Height-for-Age', 'condition' => "(lm.wfa_status IN ('MUW','SUW') AND lm.hfa_status IN ('MSt','SSt'))", 'age_min' => 0, 'age_max' => 59],
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
	pdf_render_list_table($pdf, $rows, $listCode !== '0-23');

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
	];

	foreach ($allChildren as $c) {
		$wfa = $c['wfa_status'] ?? null;
		$hfa = $c['hfa_status'] ?? null;
		$wfh = $c['wfh_status'] ?? null;
		if (in_array($wfh, ['MW', 'SW'], true)) $counts['wasted']++;
		if (in_array($hfa, ['MSt', 'SSt'], true)) $counts['stunted']++;
		if ($wfa === 'OW' || in_array($wfh, ['OW', 'Ob'], true)) $counts['ow_ob']++;
		if (in_array($wfa, ['MUW', 'SUW'], true)) $counts['underweight']++;
		if (in_array($wfa, ['MUW', 'SUW'], true) || in_array($hfa, ['MSt', 'SSt'], true)) $counts['uw_or_stunted']++;
		if (in_array($hfa, ['MSt', 'SSt'], true) || ($wfa === 'OW' || in_array($wfh, ['OW', 'Ob'], true))) $counts['stunted_or_owob']++;

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

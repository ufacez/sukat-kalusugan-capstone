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

function pdf_table_header(TCPDF $pdf, array $columns, array $widths, string $fillColor = '106E4F', int $fontSize = 7): void {
	$r = hexdec(substr($fillColor, 0, 2));
	$g = hexdec(substr($fillColor, 2, 2));
	$b = hexdec(substr($fillColor, 4, 2));

	$pdf->SetFillColor($r, $g, $b);
	$pdf->SetTextColor(255, 255, 255);
	$pdf->SetFont('helvetica', 'B', $fontSize);

	$height = 8;
	for ($i = 0; $i < count($columns); $i++) {
		$pdf->Cell($widths[$i], $height, $columns[$i], 1, 0, 'C', true);
	}
	$pdf->Ln();

	$pdf->SetTextColor(0, 0, 0);
	$pdf->SetFont('helvetica', '', $fontSize);
}

function pdf_status_fill(string $code): ?array {
	$map = [
		'Normal' => [213, 245, 227],
		'MUW'    => [254, 249, 231],
		'SUW'    => [250, 219, 216],
		'MSt'    => [254, 249, 231],
		'SSt'    => [250, 219, 216],
		'MW'     => [254, 249, 231],
		'SW'     => [250, 219, 216],
		'OW'     => [253, 235, 208],
		'Ob'     => [253, 235, 208],
		'Tall'   => [211, 228, 253],
	];
	return $map[$code] ?? null;
}

function pdf_data_row(TCPDF $pdf, array $values, array $widths, bool $isAlt = false, array $aligns = [], array $cellFills = []): void {
	$altR = $isAlt ? 240 : 255;
	$altG = $isAlt ? 248 : 255;
	$altB = $isAlt ? 244 : 255;
	$maxH = 5;
	for ($i = 0; $i < count($values); $i++) {
		if (isset($cellFills[$i])) {
			$f = $cellFills[$i];
			$pdf->SetFillColor($f[0], $f[1], $f[2]);
		} else {
			$pdf->SetFillColor($altR, $altG, $altB);
		}
		$align = $aligns[$i] ?? 'L';
		$pdf->Cell($widths[$i], $maxH, (string)$values[$i], 1, 0, $align, true);
	}
	$pdf->Ln();
}

function pdf_two_level_header(TCPDF $pdf, array $widths): void {
	$r = hexdec('10');
	$g = hexdec('6E');
	$b = hexdec('4F');
	$pdf->SetFillColor($r, $g, $b);
	$pdf->SetTextColor(255, 255, 255);
	$pdf->SetFont('helvetica', 'B', 7);

	$rowH = 5;
	$totalH = $rowH * 2;

	$pdf->Cell($widths[0], $totalH, 'Classification', 1, 0, 'C', true);

	$f1kW = 0;
	for ($i = 1; $i <= 4; $i++) { $f1kW += $widths[$i]; }
	$pdf->Cell($f1kW, $rowH, '0-23 Months (F1K)', 1, 0, 'C', true);

	$allW = 0;
	for ($i = 5; $i <= 8; $i++) { $allW += $widths[$i]; }
	$pdf->Cell($allW, $rowH, '0-59 Months', 1, 1, 'C', true);

	$pdf->SetX($pdf->GetX() + $widths[0]);
	$subHeaders = ['Boys', 'Girls', 'Total', 'Prev', 'Boys', 'Girls', 'Total', 'Prev'];
	for ($i = 0; $i < count($subHeaders); $i++) {
		$pdf->Cell($widths[$i + 1], $rowH, $subHeaders[$i], 1, 0, 'C', true);
	}
	$pdf->Ln();

	$pdf->SetTextColor(0, 0, 0);
	$pdf->SetFont('helvetica', '', 7);
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
	$scope = nutritionist_scope_fragment($user, 'c.barangay_id', $scopeParams) . ' AND c.status = \'active\'';

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

function pdf_render_list_table(TCPDF $pdf, array $rows, bool $showCategory = false, array $followupSeqMap = [], bool $showFollowups = false): void {
	$cols = ['No.', 'Address', 'Mother/Caregiver', 'Full Name of Child', 'Sex', 'Birthdate', 'Height (cm)', 'Weight (kg)', 'WFA', 'HFA', 'WFH'];
	$widths = [12, 28, 32, 50, 14, 20, 18, 18, 14, 14, 14];

	if ($showCategory) {
		$cols[] = 'Category';
		$widths[] = 30;
	}

	if ($showFollowups) {
		// Condensed base widths so 11 base + 6 Month# cols fit landscape A4
		// (printable ~273mm). Smaller 6pt font keeps "Month#6" headers inside.
		$widths = [7, 18, 22, 28, 9, 14, 11, 11, 9, 9, 9];
		for ($mh = 1; $mh <= 6; $mh++) {
			$cols[] = 'Month#' . $mh;
			$widths[] = 14;
		}
	}

	// Continue directly under the title block when space allows; only break
	// to a fresh page if the header already filled most of this one.
	if ($pdf->GetY() > 140) {
		$pdf->AddPage();
	}
	pdf_table_header($pdf, $cols, $widths, '106E4F', $showFollowups ? 6 : 7);

	$count = 0;
	foreach ($rows as $i => $row) {
		if ($pdf->GetY() > 150) {
			$pdf->AddPage();
			pdf_table_header($pdf, $cols, $widths, '106E4F', $showFollowups ? 6 : 7);
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

		$wfaCode = (string)($row['wfa_status'] ?? '');
		$hfaCode = (string)($row['hfa_status'] ?? '');
		$wfhCode = (string)($row['wfh_status'] ?? '');
		$cellFills = [];
		$wfaFill = pdf_status_fill($wfaCode);
		$hfaFill = pdf_status_fill($hfaCode);
		$wfhFill = pdf_status_fill($wfhCode);
		if ($wfaFill) {
			$cellFills[8] = $wfaFill;
		}
		if ($hfaFill) {
			$cellFills[9] = $hfaFill;
		}
		if ($wfhFill) {
			$cellFills[10] = $wfhFill;
		}

		if ($showCategory) {
			$catCodes = followup_abnormal_codes($row['wfa_status'] ?? null, $row['hfa_status'] ?? null, $row['wfh_status'] ?? null);
			$values[] = followup_category_label(implode('+', $catCodes)) ?: '';
		}

		if ($showFollowups) {
			$seqVisits = $followupSeqMap[(int)($row['id'] ?? 0)] ?? [];
			for ($mn = 1; $mn <= 6; $mn++) {
				$visit = $seqVisits[$mn - 1] ?? null;
				if ($visit === null || ($visit['scheduled_at'] ?? '') === '') {
					$values[] = '';
				} else {
					try {
						$values[] = (new DateTimeImmutable((string)$visit['scheduled_at']))->format('M j, Y');
					} catch (Exception) {
						$values[] = '';
					}
				}
			}
		}

		pdf_data_row($pdf, $values, $widths, $i % 2 === 0, [], $cellFills);
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
		'sss' . str_repeat('i', count($f['scope_params']) + count($f['barangay_filter_params'])) . 's',
		array_merge([$f['anchor_param'], $f['anchor_param'], $f['anchor_param']], $f['scope_params'], $f['barangay_filter_params'], [$f['anchor_param']])
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
		$wfhCode = (string)($row['wfh_status'] ?? '');
		$cellFills = [];
		$wfhFill = pdf_status_fill($wfhCode);
		if ($wfhFill) {
			$cellFills[12] = $wfhFill;
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
			$wfhCode,
			!empty($row['has_disability']) ? 'YES' : 'NO',
		], $widths, $i % 2 === 0, array_fill(0, count($cols), 'C'), $cellFills);
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
		'sss' . str_repeat('i', count($f['scope_params']) + count($f['barangay_filter_params'])) . 's',
		array_merge([$f['anchor_param'], $f['anchor_param'], $f['anchor_param']], $f['scope_params'], $f['barangay_filter_params'], [$f['anchor_param']])
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
		$hfaStatus = (string)($row['hfa_status'] ?? '');
		$wfhStatus = (string)($row['wfh_status'] ?? '');

		$cellFills = [];
		$wfaFill = pdf_status_fill($wfaStatus);
		$hfaFill = pdf_status_fill($hfaStatus);
		$wfhFill = pdf_status_fill($wfhStatus);
		if ($wfaFill) {
			$cellFills[12] = $wfaFill;
		} elseif ($wfaStatus === 'Use WFL/H column') {
			$cellFills[12] = [220, 220, 220];
		}
		if ($hfaFill) {
			$cellFills[13] = $hfaFill;
		}
		if ($wfhFill) {
			$cellFills[14] = $wfhFill;
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
			$hfaStatus,
			$wfhStatus,
			!empty($row['has_disability']) ? 'YES' : 'NO',
		], $widths, $index % 2 === 0, array_fill(0, count($columns), 'C'), $cellFills);
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
	$pdf->Ln(2);
	pdf_metadata_row($pdf, $f['barangay_name'], $f['period_label'], date('F j, Y'));

	$summaryRows = admin_fetch_all(
		"SELECT
			c.id, c.parent_id,
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
		'WFA' => ['Normal' => 'Normal', 'Ob' => 'Ob', 'OW' => 'OW', 'MUW' => 'MUW', 'SUW' => 'SUW'],
		'HFA' => ['Normal' => 'Normal', 'Tall' => 'Tall', 'MSt' => 'MSt', 'SSt' => 'SSt'],
		'WFL/H' => ['Normal' => 'Normal', 'OW' => 'OW', 'Ob' => 'Ob', 'MW' => 'MW/MAM', 'SW' => 'SW/SAM'],
	];
	$summary = [];
	foreach ($statusGroups as $axis => $statuses) {
		foreach ($statuses as $code => $label) {
			$summary[$axis][$code] = [
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
	$wsChildren59 = [];
	$wsChildren2459 = [];
	$owObChildren59 = [];
	$wsChildren23 = [];
	$ageCount029 = 0;
	$ageCount3059 = 0;
	$ageCount2459 = 0;
	$mcIds59 = [];
	$mcWsIds59 = [];
	$mcOwObIds59 = [];
	$mcIds23 = [];
	$mcWsIds23 = [];
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
		if ($ageMonths <= 23) {
			$f1kTotal++;
		}
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
				$summary[$axis][$code]['age_sex'][$ageGroup][$sex]++;
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
		$childId = (int)$row['id'];
		$parentId = (int)$row['parent_id'];
		$isWs = in_array($row['wfh_status'], ['MW', 'SW'], true) || in_array($row['hfa_status'], ['MSt', 'SSt'], true);
		$isOwOb = in_array($row['wfh_status'], ['OW', 'Ob'], true);
		$mcIds59[$parentId] = true;
		if ($isWs) { $wsChildren59[$childId] = true; $mcWsIds59[$parentId] = true; }
		if ($isOwOb) { $owObChildren59[$childId] = true; $mcOwObIds59[$parentId] = true; }
		if ($ageMonths >= 24 && $ageMonths <= 59) {
			$ageCount2459++;
			if ($isWs) { $wsChildren2459[$childId] = true; }
		}
		if ($ageMonths <= 29) { $ageCount029++; } else { $ageCount3059++; }
		if ($ageMonths <= 23) {
			$mcIds23[$parentId] = true;
			if ($isWs) { $wsChildren23[$childId] = true; $mcWsIds23[$parentId] = true; }
		}
	}

	$pdf->SetFont('helvetica', 'B', 8);
	$pdf->Cell(0, 6, 'Coverage and prevalence information', 0, 1);
	$pdf->SetFont('helvetica', '', 8);
	$pdf->Cell(65, 5, 'Barangay:', 0, 0); $pdf->Cell(70, 5, $f['barangay_name'], 0, 0);
	$pdf->Cell(65, 5, 'Municipality / Province:', 0, 0); $pdf->Cell(70, 5, 'City of San Fernando, Pampanga', 0, 1);
	$pdf->Cell(65, 5, 'Reporting year:', 0, 0); $pdf->Cell(70, 5, (string)$f['year'], 0, 0);
	$pdf->Cell(65, 5, 'OPT Plus coverage:', 0, 0); $pdf->Cell(70, 5, (string)$totalAssessed . ' assessed', 0, 1);
	$pdf->Cell(65, 5, 'Total children assessed:', 0, 0); $pdf->Cell(70, 5, (string)$totalAssessed, 0, 0);
	$pdf->Cell(65, 5, 'Indigenous children:', 0, 0); $pdf->Cell(70, 5, (string)$ipCount, 0, 1);
	$pdf->Cell(65, 5, 'Children with disability:', 0, 0); $pdf->Cell(70, 5, (string)$disabilityCount, 0, 1);
	$pdf->Ln(3);

	$wLabel = 24;
	$wAgeSub = 10;
	$wSumSub = 12;
	$wIPSub = 6;
	$wAgeGroup = 3 * $wAgeSub;
	$wSummary = 2 * $wSumSub;
	$wIP = 3 * $wIPSub;

	$ageGroupLabels = ['0-5', '6-11', '12-23', '24-35', '36-47', '48-59'];
	$subHeaders = ['Boys', 'Girls', 'Total'];

	$pdf->SetFont('helvetica', 'B', 8);
	$pdf->Cell(0, 6, 'NUTRITIONAL STATUS CONSOLIDATION TABLE', 0, 1);

	$darkFill = [16, 110, 79];
	$pdf->SetFillColor($darkFill[0], $darkFill[1], $darkFill[2]);
	$pdf->SetTextColor(255, 255, 255);
	$y1 = $pdf->GetY();
	$x = $pdf->GetX();

	$pdf->SetFont('helvetica', 'B', 6);
	$hTop = 10;
	$pdf->Cell($wLabel, $hTop, "ACRONYMS &\nABBREVIATIONS", 1, 0, 'C', true);
	$pdf->SetFont('helvetica', '', 6);
	foreach ($ageGroupLabels as $gl) {
		$pdf->Cell($wAgeGroup, $hTop, $gl . " Months", 1, 0, 'C', true);
	}
	$pdf->SetFont('helvetica', 'B', 5);
	$pdf->Cell($wSummary, $hTop, "Birth to 5 Years\n(0-59 Months)", 1, 0, 'C', true);
	$pdf->Cell($wSummary, $hTop, "F1K\n(0-23 Months)", 1, 0, 'C', true);
	$pdf->Cell($wIP, $hTop, "# IP\nChildren", 1, 0, 'C', true);
	$pdf->Ln();

	$pdf->SetFont('helvetica', 'B', 6);
	$hSub = 6;
	$pdf->Cell($wLabel, $hSub, '', 1, 0, 'C', true);
	for ($g = 0; $g < 6; $g++) {
		foreach ($subHeaders as $sh) {
			$pdf->Cell($wAgeSub, $hSub, $sh, 1, 0, 'C', true);
		}
	}
	foreach (['Total', 'Prev'] as $sh) {
		$pdf->Cell($wSumSub, $hSub, $sh, 1, 0, 'C', true);
	}
	foreach (['Total', 'Prev'] as $sh) {
		$pdf->Cell($wSumSub, $hSub, $sh, 1, 0, 'C', true);
	}
	foreach (['Boys', 'Girls', 'Total'] as $sh) {
		$pdf->Cell($wIPSub, $hSub, $sh, 1, 0, 'C', true);
	}
	$pdf->Ln();

	$pdf->SetTextColor(0, 0, 0);
	$pdf->SetFont('helvetica', '', 5);
	$rowH = 5;
	$rowIndex = 0;

	$owMessage = 'No Obese/Overweight classification in the WFA. Following international standards, we use WL/HZ to classify overweight and obesity in children.';

	foreach ($summary as $axis => $summaryTable) {
		foreach ($summaryTable as $code => $counts) {
			$label = $axis . ' - ' . $statusGroups[$axis][$code];

			if ($axis === 'WFA' && ($code === 'Ob' || $code === 'OW')) {
				if ($code === 'OW') {
					$totalWidth = $wLabel + (6 * $wAgeGroup) + (2 * $wSummary) + $wIP;
					$pdf->SetFillColor(240, 248, 244);
					$pdf->SetFont('helvetica', 'I', 5);
					$pdf->MultiCell($totalWidth, $rowH, $owMessage, 1, 'C', true);
					$pdf->SetFont('helvetica', '', 5);
					$rowIndex++;
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

			$widths = array_merge([$wLabel], array_fill(0, 18, $wAgeSub), array_fill(0, 2, $wSumSub), array_fill(0, 2, $wSumSub), array_fill(0, 3, $wIPSub));
			$aligns = array_merge(['L'], array_fill(0, 25, 'C'));

			if ($rowIndex % 2 === 0) {
				$pdf->SetFillColor(240, 248, 244);
			} else {
				$pdf->SetFillColor(255, 255, 255);
			}
			for ($i = 0; $i < count($values); $i++) {
				$align = $aligns[$i] ?? 'L';
				$pdf->Cell($widths[$i], $rowH, (string)$values[$i], 1, 0, $align, true);
			}
			$pdf->Ln();
			$rowIndex++;
		}
	}

	$sumChildren = [
		'ws59' => count($wsChildren59),
		'ws2459' => count($wsChildren2459),
		'owOb59' => count($owObChildren59),
		'total23' => $f1kTotal,
		'ws23' => count($wsChildren23),
		'age029' => $ageCount029,
		'age3059' => $ageCount3059,
		'age2459' => $ageCount2459,
	];
	$sumMC = [
		'total59' => count($mcIds59),
		'ws59' => count($mcWsIds59),
		'owOb59' => count($mcOwObIds59),
		'total23' => count($mcIds23),
		'ws23' => count($mcWsIds23),
	];

	$qualitySource = admin_fetch_all(
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

	$pdf->Ln(3);
	$pdf->SetFont('helvetica', 'B', 8);
	$pdf->Cell(0, 6, 'SUMMARY', 0, 1);

	$sFill = [16, 110, 79];
	$pdf->SetFillColor($sFill[0], $sFill[1], $sFill[2]);
	$pdf->SetTextColor(255, 255, 255);
	$pdf->SetFont('helvetica', 'B', 6);

	$scW = [80, 12, 80, 12, 72, 6];
	$pdf->Cell($scW[0], 6, 'Summary of Children covered by e-OPT Plus', 1, 0, 'C', true);
	$pdf->Cell($scW[1], 6, '', 1, 0, 'C', true);
	$pdf->Cell($scW[2], 6, 'Mothers/Caregivers Summary', 1, 0, 'C', true);
	$pdf->Cell($scW[3], 6, '', 1, 0, 'C', true);
	$pdf->Cell($scW[4], 6, 'Data Inaccuracy', 1, 0, 'C', true);
	$pdf->Cell($scW[5], 6, '', 1, 1, 'C', true);

	$pdf->SetTextColor(0, 0, 0);
	$pdf->SetFont('helvetica', '', 5.5);
	$sRowH = 5;

	$summaryData = [
		['# Children 0-59 mos. Wasted/Stunted', $sumChildren['ws59'], 'Total Number of M/Cs 0-59 mos. old', $sumMC['total59'], '# Children with names and birthdate repeated', $repeatedChildren],
		['# Children 24-59 mos. Wasted/Stunted', $sumChildren['ws2459'], '# M/Cs of 0-59 mos. affected by W/S', $sumMC['ws59'], '# Children with missing information', $missingInfo],
		['# Children 0-59 mos. Overweight/Obese', $sumChildren['owOb59'], '# M/Cs of 0-59 mos. Overweight/Obese', $sumMC['owOb59'], '# Children with no parent/address', $noParentAddr],
		['Total Children 0-23 mos.', $sumChildren['total23'], 'Total M/Cs 0-23 mos.', $sumMC['total23'], '# Children with no sex', $noSex],
		['# Children 0-23 mos. Wasted/Stunted', $sumChildren['ws23'], '# M/Cs 0-23 mos. affected by W/S', $sumMC['ws23'], '# Children with no DOB', $noDob],
		['Children 0-29 mos.', $sumChildren['age029'], '', '', '# Children >59 mos.', $older59],
		['Children 30-59 mos.', $sumChildren['age3059'], '', '', '# Length/height no weight', $htNoWt],
		['Children 24-59 mos.', $sumChildren['age2459'], '', '', '# Weight no ht/length', $wtNoHt],
	];

	foreach ($summaryData as $si => $sd) {
		if ($si % 2 === 0) {
			$pdf->SetFillColor(240, 248, 244);
		} else {
			$pdf->SetFillColor(255, 255, 255);
		}
		$pdf->Cell($scW[0], $sRowH, $sd[0], 1, 0, 'L', true);
		$pdf->SetFont('helvetica', 'B', 5.5);
		$pdf->Cell($scW[1], $sRowH, (string)$sd[1], 1, 0, 'C', true);
		$pdf->SetFont('helvetica', '', 5.5);
		$pdf->Cell($scW[2], $sRowH, $sd[2], 1, 0, 'L', true);
		$pdf->SetFont('helvetica', 'B', 5.5);
		$pdf->Cell($scW[3], $sRowH, (string)$sd[3], 1, 0, 'C', true);
		$pdf->SetFont('helvetica', '', 5.5);
		$pdf->Cell($scW[4], $sRowH, $sd[4], 1, 0, 'L', true);
		$pdf->SetFont('helvetica', 'B', 5.5);
		$pdf->Cell($scW[5], $sRowH, (string)$sd[5], 1, 0, 'C', true);
		$pdf->SetFont('helvetica', '', 5.5);
		$pdf->Ln();
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

	$axisConfig = [
		'WFA' => [
			'title' => '3. WEIGHT FOR AGE',
			'order' => ['Normal', 'MUW', 'SUW'],
			'message' => 'No Obese/Overweight classification in the WFA. Following international standards, we use WL/HZ to classify overweight and obesity in children.',
		],
		'HFA' => [
			'title' => '4. HEIGHT FOR AGE',
			'order' => ['Normal', 'Tall', 'MSt', 'SSt'],
			'message' => null,
		],
		'WFL/H' => [
			'title' => '5. WEIGHT FOR LENGTH/HEIGHT',
			'order' => ['Normal', 'OW', 'Ob', 'MW', 'SW'],
			'message' => null,
		],
	];
	$widths = [50, 18, 18, 18, 20, 18, 18, 18, 20];

	foreach ($axisConfig as $axis => $cfg) {
		$pdf->SetFont('helvetica', 'B', 9);
		$pdf->Cell(0, 6, $cfg['title'], 0, 1);
		$lineY = $pdf->GetY() + 1;
		$xLeft = $pdf->GetX();
		$pdf->Line($xLeft, $lineY, $pdf->GetPageWidth() - $xLeft, $lineY);
		$pdf->Ln(3);

		pdf_two_level_header($pdf, $widths);

		$i = 0;
		foreach ($cfg['order'] as $code) {
			$label = $definitions[$axis][$code] ?? $code;

			$earlyTotal = $summary[$axis][$code]['0-23']['Boys'] + $summary[$axis][$code]['0-23']['Girls'];
			$allTotal = $summary[$axis][$code]['0-59']['Boys'] + $summary[$axis][$code]['0-59']['Girls'];
			$earlyPrev = $denominators[$axis]['0-23'] > 0 ? number_format(($earlyTotal / $denominators[$axis]['0-23']) * 100, 1) . '%' : '0.0%';
			$allPrev = $denominators[$axis]['0-59'] > 0 ? number_format(($allTotal / $denominators[$axis]['0-59']) * 100, 1) . '%' : '0.0%';
			pdf_data_row($pdf, [
				$label,
				$summary[$axis][$code]['0-23']['Boys'], $summary[$axis][$code]['0-23']['Girls'], $earlyTotal, $earlyPrev,
				$summary[$axis][$code]['0-59']['Boys'], $summary[$axis][$code]['0-59']['Girls'], $allTotal, $allPrev,
			], $widths, $i % 2 === 0, ['L', 'C', 'C', 'C', 'C', 'C', 'C', 'C', 'C']);
			$i++;

			if ($code === 'Normal' && !empty($cfg['message'])) {
				$totalW = 0;
				for ($c = 0; $c < count($widths); $c++) { $totalW += $widths[$c]; }
				$pdf->SetFillColor(240, 248, 244);
				$pdf->SetFont('helvetica', 'I', 7);
				$pdf->MultiCell($totalW, 5, $cfg['message'], 1, 'C', true);
				$pdf->SetFont('helvetica', '', 7);
				$i++;
			}
		}
		$pdf->Ln(4);
	}

	$pdf->Ln(2);
	$pdf->SetFont('helvetica', 'B', 7);
	$pdf->Cell(0, 7, 'TOTAL NUMBER OF MOTHERS/CAREGIVERS OF CHILDREN (0-59 MOS OLD) AFFECTED BY UNDERNUTRITION', 1, 1, 'L');
	$pdf->Cell(20, 7, (string)count($affectedParents['0-59']), 1, 1, 'C');
	$pdf->Ln(2);
	$pdf->Cell(0, 7, 'TOTAL NUMBER OF MOTHERS/CAREGIVERS OF CHILDREN (0-23 MOS OLD) AFFECTED BY UNDERNUTRITION', 1, 1, 'L');
	$pdf->Cell(20, 7, (string)count($affectedParents['0-23']), 1, 1, 'C');
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
		$cellFills = [];
		$wfaFill = pdf_status_fill($wfa);
		$hfaFill = pdf_status_fill($hfa);
		$wfhFill = pdf_status_fill($wfh);
		if ($wfaFill) {
			$cellFills[5] = $wfaFill;
		} elseif ($wfa === 'Use the WFL/H column') {
			$cellFills[5] = [220, 220, 220];
		}
		if ($hfaFill) {
			$cellFills[6] = $hfaFill;
		}
		if ($wfhFill) {
			$cellFills[7] = $wfhFill;
		}
		pdf_data_row($pdf, [(string)($row['address'] ?? ''), (string)($row['parent_name'] ?? ''), $fullName, (string)$row['sex'], (int)$row['age_months'], $wfa, $hfa, $wfh], $widths, $index % 2 === 0, ['L', 'L', 'L', 'C', 'C', 'C', 'C', 'C'], $cellFills);
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
	// List_0-23 carries 6 sequence-based follow-up columns:
	// Month#N = the child's Nth follow-up appointment (scheduled_at ASC).
	$isInfantList = ($listCode === '0-23');
	$followupSeqMap = ($isInfantList && !empty($rows))
		? eopt_fetch_followup_sequence_map(array_column($rows, 'id'))
		: [];
	pdf_render_list_table($pdf, $rows, $listCode !== '0-23', $followupSeqMap, $isInfantList);

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

function dqc_log_factorial(int $n): float {
	if ($n <= 1) {
		return 0.0;
	}
	$s = 0.0;
	for ($i = 2; $i <= $n; $i++) {
		$s += log($i);
	}
	return $s;
}

function dqc_poisson_cdf(int $k, float $lambda): float {
	if ($lambda <= 0) {
		return 0.0;
	}
	$sum = 0.0;
	for ($i = 0; $i <= $k; $i++) {
		$sum += exp(-$lambda + $i * log($lambda) - dqc_log_factorial($i));
	}
	return $sum;
}

function dqc_section_table(TCPDF $pdf, string $title, array $rows, array $headerRgb, array $bodyRgb, float $totalWidth = 170): void {
	$letterW = 10;
	$descW = $totalWidth - $letterW - 20;
	$valueW = 20;

	$pdf->SetFillColor($headerRgb[0], $headerRgb[1], $headerRgb[2]);
	$pdf->SetFont('helvetica', 'B', 8);
	$pdf->Cell($totalWidth, 7, $title, 1, 1, 'C', true);

	$pdf->SetFont('helvetica', '', 7);
	$pdf->SetTextColor(0, 0, 0);
	foreach ($rows as [$letter, $desc, $value]) {
		$pdf->SetFillColor($bodyRgb[0], $bodyRgb[1], $bodyRgb[2]);
		$pdf->Cell($letterW, 6, $letter, 1, 0, 'C', true);
		$pdf->Cell($descW, 6, $desc, 1, 0, 'L', true);
		$pdf->Cell($valueW, 6, $value, 1, 1, 'R', true);
	}
}

function pdf_generate_dqc(array $f): TCPDF {
	$pdf = pdf_base('Data Quality Check Report', 'Landscape');
	$pdf->AddPage();
	pdf_header_block($pdf, $f['year'], $f['period_label'], $f['barangay_name']);

	$pdf->SetFont('helvetica', 'B', 11);
	$pdf->Cell(0, 7, 'DATA QUALITY CHECK (DQC)', 0, 1, 'C');
	$pdf->Ln(2);
	pdf_metadata_row($pdf, $f['barangay_name'], $f['period_label'], date('F j, Y'));

	$scopeSql = $f['scope'];
	$scopeParams = $f['scope_params'];
	$brgySql = $f['barangay_filter_sql'];
	$brgyParams = $f['barangay_filter_params'];
	$anchorParam = $f['anchor_param'];
	$allParams = array_merge($scopeParams, $brgyParams);
	$allTypes = str_repeat('i', count($allParams));

	$totalChildren = admin_scalar(
		"SELECT COUNT(*) FROM children c WHERE {$scopeSql}{$brgySql} AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 59",
		'i' . $allTypes, array_merge([$anchorParam], $allParams)
	);

	$totalWithMeasurement = admin_scalar(
		"SELECT COUNT(DISTINCT c.id) FROM children c
		 INNER JOIN measurements m ON m.child_id = c.id AND m.measurement_date <= ?
		 WHERE {$scopeSql}{$brgySql} AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 59",
		'ii' . $allTypes, array_merge([$anchorParam, $anchorParam], $allParams)
	);

	$dqDuplicateGroups = count(admin_fetch_all(
		"SELECT 1 FROM children c WHERE {$scopeSql}{$brgySql}
		 AND c.first_name != '' AND c.last_name != '' AND c.birthdate IS NOT NULL
		 GROUP BY c.first_name, c.last_name, c.birthdate HAVING COUNT(*) > 1",
		$allTypes, $allParams
	));

	$dqHeightNoWeight = admin_scalar(
		"SELECT COUNT(DISTINCT c.id) FROM children c
		 INNER JOIN measurements m ON m.child_id = c.id AND m.measurement_date <= ?
		 WHERE m.height_cm IS NOT NULL AND m.weight_kg IS NULL
		 AND {$scopeSql}{$brgySql} AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 59",
		'ii' . $allTypes, array_merge([$anchorParam, $anchorParam], $allParams)
	);

	$dqWeightNoHeight = admin_scalar(
		"SELECT COUNT(DISTINCT c.id) FROM children c
		 INNER JOIN measurements m ON m.child_id = c.id AND m.measurement_date <= ?
		 WHERE m.weight_kg IS NOT NULL AND m.height_cm IS NULL
		 AND {$scopeSql}{$brgySql} AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 59",
		'ii' . $allTypes, array_merge([$anchorParam, $anchorParam], $allParams)
	);

	$dqMissingDob = admin_scalar(
		"SELECT COUNT(*) FROM children c WHERE c.birthdate IS NULL
		 AND {$scopeSql}{$brgySql}",
		$allTypes, $allParams
	);

	$dqMissingSex = admin_scalar(
		"SELECT COUNT(*) FROM children c WHERE c.sex IS NULL
		 AND {$scopeSql}{$brgySql}",
		$allTypes, $allParams
	);

	$dqNoParentAddress = admin_scalar(
		"SELECT COUNT(*) FROM children c LEFT JOIN parents p ON p.id = c.parent_id
		 WHERE (p.id IS NULL OR COALESCE(p.name, '') = '' OR (c.local_area_id IS NULL AND COALESCE(p.address, '') = ''))
		 AND {$scopeSql}{$brgySql}",
		$allTypes, $allParams
	);

	$pct = static function (int $num, int $den) use ($totalChildren, $totalWithMeasurement): string {
		$d = $den > 0 ? $den : 1;
		return number_format(($num / $d) * 100, 2) . '%';
	};

	$completenessRows = [
		['A', '% Coverage (population of 0-59 months)', $pct($totalWithMeasurement, $totalChildren)],
		['B', '% Children measured with duplicate cases', $pct($dqDuplicateGroups, $totalWithMeasurement)],
		['C', '% Children with length/height but no weight', $pct($dqHeightNoWeight, $totalWithMeasurement)],
		['D', '% Children with weight but no length/height', $pct($dqWeightNoHeight, $totalWithMeasurement)],
		['E', '% Children with no date of birth data', $pct($dqMissingDob, $totalChildren)],
		['F', '% Children with no sex data', $pct($dqMissingSex, $totalChildren)],
		['G', '% Children with no name of parents/address', $pct($dqNoParentAddress, $totalChildren)],
	];

	dqc_section_table($pdf, 'COMPLETENESS', $completenessRows, [232, 168, 124], [248, 215, 181]);
	$pdf->Ln(5);

	$measRows = admin_fetch_all(
		"SELECT m.whz, m.is_flagged, m.weight_kg, m.height_cm
		 FROM children c
		 INNER JOIN measurements m ON m.id = (
			SELECT m2.id FROM measurements m2
			WHERE m2.child_id = c.id AND m2.measurement_date <= ?
			ORDER BY m2.measurement_date DESC, m2.id DESC LIMIT 1
		 )
		 WHERE {$scopeSql}{$brgySql} AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 59",
		'ii' . $allTypes, array_merge([$anchorParam, $anchorParam], $allParams)
	);

	$dqFlagged = 0;
	$dqDigitNumerator = 0;
	$dqDigitDenominator = 0;
	$dqWhzValues = [];
	$dqWhzBelowNeg2 = 0;
	$dqValidWhzCount = 0;

	foreach ($measRows as $m) {
		if (!empty($m['is_flagged'])) {
			$dqFlagged++;
		}

		if ($m['weight_kg'] !== null) {
			$dqDigitDenominator++;
			$lastDigit = (int)round((float)$m['weight_kg'] * 10) % 10;
			if ($lastDigit === 0 || $lastDigit === 5) {
				$dqDigitNumerator++;
			}
		}
		if ($m['height_cm'] !== null) {
			$dqDigitDenominator++;
			$lastDigit = (int)round((float)$m['height_cm'] * 10) % 10;
			if ($lastDigit === 0 || $lastDigit === 5) {
				$dqDigitNumerator++;
			}
		}

		if ($m['whz'] !== null) {
			$whzVal = (float)$m['whz'];
			$dqWhzValues[] = $whzVal;
			$dqValidWhzCount++;
			if ($whzVal < -2) {
				$dqWhzBelowNeg2++;
			}
		}
	}

	$dqFlaggedPct = $pct($dqFlagged, count($measRows));
	$dqDigitPref = $dqDigitDenominator > 0 ? number_format(($dqDigitNumerator / $dqDigitDenominator) * 100, 2) : '0.00';

	$dqSkewness = 'N/A';
	$dqKurtosis = 'N/A';
	$dqWhzStdDev = 'N/A';
	$dqPoissonP = 'N/A';

	if ($dqValidWhzCount >= 10) {
		$whzMean = array_sum($dqWhzValues) / $dqValidWhzCount;
		$whzVariance = 0.0;
		foreach ($dqWhzValues as $wv) {
			$whzVariance += ($wv - $whzMean) * ($wv - $whzMean);
		}
		$whzVariance /= ($dqValidWhzCount - 1);
		$whzStdDev = sqrt($whzVariance);
		$dqWhzStdDev = number_format($whzStdDev, 2);

		if ($whzStdDev > 0) {
			$skewSum = 0.0;
			$kurtSum = 0.0;
			foreach ($dqWhzValues as $wv) {
				$z = ($wv - $whzMean) / $whzStdDev;
				$skewSum += $z * $z * $z;
				$kurtSum += $z * $z * $z * $z;
			}
			$n = $dqValidWhzCount;
			$dqSkewness = number_format(($n / (($n - 1) * ($n - 2))) * $skewSum, 2);
			$dqKurtosis = number_format(
				(($n * ($n + 1)) / (($n - 1) * ($n - 2) * ($n - 3))) * $kurtSum
				- (3 * ($n - 1) * ($n - 1)) / (($n - 2) * ($n - 3)),
				2
			);
		}

		$lambda = $dqValidWhzCount * 0.0228;
		if ($lambda > 0) {
			$pVal = 1.0 - dqc_poisson_cdf($dqWhzBelowNeg2 - 1, $lambda);
			$dqPoissonP = number_format(max(0, $pVal), 4);
		}
	}

	$accuracyRows = [
		['A', '% Children with flagged measurement based on z-scores', $dqFlaggedPct . '%'],
		['B', 'Digit preference score for anthropometric data', $dqDigitPref . '%'],
		['C', 'Skewness of weight-for-height/length z-score', $dqSkewness],
		['D', 'Kurtosis of weight-for-height/length z-score', $dqKurtosis],
		['E', 'Poisson distribution (p-value) for WL/H z (<-2)', $dqPoissonP],
	];

	dqc_section_table($pdf, 'ACCURACY', $accuracyRows, [157, 189, 228], [217, 229, 242]);
	$pdf->Ln(5);

	$reliabilityRows = [
		['A', 'Standard deviation of weight-for-height/length z-score', $dqWhzStdDev],
	];

	dqc_section_table($pdf, 'RELIABILITY', $reliabilityRows, [240, 217, 140], [255, 242, 204]);

	$pdf->Ln(8);
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

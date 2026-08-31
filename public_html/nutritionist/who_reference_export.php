<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/who_reference_import.php';

nutritionist_require_access();

$indicators = [
	'waz'      => ['label' => 'Weight-for-Age (months)',   'table' => 'who_weight_for_age',      'column' => 'age_months', 'columnLabel' => 'Age (months)'],
	'waz-days' => ['label' => 'Weight-for-Age (days)',     'table' => 'who_weight_for_age_days', 'column' => 'age_days',   'columnLabel' => 'Age (days)'],
	'haz'      => ['label' => 'Height-for-Age (months)',   'table' => 'who_height_for_age',      'column' => 'age_months', 'columnLabel' => 'Age (months)'],
	'haz-days' => ['label' => 'Height-for-Age (days)',     'table' => 'who_height_for_age_days', 'column' => 'age_days',   'columnLabel' => 'Age (days)'],
	'whz'      => ['label' => 'Weight-for-Height (2-5y)',  'table' => 'who_weight_for_height',   'column' => 'height_cm',  'columnLabel' => 'Height (cm)'],
	'wfl'      => ['label' => 'Weight-for-Length (0-2y)',  'table' => 'who_weight_for_length',   'column' => 'height_cm',  'columnLabel' => 'Length (cm)'],
];

$indicator = strtolower((string)($_GET['indicator'] ?? 'waz'));

if (!isset($indicators[$indicator])) {
	$indicator = 'waz';
}

$sex = ($_GET['sex'] ?? 'Male') === 'Female' ? 'Female' : 'Male';
$ageRange = (string)($_GET['range'] ?? 'all');

if (!in_array($ageRange, ['young', 'old', 'all'], true)) {
	$ageRange = 'all';
}

$rangeBounds = [
	'young' => [
		'age_months' => [0, 23],
		'age_days'   => [0, 729],
	],
	'old' => [
		'age_months' => [24, 60],
		'age_days'   => [730, 1856],
	],
	'all' => null,
];

$config = $indicators[$indicator];
$sql = "SELECT {$config['column']} AS x, L, M, S FROM {$config['table']} WHERE sex = ?";
$types = 's';
$params = [$sex];

if ($rangeBounds[$ageRange] !== null && in_array($config['column'], ['age_months', 'age_days'], true)) {
	[$low, $high] = $rangeBounds[$ageRange][$config['column']];
	$sql .= " AND {$config['column']} BETWEEN ? AND ?";
	$types .= 'ii';
	$params[] = $low;
	$params[] = $high;
}

$sql .= " ORDER BY {$config['column']} ASC";

$rows = admin_fetch_all($sql, $types, $params);

function who_reference_export_sd(float $L, float $M, float $S, int $z): float
{
	if (abs($L) < 0.000001) {
		return $M * exp($S * $z);
	}

	return $M * (1 + $L * $S * $z) ** (1 / $L);
}

$header = [$config['columnLabel'], 'L', 'M', 'S', '-3SD', '-2SD', '-1SD', 'Median', '+1SD', '+2SD', '+3SD'];
$dataRows = [];

foreach ($rows as $row) {
	$L = (float)$row['L'];
	$M = (float)$row['M'];
	$S = (float)$row['S'];

	$dataRows[] = [
		$row['x'],
		round($L, 6),
		round($M, 6),
		round($S, 6),
		round(who_reference_export_sd($L, $M, $S, -3), 3),
		round(who_reference_export_sd($L, $M, $S, -2), 3),
		round(who_reference_export_sd($L, $M, $S, -1), 3),
		round($M, 3),
		round(who_reference_export_sd($L, $M, $S, 1), 3),
		round(who_reference_export_sd($L, $M, $S, 2), 3),
		round(who_reference_export_sd($L, $M, $S, 3), 3),
	];
}

$tmpPath = tempnam(sys_get_temp_dir(), 'who_export_') . '.xlsx';
$sheetName = strtoupper($indicator) . '_' . $sex;

if (!xlsx_lite_write($tmpPath, $header, $dataRows, $sheetName)) {
	admin_redirect('/nutritionist/who_reference.php', [
		'indicator' => $indicator,
		'sex' => $sex,
		'range' => $ageRange,
		'notice' => 'The export file could not be generated.',
		'type' => 'error',
	]);
}

$downloadName = 'who-' . $indicator . '-' . strtolower($sex) . '-' . $ageRange . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . (string)filesize($tmpPath));
header('Cache-Control: no-store');

readfile($tmpPath);
unlink($tmpPath);
exit;
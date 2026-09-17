<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/who_reference_import.php';

nutritionist_require_access();

function who_export_fail(string $indicator, string $sex, string $ageRange, string $notice): void
{
    error_log('[SukatKalusugan] WHO export failed: ' . $notice . " (indicator={$indicator} sex={$sex} range={$ageRange})");
    admin_redirect('/nutritionist/who_reference.php', [
        'indicator' => $indicator,
        'sex' => $sex,
        'range' => $ageRange,
        'notice' => $notice,
        'type' => 'error',
    ]);
}

// Zip is only required for .xlsx — CSV streams directly with no extension.
// On hosts without extension=zip (e.g. Azure App Service default image) an
// .xlsx request transparently falls back to CSV so the button never 500s.
$whoFormat = strtolower(trim((string)($_GET['format'] ?? 'xlsx')));
if ($whoFormat !== 'csv' && $whoFormat !== 'xlsx' && $whoFormat !== 'pdf') {
    $whoFormat = 'xlsx';
}
$whoFallbackToCsv = false;
if ($whoFormat === 'xlsx' && !class_exists('ZipArchive')) {
    error_log('[SukatKalusugan] WHO export: ZipArchive missing, falling back to CSV.');
    $whoFormat = 'csv';
    $whoFallbackToCsv = true;
}

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

// Optional exact-match search (month / day / cm value). Non-numeric input
// is ignored so the export always matches what the on-screen table shows.
$search = trim((string)($_GET['q'] ?? ''));
if ($search !== '' && preg_match('/^\d+(\.\d+)?$/', $search) !== 1) {
	$search = '';
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

if ($search !== '') {
	$sql .= " AND {$config['column']} = ?";
	if ($config['column'] === 'height_cm') {
		$types .= 'd';
		$params[] = (float)$search;
	} else {
		$types .= 'i';
		$params[] = (int)$search;
	}
}

$sql .= " ORDER BY {$config['column']} ASC";

try {
    $rows = admin_fetch_all($sql, $types, $params);
} catch (Throwable $e) {
    error_log('[SukatKalusugan] WHO export query failed: ' . $e->getMessage());
    who_export_fail($indicator, $sex, $ageRange, 'The export could not be generated (database error). Please try again.');
}

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

$tmpDir = rtrim((string)sys_get_temp_dir(), "/\\");
if ($tmpDir === '' || !is_dir($tmpDir) || !is_writable($tmpDir)) {
    // Azure fallback: use the app's writable logs dir when sys temp is locked down.
    $fallback = realpath(__DIR__ . '/../../logs');
    if ($fallback !== false && is_writable($fallback)) {
        $tmpDir = $fallback;
    }
}
$tmpPath = $tmpDir . DIRECTORY_SEPARATOR . 'who_export_' . bin2hex(random_bytes(8)) . '.xlsx';
$sheetName = strtoupper($indicator) . '_' . $sex;

if (empty($dataRows)) {
    who_export_fail($indicator, $sex, $ageRange, 'No standard rows found for this indicator. The standard table may not be seeded yet.');
}

if ($whoFormat === 'pdf') {
    require_once __DIR__ . '/../includes/pdf_generator.php';

    $rangeText = 'All rows';
    if ($ageRange === 'young') {
        $rangeText = '0-2y (0-23 mo / 0-729 d)';
    } elseif ($ageRange === 'old') {
        $rangeText = '2-5y (24-60 mo / 730-1856 d)';
    }

    $pdf = pdf_base('WHO Standard - ' . $config['label'] . ' - ' . $sex, 'Landscape');
    $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 7, 'WHO CHILD GROWTH STANDARDS (2006)', 0, 1, 'C');
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 6, strtoupper($config['label']) . ' - ' . strtoupper($sex), 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(0, 5, 'Range: ' . $rangeText . '  |  Find: ' . ($search !== '' ? $search : '-') . '  |  Generated: ' . date('F j, Y g:i A') . '  |  ' . count($dataRows) . ' row(s)', 0, 1, 'C');
    $pdf->Ln(3);

    // A4 landscape usable width is 273mm after the base 12mm margins.
    $pdfCols = [$config['columnLabel'], 'L', 'M', 'S', '-3SD', '-2SD', '-1SD', 'Median', '+1SD', '+2SD', '+3SD'];
    $pdfWidths = [27, 22, 26, 26, 24, 24, 24, 25, 25, 25, 25];
    pdf_table_header($pdf, $pdfCols, $pdfWidths, '106E4F', 6);
    $pdf->SetFont('helvetica', '', 6);
    $pdfRows = array_slice($dataRows, 0, 1000);
    foreach ($pdfRows as $ri => $pdfRow) {
        $pdf->SetFillColor($ri % 2 === 0 ? 240 : 255, $ri % 2 === 0 ? 248 : 255, $ri % 2 === 0 ? 244 : 255);
        foreach (array_values($pdfRow) as $ci => $cell) {
            $pdf->Cell($pdfWidths[$ci], 6, (string)$cell, 1, 0, $ci === 0 ? 'L' : 'R', true);
        }
        $pdf->Ln();
    }
    if (count($dataRows) > 1000) {
        $pdf->Ln(2);
        $pdf->SetFont('helvetica', 'I', 7);
        $pdf->Cell(0, 5, 'Showing the first 1,000 of ' . count($dataRows) . ' rows — use XLSX/CSV for the full table.', 0, 1, 'C');
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $pdf->Output('who-' . $indicator . '-' . strtolower($sex) . '-' . $ageRange . '.pdf', 'D');
    exit;
}

if ($whoFormat === 'csv') {
    if ($whoFallbackToCsv) {
        error_log('[SukatKalusugan] WHO export served CSV fallback instead of XLSX.');
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $csvName = 'who-' . $indicator . '-' . strtolower($sex) . '-' . $ageRange . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $csvName . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header);
    foreach ($dataRows as $dataRow) {
        fputcsv($out, $dataRow);
    }
    fclose($out);
    exit;
}

try {
    $written = xlsx_lite_write($tmpPath, $header, $dataRows, $sheetName);
} catch (Throwable $e) {
    error_log('[SukatKalusugan] WHO export write threw: ' . $e->getMessage());
    who_export_fail($indicator, $sex, $ageRange, 'The export file could not be generated.');
}

if (empty($written)) {
    @unlink($tmpPath);
    who_export_fail($indicator, $sex, $ageRange, 'The export file could not be generated.');
}

$fileSize = @filesize($tmpPath);
if ($fileSize === false || $fileSize <= 0) {
    @unlink($tmpPath);
    who_export_fail($indicator, $sex, $ageRange, 'The export file could not be generated.');
}

$downloadName = 'who-' . $indicator . '-' . strtolower($sex) . '-' . $ageRange . '.xlsx';

// Clear any buffered output (warnings/BOM) so the xlsx isn't corrupted —
// this is the most common Azure-only export failure.
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . (string)$fileSize);
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($tmpPath);
@unlink($tmpPath);
exit;
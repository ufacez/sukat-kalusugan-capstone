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

// Zip extension is required — on Azure App Service it can be disabled.
// Fail with a friendly notice instead of a 500.
if (!class_exists('ZipArchive')) {
    who_export_fail(
        (string)($_GET['indicator'] ?? 'waz'),
        (($_GET['sex'] ?? 'Male') === 'Female' ? 'Female' : 'Male'),
        (string)($_GET['range'] ?? 'all'),
        'Export is unavailable: the PHP zip extension is not enabled on this server. Enable extension=zip and try again.'
    );
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
    who_export_fail($indicator, $sex, $ageRange, 'No reference rows found for this indicator. The reference table may not be seeded yet.');
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
<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/monitoring_periods.php';
require_once __DIR__ . '/../includes/who_calculator.php';
require_once __DIR__ . '/../includes/xlsx_lite.php';

/**
 * monitoring_export.php
 *
 * Monitoring List export — same roster + filters as monitoring.php, served as
 * XLSX (default), CSV, or PDF. This is the nutritionist's manual/paper trail:
 * the exported table mirrors the on-screen columns, including the rule that
 * weight/height/status stay blank until a measurement exists in the period.
 *
 * GET: view=monthly|quarterly, year=YYYY, month=1-12 | quarter=1-4,
 *      q=search, format=xlsx|csv|pdf
 */

ob_start();

$user = nutritionist_require_access();

/**
 * Pill-like fill for a WFA/HFA/WFH status cell in the XLSX sheet.
 * Mirrors the on-screen admin-pill colors via the xlsx_lite style keys.
 */
function monitoring_export_status_style(string $code): string
{
    static $map = [
        'Normal' => 'cell_green',
        'MUW' => 'cell_yellow', 'MSt' => 'cell_yellow', 'MW' => 'cell_yellow', 'MW/MAM' => 'cell_yellow',
        'SUW' => 'cell_red', 'SSt' => 'cell_red', 'SW' => 'cell_red', 'SW/SAM' => 'cell_red',
        'OW' => 'cell_orange', 'Ob' => 'cell_orange',
        'Tall' => 'cell_blue',
        'Refer to WFL/H' => 'cell_gray',
    ];
    if ($code === '') {
        return 'cell';
    }
    return $map[$code] ?? 'cell_center';
}

function monitoring_export_fail(array $backParams, string $notice): void
{
    error_log('[SukatKalusugan] Monitoring export failed: ' . $notice);
    admin_redirect('/nutritionist/monitoring.php', array_merge($backParams, [
        'notice' => $notice,
        'type' => 'error',
    ]));
}

// ── Params (mirror monitoring.php) ──
$view = (string)($_GET['view'] ?? 'monthly');
if (!in_array($view, ['monthly', 'quarterly'], true)) {
    $view = 'monthly';
}

$year = (int)($_GET['year'] ?? (int)date('Y'));
if ($year < 2020 || $year > 2100) {
    $year = (int)date('Y');
}

$month = (int)($_GET['month'] ?? (int)date('n'));
if ($month < 1 || $month > 12) {
    $month = (int)date('n');
}

$quarter = (int)($_GET['quarter'] ?? (int)ceil((int)date('n') / 3));
if ($quarter < 1 || $quarter > 4) {
    $quarter = (int)ceil((int)date('n') / 3);
}

$search = trim((string)($_GET['q'] ?? ''));

$format = strtolower(trim((string)($_GET['format'] ?? 'xlsx')));
if ($format !== 'csv' && $format !== 'xlsx' && $format !== 'pdf') {
    $format = 'xlsx';
}
if ($format === 'xlsx' && !class_exists('ZipArchive')) {
    error_log('[SukatKalusugan] Monitoring export: ZipArchive missing, falling back to CSV.');
    $format = 'csv';
}

$backParams = ['view' => $view, 'year' => $year];
if ($view === 'monthly') {
    $period = monitoring_month_range($year, $month);
    $backParams['month'] = $month;
    $periodSlug = sprintf('%04d-%02d', $year, $month);
    $sheetName = substr($period['label'], 0, 31);
} else {
    $period = monitoring_quarter_range($year, $quarter);
    $backParams['quarter'] = $quarter;
    $periodSlug = sprintf('%04d-q%d', $year, $quarter);
    $sheetName = substr($period['label'] . ' ' . $year, 0, 31);
}
if ($search !== '') {
    $backParams['q'] = $search;
}

// ── Roster (same source as the on-screen list) ──
$roster = monitoring_fetch_list($user, $view, $period['start'], $period['end']);

if ($search !== '') {
    $needle = mb_strtolower($search);
    $roster = array_values(array_filter($roster, static function (array $row) use ($needle): bool {
        $haystack = mb_strtolower($row['first_name'] . ' ' . $row['last_name'] . ' ' . $row['child_code']);
        return mb_strpos($haystack, $needle) !== false;
    }));
}

if ($roster === []) {
    monitoring_export_fail($backParams, 'Nothing to export — no children in this roster for the selected period.');
}

$title = ($view === 'monthly' ? 'Monthly Monitoring — ' : 'Quarterly Monitoring — ') . $period['label'];
if ($view === 'quarterly') {
    $title .= ' ' . $year;
}

// ── Rows (mirror the on-screen columns + blank-until-measured rule) ──
$header = ['Child Code', 'Full name', 'Sex', 'Barangay', 'Date', 'Weight (kg)', 'Height (cm)', 'WFA', 'HFA', 'WFH', 'Age (mo)', 'Age (days)'];
$dataRows = [];

foreach ($roster as $entry) {
    $measured = !empty($entry['measured_in_period']) && !empty($entry['period_measurement_date']);
    $ageDays = doh_age((string)$entry['birthdate']) ?? ['days' => 0, 'months' => 0];

    $wfa = '';
    $hfa = '';
    $wfh = '';
    if ($measured) {
        $wfa = (string)($entry['wfa_status'] ?? '');
        $hfa = (string)($entry['hfa_status'] ?? '');
        $wfhRaw = (string)($entry['wfh_status'] ?? '');
        $wfh = $wfhRaw !== '' ? wfh_display_short($wfhRaw) : '';
    }

    $dataRows[] = [
        (string)$entry['child_code'],
        trim((string)$entry['first_name'] . ' ' . (string)$entry['last_name']),
        (string)($entry['sex'] ?? ''),
        (string)($entry['barangay_name'] ?? ''),
        $measured ? date('M j, Y', strtotime((string)$entry['period_measurement_date'])) : 'Not yet',
        ($measured && $entry['last_weight'] !== null) ? number_format((float)$entry['last_weight'], 2) : '',
        ($measured && $entry['last_height'] !== null) ? number_format((float)$entry['last_height'], 1) : '',
        $wfa,
        $hfa,
        $wfh,
        (int)$ageDays['months'],
        (int)$ageDays['days'],
    ];
}

$baseName = 'monitoring-' . $view . '-' . $periodSlug;

// ── PDF ──
if ($format === 'pdf') {
    require_once __DIR__ . '/../includes/pdf_generator.php';

    $pdf = pdf_base('Monitoring List - ' . $title, 'Landscape');
    $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 7, strtoupper($title), 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(0, 5, 'Generated: ' . date('F j, Y g:i A') . '  |  ' . count($dataRows) . ' row(s)' . ($search !== '' ? '  |  Search: ' . $search : ''), 0, 1, 'C');
    $pdf->Ln(3);

    // A4 landscape usable width is 273mm after the base 12mm margins.
    $pdfWidths = [22, 52, 12, 36, 24, 17, 17, 20, 20, 20, 14, 14];
    pdf_table_header($pdf, $header, $pdfWidths, '106E4F', 6);
    $pdf->SetFont('helvetica', '', 6);
    $pdfRows = array_slice($dataRows, 0, 1000);
    $pdfAligns = array_fill(0, count($header), 'C');
    foreach ($pdfRows as $ri => $pdfRow) {
        $cells = array_values($pdfRow);
        $cellFills = [];
        foreach ([7, 8, 9] as $statusCol) {
            $fill = pdf_status_fill((string)($cells[$statusCol] ?? ''));
            if ($fill !== null) {
                $cellFills[$statusCol] = $fill;
            }
        }
        pdf_data_row($pdf, $cells, $pdfWidths, $ri % 2 === 0, $pdfAligns, $cellFills);
    }
    if (count($dataRows) > 1000) {
        $pdf->Ln(2);
        $pdf->SetFont('helvetica', 'I', 7);
        $pdf->Cell(0, 5, 'Showing the first 1,000 of ' . count($dataRows) . ' rows — use XLSX/CSV for the full table.', 0, 1, 'C');
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $pdf->Output($baseName . '.pdf', 'D');
    exit;
}

// ── CSV ──
if ($format === 'csv') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $baseName . '.csv"');
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

// ── XLSX ──
$tmpDir = rtrim((string)sys_get_temp_dir(), "/\\");
if ($tmpDir === '' || !is_dir($tmpDir) || !is_writable($tmpDir)) {
    $fallback = realpath(__DIR__ . '/../../logs');
    if ($fallback !== false && is_writable($fallback)) {
        $tmpDir = $fallback;
    }
}
$tmpPath = $tmpDir . DIRECTORY_SEPARATOR . 'monitoring_export_' . bin2hex(random_bytes(8)) . '.xlsx';

try {
    $sheetRows = [];
    $sheetRows[] = array_map(static fn(string $h): array => ['v' => $h, 's' => 'header'], $header);
    foreach ($dataRows as $dataRow) {
        $cells = [];
        foreach (array_values($dataRow) as $ci => $value) {
            // Status columns (WFA=7, HFA=8, WFH=9) get pill-like colors.
            $style = ($ci === 7 || $ci === 8 || $ci === 9)
                ? monitoring_export_status_style((string)$value)
                : 'cell';
            $cells[] = ['v' => $value, 's' => $style];
        }
        $sheetRows[] = $cells;
    }
    $written = xlsx_lite_write_workbook($tmpPath, [[
        'name' => $sheetName,
        'widths' => [12, 24, 8, 20, 14, 11, 11, 12, 12, 12, 9, 9],
        'rows' => $sheetRows,
    ]]);
} catch (Throwable $e) {
    error_log('[SukatKalusugan] Monitoring export write threw: ' . $e->getMessage());
    @unlink($tmpPath);
    monitoring_export_fail($backParams, 'The export file could not be generated. Please try again.');
}

if (empty($written)) {
    @unlink($tmpPath);
    monitoring_export_fail($backParams, 'The export file could not be generated. Please try again.');
}

$fileSize = @filesize($tmpPath);
if ($fileSize === false || $fileSize <= 0) {
    @unlink($tmpPath);
    monitoring_export_fail($backParams, 'The export file could not be generated. Please try again.');
}

// Clear any buffered output (warnings/BOM) so the xlsx isn't corrupted.
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $baseName . '.xlsx"');
header('Content-Length: ' . (string)$fileSize);
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($tmpPath);
@unlink($tmpPath);
exit;

<?php
declare(strict_types=1);

/**
 * admin/audit_logs_export.php — audit-trail download (CSV / XLSX / PDF).
 *
 * Honors the same action / level / user filters as admin/audit_logs.php.
 * XLSX needs the PHP zip extension; when it is missing the same rows are
 * served as CSV so the button never 500s (same pattern as the EOPT/WHO
 * exports). PDF is capped at the first 1,000 rows to stay printable.
 */

require_once __DIR__ . '/../includes/admin_helpers.php';
require_once __DIR__ . '/../includes/xlsx_lite.php';
require_once __DIR__ . '/../includes/export_preview.php';

start_secure_session();
admin_require_access('audit_logs.view');

$format = strtolower(trim((string)($_GET['format'] ?? 'xlsx')));
if ($format !== 'csv' && $format !== 'xlsx' && $format !== 'pdf') {
    $format = 'xlsx';
}
if ($format === 'xlsx' && !class_exists('ZipArchive')) {
    $format = 'csv';
}
// Drive-style preview modal: CSV/XLSX fetch ?preview=json, PDFs load ?preview=inline in an iframe
// plus a lightweight ?preview=json metadata fetch for the modal header.
$isPreviewJson = isset($_GET['preview']) && $_GET['preview'] === 'json';

$actionFilter = (string)($_GET['action'] ?? '');
if (!in_array($actionFilter, ['login', 'logout', 'create', 'read', 'update', 'delete'], true)) {
    $actionFilter = '';
}
$levelFilter = strtolower((string)($_GET['level'] ?? ''));
if (!in_array($levelFilter, ['info', 'warning', 'danger'], true)) {
    $levelFilter = '';
}
$userFilter = strtolower((string)($_GET['user'] ?? ''));
if (!in_array($userFilter, ['admin', 'nutritionist', 'parent'], true)) {
    $userFilter = '';
}

$auditJoins = 'FROM audit_logs a
     LEFT JOIN users u ON u.id = a.user_id AND (a.user_type IS NULL OR a.user_type != "parent")
     LEFT JOIN parents p ON p.id = a.user_id AND (a.user_type = "parent" OR (a.user_type IS NULL AND u.id IS NULL))
     LEFT JOIN roles r ON r.id = u.role_id';

$filterWhere = '';
if ($actionFilter === 'login') {
    $filterWhere = "AND a.action = 'LOGIN'";
} elseif ($actionFilter === 'logout') {
    $filterWhere = "AND a.action = 'LOGOUT'";
} elseif ($actionFilter === 'create') {
    $filterWhere = "AND (a.action LIKE 'CREATE_%' OR a.action = 'measurement.create')";
} elseif ($actionFilter === 'read') {
    $filterWhere = "AND a.action IN ('EOPT_EXPORT','EOPT_LIST_EXPORT','FOLLOWUP_SYNC','PASSWORD_RESET_REQUEST','PASSWORD_RESET_COMPLETE')";
} elseif ($actionFilter === 'update') {
    $filterWhere = "AND a.action LIKE 'UPDATE_%'";
} elseif ($actionFilter === 'delete') {
    $filterWhere = "AND a.action LIKE 'DELETE_%'";
}
if ($levelFilter !== '') {
    // Level comes from a fixed allow-list above — safe to inline.
    $filterWhere .= ' AND a.level = "' . $levelFilter . '"';
}
if ($userFilter === 'parent') {
    $filterWhere .= ' AND (a.user_type = "parent" OR (a.user_type IS NULL AND u.id IS NULL AND p.id IS NOT NULL))';
} elseif ($userFilter === 'admin' || $userFilter === 'nutritionist') {
    $filterWhere .= ' AND (a.user_type = "' . $userFilter . '" OR (a.user_type IS NULL AND r.name = "' . $userFilter . '"))';
}

$rows = admin_fetch_all(
    'SELECT a.created_at, a.action, a.level, a.description, a.ip_address,
            COALESCE(u.email, p.email, "System") AS actor,
            COALESCE(u.name, p.name, "System") AS actor_name,
            COALESCE(a.user_type, r.name, "system") AS resolved_type
     ' . $auditJoins . '
     WHERE 1=1 ' . $filterWhere . '
     ORDER BY a.created_at DESC, a.id DESC
     LIMIT 5000'
);

$header = ['Time', 'Actor name', 'Actor email', 'Role', 'Action', 'Level', 'Description', 'IP address'];
$dataRows = [];
foreach ($rows as $row) {
    $dataRows[] = [
        (string)($row['created_at'] ?? ''),
        (string)($row['actor_name'] ?? ''),
        (string)($row['actor'] ?? ''),
        (string)($row['resolved_type'] ?? ''),
        (string)($row['action'] ?? ''),
        (string)($row['level'] ?? ''),
        (string)($row['description'] ?? ''),
        (string)($row['ip_address'] ?? ''),
    ];
}

$downloadBase = 'audit-logs-' . date('Y-m-d');

// ── Preview JSON (lightweight, no file generated) ──
// Handles ?preview=json for xlsx (default), csv, and pdf metadata.
if ($isPreviewJson) {
	while (ob_get_level() > 0) {
		ob_end_clean();
	}
	if ($format === 'pdf') {
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode([
			'success' => true,
			'title' => 'Audit Trail - ' . $downloadBase,
			'filename' => $downloadBase . '.pdf',
			'format' => 'pdf',
			'total_rows' => count($dataRows),
			'generated' => date('F j, Y g:i A'),
			'note' => '',
		], JSON_UNESCAPED_UNICODE);
		exit;
	}
	$payload = [
		'success' => true,
		'title' => 'Audit Trail - ' . $downloadBase,
		'filename' => $downloadBase . '.' . $format,
		'format' => $format,
		'total_rows' => count($dataRows),
		'generated' => date('F j, Y g:i A'),
		'headers' => $header,
		'preview_rows' => array_slice($dataRows, 0, 20),
		'preview_count' => min(20, count($dataRows)),
		'note' => count($dataRows) > 20 ? 'Showing the first 20 of ' . count($dataRows) . ' rows — download for the full file.' : '',
	];
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($payload, JSON_UNESCAPED_UNICODE);
	exit;
}

if ($format === 'pdf') {
	require_once __DIR__ . '/../includes/pdf_generator.php';
	$previewInline = isset($_GET['preview']) && $_GET['preview'] === 'inline';
    $pdf = pdf_base('Audit Trail - ' . $downloadBase, 'Landscape');
    $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 7, 'AUDIT TRAIL', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 8);
    $filterBits = [];
    if ($actionFilter !== '') $filterBits[] = 'Action: ' . $actionFilter;
    if ($levelFilter !== '') $filterBits[] = 'Level: ' . $levelFilter;
    if ($userFilter !== '') $filterBits[] = 'User: ' . $userFilter;
    $pdf->Cell(0, 5, ($filterBits !== [] ? implode('  |  ', $filterBits) . '  |  ' : '') . 'Generated: ' . date('F j, Y g:i A') . '  |  ' . count($dataRows) . ' row(s)', 0, 1, 'C');
    $pdf->Ln(3);
    // A4 landscape usable width is 273mm after the base 12mm margins.
    $cols = ['Time', 'Actor', 'Role', 'Action', 'Level', 'Description', 'IP'];
    $widths = [27, 38, 20, 36, 15, 112, 25];
    pdf_table_header($pdf, $cols, $widths, '106E4F', 6);
    $pdf->SetFont('helvetica', '', 6);
    $pdfRows = array_slice($dataRows, 0, 1000);
    foreach ($pdfRows as $ri => $pdfRow) {
        $pdf->SetFillColor($ri % 2 === 0 ? 240 : 255, $ri % 2 === 0 ? 248 : 255, $ri % 2 === 0 ? 244 : 255);
        $cells = [
            (string)($pdfRow[0] ?? ''),
            mb_strimwidth((string)($pdfRow[1] ?? ''), 0, 26, '…'),
            (string)($pdfRow[3] ?? ''),
            mb_strimwidth((string)($pdfRow[4] ?? ''), 0, 26, '…'),
            strtoupper((string)($pdfRow[5] ?? '')),
            mb_strimwidth((string)($pdfRow[6] ?? ''), 0, 150, '…'),
            (string)($pdfRow[7] ?? ''),
        ];
        foreach ($cells as $ci => $cell) {
            $pdf->Cell($widths[$ci], 6, $cell, 1, 0, 'L', true);
        }
        $pdf->Ln();
    }
    if (count($dataRows) > 1000) {
        $pdf->Ln(2);
        $pdf->SetFont('helvetica', 'I', 7);
        $pdf->Cell(0, 5, 'Showing the first 1,000 of ' . count($dataRows) . ' rows — use CSV/XLSX for the full trail.', 0, 1, 'C');
    }
    $actor = current_user();
	log_action($actor['id'] ?? null, 'AUDIT_EXPORT', 'info', sprintf('Exported audit trail PDF (%d row(s)).', count($dataRows)));
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if ($previewInline) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $downloadBase . '.pdf"');
    } else {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $downloadBase . '.pdf"');
    }
    $pdf->Output($downloadBase . '.pdf', $previewInline ? 'I' : 'D');
    exit;
}

if ($format === 'csv') {
	$previewJson = isset($_GET['preview']) && $_GET['preview'] === 'json';
	$actor = current_user();
	if (!$previewJson) {
		log_action($actor['id'] ?? null, 'AUDIT_EXPORT', 'info', sprintf('Exported audit trail CSV (%d row(s)).', count($dataRows)));
	}
	while (ob_get_level() > 0) {
		ob_end_clean();
	}
	if ($previewJson) {
		$payload = [
			'success' => true,
			'title' => 'Audit Trail - ' . $downloadBase,
			'filename' => $downloadBase . '.csv',
			'format' => 'csv',
			'total_rows' => count($dataRows),
			'generated' => date('F j, Y g:i A'),
			'headers' => $header,
			'preview_rows' => array_slice($dataRows, 0, 20),
			'preview_count' => min(20, count($dataRows)),
			'note' => count($dataRows) > 20 ? 'Showing the first 20 of ' . count($dataRows) . ' rows \u2014 download for the full file.' : '',
		];
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode($payload, JSON_UNESCAPED_UNICODE);
		exit;
	}
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="' . $downloadBase . '.csv"');
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

$tmpDir = rtrim((string)sys_get_temp_dir(), "/\\");
if ($tmpDir === '' || !is_dir($tmpDir) || !is_writable($tmpDir)) {
    $fallback = realpath(__DIR__ . '/../../logs');
    if ($fallback !== false && is_writable($fallback)) {
        $tmpDir = $fallback;
    }
}
$tmpPath = $tmpDir . DIRECTORY_SEPARATOR . 'audit_export_' . bin2hex(random_bytes(8)) . '.xlsx';

try {
    $written = xlsx_lite_write($tmpPath, $header, $dataRows, 'AuditLog');
} catch (Throwable $e) {
    error_log('[SukatKalusugan] Audit export write threw: ' . $e->getMessage());
    $written = false;
}

$fileSize = ($written) ? @filesize($tmpPath) : false;
if ($fileSize === false || $fileSize <= 0) {
    error_log('[SukatKalusugan] Audit export: xlsx unavailable, falling back to CSV.');
    @unlink($tmpPath);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $downloadBase . '.csv"');
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

$actor = current_user();
log_action($actor['id'] ?? null, 'AUDIT_EXPORT', 'info', sprintf('Exported audit trail XLSX (%d row(s)).', count($dataRows)));
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $downloadBase . '.xlsx"');
header('Content-Length: ' . (string)$fileSize);
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
readfile($tmpPath);
@unlink($tmpPath);
exit;

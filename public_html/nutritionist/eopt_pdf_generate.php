<?php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_middleware.php';
require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/export_preview.php';
$pdfGeneratorPath = __DIR__ . '/../includes/pdf_generator.php';
if (function_exists('opcache_invalidate')) {
	@opcache_invalidate($pdfGeneratorPath, true);
}
require_once __DIR__ . '/../includes/pdf_generator.php';

$user = nutritionist_require_access();

$reportType = (string)($_GET['report_type'] ?? '');
$listCode = (string)($_GET['list_code'] ?? '');
$childId = (int)($_GET['child_id'] ?? 0);

$f = pdf_scope_and_filter();

$pdf = null;

switch ($reportType) {
	case 'form1a':
		$pdf = pdf_generate_form1a($f);
		$filename = 'eopt-form1a-' . strtolower(str_replace(' ', '-', $f['period_label'])) . '-' . date('Y-m-d') . '.pdf';
		break;

	case 'form1b':
		$pdf = pdf_generate_form1b($f);
		$filename = 'eopt-form1b-' . strtolower(str_replace(' ', '-', $f['period_label'])) . '-' . date('Y-m-d') . '.pdf';
		break;

	case 'form1c':
		$pdf = pdf_generate_form1c($f);
		$filename = 'eopt-form1c-' . strtolower(str_replace(' ', '-', $f['period_label'])) . '-' . date('Y-m-d') . '.pdf';
		break;

	case 'nutstatus':
		$pdf = pdf_generate_nutstatus($f);
		$filename = 'nutstatustool-' . strtolower(str_replace(' ', '-', $f['period_label'])) . '-' . date('Y-m-d') . '.pdf';
		break;

	case 'nutstatusbrgy':
		$pdf = pdf_generate_nutstatusbrgy($f);
		$filename = 'nutstatusbrgy-' . strtolower(str_replace(' ', '-', $f['period_label'])) . '-' . date('Y-m-d') . '.pdf';
		break;

	case 'list':
		$validCodes = ['0-23', 'MW', 'SW', 'MSt_SSt', 'OW_Ob', 'MUW', 'MUW_SUW_MSt_SSt', 'MSt_SSt_MW_SW', 'MSt_SSt_OW_Ob'];
		if (!in_array($listCode, $validCodes, true)) {
			admin_redirect(app_url('/nutritionist/eopt_reports.php'), ['notice' => 'Invalid monitoring list code.', 'type' => 'error']);
			exit;
		}
		$pdf = pdf_generate_monitoring_list($listCode, $f);
		$filename = 'eopt-list-' . strtolower($listCode) . '-' . date('Y-m-d') . '.pdf';
		break;

	case 'prevalence':
		$pdf = pdf_generate_prevalence($f);
		$filename = 'eopt-prevalence-' . date('Y-m-d') . '.pdf';
		break;

	case 'dqc':
		$pdf = pdf_generate_dqc($f);
		$filename = 'eopt-dqc-' . date('Y-m-d') . '.pdf';
		break;

	case 'summary':
		$pdf = pdf_generate_nutrition_summary($f);
		$filename = 'eopt-nutrition-summary-' . date('Y-m-d') . '.pdf';
		break;

	case 'referral':
		if ($childId <= 0) {
			admin_redirect(app_url('/nutritionist/eopt_reports.php'), ['notice' => 'Invalid child ID for referral.', 'type' => 'error']);
			exit;
		}
		$pdf = pdf_generate_referral($childId);
		$filename = 'eopt-referral-' . $childId . '-' . date('Y-m-d') . '.pdf';
		break;

	default:
		admin_redirect(app_url('/nutritionist/eopt_reports.php'), ['notice' => 'Unknown report type.', 'type' => 'error']);
		exit;
}

if (!$pdf) {
	admin_redirect(app_url('/nutritionist/eopt_reports.php'), ['notice' => 'Could not generate the PDF report.', 'type' => 'error']);
	exit;
}

require_once __DIR__ . '/../includes/audit_logger.php';
$isPdfPreviewJson = isset($_GET['preview']) && $_GET['preview'] === 'json';
if ($isPdfPreviewJson) {
	while (ob_get_level() > 0) {
		ob_end_clean();
	}
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode([
		'success' => true,
		'title' => $filename,
		'filename' => $filename,
		'format' => 'pdf',
		'generated' => date('F j, Y g:i A'),
		'note' => '',
	], JSON_UNESCAPED_UNICODE);
	exit;
}
log_action((int)$user['id'], 'EOPT_PDF_EXPORT', 'info', sprintf('Generated PDF report: %s', $reportType . ($listCode !== '' ? '/' . $listCode : '')));

$previewInline = isset($_GET['preview']) && $_GET['preview'] === 'inline';
if ($previewInline) {
	$pdf->Output($filename, 'I');
} else {
	$pdf->Output($filename, 'D');
}
exit;

<?php
declare(strict_types=1);

/**
 * export_dropdown.php — reusable "Save as" export menu (CSV / XLSX / PDF).
 *
 * Modern minimal dropdown built on <details>/<summary> (no JS framework).
 * Pass RAW (unescaped) URLs; they are escaped here exactly once.
 */

if (!function_exists('admin_action_icon')) {
	require_once __DIR__ . '/admin_helpers.php';
}

function export_dropdown_assets(): string
{
	static $printed = false;
	if ($printed) {
		return '';
	}
	$printed = true;
	return <<<'HTML'
<style>
.export-dd{position:relative;display:inline-block}
.export-dd summary{list-style:none;cursor:pointer;display:inline-flex;align-items:center;gap:8px}
.export-dd summary::-webkit-details-marker{display:none}
.export-dd summary::after{content:'';width:7px;height:7px;border-right:1.6px solid currentColor;border-bottom:1.6px solid currentColor;transform:rotate(45deg) translateY(-2px);opacity:.7;flex:none}
.export-dd[open] summary::after{transform:rotate(-135deg) translateY(-1px)}
.export-dd-pop{position:absolute;right:0;top:calc(100% + 8px);z-index:60;min-width:230px;padding:6px;background:var(--admin-surface,#fff);border:1px solid var(--admin-border,#e5e7eb);border-radius:12px;box-shadow:0 12px 32px rgba(15,23,42,.14)}
.export-dd-item{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:8px;text-decoration:none;color:var(--admin-text,#111827)}
.export-dd-item:hover{background:var(--admin-surface-alt,#f3f4f6)}
.export-dd-item svg{width:20px;height:20px;flex:none}
.export-dd-item .fmt{margin-left:auto;font-size:10px;font-weight:800;letter-spacing:.06em;padding:3px 7px;border-radius:999px;flex:none}
.export-dd-xls svg{color:#15803d}.export-dd-xls .fmt{background:rgba(21,128,61,.12);color:#15803d}
.export-dd-csv svg{color:#475569}.export-dd-csv .fmt{background:rgba(71,85,105,.12);color:#475569}
.export-dd-pdf svg{color:#b91c1c}.export-dd-pdf .fmt{background:rgba(185,28,28,.1);color:#b91c1c}
.export-dd-label{font-size:13px;font-weight:700;line-height:1.2}
.export-dd-sub{font-size:11px;color:var(--admin-muted,#6b7280);font-weight:500}
.export-dd-trigger-icon{width:28px;height:28px;min-height:28px;padding:0;justify-content:center}
.export-dd-trigger-icon::after{display:none}
.export-dd-trigger-icon svg{width:15px;height:15px}
</style>
<script>
(function(){
if (window.__exportDdInit) return; window.__exportDdInit = true;
document.addEventListener('click', function(e){
var open = document.querySelectorAll('.export-dd[open]');
if (!open.length) return;
var inside = e.target && e.target.closest ? e.target.closest('.export-dd') : null;
open.forEach(function(dd){ if (dd !== inside) dd.removeAttribute('open'); });
});
document.addEventListener('keydown', function(e){
if (e.key === 'Escape') document.querySelectorAll('.export-dd[open]').forEach(function(dd){ dd.removeAttribute('open'); });
});
document.addEventListener('toggle', function(e){
var dd = e.target && e.target.closest ? e.target.closest('.export-dd') : null;
if (dd && dd.open) document.querySelectorAll('.export-dd[open]').forEach(function(o){ if (o !== dd) o.removeAttribute('open'); });
}, true);
})();
</script>
HTML;
}

/**
 * @param string      $xlsxUrl raw URL for the .xlsx download
 * @param string|null $csvUrl  raw URL for the .csv download (null hides it)
 * @param string|null $pdfUrl  raw URL for the .pdf download (null hides it)
 * @param string      $label   trigger button text
 * @param string      $variant 'button' (full Save-as pill) or 'icon' (28px icon trigger)
 */
function export_dropdown(string $xlsxUrl, ?string $csvUrl = null, ?string $pdfUrl = null, string $label = 'Save as', string $variant = 'button'): string
{
	$esc = static fn(string $u): string => htmlspecialchars($u, ENT_QUOTES, 'UTF-8');
	$html = export_dropdown_assets();
	$isIcon = $variant === 'icon';
	$triggerClass = $isIcon ? 'admin-icon-btn admin-icon-btn-primary export-dd-trigger-icon' : 'admin-btn';
	$triggerInner = $isIcon
		? admin_action_icon('export')
		: admin_action_icon('export') . ' ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
	$triggerTitle = $isIcon ? ' title="Save as: XLSX, CSV, PDF" aria-label="Save as: XLSX, CSV, PDF"' : '';

	$html .= '<details class="export-dd">';
	$html .= '<summary class="' . $triggerClass . '"' . $triggerTitle . '>' . $triggerInner . '</summary>';
	$html .= '<div class="export-dd-pop" role="menu">';
	$html .= '<a class="export-dd-item export-dd-xls" role="menuitem" href="' . $esc($xlsxUrl) . '">'
		. admin_action_icon('file_xls')
		. '<span><span class="export-dd-label">Excel workbook</span><br><span class="export-dd-sub">Formatted sheets, ready to print</span></span>'
		. '<span class="fmt">XLSX</span></a>';
	if ($csvUrl !== null && $csvUrl !== '') {
		$html .= '<a class="export-dd-item export-dd-csv" role="menuitem" href="' . $esc($csvUrl) . '">'
			. admin_action_icon('file_csv')
			. '<span><span class="export-dd-label">Raw data</span><br><span class="export-dd-sub">Opens in Excel &amp; Sheets</span></span>'
			. '<span class="fmt">CSV</span></a>';
	}
	if ($pdfUrl !== null && $pdfUrl !== '') {
		$html .= '<a class="export-dd-item export-dd-pdf" role="menuitem" href="' . $esc($pdfUrl) . '">'
			. admin_action_icon('file_pdf')
			. '<span><span class="export-dd-label">PDF document</span><br><span class="export-dd-sub">Official printable copy</span></span>'
			. '<span class="fmt">PDF</span></a>';
	}
	$html .= '</div></details>';
	return $html;
}

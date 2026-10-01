<?php
declare(strict_types=1);

/**
 * export_preview.php — Drive-style "preview before download" modal.
 *
 * Shared by every export dropdown (monitoring, EOPT reports, WHO reference,
 * audit logs). The dropdown keeps working without JS (plain download links);
 * when JS is available, clicks are intercepted and a modal opens first:
 *
 *   PDF      -> real PDF rendered in an <iframe> (first page visible, like Drive)
 *   CSV/XLSX -> Excel-like grid with the first ~20 rows + sheet tabs for
 *               multi-sheet workbooks (EOPT)
 *
 * Backend contract (implemented per export endpoint):
 *   &preview=json   -> JSON {success,title,filename,format,total_rows,
 *                     preview_rows,headers,rows,sheets,sheet,generated,note}
 *                     Simple single-table exports use headers+preview_rows;
 *                     multi-sheet workbooks use sheets+sheet+rows where each
 *                     row is {cells:[...], kind:title|label|header|data|total|blank}.
 *   &preview=inline -> PDF streamed with Content-Disposition: inline (for iframe)
 */

if (!function_exists('export_preview_url')) {
	/**
	 * Build the modal preview URL from a download URL.
	 * CSV/XLSX previews fetch lightweight JSON; PDFs load inline in an iframe.
	 */
	function export_preview_url(string $downloadUrl, string $format): string
	{
		$sep = (strpos($downloadUrl, '?') === false) ? '?' : '&';
		if ($format === 'pdf') {
			return $downloadUrl . $sep . 'preview=inline';
		}
		return $downloadUrl . $sep . 'preview=json';
	}
}

if (!function_exists('export_preview_assets')) {
	function export_preview_assets(): string
	{
		static $printed = false;
		if ($printed) {
			return '';
		}
		$printed = true;
		return <<<'HTML'
<style>
.exp-preview-overlay{position:fixed;inset:0;z-index:200;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(15,23,42,.55);backdrop-filter:blur(2px)}
.exp-preview-overlay[hidden]{display:none}
.exp-preview-modal{background:var(--admin-surface,#fff);color:var(--admin-text,#111827);border-radius:16px;box-shadow:0 24px 64px rgba(15,23,42,.35);width:min(980px,100%);max-height:88vh;display:flex;flex-direction:column;overflow:hidden;border:1px solid var(--admin-border,#e5e7eb)}
.exp-preview-head{display:flex;align-items:flex-start;gap:12px;padding:16px 18px 12px;border-bottom:1px solid var(--admin-border,#e5e7eb)}
.exp-preview-head-text{min-width:0;flex:1}
.exp-preview-title-row{display:flex;align-items:center;gap:8px;min-width:0}
.exp-preview-title{font-size:15px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.exp-preview-fmt{font-size:10px;font-weight:800;letter-spacing:.06em;padding:3px 8px;border-radius:999px;flex:none;background:var(--admin-surface-alt,#f3f4f6);color:var(--admin-muted,#6b7280)}
.exp-preview-fmt.is-xlsx{background:rgba(21,128,61,.12);color:#15803d}
.exp-preview-fmt.is-csv{background:rgba(71,85,105,.14);color:#475569}
.exp-preview-fmt.is-pdf{background:rgba(185,28,28,.1);color:#b91c1c}
.exp-preview-meta{font-size:12px;color:var(--admin-muted,#6b7280);margin-top:3px}
.exp-preview-close{flex:none;width:32px;height:32px;border-radius:8px;border:1px solid var(--admin-border,#e5e7eb);background:transparent;color:inherit;font-size:18px;line-height:1;cursor:pointer}
.exp-preview-close:hover{background:var(--admin-surface-alt,#f3f4f6)}
.exp-preview-sheets{display:flex;gap:6px;flex-wrap:wrap;padding:10px 18px 0}
.exp-preview-sheets[hidden]{display:none}
.exp-preview-sheet{font-size:11px;font-weight:700;padding:5px 10px;border-radius:999px;border:1px solid var(--admin-border,#e5e7eb);background:transparent;color:inherit;cursor:pointer;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.exp-preview-sheet.is-active{background:var(--admin-primary,#106e4f);border-color:var(--admin-primary,#106e4f);color:#fff}
.exp-preview-body{padding:14px 18px;overflow:hidden;display:flex;flex-direction:column;gap:8px;min-height:200px}
.exp-preview-loading{display:flex;align-items:center;gap:10px;justify-content:center;padding:48px 0;color:var(--admin-muted,#6b7280);font-size:13px}
.exp-preview-spinner{width:18px;height:18px;border-radius:50%;border:2.5px solid var(--admin-border,#e5e7eb);border-top-color:var(--admin-primary,#106e4f);animation:exp-preview-spin .7s linear infinite;flex:none}
@keyframes exp-preview-spin{to{transform:rotate(360deg)}}
.exp-preview-error{font-size:13px;background:rgba(185,28,28,.08);border:1px solid rgba(185,28,28,.25);color:#991b1b;border-radius:10px;padding:10px 12px}
.exp-preview-error[hidden]{display:none}
.exp-preview-grid-wrap{overflow:auto;border:1px solid var(--admin-border,#e5e7eb);border-radius:10px;max-height:52vh;background:var(--admin-surface,#fff)}
.exp-preview-grid-wrap[hidden]{display:none}
.exp-preview-grid{border-collapse:collapse;width:100%;font-size:11.5px;white-space:nowrap}
.exp-preview-grid th,.exp-preview-grid td{border:1px solid var(--admin-border,#e5e7eb);padding:6px 9px;text-align:left;max-width:280px;overflow:hidden;text-overflow:ellipsis}
.exp-preview-grid thead th{position:sticky;top:0;background:#106e4f;color:#fff;font-weight:700;z-index:1}
.exp-preview-grid tr.exp-row-title td{background:var(--admin-surface-alt,#f3f4f6);font-weight:800;text-align:center}
.exp-preview-grid tr.exp-row-label td{background:rgba(16,110,79,.06);font-weight:600;font-size:11px}
.exp-preview-grid tr.exp-row-total td{background:rgba(16,110,79,.1);font-weight:800}
.exp-preview-grid tbody tr.exp-row-data:nth-child(even) td{background:rgba(15,23,42,.025)}
.exp-preview-frame{width:100%;height:56vh;border:1px solid var(--admin-border,#e5e7eb);border-radius:10px;background:#525659}
.exp-preview-frame[hidden]{display:none}
.exp-preview-note{font-size:11.5px;color:var(--admin-muted,#6b7280)}
.exp-preview-foot{display:flex;justify-content:flex-end;gap:8px;padding:12px 18px 16px;border-top:1px solid var(--admin-border,#e5e7eb)}
@media(max-width:640px){.exp-preview-overlay{padding:10px}.exp-preview-frame{height:62vh}.exp-preview-grid-wrap{max-height:56vh}}
</style>
<div class="exp-preview-overlay" id="expPreviewOverlay" hidden>
	<div class="exp-preview-modal" role="dialog" aria-modal="true" aria-labelledby="expPreviewTitle">
		<div class="exp-preview-head">
			<div class="exp-preview-head-text">
				<div class="exp-preview-title-row"><span class="exp-preview-title" id="expPreviewTitle">File preview</span><span class="exp-preview-fmt" id="expPreviewFmt">FILE</span></div>
				<div class="exp-preview-meta" id="expPreviewMeta">Loading&hellip;</div>
			</div>
			<button type="button" class="exp-preview-close" id="expPreviewClose" aria-label="Close preview">&times;</button>
		</div>
		<div class="exp-preview-sheets" id="expPreviewSheets" hidden></div>
		<div class="exp-preview-body">
			<div class="exp-preview-loading" id="expPreviewLoading"><span class="exp-preview-spinner"></span><span>Loading preview&hellip;</span></div>
			<div class="exp-preview-error" id="expPreviewError" hidden></div>
			<div class="exp-preview-grid-wrap" id="expPreviewGridWrap" hidden><table class="exp-preview-grid" id="expPreviewGrid"></table></div>
			<iframe class="exp-preview-frame" id="expPreviewFrame" hidden title="PDF preview"></iframe>
			<div class="exp-preview-note" id="expPreviewNote"></div>
		</div>
		<div class="exp-preview-foot">
			<button type="button" class="admin-btn-secondary" id="expPreviewCancel">Cancel</button>
			<a class="admin-btn" id="expPreviewDownload" href="#">Download</a>
		</div>
	</div>
</div>
<script>
(function(){
if (window.__expPreviewInit) return; window.__expPreviewInit = true;
var overlay = null, grid = null, gridWrap = null, frame = null, sheetsBar = null;
var titleEl = null, fmtEl = null, metaEl = null, noteEl = null, errEl = null, loadEl = null, dlBtn = null;
var lastFocus = null, jsonBase = '', activeSheet = '';

function els(){
	if (overlay) return true;
	overlay = document.getElementById('expPreviewOverlay');
	if (!overlay) return false;
	grid = document.getElementById('expPreviewGrid');
	gridWrap = document.getElementById('expPreviewGridWrap');
	frame = document.getElementById('expPreviewFrame');
	sheetsBar = document.getElementById('expPreviewSheets');
	titleEl = document.getElementById('expPreviewTitle');
	fmtEl = document.getElementById('expPreviewFmt');
	metaEl = document.getElementById('expPreviewMeta');
	noteEl = document.getElementById('expPreviewNote');
	errEl = document.getElementById('expPreviewError');
	loadEl = document.getElementById('expPreviewLoading');
	dlBtn = document.getElementById('expPreviewDownload');
	var closeBtn = document.getElementById('expPreviewClose');
	var cancelBtn = document.getElementById('expPreviewCancel');
	if (closeBtn) closeBtn.addEventListener('click', closeModal);
	if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
	overlay.addEventListener('click', function(e){ if (e.target === overlay) closeModal(); });
	return true;
}

function openModal(opts){
	if (!els()) return;
	lastFocus = document.activeElement;
	jsonBase = opts.jsonUrl || '';
	activeSheet = '';
	overlay.hidden = false;
	document.body.style.overflow = 'hidden';
	titleEl.textContent = opts.title || 'File preview';
	fmtEl.textContent = (opts.format || 'FILE').toUpperCase();
	fmtEl.className = 'exp-preview-fmt is-' + (opts.format || 'file');
	metaEl.textContent = 'Loading\u2026';
	noteEl.textContent = '';
	errEl.hidden = true;
	errEl.textContent = '';
	gridWrap.hidden = true;
	grid.innerHTML = '';
	frame.hidden = true;
	frame.removeAttribute('src');
	sheetsBar.hidden = true;
	sheetsBar.innerHTML = '';
	loadEl.style.display = 'flex';
	dlBtn.setAttribute('href', opts.downloadUrl);
	var closeBtn = document.getElementById('expPreviewClose');
	if (closeBtn) closeBtn.focus();
	if (opts.format === 'pdf') {
		frame.src = opts.previewUrl;
		frame.hidden = false;
		loadEl.style.display = 'none';
		if (jsonBase) fetchJson(jsonBase, opts);
		else metaEl.textContent = 'PDF document \u00b7 first page shown below';
	} else {
		fetchJson(opts.previewUrl, opts);
	}
}

function closeModal(){
	if (!overlay || overlay.hidden) return;
	overlay.hidden = true;
	document.body.style.overflow = '';
	frame.removeAttribute('src');
	if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
}

function fetchJson(url, opts){
	loadEl.style.display = 'flex';
	fetch(url, {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
		.then(function(resp){
			var ct = resp.headers.get('content-type') || '';
			if (!resp.ok) throw new Error('Preview request failed (' + resp.status + ').');
			if (ct.indexOf('application/json') === -1) throw new Error('Preview is not available for this file yet.');
			return resp.json();
		})
		.then(function(data){ renderData(data, opts); })
		.catch(function(err){
			loadEl.style.display = 'none';
			errEl.hidden = false;
			errEl.textContent = err.message + ' You can still download the full file below.';
			if (opts.format === 'pdf') metaEl.textContent = 'PDF document \u00b7 first page shown below';
			else metaEl.textContent = 'Preview unavailable';
		});
}

function renderData(data, opts){
	loadEl.style.display = 'none';
	if (!data || data.success !== true) {
		errEl.hidden = false;
		errEl.textContent = (data && data.message) ? data.message : 'Preview is not available for this file yet. You can still download the full file below.';
		metaEl.textContent = 'Preview unavailable';
		return;
	}
	titleEl.textContent = data.title || opts.title || 'File preview';
	var metaBits = [];
	if (data.filename) metaBits.push(data.filename);
	if (typeof data.total_rows === 'number') metaBits.push(data.total_rows + ' row' + (data.total_rows === 1 ? '' : 's'));
	if (data.generated) metaBits.push('Generated ' + data.generated);
	metaEl.textContent = metaBits.length ? metaBits.join(' \u00b7 ') : ((opts.format || '').toUpperCase() + ' preview');
	if (opts.format === 'pdf') {
		noteEl.textContent = data.note || '';
		return;
	}
	if (Array.isArray(data.sheets) && data.sheets.length > 1) {
		if (opts && opts.previewUrl) jsonBase = opts.previewUrl;
		renderSheets(data.sheets, data.sheet || '');
	} else {
		sheetsBar.hidden = true;
	}
	if (Array.isArray(data.rows) && data.rows.length) {
		renderSheetRows(data.rows);
	} else if (Array.isArray(data.headers)) {
		renderSimpleTable(data.headers, data.preview_rows || []);
	} else {
		errEl.hidden = false;
		errEl.textContent = 'Nothing to preview \u2014 no rows in this file for the selected filters.';
		return;
	}
	var shown = (typeof data.preview_count === 'number') ? data.preview_count : null;
	var total = (typeof data.total_rows === 'number') ? data.total_rows : null;
	if (shown !== null && total !== null && total > shown) {
		noteEl.textContent = 'Showing the first ' + shown + ' of ' + total + ' rows \u2014 download for the full file.';
	} else {
		noteEl.textContent = data.note || '';
	}
}

function renderSheets(sheets, active){
	sheetsBar.innerHTML = '';
	sheets.forEach(function(name){
		var b = document.createElement('button');
		b.type = 'button';
		b.className = 'exp-preview-sheet' + (name === active ? ' is-active' : '');
		b.textContent = name;
		b.setAttribute('aria-pressed', name === active ? 'true' : 'false');
		b.addEventListener('click', function(){ switchSheet(name); });
		sheetsBar.appendChild(b);
	});
	sheetsBar.hidden = false;
	activeSheet = active;
}

function switchSheet(name){
	if (name === activeSheet) return;
	activeSheet = name;
	var btns = sheetsBar.querySelectorAll('.exp-preview-sheet');
	btns.forEach(function(b){
		var on = b.textContent === name;
		b.classList.toggle('is-active', on);
		b.setAttribute('aria-pressed', on ? 'true' : 'false');
	});
	var sep = jsonBase.indexOf('?') === -1 ? '?' : '&';
	fetchJson(jsonBase + sep + 'sheet=' + encodeURIComponent(name), {format: 'xlsx', title: titleEl.textContent, previewUrl: jsonBase});
}

function cellText(v){
	if (v === null || v === undefined) return '';
	if (typeof v === 'object') {
		if (Array.isArray(v)) return v.map(cellText).join(' ');
		if ('v' in v) return cellText(v.v);
		if ('value' in v) return cellText(v.value);
		if ('text' in v) return cellText(v.text);
		return '';
	}
	return String(v);
}

function normalizeRow(r){
	// Canonical shape: {cells:[...], kind:'...'}. Also accept xlsx-writer
	// shape ([{v,s},...] or plain scalars) so a backend mismatch still renders.
	if (r !== null && typeof r === 'object' && !Array.isArray(r) && Array.isArray(r.cells)) {
		return {cells: r.cells, kind: (r.kind || 'data'), styles: r.styles || []};
	}
	var arr = Array.isArray(r) ? r : (r !== null && typeof r === 'object' && Array.isArray(r.row) ? r.row : [r]);
	var cells = [];
	var styles = [];
	arr.forEach(function(c){
		if (c !== null && typeof c === 'object' && !Array.isArray(c) && ('v' in c || 's' in c)) {
			cells.push(('v' in c) ? c.v : '');
			styles.push(c.s || '');
		} else {
			cells.push(c);
			styles.push('');
		}
	});
	var kind = 'data';
	if (styles.indexOf('header') !== -1 || styles.indexOf('header_left') !== -1) kind = 'header';
	else if (styles.indexOf('title') !== -1 || styles.indexOf('subtitle') !== -1 || styles.indexOf('org') !== -1) kind = 'title';
	else if (styles.indexOf('total') !== -1 || styles.indexOf('total_label') !== -1) kind = 'total';
	else if (styles.indexOf('label') !== -1) kind = 'label';
	if (r !== null && typeof r === 'object' && !Array.isArray(r) && typeof r.kind === 'string' && r.kind) kind = r.kind;
	return {cells: cells, kind: kind, styles: styles};
}

function renderSimpleTable(headers, rows){
	grid.innerHTML = '';
	var thead = document.createElement('thead');
	var tr = document.createElement('tr');
	(headers || []).forEach(function(h){
		var th = document.createElement('th');
		th.textContent = cellText(h);
		tr.appendChild(th);
	});
	thead.appendChild(tr);
	grid.appendChild(thead);
	var tbody = document.createElement('tbody');
	(rows || []).forEach(function(r){
		var norm = normalizeRow(r);
		var row = document.createElement('tr');
		norm.cells.forEach(function(c){
			var td = document.createElement('td');
			td.textContent = cellText(c);
			td.title = cellText(c);
			row.appendChild(td);
		});
		tbody.appendChild(row);
	});
	grid.appendChild(tbody);
	gridWrap.hidden = false;
}

function renderSheetRows(rows){
	grid.innerHTML = '';
	var headBuilt = false;
	var thead = document.createElement('thead');
	var tbody = document.createElement('tbody');
	(rows || []).forEach(function(r){
		var norm = normalizeRow(r);
		var cells = norm.cells;
		var kind = norm.kind || 'data';
		if (kind === 'header' && !headBuilt) {
			var htr = document.createElement('tr');
			cells.forEach(function(c){
				var th = document.createElement('th');
				th.textContent = cellText(c);
				htr.appendChild(th);
			});
			thead.appendChild(htr);
			headBuilt = true;
			return;
		}
		var tr = document.createElement('tr');
		if (kind) tr.className = 'exp-row-' + kind;
		cells.forEach(function(c){
			var td = document.createElement('td');
			td.textContent = cellText(c);
			td.title = cellText(c);
			tr.appendChild(td);
		});
		tbody.appendChild(tr);
	});
	if (thead.children.length) grid.appendChild(thead);
	grid.appendChild(tbody);
	gridWrap.hidden = false;
}

// Any element carrying data-exp-preview opens the modal (export dropdown
// items everywhere, plus standalone "View template" buttons), so pages
// can reuse the viewer without adopting .export-dd-item's styling.
document.addEventListener('click', function(e){
	var item = e.target && e.target.closest ? e.target.closest('[data-exp-preview]') : null;
	if (!item) return;
	if (!els()) return;
	e.preventDefault();
	var dd = item.closest('.export-dd');
	if (dd) dd.removeAttribute('open');
	var format = item.getAttribute('data-exp-format') || 'xlsx';
	var previewUrl = item.getAttribute('data-exp-preview') || item.getAttribute('href');
	var downloadUrl = item.getAttribute('data-exp-download') || item.getAttribute('href');
	var label = item.querySelector('.export-dd-label');
	openModal({
		format: format,
		previewUrl: previewUrl,
		downloadUrl: downloadUrl,
		jsonUrl: format === 'pdf' ? previewUrl.replace('preview=inline', 'preview=json') : '',
		title: label ? label.textContent.trim() : 'File preview'
	});
});
document.addEventListener('keydown', function(e){
	if (e.key === 'Escape' && overlay && !overlay.hidden) closeModal();
});
})();
</script>
HTML;
	}
}

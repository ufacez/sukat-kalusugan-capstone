<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/who_calculator.php';
require_once __DIR__ . '/../includes/who_reference_import.php';

$user = nutritionist_require_access();

$indicators = [
	'waz'      => ['label' => 'Weight-for-Age (WFA)',        'short' => 'WFA', 'table' => 'who_weight_for_age',        'unit' => 'kg', 'column' => 'age_months', 'columnLabel' => 'Age (months)', 'aboutTitle' => 'About Weight-for-Age (WFA)',        'aboutDesc' => 'Assesses whether a child\'s weight is appropriate for their age. Used to identify underweight.', 'measureType' => ''],
	'waz-days' => ['label' => 'Weight-for-Age (WFA)',        'short' => 'WFA', 'table' => 'who_weight_for_age_days',   'unit' => 'kg', 'column' => 'age_days',   'columnLabel' => 'Age (days)',   'aboutTitle' => 'About Weight-for-Age (WFA)',        'aboutDesc' => 'Assesses whether a child\'s weight is appropriate for their age. Used to identify underweight.', 'measureType' => ''],
	'haz'      => ['label' => 'Height/Length-for-Age (HFA)', 'short' => 'HFA', 'table' => 'who_height_for_age',        'unit' => 'cm', 'column' => 'age_months', 'columnLabel' => 'Age (months)', 'aboutTitle' => 'About Height/Length-for-Age (HFA)', 'aboutDesc' => 'Assesses whether a child\'s height or length is appropriate for their age. Used to identify stunting.', 'measureType' => ''],
	'haz-days' => ['label' => 'Height/Length-for-Age (HFA)', 'short' => 'HFA', 'table' => 'who_height_for_age_days',   'unit' => 'cm', 'column' => 'age_days',   'columnLabel' => 'Age (days)',   'aboutTitle' => 'About Height/Length-for-Age (HFA)', 'aboutDesc' => 'Assesses whether a child\'s height or length is appropriate for their age. Used to identify stunting.', 'measureType' => ''],
	'whz'      => ['label' => 'Weight-for-Height (WFH)',     'short' => 'WFH', 'table' => 'who_weight_for_height',    'unit' => 'kg', 'column' => 'height_cm',  'columnLabel' => 'Height (cm)',  'aboutTitle' => 'About Weight-for-Height (WFH)',     'aboutDesc' => 'Assesses whether a child\'s weight is appropriate for their standing height. Used to identify wasting and overweight.', 'measureType' => 'Standing', 'heightLabel' => 'Height (cm)'],
	'wfl'      => ['label' => 'Weight-for-Length (WFL)',      'short' => 'WFL', 'table' => 'who_weight_for_length',    'unit' => 'kg', 'column' => 'height_cm',  'columnLabel' => 'Length (cm)',  'aboutTitle' => 'About Weight-for-Length (WFL)',      'aboutDesc' => 'Assesses whether a child\'s weight is appropriate for their recumbent length. Used to identify wasting and overweight.', 'measureType' => 'Recumbent', 'heightLabel' => 'Length (cm)'],
];

$tabMap = [
	'wfa'  => ['label' => 'Weight-for-Age (WFA)',            'keys' => ['waz', 'waz-days']],
	'hfa'  => ['label' => 'Height-for-Age (HFA)',            'keys' => ['haz', 'haz-days']],
	'wfl'  => ['label' => 'Weight-for-Length (WFL)',          'keys' => ['wfl', 'wfl']],
	'wfh'  => ['label' => 'Weight-for-Height (WFH)',         'keys' => ['whz', 'whz']],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'import') {
	nutritionist_require_write();
	$importIndicator = strtolower((string)($_POST['indicator'] ?? 'waz'));
	$importSex = ($_POST['sex'] ?? 'Male') === 'Female' ? 'Female' : 'Male';
	if (!isset($indicators[$importIndicator])) $importIndicator = 'waz';
	$uploadedFile = $_FILES['who_file'] ?? ['error' => UPLOAD_ERR_NO_FILE];
	$validationError = who_reference_validate_xlsx_upload($uploadedFile);
	if ($validationError !== null) {
		admin_redirect('/nutritionist/who_reference.php', ['indicator' => $importIndicator, 'sex' => $importSex, 'notice' => $validationError, 'type' => 'error']);
	}
	$result = who_reference_import_file((string)$uploadedFile['tmp_name'], $indicators[$importIndicator], $importSex);
	if (!$result['ok']) {
		admin_redirect('/nutritionist/who_reference.php', ['indicator' => $importIndicator, 'sex' => $importSex, 'notice' => $result['message'], 'type' => 'error']);
	}
	$summary = sprintf('%s: %d added, %d updated%s.', $indicators[$importIndicator]['label'], $result['inserted'] ?? 0, $result['updated'] ?? 0, ($result['skipped'] ?? 0) > 0 ? ', ' . $result['skipped'] . ' rows skipped' : '');
	admin_redirect('/nutritionist/who_reference.php', ['indicator' => $importIndicator, 'sex' => $importSex, 'notice' => $summary]);
}

$indicator = strtolower((string)($_GET['indicator'] ?? 'waz'));
if (!isset($indicators[$indicator])) $indicator = 'waz';
$sex = ($_GET['sex'] ?? 'Male') === 'Female' ? 'Female' : 'Male';
$search = trim((string)($_GET['q'] ?? ''));

$activeTab = 'wfa';
foreach ($tabMap as $tabKey => $tabDef) {
	if (in_array($indicator, $tabDef['keys'], true)) { $activeTab = $tabKey; break; }
}

$isDayView = $indicators[$indicator]['column'] === 'age_days';
$ageRange = (string)($_GET['range'] ?? 'all');
if (!in_array($ageRange, ['young', 'old', 'all'], true)) $ageRange = 'all';

$rangeBounds = [
	'young' => ['age_months' => [0, 23], 'age_days' => [0, 729]],
	'old'   => ['age_months' => [24, 60], 'age_days' => [730, 1856]],
	'all'   => null,
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
$rowCount = count($rows);
$minX = $rowCount > 0 ? $rows[0]['x'] : null;
$maxX = $rowCount > 0 ? $rows[$rowCount - 1]['x'] : null;

// Pagination
$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($rowCount / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;
$pageRows = array_slice($rows, $offset, $perPage);

function who_reference_sd(float $L, float $M, float $S, int $z): float {
	if (abs($L) < 0.000001) return $M * exp($S * $z);
	return $M * (1 + $L * $S * $z) ** (1 / $L);
}

function who_reference_format_l(float $L): string { return number_format($L, 4, '.', ''); }
function who_reference_format_ms(float $val, bool $isS): string { return number_format($val, $isS ? 5 : 4, '.', ''); }

function who_reference_url(string $indicator, string $sex, string $range, string $search = '', int $page = 1): string {
	$params = ['indicator' => $indicator, 'sex' => $sex, 'range' => $range, 'page' => $page];
	if ($search !== '') $params['q'] = $search;
	return app_url('/nutritionist/who_reference.php') . '?' . http_build_query($params);
}

function who_reference_age_range_label(string $range, string $column): string {
	if ($column === 'age_days') return match ($range) { 'young' => '0–5 years (0–1856 days)', 'old' => '2–5 years (730–1856 days)', default => 'All (0–1856 days)' };
	return match ($range) { 'young' => '0–2 years', 'old' => '2–5 years', default => 'All (0–60 months)' };
}

function who_reference_height_range_label(string $indicator): string {
	return match ($indicator) { 'wfl' => '45 – 110 cm', 'whz' => '65 – 120 cm', default => '' };
}

$exportUrl = app_url('/nutritionist/who_reference_export.php') . '?' . http_build_query(['indicator' => $indicator, 'sex' => $sex, 'range' => $ageRange]);
$actions = '<a class="admin-btn-secondary" href="' . nutritionist_e($exportUrl) . '">' . admin_action_icon('export') . ' Export</a>';

nutritionist_layout_start('WHO Reference', 'WHO Child Growth Standards (0–5 years) • Used for Z-score calculation and nutritional assessment', 'who_reference', $actions);
?>

<div class="who-ref-layout">
	<?php if (nutritionist_can_write()): ?>
	<div class="who-ref-import-section">
		<form class="who-ref-import-form" action="<?php echo nutritionist_e(app_url('/nutritionist/who_reference.php')); ?>" method="post" enctype="multipart/form-data">
			<input type="hidden" name="action" value="import">
			<input type="hidden" name="indicator" value="<?php echo nutritionist_e($indicator); ?>">
			<input type="hidden" name="sex" value="<?php echo nutritionist_e($sex); ?>">
			<div class="who-ref-import-title">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
				<span>Import reference data</span>
			</div>
			<div class="who-ref-import-body">
				<div class="who-ref-import-meta">
					<strong><?php echo nutritionist_e($config['label']); ?></strong> · <span><?php echo nutritionist_e($sex === 'Male' ? 'Boys' : 'Girls'); ?></span>
				</div>
				<div class="who-ref-import-actions">
					<label class="admin-btn-secondary" style="cursor:pointer;margin:0;white-space:nowrap;">
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m6.75 12-3-3m0 0-3 3m3-3v6m-1.5-15H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
						Choose .xlsx
						<input type="file" name="who_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required style="display:none;">
					</label>
					<button type="submit" class="admin-btn" style="white-space:nowrap;">
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/></svg>
						Import
					</button>
				</div>
			</div>
		</form>
	</div>
	<?php endif; ?>

	<?php if ($rowCount > 0): ?>
	<!-- Tabs -->
	<div class="who-ref-tabs-row">
		<?php foreach ($tabMap as $tabKey => $tabDef): ?>
			<?php
				$tabIndicator = $tabDef['keys'][0];
				if ($isDayView && count($tabDef['keys']) > 1) {
					$tabIndicator = $tabDef['keys'][1];
				}
			?>
			<a href="<?php echo nutritionist_e(who_reference_url($tabIndicator, $sex, $ageRange, $search)); ?>"
			   class="who-ref-main-tab <?php echo $tabKey === $activeTab ? 'is-active' : ''; ?>">
				<?php echo nutritionist_e($tabDef['label']); ?>
			</a>
		<?php endforeach; ?>
	</div>

	<!-- Unified info card: About + Classification -->
	<div class="who-ref-unified-card">
		<div class="who-ref-unified-left">
			<div class="who-ref-about-icon">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="22" height="22">
					<path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25"/>
				</svg>
			</div>
			<div class="who-ref-about-content">
				<h3 class="who-ref-about-title"><?php echo nutritionist_e($config['aboutTitle']); ?></h3>
				<p class="who-ref-about-desc"><?php echo nutritionist_e($config['aboutDesc']); ?></p>
				<div class="who-ref-about-meta">
					<div class="who-ref-about-meta-item">
						<span class="who-ref-about-meta-label">Population</span>
						<span class="who-ref-about-meta-value">0 – 60 months · Boys & Girls</span>
					</div>
					<div class="who-ref-about-meta-item">
						<span class="who-ref-about-meta-label">Reference</span>
						<span class="who-ref-about-meta-value">WHO Child Growth Standards, Methods and Development (2006)</span>
					</div>
				</div>
			</div>
		</div>
		<div class="who-ref-unified-divider"></div>
		<div class="who-ref-unified-right">
			<h3 class="who-ref-class-title">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/></svg>
				Z-Score Classification
			</h3>
			<div class="who-ref-class-grid">
				<div class="who-ref-class-col">
					<div class="who-ref-class-col-header who-ref-class-wfa">WEIGHT-FOR-AGE (WFA)</div>
					<div class="who-ref-class-item"><span class="who-ref-dot who-ref-dot-red"></span><strong>SUW</strong> <span class="who-ref-class-range">Z ≤ −3</span></div>
					<div class="who-ref-class-item"><span class="who-ref-dot who-ref-dot-yellow"></span><strong>MUW</strong> <span class="who-ref-class-range">−3 ≤ Z &lt; −2</span></div>
					<div class="who-ref-class-item"><span class="who-ref-dot who-ref-dot-green"></span><strong>Normal</strong> <span class="who-ref-class-range">−2 &lt; Z &lt; +2</span></div>
				</div>
				<div class="who-ref-class-col">
					<div class="who-ref-class-col-header who-ref-class-hfa">HEIGHT-FOR-AGE (HFA)</div>
					<div class="who-ref-class-item"><span class="who-ref-dot who-ref-dot-red"></span><strong>SSt</strong> <span class="who-ref-class-range">Z &lt; −3</span></div>
					<div class="who-ref-class-item"><span class="who-ref-dot who-ref-dot-yellow"></span><strong>MSt</strong> <span class="who-ref-class-range">−3 ≤ Z ≤ −2</span></div>
					<div class="who-ref-class-item"><span class="who-ref-dot who-ref-dot-green"></span><strong>Normal</strong> <span class="who-ref-class-range">−2 &lt; Z &lt; +2</span></div>
					<div class="who-ref-class-item"><span class="who-ref-dot who-ref-dot-green"></span><strong>Tall</strong> <span class="who-ref-class-range">Z &gt; +2</span></div>
				</div>
				<div class="who-ref-class-col">
					<div class="who-ref-class-col-header who-ref-class-wflh">WEIGHT-FOR-LENGTH/HEIGHT (WFL/H)</div>
					<div class="who-ref-class-item"><span class="who-ref-dot who-ref-dot-red"></span><strong>SW</strong> <span class="who-ref-class-range">Z &lt; −3</span></div>
					<div class="who-ref-class-item"><span class="who-ref-dot who-ref-dot-yellow"></span><strong>MW</strong> <span class="who-ref-class-range">−3 ≤ Z &lt; −2</span></div>
					<div class="who-ref-class-item"><span class="who-ref-dot who-ref-dot-green"></span><strong>Normal</strong> <span class="who-ref-class-range">−2 &lt; Z &lt; +2</span></div>
					<div class="who-ref-class-item"><span class="who-ref-dot who-ref-dot-orange"></span><strong>OW</strong> <span class="who-ref-class-range">+2 ≤ Z &lt; +3</span></div>
					<div class="who-ref-class-item"><span class="who-ref-dot who-ref-dot-orange"></span><strong>Ob</strong> <span class="who-ref-class-range">Z ≥ +3</span></div>
				</div>
			</div>
			<div class="who-ref-class-note">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="14" height="14"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/></svg>
				Note: Classification is based on WHO Child Growth Standards, Methods and Development (2006).
			</div>
		</div>
	</div>

	<!-- Main content: table + sidebar -->
	<div class="who-ref-main-grid">
		<div class="who-ref-table-section">
			<div class="who-ref-table-card">
				<div class="who-ref-table-header">
					<h3 class="who-ref-table-title">WHO Reference Table (<?php echo nutritionist_e($config['label']); ?>)</h3>
					<div class="who-ref-filters">
						<div class="who-ref-filter-group">
							<label class="who-ref-filter-label">Sex</label>
							<select class="who-ref-select" onchange="window.location.href=this.value">
								<option value="<?php echo nutritionist_e(who_reference_url($indicator, 'Male', $ageRange, $search)); ?>" <?php echo $sex === 'Male' ? 'selected' : ''; ?>>Boys</option>
								<option value="<?php echo nutritionist_e(who_reference_url($indicator, 'Female', $ageRange, $search)); ?>" <?php echo $sex === 'Female' ? 'selected' : ''; ?>>Girls</option>
							</select>
						</div>

						<?php if (in_array($config['column'], ['age_months', 'age_days'], true)): ?>
						<div class="who-ref-filter-group">
							<label class="who-ref-filter-label">Unit</label>
							<select class="who-ref-select" disabled>
								<option><?php echo nutritionist_e($config['unit']); ?></option>
							</select>
						</div>

						<div class="who-ref-filter-group">
							<label class="who-ref-filter-label">Age (Completed)</label>
							<div class="who-ref-toggle-group">
								<a href="<?php echo nutritionist_e(who_reference_url($indicator === 'waz-days' ? 'waz' : 'haz', $sex, $ageRange, $search)); ?>"
								   class="who-ref-toggle <?php echo !$isDayView ? 'is-active' : ''; ?>">Months</a>
								<a href="<?php echo nutritionist_e(who_reference_url($indicator === 'waz' ? 'waz-days' : ($indicator === 'haz' ? 'haz-days' : $indicator), $sex, $ageRange, $search)); ?>"
								   class="who-ref-toggle <?php echo $isDayView ? 'is-active' : ''; ?>">Days</a>
							</div>
						</div>
						<?php endif; ?>
					</div>
				</div>

				<div class="who-ref-table-wrap">
					<table class="who-ref-table" id="who-reference-table">
						<thead>
							<tr>
								<?php if ($isDayView): ?>
								<th class="who-ref-th-age">Age<br>(Days)</th>
								<th class="who-ref-th-age">Age<br>(Months)</th>
								<?php elseif ($config['column'] === 'height_cm'): ?>
								<th class="who-ref-th-age"><?php echo nutritionist_e($config['heightLabel'] ?? 'Height/Length (cm)'); ?></th>
								<?php else: ?>
								<th class="who-ref-th-age">Age<br>(Months)</th>
								<?php endif; ?>
								<th class="who-ref-th-lms">L<br><span class="who-ref-th-sub">(Box-Cox)</span></th>
								<th class="who-ref-th-lms">M<br><span class="who-ref-th-sub">(Median)</span></th>
								<th class="who-ref-th-lms">S<br><span class="who-ref-th-sub">(Coefficient of Variation)</span></th>
								<th class="who-ref-th-sd">−3 SD</th>
								<th class="who-ref-th-sd">−2 SD</th>
								<th class="who-ref-th-sd">−1 SD</th>
								<th class="who-ref-th-sd who-ref-th-median">Median</th>
								<th class="who-ref-th-sd">+1 SD</th>
								<th class="who-ref-th-sd">+2 SD</th>
								<th class="who-ref-th-sd">+3 SD</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($pageRows as $row):
								$rL = (float)$row['L'];
								$rM = (float)$row['M'];
								$rS = (float)$row['S'];
								$rx = (float)$row['x'];
							?>
								<tr data-filter-text="<?php echo nutritionist_e($config['column'] === 'height_cm' ? number_format($rx, 1, '.', '') : (string)(int)$rx); ?>">
									<?php if ($isDayView): ?>
									<td class="who-ref-td-age"><?php echo (int)$rx; ?></td>
									<td class="who-ref-td-age-sub"><?php echo nutritionist_e((string)intdiv((int)$rx, 30) . 'm ' . ((int)$rx % 30) . 'd'); ?></td>
									<?php elseif ($config['column'] === 'height_cm'): ?>
									<td class="who-ref-td-age"><?php echo number_format($rx, 1, '.', ''); ?></td>
									<?php else: ?>
									<td class="who-ref-td-age"><?php echo (int)$rx; ?></td>
									<?php endif; ?>
									<td class="who-ref-td-lms"><?php echo nutritionist_e(who_reference_format_l($rL)); ?></td>
									<td class="who-ref-td-lms"><?php echo nutritionist_e(who_reference_format_ms($rM, false)); ?></td>
									<td class="who-ref-td-lms"><?php echo nutritionist_e(who_reference_format_ms($rS, true)); ?></td>
									<td class="who-ref-td-sd sd-neg3"><?php echo number_format(who_reference_sd($rL, $rM, $rS, -3), 4, '.', ''); ?></td>
									<td class="who-ref-td-sd sd-neg2"><?php echo number_format(who_reference_sd($rL, $rM, $rS, -2), 4, '.', ''); ?></td>
									<td class="who-ref-td-sd sd-neg1"><?php echo number_format(who_reference_sd($rL, $rM, $rS, -1), 4, '.', ''); ?></td>
									<td class="who-ref-td-sd sd-med"><?php echo number_format($rM, 4, '.', ''); ?></td>
									<td class="who-ref-td-sd sd-pos1"><?php echo number_format(who_reference_sd($rL, $rM, $rS, 1), 4, '.', ''); ?></td>
									<td class="who-ref-td-sd sd-pos2"><?php echo number_format(who_reference_sd($rL, $rM, $rS, 2), 4, '.', ''); ?></td>
									<td class="who-ref-td-sd sd-pos3"><?php echo number_format(who_reference_sd($rL, $rM, $rS, 3), 4, '.', ''); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<!-- Pagination -->
				<div class="who-ref-pagination">
					<span class="who-ref-page-info">Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $perPage, $rowCount); ?> of <?php echo $rowCount; ?> rows</span>
					<div class="who-ref-page-btns">
						<?php if ($page > 1): ?>
							<a href="<?php echo nutritionist_e(who_reference_url($indicator, $sex, $ageRange, $search, 1)); ?>" class="who-ref-page-btn" title="First">&laquo;</a>
							<a href="<?php echo nutritionist_e(who_reference_url($indicator, $sex, $ageRange, $search, $page - 1)); ?>" class="who-ref-page-btn" title="Previous">&lsaquo;</a>
						<?php else: ?>
							<span class="who-ref-page-btn is-disabled">&laquo;</span>
							<span class="who-ref-page-btn is-disabled">&lsaquo;</span>
						<?php endif; ?>

						<?php
						$startPage = max(1, $page - 2);
						$endPage = min($totalPages, $page + 2);
						if ($startPage > 1): ?>
							<a href="<?php echo nutritionist_e(who_reference_url($indicator, $sex, $ageRange, $search, 1)); ?>" class="who-ref-page-btn">1</a>
							<?php if ($startPage > 2): ?><span class="who-ref-page-ellipsis">...</span><?php endif; ?>
						<?php endif; ?>
						<?php for ($p = $startPage; $p <= $endPage; $p++): ?>
							<a href="<?php echo nutritionist_e(who_reference_url($indicator, $sex, $ageRange, $search, $p)); ?>" class="who-ref-page-btn <?php echo $p === $page ? 'is-active' : ''; ?>"><?php echo $p; ?></a>
						<?php endfor; ?>
						<?php if ($endPage < $totalPages): ?>
							<?php if ($endPage < $totalPages - 1): ?><span class="who-ref-page-ellipsis">...</span><?php endif; ?>
							<a href="<?php echo nutritionist_e(who_reference_url($indicator, $sex, $ageRange, $search, $totalPages)); ?>" class="who-ref-page-btn"><?php echo $totalPages; ?></a>
						<?php endif; ?>

						<?php if ($page < $totalPages): ?>
							<a href="<?php echo nutritionist_e(who_reference_url($indicator, $sex, $ageRange, $search, $page + 1)); ?>" class="who-ref-page-btn" title="Next">&rsaquo;</a>
							<a href="<?php echo nutritionist_e(who_reference_url($indicator, $sex, $ageRange, $search, $totalPages)); ?>" class="who-ref-page-btn" title="Last">&raquo;</a>
						<?php else: ?>
							<span class="who-ref-page-btn is-disabled">&rsaquo;</span>
							<span class="who-ref-page-btn is-disabled">&raquo;</span>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>

		<!-- Sidebar -->
		<div class="who-ref-sidebar">
			<div class="who-ref-sidebar-card who-ref-sidebar-details">
				<h4 class="who-ref-sidebar-title">
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/></svg>
					Reference Details
				</h4>
				<div class="who-ref-detail-rows">
					<div class="who-ref-detail-row">
						<span class="who-ref-detail-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="14" height="14"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 3.75H6A2.25 2.25 0 0 0 3.75 6v1.5M16.5 3.75H18A2.25 2.25 0 0 1 20.25 6v1.5m0 9V18A2.25 2.25 0 0 1 18 20.25h-1.5m-9 0H6A2.25 2.25 0 0 1 3.75 18v-1.5M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg></span>
						<span class="who-ref-detail-label">Indicator</span>
						<span class="who-ref-detail-value"><?php echo nutritionist_e($config['label']); ?></span>
					</div>
					<div class="who-ref-detail-row">
						<span class="who-ref-detail-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="14" height="14"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg></span>
						<span class="who-ref-detail-label"><?php echo $config['column'] === 'height_cm' ? 'Height Range' : 'Age Range'; ?></span>
						<span class="who-ref-detail-value"><?php echo $config['column'] === 'height_cm' ? nutritionist_e(who_reference_height_range_label($indicator)) : nutritionist_e(who_reference_age_range_label($ageRange, $config['column'])); ?></span>
					</div>
					<div class="who-ref-detail-row">
						<span class="who-ref-detail-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="14" height="14"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/></svg></span>
						<span class="who-ref-detail-label">Sex</span>
						<span class="who-ref-detail-value"><?php echo nutritionist_e($sex === 'Male' ? 'Boys' : 'Girls'); ?></span>
					</div>
					<?php if ($config['measureType'] !== ''): ?>
					<div class="who-ref-detail-row">
						<span class="who-ref-detail-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="14" height="14"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15"/></svg></span>
						<span class="who-ref-detail-label">Type</span>
						<span class="who-ref-detail-value"><?php echo nutritionist_e($config['measureType']); ?></span>
					</div>
					<?php endif; ?>
					<div class="who-ref-detail-row">
						<span class="who-ref-detail-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="14" height="14"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25"/></svg></span>
						<span class="who-ref-detail-label">Reference</span>
						<span class="who-ref-detail-value">WHO Child Growth Standards, Methods and Development (2006)</span>
					</div>
				</div>
			</div>

			<div class="who-ref-sidebar-card who-ref-sidebar-formula">
				<h4 class="who-ref-sidebar-title">
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 0 0-2.455 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z"/></svg>
					How it is used
				</h4>
				<p class="who-ref-how-text">The L, M, and S values are used in the LMS method to compute the Z-score:</p>
				<div class="who-ref-formula-box">
					<span class="who-ref-formula-eq">Z = ((X/M)<sup>L</sup> − 1) / (L × S)</span>
				</div>
				<div class="who-ref-formula-legend">
					<span><strong>X</strong> = child's measurement</span>
					<span><strong>L</strong> = Box-Cox Power</span>
					<span><strong>M</strong> = Median</span>
					<span><strong>S</strong> = Coefficient of Variation</span>
				</div>
			</div>
		</div>
	</div>

	<div class="who-ref-source">
		Source: WHO Child Growth Standards, Methods and Development (2006)
	</div>

<?php else: ?>
	<div class="admin-flash is-error">
		<strong>No reference data available.</strong>
		<br>Upload WHO LMS reference data using the import form at the top.
	</div>
<?php endif; ?>
</div>

<?php nutritionist_layout_end(); ?>

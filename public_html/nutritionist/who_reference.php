<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/who_calculator.php';
require_once __DIR__ . '/../includes/who_reference_import.php';

$user = nutritionist_require_access();

$indicators = [
	'waz' => ['label' => 'Weight-for-Age', 'table' => 'who_weight_for_age', 'unit' => 'kg', 'column' => 'age_months', 'columnLabel' => 'Age (months)'],
	'haz' => ['label' => 'Height-for-Age', 'table' => 'who_height_for_age', 'unit' => 'cm', 'column' => 'age_months', 'columnLabel' => 'Age (months)'],
	'whz' => ['label' => 'Weight-for-Height', 'table' => 'who_weight_for_height', 'unit' => 'kg', 'column' => 'height_cm', 'columnLabel' => 'Height (cm)'],
];

// Import runs before anything is echoed, since it redirects back to this
// same page (POST -> Redirect -> GET) once it's done, same pattern used by
// every other nutritionist/admin form in this app.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'import') {
	$importIndicator = strtolower((string)($_POST['indicator'] ?? 'waz'));
	$importSex = ($_POST['sex'] ?? 'Male') === 'Female' ? 'Female' : 'Male';

	if (!isset($indicators[$importIndicator])) {
		$importIndicator = 'waz';
	}

	$uploadedFile = $_FILES['who_file'] ?? ['error' => UPLOAD_ERR_NO_FILE];
	$validationError = who_reference_validate_xlsx_upload($uploadedFile);

	if ($validationError !== null) {
		admin_redirect('/nutritionist/who_reference.php', [
			'indicator' => $importIndicator,
			'sex' => $importSex,
			'notice' => $validationError,
			'type' => 'error',
		]);
	}

	$result = who_reference_import_file((string)$uploadedFile['tmp_name'], $indicators[$importIndicator], $importSex);

	if (!$result['ok']) {
		admin_redirect('/nutritionist/who_reference.php', [
			'indicator' => $importIndicator,
			'sex' => $importSex,
			'notice' => $result['message'],
			'type' => 'error',
		]);
	}

	$summary = sprintf(
		'%s: %d added, %d updated%s.',
		$indicators[$importIndicator]['label'],
		$result['inserted'] ?? 0,
		$result['updated'] ?? 0,
		($result['skipped'] ?? 0) > 0 ? ', ' . $result['skipped'] . ' rows skipped (out of range or unreadable)' : ''
	);

	admin_redirect('/nutritionist/who_reference.php', [
		'indicator' => $importIndicator,
		'sex' => $importSex,
		'notice' => $summary,
	]);
}

$indicator = strtolower((string)($_GET['indicator'] ?? 'waz'));

if (!isset($indicators[$indicator])) {
	$indicator = 'waz';
}

$sex = ($_GET['sex'] ?? 'Male') === 'Female' ? 'Female' : 'Male';
$search = trim((string)($_GET['q'] ?? ''));

// Program scope is 2-5 year olds (24-60 months), so that's the default view.
// "young" = 0-24mo (not our scope, kept for completeness), "old" = 24-60mo (our scope), "all" = everything.
$ageRange = (string)($_GET['range'] ?? 'old');

if (!in_array($ageRange, ['young', 'old', 'all'], true)) {
	$ageRange = 'old';
}

// who_weight_for_height is keyed by height_cm, not age, but it was seeded from two
// separate WHO source tables: 45.0-64.5cm came from the birth-2y (recumbent length)
// table, and 65.0-120.0cm came from the 2-5y (standing height) table. So filtering
// by height_cm >= 65 lines up with the 24-60mo / "our scope" range for this indicator too.
$rangeBounds = [
	'young' => ['age_months' => [0, 23], 'height_cm' => [0, 64.9]],
	'old' => ['age_months' => [24, 60], 'height_cm' => [65, 999]],
	'all' => null,
];

$config = $indicators[$indicator];
$sql = "SELECT {$config['column']} AS x, L, M, S FROM {$config['table']} WHERE sex = ?";
$types = 's';
$params = [$sex];

if ($rangeBounds[$ageRange] !== null) {
	[$low, $high] = $rangeBounds[$ageRange][$config['column']];
	$sql .= " AND {$config['column']} BETWEEN ? AND ?";
	$types .= 'dd';
	$params[] = $low;
	$params[] = $high;
}

$sql .= " ORDER BY {$config['column']} ASC";

$rows = admin_fetch_all($sql, $types, $params);

$rowCount = count($rows);
$minX = $rowCount > 0 ? $rows[0]['x'] : null;
$maxX = $rowCount > 0 ? $rows[$rowCount - 1]['x'] : null;

function who_reference_sd(float $L, float $M, float $S, int $z): float
{
	if (abs($L) < 0.000001) {
		return $M * exp($S * $z);
	}

	return $M * (1 + $L * $S * $z) ** (1 / $L);
}

function who_reference_url(string $indicator, string $sex, string $range, string $search = ''): string
{
	$params = ['indicator' => $indicator, 'sex' => $sex, 'range' => $range];

	if ($search !== '') {
		$params['q'] = $search;
	}

	return app_url('/nutritionist/who_reference.php') . '?' . http_build_query($params);
}

$exportUrl = app_url('/nutritionist/who_reference_export.php') . '?' . http_build_query(['indicator' => $indicator, 'sex' => $sex, 'range' => $ageRange]);

$actions = '<a class="admin-btn-secondary" href="' . nutritionist_e($exportUrl) . '">Export .xlsx</a>'
	. ' <a class="admin-btn-secondary" href="' . nutritionist_e(app_url('/nutritionist/who_analysis.php')) . '">Back to WHO Analysis</a>';

nutritionist_layout_start('WHO Reference Tables', 'Official WHO Child Growth Standards LMS values used for every z-score calculation.', 'who_reference', $actions);
?>
<section class="nutritionist-panel" style="margin-bottom:20px;">
	<div class="nutritionist-table-head" style="margin-bottom:10px;flex-wrap:wrap;gap:10px;">
		<div style="display:flex;gap:8px;flex-wrap:wrap;">
			<?php foreach ($indicators as $key => $def): ?>
				<a
					href="<?php echo nutritionist_e(who_reference_url($key, $sex, $ageRange, $search)); ?>"
					class="admin-btn-secondary"
					style="<?php echo $key === $indicator ? 'background:var(--admin-primary);color:#fff;border-color:var(--admin-primary);' : ''; ?>"
				><?php echo nutritionist_e($def['label']); ?></a>
			<?php endforeach; ?>
		</div>
		<div style="display:flex;gap:8px;">
			<?php foreach (['Male', 'Female'] as $sexOption): ?>
				<a
					href="<?php echo nutritionist_e(who_reference_url($indicator, $sexOption, $ageRange, $search)); ?>"
					class="admin-btn-secondary"
					style="<?php echo $sexOption === $sex ? 'background:var(--admin-primary);color:#fff;border-color:var(--admin-primary);' : ''; ?>"
				><?php echo nutritionist_e($sexOption); ?></a>
			<?php endforeach; ?>
		</div>
	</div>

	<div class="nutritionist-table-head" style="margin-bottom:14px;flex-wrap:wrap;gap:10px;">
		<div style="display:flex;gap:8px;flex-wrap:wrap;">
			<?php foreach ([
				'old' => '2-5 years (24-60mo) - our scope',
				'young' => '0-2 years (0-23mo)',
				'all' => 'All (0-60mo)',
			] as $rangeKey => $rangeLabel): ?>
				<a
					href="<?php echo nutritionist_e(who_reference_url($indicator, $sex, $rangeKey, $search)); ?>"
					class="admin-pill <?php echo $rangeKey === $ageRange ? 'is-success' : 'is-muted'; ?>"
					style="text-decoration:none;"
				><?php echo nutritionist_e($rangeLabel); ?></a>
			<?php endforeach; ?>
		</div>
	</div>

	<?php if ($rowCount === 0): ?>
		<div class="admin-flash is-error">
			No reference rows found for <?php echo nutritionist_e($config['label']); ?> (<?php echo nutritionist_e($sex); ?>).
			The <code><?php echo nutritionist_e($config['table']); ?></code> table looks empty — run
			<code>db/20260817_who_growth_reference_seed.sql</code> against the database.
		</div>
	<?php else: ?>
		<div class="admin-mini" style="margin-bottom:12px;">
			<?php echo nutritionist_e($config['label']); ?> · <?php echo nutritionist_e($sex); ?>
			· <?php echo (int)$rowCount; ?> reference points
			· range <?php echo nutritionist_e((string)$minX); ?>&ndash;<?php echo nutritionist_e((string)$maxX); ?> <?php echo nutritionist_e($config['columnLabel']); ?>
			· Source: WHO Child Growth Standards (2006)
		</div>
	<?php endif; ?>
</section>

<section class="nutritionist-panel" style="margin-bottom:20px;">
	<h2 class="admin-section-title" style="margin-bottom:2px;">How the SD columns are calculated</h2>
	<p class="admin-section-subtitle" style="margin-bottom:12px;">
		Every row stores three WHO LMS parameters — <strong>L</strong> (Box-Cox power, corrects skewness),
		<strong>M</strong> (median), and <strong>S</strong> (coefficient of variation). The &minus;3SD…+3SD
		columns and every child's z-score are both derived from the same formula, just solved in opposite
		directions.
	</p>
	<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:14px;">
		<div class="admin-mini" style="background:var(--admin-surface-alt, #f8f9fb);padding:12px 14px;border-radius:8px;">
			<div style="font-weight:700;color:var(--admin-text);margin-bottom:6px;">SD band value at a given z (used to draw this table)</div>
			<code style="display:block;white-space:pre-wrap;">X = M &times; (1 + L&middot;S&middot;z)^(1/L)&nbsp;&nbsp;&nbsp;if L &ne; 0
X = M &times; e^(S&middot;z)&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;if L = 0</code>
			<div style="margin-top:6px;color:var(--admin-muted);">Where z is &minus;3, &minus;2, &minus;1, 0 (median), +1, +2, +3.</div>
		</div>
		<div class="admin-mini" style="background:var(--admin-surface-alt, #f8f9fb);padding:12px 14px;border-radius:8px;">
			<div style="font-weight:700;color:var(--admin-text);margin-bottom:6px;">Child's z-score at a given measurement (used by WHO Analysis)</div>
			<code style="display:block;white-space:pre-wrap;">z = [ (X / M)^L &minus; 1 ] / (L&middot;S)&nbsp;&nbsp;if L &ne; 0
z = ln(X / M) / S&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;if L = 0</code>
			<div style="margin-top:6px;color:var(--admin-muted);">Where X is the child's actual weight/height/length.</div>
		</div>
	</div>
	<p class="admin-mini" style="margin-top:10px;color:var(--admin-muted);">
		This is the WHO Child Growth Standards (2006) LMS method — see <code>who_reference_sd()</code> in this
		file for the SD-band version, and <code>who_lms_z_score()</code> in <code>includes/who_calculator.php</code>
		for the z-score version used when analyzing a child.
	</p>
</section>

<section class="nutritionist-panel" style="margin-bottom:20px;">
	<h2 class="admin-section-title" style="margin-bottom:2px;">Import from official WHO LMS 2006 .xlsx</h2>
	<p class="admin-section-subtitle" style="margin-bottom:12px;">
		Upload a WHO Child Growth Standards LMS workbook (.xlsx only) to add or replace rows for the indicator
		and sex selected above. Works with WHO's daily "expanded" tables (a <code>Day</code> column — these get
		interpolated onto the 0–60 month grid this app uses, the same way WHO derives its own monthly tables
		from the daily ones) or a table that already has one row per month, or one row per length/height value.
	</p>
	<form action="<?php echo nutritionist_e(app_url('/nutritionist/who_reference.php')); ?>" method="post" enctype="multipart/form-data" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
		<input type="hidden" name="action" value="import">
		<input type="hidden" name="indicator" value="<?php echo nutritionist_e($indicator); ?>">
		<input type="hidden" name="sex" value="<?php echo nutritionist_e($sex); ?>">
		<span class="admin-mini">Importing into: <strong><?php echo nutritionist_e($config['label']); ?> &middot; <?php echo nutritionist_e($sex); ?></strong> (change indicator/sex above first if needed)</span>
		<input type="file" name="who_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
		<button type="submit" class="admin-btn-primary">Import .xlsx</button>
	</form>
</section>

<?php if ($rowCount > 0): ?>
<section class="nutritionist-panel">
	<div class="nutritionist-table-head" style="margin-bottom:12px;">
		<div>
			<h2 class="admin-section-title" style="margin-bottom:2px;"><?php echo nutritionist_e($config['label']); ?> Reference (<?php echo nutritionist_e($sex); ?>)</h2>
			<p class="admin-section-subtitle">L, M, S values and the derived SD bands used to classify a child's z-score.</p>
		</div>
		<input class="admin-search" data-admin-filter="#who-reference-table" type="search" placeholder="Search <?php echo nutritionist_e($config['columnLabel']); ?>" style="min-width:220px;">
	</div>

	<div class="nutritionist-table-wrap">
		<table class="nutritionist-table" id="who-reference-table">
			<thead>
				<tr>
					<th><?php echo nutritionist_e($config['columnLabel']); ?></th>
					<th>L</th>
					<th>M</th>
					<th>S</th>
					<th>-3 SD</th>
					<th>-2 SD</th>
					<th>-1 SD</th>
					<th>Median</th>
					<th>+1 SD</th>
					<th>+2 SD</th>
					<th>+3 SD</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($rows as $row):
					$L = (float)$row['L'];
					$M = (float)$row['M'];
					$S = (float)$row['S'];
					$x = $row['x'];
				?>
					<tr data-filter-text="<?php echo nutritionist_e(strtolower((string)$x)); ?>">
						<td style="font-weight:600;color:var(--admin-text);"><?php echo nutritionist_e((string)$x); ?></td>
						<td style="color:var(--admin-muted);"><?php echo nutritionist_e(number_format($L, 4)); ?></td>
						<td style="color:var(--admin-muted);"><?php echo nutritionist_e(number_format($M, 4)); ?></td>
						<td style="color:var(--admin-muted);"><?php echo nutritionist_e(number_format($S, 5)); ?></td>
						<td style="color:#c0392b;"><?php echo nutritionist_e(number_format(who_reference_sd($L, $M, $S, -3), 2)); ?></td>
						<td style="color:#d35400;"><?php echo nutritionist_e(number_format(who_reference_sd($L, $M, $S, -2), 2)); ?></td>
						<td style="color:var(--admin-muted);"><?php echo nutritionist_e(number_format(who_reference_sd($L, $M, $S, -1), 2)); ?></td>
						<td style="font-weight:700;color:var(--admin-primary);"><?php echo nutritionist_e(number_format($M, 2)); ?> <?php echo nutritionist_e($config['unit']); ?></td>
						<td style="color:var(--admin-muted);"><?php echo nutritionist_e(number_format(who_reference_sd($L, $M, $S, 1), 2)); ?></td>
						<td style="color:#d35400;"><?php echo nutritionist_e(number_format(who_reference_sd($L, $M, $S, 2), 2)); ?></td>
						<td style="color:#c0392b;"><?php echo nutritionist_e(number_format(who_reference_sd($L, $M, $S, 3), 2)); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>
<?php endif; ?>
<?php
nutritionist_layout_end();
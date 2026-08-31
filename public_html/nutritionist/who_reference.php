<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/who_calculator.php';
require_once __DIR__ . '/../includes/who_reference_import.php';

$user = nutritionist_require_access();

$indicators = [
	'waz'      => ['label' => 'Weight-for-Age (months)',   'table' => 'who_weight_for_age',       'unit' => 'kg', 'column' => 'age_months', 'columnLabel' => 'Age (months)'],
	'waz-days' => ['label' => 'Weight-for-Age (days)',     'table' => 'who_weight_for_age_days',  'unit' => 'kg', 'column' => 'age_days',   'columnLabel' => 'Age (days)'],
	'haz'      => ['label' => 'Height-for-Age (months)',   'table' => 'who_height_for_age',       'unit' => 'cm', 'column' => 'age_months', 'columnLabel' => 'Age (months)'],
	'haz-days' => ['label' => 'Height-for-Age (days)',     'table' => 'who_height_for_age_days',  'unit' => 'cm', 'column' => 'age_days',   'columnLabel' => 'Age (days)'],
	'whz'      => ['label' => 'Weight-for-Height (2-5y)',  'table' => 'who_weight_for_height',    'unit' => 'kg', 'column' => 'height_cm',  'columnLabel' => 'Height (cm)'],
	'wfl'      => ['label' => 'Weight-for-Length (0-2y)',  'table' => 'who_weight_for_length',    'unit' => 'kg', 'column' => 'height_cm',  'columnLabel' => 'Length (cm)'],
];

// Import runs before anything is echoed, since it redirects back to this
// same page (POST -> Redirect -> GET) once it's done, same pattern used by
// every other nutritionist/admin form in this app.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'import') {

	nutritionist_require_write();

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

// For age-based indicators (WAZ, HAZ), "young" = 0-23mo, "old" = 24-60mo, "all" = 0-60mo.
// For height-based indicators (WHZ, WFL), each table already contains only its valid range
// (WHZ = standing height 65-120cm, WFL = recumbent length 45-110cm), so range filtering is skipped.
$ageRange = (string)($_GET['range'] ?? 'all');

if (!in_array($ageRange, ['young', 'old', 'all'], true)) {
	$ageRange = 'all';
}

// who_weight_for_height contains only the standing-height curve (65.0-120.0cm, 2-5y).
// who_weight_for_length contains only the recumbent-length curve (45.0-110.0cm, 0-2y).
// The legacy monthly age tables (who_weight_for_age, who_height_for_age)
// cover 0-60 months. The day-based tables (who_weight_for_age_days,
// who_height_for_age_days) cover 0-1856 days (~5 years + 30 days) and
// use the same young/old split translated into days.
$rangeBounds = [
	'young' => [
		'age_months' => [0, 23],
		'age_days'   => [0, 729],   // < 24 completed months
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

$actions = '<a class="admin-btn-secondary" href="' . nutritionist_e($exportUrl) . '">' . admin_action_icon('export') . ' Export</a>'
	. ' <a class="admin-btn-secondary" href="' . nutritionist_e(app_url('/nutritionist/who_analysis.php')) . '">' . admin_action_icon('back') . ' WHO Analysis</a>';

nutritionist_layout_start('WHO Reference Tables', 'Official WHO Child Growth Standards LMS values.', 'who_reference', $actions);
?>
<section class="nutritionist-panel" style="margin-bottom:20px;">
	<div class="nutritionist-table-head" style="margin-bottom:10px;flex-wrap:wrap;gap:10px;">
		<div style="display:flex;gap:6px;flex-wrap:wrap;">
			<?php foreach ($indicators as $key => $def): ?>
				<a
					href="<?php echo nutritionist_e(who_reference_url($key, $sex, $ageRange, $search)); ?>"
					class="admin-pill <?php echo $key === $indicator ? 'is-success' : 'is-muted'; ?>"
					style="text-decoration:none;"
				><?php echo nutritionist_e($def['label']); ?></a>
			<?php endforeach; ?>
		</div>
		<div style="display:flex;gap:6px;">
			<?php foreach (['Male', 'Female'] as $sexOption): ?>
				<a
					href="<?php echo nutritionist_e(who_reference_url($indicator, $sexOption, $ageRange, $search)); ?>"
					class="admin-pill <?php echo $sexOption === $sex ? 'is-success' : 'is-muted'; ?>"
					style="text-decoration:none;"
				><?php echo nutritionist_e($sexOption); ?></a>
			<?php endforeach; ?>
		</div>
	</div>

	<?php if (in_array($config['column'], ['age_months', 'age_days'], true)): ?>
	<div style="margin-bottom:14px;">
		<div style="display:flex;gap:6px;flex-wrap:wrap;">
			<?php foreach (
				$config['column'] === 'age_days'
					? [
						'all'   => 'All (0-1856d)',
						'old'   => '2-5 years (730-1856d)',
						'young' => '0-2 years (0-729d)',
					]
					: [
						'all'   => 'All (0-60mo)',
						'old'   => '2-5 years',
						'young' => '0-2 years',
					]
				as $rangeKey => $rangeLabel): ?>
				<a
					href="<?php echo nutritionist_e(who_reference_url($indicator, $sex, $rangeKey, $search)); ?>"
					class="admin-pill <?php echo $rangeKey === $ageRange ? 'is-success' : 'is-muted'; ?>"
					style="text-decoration:none;"
				><?php echo nutritionist_e($rangeLabel); ?></a>
			<?php endforeach; ?>
		</div>
	</div>
	<?php endif; ?>

	<?php if ($rowCount === 0): ?>
		<?php
			// The day-keyed tables (who_weight_for_age_days /
			// who_height_for_age_days) start empty until either
			//   1) the seeder in db/seeders/seed_who_age_days.php is
			//      run, or
			//   2) an xlsx is uploaded via this page.
			// Without one of those, the WAZ/HAZ calculator's
			// day-keyed lookup will fall back to the monthly table, so
			// the page is functional but the day-precision reference
			// isn't available. Surface that here so the operator
			// knows what to do.
			$isDayTable = $config['column'] === 'age_days';
		?>
		<div class="admin-flash is-error">
			<strong>No reference data for <?php echo nutritionist_e($config['label']); ?> (<?php echo nutritionist_e($sex); ?>).</strong>
			<?php if ($isDayTable): ?>
				<br>
				This is the day-keyed WHO 2006 expanded reference table (one row per day from 0 to 1856).
				Upload the matching <code><?php echo nutritionist_e($sex === 'Male' ? 'wfa-boys' : 'wfa-girls'); ?>-zscore-expanded-tables.xlsx</code>
				or <code><?php echo nutritionist_e($sex === 'Male' ? 'lhfa-boys' : 'lhfa-girls'); ?>-zscore-expanded-tables.xlsx</code>
				from <code>public_html/data/who lms 2006expanded/</code> using the Import form below,
				or run the one-shot seeder:
				<code>php db/seeders/seed_who_age_days.php</code>.
			<?php else: ?>
				<br>
				Upload the matching WHO LMS xlsx using the Import form below.
			<?php endif; ?>
		</div>
	<?php else: ?>
		<div class="admin-mini" style="margin-bottom:0;color:var(--admin-muted);">
			<?php echo nutritionist_e($config['label']); ?> &middot; <?php echo nutritionist_e($sex); ?>
			&middot; <?php echo (int)$rowCount; ?> rows
			&middot; <?php echo nutritionist_e((string)$minX); ?>&ndash;<?php echo nutritionist_e((string)$maxX); ?> <?php echo nutritionist_e($config['columnLabel']); ?>
		</div>
	<?php endif; ?>
</section>

<?php if (nutritionist_can_write()): ?>
<section class="nutritionist-panel" style="margin-bottom:20px;padding:16px;">
	<?php
		// Suggest the matching xlsx file from the bundled data folder.
		// The day-keyed WFA / HFA tables read the *-zscore-expanded-tables.xlsx
		// files which already ship with the repo under
		// public_html/data/who lms 2006expanded/.
		$expectedFiles = match ($indicator) {
			'waz-days' => $sex === 'Male' ? 'wfa-boys-zscore-expanded-tables.xlsx' : 'wfa-girls-zscore-expanded-tables.xlsx',
			'haz-days' => $sex === 'Male' ? 'lhfa-boys-zscore-expanded-tables.xlsx' : 'lhfa-girls-zscore-expanded-tables.xlsx',
			default    => null,
		};
	?>
	<div class="who-import-row" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
		<div class="who-import-label" style="display:flex;align-items:center;gap:8px;color:var(--admin-muted);font-size:0.85rem;">
			<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
			<span>Importing: <strong><?php echo nutritionist_e($config['label']); ?> &middot; <?php echo nutritionist_e($sex); ?></strong></span>
			<?php if ($expectedFiles !== null): ?>
				<span style="color:var(--admin-muted);font-size:0.78rem;">
					Expected file: <code style="background:var(--admin-surface-alt);padding:2px 6px;border-radius:4px;"><?php echo nutritionist_e($expectedFiles); ?></code>
				</span>
			<?php endif; ?>
		</div>
		<form class="who-import-actions" action="<?php echo nutritionist_e(app_url('/nutritionist/who_reference.php')); ?>" method="post" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex:1;min-width:0;">
			<input type="hidden" name="action" value="import">
			<input type="hidden" name="indicator" value="<?php echo nutritionist_e($indicator); ?>">
			<input type="hidden" name="sex" value="<?php echo nutritionist_e($sex); ?>">
			<label class="admin-btn-secondary" style="cursor:pointer;margin:0;white-space:nowrap;">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m6.75 12-3-3m0 0-3 3m3-3v6m-1.5-15H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
				Choose .xlsx file
				<input type="file" name="who_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required style="display:none;">
			</label>
			<button type="submit" class="admin-btn" style="white-space:nowrap;">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/></svg>
				Import
			</button>
		</form>
	</div>
</section>
<?php endif; ?>

<?php if ($rowCount > 0): ?>
<section class="nutritionist-panel">
	<div class="nutritionist-table-head" style="margin-bottom:12px;">
		<h2 class="admin-section-title" style="margin-bottom:0;"><?php echo nutritionist_e($config['label']); ?> (<?php echo nutritionist_e($sex); ?>)</h2>
		<input class="admin-search" data-admin-filter="#who-reference-table" type="search" placeholder="Search <?php echo nutritionist_e($config['columnLabel']); ?>" style="min-width:200px;max-width:240px;">
	</div>

	<div class="nutritionist-table-wrap">
		<table class="nutritionist-table who-ref-table" id="who-reference-table">
			<thead>
				<tr>
					<th class="who-ref-x"><?php echo nutritionist_e($config['columnLabel']); ?></th>
					<th class="who-ref-lms">L</th>
					<th class="who-ref-lms">M</th>
					<th class="who-ref-lms">S</th>
					<th class="who-ref-sd who-ref-sd-neg3" style="text-align:right;">-3 SD</th>
					<th class="who-ref-sd who-ref-sd-neg2" style="text-align:right;">-2 SD</th>
					<th class="who-ref-sd who-ref-sd-neg1" style="text-align:right;">-1 SD</th>
					<th class="who-ref-sd who-ref-sd-median" style="text-align:right;">Median</th>
					<th class="who-ref-sd who-ref-sd-pos1" style="text-align:right;">+1 SD</th>
					<th class="who-ref-sd who-ref-sd-pos2" style="text-align:right;">+2 SD</th>
					<th class="who-ref-sd who-ref-sd-pos3" style="text-align:right;">+3 SD</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($rows as $row):
					$L = (float)$row['L'];
					$M = (float)$row['M'];
					$S = (float)$row['S'];
					$x = $row['x'];
					$sd3neg = who_reference_sd($L, $M, $S, -3);
					$sd2neg = who_reference_sd($L, $M, $S, -2);
					$sd1neg = who_reference_sd($L, $M, $S, -1);
					$sd1pos = who_reference_sd($L, $M, $S, 1);
					$sd2pos = who_reference_sd($L, $M, $S, 2);
					$sd3pos = who_reference_sd($L, $M, $S, 3);
				?>
					<tr data-filter-text="<?php echo nutritionist_e(strtolower((string)$x)); ?>">
						<td class="who-ref-x" style="font-weight:600;"><?php echo nutritionist_e((string)$x); ?></td>
						<td class="who-ref-lms" style="color:var(--admin-muted);"><?php echo nutritionist_e(number_format($L, 4)); ?></td>
						<td class="who-ref-lms" style="color:var(--admin-muted);"><?php echo nutritionist_e(number_format($M, 4)); ?></td>
						<td class="who-ref-lms" style="color:var(--admin-muted);"><?php echo nutritionist_e(number_format($S, 5)); ?></td>
						<td class="who-ref-sd who-ref-sd-neg3" style="text-align:right;color:var(--admin-danger);font-weight:600;"><?php echo nutritionist_e(number_format($sd3neg, 2)); ?></td>
						<td class="who-ref-sd who-ref-sd-neg2" style="text-align:right;color:#e67e22;font-weight:600;"><?php echo nutritionist_e(number_format($sd2neg, 2)); ?></td>
						<td class="who-ref-sd who-ref-sd-neg1" style="text-align:right;color:var(--admin-muted);"><?php echo nutritionist_e(number_format($sd1neg, 2)); ?></td>
						<td class="who-ref-sd who-ref-sd-median" style="text-align:right;font-weight:700;color:var(--admin-primary);"><?php echo nutritionist_e(number_format($M, 2)); ?></td>
						<td class="who-ref-sd who-ref-sd-pos1" style="text-align:right;color:var(--admin-muted);"><?php echo nutritionist_e(number_format($sd1pos, 2)); ?></td>
						<td class="who-ref-sd who-ref-sd-pos2" style="text-align:right;color:#b08900;font-weight:600;"><?php echo nutritionist_e(number_format($sd2pos, 2)); ?></td>
						<td class="who-ref-sd who-ref-sd-pos3" style="text-align:right;color:var(--admin-danger);font-weight:600;"><?php echo nutritionist_e(number_format($sd3pos, 2)); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>
<?php endif; ?>
<?php
nutritionist_layout_end();
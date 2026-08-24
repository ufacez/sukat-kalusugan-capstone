<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

$user = nutritionist_require_access();

/**
 * Category rosters matching eOPT Plus's List_* sheets 1:1, keyed off the
 * child's LATEST measurement only (one measurement round per child, same
 * as eOPT). The three "double burden" combined lists (underweight+stunted,
 * stunted+wasted, stunted+overweight) mirror eOPT's compound risk lists --
 * DOH tracks these because a child flagged on two axes at once needs
 * different follow-up than a child flagged on just one.
 */
$categories = [
	'sw_sam' => ['label' => 'Severely Wasted (SAM)', 'sheet' => 'List_SW(SAM)', 'condition' => "m.wfh_status = 'SW/SAM'"],
	'mw_mam' => ['label' => 'Moderately Wasted (MAM)', 'sheet' => 'List_MW(MAM)', 'condition' => "m.wfh_status = 'MW/MAM'"],
	'stunted' => ['label' => 'Stunted (Moderate & Severe)', 'sheet' => 'List_MSt&SSt', 'condition' => "m.hfa_status IN ('MSt','SSt')"],
	'ow_ob' => ['label' => 'Overweight & Obese', 'sheet' => 'List_OW&Ob', 'condition' => "m.wfh_status IN ('OW','Ob')"],
	'underweight_stunted' => ['label' => 'Underweight + Stunted (double burden)', 'sheet' => 'List_MUW,SUW,MSt&SSt', 'condition' => "m.wfa_status IN ('MUW','SUW') AND m.hfa_status IN ('MSt','SSt')"],
	'stunted_wasted' => ['label' => 'Stunted + Wasted (double burden)', 'sheet' => 'List_MSt,SSt,MW&SW', 'condition' => "m.hfa_status IN ('MSt','SSt') AND m.wfh_status IN ('MW/MAM','SW/SAM')"],
	'stunted_overweight' => ['label' => 'Stunted + Overweight/Obese (double burden)', 'sheet' => 'List_MSt,SSt,OW&Ob', 'condition' => "m.hfa_status IN ('MSt','SSt') AND m.wfh_status IN ('OW','Ob')"],
	'flagged' => ['label' => 'Flagged for Review', 'sheet' => '(not in eOPT — added here)', 'condition' => "m.is_flagged = 1"],
];

$reportType = (string)($_GET['report'] ?? 'summary');
$ageBand = (string)($_GET['age_band'] ?? 'all');
$barangayFilter = (int)($_GET['barangay_id'] ?? 0);

$scopeParams = [];
$scope = nutritionist_scope_fragment($user, 'c.barangay_id', $scopeParams);

$barangayFilterSql = '';
$barangayFilterParams = [];

if ($barangayFilter > 0) {
	$barangayFilterSql = ' AND c.barangay_id = ?';
	$barangayFilterParams[] = $barangayFilter;
}

$ageBandSql = '';

if ($ageBand === '0-23') {
	$ageBandSql = ' AND m.age_months < 24';
} elseif ($ageBand === '24-59') {
	$ageBandSql = ' AND m.age_months >= 24';
}

$barangays = admin_barangay_options();

/*
|--------------------------------------------------------------------------
| Category roster (one row per child, latest measurement)
|--------------------------------------------------------------------------
*/
$rosterRows = [];

if ($reportType !== 'summary' && isset($categories[$reportType])) {
	$categoryCondition = $categories[$reportType]['condition'];
	$params = array_merge($scopeParams, $barangayFilterParams);

	$rosterRows = admin_fetch_all(
		"SELECT
			c.child_code,
			c.first_name,
			c.last_name,
			c.sex,
			c.birthdate,
			
			c.is_ip,
			c.has_disability,
			bg.name AS barangay,
			p.name AS parent_name,
			p.phone AS parent_phone,
			m.measurement_date,
			m.age_months,
			m.height_cm,
			m.weight_kg,
			m.waz,
			m.haz,
			m.whz,
			m.wfa_status,
			m.hfa_status,
			m.wfh_status,
			m.is_flagged,
			m.flag_reason
		 FROM children c
		 INNER JOIN parents p ON p.id = c.parent_id
		 LEFT JOIN barangays bg ON bg.id = c.barangay_id
		 INNER JOIN measurements m ON m.id = (
			SELECT m2.id FROM measurements m2
			WHERE m2.child_id = c.id
			ORDER BY m2.measurement_date DESC, m2.id DESC
			LIMIT 1
		 )
		 WHERE {$scope}{$barangayFilterSql}{$ageBandSql} AND {$categoryCondition}
		 ORDER BY c.last_name ASC, c.first_name ASC",
		str_repeat('i', count($params)),
		$params
	);
}

/*
|--------------------------------------------------------------------------
| Barangay summary (sex-disaggregated counts, matches NutStatusBrgy)
|--------------------------------------------------------------------------
*/
$summaryRows = [];

if ($reportType === 'summary') {
	$params = array_merge($scopeParams, $barangayFilterParams);

	$summaryRows = admin_fetch_all(
		"SELECT
			c.sex,
			CASE WHEN m.age_months < 24 THEN '0-23' ELSE '24-59' END AS age_band,
			m.wfa_status,
			m.hfa_status,
			m.wfh_status,
			m.is_flagged
		 FROM children c
		 INNER JOIN measurements m ON m.id = (
			SELECT m2.id FROM measurements m2
			WHERE m2.child_id = c.id
			ORDER BY m2.measurement_date DESC, m2.id DESC
			LIMIT 1
		 )
		 WHERE {$scope}{$barangayFilterSql}{$ageBandSql}",
		str_repeat('i', count($params)),
		$params
	);
}

function doh_summary_bucket(): array
{
	return [
		'Boys' => ['0-23' => 0, '24-59' => 0, 'Total' => 0],
		'Girls' => ['0-23' => 0, '24-59' => 0, 'Total' => 0],
		'Total' => ['0-23' => 0, '24-59' => 0, 'Total' => 0],
	];
}

$wfaSummary = ['SUW' => doh_summary_bucket(), 'MUW' => doh_summary_bucket(), 'Normal' => doh_summary_bucket()];
$hfaSummary = ['SSt' => doh_summary_bucket(), 'MSt' => doh_summary_bucket(), 'Normal' => doh_summary_bucket(), 'Tall' => doh_summary_bucket()];
$wfhSummary = ['SW/SAM' => doh_summary_bucket(), 'MW/MAM' => doh_summary_bucket(), 'Normal' => doh_summary_bucket(), 'OW' => doh_summary_bucket(), 'Ob' => doh_summary_bucket()];
$flaggedTotal = 0;
$totalChildren = count($summaryRows);

foreach ($summaryRows as $row) {
	$sexLabel = (string)$row['sex'] === 'Male' ? 'Boys' : 'Girls';
	$ageBandKey = (string)$row['age_band'];

	foreach ([['wfa_status', &$wfaSummary], ['hfa_status', &$hfaSummary], ['wfh_status', &$wfhSummary]] as [$field, &$summaryRef]) {
		$value = $row[$field] ?? null;

		if ($value === null || !isset($summaryRef[$value])) {
			continue;
		}

		$summaryRef[$value][$sexLabel][$ageBandKey]++;
		$summaryRef[$value][$sexLabel]['Total']++;
		$summaryRef[$value]['Total'][$ageBandKey]++;
		$summaryRef[$value]['Total']['Total']++;
	}
	unset($summaryRef);

	if (!empty($row['is_flagged'])) {
		$flaggedTotal++;
	}
}

$exportUrl = app_url('/nutritionist/doh_reports_export.php') . '?' . http_build_query([
	'report' => $reportType,
	'age_band' => $ageBand,
	'barangay_id' => $barangayFilter,
]);

$actions = '<a class="admin-btn" href="' . nutritionist_e($exportUrl) . '">Export to Excel</a>'
	. '<button type="button" class="admin-btn-secondary" onclick="window.print()">Print / Save as PDF</button>';

nutritionist_layout_start('DOH Reports', 'Category rosters and barangay summary matching the eOPT Plus community-level tool.', 'doh_reports', $actions);
?>
<style>
	@media print {
		.nutritionist-sidebar, .nutritionist-topbar, .admin-search, .doh-report-controls, .nutritionist-page-actions { display: none !important; }
		.nutritionist-content { margin: 0 !important; padding: 0 !important; }
		body { background: #fff !important; }
	}
	.doh-report-controls { display: flex; gap: 12px; flex-wrap: wrap; align-items: end; margin-bottom: 20px; }
	.doh-report-controls label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; color: var(--admin-muted); }
	.doh-report-controls select { padding: 8px 10px; border-radius: 8px; border: 1px solid var(--admin-border); }
	.doh-print-header { display: none; }
	@media print { .doh-print-header { display: block; margin-bottom: 16px; } }
</style>

<div class="doh-print-header">
	<h2 style="margin:0;">DOH Nutritional Status Report</h2>
	<div style="color:#666;font-size:13px;">Generated <?php echo nutritionist_e(date('F j, Y')); ?> · <?php echo nutritionist_e($reportType === 'summary' ? 'Barangay Summary' : $categories[$reportType]['label'] . ' (' . $categories[$reportType]['sheet'] . ')'); ?></div>
</div>

<form class="doh-report-controls" method="get">
	<label>
		Report
		<select name="report" onchange="this.form.submit()">
			<option value="summary" <?php echo $reportType === 'summary' ? 'selected' : ''; ?>>Barangay Summary (NutStatusBrgy)</option>
			<?php foreach ($categories as $key => $cat): ?>
				<option value="<?php echo nutritionist_e($key); ?>" <?php echo $reportType === $key ? 'selected' : ''; ?>><?php echo nutritionist_e($cat['label']); ?></option>
			<?php endforeach; ?>
		</select>
	</label>
	<label>
		Age band
		<select name="age_band" onchange="this.form.submit()">
			<option value="all" <?php echo $ageBand === 'all' ? 'selected' : ''; ?>>0-59 months (all)</option>
			<option value="0-23" <?php echo $ageBand === '0-23' ? 'selected' : ''; ?>>0-23 months</option>
			<option value="24-59" <?php echo $ageBand === '24-59' ? 'selected' : ''; ?>>24-59 months</option>
		</select>
	</label>
	<label>
		Barangay
		<select name="barangay_id" onchange="this.form.submit()">
			<option value="0">All (within your scope)</option>
			<?php foreach ($barangays as $barangay): ?>
				<option value="<?php echo (int)$barangay['id']; ?>" <?php echo $barangayFilter === (int)$barangay['id'] ? 'selected' : ''; ?>><?php echo nutritionist_e($barangay['name']); ?></option>
			<?php endforeach; ?>
		</select>
	</label>
</form>

<?php if ($reportType === 'summary'): ?>

	<section class="admin-grid-cards" style="margin-bottom:20px;">
		<article class="admin-card">
			<div class="admin-stat-label">Children with a recorded measurement</div>
			<div class="admin-stat-value"><?php echo $totalChildren; ?></div>
		</article>
		<article class="admin-card">
			<div class="admin-stat-label">Flagged measurements</div>
			<div class="admin-stat-value" style="<?php echo $flaggedTotal > 0 ? 'color:#E03131;' : ''; ?>"><?php echo $flaggedTotal; ?></div>
			<div class="admin-stat-note">Biologically implausible, needs re-measurement</div>
		</article>
	</section>

	<?php
	$summaryTables = [
		'Weight-for-Age (WFA)' => $wfaSummary,
		'Height-for-Age (HFA)' => $hfaSummary,
		'Weight-for-Height (WFH)' => $wfhSummary,
	];
	?>
	<?php foreach ($summaryTables as $axisTitle => $summaryTable): ?>
		<section class="nutritionist-panel" style="margin-bottom:20px;">
			<div class="admin-section-title" style="margin-bottom:12px;"><?php echo nutritionist_e($axisTitle); ?></div>
			<div class="nutritionist-table-wrap">
				<table class="nutritionist-table">
					<thead>
						<tr>
							<th>Status</th>
							<th>Boys 0-23</th>
							<th>Boys 24-59</th>
							<th>Boys Total</th>
							<th>Girls 0-23</th>
							<th>Girls 24-59</th>
							<th>Girls Total</th>
							<th>All Total</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($summaryTable as $statusLabel => $counts): ?>
							<tr>
								<td><span class="admin-pill <?php echo nutritionist_status_class($statusLabel); ?>"><?php echo nutritionist_e($statusLabel); ?></span></td>
								<td><?php echo (int)$counts['Boys']['0-23']; ?></td>
								<td><?php echo (int)$counts['Boys']['24-59']; ?></td>
								<td><strong><?php echo (int)$counts['Boys']['Total']; ?></strong></td>
								<td><?php echo (int)$counts['Girls']['0-23']; ?></td>
								<td><?php echo (int)$counts['Girls']['24-59']; ?></td>
								<td><strong><?php echo (int)$counts['Girls']['Total']; ?></strong></td>
								<td><strong><?php echo (int)$counts['Total']['Total']; ?></strong></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
	<?php endforeach; ?>

<?php else: ?>

	<section class="nutritionist-panel">
		<div class="admin-section-title" style="margin-bottom:4px;"><?php echo nutritionist_e($categories[$reportType]['label']); ?></div>
		<div class="admin-mini" style="margin-bottom:12px;">Matches eOPT Plus sheet: <?php echo nutritionist_e($categories[$reportType]['sheet']); ?> · <?php echo count($rosterRows); ?> children</div>
		<div class="nutritionist-table-wrap">
			<table class="nutritionist-table">
				<thead>
					<tr>
						<th>No.</th>
						<th>Child</th>
						<th>Sex</th>
						<th>Age</th>
						<th>Barangay / Purok</th>
						<th>Parent / Guardian</th>
						<th>WFA</th>
						<th>HFA</th>
						<th>WFH</th>
						<th>Last Measured</th>
					</tr>
				</thead>
				<tbody>
					<?php if ($rosterRows === []): ?>
						<tr><td colspan="10" style="color:var(--admin-muted);text-align:center;padding:24px;">No children currently fall into this category.</td></tr>
					<?php endif; ?>
					<?php foreach ($rosterRows as $i => $row): ?>
						<tr style="<?php echo !empty($row['is_flagged']) ? 'background:rgba(224,49,49,0.06);' : ''; ?>">
							<td><?php echo $i + 1; ?></td>
							<td>
								<div style="font-weight:600;color:var(--admin-text);"><?php echo nutritionist_e($row['last_name'] . ', ' . $row['first_name']); ?></div>
								<div class="admin-mini">
									<?php echo nutritionist_e((string)$row['child_code']); ?>
									<?php if (!empty($row['is_ip'])): ?> · IP<?php endif; ?>
									<?php if (!empty($row['has_disability'])): ?> · PWD<?php endif; ?>
									<?php if (!empty($row['is_flagged'])): ?> · <span style="color:#E03131;">⚠ flagged</span><?php endif; ?>
								</div>
							</td>
							<td><?php echo nutritionist_e((string)$row['sex']); ?></td>
							<td><?php echo nutritionist_e((string)$row['age_months']); ?> mo</td>
							<td><?php echo nutritionist_e((string)($row['barangay'] ?? '')); ?><?php echo !empty($row['purok']) ? ' / ' . nutritionist_e((string)$row['purok']) : ''; ?></td>
							<td>
								<div><?php echo nutritionist_e((string)$row['parent_name']); ?></div>
								<div class="admin-mini"><?php echo nutritionist_e((string)($row['parent_phone'] ?? '')); ?></div>
							</td>
							<td><?php echo nutritionist_e((string)($row['wfa_status'] ?? '—')); ?></td>
							<td><?php echo nutritionist_e((string)($row['hfa_status'] ?? '—')); ?></td>
							<td><?php echo nutritionist_e((string)($row['wfh_status'] ?? '—')); ?></td>
							<td><?php echo nutritionist_e((string)$row['measurement_date']); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>

<?php endif; ?>
<?php
nutritionist_layout_end();
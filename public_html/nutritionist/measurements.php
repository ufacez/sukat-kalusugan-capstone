<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

$user = nutritionist_require_access();

$params = [];
$scope = nutritionist_scope_fragment($user, 'c.barangay', $params);
$measurements = admin_fetch_all(
	"SELECT
		m.id,
		m.measurement_date,
		m.height_cm,
		m.weight_kg,
		m.age_months,
		m.source_type,
		m.waz,
		m.haz,
		m.whz,
		m.nutritional_status,
		c.id AS child_id,
		c.child_code,
		c.first_name,
		c.last_name,
		c.barangay,
		p.name AS parent_name
	 FROM measurements m
	 INNER JOIN children c ON c.id = m.child_id
	 INNER JOIN parents p ON p.id = c.parent_id
	 WHERE {$scope}
	 ORDER BY m.measurement_date DESC, m.id DESC",
	str_repeat('s', count($params)),
	$params
);

$statusCounts = [
	'Normal' => 0,
	'Underweight' => 0,
	'Severely Underweight' => 0,
	'Stunted' => 0,
	'Wasted' => 0,
	'Overweight' => 0,
];

foreach ($measurements as $measurement) {
	$status = (string)($measurement['nutritional_status'] ?? '');

	if (isset($statusCounts[$status])) {
		$statusCounts[$status]++;
	}
}

$recentMeasurements = array_values(array_filter(
	$measurements,
	static fn(array $measurement): bool => new DateTimeImmutable((string)$measurement['measurement_date']) >= (new DateTimeImmutable('today'))->modify('-7 days')
));
$atRiskCount = count(array_filter($measurements, static fn(array $measurement): bool => !in_array((string)($measurement['nutritional_status'] ?? 'Pending'), ['Normal', 'Overweight'], true)));
$actions = '<a class="admin-btn-secondary" href="' . nutritionist_e(app_url('/nutritionist/children.php')) . '">View children</a>';

nutritionist_layout_start('Measurements', 'Latest height, weight, and WHO measurements in one view.', 'measurements', $actions);
?>
<section class="nutritionist-stat-grid">
	<article class="nutritionist-stat-card is-featured">
		<div class="nutritionist-stat-label">Measurement Entries</div>
		<div class="admin-stat-value"><?php echo count($measurements); ?></div>
		<div class="admin-stat-note">All logged measurements</div>
	</article>
	<article class="nutritionist-stat-card">
		<div class="nutritionist-stat-label">Recent Week</div>
		<div class="admin-stat-value"><?php echo count($recentMeasurements); ?></div>
		<div class="admin-stat-note">Measurements from the last 7 days</div>
	</article>
	<article class="nutritionist-stat-card">
		<div class="nutritionist-stat-label">At-Risk Results</div>
		<div class="admin-stat-value"><?php echo $atRiskCount; ?></div>
		<div class="admin-stat-note">Needs follow-up</div>
	</article>
	<article class="nutritionist-stat-card">
		<div class="nutritionist-stat-label">Normal</div>
		<div class="admin-stat-value"><?php echo (int)($statusCounts['Normal'] ?? 0); ?></div>
		<div class="admin-stat-note">Healthy classification</div>
	</article>
</section>

<section class="nutritionist-panel-grid is-balanced">
	<article class="nutritionist-panel">
		<div class="admin-section-title" style="margin-bottom:12px;">Status Breakdown</div>
		<?php foreach (['Normal', 'Underweight', 'Severely Underweight', 'Stunted', 'Wasted', 'Overweight'] as $status): ?>
			<?php
			$count = (int)($statusCounts[$status] ?? 0);
			$pct = count($measurements) > 0 ? (int)round(($count / count($measurements)) * 100) : 0;
			$barColor = match ($status) {
				'Normal' => 'var(--admin-primary)',
				'Underweight' => 'var(--admin-accent)',
				'Severely Underweight' => 'var(--admin-danger)',
				'Stunted' => '#7048E8',
				'Wasted' => '#4a9fd5',
				default => '#b08900',
			};
			?>
			<div style="margin-bottom:10px;">
				<div style="display:flex;justify-content:space-between;margin-bottom:4px;align-items:center;">
					<span class="admin-pill <?php echo nutritionist_status_class($status); ?>"><?php echo nutritionist_e($status); ?></span>
					<span class="admin-mini"><?php echo $count; ?> (<?php echo $pct; ?>%)</span>
				</div>
				<div style="height:7px;border-radius:999px;background:var(--admin-bg);overflow:hidden;">
					<div style="width:<?php echo max($pct, $count > 0 ? 3 : 0); ?>%;height:100%;border-radius:999px;background:<?php echo nutritionist_e($barColor); ?>;"></div>
				</div>
			</div>
		<?php endforeach; ?>
	</article>

	<article class="nutritionist-panel">
		<div class="admin-section-title" style="margin-bottom:12px;">Recent Measurements</div>
		<div class="admin-stat-note">Latest entries across children in scope.</div>
		<div style="margin-top:14px;display:grid;gap:10px;">
			<?php foreach (array_slice($measurements, 0, 5) as $measurement): ?>
				<div class="admin-list-item" style="padding:10px 0;">
					<div>
						<div style="font-size:12px;font-weight:600;color:var(--admin-text);"><?php echo nutritionist_e($measurement['first_name'] . ' ' . $measurement['last_name']); ?></div>
						<div class="admin-mini"><?php echo nutritionist_e((string)$measurement['measurement_date']); ?> · <?php echo nutritionist_e((string)$measurement['child_code']); ?></div>
					</div>
					<div style="text-align:right;">
						<div class="admin-pill <?php echo nutritionist_status_class((string)$measurement['nutritional_status']); ?>"><?php echo nutritionist_e((string)$measurement['nutritional_status']); ?></div>
						<div class="admin-mini">WAZ <?php echo nutritionist_e((string)$measurement['waz']); ?> · HAZ <?php echo nutritionist_e((string)$measurement['haz']); ?> · WHZ <?php echo nutritionist_e((string)$measurement['whz']); ?></div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</article>
</section>

<section class="nutritionist-panel">
	<div class="nutritionist-table-head" style="margin-bottom:12px;">
		<div>
			<h2 class="admin-section-title" style="margin-bottom:2px;">Measurement Log</h2>
			<p class="admin-section-subtitle">Search, review, and route follow-ups from the table below.</p>
		</div>
		<input class="admin-search" data-admin-filter="#measurements-table" type="search" placeholder="Search measurements" style="min-width:260px;">
	</div>

	<div class="nutritionist-table-wrap">
		<table class="nutritionist-table" id="measurements-table">
			<thead>
				<tr>
					<th>Date</th>
					<th>Child</th>
					<th>Age</th>
					<th>Height</th>
					<th>Weight</th>
					<th>WAZ</th>
					<th>HAZ</th>
					<th>WHZ</th>
					<th>Status</th>
					<th>Action</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($measurements as $measurement): ?>
					<tr data-filter-text="<?php echo nutritionist_e(strtolower($measurement['measurement_date'] . ' ' . $measurement['first_name'] . ' ' . $measurement['last_name'] . ' ' . $measurement['child_code'] . ' ' . ($measurement['nutritional_status'] ?? ''))); ?>">
						<td style="white-space:nowrap;"><?php echo nutritionist_e((string)$measurement['measurement_date']); ?></td>
						<td>
							<div style="font-weight:600;color:var(--admin-text);"><?php echo nutritionist_e($measurement['first_name'] . ' ' . $measurement['last_name']); ?></div>
							<div class="admin-mini"><?php echo nutritionist_e((string)$measurement['child_code']); ?> · <?php echo nutritionist_e((string)$measurement['parent_name']); ?></div>
						</td>
						<td style="color:var(--admin-muted);"><?php echo nutritionist_e((string)$measurement['age_months']); ?> mo</td>
						<td style="color:var(--admin-muted);"><?php echo nutritionist_e((string)$measurement['height_cm']); ?> cm</td>
						<td style="color:var(--admin-muted);"><?php echo nutritionist_e((string)$measurement['weight_kg']); ?> kg</td>
						<td style="color:var(--admin-primary);font-weight:600;"><?php echo ((float)$measurement['waz'] > 0 ? '+' : '') . nutritionist_e((string)$measurement['waz']); ?></td>
						<td style="color:#4a9fd5;font-weight:600;"><?php echo ((float)$measurement['haz'] > 0 ? '+' : '') . nutritionist_e((string)$measurement['haz']); ?></td>
						<td style="color:#0d8871;font-weight:600;"><?php echo ((float)$measurement['whz'] > 0 ? '+' : '') . nutritionist_e((string)$measurement['whz']); ?></td>
						<td><span class="admin-pill <?php echo nutritionist_status_class((string)$measurement['nutritional_status']); ?>"><?php echo nutritionist_e((string)$measurement['nutritional_status']); ?></span></td>
						<td><a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/children.php?view=' . (int)$measurement['child_id'])); ?>">View child</a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>
<?php
nutritionist_layout_end();


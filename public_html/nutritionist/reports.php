<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

$user = nutritionist_require_access();

$params = [];
$scope = nutritionist_scope_fragment($user, 'c.barangay', $params);
$rows = admin_fetch_all(
	"SELECT
		m.measurement_date,
		m.nutritional_status,
		a.status AS appointment_status
	 FROM children c
	 LEFT JOIN measurements m ON m.child_id = c.id
	 LEFT JOIN appointments a ON a.child_id = c.id
	 WHERE {$scope}
	 ORDER BY m.measurement_date DESC",
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

$appointmentCounts = [
	'pending' => 0,
	'confirmed' => 0,
	'completed' => 0,
	'cancelled' => 0,
];

foreach ($rows as $row) {
	$status = (string)($row['nutritional_status'] ?? '');

	if (isset($statusCounts[$status])) {
		$statusCounts[$status]++;
	}

	$appointmentStatus = (string)($row['appointment_status'] ?? '');

	if (isset($appointmentCounts[$appointmentStatus])) {
		$appointmentCounts[$appointmentStatus]++;
	}
}

$actions = '<a class="admin-btn-secondary" href="' . nutritionist_e(app_url('/nutritionist/who_analysis.php')) . '">WHO analysis</a>';

nutritionist_layout_start('Reports', 'Nutrition status summaries and appointment trends.', 'reports', $actions);
?>
<section class="nutritionist-stat-grid">
	<article class="nutritionist-stat-card is-featured">
		<div class="nutritionist-stat-label">Normal</div>
		<div class="admin-stat-value"><?php echo (int)($statusCounts['Normal'] ?? 0); ?></div>
		<div class="admin-stat-note">Healthy classifications</div>
	</article>
	<article class="nutritionist-stat-card">
		<div class="nutritionist-stat-label">Underweight</div>
		<div class="admin-stat-value"><?php echo (int)($statusCounts['Underweight'] ?? 0); ?></div>
		<div class="admin-stat-note">Needs monitoring</div>
	</article>
	<article class="nutritionist-stat-card">
		<div class="nutritionist-stat-label">Severe / Stunted</div>
		<div class="admin-stat-value"><?php echo (int)($statusCounts['Severely Underweight'] ?? 0) + (int)($statusCounts['Stunted'] ?? 0); ?></div>
		<div class="admin-stat-note">Priority follow-up cases</div>
	</article>
	<article class="nutritionist-stat-card">
		<div class="nutritionist-stat-label">Appointments</div>
		<div class="admin-stat-value"><?php echo array_sum($appointmentCounts); ?></div>
		<div class="admin-stat-note">All appointment statuses</div>
	</article>
</section>

<section class="nutritionist-panel-grid is-balanced">
	<article class="nutritionist-panel">
		<div class="admin-section-title" style="margin-bottom:12px;">Nutrition Status Summary</div>
		<?php foreach (['Normal', 'Underweight', 'Severely Underweight', 'Stunted', 'Wasted', 'Overweight'] as $status): ?>
			<?php
			$count = (int)($statusCounts[$status] ?? 0);
			$pct = array_sum($statusCounts) > 0 ? (int)round(($count / array_sum($statusCounts)) * 100) : 0;
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
		<div class="admin-section-title" style="margin-bottom:12px;">Appointment Status</div>
		<?php foreach ($appointmentCounts as $status => $count): ?>
			<?php
			$pct = array_sum($appointmentCounts) > 0 ? (int)round(($count / array_sum($appointmentCounts)) * 100) : 0;
			$barColor = match ($status) {
				'pending' => 'var(--admin-accent)',
				'confirmed' => 'var(--admin-primary)',
				'completed' => '#0d8871',
				default => 'var(--admin-danger)',
			};
			?>
			<div style="margin-bottom:10px;">
				<div style="display:flex;justify-content:space-between;margin-bottom:4px;align-items:center;">
					<span class="admin-pill <?php echo nutritionist_status_class($status); ?>"><?php echo nutritionist_e(ucfirst($status)); ?></span>
					<span class="admin-mini"><?php echo $count; ?> (<?php echo $pct; ?>%)</span>
				</div>
				<div style="height:7px;border-radius:999px;background:var(--admin-bg);overflow:hidden;">
					<div style="width:<?php echo max($pct, $count > 0 ? 3 : 0); ?>%;height:100%;border-radius:999px;background:<?php echo nutritionist_e($barColor); ?>;"></div>
				</div>
			</div>
		<?php endforeach; ?>
	</article>
</section>

<section class="nutritionist-panel">
	<div class="nutritionist-table-head" style="margin-bottom:12px;">
		<div>
			<h2 class="admin-section-title" style="margin-bottom:2px;">Reports Table</h2>
			<p class="admin-section-subtitle">Filtered values pulled directly from the database.</p>
		</div>
		<input class="admin-search" data-admin-filter="#reports-table" type="search" placeholder="Search status" style="min-width:240px;">
	</div>

	<div class="nutritionist-table-wrap">
		<table class="nutritionist-table" id="reports-table">
			<thead>
				<tr>
					<th>Measurement Date</th>
					<th>Nutrition Status</th>
					<th>Appointment Status</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach (array_slice($rows, 0, 60) as $row): ?>
					<tr data-filter-text="<?php echo nutritionist_e(strtolower((string)($row['measurement_date'] ?? '') . ' ' . (string)($row['nutritional_status'] ?? '') . ' ' . (string)($row['appointment_status'] ?? ''))); ?>">
						<td><?php echo nutritionist_e((string)($row['measurement_date'] ?? 'n/a')); ?></td>
						<td><span class="admin-pill <?php echo nutritionist_status_class((string)($row['nutritional_status'] ?? 'Pending')); ?>"><?php echo nutritionist_e((string)($row['nutritional_status'] ?? 'Pending')); ?></span></td>
						<td><span class="admin-pill <?php echo nutritionist_status_class((string)($row['appointment_status'] ?? 'pending')); ?>"><?php echo nutritionist_e(ucfirst((string)($row['appointment_status'] ?? 'n/a'))); ?></span></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>
<?php
nutritionist_layout_end();


<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

$user = nutritionist_require_access();

$params = [];
$scope = nutritionist_scope_fragment($user, 'c.barangay', $params);
$rows = admin_fetch_all(
	"SELECT
		c.id AS child_id,
		c.child_code,
		c.first_name,
		c.last_name,
		c.sex,
		c.barangay,
		c.birthdate,
		p.name AS parent_name,
		lm.measurement_date,
		lm.height_cm,
		lm.weight_kg,
		lm.waz,
		lm.haz,
		lm.whz,
		lm.nutritional_status
	 FROM children c
	 INNER JOIN parents p ON p.id = c.parent_id
	 LEFT JOIN measurements lm ON lm.id = (
		SELECT m.id
		FROM measurements m
		WHERE m.child_id = c.id
		ORDER BY m.measurement_date DESC, m.id DESC
		LIMIT 1
	 )
	 WHERE {$scope}
	 ORDER BY c.last_name ASC, c.first_name ASC",
	str_repeat('s', count($params)),
	$params
);

$analyzed = array_values(array_filter($rows, static fn(array $row): bool => $row['measurement_date'] !== null));
$flagged = array_values(array_filter($rows, static fn(array $row): bool => !in_array((string)($row['nutritional_status'] ?? 'Pending'), ['Normal', 'Overweight'], true)));
$normal = array_values(array_filter($rows, static fn(array $row): bool => (string)($row['nutritional_status'] ?? '') === 'Normal'));

$avgWaz = 0.0;
$avgHaz = 0.0;
$avgWhz = 0.0;
$counted = 0;

foreach ($analyzed as $row) {
	$avgWaz += (float)($row['waz'] ?? 0);
	$avgHaz += (float)($row['haz'] ?? 0);
	$avgWhz += (float)($row['whz'] ?? 0);
	$counted++;
}

if ($counted > 0) {
	$avgWaz /= $counted;
	$avgHaz /= $counted;
	$avgWhz /= $counted;
}

$actions = '<a class="admin-btn-secondary" href="' . nutritionist_e(app_url('/nutritionist/measurements.php')) . '">Open measurements</a>';

nutritionist_layout_start('WHO Analysis', 'Latest WHO z-score snapshot and classification review.', 'who_analysis', $actions);
?>
<section class="nutritionist-stat-grid">
	<article class="nutritionist-stat-card is-featured">
		<div class="nutritionist-stat-label">Children Analyzed</div>
		<div class="admin-stat-value"><?php echo count($analyzed); ?></div>
		<div class="admin-stat-note">Children with a latest measurement</div>
	</article>
	<article class="nutritionist-stat-card">
		<div class="nutritionist-stat-label">Flagged</div>
		<div class="admin-stat-value"><?php echo count($flagged); ?></div>
		<div class="admin-stat-note">Outside the normal range</div>
	</article>
	<article class="nutritionist-stat-card">
		<div class="nutritionist-stat-label">Normal</div>
		<div class="admin-stat-value"><?php echo count($normal); ?></div>
		<div class="admin-stat-note">Healthy reference cases</div>
	</article>
	<article class="nutritionist-stat-card">
		<div class="nutritionist-stat-label">Average WAZ</div>
		<div class="admin-stat-value"><?php echo number_format($avgWaz, 2); ?></div>
		<div class="admin-stat-note">HAZ <?php echo number_format($avgHaz, 2); ?> · WHZ <?php echo number_format($avgWhz, 2); ?></div>
	</article>
</section>

<section class="nutritionist-panel-grid is-balanced">
	<article class="nutritionist-panel">
		<div class="admin-section-title" style="margin-bottom:12px;">Classification Guide</div>
		<div style="display:grid;gap:10px;">
			<?php foreach ([
				['Normal', 'All z-scores within accepted range'],
				['Underweight', 'Weight-for-age below expected range'],
				['Severely Underweight', 'Immediate nutritional intervention needed'],
				['Stunted', 'Height-for-age below expected range'],
				['Wasted', 'Weight-for-height below expected range'],
				['Overweight', 'Above the healthy range'],
			] as [$label, $description]): ?>
				<div class="admin-list-item" style="padding:10px 0;">
					<span class="admin-pill <?php echo nutritionist_status_class($label); ?>"><?php echo nutritionist_e($label); ?></span>
					<span class="admin-mini" style="max-width:72%;text-align:right;"><?php echo nutritionist_e($description); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
	</article>

	<article class="nutritionist-panel">
		<div class="admin-section-title" style="margin-bottom:12px;">Priority Notes</div>
		<?php if ($flagged === []): ?>
			<div class="admin-stat-note">No flags from the latest WHO snapshot.</div>
		<?php endif; ?>
		<?php foreach (array_slice($flagged, 0, 4) as $row): ?>
			<div class="admin-list-item" style="padding:10px 0;">
				<div>
					<div style="font-size:12px;font-weight:600;color:var(--admin-text);"><?php echo nutritionist_e($row['first_name'] . ' ' . $row['last_name']); ?></div>
					<div class="admin-mini"><?php echo nutritionist_e((string)$row['child_code']); ?> · <?php echo nutritionist_e((string)$row['barangay']); ?></div>
				</div>
				<span class="admin-pill <?php echo nutritionist_status_class((string)$row['nutritional_status']); ?>"><?php echo nutritionist_e((string)$row['nutritional_status']); ?></span>
			</div>
		<?php endforeach; ?>
	</article>
</section>

<section class="nutritionist-panel">
	<div class="nutritionist-table-head" style="margin-bottom:12px;">
		<div>
			<h2 class="admin-section-title" style="margin-bottom:2px;">WHO Z-Score Table</h2>
			<p class="admin-section-subtitle">Latest recorded measurements per child.</p>
		</div>
		<input class="admin-search" data-admin-filter="#who-table" type="search" placeholder="Search children" style="min-width:240px;">
	</div>

	<div class="nutritionist-table-wrap">
		<table class="nutritionist-table" id="who-table">
			<thead>
				<tr>
					<th>Child</th>
					<th>Sex</th>
					<th>Birthdate</th>
					<th>Measurement</th>
					<th>WAZ</th>
					<th>HAZ</th>
					<th>WHZ</th>
					<th>Status</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($rows as $row): ?>
					<tr data-filter-text="<?php echo nutritionist_e(strtolower($row['first_name'] . ' ' . $row['last_name'] . ' ' . $row['child_code'] . ' ' . $row['barangay'] . ' ' . ($row['nutritional_status'] ?? ''))); ?>">
						<td>
							<div style="font-weight:600;color:var(--admin-text);"><?php echo nutritionist_e($row['first_name'] . ' ' . $row['last_name']); ?></div>
							<div class="admin-mini"><?php echo nutritionist_e((string)$row['child_code']); ?> · <?php echo nutritionist_e((string)$row['parent_name']); ?></div>
						</td>
						<td style="color:var(--admin-muted);"><?php echo nutritionist_e((string)$row['sex']); ?></td>
						<td style="color:var(--admin-muted);"><?php echo nutritionist_e((string)$row['birthdate']); ?></td>
						<td style="color:var(--admin-muted);"><?php echo nutritionist_e((string)($row['measurement_date'] ?? 'n/a')); ?></td>
						<td style="color:var(--admin-primary);font-weight:600;"><?php echo isset($row['waz']) ? ((float)$row['waz'] > 0 ? '+' : '') . nutritionist_e((string)$row['waz']) : 'n/a'; ?></td>
						<td style="color:#4a9fd5;font-weight:600;"><?php echo isset($row['haz']) ? ((float)$row['haz'] > 0 ? '+' : '') . nutritionist_e((string)$row['haz']) : 'n/a'; ?></td>
						<td style="color:#0d8871;font-weight:600;"><?php echo isset($row['whz']) ? ((float)$row['whz'] > 0 ? '+' : '') . nutritionist_e((string)$row['whz']) : 'n/a'; ?></td>
						<td><span class="admin-pill <?php echo nutritionist_status_class((string)($row['nutritional_status'] ?? 'Pending')); ?>"><?php echo nutritionist_e((string)($row['nutritional_status'] ?? 'Pending')); ?></span></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>
<?php
nutritionist_layout_end();


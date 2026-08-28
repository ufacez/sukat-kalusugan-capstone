<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

$user = nutritionist_require_access();

$params = [];
$scope = nutritionist_scope_fragment($user, 'c.barangay_id', $params);
$rows = admin_fetch_all(
	"SELECT
		c.id AS child_id,
		c.child_code,
		c.first_name,
		c.last_name,
		c.sex,
		bg.name AS barangay,
		c.birthdate,
		p.name AS parent_name,
		lm.measurement_date,
		lm.height_cm,
		lm.weight_kg,
		lm.waz,
		lm.haz,
		lm.whz,
		lm.nutritional_status,
		lm.wfa_status,
		lm.hfa_status,
		lm.wfh_status
	 FROM children c
	 INNER JOIN parents p ON p.id = c.parent_id
	 LEFT JOIN barangays bg ON bg.id = c.barangay_id
	 LEFT JOIN measurements lm ON lm.id = (
		SELECT m.id
		FROM measurements m
		WHERE m.child_id = c.id
		ORDER BY m.measurement_date DESC, m.id DESC
		LIMIT 1
	 )
	 WHERE {$scope}
	 ORDER BY c.last_name ASC, c.first_name ASC",
	str_repeat('i', count($params)),
	$params
);

$analyzed = array_values(array_filter($rows, static fn(array $row): bool => $row['measurement_date'] !== null));
$flagged = array_values(array_filter($rows, static fn(array $row): bool => !in_array((string)($row['nutritional_status'] ?? 'Pending'), ['Normal'], true)));
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

$actions = '<a class="admin-btn-secondary" href="' . nutritionist_e(app_url('/nutritionist/measurements.php')) . '">' . admin_action_icon('open') . ' Open measurements</a>';

nutritionist_layout_start('WHO Analysis', 'Latest WHO z-score snapshot and classification review.', 'who_analysis', $actions);
?>
<section class="admin-grid-cards">
	<article class="admin-card">
		<div class="admin-card-row">
			<div class="admin-card-icon">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Children Analyzed</div>
				<div class="admin-card-value"><?php echo count($analyzed); ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend is-up">Children with a latest measurement</span>
				</div>
			</div>
		</div>
	</article>
	<article class="admin-card">
		<div class="admin-card-row">
			<div class="admin-card-icon">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Flagged</div>
				<div class="admin-card-value"><?php echo count($flagged); ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend">Outside the normal range</span>
				</div>
			</div>
		</div>
	</article>
	<article class="admin-card">
		<div class="admin-card-row">
			<div class="admin-card-icon is-success">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Normal</div>
				<div class="admin-card-value"><?php echo count($normal); ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend is-up">Healthy reference cases</span>
				</div>
			</div>
		</div>
	</article>
	<article class="admin-card">
		<div class="admin-card-row">
			<div class="admin-card-icon">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Average WAZ</div>
				<div class="admin-card-value"><?php echo number_format($avgWaz, 2); ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend">HAZ <?php echo number_format($avgHaz, 2); ?> · WHZ <?php echo number_format($avgWhz, 2); ?></span>
				</div>
			</div>
		</div>
	</article>
</section>

<section class="nutritionist-panel-grid is-balanced">
	<article class="nutritionist-panel">
		<div class="admin-section-title" style="margin-bottom:12px;">Classification Guide</div>
		<div style="display:grid;gap:10px;">
		<?php foreach ([
			['Normal', 'All z-scores within accepted range'],
			['Moderately Underweight', 'WFA below expected range (MUW)'],
			['Severely Underweight', 'WFA critically low (SUW)'],
			['Moderately Stunted', 'HFA below expected range (MSt)'],
			['Severely Stunted', 'HFA critically low (SSt)'],
			['Moderately Wasted', 'WFH below expected range (MW)'],
			['Severely Wasted', 'WFH critically low (SW)'],
			['Overweight', 'WFH above expected range (OW)'],
			['Obese', 'WFH critically high (Ob)'],
			['Tall', 'HFA above expected range'],
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
					<th>WFA</th>
					<th>HFA</th>
					<th>WFH</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($rows as $row): ?>
					<tr data-filter-text="<?php echo nutritionist_e(strtolower($row['first_name'] . ' ' . $row['last_name'] . ' ' . $row['child_code'] . ' ' . (string)($row['barangay'] ?? '') . ' ' . ($row['nutritional_status'] ?? ''))); ?>">
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
					<td style="color:var(--admin-muted);"><?php echo nutritionist_e((string)($row['wfa_status'] ?? '—')); ?></td>
					<td style="color:var(--admin-muted);"><?php echo nutritionist_e((string)($row['hfa_status'] ?? '—')); ?></td>
					<td style="color:var(--admin-muted);"><?php echo nutritionist_e((string)($row['wfh_status'] ?? '—')); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>
<?php
nutritionist_layout_end();


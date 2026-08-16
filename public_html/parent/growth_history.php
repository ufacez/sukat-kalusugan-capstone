<?php

require_once __DIR__ . '/../includes/parent_helpers.php';

$user = parent_require_access();

$children = admin_fetch_all(
	'SELECT id, child_code, first_name, last_name
	 FROM children
	 WHERE parent_id = ?
	 ORDER BY last_name ASC, first_name ASC',
	'i',
	[(int)$user['id']]
);

$measurements = admin_fetch_all(
	'SELECT
		m.id,
		m.measurement_date,
		m.height_cm,
		m.weight_kg,
		m.waz,
		m.haz,
		m.whz,
		m.nutritional_status,
		c.id AS child_id,
		c.child_code,
		c.first_name,
		c.last_name,
		c.sex
	 FROM measurements m
	 INNER JOIN children c ON c.id = m.child_id
	 WHERE c.parent_id = ?
	 ORDER BY m.measurement_date DESC, m.id DESC',
	'i',
	[(int)$user['id']]
);

$latestByChild = [];

foreach ($measurements as $measurement) {
	$childId = (int)$measurement['child_id'];

	if (!isset($latestByChild[$childId])) {
		$latestByChild[$childId] = $measurement;
	}
}

$actions = '<a class="admin-btn-secondary" href="' . parent_e(app_url('/parent/children.php')) . '">Children</a>';

parent_layout_start('Growth History', 'Measurement history and WHO-linked growth results per child.', 'growth_history', $actions);
?>
<section class="parent-stat-grid">
	<article class="parent-stat-card is-featured">
		<div class="parent-stat-label">Recorded measurements</div>
		<div class="admin-stat-value"><?php echo count($measurements); ?></div>
		<div class="admin-stat-note">All readings linked to your account</div>
	</article>
	<article class="parent-stat-card">
		<div class="parent-stat-label">Children tracked</div>
		<div class="admin-stat-value"><?php echo count($children); ?></div>
		<div class="admin-stat-note">Each child with at least one record</div>
	</article>
	<article class="parent-stat-card">
		<div class="parent-stat-label">Latest status</div>
		<div class="admin-stat-value"><?php echo parent_e((string)($measurements[0]['nutritional_status'] ?? 'n/a')); ?></div>
		<div class="admin-stat-note">Most recent recorded classification</div>
	</article>
	<article class="parent-stat-card">
		<div class="parent-stat-label">Latest date</div>
		<div class="admin-stat-value"><?php echo parent_e((string)($measurements[0]['measurement_date'] ?? 'n/a')); ?></div>
		<div class="admin-stat-note">Newest entry across your children</div>
	</article>
</section>

<section class="parent-panel" style="margin-top:14px;">
	<div class="parent-table-head" style="margin-bottom:12px;">
		<div>
			<h2 class="admin-section-title" style="margin-bottom:2px;">Measurement Timeline</h2>
			<p class="admin-section-subtitle">History of height, weight, and WHO-derived indicators.</p>
		</div>
		<input class="admin-search" data-admin-filter="#growth-table" type="search" placeholder="Search measurements" style="min-width:250px;">
	</div>

	<div class="parent-table-wrap">
		<table class="parent-table" id="growth-table">
			<thead>
				<tr>
					<th>Date</th>
					<th>Child</th>
					<th>Height</th>
					<th>Weight</th>
					<th>WAZ</th>
					<th>HAZ</th>
					<th>WHZ</th>
					<th>Status</th>
				</tr>
			</thead>
			<tbody>
				<?php if ($measurements === []): ?>
					<tr><td colspan="8" style="color:var(--admin-muted);">No measurement history is available yet.</td></tr>
				<?php else: ?>
					<?php foreach ($measurements as $measurement): ?>
						<tr data-filter-text="<?php echo parent_e(strtolower($measurement['measurement_date'] . ' ' . $measurement['first_name'] . ' ' . $measurement['last_name'] . ' ' . $measurement['child_code'] . ' ' . ($measurement['nutritional_status'] ?? ''))); ?>">
							<td><?php echo parent_e((string)$measurement['measurement_date']); ?></td>
							<td>
								<div style="font-weight:700;color:var(--admin-text);"><?php echo parent_e($measurement['first_name'] . ' ' . $measurement['last_name']); ?></div>
								<div class="admin-mini"><?php echo parent_e((string)$measurement['child_code']); ?></div>
							</td>
							<td><?php echo parent_e((string)$measurement['height_cm']); ?> cm</td>
							<td><?php echo parent_e((string)$measurement['weight_kg']); ?> kg</td>
							<td><?php echo parent_e((string)($measurement['waz'] ?? 'n/a')); ?></td>
							<td><?php echo parent_e((string)($measurement['haz'] ?? 'n/a')); ?></td>
							<td><?php echo parent_e((string)($measurement['whz'] ?? 'n/a')); ?></td>
							<td><span class="admin-pill <?php echo parent_status_class((string)($measurement['nutritional_status'] ?? 'Pending')); ?>"><?php echo parent_e((string)($measurement['nutritional_status'] ?? 'Pending')); ?></span></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
</section>

<section class="parent-panel" style="margin-top:14px;">
	<div class="parent-form-head" style="margin-bottom:12px;">
		<div>
			<h2 class="admin-section-title" style="margin-bottom:2px;">Latest Status Per Child</h2>
			<p class="admin-section-subtitle">Quick summary of the most recent reading for each child.</p>
		</div>
	</div>

	<div class="parent-card-grid">
		<?php if ($children === []): ?>
			<div class="admin-mini">No child records are linked to this account.</div>
		<?php else: ?>
			<?php foreach ($children as $child): ?>
				<?php $latest = $latestByChild[(int)$child['id']] ?? null; ?>
				<article class="parent-panel" style="padding:14px;box-shadow:none;">
					<div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;">
						<div>
							<div style="font-weight:700;color:var(--admin-text);"><?php echo parent_e($child['first_name'] . ' ' . $child['last_name']); ?></div>
							<div class="admin-mini"><?php echo parent_e((string)$child['child_code']); ?></div>
						</div>
						<span class="admin-pill <?php echo parent_status_class((string)($latest['nutritional_status'] ?? 'Pending')); ?>"><?php echo parent_e((string)($latest['nutritional_status'] ?? 'Pending')); ?></span>
					</div>
					<div style="margin-top:10px;color:var(--admin-muted);font-size:0.9rem;">
						Latest: <?php echo parent_e((string)($latest['measurement_date'] ?? 'n/a')); ?>
					</div>
				</article>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
</section>
<?php
parent_layout_end();


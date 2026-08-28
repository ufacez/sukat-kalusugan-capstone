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

$actions = '<a class="admin-btn-secondary" href="' . parent_e(app_url('/parent/children.php')) . '">' . admin_action_icon('children') . ' Children</a>';

parent_layout_start('Growth History', 'Measurement history and WHO-linked growth results per child.', 'growth_history', $actions);
?>
<section class="admin-grid-cards">
	<article class="admin-card">
		<div class="admin-card-row">
			<div class="admin-card-icon">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Recorded measurements</div>
				<div class="admin-card-value"><?php echo count($measurements); ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend">All readings linked to your account</span>
				</div>
			</div>
		</div>
	</article>
	<article class="admin-card">
		<div class="admin-card-row">
			<div class="admin-card-icon">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Children tracked</div>
				<div class="admin-card-value"><?php echo count($children); ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend">Each child with at least one record</span>
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
				<div class="admin-card-label">Latest status</div>
				<div class="admin-card-value"><?php echo parent_e((string)($measurements[0]['nutritional_status'] ?? 'n/a')); ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend">Most recent recorded classification</span>
				</div>
			</div>
		</div>
	</article>
	<article class="admin-card">
		<div class="admin-card-row">
			<div class="admin-card-icon">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Latest date</div>
				<div class="admin-card-value"><?php echo parent_e((string)($measurements[0]['measurement_date'] ?? 'n/a')); ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend">Newest entry across your children</span>
				</div>
			</div>
		</div>
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


<?php

require_once __DIR__ . '/../includes/parent_helpers.php';

$user = parent_require_access();

$children = admin_fetch_all(
	'SELECT
		c.id,
		c.child_code,
		c.first_name,
		c.last_name,
		c.birthdate,
		c.sex,
		bg.name AS barangay,
		c.address,
		p.name AS parent_name,
		p.parent_type,
		p.phone AS parent_phone,
		p.status AS parent_status,
		lm.measurement_date,
		lm.height_cm,
		lm.weight_kg,
		lm.waz,
		lm.haz,
		lm.whz,
		lm.nutritional_status
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
	 WHERE c.parent_id = ?
	 ORDER BY c.last_name ASC, c.first_name ASC',
	'i',
	[(int)$user['id']]
);

$selectedChild = null;

if (isset($_GET['view'])) {
	$selectedId = (int)$_GET['view'];

	foreach ($children as $child) {
		if ((int)$child['id'] === $selectedId) {
			$selectedChild = $child;
			break;
		}
	}
}

$actions = '<a class="admin-btn" href="' . parent_e(app_url('/parent/appointments.php')) . '">Request appointment</a>';

parent_layout_start('Children', 'All children linked to your parent account and their latest growth results.', 'children', $actions);
?>
<section class="parent-stat-grid">
	<article class="parent-stat-card is-featured">
		<div class="parent-stat-label">Linked children</div>
		<div class="admin-stat-value"><?php echo count($children); ?></div>
		<div class="admin-stat-note">Household members in your portal</div>
	</article>
	<article class="parent-stat-card">
		<div class="parent-stat-label">With latest reading</div>
		<div class="admin-stat-value"><?php echo count(array_filter($children, static fn(array $child): bool => trim((string)($child['measurement_date'] ?? '')) !== '')); ?></div>
		<div class="admin-stat-note">Children already measured</div>
	</article>
	<article class="parent-stat-card">
		<div class="parent-stat-label">Needs follow-up</div>
		<div class="admin-stat-value"><?php echo count(array_filter($children, static fn(array $child): bool => !in_array((string)($child['nutritional_status'] ?? 'Pending'), ['Normal', 'Overweight'], true))); ?></div>
		<div class="admin-stat-note">Latest flagged children</div>
	</article>
	<article class="parent-stat-card">
		<div class="parent-stat-label">Account type</div>
		<div class="admin-stat-value">Parent</div>
		<div class="admin-stat-note">Signed in as a parent account</div>
	</article>
</section>

<section class="parent-panel-grid" style="margin-top:14px;">
	<article class="parent-panel">
		<div class="parent-table-head" style="margin-bottom:12px;">
			<div>
				<h2 class="admin-section-title" style="margin-bottom:2px;">Children Directory</h2>
				<p class="admin-section-subtitle">Review linked child accounts and the most recent measurement.</p>
			</div>
			<input class="admin-search" data-admin-filter="#children-table" type="search" placeholder="Search children" style="min-width:240px;">
		</div>

		<div class="parent-table-wrap">
			<table class="parent-table" id="children-table">
				<thead>
					<tr>
						<th>Child</th>
						<th>Age</th>
						<th>Barangay</th>
						<th>Parent type</th>
						<th>Last status</th>
						<th>Last measurement</th>
					</tr>
				</thead>
				<tbody>
					<?php if ($children === []): ?>
						<tr><td colspan="6" style="color:var(--admin-muted);">No linked children are available yet.</td></tr>
					<?php else: ?>
						<?php foreach ($children as $child): ?>
							<?php
							$birthdate = new DateTimeImmutable((string)$child['birthdate']);
							$age = $birthdate->diff(new DateTimeImmutable('today'));
							$ageMonths = $age->y * 12 + $age->m;
							?>
							<tr data-filter-text="<?php echo parent_e(strtolower($child['child_code'] . ' ' . $child['first_name'] . ' ' . $child['last_name'] . ' ' . (string)($child['barangay'] ?? '') . ' ' . ($child['nutritional_status'] ?? ''))); ?>">
								<td>
									<div style="font-weight:700;color:var(--admin-text);"><?php echo parent_e($child['first_name'] . ' ' . $child['last_name']); ?></div>
									<div class="admin-mini"><?php echo parent_e((string)$child['child_code']); ?> · <?php echo parent_e((string)$child['sex']); ?></div>
								</td>
								<td><?php echo (int)$ageMonths; ?> mo</td>
								<td><?php echo parent_e((string)($child['barangay'] ?? '')); ?></td>
								<td><?php echo parent_e((string)($child['parent_type'] ?? 'Guardian')); ?></td>
								<td><span class="admin-pill <?php echo parent_status_class((string)($child['nutritional_status'] ?? 'Pending')); ?>"><?php echo parent_e((string)($child['nutritional_status'] ?? 'Pending')); ?></span></td>
								<td>
									<div><?php echo parent_e((string)($child['measurement_date'] ?? 'n/a')); ?></div>
									<div class="admin-mini"><?php echo parent_e((string)($child['height_cm'] ?? '')); ?> cm · <?php echo parent_e((string)($child['weight_kg'] ?? '')); ?> kg</div>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	</article>

	<article class="parent-panel">
		<div class="parent-form-head" style="margin-bottom:12px;">
			<div>
				<h2 class="admin-section-title" style="margin-bottom:2px;">Selected Child</h2>
				<p class="admin-section-subtitle">Quick details for the chosen child.</p>
			</div>
			<a class="admin-btn-secondary" href="<?php echo parent_e(app_url('/parent/growth_history.php')); ?>">Open growth history</a>
		</div>

		<?php $selectedChild = $selectedChild ?? ($children[0] ?? null); ?>
		<?php if ($selectedChild === null): ?>
			<div class="admin-mini">No child details to display.</div>
		<?php else: ?>
			<div class="parent-panel" style="padding:14px;box-shadow:none;">
				<div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;">
					<div>
						<h3 style="margin:0;color:var(--admin-text);"><?php echo parent_e($selectedChild['first_name'] . ' ' . $selectedChild['last_name']); ?></h3>
						<div class="admin-mini"><?php echo parent_e((string)$selectedChild['child_code']); ?> · <?php echo parent_e((string)$selectedChild['sex']); ?></div>
					</div>
					<span class="admin-pill <?php echo parent_status_class((string)($selectedChild['nutritional_status'] ?? 'Pending')); ?>"><?php echo parent_e((string)($selectedChild['nutritional_status'] ?? 'Pending')); ?></span>
				</div>

				<div style="display:grid;gap:10px;margin-top:14px;">
					<div class="admin-list-item" style="padding:10px 0;">
						<span class="admin-mini">Birthdate</span>
						<strong><?php echo parent_e((string)$selectedChild['birthdate']); ?></strong>
					</div>
					<div class="admin-list-item" style="padding:10px 0;">
						<span class="admin-mini">Parent type</span>
						<strong><?php echo parent_e((string)($selectedChild['parent_type'] ?? 'Guardian')); ?></strong>
					</div>
					<div class="admin-list-item" style="padding:10px 0;">
						<span class="admin-mini">Latest measurement</span>
						<strong><?php echo parent_e((string)($selectedChild['measurement_date'] ?? 'n/a')); ?></strong>
					</div>
					<div class="admin-list-item" style="padding:10px 0;">
						<span class="admin-mini">Contact</span>
						<strong><?php echo parent_e((string)($selectedChild['parent_phone'] ?? '')); ?></strong>
					</div>
				</div>
			</div>
		<?php endif; ?>
	</article>
</section>
<?php
parent_layout_end();


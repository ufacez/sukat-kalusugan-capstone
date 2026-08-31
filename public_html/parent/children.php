<?php

require_once __DIR__ . '/../includes/parent_helpers.php';
require_once __DIR__ . '/../includes/who_calculator.php';

$user = parent_require_access();

$children = admin_fetch_all(
	"SELECT
		c.id,
		c.child_code,
		c.first_name,
		c.last_name,
		c.birthdate,
		c.sex,
		bg.name AS barangay,
		la.area_name AS local_area,
		la.area_type,
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
		COALESCE(lm.nutritional_status, CASE
			WHEN lm.waz < -3 THEN 'Severely Underweight'
			WHEN lm.haz < -3 THEN 'Severely Stunted'
			WHEN lm.whz < -3 THEN 'Severely Wasted'
			WHEN lm.waz < -2 THEN 'Moderately Underweight'
			WHEN lm.haz < -2 THEN 'Moderately Stunted'
			WHEN lm.whz < -2 THEN 'Moderately Wasted'
			WHEN lm.whz > 3 THEN 'Obese'
			WHEN lm.whz > 2 THEN 'Overweight'
			ELSE 'Normal'
		END) AS nutritional_status,
		COALESCE(lm.wfa_status, CASE
			WHEN lm.waz < -3 THEN 'SUW'
			WHEN lm.waz < -2 THEN 'MUW'
			WHEN lm.waz > 2 THEN 'OW'
			ELSE 'Normal'
		END) AS wfa_status,
		COALESCE(lm.hfa_status, CASE
			WHEN lm.haz < -3 THEN 'SSt'
			WHEN lm.haz < -2 THEN 'MSt'
			WHEN lm.haz > 2 THEN 'Tall'
			ELSE 'Normal'
		END) AS hfa_status,
		COALESCE(lm.wfh_status, CASE
			WHEN lm.whz < -3 THEN 'SW'
			WHEN lm.whz < -2 THEN 'MW'
			WHEN lm.whz > 3 THEN 'Ob'
			WHEN lm.whz > 2 THEN 'OW'
			ELSE 'Normal'
		END) AS wfh_status
	 FROM children c
	 INNER JOIN parents p ON p.id = c.parent_id
	 LEFT JOIN barangays bg ON bg.id = c.barangay_id
	 LEFT JOIN local_areas la ON la.id = c.local_area_id
	 LEFT JOIN measurements lm ON lm.id = (
		SELECT m.id
		FROM measurements m
		WHERE m.child_id = c.id
		ORDER BY m.measurement_date DESC, m.id DESC
		LIMIT 1
	 )
	 WHERE c.parent_id = ?
	 ORDER BY c.last_name ASC, c.first_name ASC",
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

$actions = '<a class="admin-btn" href="' . parent_e(app_url('/parent/appointments.php')) . '">' . admin_action_icon('calendar') . ' Request appointment</a>';

parent_layout_start('Children', 'All children linked to your parent account and their latest growth results.', 'children', $actions);
?>
<section class="admin-grid-cards">
	<article class="admin-card">
		<div class="admin-card-row">
			<div class="admin-card-icon">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Linked children</div>
				<div class="admin-card-value"><?php echo count($children); ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend">Household members in your portal</span>
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
				<div class="admin-card-label">With latest reading</div>
				<div class="admin-card-value"><?php echo count(array_filter($children, static fn(array $child): bool => trim((string)($child['measurement_date'] ?? '')) !== '')); ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend">Children already measured</span>
				</div>
			</div>
		</div>
	</article>
	<article class="admin-card">
		<div class="admin-card-row">
			<div class="admin-card-icon is-danger">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Needs follow-up</div>
				<div class="admin-card-value"><?php echo count(array_filter($children, static fn(array $child): bool => !in_array((string)($child['nutritional_status'] ?? 'Pending'), ['Normal'], true))); ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend">Latest flagged children</span>
				</div>
			</div>
		</div>
	</article>
	<article class="admin-card">
		<div class="admin-card-row">
			<div class="admin-card-icon is-success">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Account type</div>
				<div class="admin-card-value">Parent</div>
				<div class="admin-card-meta">
					<span class="admin-card-trend">Signed in as a parent account</span>
				</div>
			</div>
		</div>
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
						<th>Local Area</th>
						<th>Parent type</th>
						<th>Last status</th>
						<th>Last measurement</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php if ($children === []): ?>
						<tr><td colspan="7" style="color:var(--admin-muted);">No linked children are available yet.</td></tr>
					<?php else: ?>
						<?php foreach ($children as $child): ?>
							<?php
							$age = doh_age((string)$child['birthdate']) ?? ['days' => 0, 'months' => 0];
							?>
							<tr data-filter-text="<?php echo parent_e(strtolower($child['child_code'] . ' ' . $child['first_name'] . ' ' . $child['last_name'] . ' ' . (string)($child['barangay'] ?? '') . ' ' . (string)($child['local_area'] ?? '') . ' ' . ($child['nutritional_status'] ?? ''))); ?>">
								<td>
									<div style="font-weight:700;color:var(--admin-text);"><?php echo parent_e($child['first_name'] . ' ' . $child['last_name']); ?></div>
									<div class="admin-mini"><?php echo parent_e((string)$child['child_code']); ?> · <?php echo parent_e((string)$child['sex']); ?></div>
								</td>
								<td title="<?php echo (int)$age['days']; ?> days">
									<?php echo (int)$age['months']; ?> mo
									<div class="admin-mini" style="font-size:0.7rem;"><?php echo (int)$age['days']; ?> d</div>
								</td>
								<td><?php echo parent_e((string)($child['barangay'] ?? '')); ?></td>
								<td>
									<?php if (!empty($child['local_area'])): ?>
										<span class="admin-pill is-info"><?php echo parent_e(ucfirst((string)($child['area_type'] ?? '')) . ': ' . $child['local_area']); ?></span>
									<?php else: ?>
										<span style="color:var(--admin-muted);">—</span>
									<?php endif; ?>
								</td>
								<td><?php echo parent_e((string)($child['parent_type'] ?? 'Guardian')); ?></td>
								<td><span class="admin-pill <?php echo parent_status_class((string)($child['nutritional_status'] ?? 'Pending')); ?>"><?php echo parent_e((string)($child['nutritional_status'] ?? 'Pending')); ?></span></td>
								<td>
									<div><?php echo parent_e((string)($child['measurement_date'] ?? 'n/a')); ?></div>
									<div class="admin-mini"><?php echo parent_e((string)($child['height_cm'] ?? '')); ?> cm · <?php echo parent_e((string)($child['weight_kg'] ?? '')); ?> kg</div>
								</td>
								<td>
									<div class="admin-actions">
										<a class="admin-icon-btn" title="Edit" href="<?php echo parent_e(app_url('/parent/child_edit.php?id=' . (int)$child['id'])); ?>"><?php echo admin_action_icon('edit'); ?></a>
									</div>
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
			<a class="admin-btn-secondary" href="<?php echo parent_e(app_url('/parent/growth_history.php')); ?>"><?php echo admin_action_icon('open'); ?> Open growth history</a>
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
						<span class="admin-mini">Barangay</span>
						<strong><?php echo parent_e((string)($selectedChild['barangay'] ?? '')); ?></strong>
					</div>
					<?php if (!empty($selectedChild['local_area'])): ?>
					<div class="admin-list-item" style="padding:10px 0;">
						<span class="admin-mini">Local Area</span>
						<strong><?php echo parent_e(ucfirst((string)($selectedChild['area_type'] ?? '')) . ': ' . $selectedChild['local_area']); ?></strong>
					</div>
					<?php endif; ?>
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
				<?php
			$dohLabels = [
				'SUW' => 'Severely underweight', 'MUW' => 'Moderately underweight', 'Normal' => 'Normal',
				'SSt' => 'Severely stunted', 'MSt' => 'Moderately stunted', 'Tall' => 'Tall for age',
				'SW' => 'Severe wasting (SAM)', 'MW' => 'Moderate wasting (MAM)', 'OW' => 'Overweight', 'Ob' => 'Obese',
			];
				$wfaLabel = $dohLabels[$selectedChild['wfa_status'] ?? ''] ?? null;
				$hfaLabel = $dohLabels[$selectedChild['hfa_status'] ?? ''] ?? null;
				$wfhLabel = $dohLabels[$selectedChild['wfh_status'] ?? ''] ?? null;
				?>
				<?php if ($wfaLabel !== null || $hfaLabel !== null || $wfhLabel !== null): ?>
					<div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--admin-border);">
						<div style="font-weight:700;font-size:12px;color:var(--admin-muted);margin-bottom:8px;">Growth Assessment</div>
						<div style="display:grid;gap:6px;">
							<?php if ($wfaLabel !== null): ?>
								<div class="admin-list-item" style="padding:6px 0;"><span class="admin-mini">Weight for age</span><strong><?php echo parent_e($wfaLabel); ?></strong></div>
							<?php endif; ?>
							<?php if ($hfaLabel !== null): ?>
								<div class="admin-list-item" style="padding:6px 0;"><span class="admin-mini">Height for age</span><strong><?php echo parent_e($hfaLabel); ?></strong></div>
							<?php endif; ?>
							<?php if ($wfhLabel !== null): ?>
								<div class="admin-list-item" style="padding:6px 0;"><span class="admin-mini">Weight for height</span><strong><?php echo parent_e($wfhLabel); ?></strong></div>
							<?php endif; ?>
						</div>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</article>
</section>
<?php
parent_layout_end();


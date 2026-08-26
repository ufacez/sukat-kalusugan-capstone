<?php

require_once __DIR__ . '/../includes/parent_helpers.php';
require_once __DIR__ . '/../includes/followup_scheduler.php';

$user = parent_require_access();
$parent = admin_fetch_one(
	'SELECT id, name, email, parent_type, phone, address, status, created_at
	 FROM parents
	 WHERE id = ?
	 LIMIT 1',
	'i',
	[(int)$user['id']]
);

if ($parent === null) {
	deny_access('Parent profile could not be loaded.', 404);
}

$children = admin_fetch_all(
	'SELECT
		c.id,
		c.child_code,
		c.first_name,
		c.last_name,
		c.birthdate,
		c.sex,
		bg.name AS barangay,
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

$appointments = admin_fetch_all(
	'SELECT
		a.id,
		a.scheduled_at,
		a.status,
		a.notes,
		a.appointment_type,
		c.first_name,
		c.last_name,
		c.child_code,
		u.name AS nutritionist_name
	 FROM appointments a
	 INNER JOIN children c ON c.id = a.child_id
	 INNER JOIN users u ON u.id = a.nutritionist_id
	 WHERE a.parent_id = ?
	 ORDER BY a.scheduled_at DESC, a.id DESC',
	'i',
	[(int)$user['id']]
);

$recentMeasurements = admin_fetch_all(
	'SELECT
		m.measurement_date,
		m.height_cm,
		m.weight_kg,
		m.waz,
		m.haz,
		m.whz,
		m.nutritional_status,
		m.wfa_status,
		m.hfa_status,
		m.wfh_status,
		c.first_name,
		c.last_name,
		c.child_code
	 FROM measurements m
	 INNER JOIN children c ON c.id = m.child_id
	 WHERE c.parent_id = ?
	 ORDER BY m.measurement_date DESC, m.id DESC
	 LIMIT 8',
	'i',
	[(int)$user['id']]
);

$childrenCount = count($children);
$atRiskCount = count(array_filter($children, static fn(array $child): bool => !in_array((string)($child['nutritional_status'] ?? 'Pending'), ['Normal', 'Overweight'], true)));
$upcomingAppointments = count(array_filter($appointments, static function (array $appointment): bool {
	$scheduled = new DateTimeImmutable((string)$appointment['scheduled_at']);

	return $scheduled >= new DateTimeImmutable('today');
}));
$latestMeasurementDate = $recentMeasurements[0]['measurement_date'] ?? 'n/a';

$actions = implode(' ', [
	'<a class="admin-btn-secondary" href="' . parent_e(app_url('/parent/children.php')) . '">View children</a>',
	'<a class="admin-btn" href="' . parent_e(app_url('/parent/appointment_form.php')) . '">Book appointment</a>',
]);

parent_layout_start('Dashboard', 'Track your child records, follow-up visits, and recent growth updates.', 'dashboard', $actions);
?>
<section class="parent-hero">
	<div class="parent-hero-top">
		<div>
			<p class="eyebrow" style="color:#9fdcc0;">Parent overview</p>
			<h2 style="margin:0;font-size:1.45rem;line-height:1.2;"><?php echo parent_e((string)$parent['name']); ?></h2>
			<p class="parent-hero-copy">Use this portal to review linked children, request appointments, and check the most recent measurement results synced by the nutritionist team.</p>
		</div>
		<div class="parent-badge-row">
			<span class="parent-badge"><?php echo parent_e((string)($parent['parent_type'] ?? 'Guardian')); ?></span>
			<span class="parent-badge"><?php echo parent_e((string)($parent['status'] ?? 'active')); ?></span>
		</div>
	</div>
</section>

<section class="parent-stat-grid">
	<article class="parent-stat-card is-featured">
		<div class="parent-stat-label">Children linked</div>
		<div class="admin-stat-value"><?php echo $childrenCount; ?></div>
		<div class="admin-stat-note">Household records under your account</div>
	</article>
	<article class="parent-stat-card">
		<div class="parent-stat-label">Needs follow-up</div>
		<div class="admin-stat-value"><?php echo $atRiskCount; ?></div>
		<div class="admin-stat-note">Children flagged by the latest status</div>
	</article>
	<article class="parent-stat-card">
		<div class="parent-stat-label">Upcoming appointments</div>
		<div class="admin-stat-value"><?php echo $upcomingAppointments; ?></div>
		<div class="admin-stat-note">Scheduled visits on or after today</div>
	</article>
	<article class="parent-stat-card">
		<div class="parent-stat-label">Latest measurement</div>
		<div class="admin-stat-value"><?php echo parent_e((string)$latestMeasurementDate); ?></div>
		<div class="admin-stat-note">Most recent reading across linked children</div>
	</article>
</section>

<section class="parent-panel-grid">
	<article class="parent-panel">
		<div class="parent-toolbar" style="margin-bottom:12px;">
			<div>
				<h2 class="admin-section-title" style="margin-bottom:2px;">Children Snapshot</h2>
				<p class="admin-section-subtitle">Latest child status and last recorded measurement.</p>
			</div>
			<a class="admin-btn-secondary" href="<?php echo parent_e(app_url('/parent/children.php')); ?>">Open children</a>
		</div>

		<?php if ($children === []): ?>
			<div class="admin-mini">No child records are linked to this parent account yet.</div>
		<?php else: ?>
			<div class="parent-card-grid">
				<?php foreach (array_slice($children, 0, 4) as $child): ?>
					<?php
					$childSchedule = followup_card_state(
						(string)$child['birthdate'],
						($child['measurement_date'] ?? null) !== null ? (string)$child['measurement_date'] : null,
						($child['wfa_status'] ?? null) !== null ? (string)$child['wfa_status'] : null,
						($child['hfa_status'] ?? null) !== null ? (string)$child['hfa_status'] : null,
						($child['wfh_status'] ?? null) !== null ? (string)$child['wfh_status'] : null
					);
					?>
					<article class="parent-panel" style="padding:14px;box-shadow:none;">
						<div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;">
							<div>
								<div style="font-weight:700;color:var(--admin-text);"><?php echo parent_e($child['first_name'] . ' ' . $child['last_name']); ?></div>
								<div class="admin-mini"><?php echo parent_e((string)$child['child_code']); ?> · <?php echo parent_e((string)$child['sex']); ?></div>
							</div>
							<span class="admin-pill <?php echo parent_status_class((string)($child['nutritional_status'] ?? 'Pending')); ?>"><?php echo parent_e((string)($child['nutritional_status'] ?? 'Pending')); ?></span>
						</div>
						<div style="margin-top:10px;font-size:0.9rem;color:var(--admin-muted);">Last measurement: <?php echo parent_e((string)($child['measurement_date'] ?? 'n/a')); ?></div>
						<?php if ($childSchedule['due'] !== null): ?>
							<div style="margin-top:6px;font-size:0.85rem;color:var(--admin-muted);">
								Next measurement:
								<span style="font-weight:700;color:var(--admin-text);"><?php echo parent_e($childSchedule['due']); ?></span>
							</div>
							<div style="margin-top:4px;">
								<span class="admin-pill <?php echo $childSchedule['class']; ?>" title="EOPT follow-up schedule compliance">
									<?php echo parent_e($childSchedule['label']); ?>
								</span>
							</div>
						<?php endif; ?>
						<div class="admin-mini" style="margin-top:4px;">Barangay: <?php echo parent_e((string)($child['barangay'] ?? '')); ?></div>
					</article>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</article>

	<article class="parent-panel">
		<div class="parent-toolbar" style="margin-bottom:12px;">
			<div>
				<h2 class="admin-section-title" style="margin-bottom:2px;">Upcoming Visits</h2>
				<p class="admin-section-subtitle">Your next scheduled nutritionist appointments.</p>
			</div>
			<a class="admin-btn-secondary" href="<?php echo parent_e(app_url('/parent/appointments.php')); ?>">Appointments</a>
		</div>

		<?php if ($appointments === []): ?>
			<div class="admin-mini">No appointments have been requested yet.</div>
		<?php else: ?>
			<div style="display:grid;gap:10px;">
				<?php foreach (array_slice($appointments, 0, 4) as $appointment): ?>
					<div class="admin-list-item" style="padding:12px 0;align-items:flex-start;">
						<div>
							<div style="font-weight:700;color:var(--admin-text);"><?php echo parent_e($appointment['first_name'] . ' ' . $appointment['last_name']); ?></div>
							<div class="admin-mini"><?php echo parent_e((string)$appointment['scheduled_at']); ?> · <?php echo parent_e((string)$appointment['nutritionist_name']); ?></div>
							<?php if (($appointment['appointment_type'] ?? 'regular') === 'followup'): ?>
								<span class="admin-pill is-danger" style="margin-top:4px;font-size:10px;">Auto follow-up · mandatory</span>
							<?php endif; ?>
						</div>
						<span class="admin-pill <?php echo parent_status_class((string)$appointment['status']); ?>"><?php echo parent_e(ucfirst((string)$appointment['status'])); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</article>
</section>

<section class="parent-panel" style="margin-top:14px;">
	<div class="parent-table-head" style="margin-bottom:12px;">
		<div>
			<h2 class="admin-section-title" style="margin-bottom:2px;">Recent Measurements</h2>
			<p class="admin-section-subtitle">Latest readings for linked children.</p>
		</div>
		<a class="admin-btn-secondary" href="<?php echo parent_e(app_url('/parent/growth_history.php')); ?>">Full history</a>
	</div>

	<div class="parent-table-wrap">
		<table class="parent-table">
			<thead>
				<tr>
					<th>Date</th>
					<th>Child</th>
					<th>Height</th>
					<th>Weight</th>
					<th>Status</th>
				<th>WFA</th>
				<th>HFA</th>
				<th>WFH</th>
				</tr>
			</thead>
			<tbody>
				<?php if ($recentMeasurements === []): ?>
					<tr><td colspan="8" style="color:var(--admin-muted);">No measurements available yet.</td></tr>
				<?php else: ?>
					<?php foreach ($recentMeasurements as $measurement): ?>
						<tr>
							<td><?php echo parent_e((string)$measurement['measurement_date']); ?></td>
							<td><?php echo parent_e($measurement['first_name'] . ' ' . $measurement['last_name']); ?> <div class="admin-mini"><?php echo parent_e((string)$measurement['child_code']); ?></div></td>
							<td><?php echo parent_e((string)$measurement['height_cm']); ?> cm</td>
							<td><?php echo parent_e((string)$measurement['weight_kg']); ?> kg</td>
							<td><span class="admin-pill <?php echo parent_status_class((string)($measurement['nutritional_status'] ?? 'Pending')); ?>"><?php echo parent_e((string)($measurement['nutritional_status'] ?? 'Pending')); ?></span></td>
							<td style="color:var(--admin-muted);"><?php echo parent_e((string)($measurement['wfa_status'] ?? '—')); ?></td>
							<td style="color:var(--admin-muted);"><?php echo parent_e((string)($measurement['hfa_status'] ?? '—')); ?></td>
							<td style="color:var(--admin-muted);"><?php echo parent_e((string)($measurement['wfh_status'] ?? '—')); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
</section>
<?php
parent_layout_end();


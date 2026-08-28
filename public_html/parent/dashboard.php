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
	"SELECT
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
	 LEFT JOIN barangays bg ON bg.id = c.barangay_id
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
	"SELECT
		m.measurement_date,
		m.height_cm,
		m.weight_kg,
		m.waz,
		m.haz,
		m.whz,
		COALESCE(m.nutritional_status, CASE
			WHEN m.waz < -3 THEN 'Severely Underweight'
			WHEN m.haz < -3 THEN 'Severely Stunted'
			WHEN m.whz < -3 THEN 'Severely Wasted'
			WHEN m.waz < -2 THEN 'Moderately Underweight'
			WHEN m.haz < -2 THEN 'Moderately Stunted'
			WHEN m.whz < -2 THEN 'Moderately Wasted'
			WHEN m.whz > 3 THEN 'Obese'
			WHEN m.whz > 2 THEN 'Overweight'
			ELSE 'Normal'
		END) AS nutritional_status,
		COALESCE(m.wfa_status, CASE
			WHEN m.waz < -3 THEN 'SUW'
			WHEN m.waz < -2 THEN 'MUW'
			WHEN m.waz > 2 THEN 'OW'
			ELSE 'Normal'
		END) AS wfa_status,
		COALESCE(m.hfa_status, CASE
			WHEN m.haz < -3 THEN 'SSt'
			WHEN m.haz < -2 THEN 'MSt'
			WHEN m.haz > 2 THEN 'Tall'
			ELSE 'Normal'
		END) AS hfa_status,
		COALESCE(m.wfh_status, CASE
			WHEN m.whz < -3 THEN 'SW'
			WHEN m.whz < -2 THEN 'MW'
			WHEN m.whz > 3 THEN 'Ob'
			WHEN m.whz > 2 THEN 'OW'
			ELSE 'Normal'
		END) AS wfh_status,
		c.first_name,
		c.last_name,
		c.child_code
	 FROM measurements m
	 INNER JOIN children c ON c.id = m.child_id
	 WHERE c.parent_id = ?
	 ORDER BY m.measurement_date DESC, m.id DESC
	 LIMIT 8",
	'i',
	[(int)$user['id']]
);

$childrenCount = count($children);
$atRiskCount = count(array_filter($children, static fn(array $child): bool => !in_array((string)($child['nutritional_status'] ?? 'Pending'), ['Normal'], true)));
$upcomingAppointments = count(array_filter($appointments, static function (array $appointment): bool {
	$scheduled = new DateTimeImmutable((string)$appointment['scheduled_at']);

	return $scheduled >= new DateTimeImmutable('today');
}));
$latestMeasurementDate = $recentMeasurements[0]['measurement_date'] ?? 'n/a';

$actions = implode(' ', [
	'<a class="admin-btn-secondary" href="' . parent_e(app_url('/parent/children.php')) . '">' . admin_action_icon('view') . ' View children</a>',
	'<a class="admin-btn" href="' . parent_e(app_url('/parent/appointment_form.php')) . '">' . admin_action_icon('calendar') . ' Book appointment</a>',
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

<section class="admin-grid-cards">
	<article class="admin-card">
		<div class="admin-card-row">
			<div class="admin-card-icon">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Children Linked</div>
				<div class="admin-card-value"><?php echo $childrenCount; ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend is-up">Household records</span>
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
				<div class="admin-card-label">Needs Follow-up</div>
				<div class="admin-card-value"><?php echo $atRiskCount; ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend is-danger">Flagged children</span>
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
				<div class="admin-card-label">Upcoming Appointments</div>
				<div class="admin-card-value"><?php echo $upcomingAppointments; ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend is-up">Scheduled visits</span>
				</div>
			</div>
		</div>
	</article>
	<article class="admin-card">
		<div class="admin-card-row">
			<div class="admin-card-icon is-success">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Latest Measurement</div>
				<div class="admin-card-value admin-card-value--text"><?php echo parent_e((string)$latestMeasurementDate); ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend is-up">Most recent reading</span>
				</div>
			</div>
		</div>
	</article>
</section>

<section class="parent-panel-grid">
	<article class="parent-panel">
		<div class="parent-toolbar" style="margin-bottom:12px;">
			<div>
				<h2 class="admin-section-title" style="margin-bottom:2px;">Children Snapshot</h2>
				<p class="admin-section-subtitle">Latest child status and last recorded measurement.</p>
			</div>
			<a class="admin-btn-secondary" href="<?php echo parent_e(app_url('/parent/children.php')); ?>"><?php echo admin_action_icon('open'); ?> Open children</a>
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
			<a class="admin-btn-secondary" href="<?php echo parent_e(app_url('/parent/appointments.php')); ?>"><?php echo admin_action_icon('calendar'); ?> Appointments</a>
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
		<a class="admin-btn-secondary" href="<?php echo parent_e(app_url('/parent/growth_history.php')); ?>"><?php echo admin_action_icon('view'); ?> Full history</a>
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


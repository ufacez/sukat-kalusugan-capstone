<?php

require_once __DIR__ . '/../includes/parent_helpers.php';

$user = parent_require_access();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = (string)($_POST['action'] ?? '');
	$appointmentId = (int)($_POST['id'] ?? 0);

	if ($action === 'cancel' && $appointmentId > 0) {
		$ok = admin_execute(
			'UPDATE appointments
			 SET status = ?
			 WHERE id = ? AND parent_id = ? AND status NOT IN (?, ?)
			   AND appointment_type <> ?',
			'ssiiss',
			['cancelled', $appointmentId, (int)$user['id'], 'completed', 'cancelled', 'followup']
		);

		admin_redirect('/parent/appointments.php', $ok ? ['notice' => 'Appointment cancelled.'] : ['notice' => 'Appointment could not be cancelled. Mandatory EOPT follow-ups cannot be cancelled.', 'type' => 'error']);
	}
}

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
		u.name AS nutritionist_name,
		b.name AS nutritionist_barangay
	 FROM appointments a
	 INNER JOIN children c ON c.id = a.child_id
	 INNER JOIN users u ON u.id = a.nutritionist_id
	 LEFT JOIN barangays b ON b.id = u.barangay_id
	 WHERE a.parent_id = ?
	 ORDER BY a.scheduled_at DESC, a.id DESC',
	'i',
	[(int)$user['id']]
);

$pendingCount = count(array_filter($appointments, static fn(array $appointment): bool => (string)$appointment['status'] === 'pending'));
$confirmedCount = count(array_filter($appointments, static fn(array $appointment): bool => (string)$appointment['status'] === 'confirmed'));
$completedCount = count(array_filter($appointments, static fn(array $appointment): bool => (string)$appointment['status'] === 'completed'));

$actions = '<a class="admin-btn" href="'
	. parent_e(app_url('/parent/appointment_form.php'))
	. '">' . admin_action_icon('calendar') . ' Request appointment</a>';

parent_layout_start('Appointments', 'Request follow-ups and manage your appointment schedule.', 'appointments', $actions);
?>
<section class="admin-grid-cards">
	<article class="admin-card">
		<div class="admin-card-row">
			<div class="admin-card-icon is-danger">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Pending</div>
				<div class="admin-card-value"><?php echo $pendingCount; ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend">Waiting for nutritionist review</span>
				</div>
			</div>
		</div>
	</article>
	<article class="admin-card">
		<div class="admin-card-row">
			<div class="admin-card-icon is-success">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Confirmed</div>
				<div class="admin-card-value"><?php echo $confirmedCount; ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend">Approved and scheduled</span>
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
				<div class="admin-card-label">Completed</div>
				<div class="admin-card-value"><?php echo $completedCount; ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend">Finished visits</span>
				</div>
			</div>
		</div>
	</article>
</section>

<section class="parent-panel" style="margin-top:14px;">
	<div class="parent-table-head" style="margin-bottom:12px;">
		<div>
			<h2 class="admin-section-title" style="margin-bottom:2px;">Appointment Schedule</h2>
			<p class="admin-section-subtitle">Recent appointment requests and their current status.</p>
		</div>
		<input class="admin-search" data-admin-filter="#appointments-table" type="search" placeholder="Search appointments" style="min-width:250px;">
	</div>

	<div class="parent-table-wrap">
		<table class="parent-table" id="appointments-table">
			<thead>
				<tr>
					<th>Date & time</th>
					<th>Child</th>
					<th>Nutritionist</th>
					<th>Status</th>
					<th>Notes</th>
					<th>Action</th>
				</tr>
			</thead>
			<tbody>
				<?php if ($appointments === []): ?>
					<tr><td colspan="6" style="color:var(--admin-muted);">No appointments have been created yet.</td></tr>
				<?php else: ?>
					<?php foreach ($appointments as $appointment): ?>
						<tr data-filter-text="<?php echo parent_e(strtolower($appointment['scheduled_at'] . ' ' . $appointment['first_name'] . ' ' . $appointment['last_name'] . ' ' . $appointment['nutritionist_name'] . ' ' . $appointment['status'])); ?>">
							<td><?php echo parent_e((string)$appointment['scheduled_at']); ?></td>
							<td>
								<div style="font-weight:700;color:var(--admin-text);"><?php echo parent_e($appointment['first_name'] . ' ' . $appointment['last_name']); ?></div>
								<div class="admin-mini"><?php echo parent_e((string)$appointment['child_code']); ?></div>
							</td>
							<td>
								<div style="font-weight:700;color:var(--admin-text);"><?php echo parent_e((string)$appointment['nutritionist_name']); ?></div>
								<div class="admin-mini"><?php echo parent_e((string)($appointment['nutritionist_barangay'] ?? '')); ?></div>
							</td>
							<td><span class="admin-pill <?php echo parent_status_class((string)$appointment['status']); ?>"><?php echo parent_e(ucfirst((string)$appointment['status'])); ?></span></td>
							<td style="color:var(--admin-muted);">
								<?php if (($appointment['appointment_type'] ?? 'regular') === 'followup'): ?>
									<span class="admin-pill is-danger" style="margin-right:6px;">Auto follow-up · mandatory</span>
								<?php endif; ?>
								<?php echo parent_e((string)($appointment['notes'] ?? '')); ?>
							</td>
							<td>
								<?php if (($appointment['appointment_type'] ?? 'regular') === 'followup' && !in_array((string)$appointment['status'], ['completed', 'cancelled'], true)): ?>
									<span class="admin-mini">Required re-measurement — cannot be cancelled</span>
								<?php elseif (in_array((string)$appointment['status'], ['completed', 'cancelled'], true)): ?>
									<span class="admin-mini">No action available</span>
								<?php else: ?>
									<form method="post" action="<?php echo parent_e(app_url('/parent/appointments.php')); ?>" onsubmit="return confirm('Cancel this appointment?');" style="display:inline;">
										<input type="hidden" name="action" value="cancel">
										<input type="hidden" name="id" value="<?php echo (int)$appointment['id']; ?>">
										<button class="admin-icon-btn admin-icon-btn-danger" title="Cancel" type="submit"><?php echo admin_action_icon('delete'); ?></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
</section>

<?php
parent_layout_end();


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
	. '">Request appointment</a>';

parent_layout_start('Appointments', 'Request follow-ups and manage your appointment schedule.', 'appointments', $actions);
?>
<section class="parent-stat-grid">
	<article class="parent-stat-card is-featured">
		<div class="parent-stat-label">Pending</div>
		<div class="admin-stat-value"><?php echo $pendingCount; ?></div>
		<div class="admin-stat-note">Waiting for nutritionist review</div>
	</article>
	<article class="parent-stat-card">
		<div class="parent-stat-label">Confirmed</div>
		<div class="admin-stat-value"><?php echo $confirmedCount; ?></div>
		<div class="admin-stat-note">Approved and scheduled</div>
	</article>
	<article class="parent-stat-card">
		<div class="parent-stat-label">Completed</div>
		<div class="admin-stat-value"><?php echo $completedCount; ?></div>
		<div class="admin-stat-note">Finished visits</div>
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
									<form method="post" action="<?php echo parent_e(app_url('/parent/appointments.php')); ?>" onsubmit="return confirm('Cancel this appointment?');">
										<input type="hidden" name="action" value="cancel">
										<input type="hidden" name="id" value="<?php echo (int)$appointment['id']; ?>">
										<button class="admin-btn-danger" type="submit">Cancel</button>
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


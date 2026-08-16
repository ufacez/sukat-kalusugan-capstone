<?php

require_once __DIR__ . '/../includes/parent_helpers.php';

$user = parent_require_access();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = (string)($_POST['action'] ?? '');
	$appointmentId = (int)($_POST['id'] ?? 0);

	if ($action === 'create') {
		$childId = (int)($_POST['child_id'] ?? 0);
		$nutritionistId = (int)($_POST['nutritionist_id'] ?? 0);
		$scheduledAt = trim((string)($_POST['scheduled_at'] ?? ''));
		$notes = trim((string)($_POST['notes'] ?? ''));

		if ($childId <= 0 || $nutritionistId <= 0 || $scheduledAt === '') {
			admin_redirect('/parent/appointments.php', ['notice' => 'Child, nutritionist, and schedule are required.', 'type' => 'error']);
		}

		$child = admin_fetch_one('SELECT id FROM children WHERE id = ? AND parent_id = ? LIMIT 1', 'ii', [$childId, (int)$user['id']]);

		if ($child === null) {
			admin_redirect('/parent/appointments.php', ['notice' => 'The selected child is not linked to your account.', 'type' => 'error']);
		}

		$nutritionist = admin_fetch_one(
			'SELECT u.id
			 FROM users u
			 INNER JOIN roles r ON r.id = u.role_id
			 WHERE u.id = ? AND r.name = ? AND u.status = ?
			 LIMIT 1',
			'iss',
			[$nutritionistId, 'nutritionist', 'active']
		);

		if ($nutritionist === null) {
			admin_redirect('/parent/appointments.php', ['notice' => 'Please select an active nutritionist.', 'type' => 'error']);
		}

		$ok = admin_execute(
			'INSERT INTO appointments (child_id, parent_id, nutritionist_id, scheduled_at, status, notes)
			 VALUES (?, ?, ?, ?, ?, ?)',
			'iiisss',
			[$childId, (int)$user['id'], $nutritionistId, $scheduledAt, 'pending', $notes]
		);

		admin_redirect('/parent/appointments.php', $ok ? ['notice' => 'Appointment requested successfully.'] : ['notice' => 'Appointment could not be created.', 'type' => 'error']);
	}

	if ($action === 'cancel' && $appointmentId > 0) {
		$ok = admin_execute(
			'UPDATE appointments
			 SET status = ?
			 WHERE id = ? AND parent_id = ? AND status NOT IN (?, ?)',
			'siiss',
			['cancelled', $appointmentId, (int)$user['id'], 'completed', 'cancelled']
		);

		admin_redirect('/parent/appointments.php', $ok ? ['notice' => 'Appointment cancelled.'] : ['notice' => 'Appointment could not be cancelled.', 'type' => 'error']);
	}
}

$children = admin_fetch_all(
	'SELECT id, first_name, last_name, child_code
	 FROM children
	 WHERE parent_id = ?
	 ORDER BY last_name ASC, first_name ASC',
	'i',
	[(int)$user['id']]
);

$nutritionists = admin_fetch_all(
	'SELECT u.id, u.name, u.barangay
	 FROM users u
	 INNER JOIN roles r ON r.id = u.role_id
	 WHERE r.name = ? AND u.status = ?
	 ORDER BY u.name ASC',
	'ss',
	['nutritionist', 'active']
);

$appointments = admin_fetch_all(
	'SELECT
		a.id,
		a.scheduled_at,
		a.status,
		a.notes,
		c.first_name,
		c.last_name,
		c.child_code,
		u.name AS nutritionist_name,
		u.barangay AS nutritionist_barangay
	 FROM appointments a
	 INNER JOIN children c ON c.id = a.child_id
	 INNER JOIN users u ON u.id = a.nutritionist_id
	 WHERE a.parent_id = ?
	 ORDER BY a.scheduled_at DESC, a.id DESC',
	'i',
	[(int)$user['id']]
);

$pendingCount = count(array_filter($appointments, static fn(array $appointment): bool => (string)$appointment['status'] === 'pending'));
$confirmedCount = count(array_filter($appointments, static fn(array $appointment): bool => (string)$appointment['status'] === 'confirmed'));
$completedCount = count(array_filter($appointments, static fn(array $appointment): bool => (string)$appointment['status'] === 'completed'));

$actions = '<a class="admin-btn" href="#appointment-form">Request appointment</a>';

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
	<article class="parent-stat-card">
		<div class="parent-stat-label">Children available</div>
		<div class="admin-stat-value"><?php echo count($children); ?></div>
		<div class="admin-stat-note">Choose a child when booking</div>
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
							<td style="color:var(--admin-muted);"><?php echo parent_e((string)($appointment['notes'] ?? '')); ?></td>
							<td>
								<?php if (in_array((string)$appointment['status'], ['completed', 'cancelled'], true)): ?>
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

<section class="parent-panel" id="appointment-form" style="margin-top:14px;">
	<div class="parent-form-head" style="margin-bottom:12px;">
		<div>
			<h2 class="admin-section-title" style="margin-bottom:2px;">Request Appointment</h2>
			<p class="admin-section-subtitle">Submit a follow-up request for any linked child.</p>
		</div>
	</div>

	<form method="post" class="parent-form-grid">
		<input type="hidden" name="action" value="create">
		<label class="admin-field">
			<span>Child</span>
			<select name="child_id" required>
				<option value="">-- Select child --</option>
				<?php foreach ($children as $child): ?>
					<option value="<?php echo (int)$child['id']; ?>"><?php echo parent_e($child['first_name'] . ' ' . $child['last_name'] . ' · ' . $child['child_code']); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label class="admin-field">
			<span>Nutritionist</span>
			<select name="nutritionist_id" required>
				<option value="">-- Select nutritionist --</option>
				<?php foreach ($nutritionists as $nutritionist): ?>
					<option value="<?php echo (int)$nutritionist['id']; ?>"><?php echo parent_e($nutritionist['name'] . ' · ' . (string)($nutritionist['barangay'] ?? '')); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label class="admin-field">
			<span>Preferred schedule</span>
			<input type="datetime-local" name="scheduled_at" required>
		</label>
		<label class="admin-field">
			<span>Notes</span>
			<input name="notes" placeholder="Optional reason for the visit">
		</label>
		<div class="admin-field" style="align-content:end;grid-column:1 / -1;">
			<span>&nbsp;</span>
			<button class="admin-btn" type="submit">Submit request</button>
		</div>
	</form>
</section>
<?php
parent_layout_end();


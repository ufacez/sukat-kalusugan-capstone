<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

$user = nutritionist_require_access();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = (string)($_POST['action'] ?? '');
	$appointmentId = (int)($_POST['id'] ?? 0);

	if ($action === 'create') {
		$childId = (int)($_POST['child_id'] ?? 0);
		$parentId = (int)($_POST['parent_id'] ?? 0);
		$scheduledAt = trim((string)($_POST['scheduled_at'] ?? ''));
		$notes = trim((string)($_POST['notes'] ?? ''));

		if ($childId <= 0 || $parentId <= 0 || $scheduledAt === '') {
			admin_redirect('/nutritionist/appointments.php', ['notice' => 'Child, parent, and schedule are required.', 'type' => 'error']);
		}

		$ok = admin_execute(
			'INSERT INTO appointments (child_id, parent_id, nutritionist_id, scheduled_at, status, notes)
			 VALUES (?, ?, ?, ?, ?, ?)',
			'iiisss',
			[$childId, $parentId, (int)$user['id'], $scheduledAt, 'pending', $notes]
		);

		admin_redirect('/nutritionist/appointments.php', $ok ? ['notice' => 'Appointment scheduled.'] : ['notice' => 'Appointment could not be scheduled.', 'type' => 'error']);
	}

	if ($action === 'update_status' && $appointmentId > 0) {
		$status = (string)($_POST['status'] ?? 'pending');

		if (!in_array($status, ['pending', 'confirmed', 'completed', 'cancelled'], true)) {
			$status = 'pending';
		}

		$ok = admin_execute('UPDATE appointments SET status = ? WHERE id = ?', 'si', [$status, $appointmentId]);
		admin_redirect('/nutritionist/appointments.php', $ok ? ['notice' => 'Appointment updated.'] : ['notice' => 'Appointment could not be updated.', 'type' => 'error']);
	}

	if ($action === 'delete' && $appointmentId > 0) {
		$ok = admin_execute('DELETE FROM appointments WHERE id = ?', 'i', [$appointmentId]);
		admin_redirect('/nutritionist/appointments.php', $ok ? ['notice' => 'Appointment removed.'] : ['notice' => 'Appointment could not be removed.', 'type' => 'error']);
	}
}

$params = [];
$scope = nutritionist_scope_fragment($user, 'c.barangay', $params);
$appointments = admin_fetch_all(
	"SELECT
		a.id,
		a.scheduled_at,
		a.status,
		a.notes,
		c.id AS child_id,
		c.child_code,
		c.first_name,
		c.last_name,
		c.barangay,
		p.name AS parent_name,
		p.phone AS parent_phone,
		u.name AS nutritionist_name
	 FROM appointments a
	 INNER JOIN children c ON c.id = a.child_id
	 INNER JOIN parents p ON p.id = a.parent_id
	 INNER JOIN users u ON u.id = a.nutritionist_id
	 WHERE {$scope}
	 ORDER BY a.scheduled_at ASC, a.id ASC",
	str_repeat('s', count($params)),
	$params
);

$statusCounts = ['pending' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0];

foreach ($appointments as $appointment) {
	$status = (string)$appointment['status'];

	if (isset($statusCounts[$status])) {
		$statusCounts[$status]++;
	}
}

$childrenParams = [];
$childrenScope = nutritionist_scope_fragment($user, 'c.barangay', $childrenParams);
$children = admin_fetch_all(
	"SELECT c.id, c.first_name, c.last_name, c.parent_id, p.name AS parent_name
	 FROM children c
	 INNER JOIN parents p ON p.id = c.parent_id
	 WHERE {$childrenScope}
	 ORDER BY c.last_name ASC, c.first_name ASC",
	str_repeat('s', count($childrenParams)),
	$childrenParams
);

$parents = admin_fetch_all(
	"SELECT p.id, p.name
	 FROM parents p
	 INNER JOIN children c ON c.parent_id = p.id
	 WHERE {$childrenScope}
	 GROUP BY p.id, p.name
	 ORDER BY p.name ASC",
	str_repeat('s', count($childrenParams)),
	$childrenParams
);

$actions = '<a class="admin-btn-secondary" href="#appointment-form">New appointment</a>';

nutritionist_layout_start('Appointments', 'Schedule follow-ups and manage appointment statuses.', 'appointments', $actions);
?>
<section class="nutritionist-stat-grid">
	<article class="nutritionist-stat-card is-featured">
		<div class="nutritionist-stat-label">Pending</div>
		<div class="admin-stat-value"><?php echo (int)$statusCounts['pending']; ?></div>
		<div class="admin-stat-note">Awaiting confirmation</div>
	</article>
	<article class="nutritionist-stat-card">
		<div class="nutritionist-stat-label">Confirmed</div>
		<div class="admin-stat-value"><?php echo (int)$statusCounts['confirmed']; ?></div>
		<div class="admin-stat-note">Ready for follow-up</div>
	</article>
	<article class="nutritionist-stat-card">
		<div class="nutritionist-stat-label">Completed</div>
		<div class="admin-stat-value"><?php echo (int)$statusCounts['completed']; ?></div>
		<div class="admin-stat-note">Finished visits</div>
	</article>
	<article class="nutritionist-stat-card">
		<div class="nutritionist-stat-label">Cancelled</div>
		<div class="admin-stat-value"><?php echo (int)$statusCounts['cancelled']; ?></div>
		<div class="admin-stat-note">Rescheduled or missed</div>
	</article>
</section>

<section class="nutritionist-panel">
	<div class="nutritionist-table-head" style="margin-bottom:12px;">
		<div>
			<h2 class="admin-section-title" style="margin-bottom:2px;">Appointment Schedule</h2>
			<p class="admin-section-subtitle">Manage scheduled visits directly from the table.</p>
		</div>
		<input class="admin-search" data-admin-filter="#appointments-table" type="search" placeholder="Search appointments" style="min-width:260px;">
	</div>

	<div class="nutritionist-table-wrap">
		<table class="nutritionist-table" id="appointments-table">
			<thead>
				<tr>
					<th>Date & Time</th>
					<th>Child</th>
					<th>Parent</th>
					<th>Status</th>
					<th>Notes</th>
					<th>Action</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($appointments as $appointment): ?>
					<tr data-filter-text="<?php echo nutritionist_e(strtolower($appointment['scheduled_at'] . ' ' . $appointment['first_name'] . ' ' . $appointment['last_name'] . ' ' . $appointment['parent_name'] . ' ' . $appointment['status'])); ?>">
						<td style="white-space:nowrap;"><?php echo nutritionist_e((string)$appointment['scheduled_at']); ?></td>
						<td>
							<div style="font-weight:600;color:var(--admin-text);"><?php echo nutritionist_e($appointment['first_name'] . ' ' . $appointment['last_name']); ?></div>
							<div class="admin-mini"><?php echo nutritionist_e((string)$appointment['child_code']); ?> · <?php echo nutritionist_e((string)$appointment['barangay']); ?></div>
						</td>
						<td>
							<div style="font-weight:600;color:var(--admin-text);"><?php echo nutritionist_e((string)$appointment['parent_name']); ?></div>
							<div class="admin-mini"><?php echo nutritionist_e((string)($appointment['parent_phone'] ?? '')); ?></div>
						</td>
						<td><span class="admin-pill <?php echo nutritionist_status_class((string)$appointment['status']); ?>"><?php echo nutritionist_e((string)$appointment['status']); ?></span></td>
						<td style="color:var(--admin-muted);"><?php echo nutritionist_e((string)($appointment['notes'] ?? '')); ?></td>
						<td>
							<div class="admin-actions">
								<form method="post" action="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php')); ?>">
									<input type="hidden" name="action" value="update_status">
									<input type="hidden" name="id" value="<?php echo (int)$appointment['id']; ?>">
									<select name="status" data-admin-autosubmit>
										<?php foreach (['pending', 'confirmed', 'completed', 'cancelled'] as $status): ?>
											<option value="<?php echo nutritionist_e($status); ?>" <?php echo $appointment['status'] === $status ? 'selected' : ''; ?>><?php echo nutritionist_e(ucfirst($status)); ?></option>
										<?php endforeach; ?>
									</select>
								</form>
								<form method="post" action="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php')); ?>" onsubmit="return confirm('Delete this appointment?');">
									<input type="hidden" name="action" value="delete">
									<input type="hidden" name="id" value="<?php echo (int)$appointment['id']; ?>">
									<button class="admin-btn-danger" type="submit">Delete</button>
								</form>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>

<section class="nutritionist-panel" id="appointment-form" style="margin-top:18px;">
	<div class="nutritionist-form-head" style="margin-bottom:16px;">
		<div>
			<h2 class="admin-section-title" style="margin-bottom:2px;">Schedule Appointment</h2>
			<p class="admin-section-subtitle">Create a new follow-up visit for a child in your scope.</p>
		</div>
	</div>

	<form method="post" class="nutritionist-form-grid">
		<input type="hidden" name="action" value="create">
		<label class="admin-field">
			<span>Child</span>
			<select name="child_id" required>
				<option value="">-- Select Child --</option>
				<?php foreach ($children as $child): ?>
					<option value="<?php echo (int)$child['id']; ?>"><?php echo nutritionist_e($child['first_name'] . ' ' . $child['last_name'] . ' · ' . $child['parent_name']); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label class="admin-field">
			<span>Parent/Guardian</span>
			<select name="parent_id" required>
				<option value="">-- Select Parent --</option>
				<?php foreach ($parents as $parent): ?>
					<option value="<?php echo (int)$parent['id']; ?>"><?php echo nutritionist_e($parent['name']); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label class="admin-field">
			<span>Schedule</span>
			<input type="datetime-local" name="scheduled_at" required>
		</label>
		<label class="admin-field">
			<span>Notes</span>
			<input name="notes" placeholder="Optional follow-up notes">
		</label>
		<div class="admin-field" style="align-content:end;grid-column:1 / -1;">
			<span>&nbsp;</span>
			<button class="admin-btn" type="submit">Save appointment</button>
		</div>
	</form>
</section>
<?php
nutritionist_layout_end();


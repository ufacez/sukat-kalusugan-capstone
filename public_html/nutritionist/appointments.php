<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/followup_scheduler.php';

$user = nutritionist_require_access();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = (string)($_POST['action'] ?? '');
	$appointmentId = (int)($_POST['id'] ?? 0);

	if ($action === 'sync_followups') {
		$result = followup_sync_for_scope($user);
		admin_redirect('/nutritionist/appointments.php', ['notice' => sprintf(
			'EOPT follow-ups synced — %d generated, %d auto-completed.',
			(int)$result['generated'],
			(int)$result['completed']
		)]);
	}

	/*
	 | Mandatory re-measurement check for auto-generated follow-ups.
	 | A follow-up may ONLY be completed once a newer measurement exists
	 | (dated within the grace window at/before the due date). It can never
	 | be cancelled or deleted.
	 */
	if ($action === 'complete_followup' && $appointmentId > 0) {
		$appointment = admin_fetch_one(
			"SELECT
				a.id,
				a.child_id,
				a.scheduled_at,
				lm.measurement_date AS last_measured,
				c.first_name,
				c.last_name
			 FROM appointments a
			 INNER JOIN children c ON c.id = a.child_id
			 LEFT JOIN measurements lm ON lm.id = (
				SELECT m.id FROM measurements m
				WHERE m.child_id = a.child_id
				ORDER BY m.measurement_date DESC, m.id DESC
				LIMIT 1
			 )
			 WHERE a.id = ?
			   AND a.appointment_type = 'followup'
			   AND a.status IN ('pending', 'confirmed')
			 LIMIT 1",
			'i',
			[$appointmentId]
		);

		if ($appointment === null) {
			admin_redirect('/nutritionist/appointments.php', ['notice' => 'Follow-up not found or already closed.', 'type' => 'error']);
		}

		try {
			$satisfiedFrom = (new DateTimeImmutable((string)$appointment['scheduled_at']))
				->setTime(0, 0)
				->modify('-' . FOLLOWUP_GRACE_DAYS . ' days');
		} catch (Exception) {
			$satisfiedFrom = new DateTimeImmutable('today');
		}

		$measuredAt = $appointment['last_measured'] ?? null;

		if ($measuredAt === null || $measuredAt === '' || new DateTimeImmutable((string)$measuredAt) < $satisfiedFrom) {
			admin_redirect('/nutritionist/appointments.php', [
				'notice' => 'Re-measurement is MANDATORY before this follow-up can be completed. Record a new measurement for ' . $appointment['first_name'] . ' ' . $appointment['last_name'] . ' first.',
				'type' => 'error',
			]);
		}

		$ok = admin_execute(
			"UPDATE appointments
			 SET status = 'completed'
			 WHERE id = ? AND status IN ('pending', 'confirmed')",
			'i',
			[$appointmentId]
		);

		log_action(
			(int)$user['id'],
			'FOLLOWUP_COMPLETE',
			'info',
			sprintf('Mandatory follow-up #%d satisfied by re-measurement dated %s (%s %s).', $appointmentId, (string)$measuredAt, $appointment['first_name'], $appointment['last_name'])
		);

		followup_sync_for_scope($user);

		admin_redirect('/nutritionist/appointments.php', $ok ? ['notice' => 'Re-measurement verified — follow-up completed and next cycle scheduled.'] : ['notice' => 'Follow-up could not be updated.', 'type' => 'error']);
	}

	/*
	 | Regular (manually booked) appointments keep their old behaviour.
	 */
	if ($action === 'update_status' && $appointmentId > 0) {
		$type = (string)(admin_fetch_one(
			'SELECT appointment_type FROM appointments WHERE id = ? LIMIT 1',
			'i',
			[$appointmentId]
		)['appointment_type'] ?? 'regular');

		if ($type === 'followup') {
			admin_redirect('/nutritionist/appointments.php', [
				'notice' => 'Automatic follow-ups cannot be re-statused manually — verify the mandatory re-measurement instead.',
				'type' => 'error',
			]);
		}

		$status = (string)($_POST['status'] ?? 'pending');

		if (!in_array($status, ['pending', 'confirmed', 'completed', 'cancelled'], true)) {
			$status = 'pending';
		}

		$ok = admin_execute('UPDATE appointments SET status = ? WHERE id = ?', 'si', [$status, $appointmentId]);
		admin_redirect('/nutritionist/appointments.php', $ok ? ['notice' => 'Appointment updated.'] : ['notice' => 'Appointment could not be updated.', 'type' => 'error']);
	}

	if ($action === 'delete' && $appointmentId > 0) {
		$type = (string)(admin_fetch_one(
			'SELECT appointment_type FROM appointments WHERE id = ? LIMIT 1',
			'i',
			[$appointmentId]
		)['appointment_type'] ?? 'regular');

		if ($type === 'followup') {
			log_action((int)$user['id'], 'FOLLOWUP_DELETE_BLOCKED', 'warning', sprintf('Attempted deletion of mandatory follow-up #%d was blocked.', $appointmentId));
			admin_redirect('/nutritionist/appointments.php', ['notice' => 'Automatic follow-ups are MANDATORY and cannot be deleted.', 'type' => 'error']);
		}

		$ok = admin_execute('DELETE FROM appointments WHERE id = ?', 'i', [$appointmentId]);
		admin_redirect('/nutritionist/appointments.php', $ok ? ['notice' => 'Appointment removed.'] : ['notice' => 'Appointment could not be removed.', 'type' => 'error']);
	}
}

/*
 |--------------------------------------------------------------------------
 | One automatic scheduler pass on every page load: completes satisfied
 | cycles and books missing next-cycle appointments before rendering.
 |--------------------------------------------------------------------------
 */
$syncResult = followup_sync_for_scope($user);

$params = [];
$scope = nutritionist_scope_fragment($user, 'c.barangay_id', $params);
$appointments = admin_fetch_all(
	"SELECT
		a.id,
		a.scheduled_at,
		a.status,
		a.notes,
		a.appointment_type,
		a.followup_track,
		a.followup_category,
		c.id AS child_id,
		c.child_code,
		c.first_name,
		c.last_name,
		bg.name AS barangay,
		p.name AS parent_name,
		p.phone AS parent_phone,
		u.name AS nutritionist_name
	 FROM appointments a
	 INNER JOIN children c ON c.id = a.child_id
	 INNER JOIN parents p ON p.id = a.parent_id
	 INNER JOIN users u ON u.id = a.nutritionist_id
	 LEFT JOIN barangays bg ON bg.id = c.barangay_id
	 WHERE {$scope}
	 ORDER BY a.scheduled_at ASC, a.id ASC",
	str_repeat('i', count($params)),
	$params
);

$today = new DateTimeImmutable('today');

$regularAppointments = [];
$followUpAppointments = [];

foreach ($appointments as $appointment) {
	if (($appointment['appointment_type'] ?? 'regular') === 'followup') {
		$isOverdue = false;

		if (in_array((string)$appointment['status'], ['pending', 'confirmed'], true)) {
			try {
				$isOverdue = new DateTimeImmutable((string)$appointment['scheduled_at']) < $today;
			} catch (Exception) {
				$isOverdue = false;
			}
		}

		$appointment['is_overdue'] = $isOverdue;
		$followUpAppointments[] = $appointment;
		continue;
	}

	$regularAppointments[] = $appointment;
}

$statusCounts = ['pending' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0];

foreach ($regularAppointments as $appointment) {
	$status = (string)$appointment['status'];

	if (isset($statusCounts[$status])) {
		$statusCounts[$status]++;
	}
}

$followUpOpen = count(array_filter($followUpAppointments, static fn(array $a): bool => in_array((string)$a['status'], ['pending', 'confirmed'], true)));
$followUpOverdue = count(array_filter($followUpAppointments, static fn(array $a): bool => !empty($a['is_overdue'])));
$followUpCompleted = count(array_filter($followUpAppointments, static fn(array $a): bool => (string)$a['status'] === 'completed'));

$actions = '<div class="admin-actions">'
	. '<form method="post" action="' . nutritionist_e(app_url('/nutritionist/appointments.php')) . '" style="display:inline;">'
	. '<input type="hidden" name="action" value="sync_followups">'
	. '<button class="admin-btn-secondary" type="submit">' . admin_action_icon('sync') . ' Sync EOPT follow-ups</button>'
	. '</form>'
	. '<a class="admin-btn" href="'
	. nutritionist_e(app_url('/nutritionist/appointment_form.php'))
	. '">' . admin_action_icon('add') . ' New appointment</a>'
	. '</div>';

nutritionist_layout_start('Appointments', 'Manage visits, review automatic EOPT follow-ups, and enforce mandatory re-measurements.', 'appointments', $actions);
?>
<section class="admin-grid-cards">
	<article class="admin-card">
		<div class="admin-card-row">
			<div class="admin-card-icon">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Open appointments</div>
				<div class="admin-card-value"><?php echo (int)($statusCounts['pending'] + $statusCounts['confirmed']); ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend is-up">Manually booked visits awaiting schedule</span>
				</div>
			</div>
		</div>
	</article>
	<article class="admin-card">
		<div class="admin-card-row">
			<div class="admin-card-icon">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 12c0-1.232-.046-2.453-.138-3.662a4.006 4.006 0 0 0-3.7-3.7 48.678 48.678 0 0 0-7.324 0 4.006 4.006 0 0 0-3.7 3.7c-.017.22-.032.441-.046.662M19.5 12l3-3m-3 3-3-3m-12 3c0 1.232.046 2.453.138 3.662a4.006 4.006 0 0 0 3.7 3.7 48.656 48.656 0 0 0 7.324 0 4.006 4.006 0 0 0 3.7-3.7c.017-.22.032-.441.046-.662M4.5 12l3 3m-3-3-3 3"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Auto follow-ups</div>
				<div class="admin-card-value"><?php echo $followUpOpen; ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend">Mandatory re-measurement cycles</span>
				</div>
			</div>
		</div>
	</article>
	<article class="admin-card">
		<div class="admin-card-row">
			<div class="admin-card-icon is-danger">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Overdue follow-ups</div>
				<div class="admin-card-value" style="<?php echo $followUpOverdue > 0 ? 'color:#E03131;' : ''; ?>"><?php echo $followUpOverdue; ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend">Missed mandatory re-measurements</span>
				</div>
			</div>
		</div>
	</article>
	<article class="admin-card">
		<div class="admin-card-row">
			<div class="admin-card-icon is-success">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15a2.25 2.25 0 0 1 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m8.9-4.414c.376.023.75.05 1.124.08 1.131.094 1.976 1.057 1.976 2.192V16.5A2.25 2.25 0 0 1 18 18.75h-2.25m-7.5-10.5H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V18.75m-7.5-10.5h6.375c.621 0 1.125.504 1.125 1.125v9.375m-8.25-3 1.5 1.5 3-3.75"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Cycles completed</div>
				<div class="admin-card-value"><?php echo $followUpCompleted; ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend is-up">Verified by recorded measurements</span>
				</div>
			</div>
		</div>
	</article>
</section>

<?php if ($syncResult['generated'] > 0 || $syncResult['completed'] > 0): ?>
	<div class="admin-flash">
		EOPT scheduler: <?php echo (int)$syncResult['generated']; ?> follow-up(s) generated · <?php echo (int)$syncResult['completed']; ?> cycle(s) auto-completed from recent measurements.
	</div>
<?php endif; ?>

<section class="nutritionist-panel">
	<div class="nutritionist-table-head" style="margin-bottom:12px;">
		<div>
			<h2 class="admin-section-title" style="margin-bottom:2px;">Open Appointments</h2>
			<p class="admin-section-subtitle">Manually booked visits. Statuses can be managed directly here.</p>
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
				<?php if ($regularAppointments === []): ?>
					<tr><td colspan="6" style="color:var(--admin-muted);text-align:center;padding:20px;">No manually booked appointments.</td></tr>
				<?php endif; ?>
				<?php foreach ($regularAppointments as $appointment): ?>
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
									<select class="admin-btn-secondary" name="status" data-admin-autosubmit>
										<?php foreach (['pending', 'confirmed', 'completed', 'cancelled'] as $status): ?>
											<option value="<?php echo nutritionist_e($status); ?>" <?php echo $appointment['status'] === $status ? 'selected' : ''; ?>><?php echo nutritionist_e(ucfirst($status)); ?></option>
										<?php endforeach; ?>
									</select>
								</form>
								<form method="post" action="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php')); ?>" onsubmit="return confirm('Delete this appointment?');" style="display:inline;">
									<input type="hidden" name="action" value="delete">
									<input type="hidden" name="id" value="<?php echo (int)$appointment['id']; ?>">
									<button class="admin-icon-btn admin-icon-btn-danger" title="Delete" type="submit"><?php echo admin_action_icon('delete'); ?></button>
								</form>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>

<section class="nutritionist-panel" style="margin-top:18px;border-left:4px solid var(--admin-primary);">
	<div class="nutritionist-table-head" style="margin-bottom:12px;">
		<div>
			<h2 class="admin-section-title" style="margin-bottom:2px;">
				Automatic EOPT Follow-ups
				<span class="admin-pill is-danger" style="margin-left:8px;font-size:10px;">MANDATORY RE-MEASUREMENT</span>
			</h2>
			<p class="admin-section-subtitle">
				Monthly: all children 0–23 mo + malnourished 24–59 mo · Quarterly (Apr / Jul / Oct): normal children 24–59 mo.
				Cannot be cancelled or deleted — completion requires a new measurement.
			</p>
		</div>
		<input class="admin-search" data-admin-filter="#followups-table" type="search" placeholder="Search follow-ups" style="min-width:260px;">
	</div>

	<div class="nutritionist-table-wrap">
		<table class="nutritionist-table" id="followups-table">
			<thead>
				<tr>
					<th>Due Date</th>
					<th>Child</th>
					<th>Cadence</th>
					<th>Category</th>
					<th>Schedule Flag</th>
					<th>Parent</th>
					<th>Action</th>
				</tr>
			</thead>
			<tbody>
				<?php if ($followUpAppointments === []): ?>
					<tr><td colspan="7" style="color:var(--admin-muted);text-align:center;padding:20px;">No follow-up cycles yet — they are generated automatically once children have measurements on record.</td></tr>
				<?php endif; ?>
				<?php foreach ($followUpAppointments as $appointment): ?>
					<?php
					$isOpen = in_array((string)$appointment['status'], ['pending', 'confirmed'], true);
					$flagLabel = '—';
					$flagClass = 'is-muted';

					if ((string)$appointment['status'] === 'completed') {
						$flagLabel = 'Cycle complete';
						$flagClass = 'is-success';
					} elseif (!empty($appointment['is_overdue'])) {
						$flagLabel = 'Overdue';
						$flagClass = 'is-danger';
					} elseif ($isOpen) {
						$flagLabel = 'On schedule';
						$flagClass = 'is-warn';
					}

					$trackLabel = $appointment['followup_track'] === 'quarterly' ? 'Quarterly' : 'Monthly';
					?>
					<tr style="<?php echo !empty($appointment['is_overdue']) ? 'background:rgba(224,49,49,0.06);' : ''; ?>"
						data-filter-text="<?php echo nutritionist_e(strtolower($appointment['scheduled_at'] . ' ' . $appointment['first_name'] . ' ' . $appointment['last_name'] . ' ' . $appointment['parent_name'] . ' ' . $trackLabel . ' ' . (string)($appointment['followup_category'] ?? '') . ' ' . $flagLabel)); ?>">
						<td style="white-space:nowrap;"><?php echo nutritionist_e((string)$appointment['scheduled_at']); ?></td>
						<td>
							<div style="font-weight:600;color:var(--admin-text);"><?php echo nutritionist_e($appointment['first_name'] . ' ' . $appointment['last_name']); ?></div>
							<div class="admin-mini"><?php echo nutritionist_e((string)$appointment['child_code']); ?> · <?php echo nutritionist_e((string)$appointment['barangay']); ?></div>
						</td>
						<td>
							<span class="admin-pill <?php echo $appointment['followup_track'] === 'quarterly' ? 'is-muted' : 'is-warn'; ?>"><?php echo nutritionist_e($trackLabel); ?></span>
						</td>
						<td><?php echo nutritionist_e(followup_category_label((string)($appointment['followup_category'] ?? '')) ?: '—'); ?></td>
						<td><span class="admin-pill <?php echo $flagClass; ?>"><?php echo nutritionist_e($flagLabel); ?></span></td>
						<td>
							<div style="font-weight:600;color:var(--admin-text);"><?php echo nutritionist_e((string)$appointment['parent_name']); ?></div>
							<div class="admin-mini"><?php echo nutritionist_e((string)($appointment['parent_phone'] ?? '')); ?></div>
						</td>
						<td>
							<?php if ($isOpen): ?>
								<form method="post" action="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php')); ?>" style="display:inline;"
									onsubmit="return confirm('Verify that a NEW measurement was recorded for this child? The follow-up only completes when a re-measurement exists.');">
									<input type="hidden" name="action" value="complete_followup">
									<input type="hidden" name="id" value="<?php echo (int)$appointment['id']; ?>">
									<button class="admin-icon-btn admin-icon-btn-primary" type="submit" title="Verify re-measurement"><?php echo admin_action_icon('verify'); ?></button>
								</form>
							<?php else: ?>
								<span class="admin-mini"><?php echo nutritionist_e(ucfirst((string)$appointment['status'])); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>


<?php
nutritionist_layout_end();

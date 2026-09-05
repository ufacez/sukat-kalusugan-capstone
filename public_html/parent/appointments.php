<?php

require_once __DIR__ . '/../includes/parent_helpers.php';

$user = parent_require_access();

$children = admin_fetch_all(
	'SELECT id, first_name, last_name, child_code
	 FROM children
	 WHERE parent_id = ?
	 ORDER BY last_name ASC, first_name ASC',
	'i',
	[(int)$user['id']]
);

$selectedChildId = (int)($_GET['child_id'] ?? 0);
$selectedChild = null;
foreach ($children as $child) {
	if ((int)$child['id'] === $selectedChildId) {
		$selectedChild = $child;
		break;
	}
}
if ($selectedChild === null && $children !== []) {
	$selectedChild = $children[0];
	$selectedChildId = (int)$selectedChild['id'];
}

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
		a.child_id,
		a.scheduled_at,
		a.status,
		a.notes,
		a.appointment_type,
		a.location,
		c.first_name,
		c.last_name,
		c.child_code,
		c.sex,
		u.name AS nutritionist_name,
		b.name AS nutritionist_barangay
	 FROM appointments a
	 INNER JOIN children c ON c.id = a.child_id
	 LEFT JOIN users u ON u.id = a.nutritionist_id
	 LEFT JOIN barangays b ON b.id = u.barangay_id
	 WHERE a.parent_id = ?
	 ORDER BY a.scheduled_at DESC, a.id DESC',
	'i',
	[(int)$user['id']]
);

$now = new DateTimeImmutable();
$upcoming = [];
$past = [];

foreach ($appointments as $appt) {
	$dt = new DateTimeImmutable((string)$appt['scheduled_at']);
	$isFuture = $dt > $now;
	$status = (string)$appt['status'];
	$isOpen = in_array($status, ['pending', 'confirmed'], true);

	if ($isFuture && $isOpen) {
		if ($selectedChildId === 0 || (int)$appt['child_id'] === $selectedChildId) $upcoming[] = $appt;
	} else {
		if ($selectedChildId === 0 || (int)$appt['child_id'] === $selectedChildId) $past[] = $appt;
	}
}

usort($upcoming, static fn(array $a, array $b): int => strcmp((string)$a['scheduled_at'], (string)$b['scheduled_at']));
usort($past, static fn(array $a, array $b): int => strcmp((string)$b['scheduled_at'], (string)$a['scheduled_at']));

$nextAppointment = $upcoming[0] ?? null;

$allJson = [];
foreach ($appointments as $appt) {
	$dt = new DateTimeImmutable((string)$appt['scheduled_at']);
	$allJson[(int)$appt['id']] = [
		'id' => (int)$appt['id'],
		'date' => $dt->format('F j, Y'),
		'time' => $dt->format('g:i A'),
		'type' => ucfirst((string)($appt['appointment_type'] ?? 'Regular')),
		'child' => parent_e($appt['first_name'] . ' ' . $appt['last_name']),
		'child_avatar' => strtoupper(substr((string)$appt['first_name'], 0, 1)),
		'sex' => (string)$appt['sex'],
		'location' => parent_e((string)($appt['location'] ?? 'Barangay Health Center')),
		'nutritionist' => parent_e((string)($appt['nutritionist_name'] ?? 'Unassigned nutritionist')),
		'barangay' => parent_e((string)($appt['nutritionist_barangay'] ?? '')),
		'status' => ucfirst((string)$appt['status']),
		'status_class' => parent_status_class((string)$appt['status']),
		'notes' => parent_e((string)($appt['notes'] ?? '')),
		'is_followup' => ($appt['appointment_type'] ?? 'regular') === 'followup',
		'can_cancel' => ($appt['appointment_type'] ?? 'regular') !== 'followup'
			&& !in_array((string)$appt['status'], ['completed', 'cancelled'], true),
	];
}

$actions = '<a class="admin-btn" href="' . parent_e(app_url('/parent/appointment_form.php')) . '">Request appointment <span aria-hidden="true">&#8594;</span></a>';

parent_layout_start('Appointments', 'Keep track of your child\'s scheduled visits.', 'appointments', $actions);
?>

<section class="parent-appointments-intro">
	<button type="button" class="parent-appointment-child-card" data-appointment-child-open aria-haspopup="dialog" aria-controls="appointment-child-picker">
		<span class="parent-child-avatar" aria-hidden="true"><?php echo $selectedChild !== null ? parent_e(strtoupper(substr((string)$selectedChild['first_name'], 0, 1))) : '?'; ?></span>
		<span class="parent-appointment-child-name"><?php echo $selectedChild !== null ? parent_e($selectedChild['first_name'] . ' ' . $selectedChild['last_name']) : 'All children'; ?></span>
		<span class="parent-appointment-child-arrow" aria-hidden="true">&#9662;</span>
	</button>
	<div class="parent-appointment-tabs" role="tablist" aria-label="Appointment status">
		<button type="button" class="is-active" data-appointment-tab="upcoming">Upcoming</button>
		<button type="button" data-appointment-tab="past">Past</button>
	</div>
</section>

<div class="parent-child-picker" id="appointment-child-picker" role="dialog" aria-modal="true" aria-labelledby="appointment-child-picker-title" hidden>
	<div class="parent-child-picker-backdrop" data-appointment-child-close></div>
	<div class="parent-child-picker-sheet">
		<div class="parent-child-picker-header"><div><h2 id="appointment-child-picker-title">Choose a child</h2><p>Show appointments for this child.</p></div><button type="button" class="parent-child-picker-close" data-appointment-child-close aria-label="Close child picker">&times;</button></div>
		<div class="parent-child-picker-list">
			<?php foreach ($children as $child): ?>
				<a class="parent-child-option <?php echo (int)$child['id'] === $selectedChildId ? 'is-selected' : ''; ?>" href="<?php echo parent_e(app_url('/parent/appointments.php?child_id=' . (int)$child['id'])); ?>"><span class="parent-child-option-avatar" aria-hidden="true"><?php echo parent_e(strtoupper(substr((string)$child['first_name'], 0, 1))); ?></span><span class="parent-child-option-copy"><strong><?php echo parent_e($child['first_name'] . ' ' . $child['last_name']); ?></strong><small><?php echo parent_e($child['child_code']); ?></small></span></a>
			<?php endforeach; ?>
		</div>
	</div>
</div>

<div class="parent-appt-tab-panel is-active" data-appointment-panel="upcoming">
<?php if ($nextAppointment !== null): ?>
<?php
	$dt = new DateTimeImmutable((string)$nextAppointment['scheduled_at']);
	$statusClass = parent_status_class((string)$nextAppointment['status']);
	$statusLabel = ucfirst((string)$nextAppointment['status']);
?>
<section class="parent-appt-upcoming" data-appointment-id="<?php echo (int)$nextAppointment['id']; ?>">
	<div class="parent-appt-upcoming-body">
		<div class="parent-appt-upcoming-label">Upcoming</div>
		<div class="parent-appt-upcoming-date"><?php echo parent_e($dt->format('F j, Y')); ?></div>
		<div class="parent-appt-upcoming-time"><?php echo parent_e($dt->format('g:i A')); ?></div>
		<div class="parent-appt-upcoming-type"><?php echo parent_e(ucfirst((string)($nextAppointment['appointment_type'] ?? 'Regular'))); ?></div>
		<div class="parent-appt-upcoming-child">
			<?php echo parent_e($nextAppointment['first_name'] . ' ' . $nextAppointment['last_name']); ?>
		</div>
		<div class="parent-appt-upcoming-status">
			<span class="admin-pill <?php echo $statusClass; ?>"><?php echo parent_e($statusLabel); ?></span>
		</div>
	</div>
	<button type="button" class="admin-btn-secondary parent-appt-view-btn" data-appointment-id="<?php echo (int)$nextAppointment['id']; ?>">View Details</button>
</section>
<?php else: ?>
	<div class="parent-appt-empty">No upcoming appointments yet.</div>
<?php endif; ?>
</div>

<div class="parent-appt-tab-panel" data-appointment-panel="past">
<?php if (!empty($past)): ?>
<div class="parent-appt-divider"><span>Past Appointments</span></div>

<div class="parent-appt-list">
	<?php foreach ($past as $appt):
		$dt = new DateTimeImmutable((string)$appt['scheduled_at']);
		$statusClass = parent_status_class((string)$appt['status']);
		$statusLabel = ucfirst((string)$appt['status']);
		$isCompleted = (string)$appt['status'] === 'completed';
	?>
	<div class="parent-appt-row" data-appointment-id="<?php echo (int)$appt['id']; ?>">
		<div class="parent-appt-row-icon <?php echo $isCompleted ? 'is-done' : ''; ?>">
			<?php if ($isCompleted): ?>
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
			<?php else: ?>
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
			<?php endif; ?>
		</div>
		<div class="parent-appt-row-info">
			<div class="parent-appt-row-date"><?php echo parent_e($dt->format('F j, Y')); ?></div>
			<div class="parent-appt-row-type"><?php echo parent_e(ucfirst((string)($appt['appointment_type'] ?? 'Regular'))); ?></div>
			<div class="parent-appt-row-child"><?php echo parent_e($appt['first_name'] . ' ' . $appt['last_name']); ?></div>
		</div>
		<div class="parent-appt-row-status">
			<span class="admin-pill <?php echo $statusClass; ?>"><?php echo parent_e($statusLabel); ?></span>
		</div>
	</div>
	<?php endforeach; ?>
</div>
<?php else: ?>
<div class="parent-appt-empty">No past appointments yet.</div>
<?php endif; ?>
</div>

<div class="admin-modal-overlay" id="apptModal">
	<div class="admin-modal" style="max-width:480px;">
		<div class="admin-modal-head">
			<h3>Appointment Details</h3>
			<button class="admin-modal-close" id="apptModalClose" type="button">&times;</button>
		</div>
		<div class="appt-modal-body">
			<div class="appt-modal-header">
				<div class="appt-modal-date" id="modalDate"></div>
				<div class="appt-modal-time" id="modalTime"></div>
			</div>
			<div class="appt-modal-type" id="modalType"></div>
			<div class="appt-modal-rows">
				<div class="appt-modal-row">
					<span class="appt-modal-label">Child</span>
					<span class="appt-modal-value" id="modalChild"></span>
				</div>
				<div class="appt-modal-row">
					<span class="appt-modal-label">Location</span>
					<span class="appt-modal-value" id="modalLocation"></span>
				</div>
				<div class="appt-modal-row">
					<span class="appt-modal-label">Nutritionist</span>
					<span class="appt-modal-value" id="modalNutritionist"></span>
				</div>
				<div class="appt-modal-row" id="modalBarangayRow">
					<span class="appt-modal-label">Barangay</span>
					<span class="appt-modal-value" id="modalBarangay"></span>
				</div>
				<div class="appt-modal-row">
					<span class="appt-modal-label">Status</span>
					<span class="appt-modal-value"><span class="admin-pill" id="modalStatus"></span></span>
				</div>
			</div>
			<div class="appt-modal-notes" id="modalNotesSection">
				<div class="appt-modal-notes-label">Appointment Notes</div>
				<p class="appt-modal-notes-text" id="modalNotes"></p>
			</div>
			<div class="appt-modal-cancel" id="modalCancelSection">
				<form method="post" action="<?php echo parent_e(app_url('/parent/appointments.php')); ?>" onsubmit="return confirm('Cancel this appointment?');">
					<input type="hidden" name="action" value="cancel">
					<input type="hidden" name="id" id="modalCancelId">
					<button class="admin-btn-secondary appt-modal-cancel-btn" type="submit">Cancel Appointment</button>
				</form>
			</div>
		</div>
	</div>
</div>

<script>
(function () {
	var childPicker = document.getElementById('appointment-child-picker');
	var childOpen = document.querySelector('[data-appointment-child-open]');
	if (childPicker && childOpen) {
		var closePicker = function () { childPicker.hidden = true; document.body.classList.remove('parent-picker-open'); childOpen.focus(); };
		childOpen.addEventListener('click', function () { childPicker.hidden = false; document.body.classList.add('parent-picker-open'); });
		childPicker.querySelectorAll('[data-appointment-child-close]').forEach(function (button) { button.addEventListener('click', closePicker); });
		document.addEventListener('keydown', function (event) { if (!childPicker.hidden && event.key === 'Escape') closePicker(); });
	}

	var appointmentTabs = document.querySelectorAll('[data-appointment-tab]');
	var appointmentPanels = document.querySelectorAll('[data-appointment-panel]');
	appointmentTabs.forEach(function (tab) {
		tab.addEventListener('click', function () {
			var target = tab.getAttribute('data-appointment-tab');
			appointmentTabs.forEach(function (item) { item.classList.toggle('is-active', item === tab); });
			appointmentPanels.forEach(function (panel) { panel.classList.toggle('is-active', panel.getAttribute('data-appointment-panel') === target); });
		});
	});

	var data = <?php echo json_encode($allJson); ?>;
	var overlay = document.getElementById('apptModal');
	var closeBtn = document.getElementById('apptModalClose');

	function openModal(id) {
		var a = data[id];
		if (!a) return;
		document.getElementById('modalDate').textContent = a.date;
		document.getElementById('modalTime').textContent = a.time;
		document.getElementById('modalType').textContent = a.type;
		document.getElementById('modalChild').textContent = a.child;
		document.getElementById('modalLocation').textContent = a.location;
		document.getElementById('modalNutritionist').textContent = a.nutritionist;
		var barangayRow = document.getElementById('modalBarangayRow');
		var barangayEl = document.getElementById('modalBarangay');
		if (a.barangay) { barangayRow.style.display = ''; barangayEl.textContent = a.barangay; }
		else { barangayRow.style.display = 'none'; }
		document.getElementById('modalStatus').textContent = a.status;
		document.getElementById('modalStatus').className = 'admin-pill ' + a.status_class;
		var notesSection = document.getElementById('modalNotesSection');
		var notesEl = document.getElementById('modalNotes');
		if (a.notes) { notesSection.style.display = ''; notesEl.textContent = a.notes; }
		else { notesSection.style.display = 'none'; }
		var cancelSection = document.getElementById('modalCancelSection');
		if (a.can_cancel) {
			cancelSection.style.display = '';
			document.getElementById('modalCancelId').value = a.id;
		} else {
			cancelSection.style.display = 'none';
		}
		overlay.classList.add('is-open');
		document.body.style.overflow = 'hidden';
	}

	function closeModal() {
		overlay.classList.remove('is-open');
		document.body.style.overflow = '';
	}

	document.querySelectorAll('[data-appointment-id]').forEach(function (el) {
		el.addEventListener('click', function (e) {
			if (e.target.closest('form') || e.target.closest('button[type="submit"]')) return;
			openModal(parseInt(this.getAttribute('data-appointment-id'), 10));
		});
	});

	if (closeBtn) closeBtn.addEventListener('click', closeModal);
	if (overlay) overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeModal(); });
})();
</script>

<?php
parent_layout_end();


<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

$user = nutritionist_require_access();

if (!nutritionist_can_write()) {
	admin_redirect('/nutritionist/appointments.php', ['notice' => 'You do not have permission to manage appointments.', 'type' => 'error']);
}

$editId = (int)($_GET['id'] ?? 0);
$editAppointment = null;
$preselectedChildId = (int)($_GET['child'] ?? 0);

if ($editId > 0) {
	$editParams = [$editId];
	$editScope = nutritionist_scope_fragment($user, 'c.barangay_id', $editParams);
	$editAppointment = admin_fetch_one(
		"SELECT a.id, a.child_id, a.scheduled_at, a.status, a.notes, a.location, a.appointment_type, a.followup_track
		 FROM appointments a
		 INNER JOIN children c ON c.id = a.child_id
		 WHERE a.id = ? AND {$editScope}
		 LIMIT 1",
		str_repeat('i', count($editParams)),
		$editParams
	);

	if ($editAppointment === null) {
		admin_redirect('/nutritionist/appointments.php', ['notice' => 'Appointment not found in your area.', 'type' => 'error']);
	}

	if (($editAppointment['appointment_type'] ?? 'regular') === 'followup') {
		admin_redirect('/nutritionist/appointments.php', ['notice' => 'Automatic follow-ups cannot be edited manually. Verify the mandatory re-measurement instead.', 'type' => 'error']);
	}

	$preselectedChildId = (int)$editAppointment['child_id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

	$action = (string)($_POST['action'] ?? '');

	nutritionist_require_write();

	$childId = (int)($_POST['child_id'] ?? 0);
	$scheduledAt = trim((string)($_POST['scheduled_at'] ?? ''));
	$notes = trim((string)($_POST['notes'] ?? ''));
	$location = trim((string)($_POST['location'] ?? ''));
	$status = trim((string)($_POST['status'] ?? 'pending'));

	if ($childId <= 0 || $scheduledAt === '') {
		admin_redirect($editId > 0 ? '/nutritionist/appointment_form.php?id=' . $editId : '/nutritionist/appointment_form.php', ['notice' => 'Child and schedule are required.', 'type' => 'error']);
	}

	if ($location === '') {
		$location = 'Barangay Health Center';
	}

	if (!in_array($status, ['pending', 'confirmed', 'completed', 'cancelled'], true)) {
		$status = 'pending';
	}

	$childParams = [$childId];
	$childScope = nutritionist_scope_fragment($user, 'c.barangay_id', $childParams);
	$childRecord = admin_fetch_one(
		"SELECT c.id, c.parent_id
		 FROM children c
		 WHERE c.id = ? AND {$childScope}
		 LIMIT 1",
		str_repeat('i', count($childParams)),
		$childParams
	);

	if ($childRecord === null) {
		admin_redirect($editId > 0 ? '/nutritionist/appointment_form.php?id=' . $editId : '/nutritionist/appointment_form.php', ['notice' => 'Select a valid child from your list.', 'type' => 'error']);
	}

	$parentId = (int)$childRecord['parent_id'];

	if ($action === 'update' && $editId > 0) {
		$ok = admin_execute(
			'UPDATE appointments SET child_id = ?, parent_id = ?, scheduled_at = ?, status = ?, notes = ?, location = ? WHERE id = ? AND appointment_type = \'regular\'',
			'iissssi',
			[$childId, $parentId, $scheduledAt, $status, $notes, $location, $editId]
		);

		admin_redirect('/nutritionist/appointments.php', $ok ? ['notice' => 'Appointment updated.'] : ['notice' => 'Appointment could not be updated.', 'type' => 'error']);
	}

	if ($action === 'create' || $action === '') {
		$ok = admin_execute(
			'INSERT INTO appointments (child_id, parent_id, nutritionist_id, scheduled_at, status, notes, location)
			 VALUES (?, ?, ?, ?, ?, ?, ?)',
			'iiissss',
			[$childId, $parentId, (int)$user['id'], $scheduledAt, 'pending', $notes, $location]
		);

		admin_redirect('/nutritionist/appointments.php', $ok ? ['notice' => 'Appointment scheduled.'] : ['notice' => 'Appointment could not be scheduled.', 'type' => 'error']);
	}

	admin_redirect('/nutritionist/appointments.php', ['notice' => 'No action was performed.', 'type' => 'error']);
}


$childrenParams = [];
$childrenScope = nutritionist_scope_fragment($user, 'c.barangay_id', $childrenParams);
$children = admin_fetch_all(
	"SELECT c.id, c.first_name, c.last_name, c.parent_id, p.name AS parent_name, p.parent_type, p.phone AS parent_phone, p.status AS parent_status
	 FROM children c
	 INNER JOIN parents p ON p.id = c.parent_id
	 WHERE {$childrenScope}
	 ORDER BY c.last_name ASC, c.first_name ASC",
	str_repeat('i', count($childrenParams)),
	$childrenParams
);

$isEdit = $editAppointment !== null;

$defaultLocation = $isEdit ? (string)$editAppointment['location'] : 'Barangay Health Center';
$defaultNotes = $isEdit ? (string)$editAppointment['notes'] : '';
$defaultScheduledAt = $isEdit
	? (new DateTimeImmutable((string)$editAppointment['scheduled_at']))->format('Y-m-d\TH:i')
	: (new DateTimeImmutable('+1 day'))->setTime(9, 0)->format('Y-m-d\TH:i');
$defaultStatus = $isEdit ? (string)$editAppointment['status'] : 'pending';

$actions = '<a class="admin-btn-secondary" href="'
	. nutritionist_e(app_url('/nutritionist/appointments.php'))
	. '">' . admin_action_icon('back') . ' Appointments</a>';

nutritionist_layout_start(
	$isEdit ? 'Edit Appointment' : 'Schedule Appointment',
	$isEdit ? 'Update the date, location, status, or notes for this visit.' : 'Create a new follow-up visit for a child in your scope.',
	'appointments',
	$actions
);
?>
<section class="nutritionist-panel">
	<div class="nutritionist-form-head" style="margin-bottom:16px;">
		<div>
			<h2 class="admin-section-title" style="margin-bottom:2px;"><?php echo $isEdit ? 'Edit Appointment' : 'Schedule Appointment'; ?></h2>
			<p class="admin-section-subtitle"><?php echo $isEdit ? 'Changes apply immediately to this manual appointment.' : 'New requests always start as pending until confirmed.'; ?></p>
		</div>
	</div>

	<form method="post" class="nutritionist-form-grid" id="appointment-create-form" action="<?php echo nutritionist_e(app_url('/nutritionist/appointment_form.php' . ($isEdit ? '?id=' . $editId : ''))); ?>">
		<input type="hidden" name="action" value="<?php echo $isEdit ? 'update' : 'create'; ?>">
		<label class="admin-field">
			<span>Child<span class="admin-required">*</span></span>
			<select name="child_id" id="appointment-child-select" required data-appointment-guardian-source>
				<option value="">-- Select Child --</option>
				<?php foreach ($children as $child): ?>
					<option
						value="<?php echo (int)$child['id']; ?>"
						<?php echo (
							$preselectedChildId > 0
							&& (int)$child['id'] === $preselectedChildId
						) ? 'selected' : ''; ?>
						data-parent-name="<?php echo nutritionist_e((string)$child['parent_name']); ?>"
						data-parent-type="<?php echo nutritionist_e((string)$child['parent_type']); ?>"
						data-parent-phone="<?php echo nutritionist_e((string)($child['parent_phone'] ?? '')); ?>"
					><?php echo nutritionist_e($child['first_name'] . ' ' . $child['last_name']); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label class="admin-field">
			<span>Parent/Guardian</span>
			<input type="text" id="appointment-guardian-display" value="Select a child first" disabled style="color:var(--admin-muted);">
		</label>
		<label class="admin-field">
			<span>Schedule<span class="admin-required">*</span></span>
			<input type="datetime-local" name="scheduled_at" required value="<?php echo nutritionist_e($defaultScheduledAt); ?>">
		</label>
		<label class="admin-field">
			<span>Location</span>
			<input name="location" placeholder="e.g. Barangay Health Center" value="<?php echo nutritionist_e($defaultLocation); ?>">
		</label>
		<?php if ($isEdit): ?>
		<label class="admin-field">
			<span>Status</span>
			<select name="status">
				<?php foreach (['pending', 'confirmed', 'completed', 'cancelled'] as $st): ?>
					<option value="<?php echo nutritionist_e($st); ?>" <?php echo $defaultStatus === $st ? 'selected' : ''; ?>><?php echo nutritionist_e(ucfirst($st)); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<?php endif; ?>
		<label class="admin-field" style="grid-column: 1 / -1;">
			<span>Notes</span>
			<textarea name="notes" rows="3" placeholder="Optional follow-up notes"><?php echo nutritionist_e($defaultNotes); ?></textarea>
		</label>
		<div class="admin-field" style="align-content:end;grid-column:1 / -1;">
			<span>&nbsp;</span>
			<div class="admin-actions">
				<button class="admin-btn-secondary" type="button" onclick="window.location.href='<?php echo nutritionist_e(app_url('/nutritionist/appointments.php')); ?>'">Cancel</button>
				<button class="admin-btn" type="submit"><?php echo admin_action_icon('save'); ?> <?php echo $isEdit ? 'Save changes' : 'Save appointment'; ?></button>
			</div>
		</div>
	</form>
	<p class="admin-mini" style="margin-top:8px;">The parent/guardian is set automatically from the child's own record — you can't attach an appointment to any guardian other than that child's.</p>
</section>

<script>
(function () {
	var childSelect = document.getElementById('appointment-child-select');
	var guardianDisplay = document.getElementById('appointment-guardian-display');

	if (!childSelect || !guardianDisplay) {
		return;
	}

	function updateGuardianDisplay() {
		var option = childSelect.options[childSelect.selectedIndex];

		if (!option || !option.value) {
			guardianDisplay.value = 'Select a child first';
			return;
		}

		var name = option.getAttribute('data-parent-name') || 'Unknown guardian';
		var type = option.getAttribute('data-parent-type') || '';
		var phone = option.getAttribute('data-parent-phone') || '';
		var parts = [name];

		if (type) {
			parts.push(type);
		}

		if (phone) {
			parts.push(phone);
		}

		guardianDisplay.value = parts.join(' · ');
	}

	childSelect.addEventListener('change', updateGuardianDisplay);
	updateGuardianDisplay();
})();
</script>
<?php
nutritionist_layout_end();
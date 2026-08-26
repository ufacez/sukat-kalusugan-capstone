<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

$user = nutritionist_require_access();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

	$action = (string)($_POST['action'] ?? '');

	if ($action !== 'create') {
		admin_redirect('/nutritionist/appointments.php', ['notice' => 'No action was performed.', 'type' => 'error']);
	}

	$childId = (int)($_POST['child_id'] ?? 0);
	$scheduledAt = trim((string)($_POST['scheduled_at'] ?? ''));
	$notes = trim((string)($_POST['notes'] ?? ''));

	if ($childId <= 0 || $scheduledAt === '') {
		admin_redirect('/nutritionist/appointment_form.php', ['notice' => 'Child and schedule are required.', 'type' => 'error']);
	}

	// The guardian is never taken from the submitted form. It is always
	// looked up from the child's own record so an appointment can only
	// ever be booked with that child's actual parent/guardian, and so a
	// nutritionist can't accidentally (or a tampered request can't
	// deliberately) attach the wrong guardian to a child.
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
		admin_redirect('/nutritionist/appointment_form.php', ['notice' => 'Select a valid child from your list.', 'type' => 'error']);
	}

	$parentId = (int)$childRecord['parent_id'];

	$ok = admin_execute(
		'INSERT INTO appointments (child_id, parent_id, nutritionist_id, scheduled_at, status, notes)
		 VALUES (?, ?, ?, ?, ?, ?)',
		'iiisss',
		[$childId, $parentId, (int)$user['id'], $scheduledAt, 'pending', $notes]
	);

	admin_redirect('/nutritionist/appointments.php', $ok ? ['notice' => 'Appointment scheduled.'] : ['notice' => 'Appointment could not be scheduled.', 'type' => 'error']);
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

$preselectedChildId = (int)($_GET['child'] ?? 0);

$actions = '<a class="admin-btn-secondary" href="'
	. nutritionist_e(app_url('/nutritionist/appointments.php'))
	. '">' . admin_action_icon('back') . ' Appointments</a>';

nutritionist_layout_start('Schedule Appointment', 'Create a new follow-up visit for a child in your scope.', 'appointments', $actions);
?>
<section class="nutritionist-panel">
	<div class="nutritionist-form-head" style="margin-bottom:16px;">
		<div>
			<h2 class="admin-section-title" style="margin-bottom:2px;">Schedule Appointment</h2>
			<p class="admin-section-subtitle">New requests always start as pending until confirmed.</p>
		</div>
	</div>

	<form method="post" class="nutritionist-form-grid" id="appointment-create-form" action="<?php echo nutritionist_e(app_url('/nutritionist/appointment_form.php')); ?>">
		<input type="hidden" name="action" value="create">
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
			<input type="datetime-local" name="scheduled_at" required>
		</label>
		<label class="admin-field">
			<span>Notes</span>
			<input name="notes" placeholder="Optional follow-up notes">
		</label>
		<div class="admin-field" style="align-content:end;grid-column:1 / -1;">
			<span>&nbsp;</span>
			<div class="admin-actions">
				<button class="admin-btn" type="submit"><?php echo admin_action_icon('save'); ?> Save appointment</button>
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

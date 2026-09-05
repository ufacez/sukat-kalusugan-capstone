<?php

require_once __DIR__ . '/../includes/parent_helpers.php';

$user = parent_require_access();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = (string)($_POST['action'] ?? '');

	if ($action !== 'create') {
		admin_redirect('/parent/appointments.php', ['notice' => 'No action was performed.', 'type' => 'error']);
	}

	$childId = (int)($_POST['child_id'] ?? 0);
	$nutritionistId = (int)($_POST['nutritionist_id'] ?? 0);
	$scheduledAt = trim((string)($_POST['scheduled_at'] ?? ''));
	$notes = trim((string)($_POST['notes'] ?? ''));

	if ($childId <= 0 || $nutritionistId <= 0 || $scheduledAt === '') {
		admin_redirect('/parent/appointment_form.php', ['notice' => 'Child, nutritionist, and schedule are required.', 'type' => 'error']);
	}

	$child = admin_fetch_one('SELECT id FROM children WHERE id = ? AND parent_id = ? LIMIT 1', 'ii', [$childId, (int)$user['id']]);

	if ($child === null) {
		admin_redirect('/parent/appointment_form.php', ['notice' => 'The selected child is not linked to your account.', 'type' => 'error']);
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
		admin_redirect('/parent/appointment_form.php', ['notice' => 'Please select an active nutritionist.', 'type' => 'error']);
	}

	$ok = admin_execute(
		'INSERT INTO appointments (child_id, parent_id, nutritionist_id, scheduled_at, status, notes)
		 VALUES (?, ?, ?, ?, ?, ?)',
		'iiisss',
		[$childId, (int)$user['id'], $nutritionistId, $scheduledAt, 'pending', $notes]
	);

	admin_redirect('/parent/appointments.php', $ok ? ['notice' => 'Appointment requested successfully.'] : ['notice' => 'Appointment could not be created.', 'type' => 'error']);
}

$children = admin_fetch_all(
	'SELECT id, first_name, last_name, child_code
	 FROM children
	 WHERE parent_id = ?
	 ORDER BY last_name ASC, first_name ASC',
	'i',
	[(int)$user['id']]
);

if ($children === []) {
	admin_redirect(
		'/parent/children.php',
		[
			'notice' => 'You need a linked child record before requesting an appointment.',
			'type' => 'error'
		]
	);
}

$nutritionists = admin_fetch_all(
	'SELECT u.id, u.name, b.name AS barangay
	 FROM users u
	 INNER JOIN roles r ON r.id = u.role_id
	 LEFT JOIN barangays b ON b.id = u.barangay_id
	 WHERE r.name = ? AND u.status = ?
	 ORDER BY u.name ASC',
	'ss',
	['nutritionist', 'active']
);

$actions = '<a class="admin-btn-secondary" href="'
	. parent_e(app_url('/parent/appointments.php'))
	. '">' . admin_action_icon('back') . ' Appointments</a>';

parent_layout_start('Request Appointment', 'Submit a follow-up request for any linked child.', 'appointments', $actions, 'Request Appointment');
?>
<section class="parent-panel">
	<div class="parent-form-head" style="margin-bottom:12px;">
		<div>
			<h2 class="admin-section-title" style="margin-bottom:2px;">Request Appointment</h2>
			<p class="admin-section-subtitle">New requests always start as pending until the nutritionist confirms.</p>
		</div>
	</div>

	<form method="post" class="parent-form-grid" action="<?php echo parent_e(app_url('/parent/appointment_form.php')); ?>">
		<input type="hidden" name="action" value="create">
		<label class="admin-field">
			<span>Child<span class="admin-required">*</span></span>
			<input type="hidden" name="child_id" id="appointment-child-id" required>
			<div class="parent-appointment-child-picker" role="group" aria-label="Choose a child">
				<?php foreach ($children as $child): ?>
					<button type="button" class="parent-appointment-child-option" data-child-id="<?php echo (int)$child['id']; ?>">
						<span class="parent-child-option-avatar" aria-hidden="true"><?php echo parent_e(strtoupper(substr((string)$child['first_name'], 0, 1))); ?></span>
						<span><strong><?php echo parent_e($child['first_name'] . ' ' . $child['last_name']); ?></strong><small><?php echo parent_e($child['child_code']); ?></small></span>
					</button>
				<?php endforeach; ?>
			</div>
		</label>
		<label class="admin-field">
			<span>Nutritionist<span class="admin-required">*</span></span>
			<select name="nutritionist_id" required>
				<option value="">-- Select nutritionist --</option>
				<?php foreach ($nutritionists as $nutritionist): ?>
					<option value="<?php echo (int)$nutritionist['id']; ?>"><?php echo parent_e($nutritionist['name'] . ' · ' . (string)($nutritionist['barangay'] ?? '')); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label class="admin-field">
			<span>Preferred schedule<span class="admin-required">*</span></span>
			<input type="datetime-local" name="scheduled_at" required>
		</label>
		<label class="admin-field">
			<span>Notes</span>
			<input name="notes" placeholder="Optional reason for the visit">
		</label>
		<div class="admin-field" style="align-content:end;grid-column:1 / -1;">
			<span>&nbsp;</span>
			<div class="admin-actions">
				<button class="admin-btn" type="submit"><?php echo admin_action_icon('add'); ?> Submit request</button>
			</div>
		</div>
	</form>
</section>
<script>
(function () {
	var input = document.getElementById('appointment-child-id');
	var options = document.querySelectorAll('.parent-appointment-child-option');
	options.forEach(function (option) {
		option.addEventListener('click', function () {
			options.forEach(function (item) { item.classList.remove('is-selected'); });
			option.classList.add('is-selected');
			input.value = option.getAttribute('data-child-id');
		});
	});
})();
</script>
<?php
parent_layout_end();

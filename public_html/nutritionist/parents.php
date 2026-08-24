<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

$user = nutritionist_require_access();
$parentTypes = ['Father', 'Mother', 'Guardian', 'Grandparent', 'Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = (string)($_POST['action'] ?? '');
	$parentId = (int)($_POST['id'] ?? 0);

	/*
	 * DELETE
	 */
	if ($action === 'delete' && $parentId > 0) {
		$target = admin_fetch_one('SELECT id, email FROM parents WHERE id = ? LIMIT 1', 'i', [$parentId]);

		if ($target === null) {
			admin_redirect('/nutritionist/parents.php', ['notice' => 'Parent not found.', 'type' => 'error']);
		}

		// children.parent_id and appointments.parent_id are RESTRICT (not
		// CASCADE), so check for linked children first and give a clear,
		// actionable message instead of a raw FK constraint error.
		$linkedChildren = admin_scalar('SELECT COUNT(*) FROM children WHERE parent_id = ?', 'i', [$parentId]);

		if ($linkedChildren > 0) {
			admin_redirect('/nutritionist/parents.php', [
				'notice' => 'Cannot delete this parent — ' . $linkedChildren . ' child record(s) are still linked to it. Reassign or remove those children first.',
				'type' => 'error',
			]);
		}

		$ok = admin_execute('DELETE FROM parents WHERE id = ?', 'i', [$parentId]);

		if ($ok) {
			$actor = current_user();
			log_action($actor['id'] ?? null, 'DELETE_PARENT', 'warning', 'Deleted parent account ' . $target['email'] . ' (' . $parentId . ')');
		}

		admin_redirect('/nutritionist/parents.php', $ok ? ['notice' => 'Parent deleted successfully.'] : ['notice' => 'Parent could not be deleted.', 'type' => 'error']);
	}

	/*
	 * CREATE / UPDATE
	 */
	if ($action === 'create' || $action === 'update') {
		$firstName = trim((string)($_POST['first_name'] ?? ''));
		$middleName = trim((string)($_POST['middle_name'] ?? ''));
		$lastName = trim((string)($_POST['last_name'] ?? ''));
		$email = trim((string)($_POST['email'] ?? ''));
		$phone = trim((string)($_POST['phone'] ?? ''));
		$address = trim((string)($_POST['address'] ?? ''));
		$parentType = trim((string)($_POST['parent_type'] ?? 'Guardian'));
		$barangayIdRaw = trim((string)($_POST['barangay_id'] ?? ''));
		$barangayId = $barangayIdRaw !== '' ? (int)$barangayIdRaw : null;
		$status = trim((string)($_POST['status'] ?? 'active'));
		$password = (string)($_POST['password'] ?? '');
		$passwordConfirm = (string)($_POST['password_confirm'] ?? '');
		$redirectBack = '/nutritionist/parents.php' . ($action === 'update' ? '?edit=' . $parentId : '');

		if (
			!admin_is_valid_name_part($firstName, true)
			|| !admin_is_valid_name_part($middleName, false)
			|| !admin_is_valid_name_part($lastName, true)
		) {
			admin_redirect($redirectBack, ['notice' => 'Enter a valid first name and surname (letters only). Middle name is optional.', 'type' => 'error']);
		}

		$name = admin_combine_name($firstName, $middleName, $lastName);

		if (!in_array($status, ['active', 'inactive'], true)) {
			$status = 'active';
		}

		if (!in_array($parentType, $parentTypes, true)) {
			$parentType = 'Guardian';
		}

		if ($name === '' || $email === '') {
			admin_redirect('/nutritionist/parents.php', ['notice' => 'Name and email are required.', 'type' => 'error']);
		}

		if (!admin_is_valid_ph_mobile($phone)) {
			admin_redirect($redirectBack, ['notice' => 'Enter a valid 11-digit PH mobile number starting with 09.', 'type' => 'error']);
		}

		$phone = preg_replace('/[^0-9]/', '', $phone);

		if ($action === 'create' && $password === '') {
			admin_redirect($redirectBack, ['notice' => 'Password is required.', 'type' => 'error']);
		}

		if ($password !== '' && !admin_is_strong_password($password)) {
			admin_redirect($redirectBack, ['notice' => 'Password must be at least 8 characters and include an uppercase letter, a lowercase letter, a number, and a special character.', 'type' => 'error']);
		}

		if ($password !== '' && $password !== $passwordConfirm) {
			admin_redirect($redirectBack, ['notice' => 'Password and confirm password do not match.', 'type' => 'error']);
		}

		if ($action === 'create') {
			$hash = password_hash($password, PASSWORD_DEFAULT);
			$ok = admin_execute(
				'INSERT INTO parents (name, email, password_hash, parent_type, phone, address, barangay_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
				'ssssssis',
				[$name, $email, $hash, $parentType, $phone, $address, $barangayId, $status]
			);

			if ($ok) {
				$actor = current_user();
				log_action($actor['id'] ?? null, 'CREATE_PARENT', 'info', 'Created parent account ' . $email);
			}

			admin_redirect('/nutritionist/parents.php', $ok ? ['notice' => 'Parent added.'] : ['notice' => 'Parent could not be added. Check for a duplicate email.', 'type' => 'error']);
		}

		if ($action === 'update' && $parentId > 0) {
			if ($password !== '') {
				$hash = password_hash($password, PASSWORD_DEFAULT);
				$ok = admin_execute(
					'UPDATE parents SET name = ?, email = ?, password_hash = ?, parent_type = ?, phone = ?, address = ?, barangay_id = ?, status = ? WHERE id = ?',
					'ssssssisi',
					[$name, $email, $hash, $parentType, $phone, $address, $barangayId, $status, $parentId]
				);
			} else {
				$ok = admin_execute(
					'UPDATE parents SET name = ?, email = ?, parent_type = ?, phone = ?, address = ?, barangay_id = ?, status = ? WHERE id = ?',
					'sssssisi',
					[$name, $email, $parentType, $phone, $address, $barangayId, $status, $parentId]
				);
			}

			if ($ok) {
				$actor = current_user();
				log_action($actor['id'] ?? null, 'UPDATE_PARENT', 'info', 'Updated parent ' . $email . ' (' . $parentId . ')');
			}

			admin_redirect('/nutritionist/parents.php', $ok ? ['notice' => 'Parent updated.'] : ['notice' => 'Parent could not be updated. Check for a duplicate email.', 'type' => 'error']);
		}
	}
}

$editingId = (int)($_GET['edit'] ?? 0);
$editingParent = $editingId > 0 ? admin_fetch_one(
	'SELECT id, name, email, parent_type, phone, address, barangay_id, status
	 FROM parents
	 WHERE id = ?
	 LIMIT 1',
	'i',
	[$editingId]
) : null;
$editingNameParts = admin_split_full_name($editingParent['name'] ?? '');

$params = [];
$scope = nutritionist_scope_fragment($user, 'c.barangay_id', $params);
$parents = admin_fetch_all(
	"SELECT
		p.id,
		p.name,
		p.email,
		p.parent_type,
		p.phone,
		p.address,
		p.barangay_id,
		bg.name AS barangay,
		p.status,
		COUNT(DISTINCT c.id) AS children_count,
		COUNT(DISTINCT a.id) AS appointment_count,
		SUM(CASE WHEN lm.nutritional_status IS NOT NULL AND lm.nutritional_status NOT IN ('Normal', 'Overweight') THEN 1 ELSE 0 END) AS follow_up_count,
		MAX(lm.measurement_date) AS latest_measurement
	 FROM parents p
	 LEFT JOIN barangays bg ON bg.id = p.barangay_id
	 LEFT JOIN children c ON c.parent_id = p.id AND {$scope}
	 LEFT JOIN appointments a ON a.parent_id = p.id
	 LEFT JOIN measurements lm ON lm.id = (
		SELECT m2.id
		FROM measurements m2
		WHERE m2.child_id = c.id
		ORDER BY m2.measurement_date DESC, m2.id DESC
		LIMIT 1
	 )
	 GROUP BY p.id, p.name, p.email, p.parent_type, p.phone, p.address, p.barangay_id, bg.name, p.status
	 ORDER BY p.name ASC",
	str_repeat('i', count($params)),
	$params
);

$barangays = admin_barangay_options();

$activeCount = count(array_filter($parents, static fn(array $parent): bool => (string)$parent['status'] === 'active'));
$totalChildren = array_sum(array_map(static fn(array $parent): int => (int)$parent['children_count'], $parents));
$totalAppointments = array_sum(array_map(static fn(array $parent): int => (int)$parent['appointment_count'], $parents));
$atRiskCount = count(array_filter($parents, static fn(array $parent): bool => (int)$parent['follow_up_count'] > 0));

$actions = '<a class="admin-btn" href="#parent-form">Add parent</a>';

nutritionist_layout_start('Parents', 'Linked guardians and household contact information.', 'parents', $actions);
?>
<section class="nutritionist-stat-grid">
	<article class="nutritionist-stat-card is-featured">
		<div class="nutritionist-stat-label">Parents</div>
		<div class="admin-stat-value"><?php echo count($parents); ?></div>
		<div class="admin-stat-note"><?php echo $activeCount; ?> active accounts</div>
	</article>
	<article class="nutritionist-stat-card">
		<div class="nutritionist-stat-label">Children Linked</div>
		<div class="admin-stat-value"><?php echo $totalChildren; ?></div>
		<div class="admin-stat-note">Households in scope</div>
	</article>
	<article class="nutritionist-stat-card">
		<div class="nutritionist-stat-label">Appointments</div>
		<div class="admin-stat-value"><?php echo $totalAppointments; ?></div>
		<div class="admin-stat-note">Parent-requested and nutritionist-created visits</div>
	</article>
	<article class="nutritionist-stat-card">
		<div class="nutritionist-stat-label">At-Risk Links</div>
		<div class="admin-stat-value"><?php echo $atRiskCount; ?></div>
		<div class="admin-stat-note">Parents with follow-up children</div>
	</article>
</section>

<section class="nutritionist-panel">
	<div class="nutritionist-table-head" style="margin-bottom:12px;">
		<div>
			<h2 class="admin-section-title" style="margin-bottom:2px;">Parent Directory</h2>
			<p class="admin-section-subtitle">Search, update, and review household records.</p>
		</div>
		<input class="admin-search" data-admin-filter="#parents-table" type="search" placeholder="Search parents" style="min-width:240px;">
	</div>

	<div class="nutritionist-table-wrap">
		<table class="nutritionist-table" id="parents-table">
			<thead>
				<tr>
					<th>Name</th>
					<th>Type</th>
					<th>Email</th>
					<th>Phone</th>
					<th>Barangay</th>
					<th>Children</th>
					<th>Appointments</th>
					<th>Follow-up</th>
					<th>Latest measurement</th>
					<th>Status</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($parents as $parent): ?>
					<tr data-filter-text="<?php echo nutritionist_e(strtolower($parent['name'] . ' ' . $parent['parent_type'] . ' ' . $parent['email'] . ' ' . $parent['phone'] . ' ' . $parent['address'])); ?>">
						<td>
							<div style="font-weight:600;color:var(--admin-text);"><?php echo nutritionist_e($parent['name']); ?></div>
							<div class="admin-mini"><?php echo nutritionist_e((string)($parent['address'] ?? '')); ?></div>
						</td>
						<td style="color:var(--admin-muted);"><span class="admin-pill is-muted"><?php echo nutritionist_e($parent['parent_type']); ?></span></td>
						<td style="color:var(--admin-muted);"><?php echo nutritionist_e($parent['email']); ?></td>
						<td style="color:var(--admin-muted);"><?php echo nutritionist_e((string)($parent['phone'] ?? '')); ?></td>
						<td style="color:var(--admin-muted);"><?php echo nutritionist_e((string)($parent['barangay'] ?? '')); ?></td>
						<td style="color:var(--admin-muted);"><?php echo (int)$parent['children_count']; ?></td>
						<td style="color:var(--admin-muted);"><?php echo (int)$parent['appointment_count']; ?></td>
						<td style="color:var(--admin-muted);"><?php echo (int)($parent['follow_up_count'] ?? 0); ?></td>
						<td style="color:var(--admin-muted);"><?php echo nutritionist_e((string)($parent['latest_measurement'] ?? 'n/a')); ?></td>
						<td><span class="admin-pill <?php echo $parent['status'] === 'active' ? 'is-success' : 'is-muted'; ?>"><?php echo nutritionist_e(ucfirst($parent['status'])); ?></span></td>
						<td>
							<div class="admin-actions">
								<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/parents.php?edit=' . (int)$parent['id'])); ?>#parent-form">Edit</a>
								<form method="post" action="<?php echo nutritionist_e(app_url('/nutritionist/parents.php')); ?>" onsubmit="return confirm('Delete <?php echo nutritionist_e($parent['name']); ?>?');">
									<input type="hidden" name="action" value="delete">
									<input type="hidden" name="id" value="<?php echo (int)$parent['id']; ?>">
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

<section class="nutritionist-panel" id="parent-form" style="margin-top:18px;">
	<div class="nutritionist-form-head" style="margin-bottom:16px;">
		<div>
			<h2 class="admin-section-title" style="margin-bottom:2px;"><?php echo $editingParent ? 'Edit Parent' : 'Add Parent'; ?></h2>
			<p class="admin-section-subtitle"><?php echo $editingParent ? 'Update this parent account.' : 'Create a new guardian record for linked child accounts.'; ?></p>
		</div>
	</div>

	<form class="nutritionist-form-grid" method="post" data-validate-form action="<?php echo nutritionist_e(app_url('/nutritionist/parents.php')); ?>">
		<input type="hidden" name="action" value="<?php echo $editingParent ? 'update' : 'create'; ?>">
		<?php if ($editingParent): ?>
			<input type="hidden" name="id" value="<?php echo (int)$editingParent['id']; ?>">
		<?php endif; ?>

		<div class="admin-field-wide admin-flash is-error" data-validate-banner style="display:none;"></div>

		<div class="admin-field-wide">
			<div class="admin-field-row">
				<label class="admin-field">
					<span>First name<span class="admin-required">*</span></span>
					<input id="np_first_name" name="first_name" required maxlength="60" data-validate="name" data-label="First name" value="<?php echo nutritionist_e($editingNameParts['first']); ?>" placeholder="Juan">
					<span class="admin-field-message"></span>
				</label>
				<label class="admin-field">
					<span>Middle name</span>
					<input id="np_middle_name" name="middle_name" maxlength="60" data-validate="name" data-label="Middle name" value="<?php echo nutritionist_e($editingNameParts['middle']); ?>" placeholder="Reyes">
					<span class="admin-field-message"></span>
				</label>
				<label class="admin-field">
					<span>Surname<span class="admin-required">*</span></span>
					<input id="np_last_name" name="last_name" required maxlength="60" data-validate="name" data-label="Surname" value="<?php echo nutritionist_e($editingNameParts['last']); ?>" placeholder="Dela Cruz">
					<span class="admin-field-message"></span>
				</label>
			</div>
		</div>

		<label class="admin-field">
			<span>Email<span class="admin-required">*</span></span>
			<input id="np_email" type="email" name="email" required data-validate="email" value="<?php echo nutritionist_e($editingParent['email'] ?? ''); ?>" placeholder="juan@example.com">
			<span class="admin-field-message"></span>
		</label>
		<div class="admin-field-wide">
			<div class="admin-field-row">
				<label class="admin-field">
					<span>Mobile number<span class="admin-required">*</span></span>
					<input id="np_phone" name="phone" required data-validate="phone-ph" value="<?php echo nutritionist_e($editingParent['phone'] ?? ''); ?>" placeholder="09171234567">
					<span class="admin-field-message"></span>
				</label>
				<label class="admin-field">
					<span>Relationship<span class="admin-required">*</span></span>
					<select name="parent_type" required>
						<?php foreach ($parentTypes as $type): ?>
							<option value="<?php echo nutritionist_e($type); ?>" <?php echo (($editingParent['parent_type'] ?? 'Guardian') === $type) ? 'selected' : ''; ?>><?php echo nutritionist_e($type); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>
		</div>
		<label class="admin-field">
			<span>Barangay</span>
			<select name="barangay_id">
				<option value="">-- Not set --</option>
				<?php foreach ($barangays as $barangay): ?>
					<option value="<?php echo (int)$barangay['id']; ?>" <?php echo (int)($editingParent['barangay_id'] ?? 0) === (int)$barangay['id'] ? 'selected' : ''; ?>><?php echo nutritionist_e($barangay['name']); ?></option>
				<?php endforeach; ?>
			</select>
		</label>

		<div class="admin-field-wide">
			<span style="font-size:0.88rem;font-weight:700;color:var(--admin-text);">Home address</span>
			<div class="admin-address-picker" data-psgc-picker data-psgc-address-target="np_address">
				<label class="admin-field">
					<span>Province</span>
					<select data-psgc="province"><option value="">Loading provinces…</option></select>
				</label>
				<label class="admin-field">
					<span>City / Municipality</span>
					<select data-psgc="city" disabled><option value="">-- Select province first --</option></select>
				</label>
				<label class="admin-field">
					<span>Barangay</span>
					<select data-psgc="barangay" disabled><option value="">-- Select city/municipality first --</option></select>
				</label>
			</div>
			<label class="admin-field" style="margin-top:10px;">
				<span>House no. / street / purok</span>
				<input data-psgc="street" placeholder="143 Purok 6">
			</label>
			<div class="admin-address-status" data-psgc-status></div>
			<label class="admin-field" style="margin-top:10px;">
				<span>Full address</span>
				<textarea id="np_address" name="address"><?php echo nutritionist_e($editingParent['address'] ?? ''); ?></textarea>
				<span class="admin-field-hint">Auto-filled from the picker above; you can still edit it directly.</span>
			</label>
		</div>

		<label class="admin-field">
			<span>Status<span class="admin-required">*</span></span>
			<select name="status" required>
				<option value="active" <?php echo (($editingParent['status'] ?? 'active') === 'active') ? 'selected' : ''; ?>>Active</option>
				<option value="inactive" <?php echo (($editingParent['status'] ?? 'active') === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
			</select>
		</label>

		<label class="admin-field admin-field-wide">
			<span><?php echo $editingParent ? 'New password (optional)' : 'Password'; ?><?php echo $editingParent ? '' : '<span class="admin-required">*</span>'; ?></span>
			<input id="np_password" type="password" name="password" <?php echo $editingParent ? '' : 'required'; ?> data-validate="password" autocomplete="new-password" placeholder="<?php echo $editingParent ? 'Leave blank to keep current password' : 'Create a strong password'; ?>">
			<span class="admin-field-message"></span>
			<ul class="admin-pw-checklist" data-pw-checklist-for="np_password">
				<li data-pw-rule="length">At least 8 characters</li>
				<li data-pw-rule="upper">One uppercase letter</li>
				<li data-pw-rule="lower">One lowercase letter</li>
				<li data-pw-rule="number">One number</li>
				<li data-pw-rule="special">One special character</li>
			</ul>
			<div class="admin-pw-strength" data-pw-strength-for="np_password">
				<div class="admin-pw-strength-track"><div class="admin-pw-strength-fill"></div></div>
				<div class="admin-pw-strength-label"></div>
			</div>
		</label>
		<label class="admin-field admin-field-wide">
			<span><?php echo $editingParent ? 'Confirm new password' : 'Confirm password'; ?><?php echo $editingParent ? '' : '<span class="admin-required">*</span>'; ?></span>
			<input id="np_password_confirm" type="password" name="password_confirm" <?php echo $editingParent ? '' : 'required'; ?> data-validate="confirm-password" data-match="np_password" autocomplete="new-password" placeholder="Re-type the password">
			<span class="admin-field-message"></span>
		</label>

		<div class="admin-field admin-field-wide" style="align-content:end;">
			<button class="admin-btn" type="submit"><?php echo $editingParent ? 'Save changes' : 'Create parent'; ?></button>
			<?php if ($editingParent): ?>
				<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/parents.php')); ?>#parent-form" style="margin-left:8px;">Cancel edit</a>
			<?php endif; ?>
		</div>
	</form>
</section>
<?php
nutritionist_layout_end();

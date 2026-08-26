<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

$user = nutritionist_require_access();
$parentTypes = ['Father', 'Mother', 'Guardian', 'Grandparent', 'Other'];

$editId = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

	$action = (string)($_POST['action'] ?? '');
	$parentId = (int)($_POST['id'] ?? 0);

	if ($action !== 'create' && $action !== 'update') {
		admin_redirect('/nutritionist/parents.php', ['notice' => 'No action was performed.', 'type' => 'error']);
	}

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
	$redirectBack = '/nutritionist/parent_form.php' . ($action === 'update' && $parentId > 0 ? '?id=' . $parentId : '');

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
		admin_redirect($redirectBack, ['notice' => 'Name and email are required.', 'type' => 'error']);
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

	if (($user['role'] ?? '') !== 'admin' && $barangayId !== null) {
		$userBarangayId = $user['barangay_id'] ?? null;

		if (
			$userBarangayId !== null
			&& $userBarangayId !== ''
			&& (int)$userBarangayId !== $barangayId
		) {
			admin_redirect($redirectBack, [
				'notice' => 'You can only assign parents under your assigned barangay.',
				'type' => 'error',
			]);
		}
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

	admin_redirect('/nutritionist/parents.php', ['notice' => 'No action was performed.', 'type' => 'error']);
}


/*
 * LOAD PARENT FOR EDIT MODE
 */
$editingParent = null;

if ($editId > 0) {
	$editingParent = admin_fetch_one(
		'SELECT id, name, email, parent_type, phone, address, barangay_id, status
		 FROM parents
		 WHERE id = ?
		 LIMIT 1',
		'i',
		[$editId]
	);

	if ($editingParent === null) {
		admin_redirect(
			'/nutritionist/parents.php',
			[
				'notice' => 'Parent not found.',
				'type' => 'error'
			]
		);
	}
}

$editingNameParts = admin_split_full_name($editingParent['name'] ?? '');
$barangays = admin_barangay_options();

$actions = '<a class="admin-btn-secondary" href="'
	. nutritionist_e(app_url('/nutritionist/parents.php'))
	. '">' . admin_action_icon('back') . ' Parents</a>';

nutritionist_layout_start(
	$editingParent ? 'Edit Parent' : 'Add Parent',
	$editingParent ? 'Update this parent account.' : 'Create a new guardian record for linked child accounts.',
	'parents',
	$actions
);
?>
<section class="nutritionist-panel">
	<div class="nutritionist-form-head" style="margin-bottom:16px;">
		<div>
			<h2 class="admin-section-title" style="margin-bottom:2px;"><?php echo $editingParent ? 'Edit Parent' : 'Add Parent'; ?></h2>
			<p class="admin-section-subtitle"><?php echo $editingParent ? nutritionist_e((string)$editingParent['name']) : 'Create a new guardian record for linked child accounts.'; ?></p>
		</div>
	</div>

	<form class="nutritionist-form-grid" method="post" data-validate-form action="<?php echo nutritionist_e(app_url('/nutritionist/parent_form.php')); ?>">
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
			<button class="admin-btn" type="submit"><?php echo admin_action_icon('save') . ' ' . ($editingParent ? 'Save changes' : 'Create parent'); ?></button>
			<?php if ($editingParent): ?>
				<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/parents.php')); ?>" style="margin-left:8px;"><?php echo admin_action_icon('cancel'); ?> Cancel</a>
			<?php endif; ?>
		</div>
	</form>
</section>
<?php
nutritionist_layout_end();

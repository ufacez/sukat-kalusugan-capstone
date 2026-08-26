<?php

require_once __DIR__ . '/../includes/parent_helpers.php';

$user = parent_require_access();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$name = trim((string)($_POST['name'] ?? ''));
	$email = trim((string)($_POST['email'] ?? ''));
	$phone = trim((string)($_POST['phone'] ?? ''));
	$address = trim((string)($_POST['address'] ?? ''));
	$parentType = trim((string)($_POST['parent_type'] ?? 'Guardian'));
	$currentPassword = (string)($_POST['current_password'] ?? '');
	$password = (string)($_POST['password'] ?? '');
	$allowedTypes = ['Father', 'Mother', 'Guardian', 'Grandparent', 'Other'];

	if ($name === '' || $email === '') {
		admin_redirect('/parent/settings.php', ['notice' => 'Name and email are required.', 'type' => 'error']);
	}

	if (!in_array($parentType, $allowedTypes, true)) {
		$parentType = 'Guardian';
	}

	// Only setting a new password requires proving you know the current one —
	// editing name/email/phone doesn't. This stops someone with a hijacked or
	// left-open session from silently locking the real owner out.
	if ($password !== '') {
		$current = admin_fetch_one('SELECT password_hash FROM parents WHERE id = ? LIMIT 1', 'i', [(int)$user['id']]);

		if ($current === null || $currentPassword === '' || !password_verify($currentPassword, (string)$current['password_hash'])) {
			admin_redirect('/parent/settings.php', ['notice' => 'Current password is incorrect.', 'type' => 'error']);
		}
	}

	$sql = 'UPDATE parents SET name = ?, email = ?, phone = ?, address = ?, parent_type = ?';
	$params = [$name, $email, $phone, $address, $parentType];
	$types = 'sssss';

	if ($password !== '') {
		$sql .= ', password_hash = ?';
		$params[] = password_hash($password, PASSWORD_DEFAULT);
		$types .= 's';
	}

	$sql .= ' WHERE id = ?';
	$params[] = (int)$user['id'];
	$types .= 'i';

	$ok = admin_execute($sql, $types, $params);

	if ($ok) {
		$_SESSION['auth']['name'] = $name;
		$_SESSION['auth']['email'] = $email;
		$_SESSION['auth']['phone'] = $phone;
		$_SESSION['auth']['address'] = $address;
	}

	admin_redirect('/parent/settings.php', $ok ? ['notice' => 'Profile updated successfully.'] : ['notice' => 'Profile could not be updated.', 'type' => 'error']);
}

$profile = admin_fetch_one(
	'SELECT id, name, email, parent_type, phone, address, status, created_at
	 FROM parents
	 WHERE id = ?
	 LIMIT 1',
	'i',
	[(int)$user['id']]
);

if ($profile === null) {
	deny_access('Parent profile could not be loaded.', 404);
}

$childCount = admin_scalar('SELECT COUNT(*) FROM children WHERE parent_id = ?', 'i', [(int)$user['id']]);
$appointmentCount = admin_scalar('SELECT COUNT(*) FROM appointments WHERE parent_id = ?', 'i', [(int)$user['id']]);

$actions = '<a class="admin-btn-secondary" href="' . parent_e(app_url('/parent/dashboard.php')) . '">' . admin_action_icon('back') . ' Dashboard</a>';

parent_layout_start('Settings', 'Update your profile, contact details, and login password.', 'settings', $actions);
?>
<section class="parent-stat-grid">
	<article class="parent-stat-card">
		<div class="parent-stat-label">Parent type</div>
		<div class="admin-stat-value"><?php echo parent_e((string)($profile['parent_type'] ?? 'Guardian')); ?></div>
		<div class="admin-stat-note">Household role stored in the parent record</div>
	</article>
	<article class="parent-stat-card">
		<div class="parent-stat-label">Children linked</div>
		<div class="admin-stat-value"><?php echo $childCount; ?></div>
		<div class="admin-stat-note">Child records under this account</div>
	</article>
	<article class="parent-stat-card">
		<div class="parent-stat-label">Appointments</div>
		<div class="admin-stat-value"><?php echo $appointmentCount; ?></div>
		<div class="admin-stat-note">Past and upcoming visits</div>
	</article>
	<article class="parent-stat-card">
		<div class="parent-stat-label">Account status</div>
		<div class="admin-stat-value"><?php echo parent_e(ucfirst((string)($profile['status'] ?? 'active'))); ?></div>
		<div class="admin-stat-note">Login availability for this portal</div>
	</article>
</section>

<section class="parent-panel-grid" style="margin-top:14px;">
	<article class="parent-panel">
		<div class="parent-form-head" style="margin-bottom:12px;">
			<div>
				<h2 class="admin-section-title" style="margin-bottom:2px;">Profile Information</h2>
				<p class="admin-section-subtitle">Edit the contact details stored in your parent account.</p>
			</div>
		</div>

		<form method="post" class="parent-form-grid is-single">
			<label class="admin-field">
				<span>Full Name</span>
				<input name="name" required value="<?php echo parent_e((string)($profile['name'] ?? '')); ?>">
			</label>
			<label class="admin-field">
				<span>Email Address</span>
				<input type="email" name="email" required value="<?php echo parent_e((string)($profile['email'] ?? '')); ?>">
			</label>
			<label class="admin-field">
				<span>Parent Type</span>
				<select name="parent_type">
					<?php foreach (['Father', 'Mother', 'Guardian', 'Grandparent', 'Other'] as $type): ?>
						<option value="<?php echo parent_e($type); ?>" <?php echo (($profile['parent_type'] ?? 'Guardian') === $type) ? 'selected' : ''; ?>><?php echo parent_e($type); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="admin-field">
				<span>Phone Number</span>
				<input name="phone" value="<?php echo parent_e((string)($profile['phone'] ?? '')); ?>" placeholder="0917...">
			</label>
			<label class="admin-field">
				<span>Address</span>
				<input name="address" value="<?php echo parent_e((string)($profile['address'] ?? '')); ?>" placeholder="Household address">
			</label>
			<label class="admin-field">
				<span>Current Password</span>
				<input type="password" name="current_password" autocomplete="current-password" placeholder="Only needed if setting a new password below">
			</label>
			<label class="admin-field">
				<span>New Password</span>
				<input type="password" name="password" autocomplete="new-password" placeholder="Leave blank to keep current password">
			</label>
			<div class="admin-field" style="align-content:end;grid-column:1 / -1;">
				<span>&nbsp;</span>
				<button class="admin-btn" type="submit"><?php echo admin_action_icon('save'); ?> Save profile</button>
			</div>
		</form>
	</article>

	<article class="parent-panel">
		<div class="admin-section-title" style="margin-bottom:12px;">Account Summary</div>
		<div style="display:grid;gap:10px;">
			<div class="admin-list-item" style="padding:10px 0;">
				<span class="admin-mini">Member since</span>
				<strong><?php echo parent_e((string)($profile['created_at'] ?? 'n/a')); ?></strong>
			</div>
			<div class="admin-list-item" style="padding:10px 0;">
				<span class="admin-mini">Parent type</span>
				<strong><?php echo parent_e((string)($profile['parent_type'] ?? 'Guardian')); ?></strong>
			</div>
			<div class="admin-list-item" style="padding:10px 0;">
				<span class="admin-mini">Children linked</span>
				<strong><?php echo $childCount; ?></strong>
			</div>
			<div class="admin-list-item" style="padding:10px 0;">
				<span class="admin-mini">Appointments</span>
				<strong><?php echo $appointmentCount; ?></strong>
			</div>
		</div>
	</article>
</section>
<?php
parent_layout_end();


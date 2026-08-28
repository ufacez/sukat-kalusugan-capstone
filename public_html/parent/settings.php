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
	'SELECT p.id, p.name, p.email, p.parent_type, p.phone, p.address, p.status, p.created_at,
	        p.local_area_id, la.area_name AS local_area, la.area_type
	 FROM parents p
	 LEFT JOIN local_areas la ON la.id = p.local_area_id
	 WHERE p.id = ?
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
<section class="admin-grid-cards">
	<article class="admin-card">
		<div class="admin-card-row">
			<div class="admin-card-icon is-success">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Parent type</div>
				<div class="admin-card-value"><?php echo parent_e((string)($profile['parent_type'] ?? 'Guardian')); ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend">Household role stored in the parent record</span>
				</div>
			</div>
		</div>
	</article>
	<article class="admin-card">
		<div class="admin-card-row">
			<div class="admin-card-icon">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Children linked</div>
				<div class="admin-card-value"><?php echo $childCount; ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend">Child records under this account</span>
				</div>
			</div>
		</div>
	</article>
	<article class="admin-card">
		<div class="admin-card-row">
			<div class="admin-card-icon">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Appointments</div>
				<div class="admin-card-value"><?php echo $appointmentCount; ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend">Past and upcoming visits</span>
				</div>
			</div>
		</div>
	</article>
	<article class="admin-card">
		<div class="admin-card-row">
			<div class="admin-card-icon is-success">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Account status</div>
				<div class="admin-card-value"><?php echo parent_e(ucfirst((string)($profile['status'] ?? 'active'))); ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend">Login availability for this portal</span>
				</div>
			</div>
		</div>
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
				<span>Home Address</span>
				<input name="address" value="<?php echo parent_e((string)($profile['address'] ?? '')); ?>" placeholder="House no. / street">
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
			<?php if (!empty($profile['local_area'])): ?>
			<div class="admin-list-item" style="padding:10px 0;">
				<span class="admin-mini">Local Area</span>
				<strong><?php echo parent_e(ucfirst((string)($profile['area_type'] ?? '')) . ': ' . $profile['local_area']); ?></strong>
			</div>
			<?php endif; ?>
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


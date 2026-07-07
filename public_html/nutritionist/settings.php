<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

$user = nutritionist_require_access();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$name = trim((string)($_POST['name'] ?? ''));
	$email = trim((string)($_POST['email'] ?? ''));
	$phone = trim((string)($_POST['phone'] ?? ''));
	$barangay = trim((string)($_POST['barangay'] ?? ''));
	$password = (string)($_POST['password'] ?? '');

	if ($name === '' || $email === '') {
		admin_redirect('/nutritionist/settings.php', ['notice' => 'Name and email are required.', 'type' => 'error']);
	}

	$current = admin_fetch_one('SELECT id, password_hash FROM users WHERE id = ? LIMIT 1', 'i', [(int)$user['id']]);

	if ($current === null) {
		admin_redirect('/nutritionist/settings.php', ['notice' => 'Profile could not be loaded.', 'type' => 'error']);
	}

	$params = [$name, $email, $phone, $barangay, (int)$user['id']];
	$sql = 'UPDATE users SET name = ?, email = ?, phone = ?, barangay = ?';
	$types = 'ssssi';

	if ($password !== '') {
		$sql .= ', password_hash = ?';
		$types = 'sssssi';
		$params = [$name, $email, $phone, $barangay, password_hash($password, PASSWORD_DEFAULT), (int)$user['id']];
	}

	$sql .= ' WHERE id = ?';

	$ok = admin_execute($sql, $types, $params);

	if ($ok) {
		$_SESSION['auth']['name'] = $name;
		$_SESSION['auth']['email'] = $email;
		$_SESSION['auth']['phone'] = $phone;
		$_SESSION['auth']['barangay'] = $barangay;
	}

	admin_redirect('/nutritionist/settings.php', $ok ? ['notice' => 'Profile updated successfully.'] : ['notice' => 'Profile could not be updated.', 'type' => 'error']);
}

$profile = admin_fetch_one(
	'SELECT u.id, u.name, u.email, u.phone, u.barangay, u.status, r.name AS role_name
	 FROM users u
	 INNER JOIN roles r ON r.id = u.role_id
	 WHERE u.id = ?
	 LIMIT 1',
	'i',
	[(int)$user['id']]
);

$actions = '<a class="admin-btn-secondary" href="' . nutritionist_e(app_url('/nutritionist/dashboard.php')) . '">Back to dashboard</a>';

nutritionist_layout_start('Settings', 'Manage your profile and account details.', 'settings', $actions);
?>
<section class="nutritionist-stat-grid">
	<article class="nutritionist-stat-card is-featured">
		<div class="nutritionist-stat-label">Account</div>
		<div class="admin-stat-value"><?php echo nutritionist_e(ucfirst((string)($profile['role_name'] ?? 'nutritionist'))); ?></div>
		<div class="admin-stat-note">Signed-in staff profile</div>
	</article>
	<article class="nutritionist-stat-card">
		<div class="nutritionist-stat-label">Status</div>
		<div class="admin-stat-value"><?php echo nutritionist_e(ucfirst((string)($profile['status'] ?? 'active'))); ?></div>
		<div class="admin-stat-note">Account access state</div>
	</article>
	<article class="nutritionist-stat-card">
		<div class="nutritionist-stat-label">Assigned Barangay</div>
		<div class="admin-stat-value"><?php echo nutritionist_e((string)($profile['barangay'] ?? 'All')); ?></div>
		<div class="admin-stat-note">Scope for records and appointments</div>
	</article>
	<article class="nutritionist-stat-card">
		<div class="nutritionist-stat-label">Email</div>
		<div class="admin-stat-value"><?php echo nutritionist_e((string)($profile['email'] ?? '')); ?></div>
		<div class="admin-stat-note">Used for sign-in and alerts</div>
	</article>
</section>

<section class="nutritionist-panel-grid is-balanced">
	<article class="nutritionist-panel">
		<div class="admin-section-title" style="margin-bottom:12px;">Profile Information</div>
		<form method="post" class="nutritionist-form-grid is-single">
			<label class="admin-field">
				<span>Full Name</span>
				<input name="name" required value="<?php echo nutritionist_e((string)($profile['name'] ?? '')); ?>">
			</label>
			<label class="admin-field">
				<span>Email Address</span>
				<input type="email" name="email" required value="<?php echo nutritionist_e((string)($profile['email'] ?? '')); ?>">
			</label>
			<label class="admin-field">
				<span>Phone Number</span>
				<input name="phone" value="<?php echo nutritionist_e((string)($profile['phone'] ?? '')); ?>">
			</label>
			<label class="admin-field">
				<span>Assigned Barangay</span>
				<input name="barangay" value="<?php echo nutritionist_e((string)($profile['barangay'] ?? '')); ?>">
			</label>
			<label class="admin-field">
				<span>New Password</span>
				<input type="password" name="password" placeholder="Leave blank to keep current password">
			</label>
			<div class="admin-field" style="align-content:end;">
				<span>&nbsp;</span>
				<button class="admin-btn" type="submit">Save profile</button>
			</div>
		</form>
	</article>

	<article class="nutritionist-panel">
		<div class="admin-section-title" style="margin-bottom:12px;">Account Summary</div>
		<div style="display:grid;gap:10px;">
			<div class="admin-list-item" style="padding:10px 0;">
				<span class="admin-mini">Role</span>
				<strong><?php echo nutritionist_e(ucfirst((string)($profile['role_name'] ?? 'nutritionist'))); ?></strong>
			</div>
			<div class="admin-list-item" style="padding:10px 0;">
				<span class="admin-mini">Status</span>
				<strong><?php echo nutritionist_e(ucfirst((string)($profile['status'] ?? 'active'))); ?></strong>
			</div>
			<div class="admin-list-item" style="padding:10px 0;">
				<span class="admin-mini">Staff ID</span>
				<strong><?php echo (int)($profile['id'] ?? 0); ?></strong>
			</div>
			<div class="admin-list-item" style="padding:10px 0;">
				<span class="admin-mini">Security</span>
				<strong>Use the shared login flow</strong>
			</div>
		</div>
	</article>
</section>
<?php
nutritionist_layout_end();


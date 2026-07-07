<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

$user = nutritionist_require_access();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = (string)($_POST['action'] ?? '');
	$parentId = (int)($_POST['id'] ?? 0);
	$name = trim((string)($_POST['name'] ?? ''));
	$email = trim((string)($_POST['email'] ?? ''));
	$parentType = trim((string)($_POST['parent_type'] ?? 'Guardian'));
	$phone = trim((string)($_POST['phone'] ?? ''));
	$address = trim((string)($_POST['address'] ?? ''));
	$status = (string)($_POST['status'] ?? 'active');
	$parentTypes = ['Father', 'Mother', 'Guardian', 'Grandparent', 'Other'];

	if ($action === 'delete' && $parentId > 0) {
		$ok = admin_execute('DELETE FROM parents WHERE id = ?', 'i', [$parentId]);
		admin_redirect('/nutritionist/parents.php', $ok ? ['notice' => 'Parent removed.'] : ['notice' => 'Parent could not be removed because of linked children.', 'type' => 'error']);
	}

	if (!in_array($status, ['active', 'inactive'], true)) {
		$status = 'active';
	}

	if (!in_array($parentType, $parentTypes, true)) {
		$parentType = 'Guardian';
	}

	if ($name === '' || $email === '') {
		admin_redirect('/nutritionist/parents.php', ['notice' => 'Name and email are required.', 'type' => 'error']);
	}

	if ($action === 'update' && $parentId > 0) {
		$ok = admin_execute(
			'UPDATE parents SET name = ?, email = ?, parent_type = ?, phone = ?, address = ?, status = ? WHERE id = ?',
			'ssssssi',
			[$name, $email, $parentType, $phone, $address, $status, $parentId]
		);

		admin_redirect('/nutritionist/parents.php', $ok ? ['notice' => 'Parent updated.'] : ['notice' => 'Parent could not be updated.', 'type' => 'error']);
	}

	if ($action === 'create') {
		$passwordHash = password_hash('ChangeMe123!', PASSWORD_DEFAULT);
		$ok = admin_execute(
			'INSERT INTO parents (name, email, password_hash, parent_type, phone, address, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
			'sssssss',
			[$name, $email, $passwordHash, $parentType, $phone, $address, $status]
		);

		admin_redirect('/nutritionist/parents.php', $ok ? ['notice' => 'Parent added.'] : ['notice' => 'Parent could not be added.', 'type' => 'error']);
	}
}

$params = [];
$scope = nutritionist_scope_fragment($user, 'c.barangay', $params);
$parents = admin_fetch_all(
	"SELECT
		p.id,
		p.name,
		p.email,
		p.parent_type,
		p.phone,
		p.address,
		p.status,
		COUNT(DISTINCT c.id) AS children_count,
		SUM(CASE WHEN lm.nutritional_status IS NOT NULL AND lm.nutritional_status NOT IN ('Normal', 'Overweight') THEN 1 ELSE 0 END) AS follow_up_count,
		MAX(lm.measurement_date) AS latest_measurement
	 FROM parents p
	 LEFT JOIN children c ON c.parent_id = p.id AND {$scope}
	 LEFT JOIN measurements lm ON lm.id = (
		SELECT m2.id
		FROM measurements m2
		WHERE m2.child_id = c.id
		ORDER BY m2.measurement_date DESC, m2.id DESC
		LIMIT 1
	 )
	 GROUP BY p.id, p.name, p.email, p.parent_type, p.phone, p.address, p.status
	 ORDER BY p.name ASC",
	str_repeat('s', count($params)),
	$params
);

$parentTypes = ['Father', 'Mother', 'Guardian', 'Grandparent', 'Other'];

$actions = '<a class="admin-btn" href="#parent-form">Add parent</a>';

nutritionist_layout_start('Parents', 'Linked guardians and household contact information.', 'parents', $actions);
?>
<section class="nutritionist-stat-grid">
	<article class="nutritionist-stat-card is-featured">
		<div class="nutritionist-stat-label">Parents</div>
		<div class="admin-stat-value"><?php echo count($parents); ?></div>
		<div class="admin-stat-note">Households in scope</div>
	</article>
	<article class="nutritionist-stat-card">
		<div class="nutritionist-stat-label">Active</div>
		<div class="admin-stat-value"><?php echo count(array_filter($parents, static fn(array $parent): bool => $parent['status'] === 'active')); ?></div>
		<div class="admin-stat-note">Available guardian accounts</div>
	</article>
	<article class="nutritionist-stat-card">
		<div class="nutritionist-stat-label">At-Risk Links</div>
		<div class="admin-stat-value"><?php echo count(array_filter($parents, static fn(array $parent): bool => (int)$parent['follow_up_count'] > 0)); ?></div>
		<div class="admin-stat-note">Parents with follow-up children</div>
	</article>
	<article class="nutritionist-stat-card">
		<div class="nutritionist-stat-label">Children Linked</div>
		<div class="admin-stat-value"><?php echo array_sum(array_map(static fn(array $parent): int => (int)$parent['children_count'], $parents)); ?></div>
		<div class="admin-stat-note">Total child records</div>
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
					<th>Children</th>
					<th>Follow-up</th>
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
						<td style="color:var(--admin-muted);"><?php echo (int)$parent['children_count']; ?></td>
						<td style="color:var(--admin-muted);"><?php echo (int)($parent['follow_up_count'] ?? 0); ?></td>
						<td><span class="admin-pill <?php echo $parent['status'] === 'active' ? 'is-success' : 'is-muted'; ?>"><?php echo nutritionist_e(ucfirst($parent['status'])); ?></span></td>
						<td>
							<div class="admin-actions">
								<a class="admin-btn-secondary" href="#parent-form">Edit</a>
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
			<h2 class="admin-section-title" style="margin-bottom:2px;">Add Parent</h2>
			<p class="admin-section-subtitle">Create a new guardian record for linked child accounts.</p>
		</div>
	</div>

	<form method="post" class="nutritionist-form-grid">
		<input type="hidden" name="action" value="create">
		<label class="admin-field"><span>Full Name</span><input name="name" required placeholder="Maria Santos"></label>
		<label class="admin-field"><span>Email</span><input type="email" name="email" required placeholder="maria@example.com"></label>
		<label class="admin-field"><span>Parent Type</span><select name="parent_type">
			<?php foreach ($parentTypes as $parentType): ?>
				<option value="<?php echo nutritionist_e($parentType); ?>"><?php echo nutritionist_e($parentType); ?></option>
			<?php endforeach; ?>
		</select></label>
		<label class="admin-field"><span>Phone</span><input name="phone" placeholder="0917..."></label>
		<label class="admin-field"><span>Status</span><select name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
		<label class="admin-field" style="grid-column:1 / -1;"><span>Address</span><input name="address" placeholder="Household address"></label>
		<div class="admin-field" style="align-content:end;grid-column:1 / -1;"><span>&nbsp;</span><button class="admin-btn" type="submit">Save parent</button></div>
	</form>
</section>
<?php
nutritionist_layout_end();


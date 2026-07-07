<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

function nutritionist_next_child_code(): string
{
	$row = admin_fetch_one('SELECT child_code FROM children ORDER BY id DESC LIMIT 1');
	$lastCode = (string)($row['child_code'] ?? 'CHD-0000');

	if (preg_match('/(\d+)$/', $lastCode, $matches) !== 1) {
		return 'CHD-0001';
	}

	return 'CHD-' . str_pad((string)(((int)$matches[1]) + 1), 4, '0', STR_PAD_LEFT);
}

function nutritionist_child_status_class(?string $status): string
{
	return match ($status) {
		'Normal' => 'is-success',
		'Overweight' => 'is-warn',
		'Pending' => 'is-muted',
		default => 'is-danger',
	};
}

$user = nutritionist_require_access();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = (string)($_POST['action'] ?? '');
	$childId = (int)($_POST['id'] ?? 0);

	if ($action === 'delete' && $childId > 0) {
		if (admin_execute('DELETE FROM children WHERE id = ?', 'i', [$childId])) {
			admin_redirect('/nutritionist/children.php', ['notice' => 'Child removed successfully.']);
		}

		admin_redirect('/nutritionist/children.php', ['notice' => 'Child could not be removed because of linked records.', 'type' => 'error']);
	}

	$firstName = trim((string)($_POST['first_name'] ?? ''));
	$lastName = trim((string)($_POST['last_name'] ?? ''));
	$birthdate = trim((string)($_POST['birthdate'] ?? ''));
	$sex = trim((string)($_POST['sex'] ?? 'Male'));
	$barangay = trim((string)($_POST['barangay'] ?? ''));
	$address = trim((string)($_POST['address'] ?? ''));
	$parentId = (int)($_POST['parent_id'] ?? 0);

	if ($firstName === '' || $lastName === '' || $birthdate === '' || $parentId <= 0) {
		admin_redirect('/nutritionist/children.php', ['notice' => 'First name, last name, birthdate, and parent are required.', 'type' => 'error']);
	}

	if (!in_array($sex, ['Male', 'Female'], true)) {
		$sex = 'Male';
	}

	if ($action === 'update' && $childId > 0) {
		$current = admin_fetch_one('SELECT child_code FROM children WHERE id = ? LIMIT 1', 'i', [$childId]);
		$childCode = (string)($current['child_code'] ?? nutritionist_next_child_code());

		$ok = admin_execute(
			'UPDATE children
			 SET child_code = ?, first_name = ?, last_name = ?, birthdate = ?, sex = ?, barangay = ?, address = ?, parent_id = ?
			 WHERE id = ?',
			'ssssssiii',
			[$childCode, $firstName, $lastName, $birthdate, $sex, $barangay, $address, $parentId, $childId]
		);

		admin_redirect(
			'/nutritionist/children.php',
			$ok
				? ['notice' => 'Child updated successfully.', 'edit' => $childId]
				: ['notice' => 'Child could not be updated.', 'type' => 'error']
		);
	}

	if ($action === 'create') {
		$childCode = nutritionist_next_child_code();

		$ok = admin_execute(
			'INSERT INTO children (child_code, first_name, last_name, birthdate, sex, barangay, address, parent_id)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
			'sssssssi',
			[$childCode, $firstName, $lastName, $birthdate, $sex, $barangay, $address, $parentId]
		);

		admin_redirect(
			'/nutritionist/children.php',
			$ok
				? ['notice' => 'Child added successfully.']
				: ['notice' => 'Child could not be added.', 'type' => 'error']
		);
	}

	admin_redirect('/nutritionist/children.php', ['notice' => 'No action was performed.', 'type' => 'error']);
}

$statusFilter = (string)($_GET['status'] ?? 'All');
$viewId = (int)($_GET['view'] ?? 0);
$editId = (int)($_GET['edit'] ?? 0);
$deleteId = (int)($_GET['delete'] ?? 0);

if ($deleteId > 0) {
	admin_redirect('/nutritionist/children.php?edit=' . $deleteId . '#child-form');
}

$childrenParams = [];
$childrenScope = nutritionist_scope_fragment($user, 'c.barangay', $childrenParams);
$children = admin_fetch_all(
	"SELECT
		c.id,
		c.child_code,
		c.first_name,
		c.last_name,
		c.birthdate,
		c.sex,
		c.barangay,
		c.address,
		c.parent_id,
		p.name AS parent_name,
		p.status AS parent_status,
		lm.measurement_date,
		lm.height_cm,
		lm.weight_kg,
		lm.waz,
		lm.haz,
		lm.whz,
		lm.nutritional_status
	 FROM children c
	 INNER JOIN parents p ON p.id = c.parent_id
	 LEFT JOIN measurements lm ON lm.id = (
		SELECT m.id
		FROM measurements m
		WHERE m.child_id = c.id
		ORDER BY m.measurement_date DESC, m.id DESC
		LIMIT 1
	 )
	 WHERE {$childrenScope}
	 ORDER BY c.last_name ASC, c.first_name ASC",
	str_repeat('s', count($childrenParams)),
	$childrenParams
);

$viewChild = null;
$editChild = null;

foreach ($children as $child) {
	if ((int)$child['id'] === $viewId) {
		$viewChild = $child;
	}

	if ((int)$child['id'] === $editId) {
		$editChild = $child;
	}
}

$parentsParams = [];
$parents = admin_fetch_all(
	"SELECT p.id, p.name, p.parent_type, p.status, p.phone, p.address
	 FROM parents p
	 ORDER BY p.name ASC",
	'',
	[]
);

$statuses = ['All', 'Normal', 'Underweight', 'Severely Underweight', 'Stunted', 'Wasted', 'Overweight'];
$filteredChildren = array_values(array_filter(
	$children,
	static function (array $child) use ($statusFilter): bool {
		if ($statusFilter === 'All') {
			return true;
		}

		return (string)($child['nutritional_status'] ?? 'Pending') === $statusFilter;
	}
));

$selectedChild = $viewChild ?? $editChild;
$selectedMeasurements = [];

if ($selectedChild !== null) {
	$measurementParams = [(int)$selectedChild['id']];
	$selectedMeasurements = admin_fetch_all(
		'SELECT id, measurement_date, height_cm, weight_kg, waz, haz, whz, nutritional_status
		 FROM measurements
		 WHERE child_id = ?
		 ORDER BY measurement_date DESC, id DESC',
		'i',
		$measurementParams
	);
}

$actions = '<a class="admin-btn" href="#child-form">Add child</a>';

nutritionist_layout_start('Children & Growth', 'Registered children, latest growth status, and follow-up history.', 'children', $actions);
?>
<section class="nutritionist-panel">
	<div class="nutritionist-form-head" style="margin-bottom:12px;">
		<div>
			<h2 class="admin-section-title" style="margin-bottom:2px;">Children Monitoring</h2>
			<p class="admin-section-subtitle"><?php echo count($children); ?> registered children</p>
		</div>
		<div class="nutritionist-chip-row">
			<?php foreach ($statuses as $status): ?>
				<a class="nutritionist-chip<?php echo $statusFilter === $status ? ' is-active' : ''; ?>" href="<?php echo nutritionist_e(app_url('/nutritionist/children.php?status=' . urlencode($status))); ?>"><?php echo nutritionist_e($status); ?></a>
			<?php endforeach; ?>
		</div>
	</div>

	<div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;">
		<input class="admin-search" data-admin-filter="#children-table" type="search" placeholder="Search children..." style="flex:1;min-width:200px;">
	</div>

	<div class="nutritionist-table-wrap">
		<table class="nutritionist-table" id="children-table">
			<thead>
				<tr>
					<th>Code</th>
					<th>Name</th>
					<th>Age</th>
					<th>Sex</th>
					<th>Barangay</th>
					<th>Parent</th>
					<th>Status</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($filteredChildren as $child): ?>
					<?php
					$ageMonths = (new DateTimeImmutable((string)$child['birthdate']))->diff(new DateTimeImmutable('today'))->y * 12 + (new DateTimeImmutable((string)$child['birthdate']))->diff(new DateTimeImmutable('today'))->m;
					$status = (string)($child['nutritional_status'] ?? 'Pending');
					$pillClass = nutritionist_child_status_class($status);
					?>
					<tr data-filter-text="<?php echo nutritionist_e(strtolower($child['child_code'] . ' ' . $child['first_name'] . ' ' . $child['last_name'] . ' ' . $child['barangay'] . ' ' . $child['parent_name'] . ' ' . $status)); ?>">
						<td style="font-family:monospace;color:var(--admin-muted);"><?php echo nutritionist_e($child['child_code']); ?></td>
						<td>
							<div style="display:flex;align-items:center;gap:8px;">
								<div class="admin-pill <?php echo $pillClass; ?>" style="min-width:30px;justify-content:center;border-radius:50%;padding:0.35rem 0.5rem;"><?php echo nutritionist_e(substr($child['first_name'], 0, 1) . substr($child['last_name'], 0, 1)); ?></div>
								<div>
									<div style="font-size:13px;font-weight:600;color:var(--admin-text);"><?php echo nutritionist_e($child['first_name'] . ' ' . $child['last_name']); ?></div>
									<div style="font-size:10px;color:var(--admin-muted);margin-top:1px;"><?php echo nutritionist_e((string)$child['birthdate']); ?></div>
								</div>
							</div>
						</td>
						<td style="color:var(--admin-muted);"><?php echo (int)$ageMonths; ?> mo</td>
						<td style="color:var(--admin-muted);"><?php echo nutritionist_e((string)$child['sex']); ?></td>
						<td style="color:var(--admin-muted);"><?php echo nutritionist_e((string)($child['barangay'] ?? '')); ?></td>
						<td style="color:var(--admin-muted);"><?php echo nutritionist_e((string)$child['parent_name']); ?></td>
						<td><span class="admin-pill <?php echo $pillClass; ?>"><?php echo nutritionist_e($status); ?></span></td>
						<td>
							<div class="admin-actions">
								<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/children.php?view=' . (int)$child['id'])); ?>">View</a>
								<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/children.php?edit=' . (int)$child['id']) . '#child-form'); ?>">Edit</a>
								<form method="post" action="<?php echo nutritionist_e(app_url('/nutritionist/children.php')); ?>" onsubmit="return confirm('Delete <?php echo nutritionist_e($child['first_name'] . ' ' . $child['last_name']); ?>?');">
									<input type="hidden" name="action" value="delete">
									<input type="hidden" name="id" value="<?php echo (int)$child['id']; ?>">
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

<?php if ($selectedChild !== null): ?>
	<section class="nutritionist-panel-grid" style="margin-top:18px;">
		<article class="nutritionist-panel">
			<div style="text-align:center;margin-bottom:20px;">
				<div style="display:flex;justify-content:center;margin-bottom:12px;">
					<div class="admin-pill <?php echo nutritionist_child_status_class((string)($selectedChild['nutritional_status'] ?? 'Pending')); ?>" style="width:64px;height:64px;border-radius:50%;font-size:1rem;justify-content:center;">
						<?php echo nutritionist_e(substr((string)$selectedChild['first_name'], 0, 1) . substr((string)$selectedChild['last_name'], 0, 1)); ?>
					</div>
				</div>
				<h2 style="margin:0;font-size:16px;font-weight:700;color:var(--admin-text);"><?php echo nutritionist_e($selectedChild['first_name'] . ' ' . $selectedChild['last_name']); ?></h2>
				<div style="color:var(--admin-muted);font-size:12px;margin:4px 0 10px;"><?php echo nutritionist_e((string)$selectedChild['child_code']); ?></div>
				<span class="admin-pill <?php echo nutritionist_child_status_class((string)($selectedChild['nutritional_status'] ?? 'Pending')); ?>"><?php echo nutritionist_e((string)($selectedChild['nutritional_status'] ?? 'Pending')); ?></span>
			</div>
			<div style="border-top:1px solid var(--admin-border);padding-top:16px;">
				<?php foreach ([
					['Birthdate', $selectedChild['birthdate']],
					['Age', ((new DateTimeImmutable((string)$selectedChild['birthdate']))->diff(new DateTimeImmutable('today'))->y * 12 + (new DateTimeImmutable((string)$selectedChild['birthdate']))->diff(new DateTimeImmutable('today'))->m) . ' months'],
					['Sex', $selectedChild['sex']],
					['Barangay', $selectedChild['barangay']],
					['Parent', $selectedChild['parent_name']],
					['Address', $selectedChild['address']],
				] as [$label, $value]): ?>
					<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--admin-border);">
						<span style="font-size:12px;color:var(--admin-muted);"><?php echo nutritionist_e($label); ?></span>
						<span style="font-size:12px;font-weight:600;color:var(--admin-text);text-align:right;max-width:55%;"><?php echo nutritionist_e((string)$value); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		</article>

		<article class="nutritionist-panel">
			<?php if (!empty($selectedMeasurements)): ?>
				<div style="font-weight:700;font-size:14px;color:var(--admin-text);margin-bottom:14px;">Latest WHO Z-Scores</div>
				<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;">
					<?php foreach ([
						['WAZ', $selectedMeasurements[0]['waz'], 'Weight-for-Age'],
						['HAZ', $selectedMeasurements[0]['haz'], 'Height-for-Age'],
						['WHZ', $selectedMeasurements[0]['whz'], 'Weight-for-Height'],
					] as [$label, $value, $description]): ?>
						<div style="background:var(--admin-surface-alt);border-radius:12px;padding:16px;text-align:center;">
							<div style="font-size:10px;color:var(--admin-muted);letter-spacing:0.5px;"><?php echo nutritionist_e($description); ?></div>
							<div style="font-size:28px;font-weight:800;color:<?php echo abs((float)$value) > 2 ? 'var(--admin-danger)' : 'var(--admin-primary)'; ?>;margin:8px 0 4px;"><?php echo ((float)$value > 0 ? '+' : '') . nutritionist_e((string)$value); ?></div>
							<div style="font-size:10px;color:var(--admin-muted);"><?php echo nutritionist_e($label); ?> Z-Score</div>
						</div>
					<?php endforeach; ?>
				</div>
				<div style="margin-top:12px;background:<?php echo nutritionist_child_status_class((string)$selectedChild['nutritional_status'] ?? 'Pending') === 'is-success' ? 'var(--admin-primary-soft)' : 'var(--admin-surface-alt)'; ?>;border:1px solid var(--admin-border);border-radius:10px;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;">
					<span style="font-size:12px;font-weight:700;color:var(--admin-text);">Nutritional Status: <?php echo nutritionist_e((string)($selectedChild['nutritional_status'] ?? 'Pending')); ?></span>
					<span style="font-size:11px;color:var(--admin-muted);">H: <?php echo nutritionist_e((string)($selectedChild['height_cm'] ?? 'n/a')); ?>cm · W: <?php echo nutritionist_e((string)($selectedChild['weight_kg'] ?? 'n/a')); ?>kg</span>
				</div>
			<?php endif; ?>

			<div style="font-weight:700;font-size:14px;color:var(--admin-text);margin:18px 0 14px;">Measurement History</div>
			<?php if ($selectedMeasurements === []): ?>
				<div style="text-align:center;color:var(--admin-muted);font-size:13px;padding:20px;">No measurements recorded</div>
			<?php else: ?>
				<table class="nutritionist-table">
					<thead>
						<tr>
							<th>Date</th>
							<th>Height</th>
							<th>Weight</th>
							<th>WAZ</th>
							<th>HAZ</th>
							<th>WHZ</th>
							<th>Status</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($selectedMeasurements as $measurement): ?>
							<tr>
								<td><?php echo nutritionist_e((string)$measurement['measurement_date']); ?></td>
								<td style="color:var(--admin-muted);"><?php echo nutritionist_e((string)$measurement['height_cm']); ?></td>
								<td style="color:var(--admin-muted);"><?php echo nutritionist_e((string)$measurement['weight_kg']); ?></td>
								<td style="color:var(--admin-primary);font-weight:600;"><?php echo ((float)$measurement['waz'] > 0 ? '+' : '') . nutritionist_e((string)$measurement['waz']); ?></td>
								<td style="color:var(--admin-info,#4a9fd5);font-weight:600;"><?php echo ((float)$measurement['haz'] > 0 ? '+' : '') . nutritionist_e((string)$measurement['haz']); ?></td>
								<td style="color:#0d8871;font-weight:600;"><?php echo ((float)$measurement['whz'] > 0 ? '+' : '') . nutritionist_e((string)$measurement['whz']); ?></td>
								<td><span class="admin-pill <?php echo nutritionist_child_status_class((string)$measurement['nutritional_status']); ?>"><?php echo nutritionist_e((string)$measurement['nutritional_status']); ?></span></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</article>
	</section>
<?php endif; ?>

<section class="nutritionist-panel" id="child-form" style="margin-top:18px;">
	<div class="nutritionist-form-head" style="margin-bottom:16px;">
		<div>
			<h2 class="admin-section-title" style="margin-bottom:2px;"><?php echo $editChild !== null ? 'Edit Child' : 'Add Child'; ?></h2>
			<p class="admin-section-subtitle"><?php echo $editChild !== null ? 'Update the child profile and care assignment.' : 'Create a new child record backed by the children table.'; ?></p>
		</div>
	</div>

	<form method="post" class="nutritionist-form-grid">
		<input type="hidden" name="action" value="<?php echo $editChild !== null ? 'update' : 'create'; ?>">
		<?php if ($editChild !== null): ?>
			<input type="hidden" name="id" value="<?php echo (int)$editChild['id']; ?>">
		<?php endif; ?>
		<label class="admin-field">
			<span>First Name</span>
			<input name="first_name" required value="<?php echo nutritionist_e($editChild['first_name'] ?? ''); ?>" placeholder="Juan">
		</label>
		<label class="admin-field">
			<span>Last Name</span>
			<input name="last_name" required value="<?php echo nutritionist_e($editChild['last_name'] ?? ''); ?>" placeholder="Dela Cruz">
		</label>
		<label class="admin-field">
			<span>Birthdate</span>
			<input type="date" name="birthdate" required value="<?php echo nutritionist_e($editChild['birthdate'] ?? ''); ?>">
		</label>
		<label class="admin-field">
			<span>Sex</span>
			<select name="sex" required>
				<option value="Male" <?php echo (($editChild['sex'] ?? 'Male') === 'Male') ? 'selected' : ''; ?>>Male</option>
				<option value="Female" <?php echo (($editChild['sex'] ?? '') === 'Female') ? 'selected' : ''; ?>>Female</option>
			</select>
		</label>
		<label class="admin-field">
			<span>Barangay</span>
			<input name="barangay" value="<?php echo nutritionist_e($editChild['barangay'] ?? ($user['barangay'] ?? '')); ?>" placeholder="Assigned barangay">
		</label>
		<label class="admin-field">
			<span>Parent/Guardian</span>
			<select name="parent_id" required>
					<option value="">-- Select Parent --</option>
				<?php foreach ($parents as $parent): ?>
						<option value="<?php echo (int)$parent['id']; ?>" <?php echo (int)($editChild['parent_id'] ?? 0) === (int)$parent['id'] ? 'selected' : ''; ?>><?php echo nutritionist_e($parent['name'] . ' · ' . $parent['parent_type'] . ' · ' . ($parent['status'] ?? 'unknown')); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label class="admin-field" style="grid-column:1 / -1;">
			<span>Address</span>
			<input name="address" value="<?php echo nutritionist_e($editChild['address'] ?? ''); ?>" placeholder="Home address">
		</label>
		<div class="admin-field" style="align-content:end;grid-column:1 / -1;">
			<span>&nbsp;</span>
			<div class="admin-actions">
				<?php if ($editChild !== null): ?>
					<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/children.php')); ?>">Cancel</a>
				<?php endif; ?>
				<button class="admin-btn" type="submit"><?php echo $editChild !== null ? 'Save changes' : 'Create child'; ?></button>
			</div>
		</div>
	</form>
</section>
<?php
nutritionist_layout_end();


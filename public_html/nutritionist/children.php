<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/who_calculator.php';

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
	$middleName = trim((string)($_POST['middle_name'] ?? ''));
	$lastName = trim((string)($_POST['last_name'] ?? ''));
	$birthdate = trim((string)($_POST['birthdate'] ?? ''));
	$sex = trim((string)($_POST['sex'] ?? 'Male'));
	$isIp = isset($_POST['is_ip']) ? 1 : 0;
	$hasDisability = isset($_POST['has_disability']) ? 1 : 0;
	$parentId = (int)($_POST['parent_id'] ?? 0);

	if (
		!admin_is_valid_name_part($firstName, true)
		|| !admin_is_valid_name_part($middleName, false)
		|| !admin_is_valid_name_part($lastName, true)
		|| $birthdate === ''
		|| $parentId <= 0
	) {
		admin_redirect('/nutritionist/children.php', ['notice' => 'First name, last name, birthdate, and parent are required.', 'type' => 'error']);
	}

	// A child's household barangay and address are the same as their
	// parent/guardian's -- there's no separate "where does the child live"
	// concept in this system. Rather than asking the nutritionist to
	// re-enter (and potentially mismatch) a barangay/address that's already
	// on file for the parent, inherit both directly from the selected
	// Parent/Guardian record. This also removes the old free-standing
	// Province/City/Barangay picker and Address/Purok fields, which
	// duplicated data already captured once when the parent was created.
	$parentRecord = admin_fetch_one('SELECT barangay_id, address FROM parents WHERE id = ? LIMIT 1', 'i', [$parentId]);

	if ($parentRecord === null) {
		admin_redirect('/nutritionist/children.php', ['notice' => 'Selected parent/guardian could not be found.', 'type' => 'error']);
	}

	$barangayId = $parentRecord['barangay_id'] !== null ? (int)$parentRecord['barangay_id'] : null;
	$address = (string)($parentRecord['address'] ?? '');
	$purok = null;

	// WHO growth reference tables only cover 0-60 completed months. Past
	// that, calculate_waz()/calculate_haz()/calculate_whz() fall through to
	// who_fallback_reference() and silently return a fabricated z-score
	// instead of a real WHO-derived one, which would misinform staff. Block
	// registration past that ceiling instead of letting it happen quietly.
	$registrationAgeMonths = doh_age_in_months($birthdate);

	if ($registrationAgeMonths === null || $registrationAgeMonths > 60) {
		admin_redirect('/nutritionist/children.php', ['notice' => 'Birthdate must be valid and the child must be 60 months (5 years) old or younger — WHO growth references only cover 0-60 months.', 'type' => 'error']);
	}

	if (!in_array($sex, ['Male', 'Female'], true)) {
		$sex = 'Male';
	}

	if ($action === 'update' && $childId > 0) {
		$current = admin_fetch_one('SELECT child_code FROM children WHERE id = ? LIMIT 1', 'i', [$childId]);
		$childCode = (string)($current['child_code'] ?? nutritionist_next_child_code());

		$ok = admin_execute(
			'UPDATE children
			 SET child_code = ?, first_name = ?, middle_name = ?, last_name = ?, birthdate = ?, sex = ?, barangay_id = ?, address = ?, purok = ?, is_ip = ?, has_disability = ?, parent_id = ?
			 WHERE id = ?',
			'ssssssissiiii',
			[$childCode, $firstName, $middleName, $lastName, $birthdate, $sex, $barangayId, $address, $purok, $isIp, $hasDisability, $parentId, $childId]
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
			'INSERT INTO children (child_code, first_name, middle_name, last_name, birthdate, sex, barangay_id, address, purok, is_ip, has_disability, parent_id)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
			'ssssssissiiii',
			[$childCode, $firstName, $middleName, $lastName, $birthdate, $sex, $barangayId, $address, $purok, $isIp, $hasDisability, $parentId]
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
$childrenScope = nutritionist_scope_fragment($user, 'c.barangay_id', $childrenParams);
$children = admin_fetch_all(
	"SELECT
		c.id,
		c.child_code,
		c.first_name,
		c.middle_name,
		c.last_name,
		c.birthdate,
		c.sex,
		c.barangay_id,
		bg.name AS barangay,
		c.address,
		c.purok,
		c.is_ip,
		c.has_disability,
		c.parent_id,
		p.name AS parent_name,
		p.status AS parent_status,
		lm.measurement_date,
		lm.height_cm,
		lm.weight_kg,
		lm.waz,
		lm.haz,
		lm.whz,
		lm.nutritional_status,
		lm.wfa_status,
		lm.hfa_status,
		lm.wfh_status
	 FROM children c
	 INNER JOIN parents p ON p.id = c.parent_id
	 LEFT JOIN barangays bg ON bg.id = c.barangay_id
	 LEFT JOIN measurements lm ON lm.id = (
		SELECT m.id
		FROM measurements m
		WHERE m.child_id = c.id
		ORDER BY m.measurement_date DESC, m.id DESC
		LIMIT 1
	 )
	 WHERE {$childrenScope}
	 ORDER BY c.last_name ASC, c.first_name ASC",
	str_repeat('i', count($childrenParams)),
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
	"SELECT p.id, p.name, p.parent_type, p.status, p.phone, p.address, p.barangay_id, bg.name AS barangay_name
	 FROM parents p
	 LEFT JOIN barangays bg ON bg.id = p.barangay_id
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
					$ageMonths = doh_age_in_months((string)$child['birthdate']) ?? 0;
					$status = (string)($child['nutritional_status'] ?? 'Pending');
					$pillClass = nutritionist_child_status_class($status);
					?>
					<tr data-filter-text="<?php echo nutritionist_e(strtolower($child['child_code'] . ' ' . $child['first_name'] . ' ' . ($child['middle_name'] ?? '') . ' ' . $child['last_name'] . ' ' . (string)($child['barangay'] ?? '') . ' ' . $child['parent_name'] . ' ' . $status)); ?>">
						<td style="font-family:monospace;color:var(--admin-muted);"><?php echo nutritionist_e($child['child_code']); ?></td>
						<td>
							<div style="display:flex;align-items:center;gap:8px;">
								<div class="admin-pill <?php echo $pillClass; ?>" style="min-width:30px;justify-content:center;border-radius:50%;padding:0.35rem 0.5rem;"><?php echo nutritionist_e(substr($child['first_name'], 0, 1) . substr($child['last_name'], 0, 1)); ?></div>
								<div>
									<div style="font-size:13px;font-weight:600;color:var(--admin-text);"><?php echo nutritionist_e(trim($child['first_name'] . ' ' . ($child['middle_name'] ?? '') . ' ' . $child['last_name'])); ?></div>
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
								<form method="post" action="<?php echo nutritionist_e(app_url('/nutritionist/children.php')); ?>" onsubmit="return confirm('Delete <?php echo nutritionist_e(trim($child['first_name'] . ' ' . ($child['middle_name'] ?? '') . ' ' . $child['last_name'])); ?>?');">
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
				<h2 style="margin:0;font-size:16px;font-weight:700;color:var(--admin-text);"><?php echo nutritionist_e(trim($selectedChild['first_name'] . ' ' . ($selectedChild['middle_name'] ?? '') . ' ' . $selectedChild['last_name'])); ?></h2>
				<div style="color:var(--admin-muted);font-size:12px;margin:4px 0 10px;"><?php echo nutritionist_e((string)$selectedChild['child_code']); ?></div>
				<span class="admin-pill <?php echo nutritionist_child_status_class((string)($selectedChild['nutritional_status'] ?? 'Pending')); ?>"><?php echo nutritionist_e((string)($selectedChild['nutritional_status'] ?? 'Pending')); ?></span>
			</div>
			<div style="border-top:1px solid var(--admin-border);padding-top:16px;">
				<?php foreach ([
					['Birthdate', $selectedChild['birthdate']],
					['Age', (doh_age_in_months((string)$selectedChild['birthdate']) ?? 0) . ' months'],
					['Sex', $selectedChild['sex']],
					['Barangay', $selectedChild['barangay']],
					['Parent', $selectedChild['parent_name']],
					['Address', $selectedChild['address']],
					['IP Group', !empty($selectedChild['is_ip']) ? 'Yes' : 'No'],
					['Disability', !empty($selectedChild['has_disability']) ? 'Yes' : 'No'],
				] as [$label, $value]): ?>
					<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--admin-border);">
						<span style="font-size:12px;color:var(--admin-muted);"><?php echo nutritionist_e($label); ?></span>
						<span style="font-size:12px;font-weight:600;color:var(--admin-text);text-align:right;max-width:55%;"><?php echo nutritionist_e((string)$value); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
			<?php if (($selectedChild['wfa_status'] ?? null) !== null || ($selectedChild['hfa_status'] ?? null) !== null || ($selectedChild['wfh_status'] ?? null) !== null): ?>
				<div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--admin-border);">
					<div style="font-weight:700;font-size:12px;color:var(--admin-muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:0.04em;">DOH Nutritional Status (per OPT Plus)</div>
					<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;">
						<?php foreach ([
							['WFA', $selectedChild['wfa_status'] ?? '—'],
							['HFA', $selectedChild['hfa_status'] ?? '—'],
							['WFH', $selectedChild['wfh_status'] ?? '—'],
						] as [$axis, $val]): ?>
							<div style="text-align:center;background:var(--admin-surface-alt, #f7f7f5);border-radius:8px;padding:8px 4px;">
								<div style="font-size:10px;color:var(--admin-muted);"><?php echo nutritionist_e($axis); ?></div>
								<div style="font-size:12px;font-weight:700;color:var(--admin-text);"><?php echo nutritionist_e((string)$val); ?></div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>
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

	<form method="post" class="nutritionist-form-grid" data-validate-form>
		<input type="hidden" name="action" value="<?php echo $editChild !== null ? 'update' : 'create'; ?>">
		<?php if ($editChild !== null): ?>
			<input type="hidden" name="id" value="<?php echo (int)$editChild['id']; ?>">
		<?php endif; ?>
		<label class="admin-field">
			<span>First name<span class="admin-required">*</span></span>
			<input name="first_name" required maxlength="60" data-validate="name" data-label="First name" value="<?php echo nutritionist_e($editChild['first_name'] ?? ''); ?>" placeholder="Juan">
			<span class="admin-field-message"></span>
		</label>
		<label class="admin-field">
			<span>Middle name</span>
			<input name="middle_name" maxlength="60" data-validate="name" data-label="Middle name" value="<?php echo nutritionist_e($editChild['middle_name'] ?? ''); ?>" placeholder="Santos">
			<span class="admin-field-message"></span>
		</label>
		<label class="admin-field">
			<span>Surname<span class="admin-required">*</span></span>
			<input name="last_name" required maxlength="60" data-validate="name" data-label="Surname" value="<?php echo nutritionist_e($editChild['last_name'] ?? ''); ?>" placeholder="Dela Cruz">
			<span class="admin-field-message"></span>
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
		<label class="admin-field" style="grid-column:1 / -1;position:relative;">
			<span>Parent/Guardian<span class="admin-required">*</span></span>
			<input type="hidden" name="parent_id" data-child-parent-id value="<?php echo (int)($editChild['parent_id'] ?? 0); ?>">
			<input
				type="text"
				autocomplete="off"
				placeholder="Search parent/guardian by name…"
				data-child-parent-search
				value="<?php
					$initialParentName = '';
					foreach ($parents as $parent) {
						if ((int)$parent['id'] === (int)($editChild['parent_id'] ?? 0)) {
							$initialParentName = $parent['name'];
							break;
						}
					}
					echo nutritionist_e($initialParentName);
				?>"
			>
			<div class="admin-typeahead-results" data-child-parent-results hidden></div>
			<p class="admin-mini" style="margin-top:6px;">The child's barangay and address are taken automatically from the selected parent/guardian's own record.</p>
			<div class="admin-address-status" data-child-household-preview>
				<?php if ($editChild !== null): ?>
					Household: <?php echo nutritionist_e((string)($editChild['address'] ?? '—')); ?><?php echo $editChild['barangay'] ? ' · Brgy. ' . nutritionist_e((string)$editChild['barangay']) : ''; ?>
				<?php endif; ?>
			</div>
		</label>
		<script type="application/json" data-child-parent-source><?php
			echo json_encode(array_map(static function (array $parent): array {
				return [
					'id' => (int)$parent['id'],
					'name' => (string)$parent['name'],
					'address' => (string)($parent['address'] ?? ''),
					'barangay' => (string)($parent['barangay_name'] ?? ''),
				];
			}, $parents), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
		?></script>
		<label class="admin-field admin-field-checkbox">
			<input type="checkbox" name="is_ip" value="1" <?php echo !empty($editChild['is_ip']) ? 'checked' : ''; ?>>
			<span>Belongs to IP (Indigenous Peoples) group</span>
		</label>
		<label class="admin-field admin-field-checkbox">
			<input type="checkbox" name="has_disability" value="1" <?php echo !empty($editChild['has_disability']) ? 'checked' : ''; ?>>
			<span>Has a disability</span>
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
<script>
(function () {
	var form = document.querySelector('.nutritionist-form-grid');
	var hiddenId = document.querySelector('[data-child-parent-id]');
	var searchInput = document.querySelector('[data-child-parent-search]');
	var resultsBox = document.querySelector('[data-child-parent-results]');
	var preview = document.querySelector('[data-child-household-preview]');
	var sourceTag = document.querySelector('[data-child-parent-source]');

	if (!form || !hiddenId || !searchInput || !resultsBox || !preview || !sourceTag) {
		return;
	}

	var parents = [];

	try {
		parents = JSON.parse(sourceTag.textContent || '[]');
	} catch (err) {
		parents = [];
	}

	function renderPreview(parent) {
		if (!parent) {
			preview.textContent = '';
			return;
		}

		var parts = [];

		if (parent.address) {
			parts.push(parent.address);
		}

		if (parent.barangay) {
			parts.push('Brgy. ' + parent.barangay);
		}

		preview.textContent = parts.length > 0 ? 'Household: ' + parts.join(' · ') : 'This parent has no address/barangay on file yet.';
	}

	function clearSelection() {
		hiddenId.value = '';
		renderPreview(null);
	}

	function closeResults() {
		resultsBox.hidden = true;
		resultsBox.innerHTML = '';
	}

	function selectParent(parent) {
		hiddenId.value = String(parent.id);
		searchInput.value = parent.name;
		renderPreview(parent);
		closeResults();
	}

	function showResults(matches) {
		resultsBox.innerHTML = '';

		if (matches.length === 0) {
			var empty = document.createElement('div');
			empty.className = 'admin-typeahead-empty';
			empty.textContent = 'No matching parent/guardian.';
			resultsBox.appendChild(empty);
			resultsBox.hidden = false;
			return;
		}

		matches.slice(0, 20).forEach(function (parent) {
			var item = document.createElement('button');
			item.type = 'button';
			item.className = 'admin-typeahead-item';
			item.textContent = parent.name;
			item.addEventListener('click', function () {
				selectParent(parent);
			});
			resultsBox.appendChild(item);
		});

		resultsBox.hidden = false;
	}

	searchInput.addEventListener('input', function () {
		var term = searchInput.value.trim().toLowerCase();

		// Typing invalidates whatever was previously selected until the
		// nutritionist actually picks a result again.
		hiddenId.value = '';
		renderPreview(null);

		if (term === '') {
			closeResults();
			return;
		}

		showResults(parents.filter(function (parent) {
			return parent.name.toLowerCase().indexOf(term) !== -1;
		}));
	});

	searchInput.addEventListener('focus', function () {
		if (searchInput.value.trim() !== '' && hiddenId.value === '') {
			searchInput.dispatchEvent(new Event('input'));
		}
	});

	document.addEventListener('click', function (event) {
		if (!event.target.closest('[data-child-parent-search]') && !event.target.closest('[data-child-parent-results]')) {
			closeResults();
		}
	});

	form.addEventListener('submit', function (event) {
		if (!hiddenId.value) {
			event.preventDefault();
			searchInput.focus();
			searchInput.setCustomValidity('Please search for and select a parent/guardian from the list.');
			searchInput.reportValidity();
			return;
		}

		searchInput.setCustomValidity('');
	});

	searchInput.addEventListener('input', function () {
		searchInput.setCustomValidity('');
	});
})();
</script>
<?php
nutritionist_layout_end();
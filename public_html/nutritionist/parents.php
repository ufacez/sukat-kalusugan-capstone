<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

$user = nutritionist_require_access();

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

}

$editId = (int)($_GET['edit'] ?? 0);

if ($editId > 0) {
    admin_redirect(
        '/nutritionist/parent_form.php?id=' . $editId
    );
}

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

$activeCount = count(array_filter($parents, static fn(array $parent): bool => (string)$parent['status'] === 'active'));
$totalChildren = array_sum(array_map(static fn(array $parent): int => (int)$parent['children_count'], $parents));
$totalAppointments = array_sum(array_map(static fn(array $parent): int => (int)$parent['appointment_count'], $parents));
$atRiskCount = count(array_filter($parents, static fn(array $parent): bool => (int)$parent['follow_up_count'] > 0));

$actions = '<a class="admin-btn" href="'
	. nutritionist_e(app_url('/nutritionist/parent_form.php'))
	. '">Add parent</a>';

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
								<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/parent_form.php?id=' . (int)$parent['id'])); ?>">Edit</a>
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

<?php
nutritionist_layout_end();

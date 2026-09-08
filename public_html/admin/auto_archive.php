<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_helpers.php';
require_once __DIR__ . '/../includes/audit_logger.php';

start_secure_session();
require_permission('children.view');

$conn = get_db_connection();

// ── POST: Run auto-archive ──
$archiveResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'run_archive') {
	require_permission('children.edit');

	$ageMonthsThreshold = 60;

	$res = mysqli_query(
		$conn,
		"SELECT c.id, c.child_code, c.first_name, c.last_name, c.birthdate,
		        TIMESTAMPDIFF(MONTH, c.birthdate, CURDATE()) AS age_months
		 FROM children c
		 WHERE c.status = 'active'
		   AND TIMESTAMPDIFF(MONTH, c.birthdate, CURDATE()) >= {$ageMonthsThreshold}
		 ORDER BY c.birthdate ASC"
	);

	$archived = 0;
	$errors = [];

	if ($res !== false) {
		while ($row = mysqli_fetch_assoc($res)) {
			$childId = (int)$row['id'];
			$name = trim($row['first_name'] . ' ' . $row['last_name']);

			$stmt = mysqli_prepare($conn, 'UPDATE children SET status = ? WHERE id = ? AND status = ?');
			$inactive = 'inactive';
			$active = 'active';
			mysqli_stmt_bind_param($stmt, 'sis', $inactive, $childId, $active);

			if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) > 0) {
				$archived++;
				log_action(
					'UPDATE_CHILD',
					"Auto-archived child #{$childId} ({$row['child_code']}) — reached {$row['age_months']} months of age.",
					'warning'
				);
			} else {
				$errors[] = "Failed to archive #{$childId} ({$name})";
			}
			mysqli_stmt_close($stmt);
		}
	} else {
		$errors[] = 'Query failed: ' . mysqli_error($conn);
	}

	$archiveResult = ['archived' => $archived, 'errors' => $errors];
}

// ── Fetch children who are eligible for archival (>= 60 months) ──
$eligibleRes = mysqli_query(
	$conn,
	"SELECT c.id, c.child_code, c.first_name, c.last_name, c.birthdate,
	        TIMESTAMPDIFF(MONTH, c.birthdate, CURDATE()) AS age_months,
	        TIMESTAMPDIFF(DAY, c.birthdate, CURDATE()) AS age_days,
	        bg.name AS barangay_name,
	        (SELECT COUNT(*) FROM measurements m WHERE m.child_id = c.id) AS measurement_count,
	        (SELECT MAX(m.measurement_date) FROM measurements m WHERE m.child_id = c.id) AS last_measured
	 FROM children c
	 LEFT JOIN barangays bg ON bg.id = c.barangay_id
	 WHERE c.status = 'active'
	   AND TIMESTAMPDIFF(MONTH, c.birthdate, CURDATE()) >= 60
	 ORDER BY c.birthdate ASC"
);

$eligible = [];
if ($eligibleRes !== false) {
	while ($row = mysqli_fetch_assoc($eligibleRes)) {
		$eligible[] = $row;
	}
}

// ── Fetch recently archived children ──
$archivedRes = mysqli_query(
	$conn,
	"SELECT c.id, c.child_code, c.first_name, c.last_name, c.birthdate,
	        TIMESTAMPDIFF(MONTH, c.birthdate, CURDATE()) AS age_months,
	        bg.name AS barangay_name
	 FROM children c
	 LEFT JOIN barangays bg ON bg.id = c.barangay_id
	 WHERE c.status = 'inactive'
	 ORDER BY c.last_name ASC, c.first_name ASC
	 LIMIT 50"
);

$recentlyArchived = [];
if ($archivedRes !== false) {
	while ($row = mysqli_fetch_assoc($archivedRes)) {
		$recentlyArchived[] = $row;
	}
}

admin_layout_start('Auto-Archive', 'Automatically archive children who have reached 60 months (1857 days) of age. Archived children are excluded from tracking, exports, and reports.', 'auto_archive');
?>

<?php if ($archiveResult !== null): ?>
<div style="background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:12px;padding:18px;margin-bottom:18px;">
	<div style="font-weight:700;font-size:14px;margin-bottom:8px;">Archive Complete</div>
	<div style="font-size:13px;color:var(--admin-muted);">
		Archived <strong><?php echo (int)$archiveResult['archived']; ?></strong> child(ren) who reached 60 months.
	</div>
	<?php if (!empty($archiveResult['errors'])): ?>
	<div style="font-size:12px;color:#dc2626;margin-top:8px;">
		<strong>Errors:</strong>
		<ul style="margin:4px 0 0 16px;">
			<?php foreach ($archiveResult['errors'] as $err): ?>
				<li><?php echo admin_e($err); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php endif; ?>
</div>
<?php endif; ?>

<section style="margin-bottom:18px;">
	<div class="admin-grid-cards">
		<article class="admin-card">
			<div class="admin-card-row">
				<div class="admin-card-icon" style="background:rgba(217,119,6,.12);color:#d97706;"><?php echo admin_action_icon('calendar'); ?></div>
				<div class="admin-card-content">
					<div class="admin-card-label">Eligible for Archival</div>
					<div class="admin-card-value" style="<?php echo count($eligible) > 0 ? 'color:#d97706;' : ''; ?>"><?php echo count($eligible); ?></div>
				</div>
			</div>
		</article>
		<article class="admin-card">
			<div class="admin-card-row">
				<div class="admin-card-icon is-success"><?php echo admin_action_icon('verify'); ?></div>
				<div class="admin-card-content">
					<div class="admin-card-label">Already Archived</div>
					<div class="admin-card-value"><?php echo count($recentlyArchived); ?></div>
				</div>
			</div>
		</article>
	</div>
</section>

<?php if (count($eligible) > 0): ?>
<div style="margin-bottom:18px;">
	<form method="post" action="<?php echo admin_e(app_url('/admin/auto_archive.php')); ?>" onsubmit="return confirm('Archive <?php echo count($eligible); ?> children who are 60+ months old? This will exclude them from all tracking, reports, and exports.');">
		<input type="hidden" name="action" value="run_archive">
		<button class="admin-btn-primary" type="submit"><?php echo admin_action_icon('cancel'); ?> Archive <?php echo count($eligible); ?> Aged-Out Child(ren)</button>
	</form>
</div>

<div style="overflow-x:auto;">
	<table class="admin-table">
		<thead>
			<tr>
				<th>Child</th>
				<th>Code</th>
				<th>Barangay</th>
				<th>Birthdate</th>
				<th>Age</th>
				<th>Measurements</th>
				<th>Last Measured</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($eligible as $ch): ?>
			<tr>
				<td><strong><?php echo admin_e($ch['first_name'] . ' ' . $ch['last_name']); ?></strong></td>
				<td><?php echo admin_e($ch['child_code']); ?></td>
				<td><?php echo admin_e($ch['barangay_name'] ?? ''); ?></td>
				<td><?php echo date('M j, Y', strtotime($ch['birthdate'])); ?></td>
				<td><strong style="color:#d97706;"><?php echo (int)$ch['age_months']; ?> mo</strong></td>
				<td><?php echo (int)$ch['measurement_count']; ?></td>
				<td><?php echo $ch['last_measured'] ? date('M j, Y', strtotime($ch['last_measured'])) : '<span style="color:var(--admin-muted);">Never</span>'; ?></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
<?php else: ?>
<div style="text-align:center;padding:24px;color:var(--admin-muted);background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:12px;">
	No active children are currently 60+ months old.
</div>
<?php endif; ?>

<?php if (count($recentlyArchived) > 0): ?>
<div style="margin-top:24px;">
	<h3 style="font-size:14px;font-weight:700;margin-bottom:12px;">Recently Archived Children</h3>
	<div style="overflow-x:auto;">
		<table class="admin-table">
			<thead>
				<tr>
					<th>Child</th>
					<th>Code</th>
					<th>Barangay</th>
					<th>Age at Archive</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($recentlyArchived as $ch): ?>
				<tr>
					<td><?php echo admin_e($ch['first_name'] . ' ' . $ch['last_name']); ?></td>
					<td><?php echo admin_e($ch['child_code']); ?></td>
					<td><?php echo admin_e($ch['barangay_name'] ?? ''); ?></td>
					<td><?php echo (int)$ch['age_months']; ?> mo</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
<?php endif; ?>

<?php admin_layout_end(); ?>

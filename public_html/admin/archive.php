<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_helpers.php';
require_once __DIR__ . '/../includes/audit_logger.php';
require_once __DIR__ . '/../includes/who_calculator.php';

start_secure_session();
admin_require_access('children.view');

$tab = ($_GET['tab'] ?? 'archived') === 'auto' ? 'auto' : 'archived';

// Kind filter is server-side (like the nutritionist children tabs) so it
// works with or without JS: ?tab=archived&kind=all|staff|parent|child.
$kind = strtolower((string)($_GET['kind'] ?? 'all'));
if (!in_array($kind, ['all', 'staff', 'parent', 'child'], true)) {
	$kind = 'all';
}

$conn = get_db_connection();
$actor = current_user();

// ── POST: Run auto-archive (auto tab) ──
$archiveResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'run_archive') {
	admin_require_access('children.edit');

	$tab = 'auto';
	$ageMonthsThreshold = 60;

	// admin_fetch_all() decrypts AES-256-GCM PII (SK1:) automatically so
	// names render plaintext on keyed hosts and passthrough otherwise.
	$candidates = admin_fetch_all(
		"SELECT c.id, c.child_code, c.first_name, c.last_name, c.birthdate,
		        TIMESTAMPDIFF(MONTH, c.birthdate, CURDATE()) AS age_months
		 FROM children c
		 WHERE c.status = 'active'
		   AND TIMESTAMPDIFF(MONTH, c.birthdate, CURDATE()) >= ?
		 ORDER BY c.birthdate ASC",
		'i',
		[$ageMonthsThreshold]
	);

	$archived = 0;
	$errors = [];

	foreach ($candidates as $row) {
		$childId = (int)$row['id'];
		$name = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));

		$stmt = mysqli_prepare($conn, 'UPDATE children SET status = ? WHERE id = ? AND status = ?');
		$inactive = 'inactive';
		$active = 'active';
		mysqli_stmt_bind_param($stmt, 'sis', $inactive, $childId, $active);

		if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) > 0) {
			$archived++;
			log_action(
				$actor['id'] ?? null,
				'UPDATE_CHILD',
				'warning',
				"Auto-archived child #{$childId} ({$row['child_code']}) — reached {$row['age_months']} months of age."
			);
		} else {
			$errors[] = "Failed to archive #{$childId} ({$name})";
		}
		mysqli_stmt_close($stmt);
	}

	$archiveResult = ['archived' => $archived, 'errors' => $errors];
}

// ── Auto tab data: eligible + recently archived (SK-decrypted via admin_fetch_all) ──
$eligible = [];
$recentlyArchived = [];
if ($tab === 'auto') {
	$eligible = admin_fetch_all(
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

	$recentlyArchived = admin_fetch_all(
		"SELECT c.id, c.child_code, c.first_name, c.last_name, c.birthdate,
		        TIMESTAMPDIFF(MONTH, c.birthdate, CURDATE()) AS age_months,
		        bg.name AS barangay_name
		 FROM children c
		 LEFT JOIN barangays bg ON bg.id = c.barangay_id
		 WHERE c.status = 'inactive'
		 ORDER BY c.last_name ASC, c.first_name ASC
		 LIMIT 50"
	);
}

// ── Archived tab data (same queries as the standalone *_archived.php pages) ──
$canViewStaff = has_permission('users.delete');
$canViewParents = has_permission('parents.delete');
$canViewChildren = has_permission('children.delete');

$archivedUsers = [];
$archivedParents = [];
$archivedChildren = [];

if ($tab === 'archived') {
	if ($canViewStaff) {
		$archivedUsers = admin_fetch_all(
			'SELECT u.id, u.name, u.email, u.username, u.phone, b.name AS barangay, u.status, r.name AS role_name
			 FROM users u
			 INNER JOIN roles r ON r.id = u.role_id
			 LEFT JOIN barangays b ON b.id = u.barangay_id
			 WHERE u.status = \'inactive\'
			 ORDER BY u.name ASC'
		);
	}
	if ($canViewParents) {
		$archivedParents = admin_fetch_all(
			"SELECT
				p.id,
				p.name,
				p.email,
				p.parent_type,
				p.phone,
				p.barangay_id,
				b.name AS barangay,
				p.status,
				COUNT(DISTINCT c.id) AS children_count
			 FROM parents p
			 LEFT JOIN barangays b ON b.id = p.barangay_id
			 LEFT JOIN children c ON c.parent_id = p.id
			 WHERE p.status = 'inactive'
			 GROUP BY p.id, p.name, p.email, p.parent_type, p.phone, p.barangay_id, b.name, p.status
			 ORDER BY p.id DESC"
		);
	}
	if ($canViewChildren) {
		$archivedChildren = admin_fetch_all(
			"SELECT
				c.id,
				c.child_code,
				c.first_name,
				c.middle_name,
				c.last_name,
				c.birthdate,
				c.sex,
				bg.name AS barangay,
				p.name AS parent_name,
				c.status
			 FROM children c
			 INNER JOIN parents p ON p.id = c.parent_id
			 LEFT JOIN barangays bg ON bg.id = c.barangay_id
			 WHERE c.status = 'inactive'
			 ORDER BY c.id DESC"
		);
	}
}

// ── Unified archived rows: one table, filterable by kind (staff/parent/child) ──
$archiveRows = [];
if ($tab === 'archived') {
	foreach ($archivedUsers as $u) {
		$archiveRows[] = [
			'kind' => 'staff',
			'kind_label' => ucfirst((string)($u['role_name'] ?? 'staff')),
			'pill' => (($u['role_name'] ?? '') === 'admin') ? 'is-warn' : 'is-success',
			'name' => (string)($u['name'] ?? ''),
			'sub' => (string)($u['username'] ?? ''),
			'detail' => (string)($u['email'] ?? ''),
			'barangay' => (string)($u['barangay'] ?? 'All barangays'),
			'info' => (string)($u['phone'] ?? ''),
			'id' => (int)$u['id'],
			'restore' => app_url('/api/admin/users_restore.php'),
			'delete' => app_url('/admin/users_archived.php?delete=' . (int)$u['id']),
		];
	}
	foreach ($archivedParents as $p) {
		$kidCount = (int)($p['children_count'] ?? 0);
		$archiveRows[] = [
			'kind' => 'parent',
			'kind_label' => 'Parent',
			'pill' => 'is-info',
			'name' => (string)($p['name'] ?? ''),
			'sub' => (string)($p['parent_type'] ?? ''),
			'detail' => (string)($p['email'] ?? ''),
			'barangay' => (string)($p['barangay'] ?? ''),
			'info' => $kidCount . ' child' . ($kidCount === 1 ? '' : 'ren'),
			'id' => (int)$p['id'],
			'restore' => app_url('/api/admin/parents_restore.php'),
			'delete' => null,
		];
	}
	foreach ($archivedChildren as $c) {
		$fullName = trim((string)($c['first_name'] ?? '') . ' ' . (string)($c['middle_name'] ?? '') . ' ' . (string)($c['last_name'] ?? ''));
		$age = doh_age((string)$c['birthdate']) ?? ['days' => 0, 'months' => 0];
		$archiveRows[] = [
			'kind' => 'child',
			'kind_label' => 'Child',
			'pill' => 'is-muted',
			'name' => $fullName !== '' ? $fullName : (string)($c['child_code'] ?? ''),
			'sub' => (string)($c['birthdate'] ?? ''),
			'detail' => (string)($c['child_code'] ?? '') . ' · ' . (string)($c['parent_name'] ?? ''),
			'barangay' => (string)($c['barangay'] ?? ''),
			'info' => (int)$age['days'] . ' d · ' . (int)$age['months'] . ' mo',
			'id' => (int)$c['id'],
			'restore' => app_url('/api/admin/children_restore.php'),
			'delete' => app_url('/admin/children_archived.php?delete=' . (int)$c['id']),
		];
	}
}

// ── Tab counts (cheap COUNTs for the inactive tab so both tabs show totals) ──
$archivedTotal = count($archiveRows);
$eligibleCount = count($eligible);

// Kind pill totals come from the FULL row set so they never drop to zero
// when a kind filter is active.
$kindTotals = array_count_values(array_column($archiveRows, 'kind'));

// Clamp kind to permitted sections, then filter rows for display.
if (($kind === 'staff' && !$canViewStaff)
	|| ($kind === 'parent' && !$canViewParents)
	|| ($kind === 'child' && !$canViewChildren)
) {
	$kind = 'all';
}
if ($kind !== 'all') {
	$archiveRows = array_values(array_filter(
		$archiveRows,
		static fn(array $r): bool => ($r['kind'] ?? '') === $kind
	));
}
if ($tab === 'auto') {
	$archivedTotal = 0;
	if ($canViewStaff) {
		$r = admin_fetch_one("SELECT COUNT(*) AS cnt FROM users WHERE status = 'inactive'");
		$archivedTotal += (int)($r['cnt'] ?? 0);
	}
	if ($canViewParents) {
		$r = admin_fetch_one("SELECT COUNT(*) AS cnt FROM parents WHERE status = 'inactive'");
		$archivedTotal += (int)($r['cnt'] ?? 0);
	}
	if ($canViewChildren) {
		$r = admin_fetch_one("SELECT COUNT(*) AS cnt FROM children WHERE status = 'inactive'");
		$archivedTotal += (int)($r['cnt'] ?? 0);
	}
} else {
	$r = admin_fetch_one("SELECT COUNT(*) AS cnt FROM children WHERE status = 'active' AND TIMESTAMPDIFF(MONTH, birthdate, CURDATE()) >= 60");
	$eligibleCount = (int)($r['cnt'] ?? 0);
}

$restoreSvg = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>';

$archiveBase = app_url('/admin/archive.php');

$cntStaff = (int)($kindTotals['staff'] ?? 0);
$cntParent = (int)($kindTotals['parent'] ?? 0);
$cntChild = (int)($kindTotals['child'] ?? 0);

admin_layout_start('Archive', 'Archived staff, parent and child records, plus automatic archival of children aged 60 months and older.', 'archive');
?>

<style>
/* Tabs mirror nutritionist/children.php (.rp-tabs/.rp-tab) for design consistency. */
.rp-tabs{display:flex;gap:0;border-bottom:2px solid var(--admin-border);margin:0 0 14px;overflow-x:auto}
.rp-tab{display:inline-flex;align-items:center;gap:6px;padding:10px 18px;font-size:13px;font-weight:600;color:var(--admin-muted);text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;transition:color .15s,border-color .15s,background .15s;border-radius:8px 8px 0 0;white-space:nowrap}
.rp-tab:hover{color:var(--admin-text);background:var(--admin-surface-alt)}
.rp-tab.is-active{color:var(--admin-primary);border-bottom-color:var(--admin-primary);background:transparent}
.rp-tab span{font-size:11px;opacity:.6}
.rp-kind-pills{display:flex;gap:6px;flex-wrap:wrap}
.rp-kind-pill{font-size:12px;font-weight:600;padding:7px 14px;border-radius:999px;border:1px solid var(--admin-border);background:var(--admin-surface);color:var(--admin-muted);cursor:pointer;transition:all .15s;white-space:nowrap;text-decoration:none;display:inline-block}
.rp-kind-pill:hover{border-color:rgba(11,110,79,.35);color:var(--admin-primary)}
.rp-kind-pill.is-active{background:var(--admin-primary-soft);color:var(--admin-primary);border-color:rgba(11,110,79,.35)}
.archive-section-head{align-items:center}
.archive-section-head > .admin-section-head-copy{flex:0 1 280px;min-width:220px}
.archive-section-head > .admin-toolbar{display:flex !important;flex-wrap:nowrap !important;align-items:center;gap:10px;flex:1 1 auto;min-width:0}
.archive-section-head .rp-kind-pills{flex-wrap:nowrap;min-width:0}
.archive-section-head .admin-search{width:auto;min-width:220px;flex:1 1 260px}
#archive-table{min-width:760px}
#archive-table td{vertical-align:middle}
@media (max-width:1100px){
	.archive-section-head > .admin-toolbar{flex-wrap:wrap !important}
	.archive-section-head .rp-kind-pills{flex-wrap:wrap}
}
@media (max-width:720px){
	.archive-section-head > .admin-section-head-copy{min-width:0}
	.archive-section-head > .admin-toolbar{flex-wrap:wrap !important}
	.archive-section-head .admin-search{flex:1 1 220px}
}
</style>

<div class="rp-tabs" role="tablist" aria-label="Archive sections">
	<a class="rp-tab <?php echo $tab === 'archived' ? 'is-active' : ''; ?>" role="tab" aria-selected="<?php echo $tab === 'archived' ? 'true' : 'false'; ?>" href="<?php echo admin_e($archiveBase . '?tab=archived'); ?>">Archived <span>(<?php echo (int)$archivedTotal; ?>)</span></a>
	<a class="rp-tab <?php echo $tab === 'auto' ? 'is-active' : ''; ?>" role="tab" aria-selected="<?php echo $tab === 'auto' ? 'true' : 'false'; ?>" href="<?php echo admin_e($archiveBase . '?tab=auto'); ?>">Auto-archive 60 months <span>(<?php echo (int)$eligibleCount; ?>)</span></a>
</div>

<?php if ($tab === 'archived'): ?>

<?php if (!$canViewStaff && !$canViewParents && !$canViewChildren): ?>
<div style="text-align:center;padding:24px;color:var(--admin-muted);background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:12px;">
	You do not have permission to view archived records.
</div>
<?php endif; ?>

<section class="admin-section" id="archive-table-wrap">
	<div class="admin-section-head archive-section-head">
		<div class="admin-section-head-copy">
			<h2 class="admin-section-title">Archived Records</h2>
			<p class="admin-section-subtitle"><?php echo (int)$archivedTotal; ?> archived record(s).</p>
		</div>
		<div class="admin-toolbar" style="margin:0;">
			<?php if (($canViewStaff ? 1 : 0) + ($canViewParents ? 1 : 0) + ($canViewChildren ? 1 : 0) > 1): ?>
			<div class="rp-kind-pills" role="tablist" aria-label="Filter by record type">
				<a class="rp-kind-pill <?php echo $kind === 'all' ? 'is-active' : ''; ?>" role="tab" aria-selected="<?php echo $kind === 'all' ? 'true' : 'false'; ?>" href="<?php echo admin_e($archiveBase . '?tab=archived&kind=all'); ?>">All (<?php echo (int)$archivedTotal; ?>)</a>
				<?php if ($canViewStaff): ?><a class="rp-kind-pill <?php echo $kind === 'staff' ? 'is-active' : ''; ?>" role="tab" aria-selected="<?php echo $kind === 'staff' ? 'true' : 'false'; ?>" href="<?php echo admin_e($archiveBase . '?tab=archived&kind=staff'); ?>">Staff (<?php echo (int)$cntStaff; ?>)</a><?php endif; ?>
				<?php if ($canViewParents): ?><a class="rp-kind-pill <?php echo $kind === 'parent' ? 'is-active' : ''; ?>" role="tab" aria-selected="<?php echo $kind === 'parent' ? 'true' : 'false'; ?>" href="<?php echo admin_e($archiveBase . '?tab=archived&kind=parent'); ?>">Parents (<?php echo (int)$cntParent; ?>)</a><?php endif; ?>
				<?php if ($canViewChildren): ?><a class="rp-kind-pill <?php echo $kind === 'child' ? 'is-active' : ''; ?>" role="tab" aria-selected="<?php echo $kind === 'child' ? 'true' : 'false'; ?>" href="<?php echo admin_e($archiveBase . '?tab=archived&kind=child'); ?>">Children (<?php echo (int)$cntChild; ?>)</a><?php endif; ?>
			</div>
			<?php endif; ?>
			<input class="admin-search" type="search" placeholder="Search archived records" data-admin-filter="#archive-table">
		</div>
	</div>
	<div class="admin-table-wrap">
		<table class="admin-table" id="archive-table">
			<thead>
				<tr>
					<th>Type</th>
					<th>Name</th>
					<th>Detail</th>
					<th>Barangay</th>
					<th>Info</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
				<?php if ($archiveRows === []): ?>
					<tr><td colspan="6" style="color:var(--admin-muted);text-align:center;padding:24px;">No archived records.</td></tr>
				<?php else: ?>
					<?php foreach ($archiveRows as $rowIndex => $ar): ?>
						<tr<?php echo admin_paged_row_attr($rowIndex, 10); ?> data-filter-text="<?php echo admin_e(strtolower($ar['kind'] . ' ' . $ar['kind_label'] . ' ' . $ar['name'] . ' ' . $ar['sub'] . ' ' . $ar['detail'] . ' ' . $ar['barangay'] . ' ' . $ar['info'])); ?>">
							<td><span class="admin-pill <?php echo admin_e($ar['pill']); ?>"><?php echo admin_e($ar['kind_label']); ?></span></td>
							<td>
								<div style="font-weight:600;color:var(--admin-text);"><?php echo admin_e($ar['name']); ?></div>
								<?php if ($ar['sub'] !== ''): ?><div class="admin-mini"><?php echo admin_e($ar['sub']); ?></div><?php endif; ?>
							</td>
							<td style="color:var(--admin-muted);"><?php echo admin_e($ar['detail']); ?></td>
							<td style="color:var(--admin-muted);"><?php echo admin_e($ar['barangay']); ?></td>
							<td style="color:var(--admin-muted);"><?php echo admin_e($ar['info']); ?></td>
							<td>
								<div class="admin-actions">
									<form method="post" action="<?php echo admin_e($ar['restore']); ?>" style="display:inline;">
										<input type="hidden" name="id" value="<?php echo (int)$ar['id']; ?>">
										<button class="admin-icon-btn" title="Restore" type="submit" style="color:var(--admin-primary,#0b6e4f);">
											<?php echo $restoreSvg; ?>
										</button>
									</form>
									<?php if (!empty($ar['delete'])): ?>
									<a class="admin-icon-btn admin-icon-btn-danger" title="Delete permanently" href="<?php echo admin_e($ar['delete']); ?>">
										<?php echo admin_action_icon('delete'); ?>
									</a>
									<?php endif; ?>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
</section>

<?php else: ?>

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
	<form method="post" action="<?php echo admin_e(app_url('/admin/archive.php?tab=auto')); ?>" data-admin-confirm="Archive <?php echo count($eligible); ?> children who are 60+ months old? This will exclude them from all tracking, reports, and exports." data-admin-confirm-danger>
		<input type="hidden" name="action" value="run_archive">
		<button class="admin-btn-primary" type="submit"><?php echo admin_action_icon('cancel'); ?> Archive <?php echo count($eligible); ?> Aged-Out Child(ren)</button>
	</form>
</div>

<section class="admin-section">
	<div class="admin-section-head archive-section-head">
		<div class="admin-section-head-copy">
			<h2 class="admin-section-title">Eligible Children</h2>
			<p class="admin-section-subtitle"><?php echo count($eligible); ?> child(ren) aged 60+ months.</p>
		</div>
		<div class="admin-toolbar" style="margin:0;">
			<input class="admin-search" type="search" placeholder="Search eligible children" data-admin-filter="#auto-eligible-table">
		</div>
	</div>
	<div class="admin-table-wrap">
		<table class="admin-table" id="auto-eligible-table">
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
				<?php foreach ($eligible as $eligibleIndex => $ch): ?>
				<tr<?php echo admin_paged_row_attr($eligibleIndex, 10); ?> data-filter-text="<?php echo admin_e(strtolower($ch['first_name'] . ' ' . $ch['last_name'] . ' ' . $ch['child_code'] . ' ' . (string)($ch['barangay_name'] ?? ''))); ?>">
					<td>
						<div style="font-weight:600;color:var(--admin-text);"><?php echo admin_e($ch['first_name'] . ' ' . $ch['last_name']); ?></div>
					</td>
					<td><?php echo admin_e($ch['child_code']); ?></td>
					<td style="color:var(--admin-muted);"><?php echo admin_e($ch['barangay_name'] ?? ''); ?></td>
					<td><?php echo date('M j, Y', strtotime($ch['birthdate'])); ?></td>
					<td><strong style="color:#d97706;"><?php echo (int)$ch['age_months']; ?> mo</strong></td>
					<td><?php echo (int)$ch['measurement_count']; ?></td>
					<td><?php echo $ch['last_measured'] ? date('M j, Y', strtotime($ch['last_measured'])) : '<span style="color:var(--admin-muted);">Never</span>'; ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>
<?php else: ?>
<div style="text-align:center;padding:24px;color:var(--admin-muted);background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:12px;">
	No active children are currently 60+ months old.
</div>
<?php endif; ?>

<?php endif; ?>

<?php admin_layout_end(); ?>

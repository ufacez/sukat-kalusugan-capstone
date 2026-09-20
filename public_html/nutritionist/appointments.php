<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

$user = nutritionist_require_access();

// ── POST handlers (consultation requests only) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = (string)($_POST['action'] ?? '');
	$appointmentId = (int)($_POST['id'] ?? 0);

	// Scope every appointment write to the nutritionist's barangay (the
	// list views are scoped; without this a forged id could touch another
	// barangay's appointments).
	if (in_array($action, ['confirm_request', 'cancel_request', 'complete_request'], true) && $appointmentId > 0 && ($user['role'] ?? '') !== 'admin') {
		$scopeCheck = admin_fetch_one(
			'SELECT c.barangay_id FROM appointments a INNER JOIN children c ON c.id = a.child_id WHERE a.id = ? LIMIT 1',
			'i',
			[$appointmentId]
		);
		if ($scopeCheck !== null && (int)($scopeCheck['barangay_id'] ?? 0) !== (int)($user['barangay_id'] ?? 0)) {
			admin_redirect('/nutritionist/appointments.php', ['notice' => 'You can only manage appointments within your assigned barangay.', 'type' => 'error']);
		}
	}

	if ($action === 'confirm_request' && $appointmentId > 0) {
		nutritionist_require_write();
		$ok = admin_execute(
			"UPDATE appointments SET status = 'confirmed' WHERE id = ? AND nutritionist_id = ? AND created_by = 'parent' AND status = 'pending'",
			'ii',
			[$appointmentId, (int)$user['id']]
		);
		admin_redirect('/nutritionist/appointments.php?tab=incoming', $ok ? ['notice' => 'Appointment confirmed.'] : ['notice' => 'Could not confirm appointment.', 'type' => 'error']);
	}

	if ($action === 'cancel_request' && $appointmentId > 0) {
		nutritionist_require_write();
		$ok = admin_execute(
			"UPDATE appointments SET status = 'cancelled' WHERE id = ? AND nutritionist_id = ? AND status IN ('pending', 'confirmed')",
			'ii',
			[$appointmentId, (int)$user['id']]
		);
		admin_redirect('/nutritionist/appointments.php', $ok ? ['notice' => 'Appointment cancelled.'] : ['notice' => 'Could not cancel appointment.', 'type' => 'error']);
	}

	if ($action === 'complete_request' && $appointmentId > 0) {
		nutritionist_require_write();
		$recommendations = trim((string)($_POST['recommendations'] ?? ''));
		$ok = admin_execute(
			"UPDATE appointments SET status = 'completed', recommendations = ? WHERE id = ? AND nutritionist_id = ? AND status = 'confirmed'",
			'sii',
			[$recommendations, $appointmentId, (int)$user['id']]
		);
		admin_redirect('/nutritionist/appointments.php', $ok ? ['notice' => 'Appointment marked as completed.'] : ['notice' => 'Could not complete appointment.', 'type' => 'error']);
	}
}

// ── Consultation requests for this nutritionist ──
$baseSelect = "SELECT a.id, a.child_id, a.parent_id, a.scheduled_at, a.notes, a.recommendations, a.location, a.created_at, a.created_by,
		a.status AS appt_status,
		c.first_name, c.last_name, c.child_code, c.birthdate, c.sex,
		bg.name AS barangay_name,
		p.name AS parent_name, p.phone AS parent_phone
	 FROM appointments a
	 INNER JOIN children c ON c.id = a.child_id
	 LEFT JOIN barangays bg ON bg.id = c.barangay_id
	 LEFT JOIN parents p ON p.id = a.parent_id
	 WHERE a.nutritionist_id = ?";

$incoming = admin_fetch_all(
	$baseSelect . " AND a.created_by = 'parent' AND a.status IN ('pending', 'confirmed')
	 ORDER BY a.scheduled_at ASC, a.id ASC",
	'i',
	[(int)$user['id']]
);

$outgoing = admin_fetch_all(
	$baseSelect . " AND a.created_by = 'nutritionist' AND a.status IN ('pending', 'confirmed')
	 ORDER BY a.scheduled_at ASC, a.id ASC",
	'i',
	[(int)$user['id']]
);

$history = admin_fetch_all(
	$baseSelect . " AND a.status IN ('completed', 'cancelled')
	 ORDER BY a.scheduled_at DESC, a.id DESC",
	'i',
	[(int)$user['id']]
);

$today = new DateTimeImmutable('today');
$now = new DateTimeImmutable('now');

// ── Tab / pagination ──
$validTabs = ['incoming', 'outgoing', 'history'];
$activeTab = in_array(($_GET['tab'] ?? ''), $validTabs, true) ? ($_GET['tab'] ?? '') : 'incoming';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 5;

// ── Calendar ──
$monthParam = (string)($_GET['m'] ?? $now->format('Y-m'));
try { $monthAnchor = new DateTimeImmutable($monthParam . '-01'); } catch (Exception) { $monthAnchor = $now->modify('first day of this month'); }
$calendarYear = (int)$monthAnchor->format('Y');
$calendarMonth = (int)$monthAnchor->format('n');
$monthLabel = $monthAnchor->format('F Y');
$prevMonth = $monthAnchor->modify('-1 month')->format('Y-m');
$nextMonth = $monthAnchor->modify('+1 month')->format('Y-m');

// ── Active group ──
$allGroups = ['incoming' => $incoming, 'outgoing' => $outgoing, 'history' => $history];
$activeGroup = $allGroups[$activeTab];
$totalRows = count($activeGroup);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;
$pageRows = array_slice($activeGroup, $offset, $perPage);

// ── Calendar entries (consultations only) ──
$calendarEntries = [];
foreach (array_merge($incoming, $outgoing) as $req) {
	try { $reqDt = new DateTimeImmutable($req['scheduled_at']); } catch (Exception) { continue; }
	if ((int)$reqDt->format('Y') !== $calendarYear || (int)$reqDt->format('n') !== $calendarMonth) continue;
	$dayKey = (int)$reqDt->format('j');
	$reqTime = $reqDt->format('g:i A');
	$isConfirmed = $req['appt_status'] === 'confirmed';
	$calColor = $isConfirmed ? '#16a34a' : nutritionist_calendar_color('parent_request');
	$calLabel = $isConfirmed ? 'Confirmed' : 'Pending';
	$fromParent = ($req['created_by'] ?? '') === 'parent';
	$calendarEntries[$dayKey][] = [
		'type' => 'parent_request',
		'color' => $calColor,
		'title' => $req['first_name'] . ' ' . $req['last_name'] . ' (' . $calLabel . ')',
		'time' => $reqTime,
		'location' => $req['barangay_name'] ?? '',
		'status' => $req['appt_status'],
		'url' => app_url('/nutritionist/appointments.php?tab=' . ($fromParent ? 'incoming' : 'outgoing')),
	];
}
ksort($calendarEntries);

$actions = '<a class="admin-btn" href="'
	. nutritionist_e(app_url('/nutritionist/appointment_form.php'))
	. '">' . admin_action_icon('add') . ' New request</a>';

nutritionist_layout_start('Appointments', 'Consultation requests between parents and nutritionists.', 'appointments', $actions);
?>

<style>
.rp-tabs{display:flex;gap:0;border-bottom:2px solid var(--admin-border);margin:0 0 18px}
.rp-tab{display:inline-flex;align-items:center;gap:6px;padding:10px 18px;font-size:13px;font-weight:600;color:var(--admin-muted);text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;transition:color .15s,border-color .15s,background .15s;border-radius:8px 8px 0 0}
.rp-tab:hover{color:var(--admin-text);background:var(--admin-surface-alt)}
.rp-tab.is-active{color:var(--admin-primary);border-bottom-color:var(--admin-primary);background:transparent}
.rp-tab span{font-size:11px;opacity:.6}
.appt-card{background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:14px;padding:18px;margin-bottom:18px}
.appt-card-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}
.appt-card-title{font-size:14px;font-weight:700;color:var(--admin-text);margin:0}
.appt-card-sub{font-size:12px;color:var(--admin-muted);margin-top:2px}
.appt-pill{display:inline-block;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:600;line-height:1.6}
.appt-pill.due{background:rgba(217,119,6,.12);color:#d97706}
.appt-pill.overdue{background:rgba(220,38,38,.12);color:#dc2626}
.appt-pill.upcoming{background:rgba(22,163,74,.12);color:#16a34a}
.appt-pill.completed{background:rgba(37,99,235,.12);color:#2563eb}
.appt-table{width:100%;border-collapse:collapse;font-size:12px}
.appt-table th{text-align:left;padding:8px 10px;border-bottom:2px solid var(--admin-border);color:var(--admin-muted);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.04em}
.appt-table td{padding:8px 10px;border-bottom:1px solid var(--admin-border);vertical-align:middle}
.appt-table tr:hover td{background:var(--admin-surface-alt)}
.appt-pagination{display:flex;justify-content:space-between;align-items:center;padding:12px 0;font-size:12px;color:var(--admin-muted)}
.sk-cal-day-more{font-size:9px;color:var(--admin-muted);line-height:1.3}
</style>

<!-- ============ TABS ============ -->
<div class="rp-tabs">
	<a class="rp-tab <?php echo $activeTab === 'incoming' ? 'is-active' : ''; ?>" href="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php?tab=incoming')); ?>">From Parents <span>(<?php echo count($incoming); ?>)</span></a>
	<a class="rp-tab <?php echo $activeTab === 'outgoing' ? 'is-active' : ''; ?>" href="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php?tab=outgoing')); ?>">My Requests <span>(<?php echo count($outgoing); ?>)</span></a>
	<a class="rp-tab <?php echo $activeTab === 'history' ? 'is-active' : ''; ?>" href="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php?tab=history')); ?>">History <span>(<?php echo count($history); ?>)</span></a>
</div>

<!-- ============ TABLE ============ -->
<div class="appt-card">
	<?php
	$tabLabels = ['incoming' => 'Requests From Parents', 'outgoing' => 'My Requests to Parents', 'history' => 'Appointment History'];
	$tabSubs = ['incoming' => 'Consultation requests from parents awaiting your confirmation', 'outgoing' => 'Consultation requests you sent to parents', 'history' => 'Completed and cancelled consultations'];
	?>
	<div class="appt-card-head">
		<div>
			<h3 class="appt-card-title"><?php echo $tabLabels[$activeTab]; ?></h3>
			<p class="appt-card-sub"><?php echo $tabSubs[$activeTab]; ?></p>
		</div>
	</div>

	<?php if (empty($pageRows)): ?>
		<div style="text-align:center;padding:24px;color:var(--admin-muted);"><?php echo $activeTab === 'history' ? 'No past appointments.' : 'No requests here yet.'; ?></div>
	<?php else: ?>
	<div style="overflow-x:auto;">
		<table class="appt-table">
			<thead>
				<tr>
					<th style="width:150px;">Child</th>
					<th style="width:120px;"><?php echo $activeTab === 'incoming' ? 'Parent' : 'Requested By'; ?></th>
					<th style="width:110px;">Barangay</th>
					<th style="width:120px;">Schedule</th>
					<th style="width:150px;">Notes</th>
					<th style="width:80px;">Status</th>
					<th style="width:100px;">Actions</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($pageRows as $req):
					$fullName = $req['first_name'] . ' ' . $req['last_name'];
					$reqDate = date('M j, g:i A', strtotime($req['scheduled_at']));
					$notes = $req['notes'] ? '<span style="color:var(--admin-text);">' . nutritionist_e($req['notes']) . '</span>' : '<span style="color:var(--admin-muted);font-style:italic;">None</span>';
					$reqStatus = $req['appt_status'] ?? 'pending';
					$reqPillClass = match ($reqStatus) { 'confirmed' => 'upcoming', 'completed' => 'completed', 'cancelled' => 'overdue', default => 'due' };
					$reqPillLabel = ucfirst($reqStatus);
					$fromParent = ($req['created_by'] ?? '') === 'parent';
				?>
				<tr>
					<td>
						<strong><?php echo nutritionist_e($fullName); ?></strong>
						<div style="font-size:10px;color:var(--admin-muted);"><?php echo nutritionist_e($req['child_code']); ?></div>
					</td>
					<td>
						<?php if ($activeTab === 'incoming'): ?>
							<?php echo nutritionist_e($req['parent_name'] ?? '—'); ?>
							<?php if ($req['parent_phone']): ?>
								<div style="font-size:10px;color:var(--admin-muted);"><?php echo nutritionist_e($req['parent_phone']); ?></div>
							<?php endif; ?>
						<?php else: ?>
							<?php echo $fromParent ? nutritionist_e($req['parent_name'] ?? 'Parent') : '<span style="color:var(--admin-muted);">You</span>'; ?>
						<?php endif; ?>
					</td>
					<td><?php echo nutritionist_e($req['barangay_name'] ?? ''); ?></td>
					<td>
						<strong style="color:#2563eb;"><?php echo $reqDate; ?></strong>
					</td>
					<td><div style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo $notes; ?></div></td>
					<td><span class="appt-pill <?php echo $reqPillClass; ?>"><?php echo $reqPillLabel; ?></span></td>
					<td>
						<div style="display:flex;gap:4px;">
							<?php if ($reqStatus === 'pending' && $fromParent && $activeTab !== 'history'): ?>
							<form method="post" style="display:inline;">
								<input type="hidden" name="action" value="confirm_request">
								<input type="hidden" name="id" value="<?php echo (int)$req['id']; ?>">
								<button type="submit" class="admin-btn" title="Confirm">Confirm</button>
							</form>
							<form method="post" style="display:inline;">
								<input type="hidden" name="action" value="cancel_request">
								<input type="hidden" name="id" value="<?php echo (int)$req['id']; ?>">
								<button type="submit" class="admin-btn-danger" title="Cancel">Cancel</button>
							</form>
							<?php elseif ($reqStatus === 'pending' && !$fromParent && $activeTab !== 'history'): ?>
							<form method="post" style="display:inline;">
								<input type="hidden" name="action" value="cancel_request">
								<input type="hidden" name="id" value="<?php echo (int)$req['id']; ?>">
								<button type="submit" class="admin-btn-danger" title="Withdraw">Withdraw</button>
							</form>
						<?php elseif ($reqStatus === 'confirmed' && $activeTab !== 'history'): ?>
						<button type="button" class="admin-btn" title="Mark completed" data-complete-open="<?php echo (int)$req['id']; ?>" data-complete-child="<?php echo nutritionist_e($fullName); ?>" data-complete-when="<?php echo nutritionist_e($reqDate); ?>">Done</button>
						<form method="post" style="display:inline;">
							<input type="hidden" name="action" value="cancel_request">
							<input type="hidden" name="id" value="<?php echo (int)$req['id']; ?>">
							<button type="submit" class="admin-btn-danger" title="Cancel">Cancel</button>
						</form>
						<?php elseif ($activeTab === 'history'): ?>
						<button type="button" class="admin-btn-secondary" data-appt-view="<?php echo (int)$req['id']; ?>">View</button>
						<?php else: ?>
						<span style="color:var(--admin-muted);font-size:11px;">—</span>
						<?php endif; ?>
						</div>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<?php if ($totalPages > 1): ?>
	<div class="appt-pagination">
		<span>Showing <?php echo ($offset + 1); ?>–<?php echo min($offset + $perPage, $totalRows); ?> of <?php echo $totalRows; ?></span>
		<div style="display:flex;gap:4px;">
			<?php
			$pageLink = function (int $p) use ($activeTab, $monthParam, $now) {
				$params = ['tab' => $activeTab];
				if ($p > 1) $params['page'] = $p;
				if ($monthParam !== $now->format('Y-m')) $params['m'] = $monthParam;
				return app_url('/nutritionist/appointments.php' . '?' . http_build_query($params));
			};
			?>
			<a class="admin-btn-secondary" href="<?php echo nutritionist_e($pageLink($page - 1)); ?>" <?php echo $page <= 1 ? 'style="pointer-events:none;opacity:.4;"' : ''; ?>>Prev</a>
			<span style="padding:4px 8px;">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
			<a class="admin-btn-secondary" href="<?php echo nutritionist_e($pageLink($page + 1)); ?>" <?php echo $page >= $totalPages ? 'style="pointer-events:none;opacity:.4;"' : ''; ?>>Next</a>
		</div>
	</div>
	<?php endif; ?>
	<?php endif; ?>
</div>

<!-- ============ CALENDAR (always visible) ============ -->
<div class="appt-card">
	<div class="appt-card-head">
		<h3 class="appt-card-title"><?php echo nutritionist_e($monthLabel); ?></h3>
		<div style="display:flex;gap:6px;">
			<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php?tab=' . $activeTab . '&m=' . $prevMonth)); ?>" style="padding:4px 8px;font-size:11px;">&laquo; Prev</a>
			<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php?tab=' . $activeTab . '&m=' . $nextMonth)); ?>" style="padding:4px 8px;font-size:11px;">Next &raquo;</a>
		</div>
	</div>
	<div class="sk-cal-wrap" data-sk-calendar>
		<?php echo nutritionist_render_calendar_grid($monthAnchor, $calendarEntries, $today); ?>
	</div>
</div>

<?php
// Details data for the history View modal (all tabs, keyed by id).
$apptDetailJson = [];
foreach (['incoming' => $incoming, 'outgoing' => $outgoing, 'history' => $history] as $groupRows) {
	foreach ($groupRows as $row) {
		$st = $row['appt_status'] ?? 'pending';
		$pill = match ($st) { 'confirmed' => 'upcoming', 'completed' => 'completed', 'cancelled' => 'overdue', default => 'due' };
		$apptDetailJson[(int)$row['id']] = [
			'id' => (int)$row['id'],
			'child' => $row['first_name'] . ' ' . $row['last_name'],
			'code' => (string)($row['child_code'] ?? ''),
			'parent' => (string)($row['parent_name'] ?? '—'),
			'phone' => (string)($row['parent_phone'] ?? ''),
			'barangay' => (string)($row['barangay_name'] ?? ''),
			'when' => date('M j, Y g:i A', strtotime((string)$row['scheduled_at'])),
			'location' => (string)($row['location'] ?? 'Barangay Health Center'),
			'status' => ucfirst((string)$st),
			'pill' => $pill,
			'notes' => (string)($row['notes'] ?? ''),
			'recommendations' => (string)($row['recommendations'] ?? ''),
		];
	}
}
?>

<style>
#completeModal, #apptDetailModal { display: none; }
#completeModal.is-open, #apptDetailModal.is-open { display: flex; }
.appt-detail-row { display: flex; justify-content: space-between; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--admin-border); font-size: 13px; }
.appt-detail-row:last-child { border-bottom: none; }
.appt-detail-row .k { color: var(--admin-muted); font-weight: 600; flex-shrink: 0; }
.appt-detail-row .v { color: var(--admin-text); text-align: right; min-width: 0; word-break: break-word; }
.appt-detail-notes { margin-top: 12px; }
.appt-detail-notes .k { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--admin-muted); margin-bottom: 4px; }
.appt-detail-notes .v { font-size: 13px; color: var(--admin-text); background: var(--admin-surface-alt); border: 1px solid var(--admin-border); border-radius: 8px; padding: 10px 12px; white-space: pre-wrap; }
</style>

<!-- ============ COMPLETE MODAL (Done + optional recommendations) ============ -->
<div class="admin-modal-overlay" id="completeModal">
	<div class="admin-modal" style="max-width:480px;" role="dialog" aria-modal="true" aria-label="Complete appointment">
		<div class="admin-modal-head">
			<h3>Complete appointment</h3>
			<button class="admin-modal-close" id="completeModalClose" type="button">&times;</button>
		</div>
		<form method="post" action="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php')); ?>" style="padding:16px 20px;">
			<input type="hidden" name="action" value="complete_request">
			<input type="hidden" name="id" id="completeModalId" value="0">
			<p style="font-size:13px;color:var(--admin-text);margin:0 0 4px;"><strong id="completeModalChild"></strong></p>
			<p style="font-size:12px;color:var(--admin-muted);margin:0 0 12px;" id="completeModalWhen"></p>
			<label class="admin-field" style="display:block;">
				<span style="font-size:12px;font-weight:700;color:var(--admin-text);">Recommendations <span style="color:var(--admin-muted);font-weight:500;">(optional)</span></span>
				<textarea name="recommendations" id="completeModalRecs" rows="4" placeholder="e.g. feeding advice, vitamins, next visit..." style="width:100%;margin-top:6px;"></textarea>
			</label>
			<div class="admin-actions" style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px;">
				<button class="admin-btn-secondary" type="button" id="completeModalCancel">Cancel</button>
				<button class="admin-btn" type="submit">Mark completed</button>
			</div>
		</form>
	</div>
</div>

<!-- ============ DETAILS MODAL (history re-view) ============ -->
<div class="admin-modal-overlay" id="apptDetailModal">
	<div class="admin-modal" style="max-width:480px;" role="dialog" aria-modal="true" aria-label="Appointment details">
		<div class="admin-modal-head">
			<h3>Appointment details</h3>
			<button class="admin-modal-close" id="apptDetailClose" type="button">&times;</button>
		</div>
		<div style="padding:16px 20px;">
			<div class="appt-detail-row"><span class="k">Child</span><span class="v" id="detailChild"></span></div>
			<div class="appt-detail-row"><span class="k">Parent</span><span class="v" id="detailParent"></span></div>
			<div class="appt-detail-row"><span class="k">Barangay</span><span class="v" id="detailBarangay"></span></div>
			<div class="appt-detail-row"><span class="k">Schedule</span><span class="v" id="detailWhen"></span></div>
			<div class="appt-detail-row"><span class="k">Location</span><span class="v" id="detailLocation"></span></div>
			<div class="appt-detail-row"><span class="k">Status</span><span class="v"><span class="appt-pill" id="detailStatus"></span></span></div>
			<div class="appt-detail-notes" id="detailNotesWrap">
				<div class="k">Notes</div>
				<div class="v" id="detailNotes"></div>
			</div>
			<div class="appt-detail-notes" id="detailRecsWrap">
				<div class="k">Recommendations</div>
				<div class="v" id="detailRecs"></div>
			</div>
			<div class="admin-actions" style="display:flex;justify-content:flex-end;margin-top:12px;">
				<button class="admin-btn-secondary" type="button" id="apptDetailOk">Close</button>
			</div>
		</div>
	</div>
</div>

<script>
(function () {
	var detailData = <?php echo json_encode($apptDetailJson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

	function showModal(id) {
		var el = document.getElementById(id);
		if (el) { el.classList.add('is-open'); document.body.style.overflow = 'hidden'; }
	}
	function hideModal(id) {
		var el = document.getElementById(id);
		if (el) { el.classList.remove('is-open'); document.body.style.overflow = ''; }
	}

	// Done -> complete modal with optional recommendations.
	document.querySelectorAll('[data-complete-open]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			document.getElementById('completeModalId').value = btn.getAttribute('data-complete-open');
			document.getElementById('completeModalChild').textContent = btn.getAttribute('data-complete-child') || '';
			document.getElementById('completeModalWhen').textContent = btn.getAttribute('data-complete-when') || '';
			document.getElementById('completeModalRecs').value = '';
			showModal('completeModal');
		});
	});
	document.getElementById('completeModalClose').addEventListener('click', function () { hideModal('completeModal'); });
	document.getElementById('completeModalCancel').addEventListener('click', function () { hideModal('completeModal'); });

	// History -> read-only details modal.
	function setText(id, v) {
		var el = document.getElementById(id);
		if (el) el.textContent = (v === null || v === undefined || v === '') ? '—' : v;
	}
	document.querySelectorAll('[data-appt-view]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var a = detailData[parseInt(btn.getAttribute('data-appt-view'), 10)];
			if (!a) return;
			setText('detailChild', a.child + (a.code ? ' (' + a.code + ')' : ''));
			setText('detailParent', a.parent + (a.phone ? ' · ' + a.phone : ''));
			setText('detailBarangay', a.barangay);
			setText('detailWhen', a.when);
			setText('detailLocation', a.location);
			var st = document.getElementById('detailStatus');
			if (st) { st.textContent = a.status; st.className = 'appt-pill ' + a.pill; }
			var notesWrap = document.getElementById('detailNotesWrap');
			if (notesWrap) notesWrap.style.display = a.notes ? '' : 'none';
			setText('detailNotes', a.notes);
			var recsWrap = document.getElementById('detailRecsWrap');
			if (recsWrap) recsWrap.style.display = a.recommendations ? '' : 'none';
			setText('detailRecs', a.recommendations);
			showModal('apptDetailModal');
		});
	});
	document.getElementById('apptDetailClose').addEventListener('click', function () { hideModal('apptDetailModal'); });
	document.getElementById('apptDetailOk').addEventListener('click', function () { hideModal('apptDetailModal'); });

	['completeModal', 'apptDetailModal'].forEach(function (id) {
		var overlay = document.getElementById(id);
		if (overlay) overlay.addEventListener('click', function (e) { if (e.target === overlay) hideModal(id); });
	});
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') { hideModal('completeModal'); hideModal('apptDetailModal'); }
	});
})();
</script>

<?php nutritionist_layout_end(); ?>

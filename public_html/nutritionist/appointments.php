<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

$user = nutritionist_require_access();

// â”€â”€ POST handlers (consultation requests only) â”€â”€
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
		if ($ok) {
			$actor = current_user();
			log_action($actor['id'] ?? null, 'UPDATE_APPOINTMENT', 'info', 'Confirmed appointment #' . $appointmentId);
		}
		admin_redirect('/nutritionist/appointments.php?tab=incoming', $ok ? ['notice' => 'Appointment confirmed.'] : ['notice' => 'Could not confirm appointment.', 'type' => 'error']);
	}

	if ($action === 'cancel_request' && $appointmentId > 0) {
		nutritionist_require_write();
		$ok = admin_execute(
			"UPDATE appointments SET status = 'cancelled' WHERE id = ? AND nutritionist_id = ? AND status IN ('pending', 'confirmed')",
			'ii',
			[$appointmentId, (int)$user['id']]
		);
		if ($ok) {
			$actor = current_user();
			log_action($actor['id'] ?? null, 'UPDATE_APPOINTMENT', 'warning', 'Cancelled appointment #' . $appointmentId);
		}
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
		if ($ok) {
			$actor = current_user();
			log_action($actor['id'] ?? null, 'UPDATE_APPOINTMENT', 'info', 'Completed appointment #' . $appointmentId);
		}
		admin_redirect('/nutritionist/appointments.php', $ok ? ['notice' => 'Appointment marked as completed.'] : ['notice' => 'Could not complete appointment.', 'type' => 'error']);
	}

	// â”€â”€ New request from the modal (same validation as appointment_form.php) â”€â”€
	if ($action === 'create_request') {
		nutritionist_require_write();

		$childId = (int)($_POST['child_id'] ?? 0);
		$scheduledAt = trim((string)($_POST['scheduled_at'] ?? ''));
		$notes = trim((string)($_POST['notes'] ?? ''));
		$location = trim((string)($_POST['location'] ?? ''));

		if ($childId <= 0 || $scheduledAt === '') {
			admin_redirect('/nutritionist/appointments.php?tab=outgoing', ['notice' => 'Child and schedule are required.', 'type' => 'error']);
		}

		if ($location === '') {
			$location = 'Barangay Health Center';
		}

		$childParams = [$childId];
		$childScope = nutritionist_scope_fragment($user, 'c.barangay_id', $childParams);
		$childRecord = admin_fetch_one(
			"SELECT c.id, c.parent_id
			 FROM children c
			 WHERE c.id = ? AND c.status = 'active' AND {$childScope}
			 LIMIT 1",
			str_repeat('i', count($childParams)),
			$childParams
		);

		if ($childRecord === null) {
			admin_redirect('/nutritionist/appointments.php?tab=outgoing', ['notice' => 'Select a valid child from your list.', 'type' => 'error']);
		}

		$ok = admin_execute(
			'INSERT INTO appointments (child_id, parent_id, nutritionist_id, scheduled_at, status, notes, location)
			 VALUES (?, ?, ?, ?, ?, ?, ?)',
			'iiissss',
			[$childId, (int)$childRecord['parent_id'], (int)$user['id'], $scheduledAt, 'pending', $notes, $location]
		);

		if ($ok) {
			$actor = current_user();
			$newAppointmentId = (int)get_db_connection()->insert_id;
			log_action($actor['id'] ?? null, 'CREATE_APPOINTMENT', 'info', 'Scheduled appointment #' . $newAppointmentId . ' for child #' . $childId);
		}

		admin_redirect('/nutritionist/appointments.php?tab=outgoing', $ok ? ['notice' => 'Appointment scheduled.'] : ['notice' => 'Appointment could not be scheduled.', 'type' => 'error']);
	}
}

// â”€â”€ Consultation requests for this nutritionist â”€â”€
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

// â”€â”€ Children dropdown for the New request modal (same scope as appointment_form.php) â”€â”€
$nrChildrenParams = [];
$nrChildrenScope = nutritionist_scope_fragment($user, 'c.barangay_id', $nrChildrenParams);
$nrChildren = admin_fetch_all(
	"SELECT c.id, c.first_name, c.last_name, c.parent_id, p.name AS parent_name, p.parent_type, p.phone AS parent_phone, p.status AS parent_status
	 FROM children c
	 INNER JOIN parents p ON p.id = c.parent_id
	 WHERE {$nrChildrenScope} AND c.status = 'active'
	 ORDER BY c.last_name ASC, c.first_name ASC",
	str_repeat('i', count($nrChildrenParams)),
	$nrChildrenParams
);
$nrDefaultScheduledAt = (new DateTimeImmutable('+1 day'))->setTime(9, 0)->format('Y-m-d\TH:i');

// â”€â”€ Tab selection â”€â”€
// Paging is client-side (data-page-size + admin.js), same as every other
// nutritionist list, so there is no $page/$offset/$pageRows here.
$validTabs = ['incoming', 'outgoing', 'history'];
$activeTab = in_array(($_GET['tab'] ?? ''), $validTabs, true) ? ($_GET['tab'] ?? '') : 'incoming';

// â”€â”€ Calendar â”€â”€
$monthParam = (string)($_GET['m'] ?? $now->format('Y-m'));
try { $monthAnchor = new DateTimeImmutable($monthParam . '-01'); } catch (Exception) { $monthAnchor = $now->modify('first day of this month'); }
$calendarYear = (int)$monthAnchor->format('Y');
$calendarMonth = (int)$monthAnchor->format('n');
$monthLabel = $monthAnchor->format('F Y');
$prevMonth = $monthAnchor->modify('-1 month')->format('Y-m');
$nextMonth = $monthAnchor->modify('+1 month')->format('Y-m');

// â”€â”€ Active group â”€â”€
$allGroups = ['incoming' => $incoming, 'outgoing' => $outgoing, 'history' => $history];
$activeGroup = $allGroups[$activeTab];

// â”€â”€ Calendar entries (consultations only) â”€â”€
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

$actions = nutritionist_can_write()
	? '<button type="button" class="admin-btn" data-new-request-open>' . admin_action_icon('add') . ' New request</button>'
	: '';

nutritionist_layout_start('Appointments', 'Consultation requests between parents and nutritionists.', 'appointments', $actions);
?>

<style>
/* Tabs (.rp-tabs/.rp-tab) mirror nutritionist/children.php exactly since
   there is no global tab stylesheet — same classes, same look. Cards
   (.nutritionist-panel), the table (.nutritionist-table) and status pills
   (.admin-pill) are shared classes from admin.css / nutritionist.css.
   Only the two genuinely page-specific rules live here. */
.rp-tabs{display:flex;gap:0;border-bottom:2px solid var(--admin-border);margin:0 0 14px}
.rp-tab{display:inline-flex;align-items:center;gap:6px;padding:10px 18px;font-size:13px;font-weight:600;color:var(--admin-muted);text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;transition:color .15s,border-color .15s,background .15s;border-radius:8px 8px 0 0}
.rp-tab:hover{color:var(--admin-text);background:var(--admin-surface-alt)}
.rp-tab.is-active{color:var(--admin-primary);border-bottom-color:var(--admin-primary);background:transparent}
.rp-tab span{font-size:11px;opacity:.6}
.appt-notes-cell{max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sk-cal-day-more{font-size:9px;color:var(--admin-muted);line-height:1.3}
</style>

<?php
// Tab links carry the calendar month so switching tabs doesn't reset it.
$tabUrl = static function (string $tab, string $month) {
	return app_url('/nutritionist/appointments.php') . '?' . http_build_query(['tab' => $tab, 'm' => $month]);
};
?>

<!-- ============ TABS ============ -->
<div class="rp-tabs">
	<a class="rp-tab <?php echo $activeTab === 'incoming' ? 'is-active' : ''; ?>" href="<?php echo nutritionist_e($tabUrl('incoming', $monthParam)); ?>">From Parents <span>(<?php echo count($incoming); ?>)</span></a>
	<a class="rp-tab <?php echo $activeTab === 'outgoing' ? 'is-active' : ''; ?>" href="<?php echo nutritionist_e($tabUrl('outgoing', $monthParam)); ?>">My Requests <span>(<?php echo count($outgoing); ?>)</span></a>
	<a class="rp-tab <?php echo $activeTab === 'history' ? 'is-active' : ''; ?>" href="<?php echo nutritionist_e($tabUrl('history', $monthParam)); ?>">History <span>(<?php echo count($history); ?>)</span></a>
</div>

<!-- ============ TABLE ============ -->
<section class="nutritionist-panel">
	<?php
	$tabLabels = ['incoming' => 'Requests From Parents', 'outgoing' => 'My Requests to Parents', 'history' => 'Appointment History'];
	$tabSubs = ['incoming' => 'Consultation requests from parents awaiting your confirmation', 'outgoing' => 'Consultation requests you sent to parents', 'history' => 'Completed and cancelled consultations'];
	?>
	<div class="admin-section-head">
		<div>
			<h3 class="admin-section-title"><?php echo $tabLabels[$activeTab]; ?></h3>
			<p class="admin-section-subtitle"><?php echo $tabSubs[$activeTab]; ?></p>
		</div>
	</div>

	<?php if (empty($activeGroup)): ?>
		<div style="text-align:center;padding:24px;color:var(--admin-muted);"><?php echo $activeTab === 'history' ? 'No past appointments.' : 'No requests here yet.'; ?></div>
	<?php else: ?>
	<div class="children-toolbar">
		<input
			class="admin-search"
			data-admin-filter="#appt-table"
			type="search"
			aria-label="Search appointments"
			placeholder="Search child, parent, or status..."
		>
	</div>

	<div class="nutritionist-table-wrap">
		<table class="nutritionist-table" id="appt-table" data-page-size="10">
			<thead>
				<tr>
					<th>Child</th>
					<th><?php echo $activeTab === 'incoming' ? 'Parent' : 'Requested By'; ?></th>
					<th>Barangay</th>
					<th>Schedule</th>
					<th>Notes</th>
					<th>Status</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($activeGroup as $reqIndex => $req):
					$fullName = $req['first_name'] . ' ' . $req['last_name'];
					$reqDate = date('M j, g:i A', strtotime($req['scheduled_at']));
					$notes = $req['notes'] ? '<span style="color:var(--admin-text);">' . nutritionist_e($req['notes']) . '</span>' : '<span style="color:var(--admin-muted);font-style:italic;">None</span>';
					$reqStatus = $req['appt_status'] ?? 'pending';
					$reqPillClass = match ($reqStatus) { 'confirmed' => 'is-success', 'completed' => 'is-info', 'cancelled' => 'is-muted', default => 'is-warn' };
					$reqPillLabel = ucfirst($reqStatus);
					$fromParent = ($req['created_by'] ?? '') === 'parent';
					$rowLabel = $fullName . ' ' . (string)($req['child_code'] ?? '') . ' ' . (string)($req['parent_name'] ?? '') . ' ' . $reqStatus;
				?>
				<tr<?php echo admin_paged_row_attr($reqIndex, 10); ?>
					data-filter-text="<?php echo nutritionist_e(mb_strtolower($rowLabel)); ?>"
				>
					<td>
						<strong><?php echo nutritionist_e($fullName); ?></strong>
						<div style="font-size:10px;color:var(--admin-muted);"><?php echo nutritionist_e($req['child_code']); ?></div>
					</td>
					<td>
						<?php if ($activeTab === 'incoming'): ?>
							<?php echo nutritionist_e($req['parent_name'] ?? 'â€”'); ?>
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
					<td><div class="appt-notes-cell"><?php echo $notes; ?></div></td>
					<td><span class="admin-pill <?php echo $reqPillClass; ?>"><?php echo $reqPillLabel; ?></span></td>
<td>
						<div class="admin-actions">
							<?php if ($reqStatus === 'pending' && $fromParent && $activeTab !== 'history'): ?>
							<form method="post" style="display:inline;" data-admin-confirm="Confirm this consultation request for <?php echo nutritionist_e($fullName); ?>?" data-validate-form>
								<input type="hidden" name="action" value="confirm_request">
								<input type="hidden" name="id" value="<?php echo (int)$req['id']; ?>">
								<button type="submit" class="admin-icon-btn admin-icon-btn-primary" title="Confirm" aria-label="Confirm consultation request"><?php echo admin_action_icon('verify'); ?></button>
							</form>
							<form method="post" style="display:inline;" data-admin-confirm="Cancel this consultation request for <?php echo nutritionist_e($fullName); ?>? This cannot be undone." data-admin-confirm-danger data-validate-form>
								<input type="hidden" name="action" value="cancel_request">
								<input type="hidden" name="id" value="<?php echo (int)$req['id']; ?>">
								<button type="submit" class="admin-icon-btn admin-icon-btn-danger" title="Cancel" aria-label="Cancel consultation request"><?php echo admin_action_icon('cancel'); ?></button>
							</form>
							<?php elseif ($reqStatus === 'pending' && !$fromParent && $activeTab !== 'history'): ?>
							<form method="post" style="display:inline;" data-admin-confirm="Withdraw this request to <?php echo nutritionist_e($req['parent_name'] ?? 'the parent'); ?>? This cannot be undone." data-admin-confirm-danger data-validate-form>
								<input type="hidden" name="action" value="cancel_request">
								<input type="hidden" name="id" value="<?php echo (int)$req['id']; ?>">
								<button type="submit" class="admin-icon-btn admin-icon-btn-danger" title="Withdraw" aria-label="Withdraw request"><?php echo admin_action_icon('cancel'); ?></button>
							</form>
						<?php elseif ($reqStatus === 'confirmed' && $activeTab !== 'history'): ?>
						<button type="button" class="admin-icon-btn admin-icon-btn-primary" title="Mark completed" aria-label="Mark appointment completed" data-complete-open="<?php echo (int)$req['id']; ?>" data-complete-child="<?php echo nutritionist_e($fullName); ?>" data-complete-when="<?php echo nutritionist_e($reqDate); ?>"><?php echo admin_action_icon('done'); ?></button>
						<form method="post" style="display:inline;" data-admin-confirm="Cancel this appointment for <?php echo nutritionist_e($fullName); ?>? This cannot be undone." data-admin-confirm-danger data-validate-form>
							<input type="hidden" name="action" value="cancel_request">
							<input type="hidden" name="id" value="<?php echo (int)$req['id']; ?>">
							<button type="submit" class="admin-icon-btn admin-icon-btn-danger" title="Cancel" aria-label="Cancel appointment"><?php echo admin_action_icon('cancel'); ?></button>
						</form>
						<?php elseif ($activeTab === 'history'): ?>
						<button type="button" class="admin-icon-btn admin-icon-btn-primary" title="View" aria-label="View appointment details" data-appt-view="<?php echo (int)$req['id']; ?>"><?php echo admin_action_icon('view'); ?></button>
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
	<?php endif; ?>
</section>

<!-- ============ CALENDAR (always visible) ============ -->
<section class="nutritionist-panel">
	<div class="admin-section-head">
		<h3 class="admin-section-title"><?php echo nutritionist_e($monthLabel); ?></h3>
		<div style="display:flex;gap:6px;">
			<a class="admin-btn-secondary" href="<?php echo nutritionist_e($tabUrl($activeTab, $prevMonth)); ?>" title="Previous month"><?php echo admin_action_icon('chevron_left'); ?> Prev</a>
			<a class="admin-btn-secondary" href="<?php echo nutritionist_e($tabUrl($activeTab, $nextMonth)); ?>" title="Next month">Next <?php echo admin_action_icon('chevron_right'); ?></a>
		</div>
	</div>
	<div class="sk-cal-wrap" data-sk-calendar>
		<?php echo nutritionist_render_calendar_grid($monthAnchor, $calendarEntries, $today); ?>
	</div>
</section>

<?php
// Details data for the history View modal (all tabs, keyed by id).
$apptDetailJson = [];
foreach (['incoming' => $incoming, 'outgoing' => $outgoing, 'history' => $history] as $groupRows) {
	foreach ($groupRows as $row) {
		$st = $row['appt_status'] ?? 'pending';
		$pill = match ($st) { 'confirmed' => 'is-success', 'completed' => 'is-info', 'cancelled' => 'is-muted', default => 'is-warn' };
		$apptDetailJson[(int)$row['id']] = [
			'id' => (int)$row['id'],
			'child' => $row['first_name'] . ' ' . $row['last_name'],
			'code' => (string)($row['child_code'] ?? ''),
			'parent' => (string)($row['parent_name'] ?? 'â€”'),
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
#completeModal, #apptDetailModal, #newRequestModal { display: none; }
#completeModal.is-open, #apptDetailModal.is-open, #newRequestModal.is-open { display: flex; }
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
			<div class="appt-detail-row"><span class="k">Status</span><span class="v"><span class="admin-pill" id="detailStatus"></span></span></div>
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

<!-- ============ NEW REQUEST MODAL (create only; edit stays on appointment_form.php) ============ -->
<div class="admin-modal-overlay" id="newRequestModal">
	<div class="admin-modal" style="max-width:520px;" role="dialog" aria-modal="true" aria-label="New appointment request">
		<div class="admin-modal-head">
			<h3>New request</h3>
			<button class="admin-modal-close" id="newRequestClose" type="button">&times;</button>
		</div>
		<form method="post" action="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php')); ?>" style="padding:16px 20px;">
			<input type="hidden" name="action" value="create_request">
			<label class="admin-field" style="display:block;margin-bottom:10px;">
				<span style="font-size:12px;font-weight:700;color:var(--admin-text);">Child <span style="color:var(--admin-danger);">*</span></span>
				<select name="child_id" id="newRequestChild" required style="width:100%;margin-top:6px;">
					<option value="">-- Select Child --</option>
					<?php foreach ($nrChildren as $nrChild): ?>
						<option
							value="<?php echo (int)$nrChild['id']; ?>"
							data-parent-name="<?php echo nutritionist_e((string)$nrChild['parent_name']); ?>"
							data-parent-type="<?php echo nutritionist_e((string)$nrChild['parent_type']); ?>"
							data-parent-phone="<?php echo nutritionist_e((string)($nrChild['parent_phone'] ?? '')); ?>"
						><?php echo nutritionist_e($nrChild['first_name'] . ' ' . $nrChild['last_name']); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="admin-field" style="display:block;margin-bottom:10px;">
				<span style="font-size:12px;font-weight:700;color:var(--admin-text);">Parent/Guardian</span>
				<input type="text" id="newRequestGuardian" value="Select a child first" disabled style="width:100%;margin-top:6px;color:var(--admin-muted);">
			</label>
			<label class="admin-field" style="display:block;margin-bottom:10px;">
				<span style="font-size:12px;font-weight:700;color:var(--admin-text);">Schedule <span style="color:var(--admin-danger);">*</span></span>
				<input type="datetime-local" name="scheduled_at" required value="<?php echo nutritionist_e($nrDefaultScheduledAt); ?>" style="width:100%;margin-top:6px;">
			</label>
			<label class="admin-field" style="display:block;margin-bottom:10px;">
				<span style="font-size:12px;font-weight:700;color:var(--admin-text);">Location</span>
				<input name="location" placeholder="e.g. Barangay Health Center" value="Barangay Health Center" style="width:100%;margin-top:6px;">
			</label>
			<label class="admin-field" style="display:block;">
				<span style="font-size:12px;font-weight:700;color:var(--admin-text);">Notes</span>
				<textarea name="notes" rows="3" placeholder="Optional follow-up notes" style="width:100%;margin-top:6px;"></textarea>
			</label>
			<p class="admin-mini" style="margin:8px 0 0;">New requests always start as pending until confirmed.</p>
			<div class="admin-actions" style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px;">
				<button class="admin-btn-secondary" type="button" id="newRequestCancel">Cancel</button>
				<button class="admin-btn" type="submit"><?php echo admin_action_icon('save'); ?> Save appointment</button>
			</div>
		</form>
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
		if (el) el.textContent = (v === null || v === undefined || v === '') ? 'â€”' : v;
	}
	document.querySelectorAll('[data-appt-view]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var a = detailData[parseInt(btn.getAttribute('data-appt-view'), 10)];
			if (!a) return;
			setText('detailChild', a.child + (a.code ? ' (' + a.code + ')' : ''));
			setText('detailParent', a.parent + (a.phone ? ' Â· ' + a.phone : ''));
			setText('detailBarangay', a.barangay);
			setText('detailWhen', a.when);
			setText('detailLocation', a.location);
			var st = document.getElementById('detailStatus');
			if (st) { st.textContent = a.status; st.className = 'admin-pill ' + a.pill; }
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

	// New request modal: open/close + guardian auto-display (same as appointment_form.php).
	document.querySelectorAll('[data-new-request-open]').forEach(function (btn) {
		btn.addEventListener('click', function () { showModal('newRequestModal'); });
	});
	var nrClose = document.getElementById('newRequestClose');
	if (nrClose) nrClose.addEventListener('click', function () { hideModal('newRequestModal'); });
	var nrCancel = document.getElementById('newRequestCancel');
	if (nrCancel) nrCancel.addEventListener('click', function () { hideModal('newRequestModal'); });

	var nrChild = document.getElementById('newRequestChild');
	var nrGuardian = document.getElementById('newRequestGuardian');
	function nrUpdateGuardian() {
		if (!nrChild || !nrGuardian) return;
		var option = nrChild.options[nrChild.selectedIndex];
		if (!option || !option.value) { nrGuardian.value = 'Select a child first'; return; }
		var parts = [option.getAttribute('data-parent-name') || 'Unknown guardian'];
		var ptype = option.getAttribute('data-parent-type') || '';
		var pphone = option.getAttribute('data-parent-phone') || '';
		if (ptype) parts.push(ptype);
		if (pphone) parts.push(pphone);
		nrGuardian.value = parts.join(' Â· ');
	}
	if (nrChild) { nrChild.addEventListener('change', nrUpdateGuardian); nrUpdateGuardian(); }

	['completeModal', 'apptDetailModal', 'newRequestModal'].forEach(function (id) {
		var overlay = document.getElementById(id);
		if (overlay) overlay.addEventListener('click', function (e) { if (e.target === overlay) hideModal(id); });
	});
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') { hideModal('completeModal'); hideModal('apptDetailModal'); hideModal('newRequestModal'); }
	});
})();
</script>

<?php nutritionist_layout_end(); ?>

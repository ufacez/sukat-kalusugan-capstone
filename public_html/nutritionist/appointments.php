<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/followup_scheduler.php';

$user = nutritionist_require_access();

// ── POST handlers ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = (string)($_POST['action'] ?? '');
	$appointmentId = (int)($_POST['id'] ?? 0);

	if ($action === 'complete_followup' && $appointmentId > 0) {
		nutritionist_require_write();

		$appointment = admin_fetch_one(
			"SELECT a.id, a.child_id, a.scheduled_at, lm.measurement_date AS last_measured, c.first_name, c.last_name
			 FROM appointments a
			 INNER JOIN children c ON c.id = a.child_id
			 LEFT JOIN measurements lm ON lm.id = (
				SELECT m.id FROM measurements m WHERE m.child_id = a.child_id ORDER BY m.measurement_date DESC, m.id DESC LIMIT 1
			 )
			 WHERE a.id = ? AND a.appointment_type = 'followup' AND a.status IN ('pending', 'confirmed') LIMIT 1",
			'i',
			[$appointmentId]
		);

		if ($appointment === null) {
			admin_redirect('/nutritionist/appointments.php', ['notice' => 'Follow-up not found or already closed.', 'type' => 'error']);
		}

		try {
			$satisfiedFrom = (new DateTimeImmutable((string)$appointment['scheduled_at']))->setTime(0, 0)->modify('-' . FOLLOWUP_GRACE_DAYS . ' days');
		} catch (Exception) {
			$satisfiedFrom = new DateTimeImmutable('today');
		}

		$measuredAt = $appointment['last_measured'] ?? null;

		if ($measuredAt === null || $measuredAt === '' || new DateTimeImmutable((string)$measuredAt) < $satisfiedFrom) {
			admin_redirect('/nutritionist/appointments.php', [
				'notice' => 'Re-measurement is MANDATORY before this follow-up can be completed. Record a new measurement for ' . $appointment['first_name'] . ' ' . $appointment['last_name'] . ' first.',
				'type' => 'error',
			]);
		}

		$ok = admin_execute("UPDATE appointments SET status = 'completed' WHERE id = ? AND status IN ('pending', 'confirmed')", 'i', [$appointmentId]);

		log_action(
			(int)$user['id'],
			'FOLLOWUP_COMPLETE',
			'info',
			sprintf('Mandatory follow-up #%d satisfied by re-measurement dated %s (%s %s).', $appointmentId, (string)$measuredAt, $appointment['first_name'], $appointment['last_name'])
		);

		followup_sync_for_scope($user);
		admin_redirect('/nutritionist/appointments.php', $ok ? ['notice' => 'Re-measurement verified — follow-up completed and next cycle scheduled.'] : ['notice' => 'Follow-up could not be updated.', 'type' => 'error']);
	}

	if ($action === 'confirm_request' && $appointmentId > 0) {
		nutritionist_require_write();
		$ok = admin_execute(
			"UPDATE appointments SET status = 'confirmed' WHERE id = ? AND created_by = 'parent' AND status = 'pending'",
			'i',
			[$appointmentId]
		);
		admin_redirect('/nutritionist/appointments.php?tab=open_requests', $ok ? ['notice' => 'Appointment confirmed.'] : ['notice' => 'Could not confirm appointment.', 'type' => 'error']);
	}

	if ($action === 'cancel_request' && $appointmentId > 0) {
		nutritionist_require_write();
		$ok = admin_execute(
			"UPDATE appointments SET status = 'cancelled' WHERE id = ? AND created_by = 'parent' AND status = 'pending'",
			'i',
			[$appointmentId]
		);
		admin_redirect('/nutritionist/appointments.php?tab=open_requests', $ok ? ['notice' => 'Appointment cancelled.'] : ['notice' => 'Could not cancel appointment.', 'type' => 'error']);
	}

	if ($action === 'complete_request' && $appointmentId > 0) {
		nutritionist_require_write();
		$ok = admin_execute(
			"UPDATE appointments SET status = 'completed' WHERE id = ? AND created_by = 'parent' AND status = 'confirmed'",
			'i',
			[$appointmentId]
		);
		admin_redirect('/nutritionist/appointments.php?tab=open_requests', $ok ? ['notice' => 'Appointment marked as completed.'] : ['notice' => 'Could not complete appointment.', 'type' => 'error']);
	}
}

$monitoringList = followup_fetch_monitoring_list($user);

// ── Parent-created appointments (pending + confirmed) ──
$openReqParams = [(int)$user['id']];
$allParentAppts = admin_fetch_all(
	"SELECT a.id, a.scheduled_at, a.notes, a.location, a.created_at, a.status AS appt_status,
		c.id AS child_id, c.first_name, c.last_name, c.child_code, c.birthdate, c.sex,
		bg.name AS barangay_name,
		p.name AS parent_name, p.phone AS parent_phone
	 FROM appointments a
	 INNER JOIN children c ON c.id = a.child_id
	 LEFT JOIN barangays bg ON bg.id = c.barangay_id
	 LEFT JOIN parents p ON p.id = a.parent_id
	 WHERE a.nutritionist_id = ? AND a.status IN ('pending', 'confirmed') AND a.created_by = 'parent'
	 ORDER BY a.scheduled_at ASC, a.id ASC",
	'i',
	$openReqParams
);
// Pending only for the Open Requests table
$openRequestRows = $allParentAppts;

$today = new DateTimeImmutable('today');
$now = new DateTimeImmutable('now');

// ── Tab / pagination ──
$validTabs = ['open_requests', 'monthly_young', 'monthly_old', 'quarterly'];
$activeTab = in_array(($_GET['tab'] ?? ''), $validTabs, true) ? ($_GET['tab'] ?? '') : 'open_requests';
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

// ── Split monitoring list into 3 groups ──
$groupYoung = [];   // Monthly, 0–23 months
$groupOld = [];     // Monthly, 24–60 months (abnormal)
$groupQuarterly = []; // Quarterly, 24–60 months (normal)

foreach ($monitoringList as $entry) {
	$track = $entry['schedule_type'] ?? '';
	$age = $entry['age_months'] ?? 0;
	if ($track === 'monthly' && $age <= 23) {
		$groupYoung[] = $entry;
	} elseif ($track === 'monthly' && $age >= 24) {
		$groupOld[] = $entry;
	} elseif ($track === 'quarterly') {
		$groupQuarterly[] = $entry;
	}
}

// ── Sort each group: overdue first, then due today, then upcoming ──
$sortFn = function ($a, $b) {
	$so = ['overdue' => 0, 'due_today' => 1, 'due_soon' => 2, 'special_monitoring' => 3, 'upcoming' => 4, 'completed' => 5];
	$sa = $so[$a['status']] ?? 4;
	$sb = $so[$b['status']] ?? 4;
	if ($sa !== $sb) return $sa <=> $sb;
	return $a['next_due'] <=> $b['next_due'];
};
usort($groupYoung, $sortFn);
usort($groupOld, $sortFn);
usort($groupQuarterly, $sortFn);

// ── Active group + stats ──
$allGroups = ['open_requests' => $openRequestRows, 'monthly_young' => $groupYoung, 'monthly_old' => $groupOld, 'quarterly' => $groupQuarterly];
$activeGroup = $allGroups[$activeTab];
$totalRows = count($activeGroup);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;
$pageRows = array_slice($activeGroup, $offset, $perPage);

$countOpenRequests = count($openRequestRows);

$countDueToday = 0;
$countOverdue = 0;
$countUpcoming = 0;
$countCompleted = 0;
foreach ($monitoringList as $entry) {
	match ($entry['status']) {
		'due_today' => $countDueToday++,
		'overdue' => $countOverdue++,
		'upcoming', 'due_soon' => $countUpcoming++,
		'completed' => $countCompleted++,
		default => null,
	};
}

// ── Calendar entries ──
$calendarEntries = [];
foreach ($monitoringList as $entry) {
	if (empty($entry['next_due'])) continue;
	if (($entry['status'] ?? '') === 'completed') continue;
	try { $dueDt = new DateTimeImmutable($entry['next_due']); } catch (Exception) { continue; }
	if ((int)$dueDt->format('Y') !== $calendarYear || (int)$dueDt->format('n') !== $calendarMonth) continue;
	$dayKey = (int)$dueDt->format('j');
	$label = match ($entry['status']) { 'overdue' => 'Overdue', 'due_today' => 'Due Today', 'completed' => 'Completed', 'special_monitoring' => 'Special', default => 'Upcoming' };
	$calendarEntries[$dayKey][] = ['type' => 'appointment', 'color' => nutritionist_calendar_color('appointment'), 'title' => $entry['first_name'] . ' ' . $entry['last_name'] . ' (' . $label . ')', 'time' => '', 'id' => $entry['id'], 'location' => $entry['barangay_name'] ?? '', 'status' => $entry['status']];
}
// Add parent-requested appointments to calendar (blue = pending, green = confirmed)
foreach ($allParentAppts as $req) {
	try { $reqDt = new DateTimeImmutable($req['scheduled_at']); } catch (Exception) { continue; }
	if ((int)$reqDt->format('Y') !== $calendarYear || (int)$reqDt->format('n') !== $calendarMonth) continue;
	$dayKey = (int)$reqDt->format('j');
	$reqTime = $reqDt->format('g:i A');
	$isConfirmed = $req['appt_status'] === 'confirmed';
	$calColor = $isConfirmed ? '#16a34a' : nutritionist_calendar_color('parent_request');
	$calLabel = $isConfirmed ? 'Confirmed' : 'Parent Request';
	$calendarEntries[$dayKey][] = ['type' => 'parent_request', 'color' => $calColor, 'title' => $req['first_name'] . ' ' . $req['last_name'] . ' (' . $calLabel . ')', 'time' => $reqTime, 'id' => $req['id'], 'location' => $req['barangay_name'] ?? '', 'status' => $req['appt_status']];
}
ksort($calendarEntries);

$actions = '';

nutritionist_layout_start('Appointments', 'Track children due for reweighing and monitor follow-up schedules.', 'appointments', $actions);
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
.appt-pill.special{background:rgba(124,58,237,.12);color:#7c3aed}
.appt-table{width:100%;border-collapse:collapse;font-size:12px}
.appt-table th{text-align:left;padding:8px 10px;border-bottom:2px solid var(--admin-border);color:var(--admin-muted);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.04em}
.appt-table td{padding:8px 10px;border-bottom:1px solid var(--admin-border);vertical-align:middle}
.appt-table tr:hover td{background:var(--admin-surface-alt)}
.appt-pagination{display:flex;justify-content:space-between;align-items:center;padding:12px 0;font-size:12px;color:var(--admin-muted)}
.sk-cal-day-more{font-size:9px;color:var(--admin-muted);line-height:1.3}
</style>

<!-- ============ STAT CARDS ============ -->
<section style="margin-bottom:18px;">
	<div class="admin-grid-cards">
		<article class="admin-card">
			<div class="admin-card-row">
				<div class="admin-card-icon" style="background:rgba(217,119,6,.12);color:#d97706;"><?php echo admin_action_icon('bell'); ?></div>
				<div class="admin-card-content">
					<div class="admin-card-label">Due Today</div>
					<div class="admin-card-value" style="<?php echo $countDueToday > 0 ? 'color:#d97706;' : ''; ?>"><?php echo $countDueToday; ?></div>
				</div>
			</div>
		</article>
		<article class="admin-card">
			<div class="admin-card-row">
				<div class="admin-card-icon is-danger"><?php echo admin_action_icon('cancel'); ?></div>
				<div class="admin-card-content">
					<div class="admin-card-label">Overdue</div>
					<div class="admin-card-value" style="<?php echo $countOverdue > 0 ? 'color:#dc2626;' : ''; ?>"><?php echo $countOverdue; ?></div>
				</div>
			</div>
		</article>
		<article class="admin-card">
			<div class="admin-card-row">
				<div class="admin-card-icon" style="background:rgba(22,163,74,.12);color:#16a34a;"><?php echo admin_action_icon('calendar'); ?></div>
				<div class="admin-card-content">
					<div class="admin-card-label">Upcoming</div>
					<div class="admin-card-value"><?php echo $countUpcoming; ?></div>
				</div>
			</div>
		</article>
		<article class="admin-card">
			<div class="admin-card-row">
				<div class="admin-card-icon is-success"><?php echo admin_action_icon('verify'); ?></div>
				<div class="admin-card-content">
					<div class="admin-card-label">Completed</div>
					<div class="admin-card-value"><?php echo $countCompleted; ?></div>
				</div>
			</div>
		</article>
	</div>
</section>

<!-- ============ TABS ============ -->
<div class="rp-tabs">
	<a class="rp-tab <?php echo $activeTab === 'open_requests' ? 'is-active' : ''; ?>" href="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php?tab=open_requests')); ?>">Open Requests <span>(<?php echo $countOpenRequests; ?>)</span></a>
	<a class="rp-tab <?php echo $activeTab === 'monthly_young' ? 'is-active' : ''; ?>" href="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php?tab=monthly_young')); ?>">Monthly (0–23) <span>(<?php echo count($groupYoung); ?>)</span></a>
	<a class="rp-tab <?php echo $activeTab === 'monthly_old' ? 'is-active' : ''; ?>" href="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php?tab=monthly_old')); ?>">Monthly (24–60) <span>(<?php echo count($groupOld); ?>)</span></a>
	<a class="rp-tab <?php echo $activeTab === 'quarterly' ? 'is-active' : ''; ?>" href="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php?tab=quarterly')); ?>">Quarterly <span>(<?php echo count($groupQuarterly); ?>)</span></a>
</div>

<!-- ============ TABLE ============ -->
<?php if ($activeTab !== 'open_requests'): ?>
<div class="appt-card">
<?php endif; ?>
	<?php
	$tabLabels = ['open_requests' => 'Open Requests (Parent-Requested)', 'monthly_young' => 'Monthly Monitoring (0–23 months)', 'monthly_old' => 'Monthly Monitoring (24–60 months, with problems)', 'quarterly' => 'Quarterly Monitoring (24–60 months, normal)'];
	$tabSubs = ['open_requests' => 'Appointments requested by parents awaiting your confirmation', 'monthly_young' => 'Infants and toddlers on mandatory monthly reweighing', 'monthly_old' => 'Older children with abnormal WHO indicators on monthly schedule', 'quarterly' => 'Normal older children on quarterly re-check schedule'];
	?>
	<div class="appt-card-head">
		<div>
			<h3 class="appt-card-title"><?php echo $tabLabels[$activeTab]; ?></h3>
			<p class="appt-card-sub"><?php echo $tabSubs[$activeTab]; ?></p>
		</div>
	</div>

	<?php if (empty($pageRows)): ?>
		<div style="text-align:center;padding:24px;color:var(--admin-muted);"><?php echo $activeTab === 'open_requests' ? 'No pending parent requests.' : 'No children in this group.'; ?></div>
	<?php else: ?>
	<div style="overflow-x:auto;">
		<table class="appt-table">
			<thead>
				<tr>
					<?php if ($activeTab === 'open_requests'): ?>
						<th style="width:150px;">Child</th>
						<th style="width:120px;">Parent</th>
						<th style="width:110px;">Barangay</th>
						<th style="width:120px;">Requested</th>
						<th style="width:150px;">Notes</th>
						<th style="width:80px;">Status</th>
						<th style="width:100px;">Actions</th>
					<?php else: ?>
						<th style="width:150px;">Child</th>
						<th style="width:55px;">Age</th>
						<th style="width:110px;">Barangay</th>
						<th style="width:100px;">Last Measured</th>
						<th style="width:100px;">Next Due</th>
						<th style="width:90px;">Status</th>
						<th style="width:100px;">Actions</th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php if ($activeTab === 'open_requests'):
					foreach ($pageRows as $req):
						$fullName = $req['first_name'] . ' ' . $req['last_name'];
						$reqDate = date('M j, g:i A', strtotime($req['scheduled_at']));
						$notes = $req['notes'] ? '<span style="color:var(--admin-text);">' . nutritionist_e($req['notes']) . '</span>' : '<span style="color:var(--admin-muted);font-style:italic;">None</span>';
						$reqStatus = $req['appt_status'] ?? 'pending';
						$reqPillClass = $reqStatus === 'confirmed' ? 'upcoming' : 'due';
						$reqPillLabel = $reqStatus === 'confirmed' ? 'Confirmed' : 'Pending';
				?>
				<tr>
					<td>
						<strong><?php echo nutritionist_e($fullName); ?></strong>
						<div style="font-size:10px;color:var(--admin-muted);"><?php echo nutritionist_e($req['child_code']); ?></div>
					</td>
					<td>
						<?php echo nutritionist_e($req['parent_name'] ?? '—'); ?>
						<?php if ($req['parent_phone']): ?>
							<div style="font-size:10px;color:var(--admin-muted);"><?php echo nutritionist_e($req['parent_phone']); ?></div>
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
							<?php if ($reqStatus === 'pending'): ?>
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
							<?php else: ?>
							<form method="post" style="display:inline;">
								<input type="hidden" name="action" value="complete_request">
								<input type="hidden" name="id" value="<?php echo (int)$req['id']; ?>">
								<button type="submit" class="admin-btn" title="Mark completed">&#10003; Done</button>
							</form>
							<?php endif; ?>
						</div>
					</td>
				</tr>
				<?php endforeach;
				else:
					foreach ($pageRows as $entry):
						$fullName = $entry['first_name'] . ' ' . $entry['last_name'];
						$lastMeasured = $entry['last_measurement_date'] ? date('M j', strtotime($entry['last_measurement_date'])) : '<span style="color:var(--admin-muted);">Never</span>';
						$dueDate = date('M j', strtotime($entry['next_due']));
						$pillClass = match ($entry['status']) { 'overdue' => 'overdue', 'due_today' => 'due', 'due_soon' => 'due', 'completed' => 'completed', 'special_monitoring' => 'special', default => 'upcoming' };
						$pillLabel = match ($entry['status']) { 'overdue' => 'Overdue', 'due_today' => 'Due Today', 'due_soon' => 'Due Soon', 'completed' => 'Completed', 'special_monitoring' => 'Special', default => 'Upcoming' };
				?>
				<tr>
					<td>
						<strong><?php echo nutritionist_e($fullName); ?></strong>
						<div style="font-size:10px;color:var(--admin-muted);"><?php echo nutritionist_e($entry['child_code']); ?></div>
					</td>
					<td><?php echo $entry['age_months']; ?> mo</td>
					<td><?php echo nutritionist_e($entry['barangay_name'] ?? ''); ?></td>
					<td><?php echo $lastMeasured; ?></td>
					<td>
						<?php if ($entry['days_until_due'] < 0): ?>
							<strong style="color:#dc2626;"><?php echo $dueDate; ?></strong>
						<?php elseif ($entry['days_until_due'] === 0): ?>
							<strong style="color:#d97706;">Today</strong>
						<?php else: ?>
							<?php echo $dueDate; ?>
						<?php endif; ?>
					</td>
					<td><span class="appt-pill <?php echo $pillClass; ?>"><?php echo $pillLabel; ?></span></td>
					<td>
						<div class="admin-actions" onclick="event.stopPropagation();">
							<a class="admin-icon-btn admin-icon-btn-primary" title="View follow-up" href="<?php echo nutritionist_e(app_url('/nutritionist/followup_child.php?id=' . $entry['id'])); ?>"><?php echo admin_action_icon('view'); ?></a>
							<a class="admin-icon-btn" title="Record measurement" href="<?php echo nutritionist_e(app_url('/nutritionist/measurement_record.php?id=' . $entry['id'])); ?>"><?php echo admin_action_icon('add'); ?></a>
						</div>
					</td>
				</tr>
				<?php endforeach;
				endif; ?>
			</tbody>
		</table>
	</div>

	<?php if ($totalPages > 1): ?>
	<div class="appt-pagination">
		<span>Showing <?php echo ($offset + 1); ?>–<?php echo min($offset + $perPage, $totalRows); ?> of <?php echo $totalRows; ?></span>
		<div style="display:flex;gap:4px;">
			<?php
			$pageLink = function (int $p) use ($activeTab, $monthParam) {
				$params = ['tab' => $activeTab];
				if ($p > 1) $params['page'] = $p;
				if ($monthParam !== (new DateTimeImmutable('today'))->format('Y-m')) $params['m'] = $monthParam;
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
<?php if ($activeTab !== 'open_requests'): ?>
</div>
<?php endif; ?>

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

<?php nutritionist_layout_end(); ?>

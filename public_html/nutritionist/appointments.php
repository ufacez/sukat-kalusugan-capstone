<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/followup_scheduler.php';

$user = nutritionist_require_access();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = (string)($_POST['action'] ?? '');
	$appointmentId = (int)($_POST['id'] ?? 0);

	if ($action === 'sync_followups') {
		nutritionist_require_write();
		$result = followup_sync_for_scope($user);
		admin_redirect('/nutritionist/appointments.php', ['notice' => sprintf(
			'EOPT follow-ups synced — %d generated, %d auto-completed.',
			(int)$result['generated'],
			(int)$result['completed']
		)]);
	}

	if ($action === 'complete_followup' && $appointmentId > 0) {

		nutritionist_require_write();

		$appointment = admin_fetch_one(
			"SELECT
				a.id,
				a.child_id,
				a.scheduled_at,
				lm.measurement_date AS last_measured,
				c.first_name,
				c.last_name
			 FROM appointments a
			 INNER JOIN children c ON c.id = a.child_id
			 LEFT JOIN measurements lm ON lm.id = (
				SELECT m.id FROM measurements m
				WHERE m.child_id = a.child_id
				ORDER BY m.measurement_date DESC, m.id DESC
				LIMIT 1
			 )
			 WHERE a.id = ?
			   AND a.appointment_type = 'followup'
			   AND a.status IN ('pending', 'confirmed')
			 LIMIT 1",
			'i',
			[$appointmentId]
		);

		if ($appointment === null) {
			admin_redirect('/nutritionist/appointments.php', ['notice' => 'Follow-up not found or already closed.', 'type' => 'error']);
		}

		try {
			$satisfiedFrom = (new DateTimeImmutable((string)$appointment['scheduled_at']))
				->setTime(0, 0)
				->modify('-' . FOLLOWUP_GRACE_DAYS . ' days');
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

		$ok = admin_execute(
			"UPDATE appointments
			 SET status = 'completed'
			 WHERE id = ? AND status IN ('pending', 'confirmed')",
			'i',
			[$appointmentId]
		);

		log_action(
			(int)$user['id'],
			'FOLLOWUP_COMPLETE',
			'info',
			sprintf('Mandatory follow-up #%d satisfied by re-measurement dated %s (%s %s).', $appointmentId, (string)$measuredAt, $appointment['first_name'], $appointment['last_name'])
		);

		followup_sync_for_scope($user);

		admin_redirect('/nutritionist/appointments.php', $ok ? ['notice' => 'Re-measurement verified — follow-up completed and next cycle scheduled.'] : ['notice' => 'Follow-up could not be updated.', 'type' => 'error']);
	}

	if ($action === 'update_status' && $appointmentId > 0) {

		nutritionist_require_write();

		$type = (string)(admin_fetch_one(
			'SELECT appointment_type FROM appointments WHERE id = ? LIMIT 1',
			'i',
			[$appointmentId]
		)['appointment_type'] ?? 'regular');

		if ($type === 'followup') {
			admin_redirect('/nutritionist/appointments.php', [
				'notice' => 'Automatic follow-ups cannot be re-statused manually — verify the mandatory re-measurement instead.',
				'type' => 'error',
			]);
		}

		$status = (string)($_POST['status'] ?? 'pending');

		if (!in_array($status, ['pending', 'confirmed', 'completed', 'cancelled'], true)) {
			$status = 'pending';
		}

		$ok = admin_execute('UPDATE appointments SET status = ? WHERE id = ?', 'si', [$status, $appointmentId]);
		admin_redirect('/nutritionist/appointments.php', $ok ? ['notice' => 'Appointment updated.'] : ['notice' => 'Appointment could not be updated.', 'type' => 'error']);
	}

	if ($action === 'delete' && $appointmentId > 0) {

		nutritionist_require_write();

		$type = (string)(admin_fetch_one(
			'SELECT appointment_type FROM appointments WHERE id = ? LIMIT 1',
			'i',
			[$appointmentId]
		)['appointment_type'] ?? 'regular');

		if ($type === 'followup') {
			log_action((int)$user['id'], 'FOLLOWUP_DELETE_BLOCKED', 'warning', sprintf('Attempted deletion of mandatory follow-up #%d was blocked.', $appointmentId));
			admin_redirect('/nutritionist/appointments.php', ['notice' => 'Automatic follow-ups are MANDATORY and cannot be deleted.', 'type' => 'error']);
		}

		$ok = admin_execute('DELETE FROM appointments WHERE id = ?', 'i', [$appointmentId]);
		admin_redirect('/nutritionist/appointments.php', $ok ? ['notice' => 'Appointment removed.'] : ['notice' => 'Appointment could not be removed.', 'type' => 'error']);
	}
}

$syncResult = followup_sync_for_scope($user);

$params = [];
$scope = nutritionist_scope_fragment($user, 'c.barangay_id', $params);
$appointments = admin_fetch_all(
	"SELECT
		a.id,
		a.scheduled_at,
		a.status,
		a.notes,
		a.appointment_type,
		a.followup_track,
		a.followup_category,
		a.location,
		c.id AS child_id,
		c.child_code,
		c.first_name,
		c.last_name,
		bg.name AS barangay,
		p.name AS parent_name,
		p.phone AS parent_phone,
		u.name AS nutritionist_name
	 FROM appointments a
	 INNER JOIN children c ON c.id = a.child_id
	 INNER JOIN parents p ON p.id = a.parent_id
	 INNER JOIN users u ON u.id = a.nutritionist_id
	 LEFT JOIN barangays bg ON bg.id = c.barangay_id
	 WHERE {$scope}
	 ORDER BY a.scheduled_at ASC, a.id ASC",
	str_repeat('i', count($params)),
	$params
);

$today = new DateTimeImmutable('today');
$now = new DateTimeImmutable('now');
$weekStart = $now->modify('Monday this week')->setTime(0, 0);
$weekEnd = $weekStart->modify('+7 days');
$tomorrowStart = $now->modify('+1 day')->setTime(0, 0);
$tomorrowEnd = $tomorrowStart->modify('+1 day');
$monthStart = $now->modify('first day of this month')->setTime(0, 0);
$monthEnd = $monthStart->modify('+1 month');

$regularAppointments = [];
$followUpAppointments = [];
$upcomingList = [];
$displayAppointments = [];
$calendarMonthMap = [];

$totalAll = count($appointments);
$openAll = 0;
$upcoming7 = 0;
$completedThisMonth = 0;
$thisWeekCount = 0;
$tomorrowCount = 0;
$followUpOverdue = 0;
$followUpOpen = 0;
$followUpCompleted = 0;

$filterStatus = (string)($_GET['status'] ?? '');
$filterChildId = (int)($_GET['child'] ?? 0);
$filterFrom = (string)($_GET['from'] ?? '');
$filterTo = (string)($_GET['to'] ?? '');
$tableSearch = trim((string)($_GET['q'] ?? ''));
$tablePage = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;

$monthParam = (string)($_GET['m'] ?? $now->format('Y-m'));
try {
	$monthAnchor = new DateTimeImmutable($monthParam . '-01');
} catch (Exception) {
	$monthAnchor = $now->modify('first day of this month');
}
$calendarYear = (int)$monthAnchor->format('Y');
$calendarMonth = (int)$monthAnchor->format('n');
$monthLabel = $monthAnchor->format('F Y');
$prevMonth = $monthAnchor->modify('-1 month')->format('Y-m');
$nextMonth = $monthAnchor->modify('+1 month')->format('Y-m');

foreach ($appointments as &$appointment) {
	$type = (string)($appointment['appointment_type'] ?? 'regular');
	$status = (string)$appointment['status'];

	try {
		$scheduledAt = new DateTimeImmutable((string)$appointment['scheduled_at']);
	} catch (Exception) {
		continue;
	}

	$appointment['scheduled_dt'] = $scheduledAt;
	$appointment['location'] = trim((string)($appointment['location'] ?? '')) !== '' ? trim((string)$appointment['location']) : 'Barangay Health Center';

	if ($type === 'followup') {
		$isOverdue = false;
		if (in_array($status, ['pending', 'confirmed'], true)) {
			$isOverdue = $scheduledAt < $today;
		}
		$appointment['is_overdue'] = $isOverdue;

		if ($isOverdue) {
			$followUpOverdue++;
		}
		if (in_array($status, ['pending', 'confirmed'], true)) {
			$followUpOpen++;
		}
		if ($status === 'completed') {
			$followUpCompleted++;
		}

		$followUpAppointments[] = $appointment;
	} else {
		$regularAppointments[] = $appointment;
		if (in_array($status, ['pending', 'confirmed'], true)) {
			$openAll++;
			if ($scheduledAt >= $now && $scheduledAt < $now->modify('+7 days')) {
				$upcoming7++;
			}
			if ($scheduledAt >= $weekStart && $scheduledAt < $weekEnd) {
				$thisWeekCount++;
			}
			if ($scheduledAt >= $tomorrowStart && $scheduledAt < $tomorrowEnd) {
				$tomorrowCount++;
			}
		}
		if ($status === 'completed' && $scheduledAt >= $monthStart && $scheduledAt < $monthEnd) {
			$completedThisMonth++;
		}
	}

	if (in_array($status, ['pending', 'confirmed'], true) && $scheduledAt >= $now) {
		$upcomingList[] = $appointment;
	}

	if ((int)$scheduledAt->format('Y') === $calendarYear && (int)$scheduledAt->format('n') === $calendarMonth) {
		$dayKey = (int)$scheduledAt->format('j');
		if (!isset($calendarMonthMap[$dayKey])) {
			$calendarMonthMap[$dayKey] = ['pending' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0, 'overdue' => 0];
		}
		$isOpen = in_array($status, ['pending', 'confirmed'], true);
		if ($isOpen && $scheduledAt < $today) {
			$calendarMonthMap[$dayKey]['overdue']++;
		} elseif (isset($calendarMonthMap[$dayKey][$status])) {
			$calendarMonthMap[$dayKey][$status]++;
		}
	}
}
unset($appointment);

$calendarEntries = [];
$calendarAppointmentsByDay = [];
foreach ($appointments as $appointment) {
	$dayKey = (int)$appointment['scheduled_dt']->format('j');
	if ((int)$appointment['scheduled_dt']->format('Y') !== $calendarYear
		|| (int)$appointment['scheduled_dt']->format('n') !== $calendarMonth) {
		continue;
	}
	$apptStatus = (string)$appointment['status'];
	$isOverdue = in_array($apptStatus, ['pending', 'confirmed'], true)
		&& $appointment['scheduled_dt'] < $today;
	$effectiveStatus = $isOverdue ? 'overdue' : $apptStatus;
	$calendarEntries[$dayKey][] = [
		'type' => 'appointment',
		'color' => nutritionist_calendar_color('appointment'),
		'title' => $appointment['first_name'] . ' ' . $appointment['last_name']
			. ' (' . (($appointment['appointment_type'] ?? 'regular') === 'followup' ? 'Follow-up' : 'Appointment') . ')',
		'time' => $appointment['scheduled_dt']->format('g:i A'),
		'id' => (int)$appointment['id'],
		'location' => (string)$appointment['location'],
		'status' => $effectiveStatus,
	];
	$calendarAppointmentsByDay[$dayKey][] = $appointment;
}

ksort($calendarEntries);

$todayDayNum = (int)$today->format('j');
$isCurrentMonthCalendar = ((int)$today->format('Y') === $calendarYear && (int)$today->format('n') === $calendarMonth);
$defaultCalendarDay = null;
if ($isCurrentMonthCalendar && isset($calendarEntries[$todayDayNum])) {
	$defaultCalendarDay = $today->format('Y-m-d');
} else {
	foreach ($calendarEntries as $dayKey => $entries) {
		if ($entries !== []) {
			$defaultCalendarDay = $monthAnchor->setDate($calendarYear, $calendarMonth, $dayKey)->format('Y-m-d');
			break;
		}
	}
}

usort($upcomingList, static function (array $a, array $b): int {
	return $a['scheduled_dt'] <=> $b['scheduled_dt'];
});
$upcomingList = array_slice($upcomingList, 0, 5);

foreach ($appointments as $appointment) {
	$status = (string)$appointment['status'];
	$childId = (int)$appointment['child_id'];

	if ($filterStatus !== '' && $status !== $filterStatus) {
		continue;
	}
	if ($filterChildId > 0 && $childId !== $filterChildId) {
		continue;
	}
	if ($filterFrom !== '') {
		try {
			$fromDt = new DateTimeImmutable($filterFrom);
			if ($appointment['scheduled_dt'] < $fromDt) {
				continue;
			}
		} catch (Exception) {
		}
	}
	if ($filterTo !== '') {
		try {
			$toDt = (new DateTimeImmutable($filterTo))->modify('+1 day');
			if ($appointment['scheduled_dt'] >= $toDt) {
				continue;
			}
		} catch (Exception) {
		}
	}
	if ($tableSearch !== '') {
		$haystack = strtolower(
			$appointment['first_name'] . ' ' .
			$appointment['last_name'] . ' ' .
			$appointment['parent_name'] . ' ' .
			$appointment['child_code'] . ' ' .
			$appointment['status'] . ' ' .
			$appointment['location'] . ' ' .
			$appointment['barangay']
		);
		if (strpos($haystack, strtolower($tableSearch)) === false) {
			continue;
		}
	}

	$displayAppointments[] = $appointment;
}

$displayTotal = count($displayAppointments);
$totalPages = max(1, (int)ceil($displayTotal / $perPage));
if ($tablePage > $totalPages) {
	$tablePage = $totalPages;
}
$tableStart = ($tablePage - 1) * $perPage;
$tableEnd = min($tableStart + $perPage, $displayTotal);
$tableRows = array_slice($displayAppointments, $tableStart, $perPage);

$childFilterParams = [];
$childFilterScope = nutritionist_scope_fragment($user, 'c.barangay_id', $childFilterParams);
$filterChildren = admin_fetch_all(
	"SELECT c.id, c.first_name, c.last_name
	 FROM children c
	 WHERE {$childFilterScope}
	 ORDER BY c.last_name ASC, c.first_name ASC",
	str_repeat('i', count($childFilterParams)),
	$childFilterParams
);

$actions = (nutritionist_can_write()
	? '<a class="admin-btn admin-btn-primary" style="background:var(--admin-primary);border-color:var(--admin-primary);color:#fff;" href="' . nutritionist_e(app_url('/nutritionist/appointment_form.php')) . '">' . admin_action_icon('add') . ' New Appointment</a>'
	. '<form method="post" action="' . nutritionist_e(app_url('/nutritionist/appointments.php')) . '" style="display:inline;">'
	. '<input type="hidden" name="action" value="sync_followups">'
	. '<button class="admin-btn-secondary" type="submit">' . admin_action_icon('sync') . ' Sync EOPT</button>'
	. '</form>'
	: '');

nutritionist_layout_start('Appointments', 'Manage and track nutrition consultations and follow-up visits.', 'appointments', $actions);
?>
<style>
/* === Appointment v2 — local overrides only === */
.appt-stat-row { margin-bottom: 18px; }
.appt-stat-row .admin-grid-cards { margin: 0; }
.appt-stat-trend-line { display:flex; align-items:center; gap:4px; font-size:11px; font-weight:600; }
.appt-stat-trend-line .ic { width:12px; height:12px; }
.appt-stat-trend-line.is-up   { color: #16a34a; }
.appt-stat-trend-line.is-warn { color: #d97706; }
.appt-stat-trend-line.is-danger { color: #dc2626; }
</style>

<section class="appt-stat-row">
	<div class="admin-grid-cards">
		<article class="admin-card">
			<div class="admin-card-row">
				<div class="admin-card-icon" style="background:rgba(59,130,246,.12);color:#2563eb;">
					<?php echo admin_action_icon('calendar'); ?>
				</div>
				<div class="admin-card-content">
					<div class="admin-card-label">Total Appointments</div>
					<div class="admin-card-value"><?php echo $totalAll; ?></div>
					<div class="admin-card-meta">
						<span class="appt-stat-trend-line">All records</span>
					</div>
				</div>
			</div>
		</article>
		<article class="admin-card">
			<div class="admin-card-row">
				<div class="admin-card-icon" style="background:rgba(14,165,233,.12);color:#0284c7;">
					<?php echo admin_action_icon('bell'); ?>
				</div>
				<div class="admin-card-content">
					<div class="admin-card-label">Upcoming</div>
					<div class="admin-card-value"><?php echo $upcoming7; ?></div>
					<div class="admin-card-meta">
						<span class="appt-stat-trend-line">Next 7 days</span>
					</div>
				</div>
			</div>
		</article>
		<article class="admin-card">
			<div class="admin-card-row">
				<div class="admin-card-icon is-success">
					<?php echo admin_action_icon('verify'); ?>
				</div>
				<div class="admin-card-content">
					<div class="admin-card-label">Completed</div>
					<div class="admin-card-value"><?php echo $completedThisMonth; ?></div>
					<div class="admin-card-meta">
						<span class="appt-stat-trend-line">This month</span>
					</div>
				</div>
			</div>
		</article>
		<article class="admin-card">
			<div class="admin-card-row">
				<div class="admin-card-icon is-danger">
					<?php echo admin_action_icon('cancel'); ?>
				</div>
				<div class="admin-card-content">
					<div class="admin-card-label">Overdue</div>
					<div class="admin-card-value" style="<?php echo $followUpOverdue > 0 ? 'color:#dc2626;' : ''; ?>"><?php echo $followUpOverdue; ?></div>
					<div class="admin-card-meta">
						<span class="appt-stat-trend-line is-danger">Needs follow-up</span>
					</div>
				</div>
			</div>
		</article>
	</div>
</section>

<?php if ($syncResult['generated'] > 0 || $syncResult['completed'] > 0 || ($syncResult['recategorized'] ?? 0) > 0): ?>
	<div class="admin-flash">
		EOPT scheduler: <?php echo (int)$syncResult['generated']; ?> follow-up(s) generated · <?php echo (int)$syncResult['completed']; ?> cycle(s) auto-completed<?php echo ((int)($syncResult['recategorized'] ?? 0)) > 0 ? ' · ' . (int)$syncResult['recategorized'] . ' reclassified' : ''; ?>.
	</div>
<?php endif; ?>

<div class="appt-main">

	<!-- ============ CALENDAR ============ -->
	<section class="appt-card appt-card-cal">
		<div class="appt-card-head">
			<h3 class="appt-card-title"><?php echo nutritionist_e($monthLabel); ?></h3>
			<div class="appt-cal-nav">
				<a class="appt-cal-nav-btn" href="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php?m=' . $prevMonth)); ?>" aria-label="Previous month"><?php echo admin_action_icon('chevron_left'); ?></a>
				<a class="appt-cal-nav-btn" href="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php?m=' . $nextMonth)); ?>" aria-label="Next month"><?php echo admin_action_icon('chevron_right'); ?></a>
			</div>
		</div>
		<div class="appt-cal-weekdays">
			<?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $wk): ?>
				<span><?php echo $wk; ?></span>
			<?php endforeach; ?>
		</div>
		<div class="sk-cal-wrap" data-sk-calendar data-sk-calendar-detail="appt-cal-detail" data-sk-calendar-default="<?php echo nutritionist_e($defaultCalendarDay ?? ''); ?>">
			<?php echo nutritionist_render_calendar_grid($monthAnchor, $calendarEntries, $today); ?>
		</div>

		<div class="sk-cal-detail" id="appt-cal-detail" data-calendar-detail>
			<?php
			$hasAnyApptDay = false;
			foreach ($calendarEntries as $entries) {
				if ($entries !== []) { $hasAnyApptDay = true; break; }
			}
			$initIsoDay = $defaultCalendarDay;
			$initEntries = [];
			if ($initIsoDay !== null) {
				$initDayNum = (int)(new DateTimeImmutable($initIsoDay))->format('j');
				$initEntries = $calendarEntries[$initDayNum] ?? [];
				$initEntries = array_slice($initEntries, 0, 3);
			}
			$initLabel = $initIsoDay !== null
				? (new DateTimeImmutable($initIsoDay))->format('l, F j, Y')
				: $monthAnchor->format('F Y');
			?>
			<div class="sk-cal-detail-head">
				<div>
					<div class="sk-cal-detail-title" data-calendar-detail-title>
						<?php echo nutritionist_e($initLabel); ?>
						<?php if ($initIsoDay === $today->format('Y-m-d')): ?>
							<span class="sk-cal-detail-today">Today</span>
						<?php endif; ?>
					</div>
					<div class="sk-cal-detail-sub" data-calendar-detail-sub>
						<?php echo count($initEntries); ?> event<?php echo count($initEntries) !== 1 ? 's' : ''; ?>
					</div>
				</div>
			</div>
			<div class="sk-cal-event-list is-compact" data-calendar-detail-list>
				<?php if (!$hasAnyApptDay): ?>
					<div class="sk-cal-detail-empty" data-calendar-detail-empty>
						No appointments in <?php echo nutritionist_e($monthLabel); ?>.
					</div>
				<?php else: ?>
					<?php foreach ($initEntries as $te):
						$teTime = $te['time'] ?? null;
						$teLabel = nutritionist_calendar_label((string)$te['type']);
						$teColor = (string)($te['color'] ?? nutritionist_calendar_color((string)$te['type']));
						$teStatus = (string)($te['status'] ?? '');
						$hasStatus = in_array($teStatus, ['overdue', 'cancelled', 'completed'], true);
						$teLoc = (string)($te['location'] ?? '');
						$teId = isset($te['id']) ? (int)$te['id'] : 0;
					?>
					<div class="sk-cal-event" data-entry-type="<?php echo nutritionist_e((string)$te['type']); ?>">
						<div class="sk-cal-event-head">
							<span class="sk-cal-event-dot" style="background:<?php echo nutritionist_e($teColor); ?>;"></span>
							<span class="sk-cal-event-type"><?php echo nutritionist_e($teLabel); ?></span>
							<?php if ($hasStatus): ?>
								<span class="sk-cal-event-status is-<?php echo nutritionist_e($teStatus); ?>"><?php echo nutritionist_e(ucfirst($teStatus)); ?></span>
							<?php endif; ?>
						</div>
						<div class="sk-cal-event-body">
							<?php if ($teTime !== null): ?>
								<div class="sk-cal-event-time"><?php echo nutritionist_e($teTime); ?></div>
							<?php endif; ?>
							<div class="sk-cal-event-title"><?php echo nutritionist_e((string)$te['title']); ?></div>
							<?php if ($teLoc !== ''): ?>
								<div class="sk-cal-event-loc">
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
									<span><?php echo nutritionist_e($teLoc); ?></span>
								</div>
							<?php endif; ?>
						</div>
						<?php if ($teId > 0): ?>
							<a class="sk-cal-event-action" href="<?php echo nutritionist_e(app_url('/nutritionist/appointment_form.php?id=' . $teId)); ?>">Open →</a>
						<?php endif; ?>
					</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<a class="sk-cal-detail-link" data-calendar-detail-link href="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php' . ($initIsoDay !== null ? '?from=' . $initIsoDay . '&to=' . $initIsoDay : ''))); ?>">View all appointments on this day →</a>
		</div>
	</section>

	<!-- ============ UPCOMING LIST ============ -->
	<section class="appt-card appt-card-upcoming">
		<div class="appt-card-head">
			<h3 class="appt-card-title">Upcoming Appointments</h3>
			<a class="appt-card-link" href="#all-appointments">View All</a>
		</div>
		<?php if (empty($upcomingList)): ?>
			<div class="appt-empty">No upcoming appointments. Schedule a new visit to populate this list.</div>
		<?php else: ?>
			<ul class="appt-upcoming-list">
				<?php foreach ($upcomingList as $appt):
					$dt = $appt['scheduled_dt'];
					$fullName = $appt['first_name'] . ' ' . $appt['last_name'];
					$initials = admin_initials($fullName);
					$avatarBg = admin_avatar_color($fullName);
					$typeLabel = $appt['appointment_type'] === 'followup' ? 'Follow-up Visit' : (($appt['appointment_type'] ?? '') === 'consultation' ? 'Consultation' : 'Nutrition Consultation');
					$timeRange = $dt->format('g:i A');
					try {
						$endDt = $dt->modify('+1 hour');
						$timeRange = $dt->format('g:i A') . ' – ' . $endDt->format('g:i A');
					} catch (Exception) {
					}
					?>
					<li class="appt-upcoming-row">
						<div class="appt-date-badge">
							<span class="appt-date-month"><?php echo strtoupper($dt->format('M')); ?></span>
							<span class="appt-date-day"><?php echo $dt->format('d'); ?></span>
							<span class="appt-date-weekday"><?php echo $dt->format('D'); ?></span>
						</div>
						<div class="appt-upcoming-avatar" style="background:<?php echo $avatarBg; ?>"><?php echo nutritionist_e($initials); ?></div>
						<div class="appt-upcoming-meta">
							<div class="appt-upcoming-name"><?php echo nutritionist_e($fullName); ?></div>
							<div class="appt-upcoming-sub">
								<span class="appt-upcoming-type"><?php echo nutritionist_e($typeLabel); ?></span>
								<span class="appt-upcoming-time"><?php echo nutritionist_e($timeRange); ?></span>
							</div>
							<div class="appt-upcoming-loc">
								<?php echo admin_action_icon('location'); ?>
								<span><?php echo nutritionist_e((string)$appt['location']); ?></span>
							</div>
						</div>
						<div class="appt-upcoming-right">
							<span class="admin-pill <?php echo nutritionist_status_class((string)$appt['status']); ?>"><?php echo nutritionist_e(ucfirst((string)$appt['status'])); ?></span>
							<a class="appt-upcoming-link" href="#appt-<?php echo (int)$appt['id']; ?>" aria-label="View details"><?php echo admin_action_icon('chevron_right'); ?></a>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>

	<!-- ============ FILTERS / QUICK STATS / REMINDER ============ -->
	<aside class="appt-side">

		<section class="appt-card">
			<div class="appt-card-head">
				<h3 class="appt-card-title">
					<?php echo admin_action_icon('funnel'); ?>
					<span>Filters</span>
				</h3>
				<a class="appt-card-link" href="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php')); ?>">Reset</a>
			</div>
			<form method="get" action="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php')); ?>" class="appt-filter-form">
				<label class="appt-field">
					<span>Date Range</span>
					<div class="appt-field-row">
						<input type="date" name="from" value="<?php echo nutritionist_e($filterFrom); ?>" class="appt-input" placeholder="From">
						<span class="appt-field-sep">–</span>
						<input type="date" name="to" value="<?php echo nutritionist_e($filterTo); ?>" class="appt-input" placeholder="To">
					</div>
				</label>
				<label class="appt-field">
					<span>Child</span>
					<select name="child" class="appt-input">
						<option value="">All Children</option>
						<?php foreach ($filterChildren as $child): ?>
							<option value="<?php echo (int)$child['id']; ?>" <?php echo $filterChildId === (int)$child['id'] ? 'selected' : ''; ?>><?php echo nutritionist_e($child['first_name'] . ' ' . $child['last_name']); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="appt-field">
					<span>Status</span>
					<select name="status" class="appt-input">
						<option value="">All Status</option>
						<?php foreach (['pending', 'confirmed', 'completed', 'cancelled'] as $st): ?>
							<option value="<?php echo $st; ?>" <?php echo $filterStatus === $st ? 'selected' : ''; ?>><?php echo ucfirst($st); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<input type="hidden" name="q" value="<?php echo nutritionist_e($tableSearch); ?>">
				<button type="submit" class="appt-btn-primary">Apply Filters</button>
			</form>
			<div class="appt-presets">
				<span class="appt-presets-label">Quick presets:</span>
				<a class="appt-preset-chip" href="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php?from=' . $now->format('Y-m-d') . '&to=' . $now->modify('+7 days')->format('Y-m-d'))); ?>">Next 7 days</a>
				<a class="appt-preset-chip" href="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php?from=' . $monthStart->format('Y-m-d') . '&to=' . $monthEnd->modify('-1 day')->format('Y-m-d'))); ?>">This month</a>
			</div>
		</section>

		<section class="appt-card">
			<div class="appt-card-head">
				<h3 class="appt-card-title">Quick Stats</h3>
			</div>
			<div class="appt-quickstats">
				<div class="appt-quick-tile">
					<div class="appt-quick-icon" style="background:rgba(59,130,246,.12);color:#2563eb;"><?php echo admin_action_icon('calendar'); ?></div>
					<div class="appt-quick-meta">
						<div class="appt-quick-label">This Week</div>
						<div class="appt-quick-value"><?php echo $thisWeekCount; ?></div>
						<div class="appt-quick-sub">appointments</div>
					</div>
				</div>
				<div class="appt-quick-tile">
					<div class="appt-quick-icon" style="background:rgba(14,165,233,.12);color:#0284c7;"><?php echo admin_action_icon('bell'); ?></div>
					<div class="appt-quick-meta">
						<div class="appt-quick-label">Tomorrow</div>
						<div class="appt-quick-value"><?php echo $tomorrowCount; ?></div>
						<div class="appt-quick-sub">appointments</div>
					</div>
				</div>
				<div class="appt-quick-tile">
					<div class="appt-quick-icon" style="background:rgba(34,197,94,.12);color:#16a34a;"><?php echo admin_action_icon('verify'); ?></div>
					<div class="appt-quick-meta">
						<div class="appt-quick-label">This Month</div>
						<div class="appt-quick-value"><?php echo $completedThisMonth; ?></div>
						<div class="appt-quick-sub">completed</div>
					</div>
				</div>
			</div>
		</section>

		<section class="appt-card appt-reminder">
			<div class="appt-reminder-head">
				<span class="appt-reminder-icon"><?php echo admin_action_icon('lightbulb'); ?></span>
				<span class="appt-reminder-label">Reminder</span>
			</div>
			<p class="appt-reminder-body">Please make sure to update the appointment status after the visit to keep records accurate.</p>
		</section>

	</aside>
</div>

<!-- ============ ALL APPOINTMENTS TABLE ============ -->
<section class="appt-card appt-table-card" id="all-appointments">
	<div class="appt-card-head">
		<div>
			<h3 class="appt-card-title">All Appointments</h3>
			<p class="appt-card-sub"><?php echo $displayTotal; ?> matching result<?php echo $displayTotal !== 1 ? 's' : ''; ?><?php echo ($filterStatus || $filterChildId || $filterFrom || $filterTo || $tableSearch) ? ' (filtered)' : ''; ?></p>
		</div>
		<div class="appt-table-tools">
			<form method="get" action="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php')); ?>" class="appt-search-form">
				<?php if ($filterStatus !== ''): ?><input type="hidden" name="status" value="<?php echo nutritionist_e($filterStatus); ?>"><?php endif; ?>
				<?php if ($filterChildId > 0): ?><input type="hidden" name="child" value="<?php echo $filterChildId; ?>"><?php endif; ?>
				<?php if ($filterFrom !== ''): ?><input type="hidden" name="from" value="<?php echo nutritionist_e($filterFrom); ?>"><?php endif; ?>
				<?php if ($filterTo !== ''): ?><input type="hidden" name="to" value="<?php echo nutritionist_e($filterTo); ?>"><?php endif; ?>
				<?php echo admin_action_icon('search'); ?>
				<input type="search" name="q" value="<?php echo nutritionist_e($tableSearch); ?>" placeholder="Search by child name, parent, or appointment type…" class="appt-search-input">
				<?php if ($tableSearch !== ''): ?>
					<a class="appt-search-clear" href="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php' . (($filterStatus || $filterChildId || $filterFrom || $filterTo) ? '?' . http_build_query(array_filter(['status' => $filterStatus, 'child' => $filterChildId ?: null, 'from' => $filterFrom, 'to' => $filterTo])) : ''))); ?>" title="Clear search">×</a>
				<?php endif; ?>
			</form>
			<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php?export=xlsx' . ($filterStatus ? '&status=' . $filterStatus : ''))); ?>" title="Export to Excel"><?php echo admin_action_icon('export'); ?> Export</a>
		</div>
	</div>

	<div class="appt-table-wrap">
		<table class="nutritionist-table" id="all-appointments-table" data-no-paginate>
			<thead>
				<tr>
					<th style="width:140px;">Date &amp; Time</th>
					<th style="width:160px;">Child</th>
					<th style="width:160px;">Parent</th>
					<th style="width:170px;">Appointment Type</th>
					<th style="width:160px;">Location</th>
					<th style="width:110px;">Status</th>
					<th style="width:130px;">Actions</th>
				</tr>
			</thead>
			<tbody>
				<?php if (empty($tableRows)): ?>
					<tr><td colspan="7" class="appt-empty-row">No appointments match the current filters.</td></tr>
				<?php endif; ?>
				<?php foreach ($tableRows as $appt):
					$dt = $appt['scheduled_dt'];
					$isFollowup = $appt['appointment_type'] === 'followup';
					$typeLabel = $isFollowup ? 'Follow-up Visit' : (($appt['appointment_type'] ?? '') === 'consultation' ? 'Consultation' : 'Nutrition Consultation');
					if ($isFollowup) {
						$trackLabel = !empty($appt['followup_track']) ? ucfirst((string)$appt['followup_track']) : '';
						if ($trackLabel !== '') {
							$typeLabel .= ' · ' . $trackLabel;
						}
					}
					?>
					<tr id="appt-<?php echo (int)$appt['id']; ?>"
						data-filter-text="<?php echo nutritionist_e(strtolower($appt['first_name'] . ' ' . $appt['last_name'] . ' ' . $appt['parent_name'] . ' ' . $appt['status'] . ' ' . $typeLabel)); ?>">
						<td>
							<div class="appt-t-date"><?php echo $dt->format('M j, Y'); ?></div>
							<div class="appt-t-time"><?php echo $dt->format('g:i A'); ?></div>
						</td>
						<td>
							<div class="appt-t-name"><?php echo nutritionist_e($appt['first_name'] . ' ' . $appt['last_name']); ?></div>
							<div class="appt-t-sub"><?php echo nutritionist_e((string)$appt['child_code']); ?></div>
						</td>
						<td>
							<div class="appt-t-name"><?php echo nutritionist_e((string)$appt['parent_name']); ?></div>
							<div class="appt-t-sub"><?php echo nutritionist_e((string)($appt['parent_phone'] ?? '')); ?></div>
						</td>
						<td><?php echo nutritionist_e($typeLabel); ?></td>
						<td class="appt-t-loc">
							<?php echo admin_action_icon('location'); ?>
							<span><?php echo nutritionist_e((string)$appt['location']); ?></span>
						</td>
						<td><span class="admin-pill <?php echo nutritionist_status_class((string)$appt['status']); ?>"><?php echo nutritionist_e(ucfirst((string)$appt['status'])); ?></span></td>
						<td>
							<div class="admin-actions">
								<a class="admin-icon-btn" title="View" href="<?php echo nutritionist_e(app_url('/nutritionist/appointment_form.php?id=' . (int)$appt['id'])); ?>"><?php echo admin_action_icon('view'); ?></a>
								<?php if (!$isFollowup && nutritionist_can_write()): ?>
									<a class="admin-icon-btn admin-icon-btn-primary" title="Edit" href="<?php echo nutritionist_e(app_url('/nutritionist/appointment_form.php?id=' . (int)$appt['id'])); ?>"><?php echo admin_action_icon('edit'); ?></a>
									<form method="post" action="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php')); ?>" onsubmit="return confirm('Delete this appointment?');" style="display:inline;">
										<input type="hidden" name="action" value="delete">
										<input type="hidden" name="id" value="<?php echo (int)$appt['id']; ?>">
										<button class="admin-icon-btn admin-icon-btn-danger" title="Delete" type="submit"><?php echo admin_action_icon('delete'); ?></button>
									</form>
								<?php elseif ($isFollowup && nutritionist_can_write() && in_array($appt['status'], ['pending', 'confirmed'], true)): ?>
									<form method="post" action="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php')); ?>" onsubmit="return confirm('Verify that a NEW measurement was recorded for this child?');" style="display:inline;">
										<input type="hidden" name="action" value="complete_followup">
										<input type="hidden" name="id" value="<?php echo (int)$appt['id']; ?>">
										<button class="admin-icon-btn admin-icon-btn-primary" title="Verify re-measurement" type="submit"><?php echo admin_action_icon('verify'); ?></button>
									</form>
								<?php endif; ?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<div class="admin-table-pagination">
		<span class="admin-table-page-info">
			Showing <?php echo $displayTotal > 0 ? ($tableStart + 1) : 0; ?>–<?php echo $tableEnd; ?> of <?php echo $displayTotal; ?> appointments
		</span>
		<div class="admin-table-pages">
			<?php
			$queryBase = array_filter([
				'status' => $filterStatus, 'child' => $filterChildId > 0 ? $filterChildId : null,
				'from' => $filterFrom, 'to' => $filterTo, 'q' => $tableSearch,
			], static fn($v) => $v !== null && $v !== '');
			$linkFor = static function (int $p) use ($queryBase): string {
				$params = $queryBase;
				if ($p > 1) {
					$params['page'] = $p;
				} elseif (isset($params['page'])) {
					unset($params['page']);
				}
				return app_url('/nutritionist/appointments.php' . (empty($params) ? '' : '?' . http_build_query($params)));
			};
			?>
			<button class="admin-table-page-btn" <?php echo $tablePage <= 1 ? 'disabled' : ''; ?> data-href="<?php echo nutritionist_e($linkFor($tablePage - 1)); ?>">‹</button>
			<?php
			$pageNumbers = [];
			if ($totalPages <= 7) {
				$pageNumbers = range(1, $totalPages);
			} else {
				$pageNumbers = [1, 2, 3];
				if ($tablePage > 4) $pageNumbers[] = '…';
				if ($tablePage > 3 && $tablePage < $totalPages - 1) $pageNumbers[] = $tablePage;
				if ($tablePage < $totalPages - 2) $pageNumbers[] = '…';
				$pageNumbers[] = $totalPages - 1;
				$pageNumbers[] = $totalPages;
				$pageNumbers = array_values(array_unique($pageNumbers));
			}
			foreach ($pageNumbers as $pn) {
				if ($pn === '…') {
					echo '<span class="admin-table-page-dots">…</span>';
				} else {
					$cls = 'admin-table-page-btn' . ($pn === $tablePage ? ' is-active' : '');
					echo '<button class="' . $cls . '" data-href="' . nutritionist_e($linkFor((int)$pn)) . '">' . (int)$pn . '</button>';
				}
			}
			?>
			<button class="admin-table-page-btn" <?php echo $tablePage >= $totalPages ? 'disabled' : ''; ?> data-href="<?php echo nutritionist_e($linkFor($tablePage + 1)); ?>">›</button>
		</div>
	</div>
</section>

<script>
(function () {
	document.querySelectorAll('.appt-table-pagination .admin-table-page-btn[data-href], .admin-table-pagination .admin-table-page-btn[data-href]').forEach(function (btn) {
		btn.addEventListener('click', function (e) {
			if (this.disabled) { e.preventDefault(); return; }
			var href = this.getAttribute('data-href');
			if (href) { window.location.href = href; }
		});
	});
})();
</script>

<?php
nutritionist_layout_end();
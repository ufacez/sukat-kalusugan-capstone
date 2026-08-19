<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

$user = nutritionist_require_access();
$today = new DateTimeImmutable('today');

function nutritionist_calendar_redirect_params(): array
{
	$params = [];

	if (isset($_GET['range'])) {
		$params['range'] = (string)$_GET['range'];
	}

	if (isset($_GET['month'])) {
		$params['month'] = (string)$_GET['month'];
	}

	return $params;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$eventAction = (string)($_POST['action'] ?? '');
	$redirectParams = nutritionist_calendar_redirect_params();

	if (in_array($eventAction, ['create_event', 'update_event'], true)) {
		$eventId = (int)($_POST['id'] ?? 0);
		$eventType = (string)($_POST['event_type'] ?? '');
		$title = trim((string)($_POST['title'] ?? ''));
		$eventDate = trim((string)($_POST['event_date'] ?? ''));
		$eventTime = trim((string)($_POST['event_time'] ?? ''));
		$location = trim((string)($_POST['location'] ?? ''));
		$notes = trim((string)($_POST['notes'] ?? ''));
		$barangayId = $user['barangay_id'] ?? null;

		if (!in_array($eventType, ['meeting', 'oplan_timbang'], true) || $title === '' || $eventDate === '') {
			admin_redirect('/nutritionist/dashboard.php', $redirectParams + ['notice' => 'Event type, title, and date are required.', 'type' => 'error']);
		}

		$eventTimeValue = $eventTime !== '' ? $eventTime : null;
		$locationValue = $location !== '' ? $location : null;
		$notesValue = $notes !== '' ? $notes : null;

		if ($eventAction === 'create_event') {
			$ok = admin_execute(
				'INSERT INTO nutritionist_events (event_type, title, event_date, event_time, location, barangay_id, notes, nutritionist_id)
				 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
				'sssssisi',
				[$eventType, $title, $eventDate, $eventTimeValue, $locationValue, $barangayId, $notesValue, (int)$user['id']]
			);

			admin_redirect('/nutritionist/dashboard.php', $redirectParams + ($ok ? ['notice' => 'Event added to the calendar.'] : ['notice' => 'Event could not be added.', 'type' => 'error']));
		}

		if ($eventAction === 'update_event' && $eventId > 0) {
			$ownerClause = ($user['role'] ?? '') === 'admin' ? '1=1' : 'nutritionist_id = ' . (int)$user['id'];

			$ok = admin_execute(
				"UPDATE nutritionist_events
				 SET event_type = ?, title = ?, event_date = ?, event_time = ?, location = ?, notes = ?
				 WHERE id = ? AND {$ownerClause}",
				'ssssssi',
				[$eventType, $title, $eventDate, $eventTimeValue, $locationValue, $notesValue, $eventId]
			);

			admin_redirect('/nutritionist/dashboard.php', $redirectParams + ($ok ? ['notice' => 'Event updated.'] : ['notice' => 'Event could not be updated.', 'type' => 'error']));
		}
	}

	if ($eventAction === 'delete_event') {
		$eventId = (int)($_POST['id'] ?? 0);
		$ownerClause = ($user['role'] ?? '') === 'admin' ? '1=1' : 'nutritionist_id = ' . (int)$user['id'];

		if ($eventId > 0) {
			$ok = admin_execute("DELETE FROM nutritionist_events WHERE id = ? AND {$ownerClause}", 'i', [$eventId]);
			admin_redirect('/nutritionist/dashboard.php', $redirectParams + ($ok ? ['notice' => 'Event removed.'] : ['notice' => 'Event could not be removed.', 'type' => 'error']));
		}
	}
}

$range = (string)($_GET['range'] ?? 'Today');

$childrenParams = [];
$childrenScope = nutritionist_scope_fragment($user, 'c.barangay_id', $childrenParams);
$children = admin_fetch_all(
	"SELECT
		c.id,
		c.child_code,
		c.first_name,
		c.last_name,
		c.birthdate,
		c.sex,
		c.barangay_id,
		bg.name AS barangay,
		c.address,
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

$measurementsParams = [];
$measurementsScope = nutritionist_scope_fragment($user, 'c.barangay_id', $measurementsParams);
$measurements = admin_fetch_all(
	"SELECT
		m.id,
		m.measurement_date,
		m.height_cm,
		m.weight_kg,
		m.waz,
		m.haz,
		m.whz,
		m.nutritional_status,
		m.wfa_status,
		m.hfa_status,
		m.wfh_status,
		m.source_type,
		c.first_name,
		c.last_name,
		c.child_code,
		c.birthdate,
		bg.name AS barangay,
		p.name AS parent_name
	 FROM measurements m
	 INNER JOIN children c ON c.id = m.child_id
	 INNER JOIN parents p ON p.id = c.parent_id
	 LEFT JOIN barangays bg ON bg.id = c.barangay_id
	 WHERE {$measurementsScope}
	 ORDER BY m.measurement_date DESC, m.id DESC
	 LIMIT 60",
	str_repeat('i', count($measurementsParams)),
	$measurementsParams
);

$appointmentParams = [];
$appointmentClause = ($user['role'] ?? '') === 'admin' ? '1=1' : 'a.nutritionist_id = ?';

if (($user['role'] ?? '') !== 'admin') {
	$appointmentParams[] = (int)$user['id'];
}

$appointments = admin_fetch_all(
	"SELECT
		a.id,
		a.scheduled_at,
		a.status,
		a.notes,
		c.first_name,
		c.last_name,
		c.child_code,
		p.name AS parent_name
	 FROM appointments a
	 INNER JOIN children c ON c.id = a.child_id
	 INNER JOIN parents p ON p.id = a.parent_id
	 WHERE {$appointmentClause}
	 ORDER BY a.scheduled_at ASC, a.id ASC",
	str_repeat('s', count($appointmentParams)),
	$appointmentParams
);

$monthParam = (string)($_GET['month'] ?? '');
$calendarDate = DateTimeImmutable::createFromFormat('Y-m-d', $monthParam . '-01') ?: null;

if ($calendarDate === false || $calendarDate === null || $calendarDate->format('Y-m') !== $monthParam) {
	$calendarDate = $today->modify('first day of this month');
	$monthParam = $calendarDate->format('Y-m');
} else {
	$calendarDate = $calendarDate->modify('first day of this month');
}

$prevMonthLink = app_url('/nutritionist/dashboard.php?' . http_build_query(['range' => $range, 'month' => $calendarDate->modify('-1 month')->format('Y-m')]));
$nextMonthLink = app_url('/nutritionist/dashboard.php?' . http_build_query(['range' => $range, 'month' => $calendarDate->modify('+1 month')->format('Y-m')]));
$monthStart = $calendarDate->format('Y-m-d');
$monthEnd = $calendarDate->modify('last day of this month')->format('Y-m-d');

$eventsParams = [$monthStart, $monthEnd];
$eventsScope = nutritionist_scope_fragment($user, 'ne.barangay_id', $eventsParams);
$monthEvents = admin_fetch_all(
	"SELECT ne.id, ne.event_type, ne.title, ne.event_date, ne.event_time, ne.location, ne.notes, ne.nutritionist_id, bg.name AS barangay
	 FROM nutritionist_events ne
	 LEFT JOIN barangays bg ON bg.id = ne.barangay_id
	 WHERE ne.event_date BETWEEN ? AND ? AND {$eventsScope}
	 ORDER BY ne.event_date ASC, ne.event_time ASC, ne.id ASC",
	str_repeat('s', count($eventsParams)),
	$eventsParams
);

$editingEventId = (int)($_GET['edit_event'] ?? 0);
$editingEvent = null;

if ($editingEventId > 0) {
	foreach ($monthEvents as $eventRow) {
		if ((int)$eventRow['id'] === $editingEventId) {
			$editingEvent = $eventRow;
			break;
		}
	}
}

$parentsParams = [];
$parentsScope = nutritionist_scope_fragment($user, 'c.barangay_id', $parentsParams);
$parents = admin_fetch_all(
	"SELECT
		p.id,
		p.name,
		p.parent_type,
		p.email,
		p.phone,
		p.address,
		p.status,
		COUNT(DISTINCT c.id) AS children_count,
		SUM(CASE WHEN lm.nutritional_status IS NOT NULL AND lm.nutritional_status NOT IN ('Normal', 'Overweight') THEN 1 ELSE 0 END) AS follow_up_count
	 FROM parents p
	 LEFT JOIN children c ON c.parent_id = p.id AND {$parentsScope}
	 LEFT JOIN measurements lm ON lm.id = (
		SELECT m2.id
		FROM measurements m2
		WHERE m2.child_id = c.id
		ORDER BY m2.measurement_date DESC, m2.id DESC
		LIMIT 1
	 )
	 GROUP BY p.id, p.name, p.parent_type, p.email, p.phone, p.address, p.status
	 ORDER BY p.name ASC",
	str_repeat('s', count($parentsParams)),
	$parentsParams
);

$statusCounts = [
	'Normal' => 0,
	'Underweight' => 0,
	'Severely Underweight' => 0,
	'Stunted' => 0,
	'Wasted' => 0,
	'Overweight' => 0,
	'Pending' => 0,
];

foreach ($children as $child) {
	$status = (string)($child['nutritional_status'] ?? 'Pending');

	if (!isset($statusCounts[$status])) {
		$statusCounts[$status] = 0;
	}

	$statusCounts[$status]++;
}

$atRiskChildren = array_values(array_filter(
	$children,
	static fn(array $child): bool => !in_array((string)($child['nutritional_status'] ?? 'Pending'), ['Normal', 'Overweight'], true),
));

$upcomingAppointments = array_values(array_filter(
	$appointments,
	static function (array $appointment) use ($today): bool {
		$scheduled = new DateTimeImmutable((string)$appointment['scheduled_at']);

		return $scheduled >= $today;
	}
));

$selectedChildren = array_values(array_filter(
	$children,
	static function (array $child) use ($range, $today): bool {
		$measurementDate = (string)($child['measurement_date'] ?? '');

		if ($measurementDate === '') {
			return $range === 'Today';
		}

		$date = new DateTimeImmutable($measurementDate);

		return match ($range) {
			'Weekly' => $date >= $today->modify('-7 days'),
			'Monthly' => $date >= $today->modify('-30 days'),
			'Yearly' => $date >= $today->modify('-365 days'),
			default => $date->format('Y-m-d') === $today->format('Y-m-d'),
		};
	}
));

$chartMonths = [];
$chartData = [
	'Normal' => [],
	'Severely Underweight' => [],
	'Underweight' => [],
	'Stunted' => [],
];

for ($offset = 7; $offset >= 0; $offset--) {
	$month = $today->modify('-' . $offset . ' months');
	$key = $month->format('Y-m');
	$chartMonths[] = $month->format('M');
	$chartData['Normal'][$key] = 0;
	$chartData['Severely Underweight'][$key] = 0;
	$chartData['Underweight'][$key] = 0;
	$chartData['Stunted'][$key] = 0;
}

foreach ($measurements as $measurement) {
	$status = (string)($measurement['nutritional_status'] ?? '');

	if (!isset($chartData[$status])) {
		continue;
	}

	$key = (new DateTimeImmutable((string)$measurement['measurement_date']))->format('Y-m');

	if (array_key_exists($key, $chartData[$status])) {
		$chartData[$status][$key]++;
	}
}

$seriesColors = [
	'Normal' => '#1A8F68',
	'Severely Underweight' => '#E03131',
	'Underweight' => '#E67E22',
	'Stunted' => '#7048E8',
];

$chartXs = [56, 110, 164, 218, 272, 326, 380, 420];
$toY = static fn(int $value): float => 152 - (min($value, 20) / 20) * 136;
$makePoints = static function (array $values) use ($chartXs, $toY): string {
	$points = [];

	foreach (array_values($values) as $index => $value) {
		$points[] = $chartXs[$index] . ',' . $toY((int)$value);
	}

	return implode(' ', $points);
};

$actions = implode(' ', [
	'<a class="admin-btn-secondary" href="' . nutritionist_e(app_url('/nutritionist/children.php')) . '">Open children</a>',
	'<a class="admin-btn" href="' . nutritionist_e(app_url('/nutritionist/reports.php')) . '">View reports</a>',
]);

nutritionist_layout_start('Nutritionist Dashboard', 'WHO monitoring, growth analysis, and appointment oversight.', 'dashboard', $actions);
?>
<section class="nutritionist-stat-grid">
	<article class="nutritionist-stat-card is-featured">
		<div class="nutritionist-stat-label">Children Monitored</div>
		<div class="admin-stat-value"><?php echo count($children); ?></div>
		<div class="admin-stat-note">Registered children in scope</div>
	</article>
	<article class="nutritionist-stat-card">
		<div class="nutritionist-stat-label">At-Risk Cases</div>
		<div class="admin-stat-value"><?php echo count($atRiskChildren); ?></div>
		<div class="admin-stat-note">Children needing follow-up</div>
	</article>
	<article class="nutritionist-stat-card">
		<div class="nutritionist-stat-label">Parents Linked</div>
		<div class="admin-stat-value"><?php echo count($parents); ?></div>
		<div class="admin-stat-note">Active guardians and caregivers</div>
	</article>
	<article class="nutritionist-stat-card">
		<div class="nutritionist-stat-label">Appointments</div>
		<div class="admin-stat-value"><?php echo count($upcomingAppointments); ?></div>
		<div class="admin-stat-note">Upcoming scheduled visits</div>
	</article>
</section>

<section class="nutritionist-panel-grid">
	<article class="nutritionist-panel">
		<div class="nutritionist-toolbar" style="margin-bottom:12px;">
			<div>
				<h2 class="admin-section-title" style="margin-bottom:2px;">Patient Overview</h2>
				<p class="admin-section-subtitle">Malnutrition trends over time by classification</p>
			</div>
			<div class="nutritionist-legend">
				<?php foreach ($seriesColors as $label => $color): ?>
					<div class="nutritionist-legend-item"><span class="nutritionist-dot" style="background:<?php echo nutritionist_e($color); ?>"></span><?php echo nutritionist_e($label); ?></div>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="nutritionist-dashboard-chart">
			<svg width="100%" viewBox="0 0 460 195" style="display:block;overflow:visible;">
				<?php foreach ([16, 50, 84, 118, 152] as $y): ?>
					<line x1="44" y1="<?php echo $y; ?>" x2="430" y2="<?php echo $y; ?>" stroke="var(--admin-border)" stroke-width="0.5"></line>
				<?php endforeach; ?>
				<?php foreach ([["100", 19], ["80", 53], ["60", 87], ["40", 121], ["20", 155]] as [$label, $y]): ?>
					<text x="38" y="<?php echo $y; ?>" font-size="9" fill="var(--admin-muted)" text-anchor="end"><?php echo nutritionist_e($label); ?></text>
				<?php endforeach; ?>
				<?php foreach ($chartData as $label => $series): ?>
					<g>
						<polyline points="<?php echo nutritionist_e($makePoints($series)); ?>" fill="none" stroke="<?php echo nutritionist_e($seriesColors[$label]); ?>" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"></polyline>
						<?php foreach (array_values($series) as $index => $value): ?>
							<circle cx="<?php echo $chartXs[$index]; ?>" cy="<?php echo $toY((int)$value); ?>" r="3.5" fill="<?php echo nutritionist_e($seriesColors[$label]); ?>" stroke="#fff" stroke-width="1.5"></circle>
						<?php endforeach; ?>
					</g>
				<?php endforeach; ?>
				<?php foreach ($chartMonths as $index => $monthLabel): ?>
					<text x="<?php echo $chartXs[$index]; ?>" y="178" font-size="9" fill="var(--admin-muted)" text-anchor="middle"><?php echo nutritionist_e($monthLabel); ?></text>
				<?php endforeach; ?>
			</svg>
		</div>
	</article>

	<article class="nutritionist-panel">
		<div class="nutritionist-toolbar" style="margin-bottom:12px;">
			<h2 class="admin-section-title" style="margin:0;">Calendar</h2>
			<div style="display:flex;align-items:center;gap:6px;">
				<a class="admin-btn-secondary" href="<?php echo nutritionist_e($prevMonthLink); ?>" style="min-height:24px;padding:0 8px;line-height:24px;">&lt;</a>
				<span style="font-size:12px;font-weight:600;color:var(--admin-text);min-width:110px;text-align:center;"><?php echo nutritionist_e($calendarDate->format('F Y')); ?></span>
				<a class="admin-btn-secondary" href="<?php echo nutritionist_e($nextMonthLink); ?>" style="min-height:24px;padding:0 8px;line-height:24px;">&gt;</a>
			</div>
		</div>

		<?php
		$firstWeekday = (int)$calendarDate->format('w');
		$daysInMonth = (int)$calendarDate->format('t');
		$calendarCells = array_merge(array_fill(0, $firstWeekday, null), range(1, $daysInMonth));
		while (count($calendarCells) % 7 !== 0) {
			$calendarCells[] = null;
		}

		$calendarEntries = [];
		foreach ($appointments as $appointment) {
			$date = new DateTimeImmutable((string)$appointment['scheduled_at']);

			if ($date->format('Y-m') === $calendarDate->format('Y-m')) {
				$day = (int)$date->format('j');
				$calendarEntries[$day][] = [
					'type' => 'appointment',
					'color' => nutritionist_calendar_color('appointment'),
					'title' => $appointment['first_name'] . ' ' . $appointment['last_name'] . ' (Appointment)',
					'time' => $date->format('g:i A'),
				];
			}
		}

		foreach ($monthEvents as $eventRow) {
			$date = new DateTimeImmutable((string)$eventRow['event_date']);
			$day = (int)$date->format('j');
			$calendarEntries[$day][] = [
				'type' => $eventRow['event_type'],
				'color' => nutritionist_calendar_color((string)$eventRow['event_type']),
				'title' => $eventRow['title'],
				'time' => $eventRow['event_time'] !== null ? (new DateTimeImmutable((string)$eventRow['event_date'] . ' ' . $eventRow['event_time']))->format('g:i A') : null,
				'id' => (int)$eventRow['id'],
			];
		}
		?>
		<div class="nutritionist-calendar-grid" style="margin-bottom:4px;">
			<?php foreach (['S', 'M', 'T', 'W', 'T', 'F', 'S'] as $dayLabel): ?>
				<div style="text-align:center;font-size:10px;color:var(--admin-muted);padding:3px 0;font-weight:500;"><?php echo nutritionist_e($dayLabel); ?></div>
			<?php endforeach; ?>
		</div>
		<div class="nutritionist-calendar-grid">
			<?php foreach ($calendarCells as $day): ?>
				<?php if ($day === null): ?>
					<div></div>
				<?php else: ?>
					<?php
					$isToday = $day === (int)$today->format('j') && $calendarDate->format('Y-m') === $today->format('Y-m');
					$dayEntries = $calendarEntries[$day] ?? [];
					$dayTitleParts = array_map(static fn(array $entry): string => ($entry['time'] !== null ? $entry['time'] . ' ' : '') . $entry['title'], $dayEntries);
					?>
					<a class="nutritionist-calendar-day<?php echo $isToday ? ' is-today' : ''; ?>" href="#calendar-day-detail" data-calendar-day="<?php echo (int)$day; ?>" title="<?php echo nutritionist_e(implode(' · ', $dayTitleParts)); ?>" style="text-decoration:none;cursor:<?php echo $dayEntries === [] ? 'default' : 'pointer'; ?>;">
						<div style="line-height:1;font-size:11px;font-weight:<?php echo $isToday ? 600 : 400; ?>;width:<?php echo $isToday ? 22 : 0; ?>px;height:<?php echo $isToday ? 22 : 0; ?>px;border-radius:<?php echo $isToday ? '50%' : '0'; ?>;display:flex;align-items:center;justify-content:center;<?php echo $isToday ? 'background:var(--admin-primary);color:#fff;' : 'color:var(--admin-text);'; ?>">
							<?php echo (int)$day; ?>
						</div>
						<?php if ($dayEntries !== []): ?>
							<div class="nutritionist-calendar-dots">
								<?php foreach (array_slice($dayEntries, 0, 3) as $event): ?>
									<div class="nutritionist-dot" style="background:<?php echo $isToday ? 'rgba(255,255,255,.8)' : nutritionist_e($event['color']); ?>;"></div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</a>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap;padding-top:10px;border-top:1px solid var(--admin-border);">
			<div class="nutritionist-legend-item"><span class="nutritionist-dot" style="background:<?php echo nutritionist_e(nutritionist_calendar_color('appointment')); ?>"></span>Appointment</div>
			<div class="nutritionist-legend-item"><span class="nutritionist-dot" style="background:<?php echo nutritionist_e(nutritionist_calendar_color('meeting')); ?>"></span>Meeting</div>
			<div class="nutritionist-legend-item"><span class="nutritionist-dot" style="background:<?php echo nutritionist_e(nutritionist_calendar_color('oplan_timbang')); ?>"></span>Oplan Timbang</div>
		</div>

		<div id="calendar-day-detail" style="margin-top:14px;padding-top:12px;border-top:1px solid var(--admin-border);">
			<div class="admin-mini" style="margin-bottom:8px;">Hover a day to preview it, or manage meetings and Oplan Timbang entries below. Appointments are still edited from the Appointments page.</div>

			<?php if ($monthEvents === []): ?>
				<div class="admin-stat-note">No meetings or Oplan Timbang sessions scheduled for <?php echo nutritionist_e($calendarDate->format('F Y')); ?> yet.</div>
			<?php else: ?>
				<div style="display:flex;flex-direction:column;gap:6px;">
					<?php foreach ($monthEvents as $eventRow): ?>
						<?php $eventDate = new DateTimeImmutable((string)$eventRow['event_date']); ?>
						<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 10px;border:1px solid var(--admin-border);border-radius:8px;">
							<div style="display:flex;align-items:center;gap:8px;min-width:0;">
								<span class="nutritionist-dot" style="background:<?php echo nutritionist_e(nutritionist_calendar_color((string)$eventRow['event_type'])); ?>;flex-shrink:0;"></span>
								<div style="min-width:0;">
									<div style="font-weight:600;font-size:12px;color:var(--admin-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo nutritionist_e((string)$eventRow['title']); ?></div>
									<div class="admin-mini"><?php echo nutritionist_e(nutritionist_calendar_label((string)$eventRow['event_type'])); ?> · <?php echo nutritionist_e($eventDate->format('d M Y')); ?><?php echo $eventRow['event_time'] !== null ? ' · ' . nutritionist_e((new DateTimeImmutable('1970-01-01 ' . $eventRow['event_time']))->format('g:i A')) : ''; ?><?php echo $eventRow['location'] !== null && $eventRow['location'] !== '' ? ' · ' . nutritionist_e((string)$eventRow['location']) : ''; ?></div>
								</div>
							</div>
							<div class="admin-actions">
								<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/dashboard.php?' . http_build_query(['range' => $range, 'month' => $monthParam, 'edit_event' => (int)$eventRow['id']]) . '#calendar-event-form')); ?>">Edit</a>
								<form method="post" action="<?php echo nutritionist_e(app_url('/nutritionist/dashboard.php')); ?>" onsubmit="return confirm('Remove this event?');">
									<input type="hidden" name="action" value="delete_event">
									<input type="hidden" name="id" value="<?php echo (int)$eventRow['id']; ?>">
									<input type="hidden" name="range" value="<?php echo nutritionist_e($range); ?>">
									<input type="hidden" name="month" value="<?php echo nutritionist_e($monthParam); ?>">
									<button class="admin-btn-danger" type="submit">Delete</button>
								</form>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo nutritionist_e(app_url('/nutritionist/dashboard.php')); ?>" id="calendar-event-form" class="nutritionist-form-grid" style="margin-top:14px;">
				<input type="hidden" name="action" value="<?php echo $editingEvent !== null ? 'update_event' : 'create_event'; ?>">
				<input type="hidden" name="id" value="<?php echo $editingEvent !== null ? (int)$editingEvent['id'] : ''; ?>">
				<input type="hidden" name="range" value="<?php echo nutritionist_e($range); ?>">
				<input type="hidden" name="month" value="<?php echo nutritionist_e($monthParam); ?>">
				<label class="admin-field">
					<span>Event type</span>
					<select name="event_type" required>
						<option value="meeting" <?php echo ($editingEvent['event_type'] ?? '') === 'meeting' ? 'selected' : ''; ?>>Meeting</option>
						<option value="oplan_timbang" <?php echo ($editingEvent['event_type'] ?? '') === 'oplan_timbang' ? 'selected' : ''; ?>>Oplan Timbang</option>
					</select>
				</label>
				<label class="admin-field">
					<span>Title</span>
					<input name="title" required value="<?php echo nutritionist_e((string)($editingEvent['title'] ?? '')); ?>" placeholder="e.g. Barangay nutrition meeting">
				</label>
				<label class="admin-field">
					<span>Date</span>
					<input type="date" name="event_date" required value="<?php echo nutritionist_e((string)($editingEvent['event_date'] ?? '')); ?>">
				</label>
				<label class="admin-field">
					<span>Time (optional)</span>
					<input type="time" name="event_time" value="<?php echo nutritionist_e((string)substr((string)($editingEvent['event_time'] ?? ''), 0, 5)); ?>">
				</label>
				<label class="admin-field">
					<span>Location (optional)</span>
					<input name="location" value="<?php echo nutritionist_e((string)($editingEvent['location'] ?? '')); ?>" placeholder="e.g. Barangay hall">
				</label>
				<label class="admin-field">
					<span>Notes (optional)</span>
					<input name="notes" value="<?php echo nutritionist_e((string)($editingEvent['notes'] ?? '')); ?>">
				</label>
				<div class="admin-field" style="align-content:end;grid-column:1 / -1;display:flex;gap:8px;">
					<button class="admin-btn" type="submit"><?php echo $editingEvent !== null ? 'Update event' : 'Add to calendar'; ?></button>
					<?php if ($editingEvent !== null): ?>
						<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/dashboard.php?' . http_build_query(['range' => $range, 'month' => $monthParam]))); ?>">Cancel edit</a>
					<?php endif; ?>
				</div>
			</form>
		</div>
	</article>
</section>

<section class="nutritionist-panel-grid is-balanced">
	<article class="nutritionist-panel">
		<div class="admin-section-title" style="margin-bottom:12px;">Priority Alerts</div>
		<?php if ($atRiskChildren === []): ?>
			<div class="admin-stat-note">No priority alerts right now.</div>
		<?php endif; ?>
		<?php foreach (array_slice($atRiskChildren, 0, 3) as $child): ?>
			<?php
			$status = (string)($child['nutritional_status'] ?? 'Pending');
			$statusClass = in_array($status, ['Severely Underweight', 'Stunted', 'Wasted'], true) ? 'is-danger' : 'is-warn';
			$bgColor = $statusClass === 'is-danger' ? '#fff4f4' : '#fff8ea';
			?>
			<div class="admin-list-item" style="padding:10px 12px;border-bottom:1px solid var(--admin-border);margin-bottom:8px;background:<?php echo $bgColor; ?>;border-radius:8px;">
				<div>
					<div style="font-size:12px;font-weight:600;color:var(--admin-text);"><?php echo nutritionist_e($child['first_name'] . ' ' . $child['last_name']); ?></div>
					<div class="admin-mini" style="margin-top:3px;">WAZ <?php echo nutritionist_e((string)($child['waz'] ?? 'n/a')); ?> · HAZ <?php echo nutritionist_e((string)($child['haz'] ?? 'n/a')); ?> · WHZ <?php echo nutritionist_e((string)($child['whz'] ?? 'n/a')); ?></div>
					<div class="admin-mini" style="margin-top:2px;">WFA <?php echo nutritionist_e((string)($child['wfa_status'] ?? '—')); ?> · HFA <?php echo nutritionist_e((string)($child['hfa_status'] ?? '—')); ?> · WFH <?php echo nutritionist_e((string)($child['wfh_status'] ?? '—')); ?></div>
				</div>
				<div class="admin-pill <?php echo $statusClass; ?>"><?php echo nutritionist_e($status); ?></div>
			</div>
		<?php endforeach; ?>
	</article>

	<article class="nutritionist-panel">
		<div class="admin-section-title" style="margin-bottom:12px;">Nutritional Status</div>
		<?php foreach (['Normal', 'Underweight', 'Severely Underweight', 'Stunted', 'Wasted', 'Overweight'] as $status): ?>
			<?php
			$count = (int)($statusCounts[$status] ?? 0);
			$pct = count($children) > 0 ? (int)round(($count / count($children)) * 100) : 0;
			$barColor = match ($status) {
				'Normal' => 'var(--admin-primary)',
				'Underweight' => 'var(--admin-accent)',
				'Severely Underweight' => 'var(--admin-danger)',
				'Stunted' => '#7048E8',
				'Wasted' => '#4a9fd5',
				default => '#b08900',
			};
			?>
			<div style="margin-bottom:10px;">
				<div style="display:flex;justify-content:space-between;margin-bottom:4px;align-items:center;">
					<span class="admin-pill <?php echo $status === 'Normal' ? 'is-success' : ($status === 'Overweight' ? 'is-warn' : 'is-danger'); ?>"><?php echo nutritionist_e($status); ?></span>
					<span class="admin-mini"><?php echo $count; ?> (<?php echo $pct; ?>%)</span>
				</div>
				<div style="height:7px;border-radius:999px;background:var(--admin-bg);overflow:hidden;">
					<div style="width:<?php echo max($pct, $count > 0 ? 3 : 0); ?>%;height:100%;border-radius:999px;background:<?php echo nutritionist_e($barColor); ?>;"></div>
				</div>
			</div>
		<?php endforeach; ?>
	</article>
</section>

<section class="nutritionist-panel">
	<div class="nutritionist-table-head" style="margin-bottom:12px;">
		<div>
			<h2 class="admin-section-title" style="margin-bottom:2px;">Patient Overview</h2>
			<p class="admin-section-subtitle">Registered children and their current growth status</p>
		</div>
		<div class="nutritionist-chip-row">
			<?php foreach (['Today', 'Weekly', 'Monthly', 'Yearly'] as $filter): ?>
				<a class="nutritionist-chip<?php echo $range === $filter ? ' is-active' : ''; ?>" href="<?php echo nutritionist_e(app_url('/nutritionist/dashboard.php?range=' . urlencode($filter))); ?>"><?php echo nutritionist_e($filter); ?></a>
			<?php endforeach; ?>
		</div>
	</div>

	<div class="nutritionist-table-wrap">
		<table class="nutritionist-table">
			<thead>
				<tr>
					<th></th>
					<th>No</th>
					<th>Name</th>
					<th>Age</th>
					<th>Date of Birth</th>
					<th>Status</th>
					<th>WFA</th>
					<th>HFA</th>
					<th>WFH</th>
					<th>Barangay</th>
					<th>Parent</th>
					<th>Action</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($selectedChildren as $index => $child): ?>
					<?php
					$birthdate = new DateTimeImmutable((string)$child['birthdate']);
					$ageMonths = $birthdate->diff($today)->y * 12 + $birthdate->diff($today)->m;
					$status = (string)($child['nutritional_status'] ?? 'Pending');
					$pillClass = $status === 'Normal' ? 'is-success' : ($status === 'Overweight' ? 'is-warn' : 'is-danger');
					?>
					<tr>
						<td><input type="checkbox"></td>
						<td style="color:var(--admin-muted);font-size:11px;"><?php echo str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT); ?></td>
						<td>
							<div style="display:flex;align-items:center;gap:8px;">
								<div class="admin-pill <?php echo $pillClass; ?>" style="min-width:30px;justify-content:center;border-radius:50%;padding:0.35rem 0.5rem;"><?php echo nutritionist_e(substr($child['first_name'], 0, 1) . substr($child['last_name'], 0, 1)); ?></div>
								<div>
									<div style="font-weight:600;font-size:12px;color:var(--admin-text);"><?php echo nutritionist_e($child['first_name'] . ' ' . $child['last_name']); ?></div>
									<div style="font-size:10px;color:var(--admin-muted);margin-top:1px;"><?php echo nutritionist_e((string)$child['sex']); ?></div>
								</div>
							</div>
						</td>
						<td style="color:var(--admin-muted);font-size:12px;"><?php echo (int)$ageMonths; ?> mo</td>
						<td style="color:var(--admin-muted);font-size:12px;white-space:nowrap;"><?php echo nutritionist_e($birthdate->format('d M Y')); ?></td>
						<td><span class="admin-pill <?php echo $pillClass; ?>"><?php echo nutritionist_e($status); ?></span></td>
						<td style="color:var(--admin-muted);font-size:12px;"><?php echo nutritionist_e((string)($child['wfa_status'] ?? '—')); ?></td>
						<td style="color:var(--admin-muted);font-size:12px;"><?php echo nutritionist_e((string)($child['hfa_status'] ?? '—')); ?></td>
						<td style="color:var(--admin-muted);font-size:12px;"><?php echo nutritionist_e((string)($child['wfh_status'] ?? '—')); ?></td>
						<td style="color:var(--admin-muted);font-size:12px;"><?php echo nutritionist_e((string)($child['barangay'] ?? '')); ?></td>
						<td style="color:var(--admin-muted);font-size:12px;"><?php echo nutritionist_e((string)$child['parent_name']); ?></td>
						<td>
							<div class="admin-actions">
								<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/children.php?view=' . (int)$child['id'])); ?>">View</a>
								<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/children.php?edit=' . (int)$child['id'])); ?>">Edit</a>
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
<?php
nutritionist_layout_end();


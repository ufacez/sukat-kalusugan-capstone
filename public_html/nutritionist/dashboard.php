<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/who_calculator.php';

$user = nutritionist_require_access();
$today = new DateTimeImmutable('today');

// Calendar events are read-only on this dashboard. Adding, editing, and
// deleting meetings/Oplan Timbang entries happens on the Settings page
// (see nutritionist/settings.php, "Manage Calendar" section).

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

$prevMonthLink = app_url('/nutritionist/dashboard.php?' . http_build_query(['month' => $calendarDate->modify('-1 month')->format('Y-m')]));
$nextMonthLink = app_url('/nutritionist/dashboard.php?' . http_build_query(['month' => $calendarDate->modify('+1 month')->format('Y-m')]));
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

$atRiskChildren = [];

$upcomingAppointments = array_values(array_filter(
	$appointments,
	static function (array $appointment) use ($today): bool {
		$scheduled = new DateTimeImmutable((string)$appointment['scheduled_at']);

		return $scheduled >= $today;
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
	'<a class="admin-btn" href="' . nutritionist_e(app_url('/nutritionist/eopt_reports.php')) . '">EOPT reports</a>',
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
				<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/settings.php') . '#calendar-management'); ?>" style="min-height:24px;padding:0 10px;line-height:24px;">Manage</a>
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
			<div class="admin-mini" style="margin-bottom:8px;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
				<span id="day-detail-heading"></span>
				<a href="#calendar-day-detail" id="day-detail-reset" class="admin-mini" style="display:none;font-weight:600;">Show all</a>
			</div>

			<?php if ($monthEvents === []): ?>
				<div class="admin-stat-note">No meetings or Oplan Timbang sessions scheduled for <?php echo nutritionist_e($calendarDate->format('F Y')); ?> yet.</div>
			<?php else: ?>
				<div style="display:flex;flex-direction:column;gap:6px;" id="day-detail-list">
					<?php foreach ($monthEvents as $eventRow): ?>
						<?php $eventDate = new DateTimeImmutable((string)$eventRow['event_date']); ?>
						<div data-event-day="<?php echo (int)$eventDate->format('j'); ?>" style="display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid var(--admin-border);border-radius:8px;">
							<span class="nutritionist-dot" style="background:<?php echo nutritionist_e(nutritionist_calendar_color((string)$eventRow['event_type'])); ?>;flex-shrink:0;"></span>
							<div style="min-width:0;">
								<div style="font-weight:600;font-size:12px;color:var(--admin-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo nutritionist_e((string)$eventRow['title']); ?></div>
								<div class="admin-mini"><?php echo nutritionist_e(nutritionist_calendar_label((string)$eventRow['event_type'])); ?> · <?php echo nutritionist_e($eventDate->format('d M Y')); ?><?php echo $eventRow['event_time'] !== null ? ' · ' . nutritionist_e((new DateTimeImmutable('1970-01-01 ' . $eventRow['event_time']))->format('g:i A')) : ''; ?><?php echo $eventRow['location'] !== null && $eventRow['location'] !== '' ? ' · ' . nutritionist_e((string)$eventRow['location']) : ''; ?></div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<div style="margin-top:14px;">
				<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/settings.php') . '#calendar-management'); ?>">Manage calendar events →</a>
			</div>
		</div>
	</article>
</section>

<section class="nutritionist-panel">
	<div class="admin-section-title" style="margin-bottom:12px;">Measurement Status Breakdown</div>
	<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;">
		<?php
		$breakdown = [
			['label' => 'Overweight', 'abbr' => 'OW', 'color' => '#b08900', 'key' => 'OW'],
			['label' => 'Underweight', 'abbr' => 'UW', 'color' => 'var(--admin-accent)', 'key' => 'UW'],
			['label' => 'Severely Underweight', 'abbr' => 'SUW', 'color' => 'var(--admin-danger)', 'key' => 'SUW'],
			['label' => 'Stunted', 'abbr' => 'St', 'color' => '#7048E8', 'key' => 'St'],
			['label' => 'Severely Stunted', 'abbr' => 'SSt', 'color' => '#5f3dc4', 'key' => 'SSt'],
			['label' => 'Obese', 'abbr' => 'Ob', 'color' => '#e8590c', 'key' => 'Ob'],
			['label' => 'Moderately Wasted', 'abbr' => 'MW', 'color' => '#4a9fd5', 'key' => 'MW'],
			['label' => 'Severely Wasted', 'abbr' => 'SW', 'color' => '#c92a2a', 'key' => 'SW'],
		];

		$wfaCounts = array_fill_keys(['SUW', 'UW', 'Normal', 'OW'], 0);
		$hfaCounts = array_fill_keys(['SSt', 'St', 'Normal', 'T'], 0);
		$wfhCounts = array_fill_keys(['SW', 'MW', 'Normal', 'OW', 'Ob'], 0);

		foreach ($children as $child) {
			$wfa = (string)($child['wfa_status'] ?? '');
			$hfa = (string)($child['hfa_status'] ?? '');
			$wfh = (string)($child['wfh_status'] ?? '');
			if (isset($wfaCounts[$wfa])) $wfaCounts[$wfa]++;
			if (isset($hfaCounts[$hfa])) $hfaCounts[$hfa]++;
			if (isset($wfhCounts[$wfh])) $wfhCounts[$wfh]++;
		}

		$total = max(count($children), 1);

		$abbrMap = [
			'OW' => ['count' => $wfaCounts['OW'] + $wfhCounts['OW'], 'source' => 'WFA+WFH'],
			'UW' => ['count' => $wfaCounts['UW'], 'source' => 'WFA'],
			'SUW' => ['count' => $wfaCounts['SUW'], 'source' => 'WFA'],
			'St' => ['count' => $hfaCounts['St'], 'source' => 'HFA'],
			'SSt' => ['count' => $hfaCounts['SSt'], 'source' => 'HFA'],
			'Ob' => ['count' => $wfhCounts['Ob'], 'source' => 'WFH'],
			'MW' => ['count' => $wfhCounts['MW'], 'source' => 'WFH'],
			'SW' => ['count' => $wfhCounts['SW'], 'source' => 'WFH'],
		];
		?>
		<?php foreach ($breakdown as $item): ?>
			<?php
			$info = $abbrMap[$item['key']] ?? ['count' => 0, 'source' => ''];
			$count = (int)$info['count'];
			$pct = (int)round(($count / $total) * 100);
			?>
			<div style="border:1px solid var(--admin-border);border-radius:10px;padding:12px 14px;background:var(--admin-surface);">
				<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
					<span style="font-size:12px;font-weight:700;color:var(--admin-text);"><?php echo nutritionist_e($item['label']); ?></span>
					<span style="font-size:11px;font-weight:700;padding:2px 7px;border-radius:999px;background:<?php echo nutritionist_e($item['color']); ?>;color:#fff;"><?php echo nutritionist_e($item['abbr']); ?></span>
				</div>
				<div style="display:flex;align-items:baseline;gap:6px;">
					<span style="font-size:20px;font-weight:700;color:var(--admin-text);"><?php echo $count; ?></span>
					<span style="font-size:11px;color:var(--admin-muted);">(<?php echo $pct; ?>%)</span>
				</div>
				<div style="height:5px;border-radius:999px;background:var(--admin-bg);overflow:hidden;margin-top:6px;">
					<div style="width:<?php echo max($pct, $count > 0 ? 3 : 0); ?>%;height:100%;border-radius:999px;background:<?php echo nutritionist_e($item['color']); ?>;"></div>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
</section>

<script>
(function () {
	var dayCells = document.querySelectorAll('.nutritionist-calendar-day[data-calendar-day]');
	var eventRows = document.querySelectorAll('#day-detail-list [data-event-day]');
	var heading = document.getElementById('day-detail-heading');
	var resetLink = document.getElementById('day-detail-reset');
	var detailSection = document.getElementById('calendar-day-detail');
	var defaultHeading = heading ? heading.textContent : '';

	function showDay(day) {
		var any = false;

		eventRows.forEach(function (row) {
			var match = row.getAttribute('data-event-day') === String(day);
			row.style.display = match ? '' : 'none';
			if (match) { any = true; }
		});

		dayCells.forEach(function (cell) {
			cell.classList.toggle('is-selected-day', cell.getAttribute('data-calendar-day') === String(day));
		});

		if (heading) {
			heading.textContent = any
				? 'Events on ' + day + ':'
				: 'No meetings or Oplan Timbang sessions on the ' + day + (day == 1 || day == 21 || day == 31 ? 'st' : (day == 2 || day == 22 ? 'nd' : (day == 3 || day == 23 ? 'rd' : 'th')));
		}

		if (resetLink) { resetLink.style.display = ''; }
	}

	function showAll() {
		eventRows.forEach(function (row) { row.style.display = ''; });
		dayCells.forEach(function (cell) { cell.classList.remove('is-selected-day'); });
		if (heading) { heading.textContent = defaultHeading; }
		if (resetLink) { resetLink.style.display = 'none'; }
	}

	dayCells.forEach(function (cell) {
		cell.addEventListener('click', function (event) {
			event.preventDefault();
			var day = cell.getAttribute('data-calendar-day');
			showDay(day);
			if (detailSection) {
				detailSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
		});
	});

	if (resetLink) {
		resetLink.addEventListener('click', function (event) {
			event.preventDefault();
			showAll();
		});
	}

})();
</script>
<?php
nutritionist_layout_end();
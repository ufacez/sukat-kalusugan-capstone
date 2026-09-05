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
		COALESCE(lm.nutritional_status, CASE
			WHEN lm.waz < -3 THEN 'Severely Underweight'
			WHEN lm.haz < -3 THEN 'Severely Stunted'
			WHEN lm.whz < -3 THEN 'Severely Wasted'
			WHEN lm.waz < -2 THEN 'Moderately Underweight'
			WHEN lm.haz < -2 THEN 'Moderately Stunted'
			WHEN lm.whz < -2 THEN 'Moderately Wasted'
			WHEN lm.whz > 3 THEN 'Obese'
			WHEN lm.whz > 2 THEN 'Overweight'
			ELSE 'Normal'
		END) AS nutritional_status,
		COALESCE(lm.wfa_status, CASE
			WHEN lm.waz < -3 THEN 'SUW'
			WHEN lm.waz < -2 THEN 'MUW'
			WHEN lm.waz > 2 THEN 'Refer to WFL/H'
			ELSE 'Normal'
		END) AS wfa_status,
		COALESCE(lm.hfa_status, CASE
			WHEN lm.haz < -3 THEN 'SSt'
			WHEN lm.haz < -2 THEN 'MSt'
			WHEN lm.haz > 2 THEN 'Tall'
			ELSE 'Normal'
		END) AS hfa_status,
		COALESCE(lm.wfh_status, CASE
			WHEN lm.whz < -3 THEN 'SW'
			WHEN lm.whz < -2 THEN 'MW'
			WHEN lm.whz > 3 THEN 'Ob'
			WHEN lm.whz > 2 THEN 'OW'
			ELSE 'Normal'
		END) AS wfh_status
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

// Latest measurement per child — used for the dashboard summary tiles,
// the recent-measurements table, and the WHO chart's monthly trend.
// We pick the most recent measurement for each child, then order newest-first
// so the "latest children" appear at the top of any list.
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
		COALESCE(m.nutritional_status, CASE
			WHEN m.waz < -3 THEN 'Severely Underweight'
			WHEN m.haz < -3 THEN 'Severely Stunted'
			WHEN m.whz < -3 THEN 'Severely Wasted'
			WHEN m.waz < -2 THEN 'Moderately Underweight'
			WHEN m.haz < -2 THEN 'Moderately Stunted'
			WHEN m.whz < -2 THEN 'Moderately Wasted'
			WHEN m.whz > 3 THEN 'Obese'
			WHEN m.whz > 2 THEN 'Overweight'
			ELSE 'Normal'
		END) AS nutritional_status,
		COALESCE(m.wfa_status, CASE
			WHEN m.waz < -3 THEN 'SUW'
			WHEN m.waz < -2 THEN 'MUW'
			WHEN m.waz > 2 THEN 'Refer to WFL/H'
			ELSE 'Normal'
		END) AS wfa_status,
		COALESCE(m.hfa_status, CASE
			WHEN m.haz < -3 THEN 'SSt'
			WHEN m.haz < -2 THEN 'MSt'
			WHEN m.haz > 2 THEN 'Tall'
			ELSE 'Normal'
		END) AS hfa_status,
		COALESCE(m.wfh_status, CASE
			WHEN m.whz < -3 THEN 'SW'
			WHEN m.whz < -2 THEN 'MW'
			WHEN m.whz > 3 THEN 'Ob'
			WHEN m.whz > 2 THEN 'OW'
			ELSE 'Normal'
		END) AS wfh_status,
		m.source_type,
		c.id AS child_id,
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
	 INNER JOIN (
		SELECT child_id, MAX(id) AS latest_id
		FROM measurements
		GROUP BY child_id
	 ) latest ON latest.latest_id = m.id
	 WHERE {$measurementsScope}
	 ORDER BY m.measurement_date DESC, m.id DESC",
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
		SUM(CASE WHEN lm.nutritional_status IS NOT NULL AND lm.nutritional_status NOT IN ('Normal') THEN 1 ELSE 0 END) AS follow_up_count
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

// At-risk / severe statistics from real child data
$atRiskChildren = [];
$severeCount = 0;
$moderateCount = 0;
$normalCount = 0;

foreach ($children as $child) {
	$status = strtolower(trim((string)($child['nutritional_status'] ?? '')));
	if ($status === 'normal' || $status === '') {
		$normalCount++;
	} else {
		$atRiskChildren[] = $child;
		$severeLabels = ['severely underweight', 'severely stunted', 'severely wasted', 'suw', 'sst', 'sw'];
		if (in_array($status, $severeLabels, true)) {
			$severeCount++;
		} else {
			$moderateCount++;
		}
	}
}

// Per-axis classification counts for the WHO chart sidebar.
// Each axis has its own independent Normal / Moderate / Severe tally built
// from the WFA / HFA / WFH-or-WFL status columns on the latest measurement.
function buildAxisCounts(array $measurements, string $statusField): array {
	// WFA adds a 4th "Refer" bucket (DOH eOPT Plus rule: WAZ > +2 is
	// read off the WFL/H axis). HFA / WFL/H stay at the three standard
	// Normal / Moderate / Severe buckets.
	$counts = ['Normal' => 0, 'Moderate' => 0, 'Severe' => 0, 'Refer' => 0];
	foreach ($measurements as $m) {
		$status = strtolower(trim((string)($m[$statusField] ?? '')));
		if (str_contains($status, 'refer')) {
			$counts['Refer']++;
		} elseif ($status === 'normal' || $status === 'n' || $status === 'tall' || $status === 't' || $status === '') {
			$counts['Normal']++;
		} elseif (str_contains($status, 'severe') || $status === 'sst' || $status === 'sw' || $status === 'suw' || $status === 'ob') {
			$counts['Severe']++;
		} else {
			$counts['Moderate']++;
		}
	}
	return $counts;
}

$axisCounts = [
	'wfa'  => buildAxisCounts($measurements, 'wfa_status'),
	'hfa'  => buildAxisCounts($measurements, 'hfa_status'),
	'wflh' => buildAxisCounts($measurements, 'wfh_status'),
];

// Per-pill counts (N / MUW / MSt / MW / OW / SUW / SSt / SW / Ob / REF) per axis.
// Used by the "Latest Status" sidebar so it can show every individual
// classification bucket with its own count and percentage. WFA now
// includes REF (Refer to WFL/H) for any child whose WAZ z-score lands
// above +2 — per the DOH eOPT Plus rule, that reading is read off the
// WFL/H axis instead.
function buildAxisPillCounts(array $measurements, string $statusField, string $axis): array {
	$counts = ['N' => 0, 'MUW' => 0, 'MSt' => 0, 'MW' => 0, 'OW' => 0, 'SUW' => 0, 'SSt' => 0, 'SW' => 0, 'Ob' => 0, 'REF' => 0];
	foreach ($measurements as $m) {
		$c = classifyAxisStatus($axis, (string)($m[$statusField] ?? ''));
		$key = $c['label'];
		if (!array_key_exists($key, $counts)) continue;
		$counts[$key]++;
	}
	return $counts;
}

$axisPillCounts = [
	'wfa'  => buildAxisPillCounts($measurements, 'wfa_status', 'wfa'),
	'hfa'  => buildAxisPillCounts($measurements, 'hfa_status', 'hfa'),
	'wflh' => buildAxisPillCounts($measurements, 'wfh_status', 'wflh'),
];

$axisTotalWfa  = max(1, count($measurements));
$axisTotalHfa  = max(1, count($measurements));
$axisTotalWflh = max(1, count($measurements));

$axisLabels = [
	'wfa'  => ['Normal' => 'Normal WFA', 'Moderate' => 'Moderate WFA', 'Severe' => 'Severe WFA'],
	'hfa'  => ['Normal' => 'Normal HFA', 'Moderate' => 'Moderate HFA', 'Severe' => 'Severe HFA'],
	'wflh' => ['Normal' => 'Normal WFH', 'Moderate' => 'Moderate WFH', 'Severe' => 'Severe WFH'],
];

/**
 * Classify a single axis status into a {label, level, axis, full} tuple.
 * level is 'normal' | 'moderate' | 'severe' | 'refer'.
 * label   is the short pill code (N, MUW, MSt, MW, OW, SUW, SSt, SW, Ob, REF).
 * full    is the human-readable WHO description.
 *
 * WFA no longer classifies overweight/obese: any WAZ > +2 is the "Refer
 * to WFL/H" pill (DOH eOPT Plus rule), and the operator reads the actual
 * Overweight / Obese status from the WFL/H axis instead. Tall is also
 * its own label on the HFA axis, not folded into N.
 */
function classifyAxisStatus(string $axis, string $raw): array {
	$s = strtolower(trim($raw));
	if ($s === '' || $s === 'normal' || $s === 'n') {
		return ['label' => 'N', 'full' => 'Normal', 'level' => 'normal', 'axis' => $axis];
	}
	// Severe bucket
	if ($s === 'suw') return ['label' => 'SUW', 'full' => 'Severely Underweight', 'level' => 'severe', 'axis' => 'wfa'];
	if ($s === 'sst') return ['label' => 'SSt', 'full' => 'Severely Stunted',       'level' => 'severe', 'axis' => 'hfa'];
	if ($s === 'sw')  return ['label' => 'SW',  'full' => 'Severely Wasted',        'level' => 'severe', 'axis' => 'wflh'];
	if ($s === 'ob')  return ['label' => 'Ob',  'full' => 'Obese',                  'level' => 'severe', 'axis' => 'wflh'];
	// Moderate bucket
	if ($s === 'muw') return ['label' => 'MUW', 'full' => 'Moderately Underweight', 'level' => 'moderate', 'axis' => 'wfa'];
	if ($s === 'mst') return ['label' => 'MSt', 'full' => 'Moderately Stunted',     'level' => 'moderate', 'axis' => 'hfa'];
	if ($s === 'mw')  return ['label' => 'MW',  'full' => 'Moderately Wasted',      'level' => 'moderate', 'axis' => 'wflh'];
	// OW is now WFL/H only -- WFA shows the "Refer" pill instead.
	if ($s === 'ow')  return ['label' => 'OW',  'full' => 'Overweight',             'level' => 'moderate', 'axis' => 'wflh'];
	// Tall is reported as a separate HFA pill so the operator can spot
	// children whose height-for-age is above +2 SD without folding them
	// back into the "Normal" bucket.
	if ($s === 'tall' || $s === 't') {
		return ['label' => 'Tall', 'full' => 'Tall', 'level' => 'normal', 'axis' => 'hfa'];
	}
	// WFA overflow: WAZ > +2 redirects the operator to the WFL/H axis.
	if (str_contains($s, 'refer')) {
		return ['label' => 'REF', 'full' => 'Use WFL/H column', 'level' => 'refer', 'axis' => 'wfa'];
	}
	// Generic fallbacks (long-form status strings from the schema)
	if (str_contains($s, 'severe')) {
		if (str_contains($s, 'underweight')) return ['label' => 'SUW', 'full' => 'Severely Underweight', 'level' => 'severe', 'axis' => 'wfa'];
		if (str_contains($s, 'stunted'))      return ['label' => 'SSt', 'full' => 'Severely Stunted',       'level' => 'severe', 'axis' => 'hfa'];
		if (str_contains($s, 'wasted'))       return ['label' => 'SW',  'full' => 'Severely Wasted',        'level' => 'severe', 'axis' => 'wflh'];
		if (str_contains($s, 'obese'))        return ['label' => 'Ob',  'full' => 'Obese',                  'level' => 'severe', 'axis' => 'wflh'];
		return ['label' => 'S', 'full' => 'Severe', 'level' => 'severe', 'axis' => $axis];
	}
	if (str_contains($s, 'moderate')) {
		if (str_contains($s, 'underweight')) return ['label' => 'MUW', 'full' => 'Moderately Underweight', 'level' => 'moderate', 'axis' => 'wfa'];
		if (str_contains($s, 'stunted'))      return ['label' => 'MSt', 'full' => 'Moderately Stunted',     'level' => 'moderate', 'axis' => 'hfa'];
		if (str_contains($s, 'wasted'))       return ['label' => 'MW',  'full' => 'Moderately Wasted',      'level' => 'moderate', 'axis' => 'wflh'];
		return ['label' => 'M', 'full' => 'Moderate', 'level' => 'moderate', 'axis' => $axis];
	}
	// Long-form Overweight / Obese -- WFL/H axis only now.
	if (str_contains($s, 'overweight')) return ['label' => 'OW', 'full' => 'Overweight', 'level' => 'moderate', 'axis' => 'wflh'];
	if (str_contains($s, 'obese'))      return ['label' => 'Ob', 'full' => 'Obese',       'level' => 'severe',   'axis' => 'wflh'];
	return ['label' => '?', 'full' => 'Unknown', 'level' => 'normal', 'axis' => $axis];
}

/**
 * Return a list of combined status pills for a child/measurement row.
 * Combines axes (e.g. "Ob + OW" or "SUW + SSt + MW") so users see every flag.
 */
function combinedStatusPills(?string $wfa, ?string $hfa, ?string $wfh): array {
	$pills = [];
	foreach (['wfa' => $wfa, 'hfa' => $hfa, 'wflh' => $wfh] as $axis => $value) {
		$c = classifyAxisStatus($axis, (string)$value);
		if ($c['level'] === 'normal') continue; // exclude normal per request
		$pills[] = $c;
	}
	return $pills;
}

$upcomingAppointments = array_values(array_filter(
	$appointments,
	static function (array $appointment) use ($today): bool {
		$scheduled = new DateTimeImmutable((string)$appointment['scheduled_at']);
		return $scheduled >= $today;
	}
));

// WHO chart data — real computed from measurements for three axes
$chartMonths = [];
for ($offset = 11; $offset >= 0; $offset--) {
	$month = $today->modify('-' . abs($offset) . ' months');
	$chartMonths[] = $month->format('M');
}

function buildChartData(array $measurements, string $statusField): array {
	$months = [];
	$today = new DateTimeImmutable('today');
	for ($offset = 11; $offset >= 0; $offset--) {
		$month = $today->modify('-' . abs($offset) . ' months');
		$months[$month->format('Y-m')] = 0;
	}
	// WFA gains a fourth "Refer" series (DOH eOPT Plus overflow: WAZ > +2).
	// HFA / WFL/H keep the three normal / moderate / severe series; the
	// "Refer" series just stays at zero on those tabs.
	$monthly = [
		'Normal' => $months,
		'Moderate' => array_map(fn($v) => 0, $months),
		'Severe' => array_map(fn($v) => 0, $months),
		'Refer' => array_map(fn($v) => 0, $months),
	];
	foreach ($measurements as $m) {
		$key = (new DateTimeImmutable((string)($m['measurement_date'])))->format('Y-m');
		if (!array_key_exists($key, $monthly['Normal'])) continue;
		$status = strtolower(trim((string)($m[$statusField] ?? '')));
		if (str_contains($status, 'refer')) {
			$monthly['Refer'][$key] = ($monthly['Refer'][$key] ?? 0) + 1;
		} elseif ($status === 'normal' || $status === 'n' || $status === '') {
			$monthly['Normal'][$key] = ($monthly['Normal'][$key] ?? 0) + 1;
		} elseif ($status === 'tall' || $status === 't') {
			// For HFA: tall counts as Normal (it's the desirable high end of HFA).
			$monthly['Normal'][$key] = ($monthly['Normal'][$key] ?? 0) + 1;
		} elseif (str_contains($status, 'severe') || $status === 'sst' || $status === 'sw' || $status === 'suw' || $status === 'ob') {
			$monthly['Severe'][$key] = ($monthly['Severe'][$key] ?? 0) + 1;
		} else {
			// Moderate / MUW / MSt / MW / OW / etc.
			$monthly['Moderate'][$key] = ($monthly['Moderate'][$key] ?? 0) + 1;
		}
	}
	return $monthly;
}

$wfaData = buildChartData($measurements, 'wfa_status');
$hfaData = buildChartData($measurements, 'hfa_status');
$wflhData = buildChartData($measurements, 'wfh_status');

$chartSeriesColors = [
	'Normal' => 'var(--admin-primary)',
	'Moderate' => 'var(--admin-accent)',
	'Severe' => 'var(--admin-danger)',
	// Gray for the WFA "Refer to WFL/H" overflow series.
	'Refer' => 'var(--admin-muted)',
];

$chartXs = [56, 110, 164, 218, 272, 326, 380, 420]; // kept for any external legacy references
$toY = static fn(int $value): float => 152 - (min($value, 20) / 20) * 136;

// Calendar setup
$firstWeekday = (int)$calendarDate->format('w');
$daysInMonth = (int)$calendarDate->format('t');
$calendarCells = array_merge(array_fill(0, $firstWeekday, null), range(1, $daysInMonth));
while (count($calendarCells) % 7 !== 0) {
	$calendarCells[] = null;
}

$calendarEntries = [];
$overdueByDay = [];
foreach ($appointments as $appointment) {
	try {
		$date = new DateTimeImmutable((string)$appointment['scheduled_at']);
	} catch (Exception) {
		continue;
	}
	if ($date->format('Y-m') !== $calendarDate->format('Y-m')) {
		continue;
	}
	$day = (int)$date->format('j');
	$status = (string)($appointment['status'] ?? 'pending');
	$isOverdue = in_array($status, ['pending', 'confirmed'], true)
		&& $date->format('Y-m-d') < $today->format('Y-m-d');
	$effectiveStatus = $isOverdue ? 'overdue' : $status;
	if ($isOverdue) {
		$overdueByDay[$day] = ($overdueByDay[$day] ?? 0) + 1;
	}
	$calendarEntries[$day][] = [
		'type' => 'appointment',
		'color' => nutritionist_calendar_color('appointment'),
		'title' => $appointment['first_name'] . ' ' . $appointment['last_name'] . ' (Appointment)',
		'time' => $date->format('g:i A'),
		'id' => (int)$appointment['id'],
		'location' => '',
		'status' => $effectiveStatus,
	];
}

foreach ($monthEvents as $eventRow) {
	try {
		$date = new DateTimeImmutable((string)$eventRow['event_date']);
	} catch (Exception) {
		continue;
	}
	$day = (int)$date->format('j');
	$eventType = (string)$eventRow['event_type'];
	$eventTime = $eventRow['event_time'] !== null && $eventRow['event_time'] !== ''
		? (new DateTimeImmutable((string)$eventRow['event_date'] . ' ' . (string)$eventRow['event_time']))->format('g:i A')
		: null;
	$calendarEntries[$day][] = [
		'type' => $eventType,
		'color' => nutritionist_calendar_color($eventType),
		'title' => (string)$eventRow['title'],
		'time' => $eventTime,
		'id' => (int)$eventRow['id'],
		'location' => (string)($eventRow['location'] ?? ''),
		'status' => null,
	];
}

$todayStr = $today->format('Y-m-d');
$todayInCurrentMonth = ((int)$today->format('Y') === (int)$calendarDate->format('Y')
	&& (int)$today->format('n') === (int)$calendarDate->format('n'));
$defaultCalendarDay = null;
if ($todayInCurrentMonth && isset($calendarEntries[(int)$today->format('j')])) {
	$defaultCalendarDay = $todayStr;
} else {
	foreach ($calendarEntries as $dayKey => $entries) {
		if ($entries !== []) {
			$defaultCalendarDay = $calendarDate->setDate(
				(int)$calendarDate->format('Y'),
				(int)$calendarDate->format('n'),
				$dayKey
			)->format('Y-m-d');
			break;
		}
	}
}
$recentPage = max(1, (int)($_GET['rmp'] ?? 1));
$recentPageSize = 3;
$recentTotal = count($measurements);
$recentTotalPages = max(1, (int)ceil($recentTotal / $recentPageSize));
if ($recentPage > $recentTotalPages) $recentPage = $recentTotalPages;
$recentOffset = ($recentPage - 1) * $recentPageSize;
$recentMeasurements = array_slice($measurements, $recentOffset, $recentPageSize);

// AI Insights — driven by the latest WHO growth-indicator snapshot per child.
// Speaks in the language of WFA / HFA / WFH z-score classifications and
// describes the chart data without prescribing interventions.
$aiBullets = [];
$totalMeasured = count($measurements);
$totalChildren = count($children);

$suw = (int)($axisPillCounts['wfa']['SUW'] ?? 0);
$sst = (int)($axisPillCounts['hfa']['SSt'] ?? 0);
$sw  = (int)($axisPillCounts['wflh']['SW']  ?? 0);
$ob  = (int)($axisPillCounts['wflh']['Ob']  ?? 0);
$muw = (int)($axisPillCounts['wfa']['MUW'] ?? 0);
$mst = (int)($axisPillCounts['hfa']['MSt'] ?? 0);
$mw  = (int)($axisPillCounts['wflh']['MW']  ?? 0);
$owWflh = (int)($axisPillCounts['wflh']['OW'] ?? 0);
$refWfa = (int)($axisPillCounts['wfa']['REF'] ?? 0);
$nWfa  = (int)($axisCounts['wfa']['Normal']  ?? 0);
$nHfa  = (int)($axisCounts['hfa']['Normal']  ?? 0);
$nWflh = (int)($axisCounts['wflh']['Normal'] ?? 0);

$totalSevere   = $suw + $sst + $sw + $ob;
$totalModerate = $muw + $mst + $mw + $owWflh;
$totalNormal   = $nWfa + $nHfa + $nWflh;
$totalRefer    = $refWfa;
$totalAxes     = $totalNormal + $totalModerate + $totalSevere + $totalRefer;
$pctSevere   = $totalAxes > 0 ? round(($totalSevere / $totalAxes) * 100, 1) : 0;
$pctModerate = $totalAxes > 0 ? round(($totalModerate / $totalAxes) * 100, 1) : 0;
$pctNormal   = $totalAxes > 0 ? round(($totalNormal / $totalAxes) * 100, 1) : 0;
$pctRefer    = $totalAxes > 0 ? round(($totalRefer / $totalAxes) * 100, 1) : 0;

// Insight 1 — Severe classification distribution (always shown).
if ($totalSevere > 0) {
	$parts = [];
	if ($suw) $parts[] = "$suw SUW";
	if ($sst) $parts[] = "$sst SSt";
	if ($sw)  $parts[] = "$sw SW";
	if ($ob)  $parts[] = "$ob Ob";
	$aiBullets[] = '<strong>Severe burden.</strong> ' . implode(', ', $parts) . ' on the latest growth snapshot — ' . $pctSevere . '% of all WFA / HFA / WFH classifications land in the severe bucket.';
} else {
	$aiBullets[] = '<strong>No severe malnutrition flagged.</strong> Latest WFA / HFA / WFH snapshot shows zero SUW, SSt, SW or Ob classifications across ' . $totalMeasured . ' measured children.';
}

// Insight 2 — Stunting trend (HFA focus).
if ($sst + $mst > 0) {
	$pctStunted = $totalChildren > 0 ? round((($sst + $mst) / $totalChildren) * 100, 1) : 0;
	$aiBullets[] = '<strong>Height-for-Age (HFA).</strong> ' . ($sst + $mst) . ' children (' . $pctStunted . '% of roster) are below -2 SD on the HFA axis — broken down as ' . $sst . ' severely stunted (SSt) and ' . $mst . ' moderately stunted (MSt).';
}

// Insight 3 — Weight-for-Age. OW no longer appears here; WAZ > +2
// routes to the WFL/H axis via the "Refer to WFL/H" pill instead.
if ($muw + $suw + $refWfa > 0) {
	$wfaParts = [];
	if ($muw) $wfaParts[] = "$muw MUW";
	if ($suw) $wfaParts[] = "$suw SUW";
	if ($refWfa) $wfaParts[] = "$refWfa Refer to WFL/H";
	$aiBullets[] = '<strong>Weight-for-Age (WFA).</strong> Latest snapshot shows ' . implode(', ', $wfaParts) . ' on the WFA axis. The Refer-to-WFL/H bucket means WAZ > +2 — read the actual overweight / obese status from the WFL/H axis below.';
}

// Insight 4 — Weight-for-Height/Length. OW lives on this axis now.
if ($mw + $sw + $ob + $owWflh > 0) {
	$wfhParts = [];
	if ($mw) $wfhParts[] = "$mw MW";
	if ($sw) $wfhParts[] = "$sw SW";
	if ($owWflh) $wfhParts[] = "$owWflh OW";
	if ($ob) $wfhParts[] = "$ob Ob";
	$aiBullets[] = '<strong>Weight-for-Height/Length (WFH).</strong> ' . implode(', ', $wfhParts) . ' visible on the WFH axis in the chart — represents the moderate + severe range of the WFH series.';
}

// Insight 5 — Overall distribution summary (always shown once we have data).
if ($totalAxes > 0) {
	$referClause = $pctRefer > 0 ? ', ' . $pctRefer . '% Refer to WFL/H' : '';
	$aiBullets[] = '<strong>Distribution snapshot.</strong> Across all three axes the latest readings are ' . $pctNormal . '% Normal, ' . $pctModerate . '% Moderate, and ' . $pctSevere . '% Severe' . $referClause . '. Switch the WFA / HFA / WFH tabs above to see each axis in detail.';
}

// Insight 6 — Coverage warning when measurements are missing.
if ($totalChildren > 0 && $totalMeasured < $totalChildren) {
	$gap = $totalChildren - $totalMeasured;
	$aiBullets[] = '<strong>Coverage gap.</strong> ' . $gap . ' child' . ($gap === 1 ? ' is' : 'ren are') . ' missing a current WHO snapshot — the chart only reflects ' . $totalMeasured . ' of ' . $totalChildren . ' registered children.';
}

if (count($aiBullets) === 0) {
	$aiBullets[] = '<strong>No signals yet.</strong> Add measurements to start seeing growth-indicator insights.';
}

$actions = implode(' ', [
	'<a class="admin-btn" style="background:var(--admin-valid);border-color:var(--admin-valid);" href="' . nutritionist_e(app_url('/nutritionist/risk_map.php')) . '">' . admin_action_icon('map') . ' Risk Map</a>',
	'<a class="admin-btn-secondary" href="' . nutritionist_e(app_url('/nutritionist/eopt_reports.php')) . '">' . admin_action_icon('document') . ' EOPT Reports</a>',
]);

nutritionist_layout_start('Nutritionist Dashboard', 'WHO monitoring, growth analysis, and appointment oversight.', 'dashboard', $actions);
?>

<div class="nutritionist-dashboard">

<section class="dashboard-stat-grid" aria-label="Key statistics">
	<article class="dashboard-stat-card">
		<div class="dashboard-stat-row">
			<div class="dashboard-stat-icon-wrap is-primary">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/></svg>
			</div>
			<div>
				<div class="dashboard-stat-label">Children Monitored</div>
				<div class="dashboard-stat-value"><?php echo count($children); ?></div>
				<div class="dashboard-stat-meta"><span class="highlight">Registered in your scope</span></div>
			</div>
		</div>
	</article>

	<article class="dashboard-stat-card">
		<div class="dashboard-stat-row">
			<div class="dashboard-stat-icon-wrap is-accent">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
			</div>
			<div>
				<div class="dashboard-stat-label">Children Needing Attention</div>
				<div class="dashboard-stat-value"><?php echo count($atRiskChildren); ?></div>
				<div class="dashboard-stat-meta is-danger"><span class="highlight"><?php echo $severeCount; ?> severe cases</span></div>
			</div>
		</div>
	</article>

	<article class="dashboard-stat-card">
		<div class="dashboard-stat-row">
			<div class="dashboard-stat-icon-wrap is-valid">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
			</div>
			<div>
				<div class="dashboard-stat-label">Measurements</div>
				<div class="dashboard-stat-value"><?php echo count($measurements); ?></div>
				<div class="dashboard-stat-meta">This month <a href="<?php echo nutritionist_e(app_url('/nutritionist/measurements.php')); ?>">View all →</a></div>
			</div>
		</div>
	</article>

	<article class="dashboard-stat-card">
		<div class="dashboard-stat-row">
			<div class="dashboard-stat-icon-wrap is-primary">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
			</div>
			<div>
				<div class="dashboard-stat-label">Appointments</div>
				<div class="dashboard-stat-value"><?php echo count($upcomingAppointments); ?></div>
				<div class="dashboard-stat-meta">Upcoming <a href="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php')); ?>">View all →</a></div>
			</div>
		</div>
	</article>
</section>

<section class="nutritionist-panel-grid">
	<article class="nutritionist-panel">
		<div class="nutritionist-toolbar" style="margin-bottom:12px;">
			<div>
				<h2 class="admin-section-title" style="margin-bottom:2px;">WHO Growth Indicators Overview</h2>
				<p class="admin-section-subtitle" style="margin-top:4px;">Latest growth-indicator classification by month</p>
			</div>
			<div class="dashboard-chart-tabs" id="nutritionist-chart-tabs" role="tablist" aria-label="WHO indicator">
				<button type="button" class="dashboard-chart-tab is-active" id="tab-wfa" data-axis="wfa" role="tab" aria-selected="true">WFA</button>
				<button type="button" class="dashboard-chart-tab" id="tab-hfa" data-axis="hfa" role="tab" aria-selected="false">HFA</button>
				<button type="button" class="dashboard-chart-tab" id="tab-wflh" data-axis="wflh" role="tab" aria-selected="false">WFH / WFL</button>
			</div>
		</div>

		<div class="dashboard-who-grid">
			<!-- Wave Chart -->
			<div class="audit-chart-wrap">
				<div class="audit-chart-header">
					<div class="audit-chart-title" id="nutritionist-chart-title">Weight-for-Age</div>
					<div class="audit-chart-legend">
						<div class="audit-legend-item"><span class="audit-legend-dot is-primary"></span>Normal</div>
						<div class="audit-legend-item"><span class="audit-legend-dot is-accent"></span>Moderate</div>
						<div class="audit-legend-item"><span class="audit-legend-dot is-danger"></span>Severe</div>
						<div class="audit-legend-item" data-legend-row="Refer"><span class="audit-legend-dot is-gray"></span>Refer to WFL/H</div>
					</div>
					<div class="audit-chart-badge">
						<span style="width:6px;height:6px;border-radius:50%;background:var(--admin-primary);animation:pulse-dot 2s infinite;"></span>Live
					</div>
				</div>
				<div class="audit-chart-body">
					<canvas id="nutritionist-wave-chart" class="audit-chart-canvas"></canvas>
					<div class="audit-chart-y-axis" id="nutritionist-chart-y-axis"></div>
					<div class="audit-chart-tooltip" id="nutritionist-chart-tooltip"></div>
				</div>
				<div class="audit-chart-x-axis" id="nutritionist-chart-x-axis"></div>
			</div>

			<!-- Sidebar Stats — reactive to the active WFA / HFA / WFLH tab -->
			<div class="dashboard-chart-sidebar dashboard-chart-sidebar-large" id="nutritionist-chart-sidebar">
				<div class="admin-mini" style="font-weight:800;color:var(--admin-text);font-size:0.78rem;text-transform:uppercase;letter-spacing:0.06em;" id="nutritionist-sidebar-title">Latest Status — WFA</div>
				<div class="stat-row" data-axis-row="wfa">
					<div class="stat-label"><span class="stat-dot is-primary"></span><span data-axis-label="wfa"><strong>Normal</strong> <span class="stat-code">N</span></span></div>
					<div class="stat-count" data-axis-count="wfa" data-axis-key="Normal"><?php echo (int)$axisCounts['wfa']['Normal']; ?><span class="stat-pct"><?php echo $axisTotalWfa > 0 ? round($axisCounts['wfa']['Normal'] / $axisTotalWfa * 100, 0) : 0; ?>%</span></div>
				</div>
				<div class="stat-row" data-axis-row="wfa">
					<div class="stat-label"><span class="stat-dot is-accent"></span><span data-axis-label="wfa"><strong>Moderately Underweight</strong> <span class="stat-code">MUW</span></span></div>
					<div class="stat-count" data-axis-count="wfa" data-axis-key="MUW"><?php echo (int)$axisPillCounts['wfa']['MUW']; ?><span class="stat-pct"><?php echo $axisTotalWfa > 0 ? round($axisPillCounts['wfa']['MUW'] / $axisTotalWfa * 100, 0) : 0; ?>%</span></div>
				</div>
				<div class="stat-row" data-axis-row="wfa">
					<div class="stat-label"><span class="stat-dot is-gray"></span><span data-axis-label="wfa"><strong>Use WFL/H column</strong> <span class="stat-code">REF</span></span></div>
					<div class="stat-count" data-axis-count="wfa" data-axis-key="REF"><?php echo (int)$axisPillCounts['wfa']['REF']; ?><span class="stat-pct"><?php echo $axisTotalWfa > 0 ? round($axisPillCounts['wfa']['REF'] / $axisTotalWfa * 100, 0) : 0; ?>%</span></div>
				</div>
				<div class="stat-row" data-axis-row="wfa">
					<div class="stat-label"><span class="stat-dot is-danger"></span><span data-axis-label="wfa"><strong>Severely Underweight</strong> <span class="stat-code">SUW</span></span></div>
					<div class="stat-count" data-axis-count="wfa" data-axis-key="SUW"><?php echo (int)$axisPillCounts['wfa']['SUW']; ?><span class="stat-pct"><?php echo $axisTotalWfa > 0 ? round($axisPillCounts['wfa']['SUW'] / $axisTotalWfa * 100, 0) : 0; ?>%</span></div>
				</div>

				<div class="stat-row" data-axis-row="hfa" hidden>
					<div class="stat-label"><span class="stat-dot is-primary"></span><span data-axis-label="hfa"><strong>Normal</strong> <span class="stat-code">N</span></span></div>
					<div class="stat-count" data-axis-count="hfa" data-axis-key="Normal"><?php echo (int)$axisCounts['hfa']['Normal']; ?><span class="stat-pct"><?php echo $axisTotalHfa > 0 ? round($axisCounts['hfa']['Normal'] / $axisTotalHfa * 100, 0) : 0; ?>%</span></div>
				</div>
				<div class="stat-row" data-axis-row="hfa" hidden>
					<div class="stat-label"><span class="stat-dot is-accent"></span><span data-axis-label="hfa"><strong>Moderately Stunted</strong> <span class="stat-code">MSt</span></span></div>
					<div class="stat-count" data-axis-count="hfa" data-axis-key="MSt"><?php echo (int)$axisPillCounts['hfa']['MSt']; ?><span class="stat-pct"><?php echo $axisTotalHfa > 0 ? round($axisPillCounts['hfa']['MSt'] / $axisTotalHfa * 100, 0) : 0; ?>%</span></div>
				</div>
				<div class="stat-row" data-axis-row="hfa" hidden>
					<div class="stat-label"><span class="stat-dot is-danger"></span><span data-axis-label="hfa"><strong>Severely Stunted</strong> <span class="stat-code">SSt</span></span></div>
					<div class="stat-count" data-axis-count="hfa" data-axis-key="SSt"><?php echo (int)$axisPillCounts['hfa']['SSt']; ?><span class="stat-pct"><?php echo $axisTotalHfa > 0 ? round($axisPillCounts['hfa']['SSt'] / $axisTotalHfa * 100, 0) : 0; ?>%</span></div>
				</div>
				<div class="stat-row" data-axis-row="hfa" hidden>
					<div class="stat-label"><span class="stat-dot is-primary"></span><span data-axis-label="hfa"><strong>Tall</strong> <span class="stat-code">Tall</span></span></div>
					<div class="stat-count" data-axis-count="hfa" data-axis-key="Tall"><?php
						$tallCount = 0;
						foreach ($measurements as $m) {
							if (strtolower(trim((string)($m['hfa_status'] ?? ''))) === 'tall') $tallCount++;
						}
						echo $tallCount;
					?><span class="stat-pct"><?php echo $axisTotalHfa > 0 ? round($tallCount / $axisTotalHfa * 100, 0) : 0; ?>%</span></div>
				</div>

				<div class="stat-row" data-axis-row="wflh" hidden>
					<div class="stat-label"><span class="stat-dot is-primary"></span><span data-axis-label="wflh"><strong>Normal</strong> <span class="stat-code">N</span></span></div>
					<div class="stat-count" data-axis-count="wflh" data-axis-key="Normal"><?php echo (int)$axisCounts['wflh']['Normal']; ?><span class="stat-pct"><?php echo $axisTotalWflh > 0 ? round($axisCounts['wflh']['Normal'] / $axisTotalWflh * 100, 0) : 0; ?>%</span></div>
				</div>
				<div class="stat-row" data-axis-row="wflh" hidden>
					<div class="stat-label"><span class="stat-dot is-accent"></span><span data-axis-label="wflh"><strong>Moderately Wasted</strong> <span class="stat-code">MW</span></span></div>
					<div class="stat-count" data-axis-count="wflh" data-axis-key="MW"><?php echo (int)$axisPillCounts['wflh']['MW']; ?><span class="stat-pct"><?php echo $axisTotalWflh > 0 ? round($axisPillCounts['wflh']['MW'] / $axisTotalWflh * 100, 0) : 0; ?>%</span></div>
				</div>
				<div class="stat-row" data-axis-row="wflh" hidden>
					<div class="stat-label"><span class="stat-dot is-danger"></span><span data-axis-label="wflh"><strong>Severely Wasted</strong> <span class="stat-code">SW</span></span></div>
					<div class="stat-count" data-axis-count="wflh" data-axis-key="SW"><?php echo (int)$axisPillCounts['wflh']['SW']; ?><span class="stat-pct"><?php echo $axisTotalWflh > 0 ? round($axisPillCounts['wflh']['SW'] / $axisTotalWflh * 100, 0) : 0; ?>%</span></div>
				</div>
				<div class="stat-row" data-axis-row="wflh" hidden>
					<div class="stat-label"><span class="stat-dot is-accent"></span><span data-axis-label="wflh"><strong>Overweight</strong> <span class="stat-code">OW</span></span></div>
					<div class="stat-count" data-axis-count="wflh" data-axis-key="OW"><?php echo (int)$axisPillCounts['wflh']['OW']; ?><span class="stat-pct"><?php echo $axisTotalWflh > 0 ? round($axisPillCounts['wflh']['OW'] / $axisTotalWflh * 100, 0) : 0; ?>%</span></div>
				</div>
				<div class="stat-row" data-axis-row="wflh" hidden>
					<div class="stat-label"><span class="stat-dot is-danger"></span><span data-axis-label="wflh"><strong>Obese</strong> <span class="stat-code">Ob</span></span></div>
					<div class="stat-count" data-axis-count="wflh" data-axis-key="Ob"><?php echo (int)$axisPillCounts['wflh']['Ob']; ?><span class="stat-pct"><?php echo $axisTotalWflh > 0 ? round($axisPillCounts['wflh']['Ob'] / $axisTotalWflh * 100, 0) : 0; ?>%</span></div>
				</div>

				<div class="nutritionist-ai-insights-block dashboard-ai-compact" id="ai-insights">
					<div class="dashboard-ai-heading">
						<span class="dashboard-ai-icon" aria-hidden="true"><?php echo admin_action_icon('lightbulb'); ?></span>
						<span>AI Quick insights</span>
					</div>
					<ul class="nutritionist-ai-bullets">
						<?php foreach (array_slice($aiBullets, 0, 2) as $bullet): ?>
						<li class="nutritionist-ai-bullet"><?php echo $bullet; ?></li>
						<?php endforeach; ?>
					</ul>
					<a class="dashboard-ai-link" href="#ai-insights">View AI insights →</a>
				</div>
			</div>
		</div>
	</article>

	<article class="nutritionist-panel">
		<div class="nutritionist-toolbar" style="margin-bottom:12px;">
			<h2 class="admin-section-title" style="margin:0;">Calendar</h2>
			<div style="display:flex;align-items:center;gap:6px;">
				<a class="admin-btn-secondary" href="<?php echo nutritionist_e($prevMonthLink); ?>" style="min-height:28px;padding:0 8px;line-height:28px;">‹</a>
				<span style="font-size:12px;font-weight:600;color:var(--admin-text);min-width:110px;text-align:center;"><?php echo nutritionist_e($calendarDate->format('F Y')); ?></span>
				<a class="admin-btn-secondary" href="<?php echo nutritionist_e($nextMonthLink); ?>" style="min-height:28px;padding:0 8px;line-height:28px;">›</a>
			</div>
		</div>

		<?php
		$todayStr = $today->format('Y-m-d');
		$initIsoDay = $defaultCalendarDay ?? $calendarDate->format('Y-m-d');
		$initDayNum = (int)(new DateTimeImmutable($initIsoDay))->format('j');
		$initEntries = $calendarEntries[$initDayNum] ?? [];
		$appointmentShown = false;
		$initEntries = array_values(array_filter($initEntries, static function (array $entry) use (&$appointmentShown): bool {
			if (($entry['type'] ?? '') !== 'appointment') return true;
			if ($appointmentShown) return false;
			$appointmentShown = true;
			return true;
		}));
		$initEntries = array_slice($initEntries, 0, 3);
		$initLabelDate = new DateTimeImmutable($initIsoDay);
		$initLabel = $initLabelDate->format('l, F j, Y');
		$isInitToday = $initIsoDay === $todayStr;
		?>

		<div class="sk-cal-wrap" data-sk-calendar data-sk-calendar-detail="dashboard-calendar-events" data-sk-calendar-default="<?php echo nutritionist_e($defaultCalendarDay ?? ''); ?>">
			<?php echo nutritionist_render_calendar_grid($calendarDate, $calendarEntries, $today); ?>
		</div>

		<div class="sk-cal-detail" id="dashboard-calendar-events" data-calendar-detail>
			<div class="sk-cal-detail-head">
				<div>
					<div class="sk-cal-detail-title" data-calendar-detail-title>
						<?php echo nutritionist_e($initLabel); ?>
						<?php if ($isInitToday): ?>
							<span class="sk-cal-detail-today">Today</span>
						<?php endif; ?>
					</div>
					<div class="sk-cal-detail-sub" data-calendar-detail-sub>
						<?php echo count($initEntries); ?> event<?php echo count($initEntries) !== 1 ? 's' : ''; ?>
					</div>
				</div>
			</div>
			<div class="sk-cal-event-list is-compact" data-calendar-detail-list>
				<?php if ($initEntries === []): ?>
					<div class="sk-cal-detail-empty" data-calendar-detail-empty>
						No events on this day. Click another date to see its schedule.
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

			<div class="sk-cal-detail-legend">
				<?php foreach (nutritionist_calendar_legend() as $legendItem): ?>
					<div class="sk-cal-detail-legend-item">
						<span class="sk-cal-detail-legend-dot" style="background:<?php echo nutritionist_e($legendItem['color']); ?>;"></span>
						<?php echo nutritionist_e($legendItem['label']); ?>
					</div>
				<?php endforeach; ?>
			</div>

			<a class="sk-cal-detail-link" data-calendar-detail-link href="<?php echo nutritionist_e(app_url('/nutritionist/appointments.php?from=' . $initIsoDay . '&to=' . $initIsoDay)); ?>">View full calendar →</a>
		</div>
	</article>
</section>

<section class="nutritionist-dashboard-bottom" style="margin-top:18px;">
	<article class="nutritionist-panel">
		<div class="nutritionist-bottom-grid">
			<!-- Children Requiring Attention -->
			<div class="nutritionist-bottom-col">
				<div class="nutritionist-toolbar" style="margin-bottom:10px;">
					<h2 class="admin-section-title" style="margin:0;">Children Requiring Attention</h2>
					<a href="<?php echo nutritionist_e(app_url('/nutritionist/children.php')); ?>" class="admin-mini" style="font-weight:600;">View all →</a>
				</div>

				<?php if (empty($atRiskChildren)): ?>
					<div class="nutritionist-empty" style="padding:14px;text-align:center;color:var(--admin-muted);font-size:0.8rem;border:1px dashed var(--admin-border);border-radius:12px;background:var(--admin-surface-alt);">No flagged cases at this time.</div>
				<?php else: ?>
					<div class="nutritionist-attention-list">
						<?php
						$sortedAtRisk = $atRiskChildren;
						// Sort by the largest absolute z-score across WAZ / HAZ / WHZ
						// (most extreme children surface first), then by the most
						// recent measurement date.
						usort($sortedAtRisk, static function ($a, $b) {
							$score = static function (array $row): float {
								$vals = [];
								foreach (['waz', 'haz', 'whz'] as $field) {
									$v = $row[$field] ?? null;
									if ($v !== null && $v !== '' && is_numeric($v)) {
										$vals[] = abs((float)$v);
									}
								}
								return empty($vals) ? 0.0 : max($vals);
							};
							$scoreDiff = $score($b) <=> $score($a);
							if ($scoreDiff !== 0) return $scoreDiff;
							$aDate = (string)($a['measurement_date'] ?? '');
							$bDate = (string)($b['measurement_date'] ?? '');
							return strcmp($bDate, $aDate);
						});
						// Keep the dashboard compact; the Children page contains the full list.
						$displayAtRisk = array_slice($sortedAtRisk, 0, 3);
						?>
						<?php foreach ($displayAtRisk as $child): ?>
						<div class="nutritionist-attention-row">
							<div class="nutritionist-attention-avatar" style="background:var(--admin-primary);color:#fff;">
								<?php echo nutritionist_e(strtoupper(mb_substr($child['first_name'] ?? 'C', 0, 1) . mb_substr($child['last_name'] ?? 'N', 0, 1))); ?>
							</div>
							<?php
								// Follow-up due indicator: derive from latest measurement
								// date. DOH eOPT Plus follow-up cadence = 14 days for
								// severe, 30 days for moderate. Shows Overdue / Due
								// today / Due in N days.
								$latestDate = !empty($child['measurement_date']) ? new DateTimeImmutable((string)$child['measurement_date']) : null;
								$hasSevere = false;
								$hasModerate = false;
								foreach (['wfa_status', 'hfa_status', 'wfh_status'] as $axisField) {
									$val = strtolower(trim((string)($child[$axisField] ?? '')));
									if (str_contains($val, 'severe') || $val === 'suw' || $val === 'sst' || $val === 'sw' || $val === 'ob') $hasSevere = true;
									elseif (str_contains($val, 'moderate') || $val === 'muw' || $val === 'mst' || $val === 'mw' || $val === 'ow') $hasModerate = true;
								}
								$cadenceDays = $hasSevere ? 14 : ($hasModerate ? 30 : null);
								$dueLabel = '';
								$dueClass = '';
								if ($latestDate !== null && $cadenceDays !== null) {
									$nextDue = $latestDate->modify('+' . $cadenceDays . ' days');
									$diffDays = (int)$today->diff($nextDue)->format('%r%a');
									if ($diffDays < 0) {
										$overdue = abs($diffDays);
										$dueLabel = 'Overdue ' . $overdue . ' day' . ($overdue === 1 ? '' : 's');
										$dueClass = 'is-overdue';
									} elseif ($diffDays === 0) {
										$dueLabel = 'Due today';
										$dueClass = 'is-due-today';
									} else {
										$dueLabel = 'Due in ' . $diffDays . ' day' . ($diffDays === 1 ? '' : 's');
										$dueClass = 'is-upcoming';
									}
								}
							?>
							<div class="nutritionist-attention-meta">
								<a href="<?php echo nutritionist_e(app_url('/nutritionist/children.php') . '?id=' . $child['id']); ?>" class="nutritionist-attention-name" style="color:var(--admin-text);"><?php echo nutritionist_e($child['first_name'] . ' ' . $child['last_name']); ?></a>
								<div class="nutritionist-attention-sub"><?php echo nutritionist_e((string)($child['child_code'] ?? $child['id'])); ?> · <?php echo nutritionist_e($child['barangay'] ?? ''); ?></div>
							</div>
							<div class="nutritionist-attention-status">
								<div class="nutritionist-attention-status-label">Status</div>
								<div class="nutritionist-attention-pills">
									<?php
									$pills = combinedStatusPills($child['wfa_status'] ?? null, $child['hfa_status'] ?? null, $child['wfh_status'] ?? null);
									if (empty($pills)) {
										echo '<span class="admin-pill is-success" style="font-size:0.65rem;padding:2px 6px;">N</span>';
									} else {
										foreach ($pills as $pill) {
											$lvl = match($pill['level']) { 'severe' => 'is-danger', 'refer' => 'is-info', default => 'is-warn' };
											echo '<span class="admin-pill ' . $lvl . '" style="font-size:0.65rem;padding:2px 6px;">' . nutritionist_e($pill['label']) . '</span>';
										}
									}
									?>
								</div>
							</div>
							<div class="nutritionist-attention-due">
								<div class="nutritionist-attention-due-label">Follow-up</div>
								<div class="nutritionist-attention-due-value <?php echo $dueClass; ?>"><?php echo nutritionist_e($dueLabel !== '' ? $dueLabel : '—'); ?></div>
							</div>
							<a href="<?php echo nutritionist_e(app_url('/nutritionist/children.php') . '?id=' . $child['id']); ?>" class="nutritionist-attention-link">Open →</a>
						</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<!-- Recent Measurements (with pagination) -->
			<div class="nutritionist-bottom-col">
				<div class="nutritionist-toolbar" style="margin-bottom:10px;">
					<h2 class="admin-section-title" style="margin:0;">Recent Measurements</h2>
					<a href="<?php echo nutritionist_e(app_url('/nutritionist/measurements.php')); ?>" class="admin-mini" style="font-weight:600;">View all →</a>
				</div>
				<table class="nutritionist-table" style="font-size:0.82rem;">
					<thead>
						<tr>
							<th>Child</th>
							<th>Date</th>
							<th>Weight</th>
							<th>Height</th>
							<th>Status</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($recentMeasurements as $m): ?>
						<tr>
							<td style="font-weight:600;color:var(--admin-text);"><?php echo nutritionist_e($m['first_name'] . ' ' . $m['last_name']); ?></td>
							<td><?php echo nutritionist_e(date('M d', strtotime($m['measurement_date']))); ?></td>
							<td><?php echo nutritionist_e(number_format((float)($m['weight_kg'] ?? 0), 1) . ' kg'); ?></td>
							<td><?php echo nutritionist_e(number_format((float)($m['height_cm'] ?? 0), 1) . ' cm'); ?></td>
							<td>
								<?php
								$rowPills = combinedStatusPills($m['wfa_status'] ?? null, $m['hfa_status'] ?? null, $m['wfh_status'] ?? null);
								if (empty($rowPills)) {
									echo '<span class="admin-pill is-success" style="font-size:0.72rem;padding:2px 6px;">N</span>';
								} else {
									echo '<div style="display:flex;gap:4px;flex-wrap:wrap;">';
									foreach ($rowPills as $pill) {
										$lvl = match($pill['level']) { 'severe' => 'is-danger', 'refer' => 'is-info', default => 'is-warn' };
										echo '<span class="admin-pill ' . $lvl . '" style="font-size:0.72rem;padding:2px 6px;">' . nutritionist_e($pill['label']) . '</span>';
									}
									echo '</div>';
								}
								?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php
				// Pagination controls — keep the query string for the current month
				$paginationBase = app_url('/nutritionist/dashboard.php');
				$baseParams = $_GET;
				unset($baseParams['rmp']);
				$buildPageLink = static function (int $p) use ($paginationBase, $baseParams): string {
					$params = $baseParams;
					if ($p > 1) $params['rmp'] = $p;
					return nutritionist_e($paginationBase . (empty($params) ? '' : '?' . http_build_query($params))) . '#recent-measurements';
				};
				?>
				<?php if ($recentTotalPages > 1): ?>
				<div class="nutritionist-pagination" style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:10px;">
					<div class="admin-mini" style="color:var(--admin-muted);">
						Showing <?php echo ($recentOffset + 1); ?>–<?php echo min($recentOffset + $recentPageSize, $recentTotal); ?> of <?php echo (int)$recentTotal; ?>
					</div>
					<div style="display:flex;gap:4px;">
						<?php if ($recentPage > 1): ?>
							<a class="admin-btn-secondary nutritionist-page-btn" href="<?php echo $buildPageLink($recentPage - 1); ?>" style="min-height:26px;padding:0 8px;line-height:24px;font-size:11px;">‹ Prev</a>
						<?php else: ?>
							<span class="admin-btn-secondary nutritionist-page-btn is-disabled" style="min-height:26px;padding:0 8px;line-height:24px;font-size:11px;opacity:.4;pointer-events:none;">‹ Prev</span>
						<?php endif; ?>
						<?php for ($p = 1; $p <= $recentTotalPages; $p++): ?>
							<?php if ($p === $recentPage): ?>
								<span class="nutritionist-page-btn is-active" style="min-width:26px;height:26px;line-height:24px;font-size:11px;padding:0 6px;border-radius:6px;background:var(--admin-primary);color:#fff;font-weight:700;display:inline-flex;align-items:center;justify-content:center;"><?php echo $p; ?></span>
							<?php else: ?>
								<a class="admin-btn-secondary nutritionist-page-btn" href="<?php echo $buildPageLink($p); ?>" style="min-width:26px;height:26px;line-height:24px;font-size:11px;padding:0 6px;"><?php echo $p; ?></a>
							<?php endif; ?>
						<?php endfor; ?>
						<?php if ($recentPage < $recentTotalPages): ?>
							<a class="admin-btn-secondary nutritionist-page-btn" href="<?php echo $buildPageLink($recentPage + 1); ?>" style="min-height:26px;padding:0 8px;line-height:24px;font-size:11px;">Next ›</a>
						<?php else: ?>
							<span class="admin-btn-secondary nutritionist-page-btn is-disabled" style="min-height:26px;padding:0 8px;line-height:24px;font-size:11px;opacity:.4;pointer-events:none;">Next ›</span>
						<?php endif; ?>
					</div>
				</div>
				<?php endif; ?>
			</div>
		</div>
	</article>
</section>

</div><!-- /.nutritionist-dashboard -->

<script>
// Chart data embedded from PHP for interactive Canvas switching.
// WFA gets a fourth "Refer" series (DOH eOPT Plus overflow: WAZ > +2
// routes the operator to the WFL/H axis). HFA / WFL/H keep three
// series; their Refer bucket stays at zero.
var chartMonths = <?php echo json_encode($chartMonths); ?>;
var chartXs = [56, 110, 164, 218, 272, 326, 380, 420];
var chartColors = {
	Normal: 'var(--admin-primary)',
	Moderate: 'var(--admin-accent)',
	Severe: 'var(--admin-danger)',
	// Gray for the WFA "Refer to WFL/H" overflow series.
	Refer: 'var(--admin-muted)'
};
var chartDataEmbedded = {
	wfa: {
		Normal: <?php echo json_encode(array_values($wfaData['Normal'])); ?>,
		Moderate: <?php echo json_encode(array_values($wfaData['Moderate'])); ?>,
		Severe: <?php echo json_encode(array_values($wfaData['Severe'])); ?>,
		Refer: <?php echo json_encode(array_values($wfaData['Refer'] ?? [])); ?>
	},
	hfa: {
		Normal: <?php echo json_encode(array_values($hfaData['Normal'])); ?>,
		Moderate: <?php echo json_encode(array_values($hfaData['Moderate'])); ?>,
		Severe: <?php echo json_encode(array_values($hfaData['Severe'])); ?>,
		Refer: <?php echo json_encode(array_values($hfaData['Refer'] ?? [])); ?>
	},
	wflh: {
		Normal: <?php echo json_encode(array_values($wflhData['Normal'])); ?>,
		Moderate: <?php echo json_encode(array_values($wflhData['Moderate'])); ?>,
		Severe: <?php echo json_encode(array_values($wflhData['Severe'])); ?>,
		Refer: <?php echo json_encode(array_values($wflhData['Refer'] ?? [])); ?>
	}
};

// WHO Growth Indicators — wave chart with WFA / HFA / WFLH switching
// Mirrors the audit_logs chart pattern: smooth Catmull-Rom waves, hover
// tooltip, vertical guide line, and a legend of counts.
(function () {
	var canvas = document.getElementById('nutritionist-wave-chart');
	if (!canvas) return;
	var ctx = canvas.getContext('2d');
	var tooltip = document.getElementById('nutritionist-chart-tooltip');
	var yAxisEl = document.getElementById('nutritionist-chart-y-axis');
	var xAxisEl = document.getElementById('nutritionist-chart-x-axis');
	var titleEl = document.getElementById('nutritionist-chart-title');
	var tabsRoot = document.getElementById('nutritionist-chart-tabs');

	var months = chartMonths && chartMonths.length ? chartMonths : ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug'];

	// Resolve a CSS variable (e.g. var(--admin-primary)) to its real value.
	function resolveColor(value) {
		if (typeof value !== 'string') return value;
		if (value.indexOf('var(') !== 0) return value;
		var name = value.slice(4, -1).split(',')[0].trim();
		return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || '#94a3b8';
	}

	// Convert "#0b6e4f" / "rgb(11, 110, 79)" into an {r,g,b} object so we can
	// derive translucent fill colors that match the design system exactly.
	function parseRgb(value) {
		if (!value) return { r: 148, g: 163, b: 184 };
		value = value.trim();
		if (value[0] === '#') {
			var hex = value.slice(1);
			if (hex.length === 3) {
				hex = hex.split('').map(function (c) { return c + c; }).join('');
			}
			if (hex.length >= 6) {
				return {
					r: parseInt(hex.slice(0, 2), 16),
					g: parseInt(hex.slice(2, 4), 16),
					b: parseInt(hex.slice(4, 6), 16)
				};
			}
		}
		var m = value.match(/rgba?\(([^)]+)\)/i);
		if (m) {
			var parts = m[1].split(',').map(function (s) { return parseFloat(s.trim()); });
			return { r: parts[0] || 0, g: parts[1] || 0, b: parts[2] || 0 };
		}
		return { r: 148, g: 163, b: 184 };
	}

	function rgbaFromVar(varName, alpha) {
		var c = parseRgb(resolveColor(varName));
		return 'rgba(' + c.r + ',' + c.g + ',' + c.b + ',' + alpha + ')';
	}

	// Pull the live theme palette so colors adapt to light/dark mode.
	// The "Refer" series (gray) only renders a non-zero line for the WFA
	// tab -- it is a WFA-specific overflow ("Refer to WFL/H"). On HFA /
	// WFL/H it stays flat at zero and is hidden from the legend via the
	// CSS `[data-legend-row="Refer"]` toggle in the tab switcher below.
	function palette() {
		return [
			{ key: 'Normal',   color: resolveColor('var(--admin-primary)'), fill: rgbaFromVar('--admin-primary', 0.14), label: 'Normal' },
			{ key: 'Moderate', color: resolveColor('var(--admin-accent)'),  fill: rgbaFromVar('--admin-accent', 0.18),  label: 'Moderate' },
			{ key: 'Severe',   color: resolveColor('var(--admin-danger)'),  fill: rgbaFromVar('--admin-danger', 0.14),  label: 'Severe' },
			{ key: 'Refer',    color: resolveColor('var(--admin-muted)'),   fill: rgbaFromVar('--admin-muted', 0.10),   label: 'Refer to WFL/H' }
		];
	}

	var TITLES = {
		wfa:  'Weight-for-Age',
		hfa:  'Height-for-Age',
		wflh: 'Weight-for-Height/Length'
	};

	var padL = 36, padR = 14, padT = 16, padB = 26;
	var W = 0, H = 320, cW = 0, cH = 0, dpr = 1, maxVal = 1;
	var currentKey = 'wfa';
	var currentSeries = []; // [{key,color,fill,label,values:[n]}]
	var catData = []; // [{Normal:n,Moderate:n,Severe:n,label:'Jan'}]
	var hoverIndex = -1;
	var lastWidth = 0;

	function catmullRom(p0, p1, p2, p3, t) {
		var t2 = t * t, t3 = t2 * t;
		return 0.5 * ((2 * p1) + (-p0 + p2) * t + (2 * p0 - 5 * p1 + 4 * p2 - p3) * t2 + (-p0 + 3 * p1 - 3 * p2 + p3) * t3);
	}

	function buildSeries(key) {
		var raw = (chartDataEmbedded && chartDataEmbedded[key]) || { Normal: [], Moderate: [], Severe: [], Refer: [] };
		var cats = palette();
		catData = months.map(function (m, i) {
			return {
				label: m,
				Normal: Number(raw.Normal[i] || 0),
				Moderate: Number(raw.Moderate[i] || 0),
				Severe: Number(raw.Severe[i] || 0),
				Refer: Number(raw.Refer[i] || 0)
			};
		});
		currentSeries = cats.map(function (c) {
			return { key: c.key, color: c.color, fill: c.fill, label: c.label, values: catData.map(function (d) { return d[c.key]; }) };
		});
		maxVal = 1;
		currentSeries.forEach(function (s) {
			s.values.forEach(function (v) { if (v > maxVal) maxVal = v; });
		});
		maxVal = Math.max(Math.ceil(maxVal * 1.25), 4);
	}

	function sizeCanvas() {
		dpr = window.devicePixelRatio || 1;
		var parent = canvas.parentElement;
		var rectW = parent ? parent.getBoundingClientRect().width : 420;
		W = Math.max(rectW, 220);
		H = window.matchMedia && window.matchMedia('(max-width: 700px)').matches ? 280 : 320;
		lastWidth = W;
		canvas.width = Math.round(W * dpr);
		canvas.height = Math.round(H * dpr);
		canvas.style.width = W + 'px';
		canvas.style.height = H + 'px';
		ctx.setTransform(1, 0, 0, 1, 0, 0);
		ctx.scale(dpr, dpr);
		cW = W - padL - padR;
		cH = H - padT - padB;
	}

	function buildPoints(values) {
		return values.map(function (v, i) {
			return {
				x: padL + (i / Math.max(values.length - 1, 1)) * cW,
				y: padT + cH - (v / maxVal) * cH
			};
		});
	}

	function drawSmoothLine(points, color, fill, lineWidth) {
		if (points.length < 2) return;
		ctx.beginPath();
		ctx.moveTo(points[0].x, H - padB);
		ctx.lineTo(points[0].x, points[0].y);
		for (var i = 0; i < points.length - 1; i++) {
			var p0 = points[Math.max(i - 1, 0)];
			var p1 = points[i];
			var p2 = points[i + 1];
			var p3 = points[Math.min(i + 2, points.length - 1)];
			for (var t = 0; t <= 1; t += 0.05) {
				ctx.lineTo(catmullRom(p0.x, p1.x, p2.x, p3.x, t), catmullRom(p0.y, p1.y, p2.y, p3.y, t));
			}
		}
		ctx.lineTo(points[points.length - 1].x, H - padB);
		ctx.closePath();
		ctx.fillStyle = fill;
		ctx.fill();

		ctx.beginPath();
		ctx.moveTo(points[0].x, points[0].y);
		for (var j = 0; j < points.length - 1; j++) {
			var q0 = points[Math.max(j - 1, 0)];
			var q1 = points[j];
			var q2 = points[j + 1];
			var q3 = points[Math.min(j + 2, points.length - 1)];
			for (var tt = 0; tt <= 1; tt += 0.05) {
				ctx.lineTo(catmullRom(q0.x, q1.x, q2.x, q3.x, tt), catmullRom(q0.y, q1.y, q2.y, q3.y, tt));
			}
		}
		ctx.strokeStyle = color;
		ctx.lineWidth = lineWidth || 2.25;
		ctx.lineCap = 'round';
		ctx.lineJoin = 'round';
		ctx.stroke();
	}

	function drawAxes() {
		// Horizontal grid — uses the resolved surface border so the chart
		// matches both light and dark themes.
		var borderRgb = parseRgb(resolveColor('var(--admin-border)'));
		ctx.strokeStyle = 'rgba(' + borderRgb.r + ',' + borderRgb.g + ',' + borderRgb.b + ',0.55)';
		ctx.lineWidth = 0.5;
		for (var g = 0; g <= 4; g++) {
			var gy = padT + cH * (g / 4);
			ctx.beginPath();
			ctx.moveTo(padL, gy);
			ctx.lineTo(W - padR, gy);
			ctx.stroke();
		}

		// Y-axis labels (DOM)
		if (yAxisEl) {
			yAxisEl.innerHTML = '';
			for (var i = 4; i >= 0; i--) {
				var lbl = document.createElement('div');
				lbl.className = 'audit-chart-y-label';
				lbl.textContent = Math.round(maxVal * i / 4);
				yAxisEl.appendChild(lbl);
			}
		}

		// X-axis labels (DOM)
		if (xAxisEl) {
			xAxisEl.innerHTML = '';
			months.forEach(function (m, i) {
				var lbl = document.createElement('div');
				lbl.className = 'audit-chart-x-label';
				var currentMonthIndex = months.length - 1;
				lbl.textContent = (i % 2 === 0 || i === currentMonthIndex || i === months.length - 1) ? m : '';
				xAxisEl.appendChild(lbl);
			});
		}
	}

	function drawChart() {
		ctx.clearRect(0, 0, W, H);
		drawAxes();

		currentSeries.forEach(function (s) {
			var pts = buildPoints(s.values);
			drawSmoothLine(pts, s.color, s.fill, 2.25);
		});

		// Highlighted series dots
		if (hoverIndex >= 0 && hoverIndex < catData.length) {
			var dotCenter = resolveColor('var(--admin-surface)');
			currentSeries.forEach(function (s) {
				var pts = buildPoints(s.values);
				var p = pts[hoverIndex];
				ctx.beginPath();
				ctx.arc(p.x, p.y, 4, 0, Math.PI * 2);
				ctx.fillStyle = s.color;
				ctx.fill();
				ctx.beginPath();
				ctx.arc(p.x, p.y, 1.6, 0, Math.PI * 2);
				ctx.fillStyle = dotCenter;
				ctx.fill();
			});

			// Vertical hover guide
			var pts0 = buildPoints(currentSeries[0].values);
			var xPos = pts0[hoverIndex].x;
			ctx.beginPath();
			ctx.setLineDash([3, 3]);
			ctx.moveTo(xPos, padT);
			ctx.lineTo(xPos, padT + cH);
			ctx.strokeStyle = rgbaFromVar('--admin-muted', 0.45);
			ctx.lineWidth = 1;
			ctx.stroke();
			ctx.setLineDash([]);
		}
	}

	function refreshLegend() {
		if (!tabsRoot) return;
		var items = tabsRoot.querySelectorAll('.nutritionist-legend-dot');
		// The static legend lives outside the tabs; nothing to mutate here.
		// Reserved for future inline legend state.
		items.forEach(function () {});
	}

	function showTooltip(idx) {
		if (!tooltip) return;
		if (idx < 0 || idx >= catData.length) {
			tooltip.style.opacity = '0';
			return;
		}
		var d = catData[idx];
		var textOnDark = resolveColor('var(--admin-surface)');
		var parts = ['<strong style="color:' + textOnDark + ';">' + d.label + '</strong>'];
		currentSeries.forEach(function (s) {
			if (d[s.key] > 0) {
				parts.push('<span style="color:' + s.color + '">●</span> ' + s.label + ' ' + d[s.key]);
			}
		});
		if (parts.length === 1) parts.push('No data');
		tooltip.innerHTML = parts.join(' &nbsp; ');
		var pts0 = buildPoints(currentSeries[0].values);
		var xPos = pts0[idx].x;
		tooltip.style.left = xPos + 'px';
		tooltip.style.top = (padT + 4) + 'px';
		tooltip.style.opacity = '1';
	}

	function onMove(e) {
		var rect = canvas.getBoundingClientRect();
		var mx = e.clientX - rect.left;
		var closest = -1, closestDist = Infinity;
		for (var i = 0; i < catData.length; i++) {
			var pts = buildPoints(currentSeries[0].values);
			var d = Math.abs(pts[i].x - mx);
			if (d < closestDist) { closestDist = d; closest = i; }
		}
		if (closest >= 0 && closestDist < 40) {
			hoverIndex = closest;
			showTooltip(closest);
		} else {
			hoverIndex = -1;
			showTooltip(-1);
		}
		drawChart();
	}

	function onLeave() {
		hoverIndex = -1;
		showTooltip(-1);
		drawChart();
	}

	function setAxis(key) {
		currentKey = key;
		if (titleEl && TITLES[key]) titleEl.textContent = TITLES[key];
		buildSeries(key);
		sizeCanvas();
		drawChart();
		if (tooltip) tooltip.style.opacity = '0';
		hoverIndex = -1;

		// Update the sidebar — only show rows for the active axis.
		var sidebar = document.getElementById('nutritionist-chart-sidebar');
		if (sidebar) {
			var sidebarTitle = document.getElementById('nutritionist-sidebar-title');
			if (sidebarTitle) {
				var axisName = key === 'wflh' ? 'WFH / WFL' : key.toUpperCase();
				sidebarTitle.textContent = 'Latest Status — ' + axisName;
			}
			sidebar.querySelectorAll('[data-axis-row]').forEach(function (row) {
				var rowAxis = row.getAttribute('data-axis-row');
				row.hidden = rowAxis !== key;
			});
		}

		// The "Refer to WFL/H" legend item is a WFA-specific overflow
		// (WAZ > +2). Hide it on HFA / WFL/H where it's never meaningful.
		document.querySelectorAll('[data-legend-row]').forEach(function (item) {
			item.hidden = item.getAttribute('data-legend-row') === 'Refer' && key !== 'wfa';
		});
	}

	// Wire up tab buttons
	if (tabsRoot) {
		tabsRoot.querySelectorAll('.dashboard-chart-tab').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var axis = btn.getAttribute('data-axis');
				if (!axis) return;
				tabsRoot.querySelectorAll('.dashboard-chart-tab').forEach(function (b) {
					var active = b === btn;
					b.classList.toggle('is-active', active);
					b.setAttribute('aria-selected', active ? 'true' : 'false');
				});
				setAxis(axis);
			});
		});
	}

	canvas.addEventListener('mousemove', onMove);
	canvas.addEventListener('mouseleave', onLeave);
	canvas.addEventListener('touchstart', function (e) { if (e.touches && e.touches[0]) onMove(e.touches[0]); }, { passive: true });
	canvas.addEventListener('touchmove', function (e) { if (e.touches && e.touches[0]) onMove(e.touches[0]); }, { passive: true });
	canvas.addEventListener('touchend', onLeave, { passive: true });

	var resizeTimer = null;
	window.addEventListener('resize', function () {
		if (resizeTimer) clearTimeout(resizeTimer);
		resizeTimer = setTimeout(function () {
			sizeCanvas();
			drawChart();
		}, 100);
	});

	// Initial render
	setAxis('wfa');
	refreshLegend();
})();
</script>

<?php
nutritionist_layout_end();

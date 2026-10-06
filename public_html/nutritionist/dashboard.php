<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/who_calculator.php';
require_once __DIR__ . '/../includes/monitoring_periods.php';
require_once __DIR__ . '/../includes/export_dropdown.php';

$user = nutritionist_require_access();
$today = new DateTimeImmutable('today');

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
	   AND c.status = 'active'
	   AND TIMESTAMPDIFF(MONTH, c.birthdate, CURDATE()) <= 59
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
		c.sex,
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
	   AND c.status = 'active'
	   AND TIMESTAMPDIFF(MONTH, c.birthdate, CURDATE()) <= 59
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

// Current monitoring quarter (DOH calendar quarters).
$currentQuarter = (int)ceil((int)$today->format('n') / 3);
$quarterRange = monitoring_quarter_range((int)$today->format('Y'), $currentQuarter);
$quarterEnd = new DateTimeImmutable($quarterRange['end']);
$quarterDaysLeft = $today > $quarterEnd ? 0 : (int)$today->diff($quarterEnd)->days;

// Registered-child measurement coverage for the current month.
$registeredTotal = count($children);
$registeredMeasured = 0;
foreach ($children as $child) {
	$latestMeasurementDate = (string)($child['measurement_date'] ?? '');
	if ($latestMeasurementDate !== ''
		&& $latestMeasurementDate >= $monthStart
		&& $latestMeasurementDate <= $monthEnd) {
		$registeredMeasured++;
	}
}
$registeredPct = $registeredTotal > 0
	? (int)round(($registeredMeasured / $registeredTotal) * 100)
	: 0;
$registeredProgressColor = $registeredPct >= 100 ? '#16a34a' : '#dc2626';
$registeredProgressBackground = $registeredPct >= 100
	? 'rgba(22, 163, 74, 0.14)'
	: 'rgba(220, 38, 38, 0.14)';

// Scope barangay name for the scope card. Admins see every barangay.
$scopeBarangayName = 'All Barangays';
if (($user['role'] ?? '') !== 'admin' && !empty($user['barangay_id'])) {
	$scopeRow = admin_fetch_one('SELECT name FROM barangays WHERE id = ? LIMIT 1', 'i', [(int)$user['barangay_id']]);
	if ($scopeRow !== null && trim((string)($scopeRow['name'] ?? '')) !== '') {
		$scopeBarangayName = (string)$scopeRow['name'];
	}
}

// Per-axis classification counts for the WHO chart sidebar.
// Each axis has its own independent Normal / Moderate / Severe tally built
// from the WFA / HFA / WFH-or-WFL status columns on the latest measurement.
function buildAxisCounts(array $measurements, string $statusField): array {
	// WFA adds a 4th "Refer" bucket (DOH eOPT Plus rule: WAZ > +2 is
	// read off the WFL/H axis). HFA adds a 4th "Tall" bucket (HAZ > +2,
	// per classify_hfa_status()) kept exclusive from Normal so the
	// sidebar rows always sum to the measured children count.
	$counts = ['Normal' => 0, 'Moderate' => 0, 'Severe' => 0, 'Refer' => 0, 'Tall' => 0];
	foreach ($measurements as $m) {
		$status = strtolower(trim((string)($m[$statusField] ?? '')));
		if (str_contains($status, 'refer')) {
			$counts['Refer']++;
		} elseif ($status === 'tall' || $status === 't') {
			$counts['Tall']++;
		} elseif ($status === 'normal' || $status === 'n' || $status === '') {
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

// Per-pill counts (N / MUW / MSt / MW/MAM / OW / SUW / SSt / SW/SAM / Ob / REF) per axis.
// Used by the "Latest Status" sidebar so it can show every individual
// classification bucket with its own count and percentage. WFA now
// includes REF (Refer to WFL/H) for any child whose WAZ z-score lands
// above +2 — per the DOH eOPT Plus rule, that reading is read off the
// WFL/H axis instead.
function buildAxisPillCounts(array $measurements, string $statusField, string $axis): array {
	$counts = ['N' => 0, 'MUW' => 0, 'MSt' => 0, 'MW/MAM' => 0, 'OW' => 0, 'SUW' => 0, 'SSt' => 0, 'SW/SAM' => 0, 'Ob' => 0, 'REF' => 0, 'Tall' => 0];
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

/**
 * Classify a single axis status into a {label, level, axis, full} tuple.
 * level is 'normal' | 'moderate' | 'severe' | 'refer'.
 * label   is the short pill code (N, MUW, MSt, MW/MAM, OW, SUW, SSt, SW/SAM, Ob, REF).
 * full    is the human-readable WHO description (WFH wasting labels carry SAM/MAM).
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
	if ($s === 'sw' || $s === 'sw/sam' || $s === 'sw(sam)' || $s === 'sam')  return ['label' => 'SW/SAM',  'full' => 'Severely Wasted / SAM',        'level' => 'severe', 'axis' => 'wflh'];
	if ($s === 'ob')  return ['label' => 'Ob',  'full' => 'Obese',                  'level' => 'severe', 'axis' => 'wflh'];
	// Moderate bucket
	if ($s === 'muw') return ['label' => 'MUW', 'full' => 'Moderately Underweight', 'level' => 'moderate', 'axis' => 'wfa'];
	if ($s === 'mst') return ['label' => 'MSt', 'full' => 'Moderately Stunted',     'level' => 'moderate', 'axis' => 'hfa'];
	if ($s === 'mw' || $s === 'mw/mam' || $s === 'mw(mam)' || $s === 'mam')  return ['label' => 'MW/MAM',  'full' => 'Moderately Wasted / MAM',      'level' => 'moderate', 'axis' => 'wflh'];
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
		if (str_contains($s, 'wasted') || str_contains($s, 'sam'))       return ['label' => 'SW/SAM',  'full' => 'Severely Wasted / SAM',        'level' => 'severe', 'axis' => 'wflh'];
		if (str_contains($s, 'obese'))        return ['label' => 'Ob',  'full' => 'Obese',                  'level' => 'severe', 'axis' => 'wflh'];
		return ['label' => 'S', 'full' => 'Severe', 'level' => 'severe', 'axis' => $axis];
	}
	if (str_contains($s, 'moderate')) {
		if (str_contains($s, 'underweight')) return ['label' => 'MUW', 'full' => 'Moderately Underweight', 'level' => 'moderate', 'axis' => 'wfa'];
		if (str_contains($s, 'stunted'))      return ['label' => 'MSt', 'full' => 'Moderately Stunted',     'level' => 'moderate', 'axis' => 'hfa'];
		if (str_contains($s, 'wasted') || str_contains($s, 'mam'))       return ['label' => 'MW/MAM',  'full' => 'Moderately Wasted / MAM',      'level' => 'moderate', 'axis' => 'wflh'];
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
	if (in_array($status, ['completed', 'cancelled'], true)) {
		continue;
	}
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
		'url' => app_url('/nutritionist/appointments.php'),
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


// AI Insights — driven by the latest WHO growth-indicator snapshot per child.
// Speaks in the language of WFA / HFA / WFH z-score classifications and
// describes the chart data without prescribing interventions.
$aiBullets = [];
$totalMeasured = count($measurements);
$totalChildren = count($children);

$suw = (int)($axisPillCounts['wfa']['SUW'] ?? 0);
$sst = (int)($axisPillCounts['hfa']['SSt'] ?? 0);
$sw  = (int)($axisPillCounts['wflh']['SW/SAM']  ?? 0);
$ob  = (int)($axisPillCounts['wflh']['Ob']  ?? 0);
$muw = (int)($axisPillCounts['wfa']['MUW'] ?? 0);
$mst = (int)($axisPillCounts['hfa']['MSt'] ?? 0);
$mw  = (int)($axisPillCounts['wflh']['MW/MAM']  ?? 0);
$owWflh = (int)($axisPillCounts['wflh']['OW'] ?? 0);
$refWfa = (int)($axisPillCounts['wfa']['REF'] ?? 0);
$tallHfa = (int)($axisCounts['hfa']['Tall'] ?? $axisPillCounts['hfa']['Tall'] ?? 0);
$nWfa  = (int)($axisCounts['wfa']['Normal']  ?? 0);
$nHfa  = (int)($axisCounts['hfa']['Normal']  ?? 0);
$nWflh = (int)($axisCounts['wflh']['Normal'] ?? 0);

$totalSevere   = $suw + $sst + $sw + $ob;
$totalModerate = $muw + $mst + $mw + $owWflh;
$totalNormal   = $nWfa + $nHfa + $nWflh;
$totalRefer    = $refWfa;
$totalTall     = $tallHfa;
$totalAxes     = $totalNormal + $totalModerate + $totalSevere + $totalRefer + $totalTall;
$pctSevere   = $totalAxes > 0 ? round(($totalSevere / $totalAxes) * 100, 1) : 0;
$pctModerate = $totalAxes > 0 ? round(($totalModerate / $totalAxes) * 100, 1) : 0;
$pctNormal   = $totalAxes > 0 ? round(($totalNormal / $totalAxes) * 100, 1) : 0;
$pctRefer    = $totalAxes > 0 ? round(($totalRefer / $totalAxes) * 100, 1) : 0;
$pctTall     = $totalAxes > 0 ? round(($totalTall / $totalAxes) * 100, 1) : 0;

// Insight 1 — Severe classification distribution (always shown).
if ($totalSevere > 0) {
	$parts = [];
	if ($suw) $parts[] = "$suw SUW";
	if ($sst) $parts[] = "$sst SSt";
	if ($sw)  $parts[] = "$sw SW/SAM";
	if ($ob)  $parts[] = "$ob Ob";
	$aiBullets[] = '<strong>Severe burden.</strong> ' . implode(', ', $parts) . ' on the latest growth snapshot — ' . $pctSevere . '% of all WFA / HFA / WFH classifications land in the severe bucket.';
} else {
	$aiBullets[] = '<strong>No severe malnutrition flagged.</strong> Latest WFA / HFA / WFH snapshot shows zero SUW, SSt, SW/SAM or Ob classifications across ' . $totalMeasured . ' measured children.';
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
	if ($mw) $wfhParts[] = "$mw MW/MAM";
	if ($sw) $wfhParts[] = "$sw SW/SAM";
	if ($owWflh) $wfhParts[] = "$owWflh OW";
	if ($ob) $wfhParts[] = "$ob Ob";
	$aiBullets[] = '<strong>Weight-for-Height/Length (WFH).</strong> ' . implode(', ', $wfhParts) . ' visible on the WFH axis in the chart — represents the moderate + severe range of the WFH series.';
}

// Insight 5 — Overall distribution summary (always shown once we have data).
if ($totalAxes > 0) {
	$referClause = $pctRefer > 0 ? ', ' . $pctRefer . '% Refer to WFL/H' : '';
	$tallClause = $pctTall > 0 ? ', ' . $pctTall . '% Tall' : '';
	$aiBullets[] = '<strong>Distribution snapshot.</strong> Across all three axes the latest readings are ' . $pctNormal . '% Normal, ' . $pctModerate . '% Moderate, and ' . $pctSevere . '% Severe' . $referClause . $tallClause . '. Switch the WFA / HFA / WFH tabs above to see each axis in detail.';
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
	(nutritionist_can_write('children.create')
		? '<a class="admin-btn" href="' . nutritionist_e(app_url('/nutritionist/family_form.php')) . '">' . admin_action_icon('add') . ' Add Family</a>'
		: ''),
	'<a class="admin-btn-secondary" href="' . nutritionist_e(app_url('/nutritionist/eopt_reports.php')) . '">' . admin_action_icon('document') . ' EOPT Reports</a>',
]);

// PNG-style "Nutritional Status Overview" config — one bar/status set per WHO
// axis, all sourced from the existing pill counts above (no new queries).
// Large type + high contrast so the cards stay readable for older staff.
$nskPct = static function (int $count, int $total): string {
	if ($total <= 0 || $count <= 0) {
		return '0%';
	}
	return rtrim(rtrim(number_format($count / $total * 100, 2), '0'), '.') . '%';
};
$nskBars = static function (array $defs, int $total) use ($nskPct): array {
	$max = 1;
	foreach ($defs as $d) {
		$max = max($max, (int)($d['count'] ?? 0));
	}
	// Nice y-axis scale (round steps, ~4 intervals) so ticks read cleanly.
	$rough = $max / 4;
	$mag = pow(10, (int)floor(log10(max($rough, 0.1))));
	$step = 1;
	foreach ([1, 2, 5, 10] as $m) {
		if ($m * $mag >= $rough) {
			$step = max(1, (int)($m * $mag));
			break;
		}
		$step = max(1, (int)(10 * $mag));
	}
	$niceMax = max($step, (int)ceil($max / $step) * $step);
	$ticks = [];
	for ($v = $niceMax; $v >= 0; $v -= $step) {
		$ticks[] = $v;
	}
	$bars = [];
	foreach ($defs as $d) {
		$count = (int)($d['count'] ?? 0);
		$bars[] = [
			'label' => (string)($d['label'] ?? ''),
			'short' => (string)($d['short'] ?? $d['label'] ?? ''),
			'code' => (string)($d['code'] ?? ''),
			'color' => (string)($d['color'] ?? '#34d399'),
			'count' => $count,
			'height' => $count > 0 ? max(6, (int)round($count / $niceMax * 100)) : 2,
			'pct' => $nskPct($count, $total),
		];
	}
	return ['bars' => $bars, 'ticks' => $ticks, 'niceMax' => $niceMax];
};
$nskTallHfa = (int)($axisCounts['hfa']['Tall'] ?? $axisPillCounts['hfa']['Tall'] ?? 0);
$nskAxes = [
	'wfa' => [
		'chartTitle' => 'Weight-for-Age',
		'statusTitle' => 'Latest Status — WFA',
		'chart' => $nskBars([
			['label' => 'Severely Underweight', 'short' => 'SUW', 'code' => 'SUW', 'color' => '#dc2626', 'count' => (int)$axisPillCounts['wfa']['SUW']],
			['label' => 'Moderately Underweight', 'short' => 'MUW', 'code' => 'MUW', 'color' => '#ca8a04', 'count' => (int)$axisPillCounts['wfa']['MUW']],
			['label' => 'Use WFL/H column', 'short' => 'REF', 'code' => 'REF', 'color' => '#6b7280', 'count' => (int)$axisPillCounts['wfa']['REF']],
			['label' => 'Normal', 'short' => 'Normal', 'code' => 'N', 'color' => '#059669', 'count' => (int)$axisCounts['wfa']['Normal']],
		], $axisTotalWfa),
	],
	'hfa' => [
		'chartTitle' => 'Height-for-Age',
		'statusTitle' => 'Latest Status — HFA',
		'chart' => $nskBars([
			['label' => 'Severely Stunted', 'short' => 'SSt', 'code' => 'SSt', 'color' => '#dc2626', 'count' => (int)$axisPillCounts['hfa']['SSt']],
			['label' => 'Moderately Stunted', 'short' => 'MSt', 'code' => 'MSt', 'color' => '#ca8a04', 'count' => (int)$axisPillCounts['hfa']['MSt']],
			['label' => 'Tall', 'short' => 'Tall', 'code' => 'Tall', 'color' => '#6b7280', 'count' => $nskTallHfa],
			['label' => 'Normal', 'short' => 'Normal', 'code' => 'N', 'color' => '#059669', 'count' => (int)$axisCounts['hfa']['Normal']],
		], $axisTotalHfa),
	],
	'wflh' => [
		'chartTitle' => 'Weight-for-Length/Height',
		'statusTitle' => 'Latest Status — WFH',
		'chart' => $nskBars([
			['label' => 'Severely Wasted', 'short' => 'Severely Wasted', 'code' => 'SW', 'color' => '#dc2626', 'count' => (int)$axisPillCounts['wflh']['SW/SAM']],
			['label' => 'Moderately Wasted', 'short' => 'Moderately Wasted', 'code' => 'MW', 'color' => '#ca8a04', 'count' => (int)$axisPillCounts['wflh']['MW/MAM']],
			['label' => 'Overweight', 'short' => 'Overweight', 'code' => 'OW', 'color' => '#ea580c', 'count' => (int)$axisPillCounts['wflh']['OW']],
			['label' => 'Obese', 'short' => 'Obese', 'code' => 'OB', 'color' => '#ea580c', 'count' => (int)$axisPillCounts['wflh']['Ob']],
			['label' => 'Normal', 'short' => 'Normal', 'code' => 'N', 'color' => '#059669', 'count' => (int)$axisCounts['wflh']['Normal']],
		], $axisTotalWflh),
	],
];

nutritionist_layout_start('Nutritionist Dashboard', 'WHO monitoring, growth analysis, and appointment oversight.', 'dashboard', $actions);
?>

<div class="nutritionist-dashboard">

<section class="dashboard-stat-grid sk-stagger" aria-label="Key statistics">
	<article class="dashboard-stat-card">
		<div class="dashboard-stat-row">
			<div class="dashboard-stat-icon-wrap is-primary">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/></svg>
			</div>
			<div>
				<div class="dashboard-stat-label">Children Monitored</div>
				<div class="dashboard-stat-value" data-count-up><?php echo (int)$registeredTotal; ?></div>
				<div class="dashboard-stat-meta"><span class="highlight">Registered Children</span></div>
			</div>
		</div>
	</article>

	<article class="dashboard-stat-card">
		<div class="dashboard-stat-row">
			<div class="dashboard-stat-icon-wrap" style="background:<?php echo $registeredProgressBackground; ?>;color:<?php echo $registeredProgressColor; ?>;">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0 2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
			</div>
			<div>
				<div class="dashboard-stat-label">Measurements</div>
				<div class="dashboard-stat-value" data-count-up><?php echo count($measurements); ?></div>
				<div class="dashboard-stat-meta"><?php echo (int)$registeredMeasured; ?> out of <?php echo (int)$registeredTotal; ?> (<?php echo (int)$registeredPct; ?>%)</div>
			</div>
		</div>
	</article>

	<article class="dashboard-stat-card">
		<div class="dashboard-stat-row">
			<div class="dashboard-stat-icon-wrap is-accent">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
			</div>
			<div>
				<div class="dashboard-stat-label">Monitoring Quarter</div>
				<div class="dashboard-stat-value" style="font-size:1.25rem;line-height:1.3;"><?php echo nutritionist_e(implode(' - ', array_map(static fn(int $m): string => DateTimeImmutable::createFromFormat('!n', (string)$m)->format('M'), $quarterRange['months']))); ?></div>
				<div class="dashboard-stat-meta"><?php echo (int)$quarterDaysLeft; ?> days left</div>
			</div>
		</div>
	</article>

	<article class="dashboard-stat-card">
		<div class="dashboard-stat-row">
			<div class="dashboard-stat-icon-wrap is-valid">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
			</div>
			<div>
				<div class="dashboard-stat-label">Scope Barangay</div>
				<div class="dashboard-stat-value" style="font-size:1.25rem;line-height:1.3;"><?php echo nutritionist_e($scopeBarangayName); ?></div>
				<div class="dashboard-stat-meta">Your assigned scope</div>
			</div>
		</div>
	</article>
</section>

<section class="nutritionist-panel-grid nutritionist-dashboard-grid">
	<?php echo export_dropdown_assets(); ?>
	<article class="nutritionist-panel nsk-overview-panel" aria-label="Nutritional status overview">
		<div class="nsk-overview-head">
			<details class="export-dd nsk-indicator-dd" id="nskIndicatorDd">
				<summary class="admin-btn" aria-label="Growth indicator"><span id="nskDdLabel">Weight-for-Age</span></summary>
				<div class="export-dd-pop" role="menu">
					<a class="export-dd-item" role="menuitem" href="#" data-nsk-axis-opt="wfa"><span><span class="export-dd-label">Weight-for-Age</span><br><span class="export-dd-sub">Weight-for-age status</span></span><span class="fmt">WFA</span></a>
					<a class="export-dd-item" role="menuitem" href="#" data-nsk-axis-opt="hfa"><span><span class="export-dd-label">Height-for-Age</span><br><span class="export-dd-sub">Height-for-age status</span></span><span class="fmt">HFA</span></a>
					<a class="export-dd-item" role="menuitem" href="#" data-nsk-axis-opt="wflh"><span><span class="export-dd-label">Weight-for-Length/Height</span><br><span class="export-dd-sub">Weight-for-length/height status</span></span><span class="fmt">WFH</span></a>
				</div>
			</details>
			<p class="nsk-overview-subtitle">Status for <?php echo date('Y'); ?></p>
		</div>

		<div class="nsk-overview-grid">
			<div class="nsk-card nsk-chart-card">
				<div class="nsk-chart-wrap">
					<canvas id="nskChart"></canvas>
				</div>
			</div>
<?php
// Canvas data for the prevalence-style bar chart (whole-number counts).
// X labels use full names word-wrapped (no codes) to match the page text.
$nskWrapLabel = static function (string $text, int $width = 12): string {
	$words = preg_split('/\s+/', trim($text)) ?: [];
	$lines = [];
	$cur = '';
	foreach ($words as $w) {
		if ($cur === '') { $cur = $w; }
		elseif (strlen($cur . ' ' . $w) <= $width) { $cur .= ' ' . $w; }
		else { $lines[] = $cur; $cur = $w; }
	}
	if ($cur !== '') { $lines[] = $cur; }
	return implode("\n", $lines);
};
$nskChartJson = [];
foreach ($nskAxes as $nskAxisKey => $nskAxis) {
	$nskItems = [];
	foreach ($nskAxis['chart']['bars'] as $nskBar) {
		$nskItems[] = [
			'label' => $nskWrapLabel($nskBar['label']),
			'count' => (int)$nskBar['count'],
			'color' => $nskBar['color'],
		];
	}
	$nskChartJson[$nskAxisKey] = [
		'title' => $nskAxis['chartTitle'],
		'niceMax' => (int)$nskAxis['chart']['niceMax'],
		'ticks' => array_map('intval', $nskAxis['chart']['ticks']),
		'items' => $nskItems,
	];
}
?>
<script>
window.NSK_DATA = <?php echo json_encode($nskChartJson, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
</script>

			<div class="nsk-card nsk-status-card">
				<div class="nsk-status-title" id="nsk-status-title">Latest Status — WFA</div>
				<?php foreach ($nskAxes as $nskAxisKey => $nskAxis): ?>
				<div data-nsk-axis="<?php echo nutritionist_e($nskAxisKey); ?>"<?php echo $nskAxisKey !== 'wfa' ? ' hidden' : ''; ?>>
					<?php foreach ($nskAxis['chart']['bars'] as $nskBar): ?>
					<div class="nsk-status-row">
						<span class="nsk-status-dot" style="background:<?php echo nutritionist_e($nskBar['color']); ?>;" aria-hidden="true"></span>
						<span class="nsk-status-label"><?php echo nutritionist_e($nskBar['label']); ?></span>
						<span class="nsk-status-code"><?php echo nutritionist_e($nskBar['code']); ?></span>
						<span class="nsk-status-count"><?php echo (int)$nskBar['count']; ?></span>
						<span class="nsk-status-pct"><?php echo nutritionist_e($nskBar['pct']); ?></span>
					</div>
					<?php endforeach; ?>
				</div>
				<?php endforeach; ?>

				<div class="nsk-mini-cal">
					<div class="nsk-mini-cal-head">
						<span class="nsk-mini-cal-title">Calendar</span>
						<span class="nsk-mini-cal-nav">
							<a class="nsk-mini-cal-btn" href="<?php echo nutritionist_e($prevMonthLink); ?>" aria-label="Previous month">‹</a>
							<span class="nsk-mini-cal-month"><?php echo nutritionist_e($calendarDate->format('F Y')); ?></span>
							<a class="nsk-mini-cal-btn" href="<?php echo nutritionist_e($nextMonthLink); ?>" aria-label="Next month">›</a>
						</span>
					</div>
					<div class="sk-cal-wrap" data-sk-calendar>
						<?php echo nutritionist_render_calendar_grid($calendarDate, $calendarEntries, $today); ?>
					</div>
				</div>
			</div>
		</div>
	</article>
</section>

<nav class="admin-quicknav nutritionist-quicknav" aria-label="Quick actions" style="margin-top:18px;">
	<?php
	$quickActions = [
		['href' => app_url('/nutritionist/children.php'), 'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>', 'label' => 'Children Records', 'sub' => 'View & manage children'],
		['href' => app_url('/nutritionist/monitoring.php'), 'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/></svg>', 'label' => 'Monitoring List', 'sub' => 'Follow-up tracking'],
		['href' => app_url('/nutritionist/family_import.php'), 'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/></svg>', 'label' => 'Import Families', 'sub' => 'Bulk upload'],
		['href' => app_url('/nutritionist/measurement_record.php'), 'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>', 'label' => 'Record Measurement', 'sub' => 'Add new reading'],
	];
	foreach ($quickActions as $qa): ?>
		<a class="admin-quicknav-item" href="<?php echo nutritionist_e($qa['href']); ?>">
			<span class="admin-quicknav-icon"><?php echo $qa['icon']; ?></span>
			<span class="admin-quicknav-text">
				<strong><?php echo nutritionist_e($qa['label']); ?></strong>
				<small><?php echo nutritionist_e($qa['sub']); ?></small>
			</span>
			<span class="admin-quicknav-arrow" aria-hidden="true">&#8250;</span>
		</a>
	<?php endforeach; ?>
</nav>

</div><!-- /.nutritionist-dashboard -->

<script>
/* Prevalence-style animated bar chart (whole-number counts) + dropdown switch.
   Canvas fills the card so the graph runs to the bottom, level with the
   status card. Label padding adapts to wrapped x-label lines. Repaints
   instantly on theme toggle via MutationObserver (no page refresh). */
(function () {
	var axisDd = document.getElementById('nskIndicatorDd');
	var axisLabel = document.getElementById('nskDdLabel');
	var canvas = document.getElementById('nskChart');
	var D = window.NSK_DATA;
	if (!axisDd || !canvas || !D) return;

	var TITLES = { wfa: 'Weight-for-Age', hfa: 'Height-for-Age', wflh: 'Weight-for-Length/Height' };
	var STATUS = { wfa: 'Latest Status \u2014 WFA', hfa: 'Latest Status \u2014 HFA', wflh: 'Latest Status \u2014 WFH' };
	var nskKey = 'wfa', nskHover = -1, nskAnimStart = null;

	function getCSS(v){ return getComputedStyle(document.documentElement).getPropertyValue(v).trim(); }
	function setupCanvas(cv, w, h){
		var dpr = window.devicePixelRatio || 1;
		cv.width = w * dpr;
		cv.height = h * dpr;
		cv.style.width = w + 'px';
		cv.style.height = h + 'px';
		var ctx = cv.getContext('2d');
		ctx.scale(dpr, dpr);
		return ctx;
	}
	function hexToRgba(hex, a){
		var r = parseInt(hex.slice(1,3),16), g = parseInt(hex.slice(3,5),16), b = parseInt(hex.slice(5,7),16);
		return 'rgba('+r+','+g+','+b+','+a+')';
	}
	function easeOutCubic(t){ return 1 - Math.pow(1 - t, 3); }

	function maxLabelLines(items){
		var m = 1;
		items.forEach(function(it){
			m = Math.max(m, String(it.label).split('\n').length);
		});
		return m;
	}
	function nskLayout(items){
		var rect = canvas.parentElement.getBoundingClientRect();
		var w = Math.max(rect.width, 220);
		var h = Math.max(Math.round(rect.height) || 0, 280);
		var padL = 46, padR = 14, padT = 24;
		var padB = 26 + maxLabelLines(items) * 15;
		var cW = w - padL - padR, cH = h - padT - padB;
		var groupW = cW / Math.max(items.length, 1);
		return { w: w, h: h, padL: padL, padR: padR, padT: padT, padB: padB, cW: cW, cH: cH, groupW: groupW, barW: Math.min(groupW * 0.5, 54) };
	}

	function renderNsk(key, animPct){
		animPct = animPct === undefined ? 1 : animPct;
		var data = D[key] || D.wfa;
		var items = data.items, niceMax = Math.max(data.niceMax, 1), ticks = data.ticks || [];
		var L = nskLayout(items);
		var ctx = setupCanvas(canvas, L.w, L.h);

		/* dashed grid + whole-number y labels */
		ctx.strokeStyle = hexToRgba(getCSS('--admin-muted') || '#94a3b8', 0.12);
		ctx.lineWidth = 1;
		ticks.forEach(function(t){
			var gy = L.padT + L.cH - (t / niceMax) * L.cH;
			ctx.beginPath(); ctx.setLineDash([4,4]); ctx.moveTo(L.padL, gy); ctx.lineTo(L.w-L.padR, gy); ctx.stroke();
			ctx.setLineDash([]);
			ctx.fillStyle = getCSS('--admin-muted') || '#94a3b8';
			ctx.font = '10px Inter, sans-serif';
			ctx.textAlign = 'right';
			ctx.fillText(String(t), L.padL-6, gy+3);
		});

		items.forEach(function(it, i){
			var cx = L.padL + L.groupW*i + L.groupW/2;
			var bh = (it.count / niceMax) * L.cH * animPct;
			var bx = cx - L.barW/2;
			var by = L.padT + L.cH - bh;
			var isHover = nskHover === i;

			ctx.save();
			ctx.shadowColor = hexToRgba(it.color, 0.25);
			ctx.shadowBlur = isHover ? 12 : 3;
			ctx.shadowOffsetY = 3;
			var grad = ctx.createLinearGradient(0, by, 0, L.padT + L.cH);
			grad.addColorStop(0, it.color);
			grad.addColorStop(1, hexToRgba(it.color, isHover ? 0.7 : 0.9));
			ctx.fillStyle = grad;
			ctx.beginPath();
			if (ctx.roundRect) ctx.roundRect(bx, by, L.barW, Math.max(bh, 1), [5,5,0,0]);
			else ctx.rect(bx, by, L.barW, Math.max(bh, 1));
			ctx.fill();
			ctx.restore();

			/* whole-number count on top */
			ctx.fillStyle = isHover ? it.color : (getCSS('--admin-text') || '#1e293b');
			ctx.font = (isHover ? 'bold ' : '') + '12px Inter, sans-serif';
			ctx.textAlign = 'center';
			if (animPct >= 0.95) ctx.fillText(String(it.count), cx, by - 10);

			/* x label — full name, wrapped */
			ctx.fillStyle = getCSS('--admin-text') || '#1e293b';
			ctx.font = '600 12px Inter, sans-serif';
			ctx.textAlign = 'center';
			ctx.textBaseline = 'top';
			var lines = String(it.label).split('\n');
			var lineH = 14;
			var startY = L.padT + L.cH + 16 - ((lines.length * lineH) - lineH) / 2;
			lines.forEach(function(line, li){
				ctx.fillText(line, cx, startY + li * lineH);
			});
			ctx.textBaseline = 'alphabetic';
		});
	}

	function nskLoop(ts){
		if (!nskAnimStart) nskAnimStart = ts;
		var pct = Math.min((ts - nskAnimStart) / 700, 1);
		renderNsk(nskKey, easeOutCubic(pct));
		if (pct < 1) requestAnimationFrame(nskLoop);
	}
	function playAxis(key){
		nskKey = TITLES[key] ? key : 'wfa';
		nskHover = -1;
		nskAnimStart = null;
		requestAnimationFrame(nskLoop);
	}

	function setAxis(key) {
		if (!TITLES[key]) key = 'wfa';
		if (axisLabel) axisLabel.textContent = TITLES[key];
		if (axisDd.open) axisDd.removeAttribute('open');
		document.querySelectorAll('[data-nsk-axis]').forEach(function (el) {
			el.hidden = el.getAttribute('data-nsk-axis') !== key;
		});
		var st = document.getElementById('nsk-status-title');
		if (st) st.textContent = STATUS[key];
		playAxis(key);
	}
	axisDd.querySelectorAll('[data-nsk-axis-opt]').forEach(function (opt) {
		opt.addEventListener('click', function (e) {
			e.preventDefault();
			setAxis(opt.getAttribute('data-nsk-axis-opt'));
		});
	});

	/* hover tooltip (whole numbers) */
	(function(){
		var tooltip = document.createElement('div');
		tooltip.className = 'who-chart-tooltip';
		canvas.parentElement.style.position = 'relative';
		canvas.parentElement.appendChild(tooltip);
		canvas.addEventListener('mousemove', function(e){
			var data = D[nskKey] || D.wfa;
			var items = data.items, niceMax = Math.max(data.niceMax, 1);
			var rect = canvas.getBoundingClientRect();
			var mx = e.clientX - rect.left, my = e.clientY - rect.top;
			var L = nskLayout(items);
			var found = false;
			items.forEach(function(it, i){
				var cx = L.padL + L.groupW*i + L.groupW/2;
				var bh = (it.count / niceMax) * L.cH;
				var bx = cx - L.barW/2;
				var by = L.padT + L.cH - bh;
				if (mx >= bx && mx <= bx+L.barW && my >= by && my <= L.padT+L.cH){
					tooltip.innerHTML = '<strong>' + it.label.replace(/\n/g, ' ') + '</strong><br>' + it.count + ' children';
					tooltip.style.left = (bx + L.barW/2) + 'px';
					tooltip.style.top = (by - 10) + 'px';
					tooltip.classList.add('is-visible');
					if (nskHover !== i) { nskHover = i; renderNsk(nskKey, 1); }
					found = true;
				}
			});
			if (!found){
				tooltip.classList.remove('is-visible');
				if (nskHover !== -1) { nskHover = -1; renderNsk(nskKey, 1); }
			}
		});
		canvas.addEventListener('mouseleave', function(){
			tooltip.classList.remove('is-visible');
			nskHover = -1;
			renderNsk(nskKey, 1);
		});
	})();

	var nskResize = null;
	window.addEventListener('resize', function () {
		if (nskResize) clearTimeout(nskResize);
		nskResize = setTimeout(function () { renderNsk(nskKey, 1); }, 120);
	});

	/* Repaint instantly when the theme toggles (canvas keeps stale CSS-var
	   colors otherwise until a full page refresh). */
	if (typeof MutationObserver === 'function') {
		var nskThemeObs = new MutationObserver(function (mutations) {
			for (var mi = 0; mi < mutations.length; mi++) {
				if (mutations[mi].attributeName === 'data-theme') {
					nskHover = -1;
					renderNsk(nskKey, 1);
					break;
				}
			}
		});
		nskThemeObs.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
	}

	setAxis('wfa');
})();
</script>

<?php
nutritionist_layout_end();

<?php

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth_middleware.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/nutritionist_helpers.php';

$user = api_require_staff_session();
api_require_method(['GET']);

$year = api_int($_GET['year'] ?? null, (int)date('Y'));
$view = (string)($_GET['view'] ?? 'monthly');
if (!in_array($view, ['monthly', 'quarterly'], true)) {
	$view = 'monthly';
}

$currentMonth = (int)date('n');
$month = (int)($_GET['month'] ?? ($currentMonth >= 4 && $currentMonth <= 12 ? $currentMonth : 4));
if ($month < 4 || $month > 12) {
	$month = 4;
}

$defaultCheckupMonth = 7;
foreach (FOLLOWUP_QUARTER_MONTHS as $candidateRound) {
	if ((int)date('n') <= $candidateRound) {
		$defaultCheckupMonth = $candidateRound;
		break;
	}
}
$checkupMonth = (int)($_GET['checkup_month'] ?? $defaultCheckupMonth);
if (!in_array($checkupMonth, FOLLOWUP_QUARTER_MONTHS, true)) {
	$checkupMonth = 7;
}

$barangayFilter = (int)($_GET['barangay_id'] ?? 0);

$scopeParams = [];
$scope = nutritionist_scope_fragment($user, 'c.barangay_id', $scopeParams);

$barangayFilterSql = '';
$barangayFilterParams = [];
if ($barangayFilter > 0) {
	$barangayFilterSql = ' AND c.barangay_id = ?';
	$barangayFilterParams[] = $barangayFilter;
}

try {
	$anchorDate = new DateTimeImmutable(sprintf('%04d-%02d-t', $year, $view === 'monthly' ? $month : $checkupMonth));
} catch (Exception) {
	$anchorDate = new DateTimeImmutable('today');
}
$anchorParam = $anchorDate->format('Y-m-d');

$summaryRows = admin_fetch_all(
	"SELECT
		c.sex,
		CASE WHEN TIMESTAMPDIFF(MONTH, c.birthdate, LAST_DAY(?)) < 24 THEN '0-23' ELSE '24-59' END AS age_band,
		m.wfa_status,
		m.hfa_status,
		m.wfh_status
	 FROM children c
	 INNER JOIN measurements m ON m.id = (
		SELECT m2.id FROM measurements m2
		WHERE m2.child_id = c.id
		ORDER BY m2.measurement_date DESC, m2.id DESC
		LIMIT 1
	 )
	 WHERE {$scope}{$barangayFilterSql}
	   AND TIMESTAMPDIFF(MONTH, c.birthdate, LAST_DAY(?)) BETWEEN 0 AND 59",
	's' . str_repeat('i', count($scopeParams) + count($barangayFilterParams)) . 's',
	array_merge([$anchorParam], $scopeParams, $barangayFilterParams, [$anchorParam])
);

$bucket = static fn(): array => [
	'Male' => ['0-23' => 0, '24-59' => 0, 'total' => 0],
	'Female' => ['0-23' => 0, '24-59' => 0, 'total' => 0],
	'Total' => ['0-23' => 0, '24-59' => 0, 'total' => 0],
];

$wfaSummary = ['SUW' => $bucket(), 'MUW' => $bucket(), 'Normal' => $bucket(), 'OW' => $bucket()];
$hfaSummary = ['SSt' => $bucket(), 'MSt' => $bucket(), 'Normal' => $bucket(), 'Tall' => $bucket()];
$wfhSummary = ['SW' => $bucket(), 'MW' => $bucket(), 'Normal' => $bucket(), 'OW' => $bucket(), 'Ob' => $bucket()];

foreach ($summaryRows as $row) {
	$sexLabel = (string)$row['sex'] === 'Male' ? 'Male' : 'Female';
	$ageBandKey = (string)$row['age_band'];

	foreach ([['wfa_status', &$wfaSummary], ['hfa_status', &$hfaSummary], ['wfh_status', &$wfhSummary]] as [$field, &$summaryRef]) {
		$value = $row[$field] ?? null;
		if ($value === null || !isset($summaryRef[$value])) {
			continue;
		}
		$summaryRef[$value][$sexLabel][$ageBandKey]++;
		$summaryRef[$value][$sexLabel]['total']++;
		$summaryRef[$value]['Total'][$ageBandKey]++;
		$summaryRef[$value]['Total']['total']++;
	}
	unset($summaryRef);
}

$totalAll = 0;
foreach ($wfaSummary as $statusData) {
	$totalAll = max($totalAll, (int)$statusData['Total']['total']);
}

$formatAxis = static function(array $summary): array {
	$out = [];
	foreach ($summary as $status => $counts) {
		$m023 = (int)$counts['Male']['0-23'];
		$m2459 = (int)$counts['Male']['24-59'];
		$mTotal = (int)$counts['Male']['total'];
		$f023 = (int)$counts['Female']['0-23'];
		$f2459 = (int)$counts['Female']['24-59'];
		$fTotal = (int)$counts['Female']['total'];
		$allTotal = (int)$counts['Total']['total'];
		$totalAll = (int)$counts['Total']['total'];
		$out[] = [
			'status' => $status,
			'male_0_23' => $m023,
			'male_24_59' => $m2459,
			'male_total' => $mTotal,
			'female_0_23' => $f023,
			'female_24_59' => $f2459,
			'female_total' => $fTotal,
			'grand_total' => $allTotal,
		];
	}
	return $out;
};

api_success([
	'wfa' => $formatAxis($wfaSummary),
	'hfa' => $formatAxis($hfaSummary),
	'wfh' => $formatAxis($wfhSummary),
	'total_population' => $totalAll,
	'period_label' => $view === 'monthly'
		? strftime('%B %Y', strtotime($anchorParam))
		: 'Q' . intdiv($checkupMonth - 1, 3) . ' ' . $year,
], 'Nutritional status summary loaded.');

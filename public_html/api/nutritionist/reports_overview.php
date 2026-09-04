<?php

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth_middleware.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../../includes/followup_scheduler.php';

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

$latestJoin = " INNER JOIN measurements lm ON lm.id = (
	SELECT m2.id FROM measurements m2
	WHERE m2.child_id = c.id
	ORDER BY m2.measurement_date DESC, m2.id DESC
	LIMIT 1
)";

$baseTypes = str_repeat('i', count($scopeParams) + count($barangayFilterParams)) . 's';
$baseParams = array_merge($scopeParams, $barangayFilterParams, [$anchorParam]);

$totalAssessed = admin_scalar(
	"SELECT COUNT(DISTINCT c.id) FROM children c
	 INNER JOIN measurements m ON m.child_id = c.id
	 WHERE {$scope}{$barangayFilterSql}
	   AND m.measurement_date <= ?",
	$baseTypes,
	$baseParams
);

$children023 = admin_scalar(
	"SELECT COUNT(DISTINCT c.id) FROM children c
	 INNER JOIN measurements m ON m.child_id = c.id
	 WHERE {$scope}{$barangayFilterSql}
	   AND m.measurement_date <= ?
	   AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 23",
	$baseTypes . 's',
	array_merge($baseParams, [$anchorParam])
);

$malnourishedRows = admin_fetch_all(
	"SELECT c.id
	 FROM children c
	 {$latestJoin}
	 WHERE {$scope}{$barangayFilterSql}
	   AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 59
	   AND (
		lm.wfa_status IN ('SUW','MUW')
		OR lm.hfa_status IN ('SSt','MSt')
		OR lm.wfh_status IN ('SW','MW')
	   )",
	$baseTypes,
	$baseParams
);
$malnourishedCount = count($malnourishedRows);

$owObRows = admin_fetch_all(
	"SELECT c.id
	 FROM children c
	 {$latestJoin}
	 WHERE {$scope}{$barangayFilterSql}
	   AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 59
	   AND (lm.wfa_status = 'OW' OR lm.wfh_status IN ('OW','Ob'))",
	$baseTypes,
	$baseParams
);
$affectedCount = $malnourishedCount + count($owObRows);

$dqIssues = 0;
$dqDuplicateRows = admin_fetch_all(
	"SELECT c1.first_name, c1.last_name, c1.birthdate, COUNT(*) AS cnt
	 FROM children c1
	 WHERE c1.barangay_id IS NOT NULL
	 GROUP BY c1.first_name, c1.last_name, c1.birthdate
	 HAVING cnt > 1",
	'',
	[]
);
$dqIssues += count($dqDuplicateRows);

$dqMissingSex = admin_scalar(
	"SELECT COUNT(*) FROM children c WHERE c.sex IS NULL AND {$scope}{$barangayFilterSql}",
	str_repeat('i', count($scopeParams) + count($barangayFilterParams)),
	array_merge($scopeParams, $barangayFilterParams)
);
$dqIssues += $dqMissingSex;

$dqMissingDob = admin_scalar(
	"SELECT COUNT(*) FROM children c WHERE c.birthdate IS NULL AND {$scope}{$barangayFilterSql}",
	str_repeat('i', count($scopeParams) + count($barangayFilterParams)),
	array_merge($scopeParams, $barangayFilterParams)
);
$dqIssues += $dqMissingDob;

$dqOverAge = admin_scalar(
	"SELECT COUNT(*) FROM children c
	 WHERE {$scope}{$barangayFilterSql}
	   AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) > 59",
	$baseTypes,
	$baseParams
);
$dqIssues += $dqOverAge;

$roundsLabels = [4 => 'April Round', 7 => 'July Round', 10 => 'October Round'];
$periodLabel = $view === 'monthly'
	? date('F Y', strtotime($anchorParam))
	: ($roundsLabels[$checkupMonth] ?? '') . ' ' . $year;

api_success([
	'total_assessed' => (int)$totalAssessed,
	'children_0_23' => (int)$children023,
	'malnourished' => (int)$malnourishedCount,
	'affected' => (int)$affectedCount,
	'dq_issues' => (int)$dqIssues,
	'period_label' => $periodLabel,
], 'Overview statistics loaded.');

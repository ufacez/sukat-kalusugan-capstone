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

$baseTypes = str_repeat('i', count($scopeParams) + count($barangayFilterParams)) . 's';
$baseParams = array_merge($scopeParams, $barangayFilterParams, [$anchorParam]);

$latestJoin = " INNER JOIN measurements lm ON lm.id = (
	SELECT m2.id FROM measurements m2
	WHERE m2.child_id = c.id
	ORDER BY m2.measurement_date DESC, m2.id DESC
	LIMIT 1
)";

$allChildren = admin_fetch_all(
	"SELECT c.id, c.sex, c.birthdate,
		lm.waz, lm.haz, lm.whz, lm.weight_kg, lm.height_cm,
		CASE WHEN lm.waz < -3 THEN 'SUW' WHEN lm.waz < -2 THEN 'MUW' WHEN lm.waz > 2 THEN 'Refer to WFL/H' ELSE 'Normal' END AS wfa_status,
		CASE WHEN lm.haz < -3 THEN 'SSt' WHEN lm.haz < -2 THEN 'MSt' WHEN lm.haz > 2 THEN 'Tall' ELSE 'Normal' END AS hfa_status,
		CASE WHEN lm.whz < -3 THEN 'SW' WHEN lm.whz < -2 THEN 'MW' WHEN lm.whz > 3 THEN 'Ob' WHEN lm.whz > 2 THEN 'OW' ELSE 'Normal' END AS wfh_status
	 FROM children c
	 {$latestJoin}
	 WHERE {$scope}{$barangayFilterSql}
	   AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 59",
	$baseTypes,
	$baseParams
);

$totalPop = count($allChildren);

$wastedCount = 0;
$stuntedCount = 0;
$owObCount = 0;
$underweightCount = 0;
$uwOrStuntedCount = 0;
$stuntedOrOwObCount = 0;
$muacNormal = 0;
$muacMam = 0;
$muacSam = 0;

foreach ($allChildren as $child) {
	$wfa = $child['wfa_status'] ?? null;
	$hfa = $child['hfa_status'] ?? null;
	$wfh = $child['wfh_status'] ?? null;
	$weight = $child['weight_kg'] !== null ? (float)$child['weight_kg'] : null;
	$height = $child['height_cm'] !== null ? (float)$child['height_cm'] : null;

	$isWasted = in_array($wfh, ['MW', 'SW'], true);
	$isStunted = in_array($hfa, ['MSt', 'SSt'], true);
	$isOwOb = $wfa === 'OW' || in_array($wfh, ['OW', 'Ob'], true);
	$isUw = in_array($wfa, ['MUW', 'SUW'], true);

	if ($isWasted) {
		$wastedCount++;
	}
	if ($isStunted) {
		$stuntedCount++;
	}
	if ($isOwOb) {
		$owObCount++;
	}
	if ($isUw) {
		$underweightCount++;
	}
	if ($isUw || $isStunted) {
		$uwOrStuntedCount++;
	}
	if ($isStunted || $isOwOb) {
		$stuntedOrOwObCount++;
	}

	if ($weight !== null && $height !== null && $height > 0) {
		$muacEstimate = ($weight / $height) * 10;
		if ($muacEstimate >= 11.5 && $muacEstimate < 12.5) {
			$muacMam++;
		} elseif ($muacEstimate < 11.5) {
			$muacSam++;
		} else {
			$muacNormal++;
		}
	}
}

$pct = static function(int $count, int $total): float {
	return $total > 0 ? round(($count / $total) * 100, 1) : 0.0;
};

api_success([
	'total_population' => $totalPop,
	'indicators' => [
		[
			'code' => 'wasted',
			'label' => 'Wasted (MW + SW)',
			'count' => $wastedCount,
			'prevalence' => $pct($wastedCount, $totalPop),
		],
		[
			'code' => 'stunted',
			'label' => 'Stunted (MSt + SSt)',
			'count' => $stuntedCount,
			'prevalence' => $pct($stuntedCount, $totalPop),
		],
		[
			'code' => 'ow_ob',
			'label' => 'Overweight / Obese',
			'count' => $owObCount,
			'prevalence' => $pct($owObCount, $totalPop),
		],
		[
			'code' => 'underweight',
			'label' => 'Underweight (MUW + SUW)',
			'count' => $underweightCount,
			'prevalence' => $pct($underweightCount, $totalPop),
		],
		[
			'code' => 'uw_or_stunted',
			'label' => 'Underweight and/or Stunted',
			'count' => $uwOrStuntedCount,
			'prevalence' => $pct($uwOrStuntedCount, $totalPop),
		],
		[
			'code' => 'stunted_or_owob',
			'label' => 'Stunted and/or OW/Obese',
			'count' => $stuntedOrOwObCount,
			'prevalence' => $pct($stuntedOrOwObCount, $totalPop),
		],
	],
	'muac_distribution' => [
		['label' => 'Normal (>=12.5)', 'count' => $muacNormal, 'prevalence' => $pct($muacNormal, $muacNormal + $muacMam + $muacSam)],
		['label' => 'MAM (11.5-12.4)', 'count' => $muacMam, 'prevalence' => $pct($muacMam, $muacNormal + $muacMam + $muacSam)],
		['label' => 'SAM (<11.5)', 'count' => $muacSam, 'prevalence' => $pct($muacSam, $muacNormal + $muacMam + $muacSam)],
	],
], 'Prevalence data loaded.');

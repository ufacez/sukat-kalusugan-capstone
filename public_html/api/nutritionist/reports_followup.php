<?php

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth_middleware.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../../includes/followup_scheduler.php';

$user = api_require_staff_session();
api_require_method(['GET']);

$barangayFilter = (int)($_GET['barangay_id'] ?? 0);
$statusFilter = (string)($_GET['status'] ?? '');

$scopeParams = [];
$scope = nutritionist_scope_fragment($user, 'c.barangay_id', $scopeParams);

$barangayFilterSql = '';
$barangayFilterParams = [];
if ($barangayFilter > 0) {
	$barangayFilterSql = ' AND a.child_id IN (SELECT c2.id FROM children c2 WHERE c2.barangay_id = ?)';
	$barangayFilterParams[] = $barangayFilter;
}

$statusSql = '';
$statusParams = [];
if ($statusFilter !== '' && in_array($statusFilter, ['pending', 'scheduled', 'completed', 'referred'], true)) {
	$statusSql = ' AND a.status = ?';
	$statusParams[] = $statusFilter;
}

$allParams = array_merge($scopeParams, $barangayFilterParams, $statusParams);
$allTypes = str_repeat('i', count($scopeParams) + count($barangayFilterParams)) . ($statusParams !== [] ? 's' : '');

$followupRows = admin_fetch_all(
	"SELECT
		c.id AS child_id,
		c.child_code,
		c.first_name,
		c.middle_name,
		c.last_name,
		c.sex,
		c.birthdate,
		p.name AS parent_name,
		bg.name AS barangay,
		lm.measurement_date AS last_measurement,
		lm.weight_kg AS last_weight,
		lm.height_cm AS last_height,
		lm.waz, lm.haz, lm.whz,
		CASE WHEN lm.waz < -3 THEN 'SUW' WHEN lm.waz < -2 THEN 'MUW' WHEN lm.waz > 2 THEN 'Refer to WFL/H' ELSE 'Normal' END AS wfa_status,
		CASE WHEN lm.haz < -3 THEN 'SSt' WHEN lm.haz < -2 THEN 'MSt' WHEN lm.haz > 2 THEN 'Tall' ELSE 'Normal' END AS hfa_status,
		CASE WHEN lm.whz < -3 THEN 'SW' WHEN lm.whz < -2 THEN 'MW' WHEN lm.whz > 3 THEN 'Ob' WHEN lm.whz > 2 THEN 'OW' ELSE 'Normal' END AS wfh_status,
		lm.nutritional_status,
		a.id AS appointment_id,
		a.scheduled_at,
		a.status AS appointment_status,
		a.followup_track,
		a.followup_category,
		a.appointment_type,
		a.intervention_type,
		TIMESTAMPDIFF(MONTH, c.birthdate, CURDATE()) AS age_months
	 FROM appointments a
	 INNER JOIN children c ON c.id = a.child_id
	 INNER JOIN parents p ON p.id = c.parent_id
	 LEFT JOIN barangays bg ON bg.id = c.barangay_id
	 LEFT JOIN measurements lm ON lm.id = (
		SELECT m2.id FROM measurements m2
		WHERE m2.child_id = c.id
		ORDER BY m2.measurement_date DESC, m2.id DESC
		LIMIT 1
	 )
	 WHERE a.appointment_type = 'followup'
	   AND a.status IN ('pending','scheduled')
	   AND {$scope}
	   {$barangayFilterSql}
	   {$statusSql}
	 ORDER BY a.scheduled_at ASC
	 LIMIT 100",
	$allTypes,
	$allParams
);

$output = [];
foreach ($followupRows as $row) {
	$abnormal = followup_abnormal_codes(
		$row['wfa_status'] ?? null,
		$row['hfa_status'] ?? null,
		$row['wfh_status'] ?? null
	);

	$nextAction = 'Weigh and measure';
	if (!empty($row['followup_category'])) {
		$catParts = array_map('trim', explode('+', $row['followup_category']));
		$hasSevere = in_array(implode('', $catParts), ['SUW', 'SSt', 'SW', 'Ob'], true) ||
			in_array('SUW', $catParts, true) || in_array('SSt', $catParts, true) || in_array('SW', $catParts, true);
		$nextAction = $hasSevere ? 'Urgent follow-up required' : 'Routine follow-up measurement';
	}

	$output[] = [
		'child_id' => (int)$row['child_id'],
		'child_code' => (string)$row['child_code'],
		'name' => trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '')),
		'age_months' => (int)($row['age_months'] ?? 0),
		'sex' => (string)$row['sex'],
		'parent_name' => (string)$row['parent_name'],
		'barangay' => (string)($row['barangay'] ?? ''),
		'nutritional_status' => (string)($row['nutritional_status'] ?? 'Normal'),
		'wfa_status' => (string)($row['wfa_status'] ?? ''),
		'hfa_status' => (string)($row['hfa_status'] ?? ''),
		'wfh_status' => (string)($row['wfh_status'] ?? ''),
		'last_measurement' => (string)($row['last_measurement'] ?? ''),
		'last_weight' => $row['last_weight'] !== null ? (float)$row['last_weight'] : null,
		'last_height' => $row['last_height'] !== null ? (float)$row['last_height'] : null,
		'appointment_id' => (int)$row['appointment_id'],
		'scheduled_at' => (string)$row['scheduled_at'],
		'status' => (string)$row['appointment_status'],
		'followup_track' => (string)($row['followup_track'] ?? ''),
		'followup_category' => (string)($row['followup_category'] ?? ''),
		'intervention_type' => (string)($row['intervention_type'] ?? ''),
		'next_action' => $nextAction,
	];
}

$pendingCount = 0;
$scheduledCount = 0;
$completedCount = 0;
$referredCount = 0;

$countRows = admin_fetch_all(
	"SELECT a.status, COUNT(*) AS cnt
	 FROM appointments a
	 INNER JOIN children c ON c.id = a.child_id
	 WHERE a.appointment_type = 'followup'
	   AND {$scope}
	   {$barangayFilterSql}
	 GROUP BY a.status",
	 str_repeat('i', count($scopeParams) + count($barangayFilterParams)),
	array_merge($scopeParams, $barangayFilterParams)
);

foreach ($countRows as $cr) {
	$st = (string)$cr['status'];
	$cnt = (int)$cr['cnt'];
	match ($st) {
		'pending' => $pendingCount = $cnt,
		'scheduled' => $scheduledCount = $cnt,
		'completed' => $completedCount = $cnt,
		'referred' => $referredCount = $cnt,
		default => null,
	};
}

api_success([
	'children' => $output,
	'counts' => [
		'pending' => $pendingCount,
		'scheduled' => $scheduledCount,
		'completed' => $completedCount,
		'referred' => $referredCount,
	],
], 'Follow-up data loaded.');

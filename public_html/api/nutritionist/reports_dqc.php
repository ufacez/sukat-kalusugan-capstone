<?php

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth_middleware.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/nutritionist_helpers.php';

$user = api_require_staff_session();
api_require_method(['GET']);

$year = api_int($_GET['year'] ?? null, (int)date('Y'));
$barangayFilter = (int)($_GET['barangay_id'] ?? 0);

$scopeParams = [];
$scope = nutritionist_scope_fragment($user, 'c.barangay_id', $scopeParams);

$barangayFilterSql = '';
$barangayFilterParams = [];
if ($barangayFilter > 0) {
	$barangayFilterSql = ' AND c.barangay_id = ?';
	$barangayFilterParams[] = $barangayFilter;
}

$baseParams = array_merge($scopeParams, $barangayFilterParams);
$baseTypes = str_repeat('i', count($baseParams));

$totalRecords = admin_scalar(
	"SELECT COUNT(*) FROM children c WHERE 1=1 {$barangayFilterSql}",
	$baseTypes,
	$baseParams
);

$completeRecords = admin_scalar(
	"SELECT COUNT(DISTINCT c.id) FROM children c
	 INNER JOIN parents p ON p.id = c.parent_id
	 INNER JOIN measurements m ON m.child_id = c.id
	 WHERE c.sex IS NOT NULL
	   AND c.birthdate IS NOT NULL
	   AND c.first_name != ''
	   AND c.last_name != ''
	   AND p.name IS NOT NULL AND p.name != ''
	   AND m.height_cm IS NOT NULL AND m.weight_kg IS NOT NULL
	   AND {$scope}{$barangayFilterSql}",
	str_repeat('i', count($scopeParams) + count($barangayFilterParams)),
	array_merge($scopeParams, $barangayFilterParams)
);

$dqDuplicateRows = admin_fetch_all(
	"SELECT c1.first_name, c1.last_name, c1.birthdate, COUNT(*) AS cnt
	 FROM children c1
	 WHERE c1.first_name != '' AND c1.last_name != '' AND c1.birthdate IS NOT NULL
	 GROUP BY c1.first_name, c1.last_name, c1.birthdate
	 HAVING cnt > 1",
	'',
	[]
);

$dqMissingSex = admin_scalar(
	"SELECT COUNT(*) FROM children c WHERE c.sex IS NULL AND {$scope}{$barangayFilterSql}",
	$baseTypes,
	$baseParams
);

$dqMissingDob = admin_scalar(
	"SELECT COUNT(*) FROM children c WHERE c.birthdate IS NULL AND {$scope}{$barangayFilterSql}",
	$baseTypes,
	$baseParams
);

$dqNoParent = admin_scalar(
	"SELECT COUNT(*) FROM children c
	 LEFT JOIN parents p ON p.id = c.parent_id
	 WHERE (p.id IS NULL OR p.name IS NULL OR p.name = '')
	   AND {$scope}{$barangayFilterSql}",
	$baseTypes,
	$baseParams
);

$dqOverAge = admin_scalar(
	"SELECT COUNT(*) FROM children c
	 WHERE c.birthdate IS NOT NULL
	   AND TIMESTAMPDIFF(YEAR, c.birthdate, CURDATE()) > 4
	   AND {$scope}{$barangayFilterSql}",
	$baseTypes,
	$baseParams
);

$dqHeightNoWeight = admin_scalar(
	"SELECT COUNT(DISTINCT c.id) FROM children c
	 INNER JOIN measurements m ON m.child_id = c.id
	 WHERE m.height_cm IS NOT NULL AND m.weight_kg IS NULL
	   AND {$scope}{$barangayFilterSql}",
	$baseTypes,
	$baseParams
);

$dqWeightNoHeight = admin_scalar(
	"SELECT COUNT(DISTINCT c.id) FROM children c
	 INNER JOIN measurements m ON m.child_id = c.id
	 WHERE m.weight_kg IS NOT NULL AND m.height_cm IS NULL
	   AND {$scope}{$barangayFilterSql}",
	$baseTypes,
	$baseParams
);

$issues = [
	[
		'code' => 'duplicate_name_dob',
		'label' => 'Repeated name and birthdate',
		'description' => 'Children with the same first name, last name, and birthdate may be duplicate entries.',
		'count' => count($dqDuplicateRows),
		'severity' => count($dqDuplicateRows) > 0 ? 'warning' : 'ok',
	],
	[
		'code' => 'missing_sex',
		'label' => 'Missing sex',
		'description' => 'Children without a recorded sex field.',
		'count' => (int)$dqMissingSex,
		'severity' => (int)$dqMissingSex > 0 ? 'danger' : 'ok',
	],
	[
		'code' => 'missing_birthdate',
		'label' => 'Missing date of birth',
		'description' => 'Children without a recorded birthdate.',
		'count' => (int)$dqMissingDob,
		'severity' => (int)$dqMissingDob > 0 ? 'danger' : 'ok',
	],
	[
		'code' => 'no_parent',
		'label' => 'No parent or address information',
		'description' => 'Children with no linked parent record or empty parent name.',
		'count' => (int)$dqNoParent,
		'severity' => (int)$dqNoParent > 0 ? 'danger' : 'ok',
	],
	[
		'code' => 'over_59_months',
		'label' => 'Children older than 59 months',
		'description' => 'Children who have exceeded the 0-59 month eOPT Plus age range.',
		'count' => (int)$dqOverAge,
		'severity' => (int)$dqOverAge > 0 ? 'warning' : 'ok',
	],
	[
		'code' => 'height_no_weight',
		'label' => 'Height recorded but no weight',
		'description' => 'Measurements with height/length but missing weight data.',
		'count' => (int)$dqHeightNoWeight,
		'severity' => (int)$dqHeightNoWeight > 0 ? 'warning' : 'ok',
	],
	[
		'code' => 'weight_no_height',
		'label' => 'Weight recorded but no height/length',
		'description' => 'Measurements with weight but missing height/length data.',
		'count' => (int)$dqWeightNoHeight,
		'severity' => (int)$dqWeightNoHeight > 0 ? 'warning' : 'ok',
	],
];

$totalIssues = array_sum(array_column($issues, 'count'));

api_success([
	'total_records' => (int)$totalRecords,
	'complete_records' => (int)$completeRecords,
	'total_issues' => (int)$totalIssues,
	'issues' => $issues,
	'summary' => [
		'complete' => (int)$completeRecords,
		'duplicates' => count($dqDuplicateRows),
		'missing_info' => (int)($dqMissingSex + $dqMissingDob + $dqNoParent),
		'invalid' => (int)($dqOverAge),
		'needs_review' => (int)($dqHeightNoWeight + $dqWeightNoHeight),
	],
], 'Data quality check completed.');

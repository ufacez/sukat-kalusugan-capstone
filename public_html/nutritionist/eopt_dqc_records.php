<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/followup_scheduler.php';

$user = nutritionist_require_access();

$issue = (string)($_GET['issue'] ?? '');
$year = (int)($_GET['year'] ?? date('Y'));
$barangayFilter = (int)($_GET['barangay_id'] ?? 0);

$scopeParams = [];
$scope = nutritionist_scope_fragment($user, 'c.barangay_id', $scopeParams);

$barangayFilterSql = '';
$barangayFilterParams = [];
if ($barangayFilter > 0) {
	$barangayFilterSql = ' AND c.barangay_id = ?';
	$barangayFilterParams[] = $barangayFilter;
}

$backUrl = app_url('/nutritionist/eopt_reports.php') . '?' . http_build_query([
	'year' => $year,
	'barangay_id' => $barangayFilter,
]);

$records = [];
$issueTitle = 'Unknown Issue';
$issueDescription = '';

switch ($issue) {
	case 'duplicate_name_dob':
		$issueTitle = 'Children with Repeated Name and Birthdate';
		$issueDescription = 'These children share the same first name, last name, and birthdate and may be duplicate entries.';
		$records = admin_fetch_all(
			"SELECT c.id, c.child_code, c.first_name, c.middle_name, c.last_name, c.sex, c.birthdate,
				bg.name AS barangay, p.name AS parent_name
			 FROM children c
			 INNER JOIN parents p ON p.id = c.parent_id
			 LEFT JOIN barangays bg ON bg.id = c.barangay_id
			 WHERE (c.first_name, c.last_name, c.birthdate) IN (
				SELECT c2.first_name, c2.last_name, c2.birthdate
				FROM children c2
				WHERE c2.first_name != '' AND c2.last_name != '' AND c2.birthdate IS NOT NULL
				GROUP BY c2.first_name, c2.last_name, c2.birthdate
				HAVING COUNT(*) > 1
			 )
			 ORDER BY c.last_name, c.first_name",
			'',
			[]
		);
		break;

	case 'missing_sex':
		$issueTitle = 'Children with Missing Sex';
		$issueDescription = 'These children do not have a recorded sex field.';
		$records = admin_fetch_all(
			"SELECT c.id, c.child_code, c.first_name, c.middle_name, c.last_name, c.sex, c.birthdate,
				bg.name AS barangay, p.name AS parent_name
			 FROM children c
			 INNER JOIN parents p ON p.id = c.parent_id
			 LEFT JOIN barangays bg ON bg.id = c.barangay_id
			 WHERE c.sex IS NULL AND {$scope}{$barangayFilterSql}
			 ORDER BY c.last_name, c.first_name",
			str_repeat('i', count($scopeParams) + count($barangayFilterParams)),
			array_merge($scopeParams, $barangayFilterParams)
		);
		break;

	case 'missing_birthdate':
		$issueTitle = 'Children with Missing Date of Birth';
		$issueDescription = 'These children do not have a recorded birthdate.';
		$records = admin_fetch_all(
			"SELECT c.id, c.child_code, c.first_name, c.middle_name, c.last_name, c.sex, c.birthdate,
				bg.name AS barangay, p.name AS parent_name
			 FROM children c
			 INNER JOIN parents p ON p.id = c.parent_id
			 LEFT JOIN barangays bg ON bg.id = c.barangay_id
			 WHERE c.birthdate IS NULL AND {$scope}{$barangayFilterSql}
			 ORDER BY c.last_name, c.first_name",
			str_repeat('i', count($scopeParams) + count($barangayFilterParams)),
			array_merge($scopeParams, $barangayFilterParams)
		);
		break;

	case 'no_parent':
		$issueTitle = 'Children with No Parent or Address Information';
		$issueDescription = 'These children have no linked parent record or the parent name is empty.';
		$records = admin_fetch_all(
			"SELECT c.id, c.child_code, c.first_name, c.middle_name, c.last_name, c.sex, c.birthdate,
				bg.name AS barangay, p.name AS parent_name
			 FROM children c
			 LEFT JOIN parents p ON p.id = c.parent_id
			 LEFT JOIN barangays bg ON bg.id = c.barangay_id
			 WHERE (p.id IS NULL OR p.name IS NULL OR p.name = '')
			   AND {$scope}{$barangayFilterSql}
			 ORDER BY c.last_name, c.first_name",
			str_repeat('i', count($scopeParams) + count($barangayFilterParams)),
			array_merge($scopeParams, $barangayFilterParams)
		);
		break;

	case 'over_59_months':
		$issueTitle = 'Children Older Than 59 Months';
		$issueDescription = 'These children have exceeded the 0-59 month eOPT Plus age range and should be graduated from the monitoring list.';
		$records = admin_fetch_all(
			"SELECT c.id, c.child_code, c.first_name, c.middle_name, c.last_name, c.sex, c.birthdate,
				bg.name AS barangay, p.name AS parent_name,
				TIMESTAMPDIFF(MONTH, c.birthdate, CURDATE()) AS age_months
			 FROM children c
			 INNER JOIN parents p ON p.id = c.parent_id
			 LEFT JOIN barangays bg ON bg.id = c.barangay_id
			 WHERE c.birthdate IS NOT NULL
			   AND TIMESTAMPDIFF(YEAR, c.birthdate, CURDATE()) > 4
			   AND {$scope}{$barangayFilterSql}
			 ORDER BY c.last_name, c.first_name",
			str_repeat('i', count($scopeParams) + count($barangayFilterParams)),
			array_merge($scopeParams, $barangayFilterParams)
		);
		break;

	case 'height_no_weight':
		$issueTitle = 'Height Recorded but No Weight';
		$issueDescription = 'These children have a measurement with height/length but no weight recorded.';
		$records = admin_fetch_all(
			"SELECT DISTINCT c.id, c.child_code, c.first_name, c.middle_name, c.last_name, c.sex, c.birthdate,
				bg.name AS barangay, p.name AS parent_name
			 FROM children c
			 INNER JOIN parents p ON p.id = c.parent_id
			 LEFT JOIN barangays bg ON bg.id = c.barangay_id
			 INNER JOIN measurements m ON m.child_id = c.id
			 WHERE m.height_cm IS NOT NULL AND m.weight_kg IS NULL
			   AND {$scope}{$barangayFilterSql}
			 ORDER BY c.last_name, c.first_name",
			str_repeat('i', count($scopeParams) + count($barangayFilterParams)),
			array_merge($scopeParams, $barangayFilterParams)
		);
		break;

	case 'weight_no_height':
		$issueTitle = 'Weight Recorded but No Height/Length';
		$issueDescription = 'These children have a measurement with weight but no height/length recorded.';
		$records = admin_fetch_all(
			"SELECT DISTINCT c.id, c.child_code, c.first_name, c.middle_name, c.last_name, c.sex, c.birthdate,
				bg.name AS barangay, p.name AS parent_name
			 FROM children c
			 INNER JOIN parents p ON p.id = c.parent_id
			 LEFT JOIN barangays bg ON bg.id = c.barangay_id
			 INNER JOIN measurements m ON m.child_id = c.id
			 WHERE m.weight_kg IS NOT NULL AND m.height_cm IS NULL
			   AND {$scope}{$barangayFilterSql}
			 ORDER BY c.last_name, c.first_name",
			str_repeat('i', count($scopeParams) + count($barangayFilterParams)),
			array_merge($scopeParams, $barangayFilterParams)
		);
		break;

	default:
		admin_redirect($backUrl, ['notice' => 'Unknown issue code.', 'type' => 'error']);
		exit;
}

$actions = '<a class="admin-btn-secondary" href="' . nutritionist_e($backUrl) . '">Back to Reports</a>';

nutritionist_layout_start('DQC: ' . $issueTitle, $issueDescription, 'eopt_reports', $actions);
?>

<section class="nutritionist-panel" style="margin-bottom:20px;">
	<div class="admin-section-title" style="margin-bottom:4px;"><?php echo nutritionist_e($issueTitle); ?></div>
	<div class="admin-mini" style="margin-bottom:12px;"><?php echo nutritionist_e($issueDescription); ?> · <?php echo count($records); ?> record(s) found</div>

	<?php if ($records === []): ?>
		<div class="admin-mini" style="padding:24px;text-align:center;color:var(--admin-muted);">No records match this data quality issue. Great!</div>
	<?php else: ?>
		<div class="nutritionist-table-wrap" style="overflow-x:auto;">
			<table class="nutritionist-table" style="min-width:800px;">
				<thead>
					<tr>
						<th>No.</th>
						<th>Child Code</th>
						<th>Full Name</th>
						<th>Sex</th>
						<th>Birthdate</th>
						<th>Barangay</th>
						<th>Parent / Guardian</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($records as $i => $row): ?>
						<tr>
							<td><?php echo $i + 1; ?></td>
							<td><?php echo nutritionist_e((string)$row['child_code']); ?></td>
							<td>
								<div style="font-weight:600;"><?php echo nutritionist_e(trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? ''))); ?></div>
							</td>
							<td><?php echo nutritionist_e((string)($row['sex'] ?? '—')); ?></td>
							<td><?php echo nutritionist_e((string)($row['birthdate'] ?? '—')); ?></td>
							<td><?php echo nutritionist_e((string)($row['barangay'] ?? '')); ?></td>
							<td><?php echo nutritionist_e((string)($row['parent_name'] ?? '—')); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>

<?php
nutritionist_layout_end();

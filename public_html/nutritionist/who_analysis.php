<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/who_calculator.php';

$user = nutritionist_require_access();

$conn = get_db_connection();

/* ---------- filter parameters ---------- */
$filterDateFrom   = trim($_GET['date_from'] ?? '');
$filterDateTo     = trim($_GET['date_to'] ?? '');
$filterAgeGroup   = trim($_GET['age_group'] ?? '');
$filterSex        = trim($_GET['sex'] ?? '');
$filterBarangay   = trim($_GET['barangay'] ?? '');

/* ---------- barangay options (locked for nutritionists) ---------- */
$barangayParams = [];
$scopeBarangay = nutritionist_scope_fragment($user, 'c.barangay_id', $barangayParams);
$barangayOptions = admin_fetch_all(
	"SELECT id, name FROM barangays WHERE status = 'active' ORDER BY name ASC"
);

/* ---------- base query ---------- */
$sql = "SELECT
	c.id AS child_id,
	c.child_code,
	c.first_name,
	c.last_name,
	c.sex,
	c.birthdate,
	bg.id AS barangay_id,
	bg.name AS barangay,
	lm.id AS measurement_id,
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
 WHERE {$scopeBarangay}";

$types  = str_repeat('i', count($barangayParams));
$params = $barangayParams;

if ($filterBarangay !== '' && $filterBarangay !== 'all') {
	$sql .= " AND c.barangay_id = ?";
	$types .= 'i';
	$params[] = (int)$filterBarangay;
}
if ($filterDateFrom !== '') {
	$sql .= " AND lm.measurement_date >= ?";
	$types .= 's';
	$params[] = $filterDateFrom;
}
if ($filterDateTo !== '') {
	$sql .= " AND lm.measurement_date <= ?";
	$types .= 's';
	$params[] = $filterDateTo;
}
if ($filterSex !== '' && $filterSex !== 'all') {
	$sql .= " AND c.sex = ?";
	$types .= 's';
	$params[] = $filterSex;
}

$sql .= " ORDER BY c.last_name ASC, c.first_name ASC";
$rows = admin_fetch_all($sql, $types, $params);

/* ---------- age group helper ---------- */
function who_age_group_months(?string $birthdate): ?string
{
	if ($birthdate === null || $birthdate === '') return null;
	$age = doh_age_in_months($birthdate);
	if ($age === null) return null;
	if ($age < 6)  return '0-5';
	if ($age < 12) return '6-11';
	if ($age < 24) return '12-23';
	if ($age < 36) return '24-35';
	if ($age < 48) return '36-47';
	if ($age < 60) return '48-59';
	return '60+';
}

/* ---------- classify ---------- */
$analyzed = array_values(array_filter($rows, static fn(array $r): bool => $r['measurement_id'] !== null));
$total    = count($analyzed);

$normalCount   = 0;
$moderateCount = 0;
$severeCount   = 0;

/* WFA axis */
$wfaMUW = 0; $wfaSUW = 0; $wfaNormal = 0; $wfaRefer = 0;
/* HFA axis */
$hfaMSt = 0; $hfaSSt = 0; $hfaNormal = 0; $hfaTall = 0;
/* WFH axis */
$wfhMW = 0; $wfhSW = 0; $wfhOW = 0; $wfhOb = 0; $wfhNormal = 0;

/* Combined prevalence counts */
$prevWasted_MW = 0; $prevWasted_SW = 0; $prevWasted_Total = 0;
$prevStunted_MSt = 0; $prevStunted_SSt = 0; $prevStunted_Total = 0;
$prevStuntedObese_MStOb = 0; $prevStuntedObese_MStOW = 0; $prevStuntedObese_SstOb = 0; $prevStuntedObese_SstOW = 0; $prevStuntedObese_Total = 0;
$prevUnderweight_MUW = 0; $prevUnderweight_SUW = 0; $prevUnderweight_Total = 0;
$prevObese_OW = 0; $prevObese_Ob = 0; $prevObese_Total = 0;
$prevUwStunted_MUWMSt = 0; $prevUwStunted_MUWSSt = 0; $prevUwStunted_SUWMSt = 0; $prevUwStunted_SUWSSt = 0; $prevUwStunted_Total = 0;
$prevStuntedWasted_MStMW = 0; $prevStuntedWasted_MStSW = 0; $prevStuntedWasted_SstMW = 0; $prevStuntedWasted_SstSW = 0; $prevStuntedWasted_Total = 0;

$ageGroups = ['0-5','6-11','12-23','24-35','36-47','48-59'];
$ageGroupLabels = ['0-5 mo','6-11 mo','12-23 mo','24-35 mo','36-47 mo','48-59 mo'];
$ageData = [];
foreach ($ageGroups as $ag) {
	$ageData[$ag] = ['Normal' => 0, 'At Risk' => 0, 'Problems' => 0];
}
$sexCounts = ['Male' => 0, 'Female' => 0];

foreach ($analyzed as $row) {
	$status = strtolower(trim((string)($row['nutritional_status'] ?? 'normal')));
	$wfa = strtoupper(trim((string)($row['wfa_status'] ?? '')));
	$hfa = strtoupper(trim((string)($row['hfa_status'] ?? '')));
	$wfh = strtoupper(trim((string)($row['wfh_status'] ?? '')));

	/* overall */
	if (in_array($status, ['severely underweight','severely stunted','severely wasted','suw','sst','sw'], true)) {
		$severeCount++;
	} elseif (in_array($status, ['moderately underweight','moderately stunted','moderately wasted','muw','mst','mw','overweight','obese','ow','ob'], true)) {
		$moderateCount++;
	} else {
		$normalCount++;
	}

	/* WFA axis */
	$isMUW = str_contains($wfa, 'MUW');
	$isSUW = str_contains($wfa, 'SUW');
	$isWfaNormal = str_contains($wfa, 'NORMAL') || (!$isMUW && !$isSUW && !str_contains($wfa, 'REFER'));
	$isRefer = str_contains($wfa, 'REFER');
	if ($isSUW) $wfaSUW++;
	elseif ($isMUW) $wfaMUW++;
	elseif ($isRefer) $wfaRefer++;
	else $wfaNormal++;

	/* HFA axis */
	$isMSt = str_contains($hfa, 'MST');
	$isSSt = str_contains($hfa, 'SST');
	$isTall = str_contains($hfa, 'TALL');
	$isHfaNormal = str_contains($hfa, 'NORMAL') || (!$isMSt && !$isSSt && !$isTall);
	if ($isSSt) $hfaSSt++;
	elseif ($isMSt) $hfaMSt++;
	elseif ($isTall) $hfaTall++;
	else $hfaNormal++;

	/* WFH axis */
	$isMW = str_contains($wfh, 'MW');
	$isSW = str_contains($wfh, 'SW');
	$isOW = str_contains($wfh, 'OW');
	$isOb = str_contains($wfh, 'OB');
	$isWfhNormal = str_contains($wfh, 'NORMAL') || (!$isMW && !$isSW && !$isOW && !$isOb);
	if ($isSW) $wfhSW++;
	elseif ($isMW) $wfhMW++;
	elseif ($isOb) $wfhOb++;
	elseif ($isOW) $wfhOW++;
	else $wfhNormal++;

	/* ---- Prevalence combinations ---- */
	/* Wasted */
	if ($isMW) { $prevWasted_MW++; $prevWasted_Total++; }
	if ($isSW) { $prevWasted_SW++; $prevWasted_Total++; }

	/* Stunted */
	if ($isMSt) { $prevStunted_MSt++; $prevStunted_Total++; }
	if ($isSSt) { $prevStunted_SSt++; $prevStunted_Total++; }

	/* Stunted and/or Obese/Overweight */
	$soMatch = false;
	if ($isMSt && $isOb) { $prevStuntedObese_MStOb++; $soMatch = true; }
	if ($isMSt && $isOW) { $prevStuntedObese_MStOW++; $soMatch = true; }
	if ($isSSt && $isOb) { $prevStuntedObese_SstOb++; $soMatch = true; }
	if ($isSSt && $isOW) { $prevStuntedObese_SstOW++; $soMatch = true; }
	if ($soMatch) $prevStuntedObese_Total++;

	/* Underweight */
	if ($isMUW) { $prevUnderweight_MUW++; $prevUnderweight_Total++; }
	if ($isSUW) { $prevUnderweight_SUW++; $prevUnderweight_Total++; }

	/* Obese/Overweight */
	if ($isOW) { $prevObese_OW++; $prevObese_Total++; }
	if ($isOb) { $prevObese_Ob++; $prevObese_Total++; }

	/* Underweight and/or Stunted */
	$usMatch = false;
	if ($isMUW && $isMSt) { $prevUwStunted_MUWMSt++; $usMatch = true; }
	if ($isMUW && $isSSt) { $prevUwStunted_MUWSSt++; $usMatch = true; }
	if ($isSUW && $isMSt) { $prevUwStunted_SUWMSt++; $usMatch = true; }
	if ($isSUW && $isSSt) { $prevUwStunted_SUWSSt++; $usMatch = true; }
	if ($usMatch) $prevUwStunted_Total++;

	/* Stunted and/or Wasted */
	$swMatch = false;
	if ($isMSt && $isMW) { $prevStuntedWasted_MStMW++; $swMatch = true; }
	if ($isMSt && $isSW) { $prevStuntedWasted_MStSW++; $swMatch = true; }
	if ($isSSt && $isMW) { $prevStuntedWasted_SstMW++; $swMatch = true; }
	if ($isSSt && $isSW) { $prevStuntedWasted_SstSW++; $swMatch = true; }
	if ($swMatch) $prevStuntedWasted_Total++;

	/* age group */
	$ag = who_age_group_months($row['birthdate']);
	if ($ag !== null && isset($ageData[$ag])) {
		$st = strtolower(trim((string)($row['nutritional_status'] ?? 'normal')));
		if (in_array($st, ['severely underweight','severely stunted','severely wasted','suw','sst','sw'], true)) {
			$ageData[$ag]['Problems']++;
		} elseif (in_array($st, ['moderately underweight','moderately stunted','moderately wasted','muw','mst','mw','overweight','obese','ow','ob'], true)) {
			$ageData[$ag]['At Risk']++;
		} else {
			$ageData[$ag]['Normal']++;
		}
	}

	/* sex */
	$sex = strtolower(trim((string)($row['sex'] ?? '')));
	if ($sex === 'male' || $sex === 'm') $sexCounts['Male']++;
	elseif ($sex === 'female' || $sex === 'f') $sexCounts['Female']++;
}

/* percentages */
$pctNormal   = $total > 0 ? round(($normalCount / $total) * 100, 1) : 0;
$pctModerate = $total > 0 ? round(($moderateCount / $total) * 100, 1) : 0;
$pctSevere   = $total > 0 ? round(($severeCount / $total) * 100, 1) : 0;

$sexTotal = array_sum($sexCounts);
$sexPct = [];
foreach ($sexCounts as $k => $v) {
	$sexPct[$k] = $sexTotal > 0 ? round(($v / $sexTotal) * 100, 1) : 0;
}

/* prevalence helper */
function who_prev(array $data, int $total): array {
	$r = [];
	foreach ($data as $label => $count) {
		$r[$label] = [
			'count' => $count,
			'pct' => $total > 0 ? round(($count / $total) * 100, 1) : 0,
		];
	}
	return $r;
}

$actions = '<a class="admin-btn-secondary" href="' . nutritionist_e(app_url('/nutritionist/measurements.php')) . '">' . admin_action_icon('open') . ' Measurements</a>';

nutritionist_layout_start('WHO Analysis', 'Summary of nutritional status of assessed children based on WHO Child Growth Standards.', 'who_analysis', $actions);
?>

<!-- Filters -->
<section class="who-filters-bar">
	<div class="who-filter-group">
		<label class="who-filter-label">Barangay</label>
		<select id="whoFilterBarangay" class="admin-select">
			<option value="all">All Barangays</option>
			<?php foreach ($barangayOptions as $b): ?>
				<option value="<?php echo nutritionist_e((string)$b['id']); ?>" <?php echo $filterBarangay === (string)$b['id'] ? 'selected' : ''; ?>><?php echo nutritionist_e($b['name']); ?></option>
			<?php endforeach; ?>
		</select>
	</div>
	<div class="who-filter-group">
		<label class="who-filter-label">Date Range</label>
		<div class="who-date-range">
			<input type="date" id="whoFilterDateFrom" class="admin-field-input" value="<?php echo nutritionist_e($filterDateFrom); ?>">
			<span class="who-date-sep">—</span>
			<input type="date" id="whoFilterDateTo" class="admin-field-input" value="<?php echo nutritionist_e($filterDateTo); ?>">
		</div>
	</div>
	<div class="who-filter-group">
		<label class="who-filter-label">Age Group</label>
		<select id="whoFilterAgeGroup" class="admin-select">
			<option value="all">All Age Groups</option>
			<option value="0-5" <?php echo $filterAgeGroup === '0-5' ? 'selected' : ''; ?>>0–5 months</option>
			<option value="6-11" <?php echo $filterAgeGroup === '6-11' ? 'selected' : ''; ?>>6–11 months</option>
			<option value="12-23" <?php echo $filterAgeGroup === '12-23' ? 'selected' : ''; ?>>12–23 months</option>
			<option value="24-35" <?php echo $filterAgeGroup === '24-35' ? 'selected' : ''; ?>>24–35 months</option>
			<option value="36-47" <?php echo $filterAgeGroup === '36-47' ? 'selected' : ''; ?>>36–47 months</option>
			<option value="48-59" <?php echo $filterAgeGroup === '48-59' ? 'selected' : ''; ?>>48–59 months</option>
		</select>
	</div>
	<div class="who-filter-group">
		<label class="who-filter-label">Sex</label>
		<select id="whoFilterSex" class="admin-select">
			<option value="all" <?php echo $filterSex === 'all' ? 'selected' : ''; ?>>All</option>
			<option value="Male" <?php echo $filterSex === 'Male' ? 'selected' : ''; ?>>Male</option>
			<option value="Female" <?php echo $filterSex === 'Female' ? 'selected' : ''; ?>>Female</option>
		</select>
	</div>
	<div class="who-filter-actions">
		<button type="button" class="admin-btn-secondary" id="whoResetBtn">Reset</button>
		<button type="button" class="admin-btn" id="whoApplyBtn"><?php echo admin_action_icon('verify'); ?> Apply Filters</button>
	</div>
</section>

<!-- Summary Cards -->
<section class="admin-grid-cards who-stat-cards">
	<article class="admin-card who-stat-card">
		<div class="admin-card-row">
			<div class="admin-card-icon">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Total Assessed</div>
				<div class="admin-card-value"><?php echo number_format($total); ?></div>
				<div class="admin-card-meta"><span>100% of selected data</span></div>
			</div>
		</div>
	</article>
	<article class="admin-card who-stat-card">
		<div class="admin-card-row">
			<div class="admin-card-icon is-success">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Normal</div>
				<div class="admin-card-value"><?php echo number_format($normalCount); ?><span class="who-card-pct"><?php echo $pctNormal; ?>%</span></div>
				<div class="admin-card-meta"><span class="admin-card-trend is-up">within normal range</span></div>
			</div>
		</div>
	</article>
	<article class="admin-card who-stat-card">
		<div class="admin-card-row">
			<div class="admin-card-icon is-warn">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Moderate</div>
				<div class="admin-card-value"><?php echo number_format($moderateCount); ?><span class="who-card-pct"><?php echo $pctModerate; ?>%</span></div>
				<div class="admin-card-meta"><span class="admin-card-trend">mild to moderate risk</span></div>
			</div>
		</div>
	</article>
	<article class="admin-card who-stat-card">
		<div class="admin-card-row">
			<div class="admin-card-icon is-danger">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Severe</div>
				<div class="admin-card-value"><?php echo number_format($severeCount); ?><span class="who-card-pct"><?php echo $pctSevere; ?>%</span></div>
				<div class="admin-card-meta"><span class="admin-card-trend is-danger">severe risk</span></div>
			</div>
		</div>
	</article>
</section>

<!-- Nutritional Status Distribution (left) + Distribution by Age Group & Sex (right) -->
<section class="who-two-col">
	<!-- Left: Distribution -->
	<div class="who-panel">
		<div class="who-panel-head">
			<h2 class="admin-section-title">Nutritional Status Distribution</h2>
		</div>
		<div class="who-tab-row" id="whoDistTabs">
			<button class="who-tab is-active" data-axis="wfa">Weight-for-Age (WFA)</button>
			<button class="who-tab" data-axis="hfa">Height-for-Age (HFA)</button>
			<button class="who-tab" data-axis="wfh">Weight-for-Length/Height (WFL/H)</button>
		</div>

		<div class="who-tab-panel is-active" data-panel="wfa">
			<div class="who-dist-layout">
				<div class="who-pie-wrap"><canvas id="whoPieWfa" width="180" height="180"></canvas></div>
				<div class="who-classification-table">
					<div class="who-ct-head"><span></span><span>Classification</span><span>Count</span><span>Percentage</span></div>
					<div class="who-ct-row"><span class="who-dot is-green"></span><span>Normal</span><span><?php echo $wfaNormal; ?></span><span><?php echo $total > 0 ? round(($wfaNormal/$total)*100,1) : 0; ?>%</span></div>
					<div class="who-ct-row"><span class="who-dot is-yellow"></span><span>MUW</span><span><?php echo $wfaMUW; ?></span><span><?php echo $total > 0 ? round(($wfaMUW/$total)*100,1) : 0; ?>%</span></div>
					<div class="who-ct-row"><span class="who-dot is-red"></span><span>SUW</span><span><?php echo $wfaSUW; ?></span><span><?php echo $total > 0 ? round(($wfaSUW/$total)*100,1) : 0; ?>%</span></div>
					<div class="who-ct-row"><span class="who-dot is-blue"></span><span>Refer to WFL/H</span><span><?php echo $wfaRefer; ?></span><span><?php echo $total > 0 ? round(($wfaRefer/$total)*100,1) : 0; ?>%</span></div>
					<div class="who-ct-total"><span></span><span>Total</span><span><?php echo $total; ?></span><span>100%</span></div>
				</div>
			</div>
			<div class="who-info-bar">
				<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/></svg>
				<span>WFA: MUW (Mildly Underweight), SUW (Severely Underweight)</span>
			</div>
		</div>

		<div class="who-tab-panel" data-panel="hfa">
			<div class="who-dist-layout">
				<div class="who-pie-wrap"><canvas id="whoPieHfa" width="180" height="180"></canvas></div>
				<div class="who-classification-table">
					<div class="who-ct-head"><span></span><span>Classification</span><span>Count</span><span>Percentage</span></div>
					<div class="who-ct-row"><span class="who-dot is-green"></span><span>Normal</span><span><?php echo $hfaNormal; ?></span><span><?php echo $total > 0 ? round(($hfaNormal/$total)*100,1) : 0; ?>%</span></div>
					<div class="who-ct-row"><span class="who-dot is-blue"></span><span>Tall</span><span><?php echo $hfaTall; ?></span><span><?php echo $total > 0 ? round(($hfaTall/$total)*100,1) : 0; ?>%</span></div>
					<div class="who-ct-row"><span class="who-dot is-yellow"></span><span>MSt</span><span><?php echo $hfaMSt; ?></span><span><?php echo $total > 0 ? round(($hfaMSt/$total)*100,1) : 0; ?>%</span></div>
					<div class="who-ct-row"><span class="who-dot is-red"></span><span>SSt</span><span><?php echo $hfaSSt; ?></span><span><?php echo $total > 0 ? round(($hfaSSt/$total)*100,1) : 0; ?>%</span></div>
					<div class="who-ct-total"><span></span><span>Total</span><span><?php echo $total; ?></span><span>100%</span></div>
				</div>
			</div>
			<div class="who-info-bar">
				<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/></svg>
				<span>HFA: MSt (Moderately Stunted), SSt (Severely Stunted), Tall (above +2 SD)</span>
			</div>
		</div>

		<div class="who-tab-panel" data-panel="wfh">
			<div class="who-dist-layout">
				<div class="who-pie-wrap"><canvas id="whoPieWfh" width="180" height="180"></canvas></div>
				<div class="who-classification-table">
					<div class="who-ct-head"><span></span><span>Classification</span><span>Count</span><span>Percentage</span></div>
					<div class="who-ct-row"><span class="who-dot is-green"></span><span>Normal</span><span><?php echo $wfhNormal; ?></span><span><?php echo $total > 0 ? round(($wfhNormal/$total)*100,1) : 0; ?>%</span></div>
					<div class="who-ct-row"><span class="who-dot is-yellow"></span><span>MW</span><span><?php echo $wfhMW; ?></span><span><?php echo $total > 0 ? round(($wfhMW/$total)*100,1) : 0; ?>%</span></div>
					<div class="who-ct-row"><span class="who-dot is-red"></span><span>SW</span><span><?php echo $wfhSW; ?></span><span><?php echo $total > 0 ? round(($wfhSW/$total)*100,1) : 0; ?>%</span></div>
					<div class="who-ct-row"><span class="who-dot is-orange"></span><span>OW</span><span><?php echo $wfhOW; ?></span><span><?php echo $total > 0 ? round(($wfhOW/$total)*100,1) : 0; ?>%</span></div>
					<div class="who-ct-row"><span class="who-dot is-orange-dark"></span><span>Ob</span><span><?php echo $wfhOb; ?></span><span><?php echo $total > 0 ? round(($wfhOb/$total)*100,1) : 0; ?>%</span></div>
					<div class="who-ct-total"><span></span><span>Total</span><span><?php echo $total; ?></span><span>100%</span></div>
				</div>
			</div>
			<div class="who-info-bar">
				<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/></svg>
				<span>WFL/H: MW (Moderate Wasting), SW (Severe Wasting), OW (Overweight), Ob (Obese)</span>
			</div>
		</div>
	</div>

	<!-- Right: Age Group + Sex -->
	<div class="who-panel who-right-col">
		<div class="who-panel-head">
			<h2 class="admin-section-title">Distribution by Age Group & Sex</h2>
		</div>
		<div class="who-trend-legend">
			<span class="who-legend-item"><span class="who-legend-dot" style="background:#22c55e"></span> Normal</span>
			<span class="who-legend-item"><span class="who-legend-dot" style="background:#f59e0b"></span> At Risk</span>
			<span class="who-legend-item"><span class="who-legend-dot" style="background:#ef4444"></span> Problems</span>
		</div>
		<div class="who-age-sex-row">
			<div class="who-bar-chart-wrap">
				<canvas id="whoAgeBarChart"></canvas>
			</div>
			<div class="who-sex-block">
				<div class="who-sex-donut-wrap">
					<canvas id="whoSexPie" width="120" height="120"></canvas>
					<div class="who-sex-center">
						<span class="who-sex-total"><?php echo number_format($sexTotal); ?></span>
						<span class="who-sex-label">Total</span>
					</div>
				</div>
				<div class="who-sex-legend">
					<div class="who-sex-row"><span class="who-dot is-blue"></span> Male: <?php echo $sexCounts['Male']; ?> (<?php echo $sexPct['Male']; ?>%)</div>
					<div class="who-sex-row"><span class="who-dot is-pink"></span> Female: <?php echo $sexCounts['Female']; ?> (<?php echo $sexPct['Female']; ?>%)</div>
				</div>
			</div>
		</div>
	</div>
</section>

<!-- Prevalence (single panel with dropdown) -->
<section class="who-panel who-prevalence-panel">
	<div class="who-panel-head">
		<h2 class="admin-section-title" id="whoPrevTitle">Prevalence of Wasted 0-59-Month-Old Children</h2>
		<select class="admin-select" id="whoPrevSelect">
			<option value="wasted">Wasted</option>
			<option value="stunted">Stunted</option>
			<option value="stuntObese">Stunted and/or Obese/Overweight</option>
			<option value="underweight">Underweight</option>
			<option value="obese">Obese/Overweight</option>
			<option value="uwStunt">Underweight and/or Stunted</option>
			<option value="stuntWaste">Stunted and/or Wasted</option>
		</select>
	</div>
	<div class="who-prev-chart-wrap">
		<canvas id="whoPrevChart"></canvas>
	</div>
</section>

<script>
window.WHO_DATA = {
	wfa: { n:<?php echo $wfaNormal; ?>, muw:<?php echo $wfaMUW; ?>, suw:<?php echo $wfaSUW; ?>, ref:<?php echo $wfaRefer; ?> },
	hfa: { n:<?php echo $hfaNormal; ?>, mst:<?php echo $hfaMSt; ?>, sst:<?php echo $hfaSSt; ?>, tall:<?php echo $hfaTall; ?> },
	wfh: { n:<?php echo $wfhNormal; ?>, mw:<?php echo $wfhMW; ?>, sw:<?php echo $wfhSW; ?>, ow:<?php echo $wfhOW; ?>, ob:<?php echo $wfhOb; ?> },
	ageGroups: <?php echo json_encode($ageGroupLabels); ?>,
	ageData: {
		normal: <?php echo json_encode(array_map(fn($g) => $ageData[$g]['Normal'], $ageGroups)); ?>,
		risk: <?php echo json_encode(array_map(fn($g) => $ageData[$g]['At Risk'], $ageGroups)); ?>,
		problems: <?php echo json_encode(array_map(fn($g) => $ageData[$g]['Problems'], $ageGroups)); ?>
	},
	sex: { male:<?php echo $sexCounts['Male']; ?>, female:<?php echo $sexCounts['Female']; ?> },
	total: <?php echo $total; ?>,
	prev: {
		wasted: [
			{ label:'Moderately Wasted\n(MW/MAM)', count:<?php echo $prevWasted_MW; ?>, color:'#f59e0b' },
			{ label:'Severely Wasted\n(SW/SAM)', count:<?php echo $prevWasted_SW; ?>, color:'#ef4444' },
			{ label:'Wasted\n(MW or SW)', count:<?php echo $prevWasted_Total; ?>, color:'#f97316' }
		],
		stunted: [
			{ label:'Moderately Stunted\n(MSt)', count:<?php echo $prevStunted_MSt; ?>, color:'#f59e0b' },
			{ label:'Severely Stunted\n(SSt)', count:<?php echo $prevStunted_SSt; ?>, color:'#ef4444' },
			{ label:'Stunted\n(MSt or SSt)', count:<?php echo $prevStunted_Total; ?>, color:'#f97316' }
		],
		stuntObese: [
			{ label:'MSt & Ob', count:<?php echo $prevStuntedObese_MStOb; ?>, color:'#2563eb' },
			{ label:'MSt & OW', count:<?php echo $prevStuntedObese_MStOW; ?>, color:'#2563eb' },
			{ label:'SSt & Ob', count:<?php echo $prevStuntedObese_SstOb; ?>, color:'#2563eb' },
			{ label:'SSt & OW', count:<?php echo $prevStuntedObese_SstOW; ?>, color:'#2563eb' },
			{ label:'Stunted and/or\nOverweight', count:<?php echo $prevStuntedObese_Total; ?>, color:'#ef4444' }
		],
		underweight: [
			{ label:'Moderately Underweight\n(MUW)', count:<?php echo $prevUnderweight_MUW; ?>, color:'#f59e0b' },
			{ label:'Severely Underweight\n(SUW)', count:<?php echo $prevUnderweight_SUW; ?>, color:'#ef4444' },
			{ label:'Underweight\n(MUW or SUW)', count:<?php echo $prevUnderweight_Total; ?>, color:'#f97316' }
		],
		obese: [
			{ label:'Overweight', count:<?php echo $prevObese_OW; ?>, color:'#f59e0b' },
			{ label:'Obese', count:<?php echo $prevObese_Ob; ?>, color:'#7c2d12' },
			{ label:'OW and/or Ob', count:<?php echo $prevObese_Total; ?>, color:'#ef4444' }
		],
		uwStunt: [
			{ label:'MUW & MSt', count:<?php echo $prevUwStunted_MUWMSt; ?>, color:'#2563eb' },
			{ label:'MUW & SSt', count:<?php echo $prevUwStunted_MUWSSt; ?>, color:'#2563eb' },
			{ label:'SUW & MSt', count:<?php echo $prevUwStunted_SUWMSt; ?>, color:'#2563eb' },
			{ label:'SUW & SSt', count:<?php echo $prevUwStunted_SUWSSt; ?>, color:'#2563eb' },
			{ label:'Underweight\nand/or Stunted', count:<?php echo $prevUwStunted_Total; ?>, color:'#ef4444' }
		],
		stuntWaste: [
			{ label:'MSt & MW', count:<?php echo $prevStuntedWasted_MStMW; ?>, color:'#2563eb' },
			{ label:'MSt & SW', count:<?php echo $prevStuntedWasted_MStSW; ?>, color:'#2563eb' },
			{ label:'SSt & MW', count:<?php echo $prevStuntedWasted_SstMW; ?>, color:'#2563eb' },
			{ label:'SSt & SW', count:<?php echo $prevStuntedWasted_SstSW; ?>, color:'#2563eb' },
			{ label:'Stunted and/or\nWasted', count:<?php echo $prevStuntedWasted_Total; ?>, color:'#ef4444' }
		]
	}
};
</script>

<script>
(function(){
  var D = window.WHO_DATA;
  if (!D) return;

  /* ---- Helpers ---- */
  function getCSS(v){ return getComputedStyle(document.documentElement).getPropertyValue(v).trim(); }
  function setupCanvas(canvas, w, h){
    var dpr = window.devicePixelRatio || 1;
    canvas.width = w * dpr;
    canvas.height = h * dpr;
    canvas.style.width = w + 'px';
    canvas.style.height = h + 'px';
    var ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);
    return ctx;
  }
  function hexToRgba(hex, a){
    var r = parseInt(hex.slice(1,3),16), g = parseInt(hex.slice(3,5),16), b = parseInt(hex.slice(5,7),16);
    return 'rgba('+r+','+g+','+b+','+a+')';
  }
  function lerp(a,b,t){ return a + (b - a) * t; }
  function easeOutCubic(t){ return 1 - Math.pow(1 - t, 3); }

  /* ---- Tab switching ---- */
  document.querySelectorAll('#whoDistTabs').forEach(function(row){
    row.addEventListener('click', function(e){
      var tab = e.target.closest('.who-tab');
      if (!tab) return;
      row.querySelectorAll('.who-tab').forEach(function(t){ t.classList.remove('is-active'); });
      tab.classList.add('is-active');
      row.parentElement.querySelectorAll('.who-tab-panel').forEach(function(p){
        p.classList.toggle('is-active', p.dataset.panel === tab.dataset.axis);
      });
    });
  });

  /* ====================================================================
   * ANIMATED DONUT CHART
   * Smooth entrance animation, glass center, soft shadows
   * ==================================================================== */
  function animateDonut(canvasId, slices, colors, opts){
    opts = opts || {};
    var canvas = document.getElementById(canvasId);
    if (!canvas) return;
    var size = opts.size || 180;
    var total = slices.reduce(function(a,b){ return a+b; }, 0);
    if (total === 0) return;
    var cx = size/2, cy = size/2, r = size/2 - 10;
    var hole = opts.hole || 0.58;
    var duration = 800;
    var start = null;

    function frame(ts){
      if (!start) start = ts;
      var progress = Math.min((ts - start) / duration, 1);
      var ease = easeOutCubic(progress);
      var ctx = setupCanvas(canvas, size, size);
      ctx.clearRect(0, 0, size, size);

      /* soft glow behind donut */
      ctx.save();
      ctx.shadowColor = 'rgba(0,0,0,0.08)';
      ctx.shadowBlur = 16;
      ctx.shadowOffsetY = 4;
      ctx.beginPath();
      ctx.arc(cx, cy, r + 4, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(0,0,0,0)';
      ctx.fill();
      ctx.restore();

      var sliceAngle = 0;
      var drawnAngle = ease * Math.PI * 2;
      var arcStart = -Math.PI / 2;

      slices.forEach(function(val, i){
        if (val === 0 || drawnAngle <= 0) { sliceAngle += 0; return; }
        var angle = (val / total) * Math.PI * 2;
        var drawAngle = Math.min(angle, Math.max(0, drawnAngle - sliceAngle));
        if (drawAngle <= 0) { sliceAngle += angle; return; }

        var sa = arcStart;
        var ea = arcStart + drawAngle;

        /* gradient per slice */
        var grad = ctx.createRadialGradient(cx, cy, r * hole, cx, cy, r);
        grad.addColorStop(0, hexToRgba(colors[i], 0.7));
        grad.addColorStop(1, hexToRgba(colors[i], 1));

        ctx.beginPath();
        ctx.arc(cx, cy, r, sa, ea);
        ctx.arc(cx, cy, r * hole, ea, sa, true);
        ctx.closePath();
        ctx.fillStyle = grad;
        ctx.fill();

        sliceAngle += angle;
        arcStart += angle;
      });

      /* glass center */
      var cg = ctx.createRadialGradient(cx, cy - r * 0.1, 0, cx, cy, r * hole);
      cg.addColorStop(0, 'rgba(255,255,255,0.12)');
      cg.addColorStop(0.5, 'rgba(255,255,255,0.06)');
      cg.addColorStop(1, 'rgba(0,0,0,0)');
      ctx.beginPath();
      ctx.arc(cx, cy, r * hole - 1, 0, Math.PI * 2);
      ctx.fillStyle = cg;
      ctx.fill();

      /* inner ring */
      ctx.beginPath();
      ctx.arc(cx, cy, r * hole, 0, Math.PI * 2);
      ctx.strokeStyle = getCSS('--admin-border') || 'rgba(0,0,0,0.06)';
      ctx.lineWidth = 1;
      ctx.stroke();

      if (progress < 1) requestAnimationFrame(frame);
    }
    requestAnimationFrame(frame);
  }

  animateDonut('whoPieWfa', [D.wfa.n, D.wfa.muw, D.wfa.suw, D.wfa.ref], ['#22c55e','#f59e0b','#ef4444','#3b82f6']);
  animateDonut('whoPieHfa', [D.hfa.n, D.hfa.tall, D.hfa.mst, D.hfa.sst], ['#22c55e','#3b82f6','#f59e0b','#ef4444']);
  animateDonut('whoPieWfh', [D.wfh.n, D.wfh.mw, D.wfh.sw, D.wfh.ow, D.wfh.ob], ['#22c55e','#f59e0b','#ef4444','#f97316','#ea580c']);
  animateDonut('whoSexPie', [D.sex.male, D.sex.female], ['#3b82f6','#ec4899'], { size: 120, hole: 0.62 });

  /* ====================================================================
   * ANIMATED AGE GROUP BAR CHART
   * Rounded bars with gradients, smooth height animation, hover tooltips
   * ==================================================================== */
  var ageBarHover = { idx: -1, bar: -1 };

  function renderAgeBarChart(animPct){
    animPct = animPct === undefined ? 1 : animPct;
    var canvas = document.getElementById('whoAgeBarChart');
    if (!canvas) return;
    var rect = canvas.parentElement.getBoundingClientRect();
    var w = rect.width, h = 260;
    var ctx = setupCanvas(canvas, w, h);
    var labels = D.ageGroups;
    var nD = D.ageData.normal, rD = D.ageData.risk, pD = D.ageData.problems;
    var allV = nD.concat(rD).concat(pD);
    var maxV = Math.max.apply(null, allV.concat([1]));
    maxV = Math.ceil(maxV * 1.15);

    var padL = 40, padR = 12, padT = 16, padB = 58;
    var cW = w - padL - padR, cH = h - padT - padB;
    var gCount = labels.length;
    var gW = cW / gCount;
    var barW = Math.min(gW * 0.24, 22);
    var gap = 3;

    /* subtle grid */
    ctx.strokeStyle = hexToRgba(getCSS('--admin-muted') || '#94a3b8', 0.12);
    ctx.lineWidth = 1;
    for (var g = 0; g <= 4; g++){
      var gy = padT + cH - (g/4)*cH;
      ctx.beginPath(); ctx.setLineDash([4,4]); ctx.moveTo(padL, gy); ctx.lineTo(w-padR, gy); ctx.stroke();
      ctx.setLineDash([]);
      ctx.fillStyle = getCSS('--admin-muted') || '#94a3b8';
      ctx.font = '10px Inter, sans-serif';
      ctx.textAlign = 'right';
      ctx.fillText(Math.round(maxV*g/4), padL-6, gy+3);
    }

    var colors = ['#22c55e','#f59e0b','#ef4444'];
    var hoverInfo = null;

    labels.forEach(function(label, i){
      var cx = padL + gW*i + gW/2;
      var ds = [nD[i], rD[i], pD[i]];
      var tw = ds.length * barW + (ds.length-1)*gap;
      var sx = cx - tw/2;
      ds.forEach(function(val, bi){
        var bx = sx + bi*(barW+gap);
        var bh = maxV > 0 ? (val/maxV)*cH * animPct : 0;
        var by = padT + cH - bh;
        var isHover = ageBarHover.idx === i && ageBarHover.bar === bi;

        /* bar shadow */
        ctx.save();
        ctx.shadowColor = hexToRgba(colors[bi], 0.3);
        ctx.shadowBlur = isHover ? 12 : 4;
        ctx.shadowOffsetY = 2;

        /* gradient */
        var grad = ctx.createLinearGradient(0, by, 0, padT + cH);
        grad.addColorStop(0, isHover ? hexToRgba(colors[bi], 1) : colors[bi]);
        grad.addColorStop(1, isHover ? hexToRgba(colors[bi], 0.75) : hexToRgba(colors[bi], 0.85));

        ctx.fillStyle = grad;
        ctx.beginPath();
        ctx.roundRect(bx, by, barW, bh, [4,4,0,0]);
        ctx.fill();
        ctx.restore();

        if (isHover) hoverInfo = { x: bx + barW/2, y: by - 10, val: val, label: ['Normal','At Risk','Problems'][bi], color: colors[bi] };
      });

      /* x-axis label — straight, two-line for legibility */
      ctx.fillStyle = getCSS('--admin-text') || '#1e293b';
      ctx.font = '600 11px Inter, sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'top';
      /* split "0-5 mo" into two lines: "0-5" / "mo" */
      var parts = label.split(' ');
      if (parts.length > 1) {
        ctx.fillText(parts[0], cx, h - 40);
        ctx.fillStyle = getCSS('--admin-muted') || '#94a3b8';
        ctx.font = '500 10px Inter, sans-serif';
        ctx.fillText(parts[1], cx, h - 26);
      } else {
        ctx.fillText(label, cx, h - 32);
      }
    });

    return hoverInfo;
  }

  /* animate bars on load */
  var ageAnimStart = null;
  function ageBarLoop(ts){
    if (!ageAnimStart) ageAnimStart = ts;
    var pct = Math.min((ts - ageAnimStart) / 700, 1);
    renderAgeBarChart(easeOutCubic(pct));
    if (pct < 1) requestAnimationFrame(ageBarLoop);
  }
  requestAnimationFrame(ageBarLoop);

  /* age bar hover tooltip */
  (function(){
    var canvas = document.getElementById('whoAgeBarChart');
    if (!canvas) return;
    var tooltip = document.createElement('div');
    tooltip.className = 'who-chart-tooltip';
    canvas.parentElement.style.position = 'relative';
    canvas.parentElement.appendChild(tooltip);

    canvas.addEventListener('mousemove', function(e){
      var rect = canvas.getBoundingClientRect();
      var mx = e.clientX - rect.left, my = e.clientY - rect.top;
      var labels = D.ageGroups;
      var nD = D.ageData.normal, rD = D.ageData.risk, pD = D.ageData.problems;
      var allV = nD.concat(rD).concat(pD);
      var maxV = Math.max.apply(null, allV.concat([1]));
      maxV = Math.ceil(maxV * 1.15);
      var padL = 40, padR = 12, padT = 16, padB = 58;
      var cW = rect.width - padL - padR, cH = 260 - padT - padB;
      var gW = cW / labels.length;
      var barW = Math.min(gW * 0.24, 22);
      var gap = 3;

      var found = false;
      labels.forEach(function(label, i){
        var cx = padL + gW*i + gW/2;
        var ds = [nD[i], rD[i], pD[i]];
        var tw = ds.length * barW + (ds.length-1)*gap;
        var sx = cx - tw/2;
        ds.forEach(function(val, bi){
          var bx = sx + bi*(barW+gap);
          var bh = maxV > 0 ? (val/maxV)*cH : 0;
          var by = padT + cH - bh;
          if (mx >= bx && mx <= bx+barW && my >= by && my <= padT+cH){
            var names = ['Normal','At Risk','Problems'];
            tooltip.innerHTML = '<strong>' + label + '</strong><br>' + names[bi] + ': <strong>' + val + '</strong>';
            tooltip.style.left = (bx + barW/2) + 'px';
            tooltip.style.top = (by - 10) + 'px';
            tooltip.classList.add('is-visible');
            ageBarHover = { idx: i, bar: bi };
            found = true;
          }
        });
      });
      if (!found){
        tooltip.classList.remove('is-visible');
        ageBarHover = { idx: -1, bar: -1 };
        renderAgeBarChart(1);
      }
    });
    canvas.addEventListener('mouseleave', function(){
      tooltip.classList.remove('is-visible');
      ageBarHover = { idx: -1, bar: -1 };
      renderAgeBarChart(1);
    });
  })();

  /* ====================================================================
   * ANIMATED PREVALENCE BAR CHART
   * Gradient bars, hover tooltip, smooth entrance
   * ==================================================================== */
  var prevTitles = {
    wasted: 'Prevalence of Wasted 0-59-Month-Old Children',
    stunted: 'Prevalence of Stunted 0-59-Month-Old Children',
    stuntObese: 'Prevalence of Stunted and/or Obese/Overweight 0-59-Month-Old Children',
    underweight: 'Prevalence of Underweight 0-59-Month-Old Children',
    obese: 'Prevalence of Obese/Overweight 0-59-Month-Old Children',
    uwStunt: 'Prevalence of Underweight and/or Stunted 0-59-Month-Old Children',
    stuntWaste: 'Prevalence of Stunted and/or Wasted 0-59-Month-Old Children'
  };

  var prevHover = -1;

  function renderPrevChart(key, animPct){
    animPct = animPct === undefined ? 1 : animPct;
    var canvas = document.getElementById('whoPrevChart');
    if (!canvas) return;
    var items = D.prev[key];
    var total = D.total;
    var rect = canvas.parentElement.getBoundingClientRect();
    var w = rect.width, h = 300;
    var ctx = setupCanvas(canvas, w, h);

    var maxPct = 100;
    var padL = 46, padR = 14, padT = 24, padB = 80;
    var cW = w - padL - padR, cH = h - padT - padB;
    var barCount = items.length;
    var groupW = cW / barCount;
    var barW = Math.min(groupW * 0.5, 54);

    /* subtle grid */
    ctx.strokeStyle = hexToRgba(getCSS('--admin-muted') || '#94a3b8', 0.12);
    ctx.lineWidth = 1;
    for (var g = 0; g <= 5; g++){
      var gy = padT + cH - (g/5)*cH;
      ctx.beginPath(); ctx.setLineDash([4,4]); ctx.moveTo(padL, gy); ctx.lineTo(w-padR, gy); ctx.stroke();
      ctx.setLineDash([]);
      ctx.fillStyle = getCSS('--admin-muted') || '#94a3b8';
      ctx.font = '10px Inter, sans-serif';
      ctx.textAlign = 'right';
      ctx.fillText((maxPct*g/5).toFixed(0) + '%', padL-6, gy+3);
    }

    items.forEach(function(it, i){
      var pct = total > 0 ? (it.count / total) * 100 : 0;
      var cx = padL + groupW*i + groupW/2;
      var bh = maxPct > 0 ? (pct / maxPct) * cH * animPct : 0;
      var bx = cx - barW/2;
      var by = padT + cH - bh;
      var isHover = prevHover === i;

      /* shadow */
      ctx.save();
      ctx.shadowColor = hexToRgba(it.color, 0.3);
      ctx.shadowBlur = isHover ? 14 : 5;
      ctx.shadowOffsetY = 3;

      /* gradient bar */
      var grad = ctx.createLinearGradient(0, by, 0, padT + cH);
      grad.addColorStop(0, it.color);
      grad.addColorStop(1, hexToRgba(it.color, isHover ? 0.6 : 0.75));
      ctx.fillStyle = grad;
      ctx.beginPath();
      ctx.roundRect(bx, by, barW, bh, [5,5,0,0]);
      ctx.fill();
      ctx.restore();

      /* top label */
      ctx.fillStyle = isHover ? it.color : (getCSS('--admin-text') || '#1e293b');
      ctx.font = (isHover ? 'bold ' : '') + '10px Inter, sans-serif';
      ctx.textAlign = 'center';
      if (animPct >= 0.95) ctx.fillText(it.count + ' · ' + pct.toFixed(1) + '%', cx, by - 10);

      /* x label — straight, larger */
      ctx.fillStyle = getCSS('--admin-text') || '#1e293b';
      ctx.font = '600 12px Inter, sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'top';
      var lines = it.label.split('\n');
      var lineH = 14;
      var totalH = lines.length * lineH;
      var startY = padT + cH + 16 - (totalH - lineH) / 2;
      lines.forEach(function(line, li){
        ctx.fillText(line, cx, startY + li * lineH);
      });
    });

    document.getElementById('whoPrevTitle').textContent = prevTitles[key] || '';
  }

  /* prevalence entrance animation */
  var prevAnimKey = 'wasted';
  var prevAnimStart = null;
  function prevBarLoop(ts){
    if (!prevAnimStart) prevAnimStart = ts;
    var pct = Math.min((ts - prevAnimStart) / 700, 1);
    renderPrevChart(prevAnimKey, easeOutCubic(pct));
    if (pct < 1) requestAnimationFrame(prevBarLoop);
  }
  requestAnimationFrame(prevBarLoop);

  document.getElementById('whoPrevSelect').addEventListener('change', function(){
    prevAnimKey = this.value;
    prevAnimStart = null;
    prevHover = -1;
    requestAnimationFrame(prevBarLoop);
  });

  /* prevalence hover tooltip */
  (function(){
    var canvas = document.getElementById('whoPrevChart');
    if (!canvas) return;
    var tooltip = document.createElement('div');
    tooltip.className = 'who-chart-tooltip';
    canvas.parentElement.appendChild(tooltip);

    canvas.addEventListener('mousemove', function(e){
      var rect = canvas.getBoundingClientRect();
      var mx = e.clientX - rect.left, my = e.clientY - rect.top;
      var items = D.prev[prevAnimKey];
      var total = D.total;
      var padL = 46, padR = 14, padT = 24, padB = 80;
      var cW = rect.width - padL - padR, cH = 300 - padT - padB;
      var groupW = cW / items.length;
      var barW = Math.min(groupW * 0.5, 54);

      var found = false;
      items.forEach(function(it, i){
        var pct = total > 0 ? (it.count / total) * 100 : 0;
        var cx = padL + groupW*i + groupW/2;
        var bh = (pct / 100) * cH;
        var bx = cx - barW/2;
        var by = padT + cH - bh;
        if (mx >= bx && mx <= bx+barW && my >= by && my <= padT+cH){
          var cleanLabel = it.label.replace(/\n/g, ' ');
          tooltip.innerHTML = '<strong>' + cleanLabel + '</strong><br>' + it.count + ' children · ' + pct.toFixed(1) + '%';
          tooltip.style.left = (bx + barW/2) + 'px';
          tooltip.style.top = (by - 10) + 'px';
          tooltip.classList.add('is-visible');
          prevHover = i;
          renderPrevChart(prevAnimKey, 1);
          found = true;
        }
      });
      if (!found){
        tooltip.classList.remove('is-visible');
        prevHover = -1;
        renderPrevChart(prevAnimKey, 1);
      }
    });
    canvas.addEventListener('mouseleave', function(){
      tooltip.classList.remove('is-visible');
      prevHover = -1;
      renderPrevChart(prevAnimKey, 1);
    });
  })();

  /* ====================================================================
   * FILTERS
   * ==================================================================== */
  var baseUrl = window.location.pathname;
  document.getElementById('whoApplyBtn').addEventListener('click', function(){
    var p = [], v;
    v = document.getElementById('whoFilterBarangay').value;
    if (v !== 'all') p.push('barangay='+encodeURIComponent(v));
    v = document.getElementById('whoFilterDateFrom').value;
    if (v) p.push('date_from='+encodeURIComponent(v));
    v = document.getElementById('whoFilterDateTo').value;
    if (v) p.push('date_to='+encodeURIComponent(v));
    v = document.getElementById('whoFilterAgeGroup').value;
    if (v !== 'all') p.push('age_group='+encodeURIComponent(v));
    v = document.getElementById('whoFilterSex').value;
    if (v !== 'all') p.push('sex='+encodeURIComponent(v));
    window.location.href = baseUrl + (p.length ? '?'+p.join('&') : '');
  });
  document.getElementById('whoResetBtn').addEventListener('click', function(){
    window.location.href = baseUrl;
  });

  /* ====================================================================
   * RESIZE — debounce all charts
   * ==================================================================== */
  var rt;
  window.addEventListener('resize', function(){
    clearTimeout(rt);
    rt = setTimeout(function(){
      renderAgeBarChart(1);
      renderPrevChart(document.getElementById('whoPrevSelect').value, 1);
    }, 200);
  });
})();
</script>

<?php
nutritionist_layout_end();
?>

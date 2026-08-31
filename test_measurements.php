<?php

declare(strict_types=1);

require_once __DIR__ . '/public_html/includes/db.php';
require_once __DIR__ . '/public_html/includes/who_calculator.php';

/*
============================================================
 SUKATKALUSUGAN - MANUAL WHO CALCULATOR TESTER
============================================================

 Database writes : NO
 Measurements    : NOT SAVED
 submit_measurement.php : NOT USED
 Mode             : READ ONLY

 Purpose:
 Type in weight/height/age/sex by hand and see exactly what
 calculate_who_metrics() (the same function submit_measurement.php
 calls in production) returns -- WAZ/HAZ/WHZ plus wfa/hfa/wfh
 status -- so you can manually spot-check the calculator without
 going through the kiosk or touching real data.

 Age input can be either:
   - a whole number of months, or
   - a date of birth (DOB) + date measured, from which both
     completed months AND days are derived (matching what
     submit_measurement.php and measurements_create.php actually
     write to the database on every real measurement).

 File:
 public_html/test/manual_calculator_test.php
============================================================
*/

$result = null;
$error = null;

$weight = $_POST['weight_kg'] ?? '';
$height = $_POST['height_cm'] ?? '';
$sex = $_POST['sex'] ?? 'M';

// Age can be entered directly in months, OR derived from DOB + date measured
$ageMode = $_POST['age_mode'] ?? 'months';
$ageMonthsInput = $_POST['age_months'] ?? '';
$dob = $_POST['dob'] ?? '';
$dateMeasured = $_POST['date_measured'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$weightVal = is_numeric($weight) ? (float)$weight : null;
	$heightVal = is_numeric($height) ? (float)$height : null;
	$sexVal = ($sex === 'F') ? 'Female' : 'Male';

	$ageMonths = null;
	$ageDays = null;
	$referenceDate = null;

	if ($ageMode === 'dob') {
		if ($dob === '') {
			$error = 'Please provide a date of birth.';
		} else {
			if ($dateMeasured !== '') {
				try {
					$referenceDate = new DateTimeImmutable($dateMeasured);
				} catch (Exception $e) {
					$error = 'Invalid "date measured" value.';
				}
			}

			if ($error === null) {
				// age_days is the canonical answer the WHO calculator
				// needs (WAZ/HAZ day-keyed lookup + WFL/WFH cutover
				// at 731 days). age_months is a UI-only whole-number
				// estimate derived from the day count.
				$age = doh_age($dob, $referenceDate);
				if ($age === null) {
					$error = $error ?? 'Could not compute age from that date of birth.';
				} else {
					$ageDays = $age['days'];
					$ageMonths = $age['months'];
				}
			}
		}
	} else {
		$ageMonthsInputVal = is_numeric($ageMonthsInput) ? (int)round((float)$ageMonthsInput) : null;
		if ($ageMonthsInputVal === null) {
			$error = 'Please provide a valid age in months.';
		} else {
			// Without a DOB we can only estimate days from completed
			// months. Real measurements store the actual day count,
			// but for a manual spot-check this approximation is what
			// the operator types in by hand. Use 30.4375 days per
			// average month (365.25/12) so the test value is closer
			// to what production would compute.
			$ageMonths = $ageMonthsInputVal;
			$ageDays = (int)round($ageMonthsInputVal * 30.4375);
		}
	}

	if ($weightVal === null || $weightVal <= 0) {
		$error = $error ?? 'Please provide a valid weight in kg.';
	}
	if ($heightVal === null || $heightVal <= 0) {
		$error = $error ?? 'Please provide a valid height/length in cm.';
	}

	if ($error === null && $ageDays !== null && $weightVal !== null && $heightVal !== null) {
		// age_days is the only age input the calculator needs. The
		// month figure is for the UI label only.
		$result = calculate_who_metrics($weightVal, $heightVal, $ageDays, $sexVal);
		$result['age_months_used'] = $ageMonths;
		$result['age_days_used'] = $ageDays;
	}
}

$dohLabels = [
	'SUW' => 'Severely Underweight', 'MUW' => 'Moderately Underweight', 'Normal' => 'Normal',
	// "Refer to WFL/H" replaces the old WFA "Overweight" pill --
	// any WAZ > +2 routes the operator to the WFL/H axis instead.
	'Refer to WFL/H' => 'Refer to WFL/H (WAZ > +2)',
	'SSt' => 'Severely Stunted', 'MSt' => 'Moderately Stunted', 'Tall' => 'Tall for age',
	'SW' => 'Severe Wasting (SAM)', 'MW' => 'Moderate Wasting (MAM)',
	// OW / Ob now live on the WFL/H axis (not WFA) -- per DOH eOPT
	// Plus, WFA no longer classifies overweight / obese.
	'OW' => 'Overweight (WFL/H)', 'Ob' => 'Obese (WFL/H)',
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Manual WHO Calculator Tester</title>
<style>
	body { font-family: system-ui, sans-serif; max-width: 760px; margin: 32px auto; padding: 0 16px; color: #1a1a1a; }
	h1 { font-size: 20px; }
	.banner { background: #fff3cd; border: 1px solid #ffe08a; padding: 8px 12px; border-radius: 6px; font-size: 13px; margin-bottom: 20px; }
	fieldset { border: 1px solid #ddd; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
	legend { font-weight: 600; padding: 0 6px; }
	label { display: block; margin: 10px 0 4px; font-size: 13px; font-weight: 600; }
	input[type=text], input[type=number], input[type=date], select {
		width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; box-sizing: border-box;
	}
	.row { display: flex; gap: 12px; }
	.row > div { flex: 1; }
	.radio-row { display: flex; gap: 16px; align-items: center; margin: 8px 0; font-weight: normal; font-size: 13px; }
	button { margin-top: 16px; padding: 10px 20px; background: #2e7d32; color: #fff; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; }
	button:hover { background: #26692a; }
	.error { background: #fde7e7; border: 1px solid #f3b7b7; color: #7a1f1f; padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; }
	table { width: 100%; border-collapse: collapse; margin-top: 12px; }
	th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eee; font-size: 14px; }
	.status-pill { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; background: #e8f5e9; color: #2e7d32; }
	.status-pill.warn { background: #fff3e0; color: #e65100; }
	.status-pill.crit { background: #ffebee; color: #c62828; }
	.status-pill.gray { background: #eceff1; color: #546e7a; }
	.flagged { color: #c62828; font-weight: 600; }
	.derived { color: #546e7a; font-size: 12px; }
</style>
</head>
<body>

<h1>Manual WHO Calculator Tester</h1>
<div class="banner">Read-only test tool &mdash; calls the exact same <code>calculate_who_metrics()</code> function used in production. Nothing here is saved to the database. Age in days is derived from DOB+date measured (or months&times;30 when entered directly) and is what the WAZ/HAZ day-keyed reference table is looked up against.</div>

<?php if ($error !== null): ?>
	<div class="error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<form method="post">
	<fieldset>
		<legend>Measurement</legend>
		<div class="row">
			<div>
				<label for="weight_kg">Weight (kg)</label>
				<input type="text" inputmode="decimal" name="weight_kg" id="weight_kg" value="<?php echo htmlspecialchars((string)$weight); ?>" placeholder="e.g. 10.5" required>
			</div>
			<div>
				<label for="height_cm">Height/Length (cm)</label>
				<input type="text" inputmode="decimal" name="height_cm" id="height_cm" value="<?php echo htmlspecialchars((string)$height); ?>" placeholder="e.g. 84.0" required>
			</div>
			<div>
				<label for="sex">Sex</label>
				<select name="sex" id="sex">
					<option value="M" <?php echo $sex === 'M' ? 'selected' : ''; ?>>Male</option>
					<option value="F" <?php echo $sex === 'F' ? 'selected' : ''; ?>>Female</option>
				</select>
			</div>
		</div>
	</fieldset>

	<fieldset>
		<legend>Age</legend>
		<div class="radio-row">
			<label style="font-weight:normal;"><input type="radio" name="age_mode" value="months" <?php echo $ageMode !== 'dob' ? 'checked' : ''; ?> onchange="toggleAgeMode()"> Enter age in months directly</label>
			<label style="font-weight:normal;"><input type="radio" name="age_mode" value="dob" <?php echo $ageMode === 'dob' ? 'checked' : ''; ?> onchange="toggleAgeMode()"> Compute from date of birth</label>
		</div>

		<div id="months-input" style="<?php echo $ageMode === 'dob' ? 'display:none;' : ''; ?>">
			<label for="age_months">Age in months</label>
			<input type="text" inputmode="numeric" name="age_months" id="age_months" value="<?php echo htmlspecialchars((string)$ageMonthsInput); ?>" placeholder="e.g. 22">
			<div class="derived">Days will be approximated as months&times;30. Use the DOB mode for the exact day count that production would use.</div>
		</div>

		<div id="dob-input" class="row" style="<?php echo $ageMode === 'dob' ? '' : 'display:none;'; ?>">
			<div>
				<label for="dob">Date of birth</label>
				<input type="date" name="dob" id="dob" value="<?php echo htmlspecialchars($dob); ?>">
			</div>
			<div>
				<label for="date_measured">Date measured (defaults to today)</label>
				<input type="date" name="date_measured" id="date_measured" value="<?php echo htmlspecialchars($dateMeasured); ?>">
			</div>
		</div>
	</fieldset>

	<button type="submit">Calculate</button>
</form>

<?php if ($result !== null): ?>
	<fieldset>
		<legend>Result</legend>
		<table>
			<tr>
				<th>Age used (months)</th>
				<td><?php echo (int)$result['age_months_used']; ?> months</td>
			</tr>
			<tr>
				<th>Age used (days)</th>
				<td>
					<?php echo (int)$result['age_days_used']; ?> days
					<span class="derived">(&#8776; <?php echo number_format((int)$result['age_days_used'] / 30.4375, 1); ?> completed months, what production stores as <code>measurements.age_days</code>)</span>
				</td>
			</tr>
			<tr><th>WAZ (weight-for-age z-score)</th><td><?php echo number_format($result['waz'], 2); ?></td></tr>
			<tr><th>HAZ (height-for-age z-score)</th><td><?php echo number_format($result['haz'], 2); ?></td></tr>
			<tr><th>WHZ (weight-for-height z-score)</th><td><?php echo number_format($result['whz'], 2); ?></td></tr>
		</table>

		<?php
			// Map status codes to pill variants. "Refer to WFL/H"
			// (WFA overflow) and "Tall" (HFA above +2 SD) get their
			// own neutral grey pill so they stand apart from the
			// green / orange / red normal / warn / danger pattern.
			$wfaKey = $result['wfa_status'] ?? '';
			$hfaKey = $result['hfa_status'] ?? '';
			$wfhKey = $result['wfh_status'] ?? '';
			$wfaCls = ($wfaKey === 'Refer to WFL/H') ? 'gray'
				: (in_array($wfaKey, ['SUW'], true) ? 'crit'
				: (in_array($wfaKey, ['MUW'], true) ? 'warn' : ''));
			$hfaCls = (in_array($hfaKey, ['SSt'], true) ? 'crit'
				: (in_array($hfaKey, ['MSt'], true) ? 'warn' : ''));
			$wfhCls = (in_array($wfhKey, ['SW', 'Ob'], true) ? 'crit'
				: (in_array($wfhKey, ['MW', 'OW'], true) ? 'warn' : ''));
			$wfaLabel = $dohLabels[$wfaKey] ?? ($wfaKey !== '' ? $wfaKey : '&mdash;');
			$hfaLabel = $dohLabels[$hfaKey] ?? ($hfaKey !== '' ? $hfaKey : '&mdash;');
			$wfhLabel = $dohLabels[$wfhKey] ?? ($wfhKey !== '' ? $wfhKey : '&mdash;');
		?>

		<table>
			<tr>
				<th>Weight-for-Age status</th>
				<td><span class="status-pill <?php echo $wfaCls; ?>"><?php echo htmlspecialchars($wfaLabel); ?></span></td>
			</tr>
			<tr>
				<th>Height-for-Age status</th>
				<td><span class="status-pill <?php echo $hfaCls; ?>"><?php echo htmlspecialchars($hfaLabel); ?></span></td>
			</tr>
			<tr>
				<th>Weight-for-Height status</th>
				<td><span class="status-pill <?php echo $wfhCls; ?>"><?php echo htmlspecialchars($wfhLabel); ?></span></td>
			</tr>
			<tr>
				<th>Overall nutritional_status (ENUM)</th>
				<td><?php echo htmlspecialchars($result['nutritional_status']); ?></td>
			</tr>
			<tr>
				<th>WFA overflow</th>
				<td>
					<?php if (!empty($result['wfa_overflow'])): ?>
						<span class="status-pill gray">YES &mdash; WAZ &gt; +2, read overweight/obese off the WFL/H axis</span>
					<?php else: ?>
						No
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th>Flagged as implausible?</th>
				<td class="<?php echo $result['is_flagged'] ? 'flagged' : ''; ?>">
					<?php echo $result['is_flagged'] ? 'YES &mdash; ' . htmlspecialchars((string)$result['flag_reason']) : 'No'; ?>
				</td>
			</tr>
		</table>
	</fieldset>
<?php endif; ?>

<script>
function toggleAgeMode() {
	const isDob = document.querySelector('input[name="age_mode"]:checked').value === 'dob';
	document.getElementById('months-input').style.display = isDob ? 'none' : '';
	document.getElementById('dob-input').style.display = isDob ? '' : 'none';
}
</script>

</body>
</html>

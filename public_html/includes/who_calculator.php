<?php

require_once __DIR__ . '/db.php';

function doh_age_in_months(?string $birthdate, ?DateTimeImmutable $today = null): ?int
{
	$birthdate = trim((string)$birthdate);

	if ($birthdate === '') {
		return null;
	}

	try {
		$birthDate = new DateTimeImmutable($birthdate);
	} catch (Exception $exception) {
		return null;
	}

	$referenceDate = $today ?? new DateTimeImmutable('today');

	if ($birthDate > $referenceDate) {
		return null;
	}

	$age = $birthDate->diff($referenceDate);

	return ($age->y * 12) + $age->m;
}

function who_lms_z_score(float $measurement, float $lmsL, float $lmsM, float $lmsS): float
{
	if ($measurement <= 0 || $lmsM <= 0 || $lmsS <= 0) {
		return 0.0;
	}

	if (abs($lmsL) < 0.000001) {
		return log($measurement / $lmsM) / $lmsS;
	}

	return ((pow($measurement / $lmsM, $lmsL) - 1) / ($lmsL * $lmsS));
}

function who_lookup_reference(string $table, string $sex, string $column, float $value): ?array
{
	$conn = get_db_connection();
	$normalizedSex = $sex === 'Female' ? 'Female' : 'Male';

	// age_months is always a whole number, and the age-based tables (WFA, HFA)
	// have one row per integer month, so an exact match is correct there.
	// height_cm is a continuous measurement (e.g. 92.3cm) but the WFH table is
	// only stepped every 0.5cm (45.0, 45.5, 46.0, ...). An exact-match lookup
	// after round($value, 1) would miss almost every real submitted height and
	// silently fall through to who_fallback_reference() instead. So for
	// height_cm we find the closest row in the table rather than requiring an
	// exact match.
	if ($column === 'height_cm') {
		$stmt = mysqli_prepare(
			$conn,
			'SELECT L, M, S FROM ' . $table . '
			 WHERE sex = ?
			 ORDER BY ABS(height_cm - ?) ASC
			 LIMIT 1'
		);

		if ($stmt === false) {
			return null;
		}

		mysqli_stmt_bind_param($stmt, 'sd', $normalizedSex, $value);
	} else {
		$normalizedValue = (int)round($value);
		$stmt = mysqli_prepare($conn, 'SELECT L, M, S FROM ' . $table . ' WHERE sex = ? AND ' . $column . ' = ? LIMIT 1');

		if ($stmt === false) {
			return null;
		}

		mysqli_stmt_bind_param($stmt, 'si', $normalizedSex, $normalizedValue);
	}

	mysqli_stmt_execute($stmt);
	$result = mysqli_stmt_get_result($stmt);
	$row = $result instanceof mysqli_result ? mysqli_fetch_assoc($result) : null;
	mysqli_stmt_close($stmt);

	if (is_array($row)) {
		return [
			'L' => (float)$row['L'],
			'M' => (float)$row['M'],
			'S' => (float)$row['S'],
		];
	}

	return null;
}

function who_fallback_reference(string $metric, float $measurement, int $ageMonths = 0): array
{
	return match ($metric) {
		'waz' => [
			'L' => 0.0,
			'M' => 3.5 + ($ageMonths * 0.24),
			'S' => 1.08,
		],
		'haz' => [
			'L' => 0.0,
			'M' => 49.0 + ($ageMonths * 0.82),
			'S' => 2.30,
		],
		'whz' => [
			'L' => 0.0,
			'M' => 10.5 + (($measurement - 65.0) * 0.09),
			'S' => 1.05,
		],
		default => [
			'L' => 0.0,
			'M' => max($measurement, 1.0),
			'S' => 1.0,
		],
	};
}

function calculate_waz(float $weight_kg, int $age_months, string $sex): float
{
	$reference = who_lookup_reference('who_weight_for_age', $sex, 'age_months', (float)$age_months)
		?? who_fallback_reference('waz', $weight_kg, $age_months);

	return round(who_lms_z_score($weight_kg, $reference['L'], $reference['M'], $reference['S']), 2);
}

function calculate_haz(float $height_cm, int $age_months, string $sex): float
{
	$reference = who_lookup_reference('who_height_for_age', $sex, 'age_months', (float)$age_months)
		?? who_fallback_reference('haz', $height_cm, $age_months);

	return round(who_lms_z_score($height_cm, $reference['L'], $reference['M'], $reference['S']), 2);
}

/**
 * WHO publishes weight-for-length (recumbent, 0-23 months) and
 * weight-for-height (standing, 24-60 months) as two separate curves that
 * legitimately disagree over the 65-110cm range they both cover, because
 * the correct one depends on how the child was actually measured, not on
 * height alone. This picks the curve by $age_months (<24 -> length,
 * >=24 -> height), matching the DOH e-OPT Plus "Nut_StatusTool" sheet
 * (cell AD10) and the who_weight_for_length migration this table was
 * added for.
 */
function calculate_whz(float $weight_kg, float $height_cm, int $age_months, string $sex): float
{
	$table = $age_months < 24 ? 'who_weight_for_length' : 'who_weight_for_height';

	$reference = who_lookup_reference($table, $sex, 'height_cm', $height_cm)
		?? who_fallback_reference('whz', $height_cm);

	return round(who_lms_z_score($weight_kg, $reference['L'], $reference['M'], $reference['S']), 2);
}

/**
 * WHO treats underweight (WAZ), stunting (HAZ), and wasting (WHZ) as three
 * independent classifications -- a child can be stunted with a healthy
 * weight-for-height, or wasted without being underweight-for-age. The
 * `measurements.nutritional_status` column is a single-value ENUM
 * ('Normal','Underweight','Severely Underweight','Stunted','Wasted',
 * 'Overweight'), so this returns the single most clinically severe label
 * that applies, evaluating each axis independently -- WHZ no longer feeds
 * into the underweight check, which was the original bug (it meant 'Wasted'
 * could never actually be returned, and a low WHZ could mislabel a child as
 * 'Underweight' based on the wrong indicator).
 *
 * If your program wants a child's full status (e.g. stunted AND wasted
 * shown together), that needs a schema change -- either separate columns
 * per axis, or widening the ENUM -- since one ENUM column can only hold one
 * value. Worth raising with your adviser; this fix keeps today's schema
 * working correctly without requiring that migration yet.
 *
 * NOTE: BMI-for-age is not implemented here -- flag for adviser review.
 */
function classify_nutritional_status(float $waz, float $haz, float $whz): string
{
	if ($waz < -3) {
		return 'Severely Underweight';
	}

	if ($whz < -3) {
		return 'Wasted';
	}

	if ($waz < -2) {
		return 'Underweight';
	}

	if ($whz < -2) {
		return 'Wasted';
	}

	if ($haz < -2) {
		return 'Stunted';
	}

	if ($whz > 2) {
		return 'Overweight';
	}

	return 'Normal';
}

/**
 * DOH Operation Timbang Plus (eOPT Plus) classifies WFA, HFA, and WFH as
 * three independent axes rather than collapsing them into one label. These
 * match the exact cutoffs used in the DOH "Nut_StatusTool" reference sheet,
 * verified against the e-OPT Plus workbook in public_html/data/ (which emits
 * OW on the weight-for-age axis for children above +2SD).
 */
function classify_wfa_status(float $waz): string
{
	if ($waz < -3) {
		return 'SUW';
	}

	if ($waz < -2) {
		return 'MUW';
	}

	if ($waz <= 2) {
		return 'Normal';
	}

	return 'OW';
}

function classify_hfa_status(float $haz): string
{
	if ($haz < -3) {
		return 'SSt';
	}

	if ($haz < -2) {
		return 'MSt';
	}

	if ($haz <= 2) {
		return 'Normal';
	}

	return 'Tall';
}

function classify_wfh_status(float $whz): string
{
	if ($whz < -3) {
		return 'SW/SAM';
	}

	if ($whz < -2) {
		return 'MW/MAM';
	}

	if ($whz <= 2) {
		return 'Normal';
	}

	if ($whz <= 3) {
		return 'OW';
	}

	return 'Ob';
}

/**
 * Flags a measurement as biologically implausible -- almost always a data
 * entry or device error, not a real child. These are the WHO Anthro
 * software's standard flagging thresholds (used when the extended per-age
 * SD reference tables aren't available): WAZ outside -6..5, HAZ outside
 * -6..6, WHZ outside -5..5. eOPT Plus computes a more precise per-age SD
 * cutoff (needs a 4th reference parameter our who_* tables don't carry),
 * but for catching implausible entries the practical result is the same.
 *
 * @return array{is_flagged: bool, reason: string|null}
 */
function flag_measurement(float $waz, float $haz, float $whz): array
{
	$reasons = [];

	if ($waz < -6 || $waz > 5) {
		$reasons[] = 'WAZ out of range';
	}

	if ($haz < -6 || $haz > 6) {
		$reasons[] = 'HAZ out of range';
	}

	if ($whz < -5 || $whz > 5) {
		$reasons[] = 'WHZ out of range';
	}

	return [
		'is_flagged' => $reasons !== [],
		'reason' => $reasons !== [] ? implode('; ', $reasons) : null,
	];
}

function calculate_who_metrics(float $weight_kg, float $height_cm, int $age_months, string $sex): array
{
	$waz = calculate_waz($weight_kg, $age_months, $sex);
	$haz = calculate_haz($height_cm, $age_months, $sex);
	$whz = calculate_whz($weight_kg, $height_cm, $age_months, $sex);
	$flag = flag_measurement($waz, $haz, $whz);

	return [
		'waz' => $waz,
		'haz' => $haz,
		'whz' => $whz,
		'nutritional_status' => classify_nutritional_status($waz, $haz, $whz),
		'wfa_status' => classify_wfa_status($waz),
		'hfa_status' => classify_hfa_status($haz),
		'wfh_status' => classify_wfh_status($whz),
		'is_flagged' => $flag['is_flagged'],
		'flag_reason' => $flag['reason'],
	];
}
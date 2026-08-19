<?php

require_once __DIR__ . '/db.php';

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

function calculate_whz(float $weight_kg, float $height_cm, string $sex): float
{
	$reference = who_lookup_reference('who_weight_for_height', $sex, 'height_cm', $height_cm)
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
 * match the exact cutoffs used in the DOH "Nut_StatusTool" reference sheet.
 *
 * WFA deliberately has no overweight/obese category -- DOH classifies
 * weight-related overweight/obesity through WFH instead, since WFA alone
 * can't distinguish a tall-heavy child from an overweight one. A waz above
 * +2 returns null here; the WFH axis is what carries that signal.
 */
function classify_wfa_status(float $waz): ?string
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

	return null;
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
		return 'SW(SAM)';
	}

	if ($whz < -2) {
		return 'MW(MAM)';
	}

	if ($whz <= 2) {
		return 'Normal';
	}

	if ($whz <= 3) {
		return 'OW';
	}

	return 'Ob';
}

function calculate_who_metrics(float $weight_kg, float $height_cm, int $age_months, string $sex): array
{
	$waz = calculate_waz($weight_kg, $age_months, $sex);
	$haz = calculate_haz($height_cm, $age_months, $sex);
	$whz = calculate_whz($weight_kg, $height_cm, $sex);

	return [
		'waz' => $waz,
		'haz' => $haz,
		'whz' => $whz,
		'nutritional_status' => classify_nutritional_status($waz, $haz, $whz),
		'wfa_status' => classify_wfa_status($waz),
		'hfa_status' => classify_hfa_status($haz),
		'wfh_status' => classify_wfh_status($whz),
	];
}
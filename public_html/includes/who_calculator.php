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
	$normalizedValue = $column === 'height_cm' ? round($value, 1) : (int)round($value);
	$stmt = mysqli_prepare($conn, 'SELECT L, M, S FROM ' . $table . ' WHERE sex = ? AND ' . $column . ' = ? LIMIT 1');

	if ($stmt === false) {
		return null;
	}

	mysqli_stmt_bind_param($stmt, 'sd', $normalizedSex, $normalizedValue);
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

function classify_nutritional_status(float $waz, float $haz, float $whz): string
{
	if ($waz < -3 || $whz < -3) {
		return 'Severely Underweight';
	}

	if ($waz < -2 || $whz < -2) {
		return 'Underweight';
	}

	if ($haz < -2) {
		return 'Stunted';
	}

	if ($whz > 2) {
		return 'Overweight';
	}

	return 'Normal';
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
	];
}

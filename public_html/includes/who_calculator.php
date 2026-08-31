<?php

require_once __DIR__ . '/db.php';

/**
 * Returns the child's age in whole days as of $onDate (defaults to today).
 * This is the canonical age input for the WHO 2006 expanded reference
 * tables, which provide one row per day from birth through 1856 days
 * (~5 years + 30 days). Matches the DOH eOPT Plus convention used by
 * `measurement_date - birthdate` on every measurement record.
 */
function doh_age_in_days(?string $birthdate, ?DateTimeImmutable $onDate = null): ?int
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

	$referenceDate = $onDate ?? new DateTimeImmutable('today');

	if ($birthDate > $referenceDate) {
		return null;
	}

	$diffDays = (int)$birthDate->diff($referenceDate)->format('%r%a');

	return max(0, $diffDays);
}

/**
 * The single source of truth for any "how old is this child right now"
 * computation in the UI. Returns both the exact day count and a
 * whole-number month estimate derived from the day count.
 *
 * The month estimate uses the average number of days per calendar
 * month (30.4375 = 365.25 / 12) and is rounded down (`intdiv`) to a
 * whole number -- so the UI never has to deal with a decimal point.
 *
 * The day count is the canonical answer. The month estimate is for
 * the eOPT Plus roster reports and the kiosk child list, which the
 * DOH form labels with "X mo" so the operator can match it against
 * the printed worksheet.
 *
 * @return array{days: int, months: int}|null
 */
function doh_age(?string $birthdate, ?DateTimeImmutable $onDate = null): ?array
{
	$days = doh_age_in_days($birthdate, $onDate);

	if ($days === null) {
		return null;
	}

	return [
		'days' => $days,
		// 30.4375 days per average calendar month (365.25 / 12). The
		// intdiv() gives us a whole-number estimate, never a decimal.
		'months' => intdiv($days, 30),
	];
}

/**
 * Calendar-completed months (the old DOH e-OPT Plus convention). This
 * is a UI helper ONLY -- the WHO calculator no longer uses month-based
 * ages internally (it uses day-based lookups everywhere). eOPT reports
 * still print "X mo" labels, so the helper is kept for those.
 *
 * Deprecated for any calculation: prefer doh_age() and the
 * `months` estimate, or doh_age_in_days() for the canonical answer.
 */
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

	// age_months / age_days are always whole numbers, and the age-based
	// tables (WFA, HFA) have one row per integer day or month, so an
	// exact match is correct there. height_cm is a continuous measurement
	// (e.g. 92.3cm) but the WFH table is only stepped every 0.5cm
	// (45.0, 45.5, 46.0, ...). An exact-match lookup after round($value, 1)
	// would miss almost every real submitted height and silently fall
	// through to who_fallback_reference() instead. So for height_cm we
	// find the closest row in the table rather than requiring an exact
	// match.
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

function who_fallback_reference(string $metric, float $measurement, int $ageDays = 0): array
{
	// Translate days into a rough completed-months value so the legacy
	// weight/height regression curves below still produce plausible LMS.
	$ageMonths = intdiv($ageDays, 30);

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

/**
 * WAZ (Weight-for-Age z-score) using the day-keyed WHO 2006 expanded
 * reference table. If the day row is missing (e.g. age > 1856 days) the
 * calculator falls back to the legacy monthly table and finally to a
 * rough regression curve.
 */
function calculate_waz(float $weight_kg, int $age_days, string $sex): float
{
	$reference = who_lookup_reference('who_weight_for_age_days', $sex, 'age_days', (float)$age_days);

	if ($reference === null) {
		// Legacy monthly fallback for any out-of-range or pre-seeded days.
		$reference = who_lookup_reference('who_weight_for_age', $sex, 'age_months', (float)intdiv($age_days, 30));
	}

	if ($reference === null) {
		$reference = who_fallback_reference('waz', $weight_kg, $age_days);
	}

	return round(who_lms_z_score($weight_kg, $reference['L'], $reference['M'], $reference['S']), 2);
}

/**
 * HAZ (Height-for-Age z-score) using the day-keyed WHO 2006 expanded
 * reference table.
 */
function calculate_haz(float $height_cm, int $age_days, string $sex): float
{
	$reference = who_lookup_reference('who_height_for_age_days', $sex, 'age_days', (float)$age_days);

	if ($reference === null) {
		$reference = who_lookup_reference('who_height_for_age', $sex, 'age_months', (float)intdiv($age_days, 30));
	}

	if ($reference === null) {
		$reference = who_fallback_reference('haz', $height_cm, $age_days);
	}

	return round(who_lms_z_score($height_cm, $reference['L'], $reference['M'], $reference['S']), 2);
}

/**
 * WHO publishes weight-for-length (recumbent, <2y) and weight-for-height
 * (standing, >=2y) as two separate curves that legitimately disagree over
 * the 65-110cm range they both cover, because the correct one depends on
 * how the child was actually measured, not on height alone.
 *
 * Cutover is by `age_days`: 731 days (~2 years) is the boundary. The WFL
 * (recumbent length) curve is for kids < 731 days; WFH (standing height)
 * for kids >= 731 days. The number 731 is the integer day count that
 * represents two full years using the 365.25-days-per-year average, so
 * 2y 0d 0h maps to day 730 and the WFL curve applies; 2y 1d maps to
 * day 731 and the WFH curve applies.
 *
 * This mirrors what the day-keyed WFA/HFA tables use everywhere else in
 * the calculator -- age_days is the single source of truth for the WHO
 * lookup key.
 */
function calculate_whz(float $weight_kg, float $height_cm, int $age_days, string $sex): float
{
	$table = $age_days < 731 ? 'who_weight_for_length' : 'who_weight_for_height';

	$reference = who_lookup_reference($table, $sex, 'height_cm', $height_cm)
		?? who_fallback_reference('whz', $height_cm);

	return round(who_lms_z_score($weight_kg, $reference['L'], $reference['M'], $reference['S']), 2);
}

/**
 * WHO treats underweight (WAZ), stunting (HAZ), and wasting (WHZ) as three
 * independent classifications -- a child can be stunted with a healthy
 * weight-for-height, or wasted without being underweight-for-age. The
 * `measurements.nutritional_status` column stores the single most clinically
 * severe label that applies, evaluating each axis independently.
 *
 * Note: WAZ > +2 is no longer part of the WFA axis (per the DOH eOPT Plus
 * rule, the WFA tab shows a "Refer to WFL/H" pill and the WFL/H axis
 * decides Overweight/Obese). So the "Overweight" / "Obese" labels in this
 * consolidated status are driven exclusively by WHZ.
 *
 * Categories returned:
 *   Severely Underweight   (WAZ < -3)
 *   Severely Stunted       (HAZ < -3)
 *   Severely Wasted        (WHZ < -3)
 *   Moderately Underweight (WAZ < -2)
 *   Moderately Stunted     (HAZ < -2)
 *   Moderately Wasted      (WHZ < -2)
 *   Obese                  (WHZ > 3)
 *   Overweight             (WHZ > 2)
 *   Normal
 */
function classify_nutritional_status(float $waz, float $haz, float $whz): string
{
	if ($waz < -3) {
		return 'Severely Underweight';
	}

	if ($haz < -3) {
		return 'Severely Stunted';
	}

	if ($whz < -3) {
		return 'Severely Wasted';
	}

	if ($waz < -2) {
		return 'Moderately Underweight';
	}

	if ($haz < -2) {
		return 'Moderately Stunted';
	}

	if ($whz < -2) {
		return 'Moderately Wasted';
	}

	if ($whz > 3) {
		return 'Obese';
	}

	if ($whz > 2) {
		return 'Overweight';
	}

	return 'Normal';
}

/**
 * DOH e-OPT Plus Weight-for-Age classification. WFA is intentionally a
 * one-sided (under-nutrition) axis: any z-score above +2 means the
 * weight-for-age reading is unreliable, and the operator is redirected to
 * the WFL/H axis instead. The pill the WFA tab renders for that case is
 * "Use WFL/H column" -- the `wfa_status` value is the exact string
 * stored in the `measurements.wfa_status` column, so the chart and the
 *   history tables can match on it.
 *
 *   SUW              < -3SD
 *   MUW         -3..-2SD
 *   Normal     -2..+2SD
 *   Refer to WFL/H   > +2SD  (DOH e-OPT Plus rule)
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

	return 'Refer to WFL/H';
}

/**
 * DOH e-OPT Plus Height-for-Age classification. Tall is reported on the
 * HFA tab (no redirect) because the WFA-overflow rule is WFA-specific.
 *
 *   SSt            < -3SD
 *   MSt       -3..-2SD
 *   Normal   -2..+2SD
 *   Tall          > +2SD
 */
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

/**
 * DOH e-OPT Plus Weight-for-Length/Height classification:
 *   SW < -3SD | MW -3..-2SD | Normal -2..+2SD | OW +2..+3SD | Ob > +3SD
 */
function classify_wfh_status(float $whz): string
{
	if ($whz < -3) {
		return 'SW';
	}

	if ($whz < -2) {
		return 'MW';
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
 * entry or device error, not a real child. WHO Anthro standard thresholds:
 * WAZ outside -6..5, HAZ outside -6..6, WHZ outside -5..5. We let WAZ go
 * up to +5 here even though the WFA tab now treats anything > +2 as
 * "Refer to WFL/H" -- a value above +5 is still implausible and the
 * flag should trip.
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

/**
 * Convenience wrapper used by every callsite (ESP32 submit, manual
 * measurement entry, etc.). The only age input is `age_days` -- it
 * drives the WAZ/HAZ day-keyed lookups and the WFL/WFH cutover. The
 * `age_months` field on the measurements row is a UI-only whole-number
 * estimate (age_days / 30.4375, rounded) and is NOT used by the
 * calculator itself.
 */
function calculate_who_metrics(float $weight_kg, float $height_cm, int $age_days, string $sex): array
{
	$waz = calculate_waz($weight_kg, $age_days, $sex);
	$haz = calculate_haz($height_cm, $age_days, $sex);
	$whz = calculate_whz($weight_kg, $height_cm, $age_days, $sex);
	$flag = flag_measurement($waz, $haz, $whz);

	return [
		'waz' => $waz,
		'haz' => $haz,
		'whz' => $whz,
		'nutritional_status' => classify_nutritional_status($waz, $haz, $whz),
		'wfa_status' => classify_wfa_status($waz),
		'hfa_status' => classify_hfa_status($haz),
		'wfh_status' => classify_wfh_status($whz),
		'wfa_overflow' => $waz > 2,
		'is_flagged' => $flag['is_flagged'],
		'flag_reason' => $flag['reason'],
	];
}

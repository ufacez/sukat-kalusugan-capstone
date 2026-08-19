<?php

require_once __DIR__ . '/db.php';

/**
 * Age in COMPLETED months, using the same convention as the official DOH
 * e-OPT Plus "Nut_StatusTool" sheet (cell K10):
 *
 *   months = ROUNDDOWN( (measured_date - birthdate) / 30.4375 , 0 )
 *
 * 30.4375 is the average number of days in a month (365.25 / 12). This is
 * also the convention the WHO growth-standard tables themselves are built
 * on, so it is not just "matching DOH's spreadsheet" -- it is the correct
 * convention for indexing into who_weight_for_age / who_height_for_age,
 * which are keyed by completed month (0, 1, 2, ... 60).
 *
 * BEFORE THIS FIX, this codebase had several *different* age-in-months
 * implementations scattered across kiosk_helpers.php, submit_measurement.php,
 * parent/children.php, and nutritionist/{children,dashboard}.php, all using
 * PHP's calendar-based DateInterval instead:
 *
 *   $diff = $birth->diff($today);
 *   $ageMonths = $diff->y * 12 + $diff->m;
 *
 * That counts *calendar* months elapsed, which disagrees with the DOH/WHO
 * average-days formula near month boundaries. Example: a child born
 * 2023-01-31, measured 2023-03-01, is 29 days old --
 *   - calendar method:      1 month  (Jan 31 -> Mar 1 crosses one "month mark")
 *   - DOH/WHO method:        0 months (29 / 30.4375 = 0.95, rounds down to 0)
 * That one-month gap changes which row of the WHO reference table gets
 * looked up, which changes L/M/S, which changes every z-score and status
 * label. This is the root cause of "age in months" not matching between
 * this app and the official e-OPT Plus tool -- every call site should use
 * THIS function so the whole app (and DOH cross-checks) agree.
 *
 * @param string $birthdate      Y-m-d (or any strtotime-parsable date)
 * @param string $referenceDate  Y-m-d to measure age AT (defaults to today).
 *                                Pass the actual measurement date when one
 *                                exists (e.g. re-processing a past record),
 *                                not 'today', or the age will drift every
 *                                time the record is re-rendered.
 * @return int|null  Completed months, or null if the dates are invalid,
 *                    the reference date is before the birthdate ("Check
 *                    data entry for dates" in DOH's tool), or the age is
 *                    beyond the tool's 0-60 month scope ("Age above limit").
 */
function doh_age_in_months(string $birthdate, string $referenceDate = 'today'): ?int
{
	try {
		$birth = new DateTimeImmutable($birthdate);
		$reference = $referenceDate === 'today'
			? new DateTimeImmutable('today')
			: new DateTimeImmutable($referenceDate);
	} catch (Throwable $e) {
		return null;
	}

	if ($reference < $birth) {
		return null;
	}

	$days = (int)$reference->diff($birth)->days;
	$months = (int)floor($days / 30.4375);

	if ($months > 60) {
		return null;
	}

	return $months;
}

/**
 * WHO / SMART-survey standard "biologically implausible" flag limits.
 * These are the fixed z-score cutoffs WHO recommends for FLAGGING a record
 * for data-entry review -- they are separate from, and wider than, the
 * clinical classification cutoffs (+-2 / +-3 SD) used by
 * classify_wfa_status() etc. A flagged record can still be genuinely
 * severe; the flag just means "double-check the raw weight/height/age
 * before trusting the label", the same role the "flagged children" (P/V/AB
 * columns) play in the DOH e-OPT Plus sheet.
 *
 * Source: WHO Department of Nutrition, "WHO Anthro" / SMART survey plausibility
 * checks (weight-for-age: -6 to +5, height-for-age: -6 to +6,
 * weight-for-height: -5 to +5).
 */
const WHO_FLAG_LIMITS = [
	'waz' => ['min' => -6.0, 'max' => 5.0],
	'haz' => ['min' => -6.0, 'max' => 6.0],
	'whz' => ['min' => -5.0, 'max' => 5.0],
];

function who_is_flagged(string $indicator, float $z): bool
{
	$limits = WHO_FLAG_LIMITS[$indicator] ?? null;

	if ($limits === null) {
		return false;
	}

	return $z < $limits['min'] || $z > $limits['max'];
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
 * Weight-for-length/height. WHO publishes this as TWO separate curves that
 * legitimately disagree over the 65-110cm range they both cover, because
 * the correct one depends on whether the child was measured lying down
 * (recumbent LENGTH, ages 0-23 months) or standing (HEIGHT, ages 24-60
 * months) -- not on the height value itself. This mirrors the DOH e-OPT
 * Plus "Nut_StatusTool" sheet, which branches the same way (cell AD10:
 * `IF(K10<24, <length table>, <height table>)`).
 *
 * who_weight_for_length holds the 0-23-month recumbent-length curve;
 * who_weight_for_height holds the 24-60-month standing-height curve. Pass
 * $age_months so the correct one gets used -- calling this without a real
 * age_months silently used the height curve for everyone, which is wrong
 * for any child under 24 months measured at >=65cm (previously a fixed
 * $height_cm-only lookup with no age branch).
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

function calculate_who_metrics(float $weight_kg, float $height_cm, int $age_months, string $sex): array
{
	$waz = calculate_waz($weight_kg, $age_months, $sex);
	$haz = calculate_haz($height_cm, $age_months, $sex);
	$whz = calculate_whz($weight_kg, $height_cm, $age_months, $sex);

	// See WHO_FLAG_LIMITS: flagged = "double-check this measurement before
	// trusting the label", not "reject it". Classification below still runs
	// on the raw z-score either way, same as DOH e-OPT Plus does -- a flag
	// is metadata for the reviewer, it never blanks out the status.
	$flags = [
		'waz' => who_is_flagged('waz', $waz),
		'haz' => who_is_flagged('haz', $haz),
		'whz' => who_is_flagged('whz', $whz),
	];

	return [
		'waz' => $waz,
		'haz' => $haz,
		'whz' => $whz,
		'nutritional_status' => classify_nutritional_status($waz, $haz, $whz),
		'wfa_status' => classify_wfa_status($waz),
		'hfa_status' => classify_hfa_status($haz),
		'wfh_status' => classify_wfh_status($whz),
		'flags' => $flags,
		'flagged' => $flags['waz'] || $flags['haz'] || $flags['whz'],
	];
}
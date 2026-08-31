<?php
/**
 * who_reference_import.php
 *
 * Helper functions for importing official WHO LMS 2006 reference tables
 * (.xlsx only) into who_weight_for_age / who_height_for_age /
 * who_weight_for_height / who_weight_for_length, and for exporting the
 * current contents of those tables back out to .xlsx. Used by
 * nutritionist/who_reference.php.
 */

require_once __DIR__ . '/xlsx_lite.php';

/** Header names (lowercased, trimmed) accepted for each column this importer looks for. */
function who_reference_column_aliases(): array
{
	return [
		'day' => ['day', 'days', 'age (days)', 'age_days'],
		'age_months' => ['age (months)', 'age_months', 'month', 'months', 'age', 'age (completed months)'],
		'height_cm' => ['length', 'height', 'length/height', 'length (cm)', 'height (cm)', 'length/height (cm)', 'height_cm'],
		'l' => ['l'],
		'm' => ['m'],
		's' => ['s'],
	];
}

/**
 * Returns the unique key for the day-based reference tables. Indicators
 * that have been switched to age-in-days lookups (WFA, HFA) key into
 * these day tables; the legacy monthly tables are still used as a
 * fallback for any out-of-range days (e.g. age > 1856 days).
 */
function who_reference_day_table_key(string $table): ?string
{
	return match ($table) {
		'who_weight_for_age_days' => 'age_days',
		'who_height_for_age_days' => 'age_days',
		default => null,
	};
}

/**
 * Matches a parsed header row against the alias list and returns the column
 * index for each key it can find (missing keys are simply absent).
 *
 * @param string[] $headerRow
 * @return array<string,int>
 */
function who_reference_match_headers(array $headerRow): array
{
	$aliases = who_reference_column_aliases();
	$normalized = array_map(static fn ($v) => strtolower(trim((string)$v)), $headerRow);
	$matches = [];

	foreach ($aliases as $key => $names) {
		foreach ($names as $name) {
			$pos = array_search($name, $normalized, true);

			if ($pos !== false) {
				$matches[$key] = $pos;
				break;
			}
		}
	}

	return $matches;
}

/**
 * Linearly interpolates L/M/S between the two nearest known days in a daily
 * (0-1856ish) WHO "expanded" table, at a fractional day such as 30.4375
 * (i.e. one average month). This is the same math WHO itself uses to derive
 * the standard monthly tables from the daily ones, so imported values line
 * up with the officially published monthly LMS to 3-4 decimal places.
 *
 * @param array<int, array{L: float, M: float, S: float}> $dayMap
 * @return array{L: float, M: float, S: float}|null
 */
function who_reference_interpolate_day(array $dayMap, float $targetDay): ?array
{
	$lo = (int)floor($targetDay);
	$hi = (int)ceil($targetDay);

	if (!isset($dayMap[$lo]) || !isset($dayMap[$hi])) {
		return null;
	}

	if ($lo === $hi) {
		return $dayMap[$lo];
	}

	$frac = $targetDay - $lo;
	$loRow = $dayMap[$lo];
	$hiRow = $dayMap[$hi];

	return [
		'L' => $loRow['L'] + ($hiRow['L'] - $loRow['L']) * $frac,
		'M' => $loRow['M'] + ($hiRow['M'] - $loRow['M']) * $frac,
		'S' => $loRow['S'] + ($hiRow['S'] - $loRow['S']) * $frac,
	];
}

/**
 * Parses an uploaded .xlsx (already validated as a real xlsx by the caller)
 * and imports it into the given indicator's table for the given sex.
 *
 * @return array{ok: bool, message: string, inserted?: int, updated?: int, skipped?: int}
 */
function who_reference_import_file(string $tmpPath, array $indicatorConfig, string $sex): array
{
	try {
		$rows = xlsx_lite_read_first_sheet($tmpPath);
	} catch (Throwable $e) {
		return ['ok' => false, 'message' => 'Could not read that file: ' . $e->getMessage()];
	}

	if (count($rows) < 2) {
		return ['ok' => false, 'message' => 'That workbook does not have any data rows below the header row.'];
	}

	$headerRow = array_shift($rows);
	$cols = who_reference_match_headers($headerRow);

	if (!isset($cols['l'], $cols['m'], $cols['s'])) {
		return ['ok' => false, 'message' => 'Could not find L, M, and S columns in that file. Expected a header row with columns named L, M, S plus an age/day/length column.'];
	}

	$targetColumn = $indicatorConfig['column']; // 'age_months', 'age_days', or 'height_cm'
	$table = $indicatorConfig['table'];

	// Day-keyed age tables (WFA, HFA in their new day-based form) take
	// one row per day from the expanded WHO 2006 file. The Day column is
	// the canonical key here, not a fallback to derive monthly rows from.
	if ($targetColumn === 'age_days') {
		if (!isset($cols['day'])) {
			return ['ok' => false, 'message' => 'This is a day-based reference table, but no "Day" column was found in that file.'];
		}

		return who_reference_import_day_table($table, $sex, $rows, $cols);
	}

	if ($targetColumn === 'age_months') {
		if (isset($cols['day'])) {
			return who_reference_import_age_from_days($table, $sex, $rows, $cols);
		}

		if (isset($cols['age_months'])) {
			return who_reference_import_age_direct($table, $sex, $rows, $cols);
		}

		return ['ok' => false, 'message' => 'This is an age-based indicator, but no "Day" or "Age (months)" column was found in that file.'];
	}

	// height_cm
	if (!isset($cols['height_cm'])) {
		return ['ok' => false, 'message' => 'This is a length/height-based indicator, but no "Length" or "Height" column was found in that file.'];
	}

	return who_reference_import_height($table, $sex, $rows, $cols);
}

/** Imports a daily "expanded" table (Day, L, M, S, ...) by interpolating to WHO's 0-60 monthly grid. */
function who_reference_import_age_from_days(string $table, string $sex, array $rows, array $cols): array
{
	$dayMap = [];

	foreach ($rows as $row) {
		$day = $row[$cols['day']] ?? null;
		$l = $row[$cols['l']] ?? null;
		$m = $row[$cols['m']] ?? null;
		$s = $row[$cols['s']] ?? null;

		if ($day === null || $day === '' || !is_numeric($day) || !is_numeric($l) || !is_numeric($m) || !is_numeric($s)) {
			continue;
		}

		$dayMap[(int)round((float)$day)] = ['L' => (float)$l, 'M' => (float)$m, 'S' => (float)$s];
	}

	if ($dayMap === []) {
		return ['ok' => false, 'message' => 'No usable Day/L/M/S rows were found in that file.'];
	}

	$inserted = 0;
	$updated = 0;
	$skipped = 0;

	for ($month = 0; $month <= 60; $month++) {
		$targetDay = $month * 30.4375;
		$lms = who_reference_interpolate_day($dayMap, $targetDay);

		if ($lms === null) {
			$skipped++;
			continue;
		}

		$result = who_reference_upsert($table, 'age_months', $sex, $month, $lms['L'], $lms['M'], $lms['S']);

		if ($result === 'inserted') {
			$inserted++;
		} elseif ($result === 'updated') {
			$updated++;
		} else {
			$skipped++;
		}
	}

	return ['ok' => true, 'message' => "Imported {$sex} 0-60 months from the daily table.", 'inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped];
}

/** Imports a file that already has one row per whole month. */
function who_reference_import_age_direct(string $table, string $sex, array $rows, array $cols): array
{
	$inserted = 0;
	$updated = 0;
	$skipped = 0;

	foreach ($rows as $row) {
		$ageRaw = $row[$cols['age_months']] ?? null;
		$l = $row[$cols['l']] ?? null;
		$m = $row[$cols['m']] ?? null;
		$s = $row[$cols['s']] ?? null;

		if ($ageRaw === null || $ageRaw === '' || !is_numeric($ageRaw) || !is_numeric($l) || !is_numeric($m) || !is_numeric($s)) {
			$skipped++;
			continue;
		}

		$month = (int)round((float)$ageRaw);

		if ($month < 0 || $month > 60) {
			$skipped++;
			continue;
		}

		$result = who_reference_upsert($table, 'age_months', $sex, $month, (float)$l, (float)$m, (float)$s);

		if ($result === 'inserted') {
			$inserted++;
		} elseif ($result === 'updated') {
			$updated++;
		} else {
			$skipped++;
		}
	}

	return ['ok' => true, 'message' => "Imported {$sex} monthly rows.", 'inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped];
}

/**
 * Imports the WHO 2006 expanded daily table (one row per day) directly
 * into a day-keyed reference table. The expanded table provides ~1857
 * rows (Day 0..1856) per sex, which is the source of truth for the
 * `who_weight_for_age_days` / `who_height_for_age_days` lookups used by
 * the WAZ/HAZ calculator. The day count is intentionally NOT bounded
 * here -- the migration that created the day tables added a unique key
 * on (sex, age_days), so any out-of-range file is safely skipped rather
 * than failing the whole import.
 */
function who_reference_import_day_table(string $table, string $sex, array $rows, array $cols): array
{
	$inserted = 0;
	$updated = 0;
	$skipped = 0;

	foreach ($rows as $row) {
		$dayRaw = $row[$cols['day']] ?? null;
		$l = $row[$cols['l']] ?? null;
		$m = $row[$cols['m']] ?? null;
		$s = $row[$cols['s']] ?? null;

		if ($dayRaw === null || $dayRaw === '' || !is_numeric($dayRaw) || !is_numeric($l) || !is_numeric($m) || !is_numeric($s)) {
			$skipped++;
			continue;
		}

		$day = (int)round((float)$dayRaw);

		if ($day < 0 || $day > 1856) {
			$skipped++;
			continue;
		}

		$result = who_reference_upsert($table, 'age_days', $sex, $day, (float)$l, (float)$m, (float)$s);

		if ($result === 'inserted') {
			$inserted++;
		} elseif ($result === 'updated') {
			$updated++;
		} else {
			$skipped++;
		}
	}

	return [
		'ok' => true,
		'message' => "Imported {$sex} day-keyed rows.",
		'inserted' => $inserted,
		'updated' => $updated,
		'skipped' => $skipped,
	];
}

/** Imports a length/height-keyed table (any decimal step - the calculator already finds the nearest row). */
function who_reference_import_height(string $table, string $sex, array $rows, array $cols): array
{
	$inserted = 0;
	$updated = 0;
	$skipped = 0;

	foreach ($rows as $row) {
		$heightRaw = $row[$cols['height_cm']] ?? null;
		$l = $row[$cols['l']] ?? null;
		$m = $row[$cols['m']] ?? null;
		$s = $row[$cols['s']] ?? null;

		if ($heightRaw === null || $heightRaw === '' || !is_numeric($heightRaw) || !is_numeric($l) || !is_numeric($m) || !is_numeric($s)) {
			$skipped++;
			continue;
		}

		$height = round((float)$heightRaw, 1);

		if ($height < 30 || $height > 150) {
			$skipped++;
			continue;
		}

		$result = who_reference_upsert($table, 'height_cm', $sex, $height, (float)$l, (float)$m, (float)$s);

		if ($result === 'inserted') {
			$inserted++;
		} elseif ($result === 'updated') {
			$updated++;
		} else {
			$skipped++;
		}
	}

	return ['ok' => true, 'message' => "Imported {$sex} rows.", 'inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped];
}

/** Inserts a row, or updates it in place if (sex, column) already exists (uq_wfa / uq_hfa / uq_wfh / uq_wfad / uq_hfad). */
function who_reference_upsert(string $table, string $column, string $sex, int|float $x, float $l, float $m, float $s): string
{
	$conn = get_db_connection();
	// age-based lookups (both monthly and day-based) are integer keys
	// in the DB, height-based lookups use decimal so the closest-row
	// fallback can find an exact match on a 0.1cm step.
	$xType = in_array($column, ['age_months', 'age_days'], true) ? 'i' : 'd';

	$sql = "INSERT INTO {$table} (sex, {$column}, L, M, S) VALUES (?, ?, ?, ?, ?)
	        ON DUPLICATE KEY UPDATE L = VALUES(L), M = VALUES(M), S = VALUES(S)";

	$stmt = mysqli_prepare($conn, $sql);

	if ($stmt === false) {
		return 'skipped';
	}

	mysqli_stmt_bind_param($stmt, 's' . $xType . 'ddd', $sex, $x, $l, $m, $s);
	$ok = mysqli_stmt_execute($stmt);
	$wasInsert = $ok && mysqli_stmt_affected_rows($stmt) === 1;
	mysqli_stmt_close($stmt);

	if (!$ok) {
		return 'skipped';
	}

	// MySQL reports affected_rows=1 for a fresh insert, 2 for an upsert that
	// changed a row, and 0 for an upsert where the values were identical.
	return $wasInsert ? 'inserted' : 'updated';
}

/**
 * Validates an uploaded file as a genuine .xlsx (extension + zip magic
 * bytes) before it's handed to the reader. Only .xlsx is accepted, per the
 * WHO reference import screen's design.
 */
function who_reference_validate_xlsx_upload(array $file): ?string
{
	if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
		return 'Choose an .xlsx file to import first.';
	}

	if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
		return 'That file failed to upload. Please try again.';
	}

	$name = (string)($file['name'] ?? '');

	if (strtolower((string)pathinfo($name, PATHINFO_EXTENSION)) !== 'xlsx') {
		return 'Only .xlsx files are accepted for import.';
	}

	$maxBytes = 8 * 1024 * 1024; // 8MB is generous for a ~1,900-row LMS table

	if ((int)($file['size'] ?? 0) > $maxBytes) {
		return 'That file is too large (max 8MB).';
	}

	$handle = @fopen((string)$file['tmp_name'], 'rb');

	if ($handle === false) {
		return 'That file could not be read.';
	}

	$magic = fread($handle, 4);
	fclose($handle);

	// .xlsx files are zip archives, which always start with "PK\x03\x04".
	if ($magic !== "PK\x03\x04") {
		return 'That file is not a valid .xlsx workbook.';
	}

	return null;
}
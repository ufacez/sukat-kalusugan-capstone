<?php
/**
 * db/seeders/seed_who_age_days.php
 *
 * One-shot seeder for the day-keyed WHO 2006 expanded reference tables
 * introduced by db/20260831_who_age_days_migration.sql. Reads the
 * official xlsx files from public_html/data/who lms 2006expanded/
 * (1857 daily rows each, per sex) and upserts them into
 *   who_weight_for_age_days
 *   who_height_for_age_days
 *
 * Run from CLI once after applying the migration:
 *   php db/seeders/seed_who_age_days.php
 *
 * Safe to re-run -- the importer upserts on (sex, age_days) and only
 * touches rows that don't already exist or whose LMS values differ.
 */

require_once __DIR__ . '/../../public_html/includes/db.php';
require_once __DIR__ . '/../../public_html/includes/xlsx_lite.php';

// Resolve the data folder from this script's location. The seeder lives
// at   <project>/db/seeders/seed_who_age_days.php
// so the data folder is   <project>/public_html/who lms 2006expanded
// = 2 levels up from __DIR__, then down into public_html/who lms 2006expanded
$baseDir = realpath(__DIR__ . '/../../public_html/who lms 2006expanded');

if ($baseDir === false || $baseDir === '') {
	fwrite(STDERR, "Could not resolve the data folder from __DIR__=" . __DIR__ . PHP_EOL);
	fwrite(STDERR, "Tried: " . __DIR__ . '/../../public_html/who lms 2006expanded' . PHP_EOL);
	exit(1);
}

$jobs = [
	[
		'xlsx' => $baseDir . DIRECTORY_SEPARATOR . 'WFA' . DIRECTORY_SEPARATOR . 'wfa-boys-zscore-expanded-tables.xlsx',
		'table' => 'who_weight_for_age_days',
		'sex' => 'Male',
		'indicator' => 'WFA',
	],
	[
		'xlsx' => $baseDir . DIRECTORY_SEPARATOR . 'WFA' . DIRECTORY_SEPARATOR . 'wfa-girls-zscore-expanded-tables.xlsx',
		'table' => 'who_weight_for_age_days',
		'sex' => 'Female',
		'indicator' => 'WFA',
	],
	[
		'xlsx' => $baseDir . DIRECTORY_SEPARATOR . 'LHFA' . DIRECTORY_SEPARATOR . 'lhfa-boys-zscore-expanded-tables.xlsx',
		'table' => 'who_height_for_age_days',
		'sex' => 'Male',
		'indicator' => 'LHFA',
	],
	[
		'xlsx' => $baseDir . DIRECTORY_SEPARATOR . 'LHFA' . DIRECTORY_SEPARATOR . 'lhfa-girls-zscore-expanded-tables.xlsx',
		'table' => 'who_height_for_age_days',
		'sex' => 'Female',
		'indicator' => 'LHFA',
	],
];

$conn = get_db_connection();
$totalInserted = 0;
$totalUpdated = 0;
$totalSkipped = 0;

foreach ($jobs as $job) {
	echo "Importing {$job['indicator']} {$job['sex']} from " . basename($job['xlsx']) . " ...\n";

	if (!is_file($job['xlsx'])) {
		echo "  ! file missing: {$job['xlsx']}\n";
		$totalSkipped += 1857;
		continue;
	}

	try {
		$rows = xlsx_lite_read_first_sheet($job['xlsx']);
	} catch (Throwable $e) {
		echo "  ! could not read xlsx: " . $e->getMessage() . "\n";
		$totalSkipped += 1857;
		continue;
	}

	if (count($rows) < 2) {
		echo "  ! xlsx has no data rows\n";
		continue;
	}

	$header = array_shift($rows);
	$normalized = array_map(static fn ($v) => strtolower(trim((string)$v)), $header);
	$dayIdx = array_search('day', $normalized, true);
	$lIdx = array_search('l', $normalized, true);
	$mIdx = array_search('m', $normalized, true);
	$sIdx = array_search('s', $normalized, true);

	if ($dayIdx === false || $lIdx === false || $mIdx === false || $sIdx === false) {
		echo "  ! header missing required columns (Day, L, M, S); got: " . implode(', ', $header) . "\n";
		$totalSkipped += count($rows);
		continue;
	}

	$inserted = 0;
	$updated = 0;
	$skipped = 0;

	$stmt = mysqli_prepare(
		$conn,
		"INSERT INTO {$job['table']} (sex, age_days, L, M, S)
		 VALUES (?, ?, ?, ?, ?)
		 ON DUPLICATE KEY UPDATE L = VALUES(L), M = VALUES(M), S = VALUES(S)"
	);

	if ($stmt === false) {
		echo "  ! prepare failed: " . mysqli_error($conn) . "\n";
		continue;
	}

	foreach ($rows as $row) {
		$day = $row[$dayIdx] ?? null;
		$l = $row[$lIdx] ?? null;
		$m = $row[$mIdx] ?? null;
		$s = $row[$sIdx] ?? null;

		if ($day === null || $day === '' || !is_numeric($day) || !is_numeric($l) || !is_numeric($m) || !is_numeric($s)) {
			$skipped++;
			continue;
		}

		$dayInt = (int)round((float)$day);

		if ($dayInt < 0 || $dayInt > 1856) {
			$skipped++;
			continue;
		}

		// mysqli_stmt_bind_param requires real variables (by-reference),
		// so stash the cast values into locals before binding.
		$sexVar = $job['sex'];
		$dayVar = $dayInt;
		$lVar = (float)$l;
		$mVar = (float)$m;
		$sVar = (float)$s;
		mysqli_stmt_bind_param($stmt, 'siddd', $sexVar, $dayVar, $lVar, $mVar, $sVar);
		$ok = mysqli_stmt_execute($stmt);

		if (!$ok) {
			$skipped++;
			continue;
		}

		// MySQL reports 1 for fresh inserts and 2 for upserts that
		// actually changed a row (0 means values were identical).
		$affected = mysqli_stmt_affected_rows($stmt);
		if ($affected === 1) {
			$inserted++;
		} elseif ($affected === 2) {
			$updated++;
		} else {
			$skipped++;
		}
	}

	mysqli_stmt_close($stmt);

	echo sprintf("  %s %s: %d inserted, %d updated, %d skipped\n", $job['indicator'], $job['sex'], $inserted, $updated, $skipped);

	$totalInserted += $inserted;
	$totalUpdated += $updated;
	$totalSkipped += $skipped;
}

echo "\nDone.\n";
echo sprintf("Total: %d inserted, %d updated, %d skipped\n", $totalInserted, $totalUpdated, $totalSkipped);
echo "Verify: SELECT sex, COUNT(*) FROM who_weight_for_age_days GROUP BY sex;\n";
echo "        SELECT sex, COUNT(*) FROM who_height_for_age_days GROUP BY sex;\n";

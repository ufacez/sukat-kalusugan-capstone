<?php

/**
 * tools/validate_sft_vs_db.php
 *
 * Validates the live who_weight_for_length / who_weight_for_height reference
 * tables against the WHO Simplified Field Tables extracted from the PDFs in
 * public_html/data/ (see simplified_field_tables_girls.json).
 *
 * For every length/height key covered by both sources, this computes the
 * -3SD / -2SD / +2SD / +3SD cutoffs from the DB's stored LMS values and
 * compares them with the PDF's published cutoff values.
 *
 * Usage (CLI):
 *   php tools/validate_sft_vs_db.php
 *
 * Exit code 0 = all checks pass, 1 = discrepancies found.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	exit("CLI only\n");
}

require __DIR__ . '/../public_html/includes/config.php';
require __DIR__ . '/../public_html/includes/db.php';

const TOLERANCE_KG = 0.06;

/**
 * WHO simplified field tables, extracted from:
 *   - sft-wfl-girls-z-0-2.pdf (weight-for-length, girls, birth to 2 years)
 *   - sft-wfh-girls-z-2-5.pdf (weight-for-height, girls, 2 to 5 years)
 */
$sftPath = __DIR__ . '/../public_html/data/simplified_field_tables_girls.json';

if (!is_file($sftPath)) {
	fwrite(STDERR, "Missing {$sftPath} -- extract the PDFs first.\n");
	exit(1);
}

$sft = json_decode((string)file_get_contents($sftPath), true);

$sources = [
	'who_weight_for_length' => 'sft-wfl-girls-z-0-2',
	'who_weight_for_height' => 'sft-wfh-girls-z-2-5',
];

$conn = get_db_connection();
$failures = 0;
$checked = 0;
$missingInDb = [];

function lms_cutoff(float $l, float $m, float $s, float $z): float
{
	if (abs($l) < 0.000001) {
		return $m * exp($s * $z);
	}

	$x = 1.0 + $l * $s * $z;

	return $x <= 0.0 ? NAN : $m * pow($x, 1.0 / $l);
}

foreach ($sources as $dbTable => $sftKey) {
	echo "== {$dbTable} vs {$sftKey} ==\n";

	foreach ($sft[$sftKey] as $row) {
		$cm = (float)$row['cm'];

		$stmt = mysqli_prepare($conn, "SELECT L, M, S FROM `{$dbTable}` WHERE sex='Female' AND height_cm = ?");
		mysqli_stmt_bind_param($stmt, 'd', $cm);
		mysqli_stmt_execute($stmt);
		$res = mysqli_stmt_get_result($stmt);
		$db = mysqli_fetch_assoc($res);
		mysqli_stmt_close($stmt);

		if ($db === null) {
			$missingInDb[$dbTable][] = $cm;
			continue;
		}

		foreach ([[-3, 'm3'], [-2, 'm2'], [2, 'p2'], [3, 'p3']] as [$z, $key]) {
			$expected = (float)$row[$key];
			$actual = lms_cutoff((float)$db['L'], (float)$db['M'], (float)$db['S'], (float)$z);
			$checked++;

			if (abs($actual - $expected) > TOLERANCE_KG) {
				$failures++;
				printf(
					"  MISMATCH %s @ %scm z=%+d: db=%.3f pdf=%.1f diff=%+.3f\n",
					$dbTable,
					number_format($cm, 1),
					$z,
					$actual,
					$expected,
					$actual - $expected
				);
			}
		}
	}
}

echo "\nchecked={$checked} mismatches={$failures}\n";

foreach ($missingInDb as $table => $cms) {
	printf(
		"MISSING IN DB %s (Female): %d keys, %.1f..%.1f cm\n",
		$table,
		count($cms),
		min($cms),
		max($cms)
	);
}

exit($failures === 0 ? 0 : 1);

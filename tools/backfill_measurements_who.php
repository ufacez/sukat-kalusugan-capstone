<?php

/**
 * tools/backfill_measurements_who.php
 *
 * Recomputes WHO z-scores, classifications, and data-quality flags for every
 * row in `measurements` using the CURRENT reference tables and calculator,
 * then updates rows whose stored values differ.
 *
 * Age semantics: uses the stored per-measurement `age_months` snapshot (the
 * schema's documented contract) -- birthdates are NOT re-evaluated.
 *
 * Run AFTER applying db/20260826_who_reference_rebuild_expanded.sql.
 *
 * Usage (CLI):
 *   php tools/backfill_measurements_who.php            # live run
 *   php tools/backfill_measurements_who.php --dry-run  # report only
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	exit("CLI only\n");
}

require __DIR__ . '/../public_html/includes/config.php';
require __DIR__ . '/../public_html/includes/db.php';
require __DIR__ . '/../public_html/includes/who_calculator.php';

$dryRun = in_array('--dry-run', $argv, true);

$conn = get_db_connection();

$res = mysqli_query(
	$conn,
	'SELECT m.id, m.height_cm, m.weight_kg, m.age_months,
	        m.waz, m.haz, m.whz,
	        m.nutritional_status, m.wfa_status, m.hfa_status, m.wfh_status,
	        m.is_flagged, m.flag_reason,
	        c.sex
	 FROM measurements m
	 INNER JOIN children c ON c.id = m.child_id
	 ORDER BY m.id'
);

if ($res === false) {
	fwrite(STDERR, 'Query failed: ' . mysqli_error($conn) . "\n");
	exit(1);
}

$total = 0;
$updated = 0;
$skipped = 0;

while ($row = mysqli_fetch_assoc($res)) {
	$total++;

	$height = (float)$row['height_cm'];
	$weight = (float)$row['weight_kg'];
	$age = (int)$row['age_months'];
	$sex = (string)$row['sex'];

	if ($height <= 0 || $weight <= 0 || $age < 0) {
		$skipped++;
		echo "skip #{$row['id']}: invalid stored values\n";
		continue;
	}

	// NOTE: calculate_who_metrics() signature is ($weight_kg, $height_cm, ...)
	$m = calculate_who_metrics($weight, $height, $age, $sex);

	$diffs = [];
	foreach (['waz', 'haz', 'whz'] as $k) {
		$old = $row[$k] !== null ? round((float)$row[$k], 2) : null;
		if ($old !== $m[$k]) {
			$diffs[$k] = [$old, $m[$k]];
		}
	}
	foreach (['nutritional_status', 'wfa_status', 'hfa_status', 'wfh_status', 'flag_reason'] as $k) {
		$newVal = $m[$k === 'flag_reason' ? 'flag_reason' : $k];
		if ($k === 'flag_reason') {
			$newVal = $m['flag_reason'];
		}
		$oldVal = $row[$k];
		if ((string)($oldVal ?? '') !== (string)($newVal ?? '')) {
			$diffs[$k] = [$oldVal, $newVal];
		}
	}
	$oldFlag = (int)($row['is_flagged'] ?? 0);
	$newFlag = $m['is_flagged'] ? 1 : 0;
	if ($oldFlag !== $newFlag) {
		$diffs['is_flagged'] = [$oldFlag, $newFlag];
	}

	if ($diffs === []) {
		continue;
	}

	$updated++;
	$changes = [];
	foreach ($diffs as $k => [$o, $n]) {
		$changes[] = "{$k}: " . var_export($o, true) . " -> " . var_export($n, true);
	}
	echo "#{$row['id']} ({$sex}, {$age}mo, h={$height}, w={$weight}):\n  " . implode("\n  ", $changes) . "\n";

	if (!$dryRun) {
		$stmt = mysqli_prepare(
			$conn,
			'UPDATE measurements
			 SET waz = ?, haz = ?, whz = ?, nutritional_status = ?, wfa_status = ?,
			     hfa_status = ?, wfh_status = ?, is_flagged = ?, flag_reason = ?
			 WHERE id = ?'
		);
		$waz = $m['waz']; $haz = $m['haz']; $whz = $m['whz'];
		$ns = $m['nutritional_status']; $wf = $m['wfa_status'];
		$hf = $m['hfa_status']; $wh = $m['wfh_status'];
		$isF = $newFlag; $fr = $m['flag_reason']; $id = (int)$row['id'];
		mysqli_stmt_bind_param($stmt, 'dddssssisi', $waz, $haz, $whz, $ns, $wf, $hf, $wh, $isF, $fr, $id);
		if (!mysqli_stmt_execute($stmt)) {
			fwrite(STDERR, 'UPDATE failed for #' . $id . ': ' . mysqli_error($conn) . "\n");
			mysqli_stmt_close($stmt);
			exit(1);
		}
		mysqli_stmt_close($stmt);
	}
}

echo ($dryRun ? '[DRY RUN] ' : '') . "scanned={$total} updated={$updated} skipped={$skipped}\n";

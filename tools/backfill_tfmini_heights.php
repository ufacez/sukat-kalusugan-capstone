<?php

/**
 * tools/backfill_tfmini_heights.php
 *
 * Corrects kiosk heights inflated by the TFmini unit-scale fault (sensor
 * reported centimeters, firmware parsed as millimeters): H_true = 10*H_rec
 * - 9*M, then recomputes WHO z-scores/classifications/flags with the
 * current calculator — same recompute pattern as backfill_measurements_who.php.
 *
 * SAFETY:
 *   - Dry-run by default. Add --apply to write. There is no undo besides
 *     restoring from backup — take a DB dump first.
 *   - Only touches rows inside the suspect band (default [0.9*M+3, 0.9*M+16],
 *     e.g. [129.0, 142.0] for M=140) whose corrected height is plausible
 *     (30..160 cm). Corrected rows leave the band, so a second run is a
 *     no-op — but still scope with --before=<reflash date> so genuine tall
 *     children measured after the fix are never touched.
 *   - Run tools/audit_tfmini_measurements.php first and review its output.
 *
 * Usage (CLI):
 *   php tools/backfill_tfmini_heights.php --before=2026-09-14          # dry run
 *   php tools/backfill_tfmini_heights.php --before=2026-09-14 --apply  # live run
 *   php tools/backfill_tfmini_heights.php --only-id=123 --apply        # single row
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	exit("CLI only\n");
}

require __DIR__ . '/../public_html/includes/config.php';
require __DIR__ . '/../public_html/includes/db.php';
require __DIR__ . '/../public_html/includes/who_calculator.php';
require __DIR__ . '/../public_html/includes/audit_logger.php';

function opt(string $name, ?string $default = null): ?string
{
	foreach ($GLOBALS['argv'] as $arg) {
		if (str_starts_with($arg, '--' . $name . '=')) {
			return substr($arg, strlen($name) + 3);
		}
	}
	return $default;
}

$apply = in_array('--apply', $argv, true);
$deviceCode = (string)(opt('device', 'ESP32-KIOSK-01') ?? 'ESP32-KIOSK-01');
$mounting = (float)(opt('mounting', '140.00') ?? '140.00');
$recMin = opt('rec-min') !== null ? (float)opt('rec-min') : round(0.9 * $mounting + 3, 1);
$recMax = opt('rec-max') !== null ? (float)opt('rec-max') : round(0.9 * $mounting + 16, 1);
$before = opt('before', date('Y-m-d'));
$after = opt('after');
$onlyId = opt('only-id') !== null ? (int)opt('only-id') : 0;

if (!$apply) {
	echo "*** DRY RUN — no rows will be changed. Add --apply to write. ***\n";
}

$conn = get_db_connection();

$devStmt = mysqli_prepare($conn, 'SELECT id FROM devices WHERE device_code = ? LIMIT 1');
mysqli_stmt_bind_param($devStmt, 's', $deviceCode);
mysqli_stmt_execute($devStmt);
$devRes = mysqli_stmt_get_result($devStmt);
$devRow = $devRes instanceof mysqli_result ? mysqli_fetch_assoc($devRes) : null;
mysqli_stmt_close($devStmt);

if (!is_array($devRow)) {
	fwrite(STDERR, "Device not found: {$deviceCode}\n");
	exit(1);
}
$deviceId = (int)$devRow['id'];

$where = 'm.device_id = ? AND m.source_type = \'kiosk\' AND m.height_cm BETWEEN ? AND ?';
$types = 'idd';
$params = [$deviceId, $recMin, $recMax];

if ($before !== null && $before !== '') {
	$where .= ' AND m.measurement_date <= ?';
	$types .= 's';
	$params[] = $before;
}
if ($after !== null && $after !== '') {
	$where .= ' AND m.measurement_date >= ?';
	$types .= 's';
	$params[] = $after;
}
if ($onlyId > 0) {
	$where .= ' AND m.id = ?';
	$types .= 'i';
	$params[] = $onlyId;
}

$stmt = mysqli_prepare(
	$conn,
	"SELECT m.id, m.child_id, m.height_cm, m.weight_kg, m.age_months, m.age_days,
	        m.measurement_date, m.nutritional_status,
	        c.child_code, c.first_name, c.last_name, c.sex
	 FROM measurements m
	 INNER JOIN children c ON c.id = m.child_id
	 WHERE {$where}
	 ORDER BY m.id ASC"
);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$rows = $res instanceof mysqli_result ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
mysqli_stmt_close($stmt);

$scanned = count($rows);
$updated = 0;
$skippedImplausible = 0;

$updStmt = null;
if ($apply) {
	$updStmt = mysqli_prepare(
		$conn,
		'UPDATE measurements
		 SET height_cm = ?, waz = ?, haz = ?, whz = ?, nutritional_status = ?,
		     wfa_status = ?, hfa_status = ?, wfh_status = ?,
		     is_flagged = ?, flag_reason = ?
		 WHERE id = ?'
	);
	if ($updStmt === false) {
		fwrite(STDERR, 'Prepare failed: ' . mysqli_error($conn) . "\n");
		exit(1);
	}
}

foreach ($rows as $row) {
	$id = (int)$row['id'];
	$recH = (float)$row['height_cm'];
	$corrH = round(10 * $recH - 9 * $mounting, 2);

	if ($corrH < 30 || $corrH > 160) {
		$skippedImplausible++;
		echo "skip #{$id}: corrected height {$corrH} implausible — re-measure manually\n";
		continue;
	}

	$weight = (float)$row['weight_kg'];
	$age = (int)($row['age_days'] ?? 0);
	if ($age <= 0) {
		$age = (int)$row['age_months'] * 30;
	}
	$sex = (string)$row['sex'];

	if ($weight <= 0 || $age < 0) {
		$skippedImplausible++;
		echo "skip #{$id}: invalid weight/age for WHO recompute\n";
		continue;
	}

	$m = calculate_who_metrics($weight, $corrH, $age, $sex);
	$name = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
	echo "#{$id} {$row['measurement_date']} {$row['child_code']} ({$name}): "
		. "h {$recH} -> {$corrH} | {$row['nutritional_status']} -> {$m['nutritional_status']}"
		. ($m['is_flagged'] ? " | FLAGGED: {$m['flag_reason']}" : '') . "\n";

	if ($apply && $updStmt !== null) {
		$waz = $m['waz']; $haz = $m['haz']; $whz = $m['whz'];
		$ns = $m['nutritional_status']; $wf = $m['wfa_status'];
		$hf = $m['hfa_status']; $wh = $m['wfh_status'];
		$isF = $m['is_flagged'] ? 1 : 0; $fr = $m['flag_reason'];
		mysqli_stmt_bind_param($updStmt, 'dddsssssisi', $corrH, $waz, $haz, $whz, $ns, $wf, $hf, $wh, $isF, $fr, $id);
		if (!mysqli_stmt_execute($updStmt)) {
			fwrite(STDERR, "UPDATE failed for #{$id}: " . mysqli_stmt_error($updStmt) . "\n");
			mysqli_stmt_close($updStmt);
			exit(1);
		}
		$updated++;
	} else {
		$updated++;
	}
}

if ($updStmt !== null) {
	mysqli_stmt_close($updStmt);
}

if ($apply) {
	log_action(
		null,
		'TFMINI_BACKFILL',
		'info',
		sprintf(
			'TFmini unit-scale backfill on %s (mounting %.2f, band [%.1f, %.1f], before %s): %d corrected, %d skipped-needs-manual, %d scanned.',
			$deviceCode, $mounting, $recMin, $recMax, (string)$before, $updated, $skippedImplausible, $scanned
		)
	);
}

echo ($apply ? '' : '[DRY RUN] ') . "scanned={$scanned} corrected={$updated} skipped_implausible={$skippedImplausible}\n";

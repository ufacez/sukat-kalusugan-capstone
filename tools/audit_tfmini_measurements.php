<?php

/**
 * tools/audit_tfmini_measurements.php
 *
 * READ-ONLY audit: lists kiosk measurements suspected of TFmini unit-scale
 * inflation (sensor reported centimeters, firmware parsed as millimeters,
 * so every height read ~10x too small a gap subtracted from mounting).
 *
 * Fault math (M = mounting height while faulty, H_rec = recorded height):
 *   H_rec = M - G_true/10   =>   H_true = 10*H_rec - 9*M
 * A recorded height is "suspect" when it falls in the band produced by
 * plausible true heights (30..160 cm): [0.9*M + 3, 0.9*M + 16].
 * With M = 140 that is [129.0, 142.0] — far above any real child, which
 * is exactly why the band is safe: genuine rows never land in it.
 *
 * Scope defaults to kiosk-source rows from one device. Manual/mobile rows
 * are typed by humans and unaffected by the sensor.
 *
 * Usage (CLI, never writes):
 *   php tools/audit_tfmini_measurements.php
 *   php tools/audit_tfmini_measurements.php --device=ESP32-KIOSK-01 --mounting=140.00
 *   php tools/audit_tfmini_measurements.php --rec-min=129 --rec-max=142
 *   php tools/audit_tfmini_measurements.php --source=all --before=2026-09-14
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	exit("CLI only\n");
}

require __DIR__ . '/../public_html/includes/config.php';
require __DIR__ . '/../public_html/includes/db.php';
require __DIR__ . '/../public_html/includes/who_calculator.php';

function opt(string $name, ?string $default = null): ?string
{
	foreach ($GLOBALS['argv'] as $arg) {
		if (str_starts_with($arg, '--' . $name . '=')) {
			return substr($arg, strlen($name) + 3);
		}
	}
	return $default;
}

$deviceCode = (string)(opt('device', 'ESP32-KIOSK-01') ?? 'ESP32-KIOSK-01');
$mounting = (float)(opt('mounting', '140.00') ?? '140.00');
$recMin = opt('rec-min') !== null ? (float)opt('rec-min') : round(0.9 * $mounting + 3, 1);
$recMax = opt('rec-max') !== null ? (float)opt('rec-max') : round(0.9 * $mounting + 16, 1);
$source = (string)(opt('source', 'kiosk') ?? 'kiosk');
$before = opt('before');
$after = opt('after');

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

$where = 'm.device_id = ? AND m.height_cm BETWEEN ? AND ?';
$types = 'idd';
$params = [$deviceId, $recMin, $recMax];

if ($source !== 'all') {
	$where .= ' AND m.source_type = ?';
	$types .= 's';
	$params[] = $source;
}
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

$stmt = mysqli_prepare(
	$conn,
	"SELECT m.id, m.child_id, m.height_cm, m.weight_kg, m.age_months, m.age_days,
	        m.measurement_date, m.source_type, m.measurement_type,
	        m.nutritional_status, m.wfa_status, m.hfa_status, m.wfh_status,
	        c.child_code, c.first_name, c.last_name, c.sex
	 FROM measurements m
	 INNER JOIN children c ON c.id = m.child_id
	 WHERE {$where}
	 ORDER BY m.measurement_date ASC, m.id ASC"
);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$totalDevice = 0;
$c1 = mysqli_prepare($conn, 'SELECT COUNT(*) FROM measurements WHERE device_id = ?');
mysqli_stmt_bind_param($c1, 'i', $deviceId);
mysqli_stmt_execute($c1);
$cr = mysqli_stmt_get_result($c1);
$totalDevice = $cr ? (int)mysqli_fetch_row($cr)[0] : 0;
mysqli_stmt_close($c1);

echo "device={$deviceCode} (id={$deviceId}) mounting={$mounting} band=[{$recMin}, {$recMax}] source={$source}\n";
echo "total_rows_on_device={$totalDevice}\n";
echo str_repeat('-', 110) . "\n";

$suspect = 0;
$plausible = 0;
$implausible = 0;

if ($res instanceof mysqli_result) {
	while ($row = mysqli_fetch_assoc($res)) {
		$suspect++;
		$recH = (float)$row['height_cm'];
		$corrH = round(10 * $recH - 9 * $mounting, 2);
		$ok = ($corrH >= 30 && $corrH <= 160);
		$ok ? $plausible++ : $implausible++;

		$age = (int)($row['age_days'] ?? 0);
		if ($age <= 0) {
			$age = (int)$row['age_months'] * 30;
		}
		$newStatus = '?';
		try {
			$m = calculate_who_metrics((float)$row['weight_kg'], $corrH, $age, (string)$row['sex']);
			$newStatus = (string)$m['nutritional_status'];
		} catch (Throwable) {
			$newStatus = '(calc failed)';
		}

		$name = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
		printf(
			"#%d %s %s (%s) rec_h=%.2f w=%.3f -> corr_h=%.2f [%s] status: %s -> %s\n",
			(int)$row['id'],
			(string)$row['measurement_date'],
			(string)$row['child_code'],
			$name,
			$recH,
			(float)$row['weight_kg'],
			$corrH,
			$ok ? 'PLAUSIBLE' : 'NEEDS MANUAL RE-MEASURE',
			(string)($row['nutritional_status'] ?? '?'),
			$newStatus
		);
	}
}
mysqli_stmt_close($stmt);

echo str_repeat('-', 110) . "\n";
echo "suspect={$suspect} plausible_correction={$plausible} needs_manual={$implausible}\n";
echo "READ-ONLY: no rows changed. To correct plausible rows, see tools/backfill_tfmini_heights.php\n";

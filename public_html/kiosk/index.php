<?php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/firebase_sync.php';

function kiosk_e(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function kiosk_fetch_all(string $sql, string $types = '', array $params = []): array
{
	$conn = get_db_connection();
	$stmt = mysqli_prepare($conn, $sql);

	if ($stmt === false) {
		return [];
	}

	if ($types !== '' && $params !== []) {
		$bindArgs = [$stmt, $types];

		foreach ($params as $index => &$value) {
			$bindArgs[] = &$value;
		}

		call_user_func_array('mysqli_stmt_bind_param', $bindArgs);
	}

	mysqli_stmt_execute($stmt);
	$result = mysqli_stmt_get_result($stmt);
	$rows = [];

	if ($result instanceof mysqli_result) {
		while ($row = mysqli_fetch_assoc($result)) {
			$rows[] = $row;
		}
	}

	mysqli_stmt_close($stmt);

	return $rows;
}

function kiosk_age_months(?string $birthdate): int
{
	if ($birthdate === null || $birthdate === '') {
		return 0;
	}

	$birth = new DateTimeImmutable($birthdate);
	$today = new DateTimeImmutable('today');
	$diff = $birth->diff($today);

	return ($diff->y * 12) + $diff->m;
}

function kiosk_result_from_measurement(float $weightKg, float $heightCm, int $ageMonths): array
{
	$wazMedian = 3.5 + ($ageMonths * 0.24);
	$hazMedian = 49 + ($ageMonths * 0.82);
	$whzMedian = 10.5 + (($heightCm - 65) * 0.09);

	$waz = round(($weightKg - $wazMedian) / 1.08, 2);
	$haz = round(($heightCm - $hazMedian) / 2.3, 2);
	$whz = round(($weightKg - $whzMedian) / 1.05, 2);

	$status = 'Normal';

	if ($waz < -3 || $whz < -3) {
		$status = 'Severely Underweight';
	} elseif ($waz < -2 || $whz < -2) {
		$status = 'Underweight';
	} elseif ($haz < -2) {
		$status = 'Stunted';
	} elseif ($whz > 2) {
		$status = 'Overweight';
	}

	return [
		'waz' => $waz,
		'haz' => $haz,
		'whz' => $whz,
		'status' => $status,
	];
}

function kiosk_person_name(array $child): string
{
	return trim((string)($child['first_name'] ?? '') . ' ' . (string)($child['last_name'] ?? ''));
}

$children = kiosk_fetch_all(
	'SELECT
		c.id,
		c.child_code,
		c.first_name,
		c.last_name,
		c.birthdate,
		c.sex,
		c.barangay,
		c.address,
		p.name AS parent_name,
		p.parent_type,
		p.status AS parent_status,
		lm.measurement_date,
		lm.height_cm,
		lm.weight_kg,
		lm.waz,
		lm.haz,
		lm.whz,
		lm.nutritional_status
	 FROM children c
	 INNER JOIN parents p ON p.id = c.parent_id
	 LEFT JOIN measurements lm ON lm.id = (
		SELECT m.id
		FROM measurements m
		WHERE m.child_id = c.id
		ORDER BY m.measurement_date DESC, m.id DESC
		LIMIT 1
	 )
	 ORDER BY c.last_name ASC, c.first_name ASC'
);

$devices = kiosk_fetch_all(
	'SELECT device_code, location, status, last_calibration_at, calibration_offset_height, calibration_offset_weight, updated_at
	 FROM devices
	 ORDER BY updated_at DESC, id DESC
	 LIMIT 4'
);

$measurements = kiosk_fetch_all(
	'SELECT
		m.measurement_date,
		m.height_cm,
		m.weight_kg,
		m.waz,
		m.haz,
		m.whz,
		m.nutritional_status,
		m.source_type,
		c.child_code,
		c.first_name,
		c.last_name,
		c.barangay
	 FROM measurements m
	 INNER JOIN children c ON c.id = m.child_id
	 ORDER BY m.measurement_date DESC, m.id DESC
	 LIMIT 12'
);

$audit = kiosk_fetch_all(
	'SELECT created_at, action, level, description
	 FROM audit_logs
	 WHERE action LIKE ? OR action LIKE ?
	 ORDER BY created_at DESC, id DESC
	 LIMIT 8',
	'ss',
	['KIOSK%', 'ESP32%']
);

$childrenPayload = array_map(static function (array $child): array {
	return [
		'id' => (int)$child['id'],
		'child_code' => (string)$child['child_code'],
		'first_name' => (string)$child['first_name'],
		'last_name' => (string)$child['last_name'],
		'sex' => (string)$child['sex'],
		'age_months' => kiosk_age_months((string)($child['birthdate'] ?? '')),
		'barangay' => (string)($child['barangay'] ?? ''),
		'parent_name' => (string)($child['parent_name'] ?? ''),
		'parent_type' => (string)($child['parent_type'] ?? ''),
		'status' => (string)($child['nutritional_status'] ?? 'Pending'),
		'height_cm' => isset($child['height_cm']) ? (float)$child['height_cm'] : null,
		'weight_kg' => isset($child['weight_kg']) ? (float)$child['weight_kg'] : null,
	];
}, $children);

$recentPayload = array_map(static function (array $row): array {
	return [
		'measurement_date' => (string)($row['measurement_date'] ?? ''),
		'height_cm' => isset($row['height_cm']) ? (float)$row['height_cm'] : null,
		'weight_kg' => isset($row['weight_kg']) ? (float)$row['weight_kg'] : null,
		'waz' => isset($row['waz']) ? (float)$row['waz'] : null,
		'haz' => isset($row['haz']) ? (float)$row['haz'] : null,
		'whz' => isset($row['whz']) ? (float)$row['whz'] : null,
		'nutritional_status' => (string)($row['nutritional_status'] ?? 'Pending'),
		'source_type' => (string)($row['source_type'] ?? 'kiosk'),
		'child_code' => (string)($row['child_code'] ?? ''),
		'child_name' => trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')),
		'barangay' => (string)($row['barangay'] ?? ''),
	];
}, $measurements);

$devicePayload = array_map(static function (array $row): array {
	return [
		'device_code' => (string)$row['device_code'],
		'location' => (string)($row['location'] ?? ''),
		'status' => (string)($row['status'] ?? 'offline'),
		'last_calibration_at' => (string)($row['last_calibration_at'] ?? 'n/a'),
		'updated_at' => (string)($row['updated_at'] ?? ''),
		'calibration_offset_height' => (float)($row['calibration_offset_height'] ?? 0),
		'calibration_offset_weight' => (float)($row['calibration_offset_weight'] ?? 0),
	];
}, $devices);

$appData = [
	'children' => $childrenPayload,
	'devices' => $devicePayload,
	'measurements' => $recentPayload,
	'demoMode' => false,
	'company' => 'Sukat Kalusugan',
	'firebase' => [
		'databaseUrl' => firebase_database_url(),
		'enabled' => firebase_database_url() !== '',
	],
	'endpoints' => [
		'ping' => '../api/esp32/device_ping.php',
		'command' => '../api/esp32/get_command.php',
		'startMeasurement' => '../api/kiosk/start_measurement.php',
		'measurementStatus' => '../api/kiosk/measurement_status.php',
		'measurement' => '../api/esp32/submit_measurement.php',
	],
	'defaults' => [
		'deviceId' => 'ESP32-KIOSK-01',
		'syncSeconds' => 15,
		'pollSeconds' => 2,
		'sessionTimeoutSeconds' => 180,
	],
];

$initialChild = $childrenPayload[0] ?? null;

function kiosk_json(array $value): string
{
	return htmlspecialchars((string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
}

?><!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Sukat Kalusugan | Kiosk</title>
	<link rel="stylesheet" href="../assets/css/app.css">
	<link rel="stylesheet" href="../assets/css/kiosk.css">
</head>
<body class="kiosk-page">
	<main class="kiosk-shell">
		<header class="kiosk-topbar">
			<div class="kiosk-brand">
				<div class="kiosk-brand-mark">SK</div>
				<div>
					<div class="kiosk-brand-title">Sukat Kalusugan</div>
					<div class="kiosk-brand-subtitle">Community Nutrition Kiosk</div>
				</div>
			</div>
			<div class="kiosk-topbar-meta">
				<span class="kiosk-chip" data-kiosk-chip-lidar><span class="kiosk-dot"></span> Waiting for LiDAR</span>
				<span class="kiosk-chip" data-kiosk-chip-loadcell><span class="kiosk-dot"></span> Waiting for scale</span>
				<span class="kiosk-chip" data-kiosk-chip-connected><span class="kiosk-dot"></span> Waiting for device</span>
				<span class="kiosk-chip" data-kiosk-clock>--:--:--</span>
			</div>
		</header>

		<section class="kiosk-page-panel" data-kiosk-screen="welcome">
			<div class="kiosk-hero-layout">
				<div class="kiosk-hero-copy">
					<p class="kiosk-eyebrow">Child Nutrition Assessment</p>
					<h1>Healthy growth starts here.</h1>
					<p class="kiosk-hero-subcopy">A simple guided measurement flow for each child in the community.</p>
					<p class="kiosk-hero-note">Select a child, scan height and weight, and review the nutritional status in a few clear steps.</p>
					<div class="kiosk-hero-status-row">
						<span><strong>LiDAR</strong> Waiting</span>
						<span><strong>Load Cell</strong> Waiting</span>
						<span><strong>Wi-Fi</strong> Ready</span>
					</div>
					<div class="kiosk-hero-actions">
						<button class="kiosk-button is-primary kiosk-touch-button" type="button" data-kiosk-action="start">Start Measurement</button>
					</div>
				</div>

				<div class="kiosk-hero-side">
					<div class="kiosk-logo-ring">SK</div>
					<div class="kiosk-hero-clock" data-kiosk-live-clock></div>
					<div class="kiosk-hero-date" data-kiosk-live-date></div>
					<div class="kiosk-status-grid">
						<div class="kiosk-status-item">
							<span>Device</span>
							<strong>ESP32-KIOSK-01</strong>
						</div>
						<div class="kiosk-status-item">
							<span>Children</span>
							<strong><?php echo count($childrenPayload); ?> profiles</strong>
						</div>
						<div class="kiosk-status-item">
							<span>Sync</span>
							<strong>Live</strong>
						</div>
					</div>
				</div>
			</div>
		</section>

		<aside class="kiosk-sidepanel" aria-hidden="true">
			<div class="kiosk-side-card">
				<h3>Activity</h3>
				<div class="kiosk-feed" data-kiosk-feed></div>
			</div>
		</aside>

		<section class="kiosk-stepbar" aria-label="Kiosk progress" hidden>
			<button type="button" class="kiosk-step is-active" data-kiosk-step-jump="select"><span>1</span>Select Child</button>
			<button type="button" class="kiosk-step" data-kiosk-step-jump="height"><span>2</span>Height</button>
			<button type="button" class="kiosk-step" data-kiosk-step-jump="weight"><span>3</span>Weight</button>
			<button type="button" class="kiosk-step" data-kiosk-step-jump="processing"><span>4</span>Process</button>
			<button type="button" class="kiosk-step" data-kiosk-step-jump="results"><span>5</span>Result</button>
		</section>

		<section class="kiosk-stage" hidden>
			<article class="kiosk-panel is-visible" data-kiosk-screen="select">
				<div class="kiosk-panel-head">
					<div>
						<p class="kiosk-section-kicker">Step 1</p>
						<h2>Select Child</h2>
						<p>Choose a child profile to begin the assessment.</p>
					</div>
				</div>

				<input class="kiosk-search kiosk-search-full" type="search" placeholder="Search by name or code..." data-kiosk-search>

				<div class="kiosk-child-grid kiosk-child-grid-wireframe" data-kiosk-child-grid>
					<?php foreach ($childrenPayload as $child): ?>
						<button
							type="button"
							class="kiosk-child-card"
							data-kiosk-child-card
							data-child-id="<?php echo (int)$child['id']; ?>"
							data-filter-text="<?php echo kiosk_e(strtolower($child['first_name'] . ' ' . $child['last_name'] . ' ' . $child['child_code'] . ' ' . $child['barangay'])); ?>"
						>
							<div class="kiosk-avatar"><?php echo kiosk_e(substr($child['first_name'], 0, 1) . substr($child['last_name'], 0, 1)); ?></div>
							<div class="kiosk-child-name"><?php echo kiosk_e($child['first_name'] . ' ' . $child['last_name']); ?></div>
							<div class="kiosk-child-meta"><?php echo (int)$child['age_months']; ?> months · <?php echo kiosk_e($child['sex']); ?></div>
							<div class="kiosk-child-code"><?php echo kiosk_e($child['child_code']); ?></div>
						</button>
					<?php endforeach; ?>
				</div>

				<div class="kiosk-panel-actions">
					<button class="kiosk-button is-primary" type="button" data-kiosk-action="proceed-height" disabled>Continue</button>
				</div>
			</article>

			<article class="kiosk-panel" data-kiosk-screen="height" hidden>
				<div class="kiosk-panel-head">
					<div>
						<p class="kiosk-section-kicker">Step 2</p>
						<h2>Height Measurement</h2>
						<p>Follow the scanner and lock the value once stable.</p>
					</div>
					<div class="kiosk-locked-chip">
						<span>Child</span>
						<strong data-kiosk-current-child-label>Choose a child</strong>
					</div>
				</div>

				<div class="kiosk-sensor-card">
					<div class="kiosk-sensor-visual is-height">
						<div class="kiosk-wave"></div>
						<div class="kiosk-sensor-readout" data-kiosk-height-readout>--.-</div>
						<div class="kiosk-sensor-unit">cm</div>
					</div>
					<div class="kiosk-sensor-status" data-kiosk-height-status>Ready to measure height</div>
					<div class="kiosk-sensor-bar"><span data-kiosk-height-bar></span></div>
				</div>

				<div class="kiosk-panel-actions">
					<button class="kiosk-button is-primary" type="button" data-kiosk-action="start-height">Start Scan</button>
					<button class="kiosk-button is-secondary" type="button" data-kiosk-action="back-select">Back</button>
				</div>
			</article>

			<article class="kiosk-panel" data-kiosk-screen="weight" hidden>
				<div class="kiosk-panel-head">
					<div>
						<p class="kiosk-section-kicker">Step 3</p>
						<h2>Weight Measurement</h2>
						<p>Let the scale stabilize before locking the reading.</p>
					</div>
					<div class="kiosk-locked-chip">
						<span>Height locked</span>
						<strong data-kiosk-height-final>--.- cm</strong>
					</div>
				</div>

				<div class="kiosk-sensor-card">
					<div class="kiosk-sensor-visual is-weight">
						<div class="kiosk-bars" data-kiosk-weight-bars></div>
						<div class="kiosk-sensor-readout" data-kiosk-weight-readout>--.--</div>
						<div class="kiosk-sensor-unit">kg</div>
					</div>
					<div class="kiosk-sensor-status" data-kiosk-weight-status>Ready to measure weight</div>
				</div>

				<div class="kiosk-panel-actions">
					<button class="kiosk-button is-primary" type="button" data-kiosk-action="start-weight">Start Scan</button>
					<button class="kiosk-button is-secondary" type="button" data-kiosk-action="back-height">Back</button>
				</div>
			</article>

			<article class="kiosk-panel" data-kiosk-screen="processing" hidden>
				<div class="kiosk-processing-ring">
					<svg viewBox="0 0 160 160" aria-hidden="true">
						<circle cx="80" cy="80" r="68" class="kiosk-ring-track"></circle>
						<circle cx="80" cy="80" r="68" class="kiosk-ring-progress" data-kiosk-progress-ring></circle>
					</svg>
					<div class="kiosk-processing-label">
						<strong data-kiosk-progress-value>0%</strong>
						<span data-kiosk-process-stage>Validating sensor data...</span>
					</div>
				</div>

				<ul class="kiosk-stage-list" data-kiosk-process-list>
					<li>Validating sensor data...</li>
					<li>Applying WHO 2006 standard...</li>
					<li>Computing WAZ, HAZ, and WHZ...</li>
					<li>Classifying nutritional status...</li>
					<li>Syncing to eOPT+ / cloud...</li>
					<li>Complete!</li>
				</ul>
			</article>

			<article class="kiosk-panel" data-kiosk-screen="results" hidden>
				<div class="kiosk-panel-head">
					<div>
						<p class="kiosk-section-kicker">Step 5</p>
						<h2>Measurement Result</h2>
						<p>Review the final assessment before starting a new record.</p>
					</div>
					<button class="kiosk-button is-secondary" type="button" data-kiosk-action="reset">New Measurement</button>
				</div>

				<div class="kiosk-results-summary">
					<div>
						<div class="kiosk-results-name" data-kiosk-result-child>Name</div>
						<div class="kiosk-results-meta" data-kiosk-result-meta>-- months old</div>
					</div>
					<div class="kiosk-status-pill" data-kiosk-result-status>Normal</div>
				</div>

				<div class="kiosk-result-grid kiosk-result-grid-wireframe">
					<div class="kiosk-result-card"><span>Height</span><strong data-kiosk-result-height>--.- cm</strong></div>
					<div class="kiosk-result-card"><span>Weight</span><strong data-kiosk-result-weight>--.-- kg</strong></div>
					<div class="kiosk-result-card"><span>WAZ</span><strong data-kiosk-result-waz>--</strong></div>
					<div class="kiosk-result-card"><span>HAZ</span><strong data-kiosk-result-haz>--</strong></div>
					<div class="kiosk-result-card"><span>WHZ</span><strong data-kiosk-result-whz>--</strong></div>
					<div class="kiosk-result-card is-wide"><span>Source</span><strong data-kiosk-result-source>demo</strong></div>
				</div>

				<div class="kiosk-panel-actions">
					<button class="kiosk-button is-primary" type="button" data-kiosk-action="reset">Measure Another Child</button>
					<button class="kiosk-button is-secondary" type="button" data-kiosk-action="export">Copy Payload</button>
				</div>
			</article>
		</section>
	</main>

	<script>
		window.KIOSK_DATA = <?php echo kiosk_json($appData); ?>;
	</script>
	<script src="../assets/js/kiosk.js"></script>
</body>
</html>


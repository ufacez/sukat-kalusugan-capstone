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
    if (!$birthdate) return 0;

    $birth = new DateTimeImmutable($birthdate);
    $today = new DateTimeImmutable('today');
    $diff = $birth->diff($today);

    return ($diff->y * 12) + $diff->m;
}

function kiosk_person_name(array $child): string
{
    return trim(
        (string)($child['first_name'] ?? '') . ' ' .
        (string)($child['last_name'] ?? '')
    );
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
    'SELECT device_code, location, status, last_calibration_at,
            calibration_offset_height, calibration_offset_weight, updated_at
     FROM devices
     ORDER BY updated_at DESC, id DESC
     LIMIT 4'
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
        'status' => (string)($child['nutritional_status'] ?? 'Pending'),
        'height_cm' => isset($child['height_cm']) ? (float)$child['height_cm'] : null,
        'weight_kg' => isset($child['weight_kg']) ? (float)$child['weight_kg'] : null,
    ];
}, $children);

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
        'syncSeconds' => 5,
        'pollSeconds' => 1,
        'sessionTimeoutSeconds' => 180,
    ],
];

function kiosk_json(array $value): string
{
    return htmlspecialchars(
        (string)json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ),
        ENT_QUOTES,
        'UTF-8'
    );
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
            <span class="kiosk-chip" data-kiosk-chip-lidar>
                <span class="kiosk-dot"></span> Waiting for LiDAR
            </span>
            <span class="kiosk-chip" data-kiosk-chip-loadcell>
                <span class="kiosk-dot"></span> Waiting for scale
            </span>
            <span class="kiosk-chip" data-kiosk-chip-connected>
                <span class="kiosk-dot"></span> Waiting for device
            </span>
            <span class="kiosk-chip" data-kiosk-clock>--:--:--</span>
        </div>
    </header>

    <!-- WELCOME -->
    <section class="kiosk-page-panel" data-kiosk-screen="welcome">
        <div class="kiosk-hero-layout">

            <div class="kiosk-hero-copy">
                <p class="kiosk-eyebrow">Child Nutrition Assessment</p>
                <h1>Healthy growth starts here.</h1>

                <p class="kiosk-hero-subcopy">
                    A simple guided measurement flow for each child in the community.
                </p>

                <p class="kiosk-hero-note">
                    Select a child, then start the automated measurement.
                </p>

                <div class="kiosk-hero-status-row">
                    <span><strong>1</strong> Select child</span>
                    <span><strong>2</strong> Weight</span>
                    <span><strong>3</strong> Height</span>
                    <span><strong>4</strong> Result</span>
                </div>

                <div class="kiosk-hero-actions">
                    <button
                        class="kiosk-button is-primary kiosk-touch-button"
                        type="button"
                        data-kiosk-action="start"
                    >
                        Start Measurement
                    </button>
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

        <div class="kiosk-side-card">
            <h3>Session</h3>
            <div class="kiosk-session-info">
                <div><strong>Session</strong> <span data-kiosk-session-id>—</span></div>
                <div><strong>Status</strong> <span data-kiosk-session-status>Idle</span></div>
                <div><strong>Started</strong> <span data-kiosk-session-started>—</span></div>
            </div>
        </div>
    </aside>

    <!-- STEPS -->
    <section class="kiosk-stepbar" aria-label="Kiosk progress" hidden>
        <button type="button" class="kiosk-step is-active" data-kiosk-step-jump="select">
            <span>1</span>Select Child
        </button>

        <button type="button" class="kiosk-step" data-kiosk-step-jump="weight">
            <span>2</span>Weight
        </button>

        <button type="button" class="kiosk-step" data-kiosk-step-jump="height">
            <span>3</span>Height
        </button>

        <button type="button" class="kiosk-step" data-kiosk-step-jump="processing">
            <span>4</span>Calculate
        </button>

        <button type="button" class="kiosk-step" data-kiosk-step-jump="results">
            <span>5</span>Result
        </button>
    </section>

    <section class="kiosk-stage" hidden>

        <!-- SELECT -->
        <article class="kiosk-panel" data-kiosk-screen="select" hidden>
            <div class="kiosk-panel-head">
                <div>
                    <p class="kiosk-section-kicker">Step 1</p>
                    <h2>Select Child</h2>
                    <p>Choose the child profile before starting the automated scan.</p>
                </div>
            </div>

            <input
                class="kiosk-search kiosk-search-full"
                type="search"
                placeholder="Search by name or code..."
                data-kiosk-search
            >

            <div class="kiosk-child-grid kiosk-child-grid-wireframe">
                <?php foreach ($childrenPayload as $child): ?>
                    <button
                        type="button"
                        class="kiosk-child-card"
                        data-kiosk-child-card
                        data-child-id="<?php echo (int)$child['id']; ?>"
                        data-filter-text="<?php
                            echo kiosk_e(
                                strtolower(
                                    $child['first_name'] . ' ' .
                                    $child['last_name'] . ' ' .
                                    $child['child_code'] . ' ' .
                                    $child['barangay']
                                )
                            );
                        ?>"
                    >
                        <div class="kiosk-avatar">
                            <?php
                            echo kiosk_e(
                                substr($child['first_name'], 0, 1) .
                                substr($child['last_name'], 0, 1)
                            );
                            ?>
                        </div>

                        <div class="kiosk-child-name">
                            <?php echo kiosk_e($child['first_name'] . ' ' . $child['last_name']); ?>
                        </div>

                        <div class="kiosk-child-meta">
                            <?php echo (int)$child['age_months']; ?> months ·
                            <?php echo kiosk_e($child['sex']); ?>
                        </div>

                        <div class="kiosk-child-code">
                            <?php echo kiosk_e($child['child_code']); ?>
                        </div>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="kiosk-panel-actions">
                <button
                    class="kiosk-button is-primary"
                    type="button"
                    data-kiosk-action="proceed-height"
                    disabled
                >
                    Continue to Weight
                </button>
            </div>
        </article>

        <!-- WEIGHT -->
        <article class="kiosk-panel" data-kiosk-screen="weight" hidden>
            <div class="kiosk-panel-head">
                <div>
                    <p class="kiosk-section-kicker">Step 2</p>
                    <h2>Measuring Weight</h2>
                    <p>Stand still on the platform. The scale will stabilize automatically.</p>
                </div>

                <div class="kiosk-locked-chip">
                    <span>Child</span>
                    <strong data-kiosk-current-child-label>Choose a child</strong>
                </div>
            </div>

            <div class="kiosk-sensor-card">
                <div class="kiosk-sensor-visual is-weight">
                    <div class="kiosk-bars" data-kiosk-weight-bars></div>
                    <div class="kiosk-sensor-readout" data-kiosk-weight-readout>--.--</div>
                    <div class="kiosk-sensor-unit">kg</div>
                </div>

                <div class="kiosk-sensor-status" data-kiosk-weight-status>
                    Waiting for the scale...
                </div>
            </div>

            <div class="kiosk-panel-actions">
                <button class="kiosk-button is-secondary" type="button" data-kiosk-action="start-weight">
                    Start Measurement
                </button>
            </div>
        </article>

        <!-- HEIGHT -->
        <article class="kiosk-panel" data-kiosk-screen="height" hidden>
            <div class="kiosk-panel-head">
                <div>
                    <p class="kiosk-section-kicker">Step 3</p>
                    <h2>Measuring Height</h2>
                    <p>Stand straight and look forward. The TF-Luna will read the distance.</p>
                </div>

                <div class="kiosk-locked-chip">
                    <span>Weight locked</span>
                    <strong data-kiosk-height-final>--.-- kg</strong>
                </div>
            </div>

            <div class="kiosk-sensor-card">
                <div class="kiosk-sensor-visual is-height">
                    <div class="kiosk-wave"></div>
                    <div class="kiosk-sensor-readout" data-kiosk-height-readout>--.-</div>
                    <div class="kiosk-sensor-unit">cm</div>
                </div>

                <div class="kiosk-sensor-status" data-kiosk-height-status>
                    Waiting for height measurement...
                </div>

                <div class="kiosk-sensor-bar">
                    <span data-kiosk-height-bar></span>
                </div>
            </div>

            <div class="kiosk-panel-actions">
                <button class="kiosk-button is-secondary" type="button" data-kiosk-action="start-height">
                    Height runs automatically
                </button>
            </div>
        </article>

        <!-- PROCESSING -->
        <article class="kiosk-panel" data-kiosk-screen="processing" hidden>
            <div class="kiosk-processing-ring">
                <svg viewBox="0 0 160 160" aria-hidden="true">
                    <circle cx="80" cy="80" r="68" class="kiosk-ring-track"></circle>
                    <circle cx="80" cy="80" r="68" class="kiosk-ring-progress" data-kiosk-progress-ring></circle>
                </svg>

                <div class="kiosk-processing-label">
                    <strong data-kiosk-progress-value>0%</strong>
                    <span data-kiosk-process-stage>Calculating...</span>
                </div>
            </div>

            <ul class="kiosk-stage-list">
                <li>Weight captured</li>
                <li>Height captured</li>
                <li>Calculating BMI / growth indicators</li>
                <li>Classifying nutritional status</li>
                <li>Saving measurement to SQL</li>
                <li>Complete</li>
            </ul>
        </article>

        <!-- RESULTS -->
        <article class="kiosk-panel" data-kiosk-screen="results" hidden>
            <div class="kiosk-panel-head">
                <div>
                    <p class="kiosk-section-kicker">Step 5</p>
                    <h2>Measurement Result</h2>
                    <p>The final measurement has been saved.</p>
                </div>

                <button
                    class="kiosk-button is-secondary"
                    type="button"
                    data-kiosk-action="reset"
                >
                    New Measurement
                </button>
            </div>

            <div class="kiosk-results-summary">
                <div>
                    <div class="kiosk-results-name" data-kiosk-result-child>Name</div>
                    <div class="kiosk-results-meta" data-kiosk-result-meta>-- months old</div>
                </div>

                <div class="kiosk-status-pill" data-kiosk-result-status>
                    Pending
                </div>
            </div>

            <div class="kiosk-result-grid kiosk-result-grid-wireframe">
                <div class="kiosk-result-card">
                    <span>Height</span>
                    <strong data-kiosk-result-height>--.- cm</strong>
                </div>

                <div class="kiosk-result-card">
                    <span>Weight</span>
                    <strong data-kiosk-result-weight>--.-- kg</strong>
                </div>

                <div class="kiosk-result-card">
                    <span>WAZ</span>
                    <strong data-kiosk-result-waz>--</strong>
                </div>

                <div class="kiosk-result-card">
                    <span>HAZ</span>
                    <strong data-kiosk-result-haz>--</strong>
                </div>

                <div class="kiosk-result-card">
                    <span>WHZ</span>
                    <strong data-kiosk-result-whz>--</strong>
                </div>

                <div class="kiosk-result-card is-wide">
                    <span>Source</span>
                    <strong data-kiosk-result-source>ESP32 → Firebase → SQL</strong>
                </div>
            </div>

            <div class="kiosk-panel-actions">
                <button class="kiosk-button is-primary" type="button" data-kiosk-action="reset">
                    Measure Another Child
                </button>
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

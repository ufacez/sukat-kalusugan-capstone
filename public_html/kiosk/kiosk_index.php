<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/firebase_sync.php';
require_once __DIR__ . '/../includes/kiosk_helpers.php';

// Same convention the ESP32 endpoints use (device_ping.php, get_command.php,
// etc.): the physical unit / kiosk browser identifies itself with a
// ?device= code, falling back to the single-kiosk default so existing
// deployments that never pass one keep working unchanged.
$deviceCode = trim((string)($_GET['device'] ?? 'ESP32-KIOSK-01'));
$kioskBarangay = kiosk_resolve_device_barangay($deviceCode);

$childrenScopeSql = '';
$childrenScopeParams = [];
$childrenScopeTypes = '';

if ($kioskBarangay !== null) {
    $childrenScopeSql = ' WHERE c.barangay_id = ?';
    $childrenScopeParams = [$kioskBarangay['id']];
    $childrenScopeTypes = 'i';
}

$children = kiosk_fetch_all(
    "SELECT c.id,c.child_code,c.first_name,c.last_name,c.birthdate,c.sex,c.barangay_id,bg.name AS barangay,c.address,
            p.name AS parent_name,p.parent_type,p.status AS parent_status,
            lm.measurement_date,lm.height_cm,lm.weight_kg,lm.waz,lm.haz,lm.whz,lm.nutritional_status
     FROM children c
     INNER JOIN parents p ON p.id=c.parent_id
     LEFT JOIN barangays bg ON bg.id = c.barangay_id
     LEFT JOIN measurements lm ON lm.id=(
       SELECT m.id FROM measurements m WHERE m.child_id=c.id
       ORDER BY m.measurement_date DESC,m.id DESC LIMIT 1
     )
     {$childrenScopeSql}
     ORDER BY c.last_name ASC,c.first_name ASC",
    $childrenScopeTypes,
    $childrenScopeParams
);

$childrenPayload = array_map(
    static function (array $c): array {
        return [
            'id' => (int) $c['id'],
            'child_code' => (string) $c['child_code'],
            'first_name' => (string) $c['first_name'],
            'last_name' => (string) $c['last_name'],
            'sex' => (string) $c['sex'],
            'age_months' => kiosk_age_months((string) ($c['birthdate'] ?? '')),
            'barangay' => (string) ($c['barangay'] ?? ''),
            'parent_name' => (string) ($c['parent_name'] ?? ''),
            'status' => (string) ($c['nutritional_status'] ?? 'Pending'),
            'height_cm' => isset($c['height_cm']) ? (float) $c['height_cm'] : null,
            'weight_kg' => isset($c['weight_kg']) ? (float) $c['weight_kg'] : null,
            'waz' => isset($c['waz']) ? (float) $c['waz'] : null,
            'haz' => isset($c['haz']) ? (float) $c['haz'] : null,
            'whz' => isset($c['whz']) ? (float) $c['whz'] : null,
        ];
    },
    $children
);

$devicesScopeSql = '';
$devicesScopeParams = [];
$devicesScopeTypes = '';

if ($kioskBarangay !== null) {
    $devicesScopeSql = ' WHERE barangay_id = ?';
    $devicesScopeParams = [$kioskBarangay['id']];
    $devicesScopeTypes = 'i';
}

$devices = kiosk_fetch_all(
    "SELECT device_code,location,status,last_calibration_at,calibration_offset_height,calibration_offset_weight,updated_at
     FROM devices{$devicesScopeSql} ORDER BY updated_at DESC,id DESC LIMIT 4",
    $devicesScopeTypes,
    $devicesScopeParams
);
$devicePayload = array_map(
    static function (array $r): array {
        return [
            'device_code' => (string) $r['device_code'],
            'location' => (string) ($r['location'] ?? ''),
            'status' => (string) ($r['status'] ?? 'offline'),
            'last_calibration_at' => (string) ($r['last_calibration_at'] ?? 'n/a'),
            'updated_at' => (string) ($r['updated_at'] ?? ''),
            'calibration_offset_height' => (float) ($r['calibration_offset_height'] ?? 0),
            'calibration_offset_weight' => (float) ($r['calibration_offset_weight'] ?? 0),
        ];
    },
    $devices
);

$firebaseUrl = trim((string) firebase_database_url());
$appData = [
    'children' => $childrenPayload,
    'devices' => $devicePayload,
    'demoMode' => false,
    'company' => 'Sukat Kalusugan',
    'firebase' => ['databaseUrl' => $firebaseUrl, 'enabled' => $firebaseUrl !== ''],
    'barangay' => $kioskBarangay,
    'endpoints' => [
        'ping' => '../api/esp32/device_ping.php',
        'command' => '../api/esp32/get_command.php',
        'startMeasurement' => '../api/kiosk/start_measurement.php',
        'requestProcess' => '../api/kiosk/request_process.php',
        'measurementStatus' => '../api/kiosk/measurement_status.php',
        'measurement' => '../api/esp32/submit_measurement.php',
    ],
    'defaults' => [
        'deviceId' => $deviceCode,
        // How often the kiosk browser re-checks device_ping.php while idle.
        // Kept close to the ESP32's own 2s heartbeat (COMMAND_POLL_INTERVAL
        // in the firmware) so an offline flip in MySQL shows up on screen
        // within a couple of seconds, not five.
        'syncSeconds' => 3,
        'pollSeconds' => 0.5,
        'sessionTimeoutSeconds' => 180,
    ],
];
?>
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width,initial-scale=1" />
        <meta name="theme-color" content="#0b6e4f" />
        <title>Sukat Kalusugan | Kiosk</title>
        <link rel="stylesheet" href="../assets/css/app.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/app.css') ?: time(); ?>" />
        <link rel="stylesheet" href="../assets/css/kiosk.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/kiosk.css') ?: time(); ?>" />
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
                    <span class="kiosk-chip" data-kiosk-chip-lidar><span class="kiosk-dot"></span> LiDAR: Waiting</span>
                    <span class="kiosk-chip" data-kiosk-chip-loadcell><span class="kiosk-dot"></span> Scale: Waiting</span>
                    <span class="kiosk-chip" data-kiosk-chip-connected
                        ><span class="kiosk-dot"></span> Device: Waiting</span
                    >
                    <span class="kiosk-chip" data-kiosk-clock>--:--:--</span>
                </div>
            </header>

            <section class="kiosk-page-panel" data-kiosk-screen="welcome">
                <div class="kiosk-hero-layout">
                    <div class="kiosk-hero-copy">
                        <p class="kiosk-eyebrow">Welcome to</p>
                        <h1>Sukat Kalusugan Kiosk</h1>
                        <p class="kiosk-hero-subcopy">Fast, guided height and weight check-ups for every child in the community.</p>
                        <p class="kiosk-hero-note">Select a child, then start the automated measurement.</p>
                        <div class="kiosk-hero-status-row">
                            <span><strong>1</strong> Select Child</span>
                            <span><strong>2</strong> Live Measurement</span>
                            <span><strong>3</strong> Processing</span>
                            <span><strong>4</strong> Result</span>
                        </div>
                        <div class="kiosk-hero-actions">
                            <button class="kiosk-button is-primary kiosk-touch-button" type="button" data-kiosk-action="start">
                                Start Measurement
                            </button>
                        </div>
                    </div>
                    <div class="kiosk-hero-side">
                        <div class="kiosk-logo-ring">SK</div>
                        <div class="kiosk-hero-clock" data-kiosk-live-clock>--:--:--</div>
                        <div class="kiosk-hero-date" data-kiosk-live-date>--</div>
                        <div class="kiosk-status-grid">
                            <div class="kiosk-status-item">
                                <span>Kiosk</span>
                                <strong><?php echo kiosk_e($deviceCode); ?></strong>
                            </div>
                            <div class="kiosk-status-item">
                                <span>Children</span>
                                <strong><?php echo count($childrenPayload); ?> profiles</strong>
                            </div>
                            <div class="kiosk-status-item">
                                <span>Status</span>
                                <strong>Connected</strong>
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
                        <div>
                            <strong>Session</strong>
                            <span data-kiosk-session-id>—</span>
                        </div>
                        <div>
                            <strong>Status</strong>
                            <span data-kiosk-session-status>Idle</span>
                        </div>
                        <div>
                            <strong>Started</strong>
                            <span data-kiosk-session-started>—</span>
                        </div>
                    </div>
                </div>
            </aside>

            <section class="kiosk-stepbar" aria-label="Kiosk progress" hidden>
                <button type="button" class="kiosk-step is-active" data-kiosk-step-jump="select">
                    <span>1</span>Select Child
                </button>
                <button type="button" class="kiosk-step" data-kiosk-step-jump="live"><span>2</span>Live Measurement</button>
                <button type="button" class="kiosk-step" data-kiosk-step-jump="processing"><span>3</span>Processing</button>
                <button type="button" class="kiosk-step" data-kiosk-step-jump="results"><span>4</span>Result</button>
            </section>

            <section class="kiosk-stage" hidden>
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
                        placeholder="Search by name, code, or barangay..."
                        autocomplete="off"
                        data-kiosk-search
                    />
                    <div class="kiosk-child-grid kiosk-child-grid-wireframe">
                        <?php foreach ($childrenPayload as $child): ?>
                            <?php
                            $fullName = trim($child['first_name'] . ' ' . $child['last_name']);
                            $initials = strtoupper(substr($child['first_name'], 0, 1) . substr($child['last_name'], 0, 1));
                            $filter = strtolower($fullName . ' ' . $child['child_code'] . ' ' . $child['barangay']);
                            ?>
                            <button
                                type="button"
                                class="kiosk-child-card"
                                data-kiosk-child-card
                                data-child-id="<?php echo (int) $child['id']; ?>"
                                data-filter-text="<?php echo kiosk_e($filter); ?>"
                            >
                                <div class="kiosk-avatar"><?php echo kiosk_e($initials); ?></div>
                                <div class="kiosk-child-name"><?php echo kiosk_e($fullName); ?></div>
                                <div class="kiosk-child-meta">
                                    <?php echo (int) $child['age_months']; ?> months · <?php echo kiosk_e($child['sex']); ?>
                                </div>
                                <div class="kiosk-child-code"><?php echo kiosk_e($child['child_code']); ?></div>
                                <?php if ($child['barangay'] !== ''): ?>
                                    <div class="kiosk-child-code"><?php echo kiosk_e($child['barangay']); ?></div>
                                <?php endif; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <div class="kiosk-panel-actions">
                        <button class="kiosk-button is-secondary" type="button" data-kiosk-action="reset">
                            Back
                        </button>
                        <button class="kiosk-button is-primary" type="button" data-kiosk-action="proceed-live" disabled>
                            Continue to Live Measurement
                        </button>
                    </div>
                </article>

                <article class="kiosk-panel" data-kiosk-screen="live" hidden>
                    <div class="kiosk-panel-head">
                        <div>
                            <p class="kiosk-section-kicker">Step 2</p>
                            <h2>Live Measurement</h2>
                            <p>
                                Stand still on the platform and look forward. Weight and height are captured together
                                automatically.
                            </p>
                        </div>
                        <div class="kiosk-panel-head-actions">
                            <button class="kiosk-button is-secondary" type="button" data-kiosk-action="back-to-select">
                                Back
                            </button>
                            <div class="kiosk-locked-chip">
                                <span>Child</span>
                                <strong data-kiosk-current-child-label>Choose a child</strong>
                            </div>
                        </div>
                    </div>

                    <div class="kiosk-live-grid">
                        <div class="kiosk-sensor-card">
                            <div class="kiosk-sensor-visual is-weight">
                                <div class="kiosk-bars" data-kiosk-weight-bars></div>
                                <div class="kiosk-sensor-readout"><span data-kiosk-weight-readout>--.--</span></div>
                                <div class="kiosk-sensor-unit">kg</div>
                            </div>
                            <div class="kiosk-sensor-status" data-kiosk-weight-status>Waiting for HX711...</div>
                        </div>
                        <div class="kiosk-sensor-card">
                            <div class="kiosk-sensor-visual is-height">
                                <div class="kiosk-wave"></div>
                                <div class="kiosk-sensor-readout"><span data-kiosk-height-readout>--.-</span></div>
                                <div class="kiosk-sensor-unit">cm</div>
                            </div>
                            <div class="kiosk-sensor-status" data-kiosk-height-status>Waiting for TF-Luna...</div>
                            <div class="kiosk-sensor-bar"><span data-kiosk-height-bar></span></div>
                        </div>
                    </div>
                    <div class="kiosk-live-footer">
                        <div class="kiosk-live-indicator">
                            <span class="kiosk-status-dot"></span><strong>LIVE</strong><span>Streaming live readings</span>
                        </div>
                        <button
                            class="kiosk-button is-primary"
                            type="button"
                            data-kiosk-action="process-measurement"
                            disabled
                        >
                            Waiting for Measurement
                        </button>
                    </div>
                </article>

                <article class="kiosk-panel" data-kiosk-screen="processing" hidden>
                    <p class="kiosk-section-kicker kiosk-center">Step 3</p>
                    <div class="kiosk-live-error" data-kiosk-processing-error hidden>
                        <div class="kiosk-live-error-body">
                            <strong>Measurement failed</strong>
                            <span data-kiosk-processing-error-message>—</span>
                        </div>
                        <button class="kiosk-button is-secondary" type="button" data-kiosk-action="reset">
                            Okay, Go Back
                        </button>
                    </div>
                    <div class="kiosk-processing-ring">
                        <svg viewBox="0 0 160 160" aria-hidden="true">
                            <circle cx="80" cy="80" r="68" class="kiosk-ring-track"></circle>
                            <circle cx="80" cy="80" r="68" class="kiosk-ring-progress" data-kiosk-progress-ring></circle>
                        </svg>
                        <div class="kiosk-processing-label">
                            <strong data-kiosk-progress-value>0%</strong>
                            <span data-kiosk-process-stage>Processing measurement...</span>
                        </div>
                    </div>
                    <p class="kiosk-processing-note">Please wait, this only takes a few seconds.</p>
                </article>

                <article class="kiosk-panel" data-kiosk-screen="results" hidden>
                    <div class="kiosk-panel-head">
                        <div>
                            <p class="kiosk-section-kicker">Step 4</p>
                            <h2>Measurement Result</h2>
                            <p>The final measurement has been saved.</p>
                        </div>
                        <button class="kiosk-button is-secondary" type="button" data-kiosk-action="reset">
                            New Measurement
                        </button>
                    </div>
                    <div class="kiosk-results-summary">
                        <div>
                            <div class="kiosk-results-name" data-kiosk-result-child>Name</div>
                            <div class="kiosk-results-meta" data-kiosk-result-meta>-- months old</div>
                        </div>
                        <div class="kiosk-status-pill" data-kiosk-result-status>Pending</div>
                    </div>
                    <div class="kiosk-flag-banner" data-kiosk-result-flag hidden>
                        <span class="kiosk-flag-icon">⚠</span>
                        <div>
                            <strong>Needs review</strong>
                            <span data-kiosk-result-flag-reason>One or more readings look unusual for this child.</span>
                        </div>
                    </div>
                    <div class="kiosk-result-primary">
                        <div class="kiosk-result-card is-primary">
                            <span>Height</span><strong data-kiosk-result-height>--.- cm</strong>
                        </div>
                        <div class="kiosk-result-card is-primary">
                            <span>Weight</span><strong data-kiosk-result-weight>--.-- kg</strong>
                        </div>
                    </div>
                    <p class="kiosk-section-kicker kiosk-indicators-kicker">Growth Indicators</p>
                    <div class="kiosk-result-grid">
                        <div class="kiosk-result-card"><span>Weight-for-Age</span><strong data-kiosk-result-waz>--</strong></div>
                        <div class="kiosk-result-card"><span>Height-for-Age</span><strong data-kiosk-result-haz>--</strong></div>
                        <div class="kiosk-result-card"><span>Weight-for-Height</span><strong data-kiosk-result-whz>--</strong></div>
                    </div>
                    <div class="kiosk-panel-actions kiosk-panel-actions-bottom">
                        <button class="kiosk-button is-primary" type="button" data-kiosk-action="reset">
                            Measure Another Child
                        </button>
                    </div>
                </article>
            </section>
        </main>
        <script>
            window.KIOSK_DATA = <?php echo json_encode($appData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        </script>
        <script src="../assets/js/kiosk.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/kiosk.js') ?: time(); ?>" defer></script>
    </body>
</html>
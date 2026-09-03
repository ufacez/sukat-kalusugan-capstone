<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/firebase_sync.php';
require_once __DIR__ . '/../includes/kiosk_helpers.php';
require_once __DIR__ . '/../includes/auth_middleware.php';

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
    "SELECT c.id,c.child_code,c.first_name,c.last_name,c.birthdate,c.sex,c.barangay_id,bg.name AS barangay,
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
        $age = kiosk_age((string) ($c['birthdate'] ?? ''));
        return [
            'id' => (int) $c['id'],
            'child_code' => (string) $c['child_code'],
            'first_name' => (string) $c['first_name'],
            'last_name' => (string) $c['last_name'],
            'sex' => (string) $c['sex'],
            'age_days' => $age['days'],
            'age_months' => $age['months'],
            'barangay' => (string) ($c['barangay'] ?? ''),
            'barangay_id' => isset($c['barangay_id']) ? (int) $c['barangay_id'] : null,
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

$esp32LocalIp = null;
$conn = get_db_connection();
$ipStmt = mysqli_prepare($conn, 'SELECT local_ip FROM devices WHERE device_code = ? LIMIT 1');
if ($ipStmt !== false) {
    mysqli_stmt_bind_param($ipStmt, 's', $deviceCode);
    mysqli_stmt_execute($ipStmt);
    $ipResult = mysqli_stmt_get_result($ipStmt);
    if ($ipResult instanceof mysqli_result) {
        $ipRow = mysqli_fetch_assoc($ipResult);
        $esp32LocalIp = !empty($ipRow['local_ip']) ? $ipRow['local_ip'] : null;
    }
    mysqli_stmt_close($ipStmt);
}

$barangays = kiosk_fetch_all(
    "SELECT id, name FROM barangays ORDER BY name ASC"
);

$appData = [
    'children' => $childrenPayload,
    'devices' => $devicePayload,
    'barangays' => array_map(fn($b) => ['id' => (int)$b['id'], 'name' => (string)$b['name']], $barangays),
    'demoMode' => false,
    'company' => 'Sukat Kalusugan',
    'firebase' => ['databaseUrl' => $firebaseUrl, 'enabled' => $firebaseUrl !== ''],
    'websocket' => ['enabled' => true, 'esp32_ip' => $esp32LocalIp],
    'barangay' => $kioskBarangay,
    'endpoints' => [
        'ping' => '../api/kiosk/device_status.php',
        'command' => '../api/esp32/get_command.php',
        'startMeasurement' => '../api/kiosk/start_measurement.php',
        'registerAndStart' => '../api/kiosk/register_and_start.php',
        'requestProcess' => '../api/kiosk/request_process.php',
        'measurementStatus' => '../api/kiosk/measurement_status.php',
        'measurement' => '../api/esp32/submit_measurement.php',
    ],
    'defaults' => [
        'deviceId' => $deviceCode,
        'syncSeconds' => 3,
        'pollSeconds' => 0.2,
        'sessionTimeoutSeconds' => 180,
    ],
];
?>
<!doctype html>
<html lang="tl">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width,initial-scale=1" />
        <meta name="theme-color" content="#0b6e4f" />
        <title>Sukat Kalusugan | Kiosk</title>
        <link rel="stylesheet" href="<?php echo kiosk_e(app_url('/assets/css/app.css?v=' . (@filemtime(__DIR__ . '/../assets/css/app.css') ?: time()))); ?>" />
        <link rel="stylesheet" href="<?php echo kiosk_e(app_url('/assets/css/kiosk.css?v=' . (@filemtime(__DIR__ . '/../assets/css/kiosk.css') ?: time()))); ?>" />
        <link rel="icon" type="image/svg+xml" href="<?php echo kiosk_e(app_url('/assets/img/logo/logo_forlight.svg')); ?>" />
    </head>
    <body class="kiosk-page">
        <main class="kiosk-shell">

            <!-- ============================================================ -->
            <!-- STEP 1: WELCOME / HOME -->
            <!-- ============================================================ -->
            <section class="kiosk-screen is-active" data-kiosk-screen="welcome">
                <div class="kiosk-welcome">
                    <div class="auth-logo-group">
                        <div class="mark-standalone-icon" aria-hidden="true">
                            <img src="<?php echo kiosk_e(app_url('/assets/img/logo/logo_forlight.svg?v=2')); ?>" alt="Sukat Kalusugan Icon" class="mark-icon-img" data-logo-light="<?php echo kiosk_e(app_url('/assets/img/logo/logo_forlight.svg?v=2')); ?>" data-logo-dark="<?php echo kiosk_e(app_url('/assets/img/logo/logo_fordark.svg?v=2')); ?>">
                        </div>

                        <div class="mark-standalone" aria-hidden="true">
                            <img src="<?php echo kiosk_e(app_url('/assets/img/logo/logotext_forlight.svg?v=2')); ?>" alt="Sukat Kalusugan" class="mark-standalone-img" data-logo-light="<?php echo kiosk_e(app_url('/assets/img/logo/logotext_forlight.svg?v=2')); ?>" data-logo-dark="<?php echo kiosk_e(app_url('/assets/img/logo/logotext_fordark.svg?v=2')); ?>">
                        </div>

                        <p class="auth-tagline">Tamang <span class="hl">Sukat</span>, Gabay sa wastong <span class="hl">Kalusugan</span>.</p>
                    </div>

                    <div class="kiosk-welcome-clock" data-kiosk-live-clock>--:--</div>
                    <div class="kiosk-welcome-date" data-kiosk-live-date>—</div>

                    <div class="kiosk-device-status" data-kiosk-device-status>
                        <span class="kiosk-device-dot offline"></span>
                        <span class="kiosk-device-label">Device offline</span>
                    </div>

                    <div class="kiosk-welcome-actions">
                        <button class="kiosk-btn kiosk-btn-primary kiosk-btn-lg" type="button" data-kiosk-action="start">
                            SIMULAN
                        </button>
                    </div>
                </div>
            </section>

            <!-- ============================================================ -->
            <!-- STEP 1B: PRIVACY NOTICE -->
            <!-- ============================================================ -->
            <section class="kiosk-screen" data-kiosk-screen="privacy" hidden>
                <div class="kiosk-form-screen">
                    <button class="kiosk-back-btn" type="button" data-kiosk-action="back-to-welcome" aria-label="Bumalik">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>

                    <div class="kiosk-privacy-hero">
                        <h2 class="kiosk-privacy-title">Privacy Notice</h2>
                        <p class="kiosk-privacy-lead">Paalala sa Data Privacy bago magsimula</p>
                    </div>

                    <div class="kiosk-privacy-grid">
                        <div class="kiosk-privacy-tile">
                            <div class="kiosk-privacy-tile-num">1</div>
                            <div class="kiosk-privacy-tile-body">
                                <h3>Data Collection</h3>
                                <p class="kiosk-privacy-tile-sub">Impormasyong kokolektahin</p>
                                <div class="kiosk-privacy-tags">
                                    <span class="kiosk-tag">Pangalan</span>
                                    <span class="kiosk-tag">Edad</span>
                                    <span class="kiosk-tag">Kasarian</span>
                                    <span class="kiosk-tag">Timbang</span>
                                    <span class="kiosk-tag">Taas</span>
                                    <span class="kiosk-tag">Barangay</span>
                                </div>
                            </div>
                        </div>

                        <div class="kiosk-privacy-tile">
                            <div class="kiosk-privacy-tile-num">2</div>
                            <div class="kiosk-privacy-tile-body">
                                <h3>Layunin</h3>
                                <p class="kiosk-privacy-tile-sub">Bakit kokolektahin</p>
                                <p>Pagsukat, nutritional assessment, monitoring, at research para sa SukatKalusugan study.</p>
                            </div>
                        </div>

                        <div class="kiosk-privacy-tile">
                            <div class="kiosk-privacy-tile-num">3</div>
                            <div class="kiosk-privacy-tile-body">
                                <h3>Proteksyon</h3>
                                <p class="kiosk-privacy-tile-sub">Paano poprotektahan</p>
                                <p>Kumpidensyal. Maa-access lamang ng awtorisadong tao na kasangkot sa pag-aaral.</p>
                            </div>
                        </div>
                    </div>

                    <button class="kiosk-privacy-full-btn" type="button" data-kiosk-action="open-full-privacy">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16" height="16">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/>
                        </svg>
                        Basahin ang buong Privacy Notice
                    </button>

                    <div class="kiosk-info-sheet-tile">
                        <div class="kiosk-info-sheet-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="20" height="20">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>
                            </svg>
                        </div>
                        <div class="kiosk-info-sheet-body">
                            <h3>Information Sheet</h3>
                            <p class="kiosk-privacy-tile-sub">Impormasyon tungkol sa pag-aaral</p>
                        </div>
                    </div>

                    <button class="kiosk-privacy-full-btn" type="button" data-kiosk-action="open-info-sheet">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16" height="16">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>
                        </svg>
                        Basahin ang Information Sheet
                    </button>

                    <div class="kiosk-privacy-checks">
                        <label class="kiosk-privacy-checkbox">
                            <input type="checkbox" id="privacyCheckbox" />
                            <span class="kiosk-privacy-checkmark"></span>
                            <span>Nabasa at sang-ayon ako sa Privacy Notice</span>
                        </label>

                        <label class="kiosk-privacy-checkbox">
                            <input type="checkbox" id="infoSheetCheckbox" />
                            <span class="kiosk-privacy-checkmark"></span>
                            <span>Nabasa at naintindihan ko ang Information Sheet</span>
                        </label>
                    </div>

                    <button class="kiosk-btn kiosk-btn-primary kiosk-btn-block" type="button" data-kiosk-action="privacy-continue" disabled id="privacyContinueBtn">
                        MAGPATULOY
                    </button>
                </div>
            </section>

            <!-- ============================================================ -->
            <!-- STEP 2: CHILD LOOKUP / HANAPIN ANG BATA -->
            <!-- ============================================================ -->
            <section class="kiosk-screen" data-kiosk-screen="child-lookup" hidden>
                <div class="kiosk-lookup">
                    <!-- Back button (positioned absolute top-left) -->
                    <button class="kiosk-back-btn" type="button" data-kiosk-action="back-to-privacy" aria-label="Bumalik">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>

                    <!-- Top bar -->
                    <div class="kiosk-lookup-header">
                        <div class="kiosk-lookup-location">
                            <span class="kiosk-device-dot online"></span>
                            <span data-kiosk-lookup-barangay><?php echo kiosk_e($kioskBarangay['name'] ?? ''); ?></span>
                        </div>
                        <div class="kiosk-lookup-status">
                            <span class="kiosk-device-dot online"></span>
                            KIOSK READY
                        </div>
                    </div>

                    <!-- Main content -->
                    <div class="kiosk-lookup-body">
                        <h1 class="kiosk-lookup-title">Sino ang susukatin?</h1>
                        <p class="kiosk-lookup-subtitle">I-type ang Child ID o pangalan ng bata para mahanap ang record niya.</p>

                        <!-- IDLE STATE -->
                        <div class="kiosk-lookup-state" data-lookup-state="idle">
                            <div class="kiosk-lookup-input-group">
                                <svg class="kiosk-lookup-input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="22" height="22"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>
                                <input class="kiosk-lookup-input" type="text" id="childIdInput" placeholder="Ilagay ang Child ID o pangalan..." autocomplete="off" autocapitalize="characters" spellcheck="false" />
                                <button class="kiosk-lookup-clear" type="button" id="clearChildId" aria-label="Linisin" hidden>
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                </button>
                            </div>

                            <!-- Live search preview -->
                            <div class="kiosk-lookup-preview" id="lookupPreview" hidden></div>

                            <button class="kiosk-btn kiosk-btn-primary kiosk-btn-block kiosk-lookup-submit" type="button" id="lookupSubmit">
                                HANAPIN
                            </button>

                            <div class="kiosk-lookup-help">
                                <span>Walang Child ID?</span>
                                <button class="kiosk-lookup-help-btn" type="button" data-kiosk-action="open-lookup-help">Pindutin ito!</button>
                            </div>
                        </div>

                        <!-- SEARCHING STATE -->
                        <div class="kiosk-lookup-state" data-lookup-state="searching" hidden>
                            <div class="kiosk-lookup-loading">
                                <div class="kiosk-lookup-spinner"></div>
                                <p class="kiosk-lookup-loading-text">Hahanapin ang record...</p>
                                <p class="kiosk-lookup-loading-sub">Sandali lamang.</p>
                            </div>
                        </div>

                        <!-- FOUND STATE -->
                        <div class="kiosk-lookup-state" data-lookup-state="found" hidden>
                            <div class="kiosk-lookup-found-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="#fff" width="28" height="28"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                            </div>
                            <p class="kiosk-lookup-found-label">RECORD FOUND</p>

                            <div class="kiosk-lookup-found-card">
                                <div class="kiosk-lookup-found-name" id="foundChildName">—</div>
                                <div class="kiosk-lookup-found-id" id="foundChildCode">—</div>
                                <div class="kiosk-lookup-found-meta">
                                    <span id="foundChildAge">—</span>
                                    <span class="kiosk-lookup-meta-dot"></span>
                                    <span id="foundChildSex">—</span>
                                </div>
                            </div>

                            <div class="kiosk-lookup-found-actions">
                                <button class="kiosk-btn kiosk-btn-outline" type="button" data-kiosk-action="lookup-retry">
                                    Hanapin Ulit
                                </button>
                                <button class="kiosk-btn kiosk-btn-primary" type="button" data-kiosk-action="lookup-confirm">
                                    Simulan na
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Help modal -->
                <div class="kiosk-lookup-help-overlay" id="lookupHelpModal" hidden>
                    <div class="kiosk-lookup-help-modal">
                        <div class="kiosk-lookup-help-header">
                            <h3>Kailangan ng tulong?</h3>
                            <button class="kiosk-modal-close" type="button" data-kiosk-action="close-lookup-help" aria-label="Isara">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="22" height="22"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                        <div class="kiosk-lookup-help-body">
                            <div class="kiosk-lookup-help-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#0b6e4f" width="28" height="28"><path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z"/></svg>
                            </div>
                            <p>Kung wala pang Child ID ang bata, mangyaring magpatulong sa health worker upang ma-register ang bata sa system.</p>
                        </div>
                        <div class="kiosk-lookup-help-footer">
                            <button class="kiosk-btn kiosk-btn-primary kiosk-btn-block" type="button" data-kiosk-action="close-lookup-help">
                                ISARA
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============================================================ -->
            <!-- STEP 3: HEIGHT + WEIGHT MEASUREMENT (LIVE, SIMULTANEOUS) -->
            <!-- ============================================================ -->
            <section class="kiosk-screen" data-kiosk-screen="measurement" hidden>
                <div class="kiosk-form-screen">
                    <button class="kiosk-back-btn" type="button" data-kiosk-action="back-to-lookup" aria-label="Bumalik">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>

                    <h2 class="kiosk-form-title">SUKAT NG TAAS AT TIMBANG</h2>
                    <p class="kiosk-form-subtitle">Tumayo nang tuwid sa platform.<br>Huwag muna gumalaw habang sinusukat.</p>

                    <div class="kiosk-measure-layout">
                        <!-- Ruler (no child figure) -->
                        <div class="kiosk-ruler-art">
                            <div class="kiosk-ruler">
                                <?php for ($i = 150; $i >= 0; $i -= 10): ?>
                                    <div class="kiosk-ruler-mark" style="bottom: <?= ($i / 150 * 100) ?>%">
                                        <span class="kiosk-ruler-num"><?= $i ?></span>
                                        <span class="kiosk-ruler-line"></span>
                                    </div>
                                <?php endfor; ?>
                                <div class="kiosk-ruler-indicator" data-kiosk-height-indicator></div>
                            </div>
                        </div>

                        <!-- Stacked live cards: HEIGHT + WEIGHT -->
                        <div class="kiosk-live-stack">
                            <div class="kiosk-live-card kiosk-live-card-sm">
                                <div class="kiosk-live-label">TAAS</div>
                                <div class="kiosk-live-value" data-kiosk-height-readout>--.-</div>
                                <div class="kiosk-live-unit">cm</div>
                                <div class="kiosk-live-status" data-kiosk-height-status>
                                    <span class="kiosk-status-icon waiting"></span>
                                    Naghihintay...
                                </div>
                            </div>

                            <div class="kiosk-live-card kiosk-live-card-sm">
                                <div class="kiosk-live-label">TIMBANG</div>
                                <div class="kiosk-live-value" data-kiosk-weight-readout>--.--</div>
                                <div class="kiosk-live-unit">kg</div>
                                <div class="kiosk-live-status" data-kiosk-weight-status>
                                    <span class="kiosk-status-icon waiting"></span>
                                    Naghihintay...
                                </div>
                                <div class="kiosk-weight-bar" aria-hidden="true">
                                    <div class="kiosk-weight-bar-track">
                                        <?php for ($kg = 0; $kg <= 50; $kg += 10): ?>
                                            <div class="kiosk-weight-bar-mark" style="left: <?= ($kg / 50 * 100) ?>%">
                                                <span class="kiosk-weight-bar-tick"></span>
                                                <span class="kiosk-weight-bar-num"><?= $kg ?></span>
                                            </div>
                                        <?php endfor; ?>
                                        <div class="kiosk-weight-bar-indicator" data-kiosk-weight-indicator></div>
                                    </div>
                                </div>
                                <div class="kiosk-stability-bars" data-kiosk-weight-bars></div>
                            </div>
                        </div>
                    </div>

                    <div class="kiosk-reminder">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="#0b6e4f" stroke-width="1.5"><circle cx="8" cy="8" r="7"/><line x1="8" y1="7" x2="8" y2="11"/><circle cx="8" cy="5" r="0.6" fill="#0b6e4f"/></svg>
                        <span>Hintaying maging stable ang dalawang sensor bago kunin ang sukat.</span>
                    </div>

                    <button class="kiosk-btn kiosk-btn-primary kiosk-btn-block" type="button" data-kiosk-action="process-measurement" disabled id="processBtn">
                        I-process ang measurement
                    </button>

                    <div class="kiosk-step-dots">
                        <span class="kiosk-dot-ind"></span>
                        <span class="kiosk-dot-ind is-active"></span>
                        <span class="kiosk-dot-ind"></span>
                    </div>
                </div>
            </section>

            <!-- ============================================================ -->
            <!-- PROCESSING OVERLAY -->
            <!-- ============================================================ -->
            <section class="kiosk-screen" data-kiosk-screen="processing" hidden>
                <div class="kiosk-processing-wrap">
                    <div class="kiosk-live-error" data-kiosk-processing-error hidden>
                        <div class="kiosk-live-error-body">
                            <strong>Nabigo ang pagsukat</strong>
                            <span data-kiosk-processing-error-message>—</span>
                        </div>
                        <button class="kiosk-btn kiosk-btn-outline" type="button" data-kiosk-action="reset">Okay, Bumalik</button>
                    </div>
                    <div class="kiosk-processing-ring">
                        <svg viewBox="0 0 200 200" aria-hidden="true">
                            <circle cx="100" cy="100" r="88" class="kiosk-ring-track"></circle>
                            <circle cx="100" cy="100" r="88" class="kiosk-ring-progress" data-kiosk-progress-ring></circle>
                        </svg>
                        <div class="kiosk-processing-label">
                            <strong data-kiosk-progress-value>0%</strong>
                            <span data-kiosk-process-stage>Pinoproseso ang pagsukat...</span>
                        </div>
                    </div>
                    <p class="kiosk-processing-note">Maghintay lamang, ilang segundo lang ito.</p>
                </div>
            </section>

            <!-- ============================================================ -->
            <!-- STEP 5: RESULT SUMMARY -->
            <!-- ============================================================ -->
            <section class="kiosk-screen" data-kiosk-screen="results" hidden>
                <div class="kiosk-form-screen">
                    <button class="kiosk-back-btn" type="button" data-kiosk-action="reset" aria-label="Bumalik">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>

                    <h2 class="kiosk-form-title">RESULTA</h2>
                    <p class="kiosk-form-subtitle">Narito ang resulta ng pagsukat.</p>

                    <div class="kiosk-result-child-card">
                        <div class="kiosk-result-child-avatar" aria-hidden="true">
                            <span data-kiosk-result-initials>JD</span>
                        </div>
                        <div class="kiosk-result-child-info">
                            <div class="kiosk-result-child-name" data-kiosk-result-child>Juan Dela Cruz</div>
                            <div class="kiosk-result-child-meta">
                                <span class="kiosk-result-meta-item">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    <span data-kiosk-result-meta>3 taon, 2 buwan (38 buwan)</span>
                                </span>
                                <span class="kiosk-result-meta-item">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    <span data-kiosk-result-sex>Lalaki</span>
                                </span>
                                <span class="kiosk-result-meta-item">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                    <span data-kiosk-result-date>May 31, 2024</span>
                                    <span data-kiosk-result-time>10:30 AM</span>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="kiosk-result-measurements">
                        <div class="kiosk-result-row">
                            <span class="kiosk-result-label">Timbang</span>
                            <span class="kiosk-result-val" data-kiosk-result-weight>28.6 kg</span>
                        </div>
                        <div class="kiosk-result-row">
                            <span class="kiosk-result-label">Taas</span>
                            <span class="kiosk-result-val" data-kiosk-result-height>120.4 cm</span>
                        </div>
                    </div>

                    <h3 class="kiosk-result-section-title">NUTRITIONAL STATUS</h3>
                    <div class="kiosk-result-status-grid">
                        <div class="kiosk-result-status-row">
                            <span>Weight-for-Age (WFA)</span>
                            <span class="kiosk-status-badge is-normal" data-kiosk-result-wfa-status>Normal</span>
                        </div>
                        <div class="kiosk-result-status-row">
                            <span>Height-for-Age (HFA)</span>
                            <span class="kiosk-status-badge is-normal" data-kiosk-result-hfa-status>Normal</span>
                        </div>
                        <div class="kiosk-result-status-row">
                            <span>Weight-for-Length/Height (WFL/H)</span>
                            <span class="kiosk-status-badge is-normal" data-kiosk-result-wflh-status>Normal</span>
                        </div>
                    </div>

                    <button class="kiosk-btn kiosk-btn-primary kiosk-btn-block" type="button" data-kiosk-action="finish">
                        Done
                    </button>
                </div>
            </section>

            <!-- ============================================================ -->
            <!-- STEP 6: THANK YOU / END -->
            <!-- ============================================================ -->
            <section class="kiosk-screen" data-kiosk-screen="thankyou" hidden>
                <div class="kiosk-thankyou">
                    <h2 class="kiosk-thankyou-title">Matagumpay!</h2>
                    <div class="kiosk-thankyou-check">
                        <svg width="80" height="80" viewBox="0 0 80 80" fill="none">
                            <circle cx="40" cy="40" r="38" fill="#0b6e4f"/>
                            <path d="M24 40l10 10 22-22" stroke="#fff" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <p class="kiosk-thankyou-msg">Nai-save na ang sukat<br>sa system.</p>
                    <p class="kiosk-thankyou-thanks">Maraming Salamat!</p>

                    <button class="kiosk-btn kiosk-btn-primary kiosk-btn-lg" type="button" data-kiosk-action="reset">
                        Balik sa Home
                    </button>
                    <p class="kiosk-thankyou-sub">Tamang Sukat, Gabay sa wastong Kalusugan</p>
                </div>
            </section>

            <!-- ============================================================ -->
            <!-- VIEW RESULTS SCREEN (previous measurements) -->
            <!-- ============================================================ -->
            <section class="kiosk-screen" data-kiosk-screen="view-results" hidden>
                <div class="kiosk-form-screen">
                    <button class="kiosk-back-btn" type="button" data-kiosk-action="back-to-welcome" aria-label="Bumalik">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>
                    <h2 class="kiosk-form-title">MGA RESULTA</h2>
                    <p class="kiosk-form-subtitle">Piliin ang bata para makita ang mga naunang resulta.</p>
                    <div class="kiosk-view-results-search">
                        <svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="#7a998c" stroke-width="2"><circle cx="8" cy="8" r="6"/><line x1="12.5" y1="12.5" x2="16" y2="16"/></svg>
                        <input class="kiosk-input" type="text" id="viewResultsSearch" placeholder="Hanapin ang pangalan..." />
                    </div>
                    <div class="kiosk-view-results-list" id="viewResultsList">
                        <div class="kiosk-view-results-empty">
                            <svg width="48" height="48" viewBox="0 0 48 48" fill="none" stroke="#d4e5dc" stroke-width="2"><circle cx="24" cy="24" r="20"/><line x1="18" y1="24" x2="30" y2="24"/><line x1="24" y1="18" x2="24" y2="30"/></svg>
                            <p>Wala pang rehistradong bata sa kiosk na ito.</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============================================================ -->
            <!-- INFORMATION SCREEN -->
            <!-- ============================================================ -->
            <section class="kiosk-screen" data-kiosk-screen="info" hidden>
                <div class="kiosk-form-screen">
                    <button class="kiosk-back-btn" type="button" data-kiosk-action="back-to-welcome" aria-label="Bumalik">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>
                    <h2 class="kiosk-form-title">TUNGKOL SA<br>SUKAT KALUSUGAN</h2>
                    <div class="kiosk-info-scroll">
                        <div class="kiosk-info-card">
                            <div class="kiosk-info-card-icon kiosk-info-icon-green">
                                <svg width="28" height="28" viewBox="0 0 28 28" fill="none" stroke="#fff" stroke-width="2"><circle cx="14" cy="10" r="7"/><path d="M14 17v6M10 23h8"/></svg>
                            </div>
                            <div>
                                <h3>Ano ang Sukat Kalusugan?</h3>
                                <p>Ang Sukat Kalusugan ay isang sistema para sa pagsubaybay sa nutrisyon at kalusugan ng mga bata. Sinusukat ang taas at timbang ng bata upang matukoy kung siya ay nabibilang sa wastong nutritional status ayon sa mga pamantayan ng WHO.</p>
                            </div>
                        </div>
                        <div class="kiosk-info-card">
                            <div class="kiosk-info-card-icon kiosk-info-icon-blue">
                                <svg width="28" height="28" viewBox="0 0 28 28" fill="none" stroke="#fff" stroke-width="2"><rect x="4" y="4" width="20" height="20" rx="3"/><path d="M8 14l4 4 8-8"/></svg>
                            </div>
                            <div>
                                <h3>Paano Gumagana?</h3>
                                <p>1. Pindutin ang <strong>"Simulan na"</strong><br>
                                2. Ilagay ang pangalan, araw ng kapanganakan, kasarian, at barangay ng bata<br>
                                3. Sundin ang mga tagubilin para sa pagsukat ng taas at timbang<br>
                                4. Tingnan ang resulta pagkatapos ng pagsukat</p>
                            </div>
                        </div>
                        <div class="kiosk-info-card">
                            <div class="kiosk-info-card-icon kiosk-info-icon-orange">
                                <svg width="28" height="28" viewBox="0 0 28 28" fill="none" stroke="#fff" stroke-width="2"><circle cx="14" cy="14" r="11"/><line x1="14" y1="8" x2="14" y2="14"/><circle cx="14" cy="19" r="1" fill="#fff"/></svg>
                            </div>
                            <div>
                                <h3>Mga Sukat</h3>
                                <p><strong>Taas (Height):</strong> Sinusukat ang taas ng bata nang nakatayo nang tuwid. Mahalaga para sa Height-for-Age (HFA).<br><br>
                                <strong>Timbang (Weight):</strong> Sinusukat ang timbang ng bata sa timbangan. Mahalaga para sa Weight-for-Age (WFA) at Weight-for-Length/Height (WFL/H).</p>
                            </div>
                        </div>
                        <div class="kiosk-info-card">
                            <div class="kiosk-info-card-icon kiosk-info-icon-purple">
                                <svg width="28" height="28" viewBox="0 0 28 28" fill="none" stroke="#fff" stroke-width="2"><rect x="3" y="6" width="22" height="16" rx="3"/><path d="M8 12h4M8 16h6"/></svg>
                            </div>
                            <div>
                                <h3>Nutritional Status</h3>
                                <p>Gumagamit ang system ng WHO z-scores para matukoy ang status:<br><br>
                                <span class="kiosk-info-badge is-normal">Normal</span> – Nasa wastong timbang at taas<br>
                                <span class="kiosk-info-badge is-underweight">Underweight</span> – Mababa ang timbang para sa edad<br>
                                <span class="kiosk-info-badge is-stunted">Stunted</span> – Mababa ang taas para sa edad<br>
                                <span class="kiosk-info-badge is-wasted">Wasted</span> – Mababa ang timbang para sa taas<br>
                                <span class="kiosk-info-badge is-overweight">Overweight</span> – Mabigat para sa taas</p>
                            </div>
                        </div>
                        <div class="kiosk-info-card">
                            <div class="kiosk-info-card-icon kiosk-info-icon-red">
                                <svg width="28" height="28" viewBox="0 0 28 28" fill="none" stroke="#fff" stroke-width="2"><path d="M14 4l10 16H4z"/><line x1="14" y1="11" x2="14" y2="16"/><circle cx="14" cy="19" r="1" fill="#fff"/></svg>
                            </div>
                            <div>
                                <h3>Mga Paalala</h3>
                                <p>• Siguraduhing tama ang impormasyong inilagay<br>
                                • Ang mga resulta ay para sa monitoring lamang at hindi kapalit ng konsultasyon sa doktor<br>
                                • Kung may alinlangan, kumonsulta sa inyong nutritionist o doktor<br>
                                • Para sa mga emergency, tumawag sa inyong local health center</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============================================================ -->
            <!-- FULL PRIVACY NOTICE MODAL -->
            <!-- ============================================================ -->
            <div class="kiosk-modal-overlay" id="privacyModal" hidden>
                <div class="kiosk-modal">
                    <div class="kiosk-modal-header">
                        <h2>PRIVACY NOTICE</h2>
                        <button class="kiosk-modal-close" type="button" data-kiosk-action="close-privacy-modal" aria-label="Isara">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <div class="kiosk-modal-body">
                        <p class="kiosk-modal-lead">Your privacy matters to us.</p>
                        <p>SukatKalusugan is a study-based system designed to support the collection and assessment of children's anthropometric measurements. Before participating, we want you to understand how information collected through the system will be handled.</p>

                        <h4>1. INFORMATION WE COLLECT</h4>
                        <p>For the purposes of the study, SukatKalusugan may collect information necessary for the assessment of the child, including:</p>
                        <ul>
                            <li>Child ID or reference number</li>
                            <li>Name, when required</li>
                            <li>Date of birth or age</li>
                            <li>Sex</li>
                            <li>Barangay or other information required by the study</li>
                            <li>Weight</li>
                            <li>Height or length</li>
                            <li>Date and time of measurement</li>
                            <li>Nutritional assessment results</li>
                            <li>WHO-based growth assessment and classification</li>
                        </ul>
                        <p>Only information necessary for the stated study purposes will be collected.</p>

                        <h4>2. WHY DO WE COLLECT THIS INFORMATION?</h4>
                        <p>The information is collected for the purposes of the SukatKalusugan study, including:</p>
                        <ul>
                            <li>conducting anthropometric measurements;</li>
                            <li>assessing the nutritional status of children;</li>
                            <li>generating nutritional assessment results;</li>
                            <li>supporting monitoring and reporting related to the study; and</li>
                            <li>supporting the research, evaluation, and development of the SukatKalusugan system.</li>
                        </ul>
                        <p>The information will only be used for the specific purposes covered by the approved study and data request.</p>

                        <h4>3. HOW WILL THE INFORMATION BE USED?</h4>
                        <p>The collected information may be processed to generate measurements and nutritional indicators such as:</p>
                        <ul>
                            <li>Weight-for-Age (WFA)</li>
                            <li>Height-for-Age (HFA)</li>
                            <li>Weight-for-Height/Length (WFH/WFL)</li>
                        </ul>
                        <p>The system may use the child's age, sex, weight, and height/length to generate the corresponding assessment results based on the applicable WHO Child Growth Standards.</p>

                        <h4>4. WHO MAY ACCESS THE INFORMATION?</h4>
                        <p>Access to the collected information will be limited to authorized individuals involved in the study and the operation of the SukatKalusugan system, according to their assigned responsibilities.</p>
                        <p>Personal information of the child will not be made publicly available or accessed by unauthorized persons.</p>

                        <h4>5. HOW WILL THE INFORMATION BE PROTECTED?</h4>
                        <ul>
                            <li>Reasonable technical, physical, and organizational safeguards will be applied to protect the information against unauthorized access, disclosure, alteration, loss, or misuse.</li>
                            <li>Authorized system users will only be given access to information necessary for their assigned responsibilities.</li>
                            <li>All persons who are authorized to access the information are expected to maintain its confidentiality.</li>
                        </ul>

                        <h4>6. CONFIDENTIALITY</h4>
                        <p>Information collected through the study will be treated as confidential.</p>
                        <p>The information obtained through the approved data request will be used only for the specific purposes stated in the study and related documentation.</p>
                        <p>Unauthorized access, processing, use, or disclosure of the information is not permitted.</p>

                        <h4>7. DATA SHARING AND DISCLOSURE</h4>
                        <p>Personal information will not be disclosed or shared with unauthorized individuals or organizations.</p>
                        <p>Any authorized use, disclosure, or processing of the information must be consistent with the purposes of the study and applicable data privacy requirements.</p>

                        <h4>8. DATA RETENTION</h4>
                        <p>Study information will be retained only for the period necessary to fulfill the approved purposes of the study and applicable requirements.</p>
                        <p>When the information is no longer necessary, it will be securely disposed of, deleted, or anonymized when appropriate and in accordance with the applicable study policies.</p>

                        <h4>9. YOUR PRIVACY RIGHTS</h4>
                        <p>Under the Data Privacy Act of 2012 (Republic Act No. 10173), data subjects have rights concerning their personal information, subject to applicable laws and requirements.</p>
                        <p>These may include the right to:</p>
                        <ul>
                            <li>be informed about the processing of personal information;</li>
                            <li>access personal information;</li>
                            <li>request correction of inaccurate information;</li>
                            <li>object to certain processing when applicable; and</li>
                            <li>request deletion or blocking when applicable.</li>
                        </ul>
                        <p>For information concerning a child, these rights may be exercised by the child's parent or legal guardian, when appropriate.</p>

                        <h4>10. DATA PRIVACY COMMITMENT</h4>
                        <p>SukatKalusugan recognizes the importance of protecting children's personal information.</p>
                        <p>The collection and processing of information for this study will be conducted in accordance with the Republic Act No. 10173, or the Data Privacy Act of 2012, and the applicable data privacy policies and security procedures governing the approved study and data request.</p>

                        <h4>ACKNOWLEDGMENT</h4>
                        <p>Before proceeding with the measurement, please make sure that you have read and understood this Privacy Notice.</p>
                        <p><em>I have read and understood this Privacy Notice and understand how my child's information may be collected and used for the purposes of the SukatKalusugan study.</em></p>
                    </div>
                    <div class="kiosk-modal-footer">
                        <button class="kiosk-btn kiosk-btn-primary kiosk-btn-block" type="button" data-kiosk-action="close-privacy-modal">
                            NAIINTINDIHAN KO
                        </button>
                    </div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- INFORMATION SHEET MODAL -->
            <!-- ============================================================ -->
            <div class="kiosk-modal-overlay" id="infoSheetModal" hidden>
                <div class="kiosk-modal">
                    <div class="kiosk-modal-header">
                        <h2>INFORMATION SHEET</h2>
                        <button class="kiosk-modal-close" type="button" data-kiosk-action="close-info-sheet" aria-label="Isara">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="24" height="24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <div class="kiosk-modal-body">
                        <h4>Introduction</h4>
                        <p>We are students from the College of Computer Studies at Our Lady of Fatima University conducting research for our capstone project, "SukatKalusugan: A Smart Kiosk-Based Anthropometric Monitoring System with a Web Application for the City Health Office in the City of San Fernando, Pampanga." This study is being carried out at the City Health Office of the City of San Fernando, Pampanga, with the aim of improving the speed and accuracy of data collection through the use of innovative technology.</p>

                        <h4>Purpose of the Research</h4>
                        <p>The purpose of this study is to design and develop a smart kiosk-based system for monitoring anthropometric measures that integrates IoT sensors, AI analysis for decision supporting, and a web application for the collection, monitoring, and management of anthropometric data. The system aims to provide accurate real-time measurements and identify trends and patterns in anthropometric classifications among children aged 24-59 months in accordance to WHO standards, supporting health monitoring and promotes awareness of their nutritional and physical growth status.</p>

                        <h4>Type of Research Intervention</h4>
                        <p>Participation will involve parents, nutritionists, and IT experts testing the smart kiosk-based anthropometric monitoring system with the help of City Nutrition Program Coordinators from City Health Office. Participants will use the kiosk and web application to collect and view anthropometric data, while the system tracks measurements over time to identify trends and patterns that may support follow-up monitoring. Participants will also evaluate the system through an ISO 25010-based survey and interview.</p>

                        <h4>Participant Selection</h4>
                        <p>You are invited to participate because your experience as a parent/guardian, nutritionist, or IT expert is valuable in helping us understand child nutrition monitoring and in improving the system.</p>

                        <h4>Voluntary Participation</h4>
                        <p>Your participation in this study is entirely voluntary. You may choose not to participate or to withdraw from the study at any time without providing a reason. If you decide to withdraw, you will no longer be considered a participant and will no longer be involved in any part of the study or its activities. Choosing not to participate or withdrawing from the study will not result in any penalty, disadvantage, or loss of any health services or benefits that you are entitled to receive from the City Health Office. Your decision will not affect your relationship with the researchers, the City Health Office, Our Lady of Fatima University, or any other institution involved in the study.</p>

                        <h4>Procedures</h4>
                        <p>The researchers invite you to help them understand the child nutrition monitoring methods at the City Health Office of the City of San Fernando, Pampanga and how the SukatKalusugan system can improve the process. If you agree to participate, you will be asked to sign consent forms for documentation.</p>
                        <p>You will be asked to:</p>
                        <ul>
                            <li>Interact with the SukatKalusugan system (kiosk and web application) to collect and view children's anthropometric measurements, which will take approximately 1 hour.</li>
                            <li>Complete a survey based on ISO 25010 standards to evaluate the system's functionality, reliability, usability, and other quality attributes, which will take approximately 15-20 minutes.</li>
                            <li>Participate in a brief interview (10-15 minutes) to provide feedback on your experience.</li>
                        </ul>
                        <p>If you prefer not to answer any question in the survey or in the interview, you may skip them and proceed to the next activity. All information collected will be kept confidential. Your name will not appear on the survey or interview records; instead, a unique number will identify your responses. Only the researchers, the capstone adviser, and panelists will have access to the data.</p>

                        <h4>Duration</h4>
                        <p>The activities will take approximately 1 hour in total to complete. The research will be conducted from June 2026 to December 2026. During this period, we may contact you occasionally through email or phone for follow-up or additional input.</p>

                        <h4>Risks</h4>
                        <p>There are no anticipated physical risks associated with this study. The anthropometric measurements collected by the system are non-invasive and will not cause physical harm to participants. However, there may be minor discomfort or inconvenience while using the kiosk or providing information required for the study. Participants may choose not to answer any question or may withdraw from the study at any time without penalty. To protect privacy and confidentiality, appropriate security measures will be implemented to safeguard the data collected through the kiosk and web application.</p>

                        <h4>Benefits</h4>
                        <p>As a parent/guardian, nutritionist, or IT expert, your participation will help improve the development of a smart kiosk-based anthropometric monitoring system for children aged 24–59 months. Your involvement will contribute to evaluating the system's ability to collect anthropometric measurements, monitor records, and identify trends and patterns over time. You will gain experience with an innovative IoT and your feedback will help improve the usability, functionality, and reliability of the kiosk and web application.</p>

                        <h4>Reimbursements</h4>
                        <p>There will be no financial or material incentives provided for participating in this research.</p>

                        <h4>Confidentiality</h4>
                        <p>All information collected during this study will be kept strictly confidential and will only be accessible to the research team, the capstone adviser, and panel members for academic evaluation purposes. Personal information and anthropometric records will be stored securely, and participant identities will be protected through the use of unique identifiers or codes instead of names whenever possible. The collected data will be used solely for research purposes and will be handled in accordance with the Data Privacy Act of 2012 (Republic Act No. 10173) of the Philippines. Upon completion of the study, all records will be securely retained or disposed of following institutional and data privacy guidelines.</p>

                        <h4>Sharing the Results</h4>
                        <p>Any information you provide during this study will remain confidential and will not be shared outside the research team. No data will be linked to your name. The insights gained from this research will be shared with you and the City Health Office of the City of San Fernando Pampanga before any public dissemination. The researchers plan to publish the results so others may benefit from the study as well.</p>

                        <h4>Right to Refuse or Withdraw</h4>
                        <p>Participation in this research is entirely voluntary. Choosing not to participate will not affect your employment, duties, responsibilities, or relationship with the City Health Office in any way. You may also withdraw from the study at any time without any penalty or negative consequences. At the end of the data collection process, you will have the opportunity to review your responses and may request modifications or the removal of any information that you are not comfortable sharing.</p>
                    </div>
                    <div class="kiosk-modal-footer">
                        <button class="kiosk-btn kiosk-btn-primary kiosk-btn-block" type="button" data-kiosk-action="close-info-sheet">
                            NAIINTINDIHAN KO
                        </button>
                    </div>
                </div>
            </div>

        </main>

            <!-- Hidden sidebar for activity feed (admin) -->
            <aside class="kiosk-sidepanel" aria-hidden="true">
            <div class="kiosk-side-card">
                <h3>Aktibidad</h3>
                <div class="kiosk-feed" data-kiosk-feed></div>
            </div>
        </aside>

        <script>
            window.KIOSK_DATA = <?php echo json_encode($appData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        </script>
        <script src="../assets/js/kiosk.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/kiosk.js') ?: time(); ?>" defer></script>
    </body>
</html>

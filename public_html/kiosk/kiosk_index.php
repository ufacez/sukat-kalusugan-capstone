<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/firebase_sync.php';
require_once __DIR__ . '/../includes/kiosk_helpers.php';
require_once __DIR__ . '/../includes/auth_middleware.php';
require_once __DIR__ . '/../includes/kiosk_i18n.php';

$kioskLang = kiosk_lang();
kiosk_persist_lang();

$deviceCode = trim((string)($_GET['device'] ?? ''));
$kioskBarangay = ($deviceCode !== '') ? kiosk_resolve_device_barangay($deviceCode) : null;
// Unregistered/unknown devices see no child data. Previously this fell back
// to an unscoped full dump so fresh kiosks showed every child — a PII leak
// on the public internet. Registered devices stay barangay-scoped.
$kioskRegistered = ($deviceCode !== '' && $kioskBarangay !== null);

$childrenScopeSql = '';
$childrenScopeParams = [];
$childrenScopeTypes = '';

if ($kioskRegistered) {
    $childrenScopeSql = ' WHERE c.barangay_id = ? AND c.status = ? AND TIMESTAMPDIFF(MONTH, c.birthdate, CURDATE()) <= 59';
    $childrenScopeParams = [$kioskBarangay['id'], 'active'];
    $childrenScopeTypes = 'is';
} else {
    // Impossible predicate: returns zero rows without a full-table scan.
    $childrenScopeSql = ' WHERE 1 = 0';
    $childrenScopeParams = [];
    $childrenScopeTypes = '';
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

if ($kioskRegistered) {
    $devicesScopeSql = ' WHERE barangay_id = ?';
    $devicesScopeParams = [$kioskBarangay['id']];
    $devicesScopeTypes = 'i';
} else {
    $devicesScopeSql = ' WHERE 1 = 0';
    $devicesScopeParams = [];
    $devicesScopeTypes = '';
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
    'lang' => $kioskLang,
    'i18n' => kiosk_translations($kioskLang),
    'demoMode' => false,
    'company' => 'Sukat Kalusugan',
    'firebase' => ['databaseUrl' => $firebaseUrl, 'enabled' => $firebaseUrl !== ''],
    'websocket' => ['enabled' => true, 'esp32_ip' => $esp32LocalIp],
    'barangay' => $kioskBarangay,
    'registered' => $kioskRegistered,
    'endpoints' => [
        'ping' => '../api/kiosk/device_status.php',
        'command' => '../api/esp32/get_command.php',
        'startMeasurement' => '../api/kiosk/start_measurement.php',
        'registerAndStart' => '../api/kiosk/register_and_start.php',
        'requestProcess' => '../api/kiosk/request_process.php',
        'measurementStatus' => '../api/kiosk/measurement_status.php',
        'measurement' => '../api/esp32/submit_measurement.php',
        'checkDue' => '../api/kiosk/check_due.php',
        'cancelSession' => '../api/kiosk/cancel_session.php',
        'childrenList' => '../api/kiosk/children_list.php',
    ],
    'defaults' => [
        'deviceId' => $deviceCode,
        'syncSeconds' => 3,
        'pollSeconds' => 0.2,
        'sessionTimeoutSeconds' => 180,
    ],
];

// Toggle link target: keeps the ?device= scope, swaps only the language.
$kioskLangUrl = static function (string $lang) use ($deviceCode): string {
    $params = ['lang' => $lang];
    if ($deviceCode !== '') {
        $params['device'] = $deviceCode;
    }
    return '?' . http_build_query($params, '', '&amp;');
};
?>
<!doctype html>
<html lang="<?php echo $kioskLang === 'tl' ? 'tl' : 'en'; ?>">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width,initial-scale=1" />
        <meta name="theme-color" content="#0b6e4f" />
        <title><?php echo kiosk_e(kiosk_t('brand.title')); ?></title>
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
                <div class="kiosk-lang-toggle" role="group" aria-label="<?php echo kiosk_e(kiosk_t('lang.switch_label')); ?>">
                    <a class="kiosk-lang-btn<?php echo $kioskLang === 'tl' ? ' is-active' : ''; ?>" href="<?php echo $kioskLangUrl('tl'); ?>" rel="nofollow"><?php echo kiosk_e(kiosk_t('lang.tagalog')); ?></a>
                    <a class="kiosk-lang-btn<?php echo $kioskLang === 'en' ? ' is-active' : ''; ?>" href="<?php echo $kioskLangUrl('en'); ?>" rel="nofollow"><?php echo kiosk_e(kiosk_t('lang.english')); ?></a>
                </div>

                <div class="kiosk-welcome">
                    <div class="auth-logo-group">
                        <div class="mark-standalone-icon" aria-hidden="true">
                            <img src="<?php echo kiosk_e(app_url('/assets/img/logo/logo_forlight.svg?v=2')); ?>" alt="<?php echo kiosk_e(kiosk_t('brand.alt_icon')); ?>" class="mark-icon-img" data-logo-light="<?php echo kiosk_e(app_url('/assets/img/logo/logo_forlight.svg?v=2')); ?>" data-logo-dark="<?php echo kiosk_e(app_url('/assets/img/logo/logo_fordark.svg?v=2')); ?>">
                        </div>

                        <div class="mark-standalone" aria-hidden="true">
                            <img src="<?php echo kiosk_e(app_url('/assets/img/logo/logotext_forlight.svg?v=2')); ?>" alt="<?php echo kiosk_e(kiosk_t('brand.alt_logo')); ?>" class="mark-standalone-img" data-logo-light="<?php echo kiosk_e(app_url('/assets/img/logo/logotext_forlight.svg?v=2')); ?>" data-logo-dark="<?php echo kiosk_e(app_url('/assets/img/logo/logotext_fordark.svg?v=2')); ?>">
                        </div>

                        <p class="auth-tagline"><?php echo kiosk_t('welcome.tagline'); ?></p>
                    </div>

                    <div class="kiosk-welcome-clock" data-kiosk-live-clock>--:--</div>
                    <div class="kiosk-welcome-date" data-kiosk-live-date>—</div>

                    <div class="kiosk-device-status" data-kiosk-device-status>
                        <span class="kiosk-device-dot offline"></span>
                        <span class="kiosk-device-label"><?php echo kiosk_e(kiosk_t('welcome.device_offline')); ?></span>
                    </div>

                    <div class="kiosk-welcome-actions">
                        <button class="kiosk-btn kiosk-btn-primary kiosk-btn-lg" type="button" data-kiosk-action="start">
                            <?php echo kiosk_e(kiosk_t('welcome.start')); ?>
                        </button>
                    </div>
                </div>
            </section>

            <!-- ============================================================ -->
            <!-- STEP 1B: PRIVACY NOTICE -->
            <!-- ============================================================ -->
            <section class="kiosk-screen" data-kiosk-screen="privacy" hidden>
                <div class="kiosk-form-screen">
                    <button class="kiosk-back-btn" type="button" data-kiosk-action="back-to-welcome" aria-label="<?php echo kiosk_e(kiosk_t('common.back')); ?>">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>

                    <div class="kiosk-privacy-hero">
                        <h2 class="kiosk-privacy-title"><?php echo kiosk_e(kiosk_t('privacy.title')); ?></h2>
                        <p class="kiosk-privacy-lead"><?php echo kiosk_e(kiosk_t('privacy.lead')); ?></p>
                    </div>

                    <div class="kiosk-privacy-grid">
                        <div class="kiosk-privacy-tile">
                            <div class="kiosk-privacy-tile-num">1</div>
                            <div class="kiosk-privacy-tile-body">
                                <h3><?php echo kiosk_e(kiosk_t('privacy.collect_title')); ?></h3>
                                <p class="kiosk-privacy-tile-sub"><?php echo kiosk_e(kiosk_t('privacy.collect_sub')); ?></p>
                                <div class="kiosk-privacy-tags">
                                    <span class="kiosk-tag"><?php echo kiosk_e(kiosk_t('privacy.tag.name')); ?></span>
                                    <span class="kiosk-tag"><?php echo kiosk_e(kiosk_t('privacy.tag.age')); ?></span>
                                    <span class="kiosk-tag"><?php echo kiosk_e(kiosk_t('privacy.tag.sex')); ?></span>
                                    <span class="kiosk-tag"><?php echo kiosk_e(kiosk_t('privacy.tag.weight')); ?></span>
                                    <span class="kiosk-tag"><?php echo kiosk_e(kiosk_t('privacy.tag.height')); ?></span>
                                    <span class="kiosk-tag"><?php echo kiosk_e(kiosk_t('privacy.tag.barangay')); ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="kiosk-privacy-tile">
                            <div class="kiosk-privacy-tile-num">2</div>
                            <div class="kiosk-privacy-tile-body">
                                <h3><?php echo kiosk_e(kiosk_t('privacy.purpose_title')); ?></h3>
                                <p class="kiosk-privacy-tile-sub"><?php echo kiosk_e(kiosk_t('privacy.purpose_sub')); ?></p>
                                <p><?php echo kiosk_e(kiosk_t('privacy.purpose_body')); ?></p>
                            </div>
                        </div>

                        <div class="kiosk-privacy-tile">
                            <div class="kiosk-privacy-tile-num">3</div>
                            <div class="kiosk-privacy-tile-body">
                                <h3><?php echo kiosk_e(kiosk_t('privacy.protection_title')); ?></h3>
                                <p class="kiosk-privacy-tile-sub"><?php echo kiosk_e(kiosk_t('privacy.protection_sub')); ?></p>
                                <p><?php echo kiosk_e(kiosk_t('privacy.protection_body')); ?></p>
                            </div>
                        </div>
                    </div>

                    <button class="kiosk-privacy-full-btn" type="button" data-kiosk-action="open-full-privacy">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16" height="16">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/>
                        </svg>
                        <?php echo kiosk_e(kiosk_t('privacy.read_full')); ?>
                    </button>

                    <div class="kiosk-info-sheet-tile">
                        <div class="kiosk-info-sheet-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="20" height="20">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>
                            </svg>
                        </div>
                        <div class="kiosk-info-sheet-body">
                            <h3><?php echo kiosk_e(kiosk_t('privacy.info_sheet_title')); ?></h3>
                            <p class="kiosk-privacy-tile-sub"><?php echo kiosk_e(kiosk_t('privacy.info_sheet_sub')); ?></p>
                        </div>
                    </div>

                    <button class="kiosk-privacy-full-btn" type="button" data-kiosk-action="open-info-sheet">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16" height="16">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>
                        </svg>
                        <?php echo kiosk_e(kiosk_t('privacy.read_info_sheet')); ?>
                    </button>

                    <div class="kiosk-privacy-checks">
                        <label class="kiosk-privacy-checkbox">
                            <input type="checkbox" id="privacyCheckbox" />
                            <span class="kiosk-privacy-checkmark"></span>
                            <span><?php echo kiosk_e(kiosk_t('privacy.check_notice')); ?></span>
                        </label>

                        <label class="kiosk-privacy-checkbox">
                            <input type="checkbox" id="infoSheetCheckbox" />
                            <span class="kiosk-privacy-checkmark"></span>
                            <span><?php echo kiosk_e(kiosk_t('privacy.check_sheet')); ?></span>
                        </label>
                    </div>

                    <button class="kiosk-btn kiosk-btn-primary kiosk-btn-block" type="button" data-kiosk-action="privacy-continue" disabled id="privacyContinueBtn">
                        <?php echo kiosk_e(kiosk_t('privacy.continue')); ?>
                    </button>
                </div>
            </section>

            <!-- ============================================================ -->
            <!-- STEP 2: CHILD LOOKUP / HANAPIN ANG BATA -->
            <!-- ============================================================ -->
            <section class="kiosk-screen" data-kiosk-screen="child-lookup" hidden>
                <div class="kiosk-lookup">
                    <!-- Back button (positioned absolute top-left) -->
                    <button class="kiosk-back-btn" type="button" data-kiosk-action="back-to-privacy" aria-label="<?php echo kiosk_e(kiosk_t('common.back')); ?>">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>

                    <!-- Top bar -->
                    <div class="kiosk-lookup-header">
                        <div class="kiosk-lookup-location">
                            <span class="kiosk-device-dot online"></span>
                            <span data-kiosk-lookup-barangay><?php echo kiosk_e($kioskBarangay['name'] ?? ''); ?></span>
                            <?php if (!$kioskRegistered): ?>
                                <span style="display:block;font-size:.8rem;color:#b91c1c;"><?php echo kiosk_e(kiosk_t('lookup.unregistered')); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="kiosk-lookup-status">
                            <span class="kiosk-device-dot online"></span>
                            <?php echo kiosk_e(kiosk_t('lookup.ready')); ?>
                        </div>
                    </div>

                    <!-- Main content -->
                    <div class="kiosk-lookup-body">
                        <h1 class="kiosk-lookup-title"><?php echo kiosk_e(kiosk_t('lookup.title')); ?></h1>
                        <p class="kiosk-lookup-subtitle"><?php echo kiosk_e(kiosk_t('lookup.subtitle')); ?></p>

                        <!-- Error banner (shown when start fails) -->
                        <div class="kiosk-lookup-error" id="lookupError" hidden>
                            <div class="kiosk-lookup-error-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="#dc2626" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
                            </div>
                            <span class="kiosk-lookup-error-text" id="lookupErrorText"></span>
                            <button class="kiosk-lookup-error-dismiss" type="button" id="lookupErrorDismiss" aria-label="<?php echo kiosk_e(kiosk_t('common.close')); ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                            </button>
                        </div>

                        <!-- IDLE STATE -->
                        <div class="kiosk-lookup-state" data-lookup-state="idle">
                            <div class="kiosk-lookup-input-group">
                                <svg class="kiosk-lookup-input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="22" height="22"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>
                                <input class="kiosk-lookup-input" type="text" id="childIdInput" placeholder="<?php echo kiosk_e(kiosk_t('lookup.placeholder')); ?>" autocomplete="off" autocapitalize="characters" spellcheck="false" />
                                <button class="kiosk-lookup-clear" type="button" id="clearChildId" aria-label="<?php echo kiosk_e(kiosk_t('common.clear')); ?>" hidden>
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                </button>
                            </div>

                            <!-- Live search preview -->
                            <div class="kiosk-lookup-preview" id="lookupPreview" hidden></div>

                            <button class="kiosk-btn kiosk-btn-primary kiosk-btn-block kiosk-lookup-submit" type="button" id="lookupSubmit">
                                <?php echo kiosk_e(kiosk_t('lookup.search')); ?>
                            </button>

                            <div class="kiosk-lookup-help">
                                <span><?php echo kiosk_e(kiosk_t('lookup.no_id')); ?></span>
                                <button class="kiosk-lookup-help-btn" type="button" data-kiosk-action="open-lookup-help"><?php echo kiosk_e(kiosk_t('lookup.tap_here')); ?></button>
                            </div>
                        </div>

                        <!-- SEARCHING STATE -->
                        <div class="kiosk-lookup-state" data-lookup-state="searching" hidden>
                            <div class="kiosk-lookup-loading">
                                <div class="kiosk-lookup-spinner"></div>
                                <p class="kiosk-lookup-loading-text"><?php echo kiosk_e(kiosk_t('lookup.searching')); ?></p>
                                <p class="kiosk-lookup-loading-sub"><?php echo kiosk_e(kiosk_t('lookup.searching_sub')); ?></p>
                            </div>
                        </div>

                        <!-- FOUND STATE -->
                        <div class="kiosk-lookup-state" data-lookup-state="found" hidden>
                            <div class="kiosk-lookup-found-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="#fff" width="28" height="28"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                            </div>
                            <p class="kiosk-lookup-found-label"><?php echo kiosk_e(kiosk_t('lookup.found')); ?></p>

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
                                    <?php echo kiosk_e(kiosk_t('lookup.retry')); ?>
                                </button>
                                <button class="kiosk-btn kiosk-btn-primary" type="button" data-kiosk-action="lookup-confirm">
                                    <?php echo kiosk_e(kiosk_t('lookup.confirm')); ?>
                                </button>
                            </div>
                        </div>

                        <!-- NOT-DUE STATE -->
                        <div class="kiosk-lookup-state" data-lookup-state="not-due" hidden>
                            <div class="kiosk-lookup-found-icon" style="background: #fee2e2;">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="#dc2626" width="28" height="28"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                            </div>
                            <p class="kiosk-lookup-found-label" style="color: #dc2626;"><?php echo kiosk_e(kiosk_t('lookup.not_due')); ?></p>

                            <div class="kiosk-lookup-found-card">
                                <div class="kiosk-lookup-found-name" id="notDueChildName">&mdash;</div>
                                <div class="kiosk-lookup-found-id" id="notDueChildCode">&mdash;</div>
                                <div class="kiosk-lookup-found-meta">
                                    <span id="notDueChildAge">&mdash;</span>
                                    <span class="kiosk-lookup-meta-dot"></span>
                                    <span id="notDueChildSex">&mdash;</span>
                                </div>
                            </div>

                            <div class="kiosk-lookup-not-due-reason" id="notDueReason"></div>

                            <div class="kiosk-lookup-found-actions">
                                <button class="kiosk-btn kiosk-btn-outline kiosk-btn-block" type="button" data-kiosk-action="not-due-recheck" id="notDueRecheckBtn" hidden>
                                    <?php echo kiosk_e(kiosk_t('lookup.recheck')); ?>
                                </button>
                                <button class="kiosk-btn kiosk-btn-primary kiosk-btn-block" type="button" data-kiosk-action="lookup-retry">
                                    <?php echo kiosk_e(kiosk_t('lookup.retry')); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Help modal -->
                <div class="kiosk-lookup-help-overlay" id="lookupHelpModal" hidden>
                    <div class="kiosk-lookup-help-modal">
                        <div class="kiosk-lookup-help-header">
                            <h3><?php echo kiosk_e(kiosk_t('lookup.help_title')); ?></h3>
                            <button class="kiosk-modal-close" type="button" data-kiosk-action="close-lookup-help" aria-label="<?php echo kiosk_e(kiosk_t('common.close')); ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="22" height="22"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                        <div class="kiosk-lookup-help-body">
                            <div class="kiosk-lookup-help-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#0b6e4f" width="28" height="28"><path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z"/></svg>
                            </div>
                            <p><?php echo kiosk_e(kiosk_t('lookup.help_body')); ?></p>
                        </div>
                        <div class="kiosk-lookup-help-footer">
                            <button class="kiosk-btn kiosk-btn-primary kiosk-btn-block" type="button" data-kiosk-action="close-lookup-help">
                                <?php echo kiosk_e(kiosk_t('lookup.help_close')); ?>
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
                    <button class="kiosk-back-btn" type="button" data-kiosk-action="back-to-lookup" aria-label="<?php echo kiosk_e(kiosk_t('common.back')); ?>">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>

                    <h2 class="kiosk-form-title"><?php echo kiosk_e(kiosk_t('measure.title')); ?></h2>
                    <p class="kiosk-form-subtitle"><?php echo kiosk_t('measure.subtitle'); ?></p>

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
                                <div class="kiosk-live-label"><?php echo kiosk_e(kiosk_t('measure.height')); ?></div>
                                <div class="kiosk-live-value" data-kiosk-height-readout>--.-</div>
                                <div class="kiosk-live-unit">cm</div>
                                <div class="kiosk-live-status" data-kiosk-height-status>
                                    <span class="kiosk-status-icon waiting"></span>
                                    <?php echo kiosk_e(kiosk_t('measure.waiting')); ?>
                                </div>
                            </div>

                            <div class="kiosk-live-card kiosk-live-card-sm">
                                <div class="kiosk-live-label"><?php echo kiosk_e(kiosk_t('measure.weight')); ?></div>
                                <div class="kiosk-live-value" data-kiosk-weight-readout>--.--</div>
                                <div class="kiosk-live-unit">kg</div>
                                <div class="kiosk-live-status" data-kiosk-weight-status>
                                    <span class="kiosk-status-icon waiting"></span>
                                    <?php echo kiosk_e(kiosk_t('measure.waiting')); ?>
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
                        <span><?php echo kiosk_e(kiosk_t('measure.reminder')); ?></span>
                    </div>

                    <button class="kiosk-btn kiosk-btn-primary kiosk-btn-block" type="button" data-kiosk-action="process-measurement" disabled id="processBtn">
                        <?php echo kiosk_e(kiosk_t('measure.process')); ?>
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
                            <strong><?php echo kiosk_e(kiosk_t('processing.error_title')); ?></strong>
                            <span data-kiosk-processing-error-message>—</span>
                        </div>
                        <button class="kiosk-btn kiosk-btn-outline" type="button" data-kiosk-action="reset"><?php echo kiosk_e(kiosk_t('measure.reset')); ?></button>
                    </div>
                    <div class="kiosk-processing-ring">
                        <svg viewBox="0 0 200 200" aria-hidden="true">
                            <circle cx="100" cy="100" r="88" class="kiosk-ring-track"></circle>
                            <circle cx="100" cy="100" r="88" class="kiosk-ring-progress" data-kiosk-progress-ring></circle>
                        </svg>
                        <div class="kiosk-processing-label">
                            <strong data-kiosk-progress-value>0%</strong>
                            <span data-kiosk-process-stage><?php echo kiosk_e(kiosk_t('processing.stage')); ?></span>
                        </div>
                    </div>
                    <p class="kiosk-processing-note"><?php echo kiosk_e(kiosk_t('processing.note')); ?></p>
                </div>
            </section>

            <!-- ============================================================ -->
            <!-- STEP 5: RESULT SUMMARY -->
            <!-- ============================================================ -->
            <section class="kiosk-screen" data-kiosk-screen="results" hidden>
                <div class="kiosk-form-screen">
                    <button class="kiosk-back-btn" type="button" data-kiosk-action="reset" aria-label="<?php echo kiosk_e(kiosk_t('common.back')); ?>">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>

                    <h2 class="kiosk-form-title"><?php echo kiosk_e(kiosk_t('results.title')); ?></h2>
                    <p class="kiosk-form-subtitle"><?php echo kiosk_e(kiosk_t('results.subtitle')); ?></p>

                    <div class="kiosk-result-child-card">
                        <div class="kiosk-result-child-avatar" aria-hidden="true">
                            <span data-kiosk-result-initials>JD</span>
                        </div>
                        <div class="kiosk-result-child-info">
                            <div class="kiosk-result-child-name" data-kiosk-result-child>Juan Dela Cruz</div>
                            <div class="kiosk-result-child-meta">
                                <span class="kiosk-result-meta-item">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    <span data-kiosk-result-meta><?php echo kiosk_e(kiosk_t('age.months')); ?></span>
                                </span>
                                <span class="kiosk-result-meta-item">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    <span data-kiosk-result-sex><?php echo kiosk_e(kiosk_t('sex.male')); ?></span>
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
                            <span class="kiosk-result-label"><?php echo kiosk_e(kiosk_t('results.weight_label')); ?></span>
                            <span class="kiosk-result-val" data-kiosk-result-weight>28.6 kg</span>
                        </div>
                        <div class="kiosk-result-row">
                            <span class="kiosk-result-label"><?php echo kiosk_e(kiosk_t('results.height_label')); ?></span>
                            <span class="kiosk-result-val" data-kiosk-result-height>120.4 cm</span>
                        </div>
                    </div>

                    <h3 class="kiosk-result-section-title"><?php echo kiosk_e(kiosk_t('results.section')); ?></h3>
                    <div class="kiosk-result-status-grid">
                        <div class="kiosk-result-status-row">
                            <span><?php echo kiosk_e(kiosk_t('results.axis_wfa')); ?></span>
                            <span class="kiosk-status-badge is-normal" data-kiosk-result-wfa-status>Normal</span>
                        </div>
                        <div class="kiosk-result-status-row">
                            <span><?php echo kiosk_e(kiosk_t('results.axis_hfa')); ?></span>
                            <span class="kiosk-status-badge is-normal" data-kiosk-result-hfa-status>Normal</span>
                        </div>
                        <div class="kiosk-result-status-row">
                            <span><?php echo kiosk_e(kiosk_t('results.axis_wflh')); ?></span>
                            <span class="kiosk-status-badge is-normal" data-kiosk-result-wflh-status>Normal</span>
                        </div>
                    </div>
                    <button class="kiosk-btn kiosk-btn-primary kiosk-btn-block" type="button" data-kiosk-action="finish">
                        <?php echo kiosk_e(kiosk_t('results.done')); ?>
                    </button>
                </div>
            </section>

            <!-- ============================================================ -->
            <!-- STEP 6: THANK YOU / END -->
            <!-- ============================================================ -->
            <section class="kiosk-screen" data-kiosk-screen="thankyou" hidden>
                <div class="kiosk-thankyou">
                    <h2 class="kiosk-thankyou-title"><?php echo kiosk_e(kiosk_t('thankyou.title')); ?></h2>
                    <div class="kiosk-thankyou-check">
                        <svg width="80" height="80" viewBox="0 0 80 80" fill="none">
                            <circle cx="40" cy="40" r="38" fill="#0b6e4f"/>
                            <path d="M24 40l10 10 22-22" stroke="#fff" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <p class="kiosk-thankyou-msg"><?php echo kiosk_t('thankyou.msg'); ?></p>

                    <button class="kiosk-btn kiosk-btn-primary kiosk-btn-lg" type="button" data-kiosk-action="reset">
                        <?php echo kiosk_e(kiosk_t('thankyou.home')); ?>
                    </button>
                    <div class="kiosk-thankyou-progress" aria-hidden="true"><span id="thankyouProgressBar"></span></div>
                    <p class="sr-only" id="thankyouCountdown" aria-live="polite"></p>
                </div>
            </section>

            <!-- ============================================================ -->
            <!-- VIEW RESULTS SCREEN (previous measurements) -->
            <!-- ============================================================ -->
            <section class="kiosk-screen" data-kiosk-screen="view-results" hidden>
                <div class="kiosk-form-screen">
                    <button class="kiosk-back-btn" type="button" data-kiosk-action="back-to-welcome" aria-label="<?php echo kiosk_e(kiosk_t('common.back')); ?>">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>
                    <h2 class="kiosk-form-title"><?php echo kiosk_e(kiosk_t('view.title')); ?></h2>
                    <p class="kiosk-form-subtitle"><?php echo kiosk_e(kiosk_t('view.subtitle')); ?></p>
                    <div class="kiosk-view-results-search">
                        <svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="#7a998c" stroke-width="2"><circle cx="8" cy="8" r="6"/><line x1="12.5" y1="12.5" x2="16" y2="16"/></svg>
                        <input class="kiosk-input" type="text" id="viewResultsSearch" placeholder="<?php echo kiosk_e(kiosk_t('view.search')); ?>" />
                    </div>
                    <div class="kiosk-view-results-list" id="viewResultsList">
                        <div class="kiosk-view-results-empty">
                            <svg width="48" height="48" viewBox="0 0 48 48" fill="none" stroke="#d4e5dc" stroke-width="2"><circle cx="24" cy="24" r="20"/><line x1="18" y1="24" x2="30" y2="24"/><line x1="24" y1="18" x2="24" y2="30"/></svg>
                            <p><?php echo kiosk_e(kiosk_t('view.empty')); ?></p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============================================================ -->
            <!-- INFORMATION SCREEN -->
            <!-- ============================================================ -->
            <section class="kiosk-screen" data-kiosk-screen="info" hidden>
                <div class="kiosk-form-screen">
                    <button class="kiosk-back-btn" type="button" data-kiosk-action="back-to-welcome" aria-label="<?php echo kiosk_e(kiosk_t('common.back')); ?>">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>
                    <h2 class="kiosk-form-title"><?php echo kiosk_t('info.title'); ?></h2>
                    <div class="kiosk-info-scroll">
                        <div class="kiosk-info-card">
                            <div class="kiosk-info-card-icon kiosk-info-icon-green">
                                <svg width="28" height="28" viewBox="0 0 28 28" fill="none" stroke="#fff" stroke-width="2"><circle cx="14" cy="10" r="7"/><path d="M14 17v6M10 23h8"/></svg>
                            </div>
                            <div>
                                <h3><?php echo kiosk_e(kiosk_t('info.about_title')); ?></h3>
                                <p><?php echo kiosk_e(kiosk_t('info.about_body')); ?></p>
                            </div>
                        </div>
                        <div class="kiosk-info-card">
                            <div class="kiosk-info-card-icon kiosk-info-icon-blue">
                                <svg width="28" height="28" viewBox="0 0 28 28" fill="none" stroke="#fff" stroke-width="2"><rect x="4" y="4" width="20" height="20" rx="3"/><path d="M8 14l4 4 8-8"/></svg>
                            </div>
                            <div>
                                <h3><?php echo kiosk_e(kiosk_t('info.how_title')); ?></h3>
                                <p><?php echo kiosk_t('info.how_body'); ?></p>
                            </div>
                        </div>
                        <div class="kiosk-info-card">
                            <div class="kiosk-info-card-icon kiosk-info-icon-orange">
                                <svg width="28" height="28" viewBox="0 0 28 28" fill="none" stroke="#fff" stroke-width="2"><circle cx="14" cy="14" r="11"/><line x1="14" y1="8" x2="14" y2="14"/><circle cx="14" cy="19" r="1" fill="#fff"/></svg>
                            </div>
                            <div>
                                <h3><?php echo kiosk_e(kiosk_t('info.measure_title')); ?></h3>
                                <p><?php echo kiosk_t('info.measure_body'); ?></p>
                            </div>
                        </div>
                        <div class="kiosk-info-card">
                            <div class="kiosk-info-card-icon kiosk-info-icon-purple">
                                <svg width="28" height="28" viewBox="0 0 28 28" fill="none" stroke="#fff" stroke-width="2"><rect x="3" y="6" width="22" height="16" rx="3"/><path d="M8 12h4M8 16h6"/></svg>
                            </div>
                            <div>
                                <h3><?php echo kiosk_e(kiosk_t('info.status_title')); ?></h3>
                                <p><?php echo kiosk_t('info.status_body'); ?></p>
                            </div>
                        </div>
                        <div class="kiosk-info-card">
                            <div class="kiosk-info-card-icon kiosk-info-icon-red">
                                <svg width="28" height="28" viewBox="0 0 28 28" fill="none" stroke="#fff" stroke-width="2"><path d="M14 4l10 16H4z"/><line x1="14" y1="11" x2="14" y2="16"/><circle cx="14" cy="19" r="1" fill="#fff"/></svg>
                            </div>
                            <div>
                                <h3><?php echo kiosk_e(kiosk_t('info.reminders_title')); ?></h3>
                                <p><?php echo kiosk_t('info.reminders_body'); ?></p>
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
                        <h2><?php echo kiosk_e(kiosk_t('doc.privacy_title')); ?></h2>
                        <button class="kiosk-modal-close" type="button" data-kiosk-action="close-privacy-modal" aria-label="<?php echo kiosk_e(kiosk_t('common.close')); ?>">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <div class="kiosk-modal-body">
<?php echo kiosk_t('doc.privacy_body'); ?>
                    </div>
                    <div class="kiosk-modal-footer">
                        <button class="kiosk-btn kiosk-btn-primary kiosk-btn-block" type="button" data-kiosk-action="close-privacy-modal">
                            <?php echo kiosk_e(kiosk_t('doc.understand')); ?>
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
                        <h2><?php echo kiosk_e(kiosk_t('doc.info_title')); ?></h2>
                        <button class="kiosk-modal-close" type="button" data-kiosk-action="close-info-sheet" aria-label="<?php echo kiosk_e(kiosk_t('common.close')); ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="24" height="24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <div class="kiosk-modal-body">
<?php echo kiosk_t('doc.info_body'); ?>
                    </div>
                    <div class="kiosk-modal-footer">
                        <button class="kiosk-btn kiosk-btn-primary kiosk-btn-block" type="button" data-kiosk-action="close-info-sheet">
                            <?php echo kiosk_e(kiosk_t('doc.understand')); ?>
                        </button>
                    </div>
                </div>
            </div>

        </main>

            <!-- Hidden sidebar for activity feed (admin) -->
            <aside class="kiosk-sidepanel" aria-hidden="true">
            <div class="kiosk-side-card">
                <h3><?php echo kiosk_e(kiosk_t('feed.title')); ?></h3>
                <div class="kiosk-feed" data-kiosk-feed></div>
            </div>
        </aside>

        <script>
            window.KIOSK_DATA = <?php echo json_encode($appData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        </script>
        <script src="../assets/js/kiosk.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/kiosk.js') ?: time(); ?>" defer></script>
    </body>
</html>

<?php

require_once __DIR__ . '/../includes/admin_helpers.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/firebase_sync.php';

start_secure_session();
require_permission('sensors.update');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_device'])) {
    require_permission('sensors.update');

    $deviceId = (int)($_POST['device_id'] ?? 0);
    $location = trim((string)($_POST['location'] ?? ''));
    $barangayIdRaw = trim((string)($_POST['barangay_id'] ?? ''));
    $barangayId = $barangayIdRaw !== '' ? (int)$barangayIdRaw : null;
    $status = trim((string)($_POST['status'] ?? 'active'));
    $lastCalibrationAt = trim((string)($_POST['last_calibration_at'] ?? ''));
    $heightOffset = (float)($_POST['calibration_offset_height'] ?? 0);
    $weightOffset = (float)($_POST['calibration_offset_weight'] ?? 0);
    $hx711CalFactor = (float)($_POST['hx711_calibration_factor'] ?? -20892.50);
    $mountingHeightCm = (float)($_POST['mounting_height_cm'] ?? 182.88);

    if ($deviceId > 0) {
        admin_execute(
            'UPDATE devices SET location = ?, barangay_id = ?, status = ?, last_calibration_at = NULLIF(?, ""), calibration_offset_height = ?, calibration_offset_weight = ?, hx711_calibration_factor = ?, mounting_height_cm = ? WHERE id = ?',
            'sissddddi',
            [$location, $barangayId, $status, $lastCalibrationAt, $heightOffset, $weightOffset, $hx711CalFactor, $mountingHeightCm, $deviceId]
        );

        log_action((current_user()['id'] ?? null), 'UPDATE_DEVICE', 'info', 'Updated device ' . $deviceId);
        admin_redirect('/admin/sensors.php', ['notice' => 'Sensor settings updated successfully.', 'type' => 'success']);
    }

    admin_redirect('/admin/sensors.php', ['notice' => 'Device not found.', 'type' => 'error']);
}

$editId = (int)($_GET['id'] ?? ($_GET['edit'] ?? 0));

$device = null;

if ($editId > 0) {
    $deviceParams = [$editId];
    $deviceStmtSql = 'SELECT d.id, d.device_code, d.location, d.local_ip, d.barangay_id, bg.name AS barangay, d.status, d.last_seen_at, d.last_calibration_at, d.calibration_offset_height, d.calibration_offset_weight, d.hx711_calibration_factor, d.mounting_height_cm, d.updated_at,
            TIMESTAMPDIFF(SECOND, d.last_seen_at, NOW()) AS seconds_since_last_seen
     FROM devices d
     LEFT JOIN barangays bg ON bg.id = d.barangay_id
     WHERE d.id = ?
     LIMIT 1';

    $device = admin_fetch_one($deviceStmtSql, 'i', $deviceParams);
}

if ($device === null) {
    admin_redirect(
        '/admin/sensors.php',
        [
            'notice' => 'Device not found.',
            'type' => 'error'
        ]
    );
}

$device = api_sync_stale_device_status($device);
$device['connection_online'] = api_device_is_online($device);

$barangays = admin_barangay_options();

$connectionText = !empty($device['connection_online']) ? 'online' : 'offline';
$connectionClass = !empty($device['connection_online']) ? 'is-success' : 'is-danger';
$deviceStatus = (string)($device['status'] ?? 'offline');

$actions = '<a class="admin-btn-secondary" href="'
    . admin_e(app_url('/admin/sensors.php'))
    . '">' . admin_action_icon('back') . ' Sensors</a>';

admin_layout_start('Edit Device', 'Update location, assignment, status, and calibration offsets.', 'sensors', $actions, 'Edit Device');
?>
<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title"><?php echo admin_e((string)($device['device_code'] ?? '')); ?></h2>
            <p class="admin-section-subtitle">Last seen: <?php echo admin_e((string)($device['last_seen_at'] ?? 'never')); ?></p>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <span class="admin-pill <?php echo $deviceStatus === 'active' ? 'is-success' : ($deviceStatus === 'maintenance' ? 'is-warn' : 'is-danger'); ?>"><?php echo admin_e($deviceStatus); ?></span>
            <span class="admin-pill <?php echo $connectionClass; ?>"><?php echo admin_e($connectionText); ?></span>
            <span class="admin-pill is-muted"><?php echo admin_e((string)($device['barangay'] ?? 'All barangays')); ?></span>
        </div>
    </div>

    <form class="admin-form-grid" method="post" action="<?php echo admin_e(app_url('/admin/device_form.php')); ?>">
        <input type="hidden" name="device_id" value="<?php echo (int)$device['id']; ?>">
        <input type="hidden" name="save_device" value="1">

        <label class="admin-field">
            <span>Location</span>
            <input name="location" value="<?php echo admin_e((string)($device['location'] ?? '')); ?>">
        </label>
        <label class="admin-field">
            <span>Barangay</span>
            <select name="barangay_id">
                <option value="">-- Unassigned (shows all barangays) --</option>
                <?php foreach ($barangays as $barangay): ?>
                    <option value="<?php echo (int)$barangay['id']; ?>" <?php echo (int)($device['barangay_id'] ?? 0) === (int)$barangay['id'] ? 'selected' : ''; ?>><?php echo admin_e($barangay['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="admin-field">
            <span>Device status</span>
            <select name="status">
                <option value="active" <?php echo $deviceStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="maintenance" <?php echo $deviceStatus === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                <option value="offline" <?php echo $deviceStatus === 'offline' ? 'selected' : ''; ?>>Offline</option>
            </select>
        </label>
        <label class="admin-field">
            <span>Last calibration date</span>
            <input type="date" name="last_calibration_at" value="<?php echo admin_e((string)($device['last_calibration_at'] ?? '')); ?>">
        </label>
        <label class="admin-field">
            <span>Height offset (cm)</span>
            <input type="number" step="0.01" name="calibration_offset_height" value="<?php echo admin_e((string)($device['calibration_offset_height'] ?? '0')); ?>">
        </label>
        <label class="admin-field">
            <span>Weight offset (kg)</span>
            <input type="number" step="0.001" name="calibration_offset_weight" value="<?php echo admin_e((string)($device['calibration_offset_weight'] ?? '0')); ?>">
        </label>
        <label class="admin-field">
            <span>HX711 calibration factor</span>
            <input type="number" step="0.0001" name="hx711_calibration_factor" id="cal-factor-input" value="<?php echo admin_e((string)($device['hx711_calibration_factor'] ?? '-20892.5')); ?>">
            <small class="admin-field-hint">Raw load-cell scale factor (grams per HX711 count). Fetched by the ESP32 on its next poll — no reflash needed.</small>
        </label>
        <label class="admin-field">
            <span>Mounting height (cm)</span>
            <input type="number" step="0.01" name="mounting_height_cm" value="<?php echo admin_e((string)($device['mounting_height_cm'] ?? '182.88')); ?>">
            <small class="admin-field-hint">TF-Luna sensor height above an empty platform. Fetched by the ESP32 on its next poll — no reflash needed.</small>
        </label>

        <div class="admin-field admin-field-wide" style="align-content:end;">
            <span>&nbsp;</span>
            <div class="admin-actions">
                <button class="admin-btn" type="submit"><?php echo admin_action_icon('save'); ?> Save sensor settings</button>
            </div>
        </div>
    </form>
</section>

<!-- ================================================================
SCALE CALIBRATION WIZARD
Connects directly to the ESP32 via WebSocket to run calibration
without needing Arduino IDE or Serial Monitor.
================================================================ -->
<section class="admin-section" id="calibration-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">Scale Calibration</h2>
            <p class="admin-section-subtitle">Calibrate the HX711 load-cell in-browser — no Arduino IDE needed.</p>
        </div>
    </div>

    <div class="cal-wiz" id="cal-wiz">

        <!-- Connection status banner -->
        <div class="cal-banner" id="cal-banner">
            <span id="cal-banner-text">Connect to the ESP32 to begin calibration.</span>
            <button class="admin-btn-secondary" id="cal-connect-btn" type="button">Connect</button>
        </div>

        <!-- Step indicators -->
        <div class="cal-steps" id="cal-steps" style="display:none;">
            <div class="cal-step active" id="cal-step-1">
                <span class="cal-step-num">1</span>
                <span>Tare (zero)</span>
            </div>
            <div class="cal-step" id="cal-step-2">
                <span class="cal-step-num">2</span>
                <span>Read raw</span>
            </div>
            <div class="cal-step" id="cal-step-3">
                <span class="cal-step-num">3</span>
                <span>Calculate</span>
            </div>
        </div>

        <!-- Step 1: Tare -->
        <div class="cal-card" id="cal-ui-1" style="display:none;">
            <h3>Step 1 — Tare (zero the scale)</h3>
            <p>Make sure the platform is <strong>completely empty</strong> before clicking Tare.</p>
            <div class="cal-actions">
                <button class="admin-btn" id="cal-btn-tare" type="button">Tare Scale</button>
            </div>
            <div class="cal-log" id="cal-log-1"></div>
        </div>

        <!-- Step 2: Read raw -->
        <div class="cal-card" id="cal-ui-2" style="display:none;">
            <h3>Step 2 — Place weight &amp; read raw</h3>
            <p>Place your <strong>known weight</strong> on the platform, then click "Read Raw".<br>Wait for the reading to stabilize before calculating.</p>
            <div class="cal-live">
                <span>Raw reading:</span>
                <strong id="cal-raw-display">—</strong>
                <span id="cal-stable-badge" class="admin-pill is-warn" style="display:none;">Stabilizing...</span>
                <span id="cal-ready-badge" class="admin-pill is-success" style="display:none;">Ready</span>
            </div>
            <div class="cal-actions">
                <button class="admin-btn" id="cal-btn-read" type="button">Read Raw</button>
                <button class="admin-btn-secondary" id="cal-btn-stop" type="button" style="display:none;">Stop</button>
            </div>
            <div class="cal-log" id="cal-log-2"></div>
        </div>

        <!-- Step 3: Calculate & Apply -->
        <div class="cal-card" id="cal-ui-3" style="display:none;">
            <h3>Step 3 — Calculate &amp; apply factor</h3>
            <p>Enter the known weight you placed on the platform, then click Calculate.</p>
            <div class="cal-factor-input">
                <label>Known weight (kg):</label>
                <input type="number" step="0.01" id="cal-known-weight" value="2.00" min="0.1" max="50">
            </div>
            <div class="cal-actions">
                <button class="admin-btn" id="cal-btn-commit" type="button">Calculate Factor</button>
            </div>
            <div class="cal-result" id="cal-result" style="display:none;">
                <div class="cal-result-label">New calibration factor:</div>
                <div class="cal-result-value" id="cal-result-factor">—</div>
                <div class="cal-actions">
                    <button class="admin-btn" id="cal-btn-apply" type="button">Apply to Form Above</button>
                </div>
            </div>
            <div class="cal-log" id="cal-log-3"></div>
        </div>

    </div>
</section>

<style>
#calibration-section { margin-top: 2rem; }

/* ── Calibration wizard — theme-aware colors ── */
.cal-wiz { display: flex; flex-direction: column; gap: 1rem; }

.cal-banner {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0.75rem 1rem;
    background: var(--admin-surface);
    border: 1px solid var(--admin-border);
    border-radius: 8px;
}
.cal-banner.connected {
    border-color: var(--admin-cal-succ);
    background: var(--admin-cal-succ-bg);
}
.cal-banner.error {
    border-color: var(--admin-danger);
    background: var(--admin-cal-err-bg);
}

.cal-steps { display: flex; gap: 0.5rem; flex-wrap: wrap; }

.cal-step {
    display: flex; align-items: center; gap: 0.5rem;
    padding: 0.5rem 1rem; border-radius: 20px;
    background: var(--admin-surface-alt);
    border: 1px solid var(--admin-border);
    color: var(--admin-muted);
    font-size: 0.875rem;
}
.cal-step.active {
    background: var(--admin-cal-active-bg);
    border-color: var(--admin-cal-active);
    color: var(--admin-cal-active);
    font-weight: 600;
}
.cal-step.done {
    background: var(--admin-cal-succ-bg);
    border-color: var(--admin-cal-succ);
    color: var(--admin-cal-succ);
}
.cal-step-num {
    width: 22px; height: 22px; border-radius: 50%;
    background: currentColor; color: var(--admin-surface);
    display: flex; align-items: center; justify-content: center;
    font-size: 0.75rem; font-weight: 700;
}
.cal-step.done .cal-step-num { background: var(--admin-cal-succ); color: var(--admin-surface); }

.cal-card {
    padding: 1.25rem; border-radius: 8px;
    background: var(--admin-surface-alt);
    border: 1px solid var(--admin-border);
}
.cal-card h3 { margin: 0 0 0.5rem; font-size: 1rem; color: var(--admin-text); }
.cal-card p { margin: 0 0 1rem; color: var(--admin-muted); font-size: 0.875rem; }

.cal-live {
    display: flex; align-items: center; gap: 0.75rem;
    margin-bottom: 1rem; padding: 0.75rem;
    background: var(--admin-surface);
    border: 1px solid var(--admin-border);
    border-radius: 6px;
    font-family: monospace; font-size: 1.25rem;
    color: var(--admin-text);
}
.cal-live strong { font-size: 1.5rem; color: var(--admin-text); }

.cal-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }

.cal-log {
    margin-top: 0.75rem; padding: 0.5rem 0.75rem;
    background: var(--admin-surface);
    border: 1px solid var(--admin-border);
    border-radius: 4px;
    font-family: monospace; font-size: 0.8rem;
    color: var(--admin-muted);
    max-height: 120px; overflow-y: auto;
}
.cal-log .ok { color: var(--admin-cal-succ); }
.cal-log .err { color: var(--admin-danger); }

.cal-factor-input {
    display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;
}
.cal-factor-input label { color: var(--admin-text); font-size: 0.875rem; }
.cal-factor-input input {
    width: 100px;
    background: var(--admin-field-bg);
    border: 1px solid var(--admin-border);
    border-radius: 4px;
    padding: 0.25rem 0.5rem;
    color: var(--admin-text);
}

.cal-result {
    margin-top: 1rem; padding: 1rem;
    background: var(--admin-cal-succ-bg);
    border: 1px solid var(--admin-cal-succ);
    border-radius: 8px;
}
.cal-result-label { font-size: 0.875rem; color: var(--admin-cal-succ); margin-bottom: 0.25rem; }
.cal-result-value {
    font-family: monospace; font-size: 1.5rem; font-weight: 700;
    color: var(--admin-cal-succ); margin-bottom: 0.75rem;
}

/* Input flash animation on "Apply" */
#cal-factor-input { transition: background-color 0.4s ease; }
#cal-factor-input.cal-flash {
    background-color: var(--admin-cal-succ-bg) !important;
}

/* ── Dark mode overrides for calibration wizard ── */
[data-theme="dark"] .cal-banner.connected { background: rgba(46, 197, 122, 0.12); }
[data-theme="dark"] .cal-banner.error { background: rgba(239, 83, 80, 0.12); }
[data-theme="dark"] .cal-step.active {
    background: rgba(59, 130, 246, 0.18);
    border-color: #60a5fa;
    color: #60a5fa;
}
[data-theme="dark"] .cal-step.done {
    background: rgba(46, 197, 122, 0.12);
    border-color: #2ec57a;
    color: #2ec57a;
}
[data-theme="dark"] .cal-step.done .cal-step-num { background: #2ec57a; }
[data-theme="dark"] .cal-result {
    background: rgba(46, 197, 122, 0.1);
    border-color: #2ec57a;
}
[data-theme="dark"] .cal-result-label,
[data-theme="dark"] .cal-result-value { color: #2ec57a; }
</style>

<script>
(function() {
    // ESP32 WebSocket config — use device's local IP stored in DB
    var deviceIp = <?php echo json_encode((string)($device['local_ip'] ?? '')); ?>;
    var deviceCode = <?php echo json_encode((string)($device['device_code'] ?? '')); ?>;
    var wsPort = 80;
    var wsPath = '/ws';
    var ws = null;
    var wsConnected = false;
    var calStep = 0;
    var newCalFactor = null;
    var stableCount = 0;
    var pollStableTimer = null;

    var el = function(id) { return document.getElementById(id); };

    // ── Connection ────────────────────────────────────────────────
    function connectWs() {
        if (!deviceIp) {
            showBanner('error', 'No local IP for this device. Is the ESP32 online?');
            return;
        }
        showBanner('', 'Connecting to ESP32 at ' + deviceIp + '...');
        el('cal-connect-btn').disabled = true;

        var url = 'ws://' + deviceIp + ':' + wsPort + wsPath;
        ws = new WebSocket(url);

        ws.onopen = function() {
            wsConnected = true;
            showBanner('connected', 'Connected to ' + deviceCode + ' (' + deviceIp + ')');
            el('cal-connect-btn').textContent = 'Disconnect';
            el('cal-connect-btn').disabled = false;
            showStep(1);
        };

        ws.onmessage = function(event) {
            try {
                var msg = JSON.parse(event.data);
                handleWsMessage(msg);
            } catch(e) {}
        };

        ws.onclose = function() {
            wsConnected = false;
            ws = null;
            showBanner('', 'Disconnected. Click Connect to try again.');
            el('cal-connect-btn').textContent = 'Connect';
            el('cal-connect-btn').disabled = false;
            el('cal-steps').style.display = 'none';
            hideAllSteps();
            clearInterval(pollStableTimer);
        };

        ws.onerror = function() {
            showBanner('error', 'Could not connect to ESP32. Is it online and on the same network?');
            el('cal-connect-btn').disabled = false;
        };
    }

    function disconnectWs() {
        if (ws) ws.close();
    }

    function wsSend(msg) {
        if (ws && wsConnected) ws.send(JSON.stringify(msg));
    }

    // ── UI helpers ──────────────────────────────────────────────────
    function showBanner(cls, text) {
        var b = el('cal-banner');
        b.className = 'cal-banner' + (cls ? ' ' + cls : '');
        el('cal-banner-text').textContent = text;
    }

    function showStep(n) {
        calStep = n;
        el('cal-steps').style.display = 'flex';
        el('cal-steps').querySelectorAll('.cal-step').forEach(function(s, i) {
            s.classList.remove('active', 'done');
            if (i + 1 < n) s.classList.add('done');
            else if (i + 1 === n) s.classList.add('active');
        });
        hideAllSteps();
        if (n >= 1) el('cal-ui-1').style.display = 'block';
        if (n >= 2) el('cal-ui-2').style.display = 'block';
        if (n >= 3) el('cal-ui-3').style.display = 'block';
    }

    function hideAllSteps() {
        ['cal-ui-1','cal-ui-2','cal-ui-3'].forEach(function(id) {
            var el2 = el(id);
            if (el2) el2.style.display = 'none';
        });
    }

    function log(stepId, msg, cls) {
        var d = el('cal-log-' + stepId);
        if (!d) return;
        var p = document.createElement('div');
        p.textContent = '> ' + msg;
        if (cls) p.classList.add(cls);
        d.appendChild(p);
        d.scrollTop = d.scrollHeight;
    }

    // ── WebSocket message handler ───────────────────────────────────
    function handleWsMessage(msg) {
        if (msg.type === 'calibrate') {
            handleCalibrateMsg(msg);
        } else if (msg.type === 'calibrate_status') {
            handleCalibrateStatus(msg);
        }
    }

    function handleCalibrateMsg(msg) {
        var action = msg.action;
        var result = msg.result;
        var message = msg.message || '';

        if (action === 'tare') {
            if (result === 'ok') {
                log('1', 'Tare complete.', 'ok');
                setTimeout(function() { showStep(2); }, 800);
            } else {
                log('1', 'Tare failed: ' + message, 'err');
            }
        } else if (action === 'read_raw') {
            if (result === 'ok') {
                log('2', 'Reading active. Place weight on platform now.', 'ok');
            } else {
                log('2', 'Error: ' + message, 'err');
            }
        } else if (action === 'stop_reading') {
            log('2', 'Reading stopped.');
        } else if (action === 'commit') {
            if (result === 'ok') {
                newCalFactor = msg.new_calibration_factor;
                el('cal-result-factor').textContent = newCalFactor.toFixed(4);
                el('cal-result').style.display = 'block';
                log('3', 'Calibration factor calculated: ' + newCalFactor.toFixed(4), 'ok');
            } else {
                log('3', 'Commit failed: ' + message, 'err');
            }
        }
    }

    function handleCalibrateStatus(msg) {
        if (calStep !== 2) return;
        el('cal-raw-display').textContent = msg.latest_raw ? msg.latest_raw.toFixed(2) : '—';

        if (msg.stable) {
            el('cal-stable-badge').style.display = 'none';
            el('cal-ready-badge').style.display = 'inline-block';
        } else {
            el('cal-stable-badge').style.display = 'inline-block';
            el('cal-ready-badge').style.display = 'none';
        }
    }

    // ── Button handlers ─────────────────────────────────────────────
    el('cal-connect-btn').addEventListener('click', function() {
        if (wsConnected) {
            disconnectWs();
        } else {
            connectWs();
        }
    });

    el('cal-btn-tare').addEventListener('click', function() {
        log('1', 'Sending tare command...');
        wsSend({ type: 'calibrate', action: 'tare' });
    });

    el('cal-btn-read').addEventListener('click', function() {
        stableCount = 0;
        log('2', 'Starting raw readings. Place weight on platform...');
        wsSend({ type: 'calibrate', action: 'read_raw' });
        el('cal-btn-read').style.display = 'none';
        el('cal-btn-stop').style.display = 'inline-block';
    });

    el('cal-btn-stop').addEventListener('click', function() {
        wsSend({ type: 'calibrate', action: 'stop_reading' });
        el('cal-btn-stop').style.display = 'none';
        el('cal-btn-read').style.display = 'inline-block';
    });

    el('cal-btn-commit').addEventListener('click', function() {
        var weight = parseFloat(el('cal-known-weight').value);
        if (!weight || weight <= 0) {
            alert('Enter a valid weight in kg.');
            return;
        }
        log('3', 'Committing with known weight: ' + weight + ' kg...');
        wsSend({ type: 'calibrate', action: 'commit', known_weight_kg: weight });
    });

    el('cal-btn-apply').addEventListener('click', function() {
        if (newCalFactor === null) return;
        el('cal-factor-input').value = newCalFactor.toFixed(4);
        // Scroll to the form factor input
        el('cal-factor-input').scrollIntoView({ behavior: 'smooth', block: 'center' });
        // Briefly highlight it
        el('cal-factor-input').classList.add('cal-flash');
        setTimeout(function() {
            el('cal-factor-input').classList.remove('cal-flash');
        }, 1500);
        log('3', 'Applied to form above. Click Save Sensor Settings to persist.', 'ok');
    });

})();
</script>
<?php
admin_layout_end();

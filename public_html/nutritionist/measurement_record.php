<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

$user = nutritionist_require_access();

$childrenParams = [];
$childrenScope = nutritionist_scope_fragment($user, 'c.barangay_id', $childrenParams);

$children = admin_fetch_all(
    "SELECT
        c.id,
        c.child_code,
        c.first_name,
        c.middle_name,
        c.last_name,
        c.birthdate,
        c.sex,
        c.barangay_id,
        bg.name AS barangay
     FROM children c
     LEFT JOIN barangays bg ON bg.id = c.barangay_id
     WHERE {$childrenScope}
     ORDER BY c.last_name ASC, c.first_name ASC",
    str_repeat('i', count($childrenParams)),
    $childrenParams
);

/*
 * Look up the preselected child (from the URL `?child=ID`) and pull the
 * existing latest measurement so we can show "previous reading" hints
 * while the new measurement is being captured.
 */
$preselectedChildId = (int)($_GET['child'] ?? 0);
$preselectedChild = null;
foreach ($children as $option) {
    if ((int)$option['id'] === $preselectedChildId) {
        $preselectedChild = $option;
        break;
    }
}

$lastMeasurement = null;
if ($preselectedChild !== null) {
    $lastMeasurement = admin_fetch_one(
        'SELECT id, measurement_date, height_cm, weight_kg, waz, haz, whz, nutritional_status
         FROM measurements WHERE child_id = ? ORDER BY measurement_date DESC, id DESC LIMIT 1',
        'i',
        [(int)$preselectedChild['id']]
    );
}

/*
 * Pre-build the child list as JSON for the front-end search + the
 * typeahead-style filter. Keeping it server-rendered keeps the page
 * fully usable without any client round-trip when a child is selected
 * from the dropdown.
 */
$childrenJson = json_encode(
    array_map(static function (array $c): array {
        $fullName = trim($c['first_name'] . ' ' . ($c['middle_name'] ?? '') . ' ' . $c['last_name']);
        return [
            'id' => (int)$c['id'],
            'code' => (string)$c['child_code'],
            'name' => $fullName,
            'first_name' => (string)$c['first_name'],
            'last_name' => (string)$c['last_name'],
            'sex' => (string)$c['sex'],
            'birthdate' => (string)$c['birthdate'],
            'barangay' => (string)($c['barangay'] ?? ''),
            'label' => $fullName . ' (' . $c['child_code'] . ' · ' . ($c['barangay'] ?? 'No barangay') . ')',
        ];
    }, $children),
    JSON_UNESCAPED_UNICODE
);

$actions = '<a class="admin-btn-secondary" href="'
    . nutritionist_e(app_url('/nutritionist/children.php'))
    . '">' . admin_action_icon('back') . ' Children</a>';

nutritionist_layout_start(
    'New measurement',
    'Select a child, type weight and height, see the WHO assessment live, and save.',
    'children',
    $actions,
    'New measurement'
);
?>
<style>
.record-shell{display:grid;grid-template-columns:minmax(0,720px);justify-content:center;gap:16px;align-items:start}
.record-shell > div{display:grid;gap:16px;align-items:start;min-width:0}

.step-card{padding:22px}
.step-num{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:var(--admin-primary);color:#fff;font-size:11px;font-weight:700;margin-right:8px}
.step-title{font-size:13px;font-weight:700;color:var(--admin-text);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:14px;display:flex;align-items:center}

.child-picker{position:relative}
.child-picker input[type="search"]{width:100%;padding:10px 12px;border:1px solid var(--admin-border);border-radius:10px;background:var(--admin-surface);color:var(--admin-text);font-size:13px;font-family:inherit}
.child-picker input[type="search"]:focus{outline:none;border-color:var(--admin-primary)}
.child-picker-results{position:absolute;left:0;right:0;top:calc(100% + 4px);background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.12);max-height:260px;overflow-y:auto;z-index:20;display:none}
.child-picker-results.is-open{display:block}
.child-picker-item{padding:9px 12px;display:flex;align-items:center;gap:10px;cursor:pointer;font-size:12px;border-bottom:1px solid var(--admin-border)}
.child-picker-item:last-child{border-bottom:none}
.child-picker-item:hover{background:var(--admin-surface-alt)}
.child-picker-item .avatar{width:28px;height:28px;border-radius:50%;background:#94a3b8;color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.child-picker-item .meta{min-width:0;flex:1}
.child-picker-item .meta .name{font-weight:600;color:var(--admin-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.child-picker-item .meta .sub{font-size:10px;color:var(--admin-muted);margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

.child-summary{display:none;background:var(--admin-primary-soft);border:1px solid var(--admin-border);border-radius:10px;padding:12px 14px;margin-top:10px}
.child-summary.is-visible{display:block}
.child-summary .row{display:flex;justify-content:space-between;align-items:center;font-size:12px;padding:3px 0}
.child-summary .row .label{color:var(--admin-muted)}
.child-summary .row .value{font-weight:600;color:var(--admin-text)}
.child-summary .pills{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}

.measurement-form{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px}
.measurement-form .admin-field{margin:0;display:flex;flex-direction:column;gap:6px}
.measurement-form .admin-field > span{font-size:11px;color:var(--admin-muted);font-weight:600}
.measurement-form .admin-field > input{padding:12px 14px;border:1px solid var(--admin-border);border-radius:10px;background:var(--admin-surface);color:var(--admin-text);font-size:14px;font-family:inherit}
.measurement-form .admin-field > input:focus{outline:none;border-color:var(--admin-primary)}
.measurement-form .admin-field-wide{grid-column:1 / -1}
@media(max-width:560px){.measurement-form{grid-template-columns:1fr}}
.measurement-form .admin-field.is-disabled > input{background:var(--admin-surface-alt);color:var(--admin-muted);cursor:not-allowed}

.who-result{margin-top:18px;background:var(--admin-surface-alt);border:1px solid var(--admin-border);border-radius:12px;padding:16px}
.who-result .who-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.who-result .who-header .title{font-size:12px;font-weight:700;color:var(--admin-text);text-transform:uppercase;letter-spacing:0.04em}
.who-result .zgrid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.who-result .zgrid .zcard{background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:10px;padding:12px;text-align:center}
.who-result .zgrid .zcard .axis{font-size:10px;color:var(--admin-muted);text-transform:uppercase;letter-spacing:0.04em}
.who-result .zgrid .zcard .value{font-size:22px;font-weight:800;margin:4px 0}
.who-result .zgrid .zcard .value.is-warn{color:var(--admin-danger)}
.who-result .zgrid .zcard .value.is-ok{color:var(--admin-primary)}
.who-result .zgrid .zcard .label{font-size:10px;color:var(--admin-muted)}

.who-flags{margin-top:10px;display:flex;flex-wrap:wrap;gap:6px}
.who-flags .pill{font-size:10px;font-weight:600;padding:3px 8px;border-radius:6px;background:rgba(224,49,49,0.10);color:#E03131}

.who-summary{margin-top:10px;padding:10px 12px;border-radius:8px;background:var(--admin-primary-soft);color:var(--admin-text);font-size:12px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}

.action-row{margin-top:18px;display:flex;gap:10px;flex-wrap:wrap}

.flag-banner{display:none;margin-top:10px;padding:10px 12px;border-radius:8px;background:rgba(224,49,49,0.08);color:#E03131;font-size:12px;font-weight:600}
.flag-banner.is-visible{display:block}

.last-hint{margin-top:8px;padding:8px 10px;border-radius:8px;background:var(--admin-surface-alt);font-size:11px;color:var(--admin-muted);display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap}
.last-hint strong{color:var(--admin-text);font-weight:700}
</style>

<?php if (!nutritionist_can_write()): ?>
<section class="nutritionist-panel">
    <div class="admin-flash">You do not have permission to record measurements. Contact your administrator if this is a mistake.</div>
</section>
<?php else: ?>

<section class="record-shell">
    <div>
        <article class="nutritionist-panel step-card">
            <div class="step-title"><span class="step-num">1</span> Select child</div>

            <div class="child-picker">
                <input
                    type="search"
                    id="child-search"
                    placeholder="Search by name, code, or barangay..."
                    autocomplete="off"
                    value="<?php echo $preselectedChild !== null ? nutritionist_e($preselectedChild['first_name'] . ' ' . $preselectedChild['last_name'] . ' (' . $preselectedChild['child_code'] . ')') : ''; ?>"
                >
                <input type="hidden" id="child-id" name="child_id" value="<?php echo $preselectedChild !== null ? (int)$preselectedChild['id'] : ''; ?>">
                <div class="child-picker-results" id="child-results"></div>
            </div>

            <div class="child-summary<?php echo $preselectedChild !== null ? ' is-visible' : ''; ?>" id="child-summary">
                <div class="row"><span class="label">Name</span><span class="value" id="cs-name"><?php echo $preselectedChild !== null ? nutritionist_e(trim($preselectedChild['first_name'] . ' ' . ($preselectedChild['middle_name'] ?? '') . ' ' . $preselectedChild['last_name'])) : ''; ?></span></div>
                <div class="row"><span class="label">Code</span><span class="value" id="cs-code"><?php echo $preselectedChild !== null ? nutritionist_e((string)$preselectedChild['child_code']) : ''; ?></span></div>
                <div class="row"><span class="label">Sex</span><span class="value" id="cs-sex"><?php echo $preselectedChild !== null ? nutritionist_e((string)$preselectedChild['sex']) : ''; ?></span></div>
                <div class="row"><span class="label">Birthdate</span><span class="value" id="cs-birthdate"><?php echo $preselectedChild !== null ? nutritionist_e((string)$preselectedChild['birthdate']) : ''; ?></span></div>
                <div class="row"><span class="label">Age at measurement</span><span class="value" id="cs-age">—</span></div>
                <div class="row"><span class="label">Age in days</span><span class="value" id="cs-age-days">—</span></div>
                <div class="row"><span class="label">Barangay</span><span class="value" id="cs-barangay"><?php echo $preselectedChild !== null ? nutritionist_e((string)($preselectedChild['barangay'] ?? '—')) : ''; ?></span></div>
                <div class="row"><span class="label">Measurement date</span><span class="value" id="cs-measurement-date">—</span></div>
                <div class="pills" id="cs-pills"></div>

                <?php if ($lastMeasurement !== null): ?>
                    <div class="last-hint">
                        <span>Last reading: <strong><?php echo nutritionist_e(number_format((float)$lastMeasurement['weight_kg'], 2)); ?> kg</strong> · <strong><?php echo nutritionist_e(number_format((float)$lastMeasurement['height_cm'], 1)); ?> cm</strong> on <?php echo nutritionist_e(date('M j, Y', strtotime((string)$lastMeasurement['measurement_date']))); ?></span>
                        <?php
                        $abbrMap = [
                            'Normal' => 'Normal',
                            'Moderately Underweight' => 'MUW',
                            'Severely Underweight' => 'SUW',
                            'Moderately Stunted' => 'MSt',
                            'Severely Stunted' => 'SSt',
                            'Moderately Wasted' => 'MW/MAM',
                            'Severely Wasted' => 'SW/SAM',
                            'Overweight' => 'OW',
                            'Obese' => 'Ob',
                        ];
                        $abbrStatus = $abbrMap[(string)$lastMeasurement['nutritional_status']] ?? (string)$lastMeasurement['nutritional_status'];
                        ?>
                        <span class="admin-pill <?php echo nutritionist_e(nutritionist_status_class($abbrStatus)); ?>"><?php echo nutritionist_e($abbrStatus); ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </article>

        <article class="nutritionist-panel step-card">
            <div class="step-title"><span class="step-num">2</span> Enter values</div>

            <form
                id="new-measurement-form"
                data-endpoint="<?php echo nutritionist_e(app_url('/api/nutritionist/measurements_create.php')); ?>"
            >
                <div class="measurement-form">
                    <label class="admin-field">
                        <span>Weight (kg) *</span>
                        <input type="number" name="weight_kg" id="weight-input" step="0.001" min="2" max="80" inputmode="decimal" placeholder="e.g. 14.500" required>
                    </label>
                    <label class="admin-field">
                        <span>Height (cm) *</span>
                        <input type="number" name="height_cm" id="height-input" step="0.01" min="40" max="140" inputmode="decimal" placeholder="e.g. 95.50" required>
                    </label>
                    <label class="admin-field admin-field-wide">
                        <span>Measurement date</span>
                        <input type="date" name="measurement_date" id="date-input" value="<?php echo nutritionist_e(date('Y-m-d')); ?>" max="<?php echo nutritionist_e(date('Y-m-d')); ?>" required>
                    </label>
                </div>
            </form>

            <div class="who-result" id="who-result" style="display:none;">
                <div class="who-header">
                    <span class="title">WHO assessment (live)</span>
                    <span class="admin-mini" id="who-age">—</span>
                </div>
                <div class="zgrid">
                    <div class="zcard">
                        <div class="axis">Weight-for-Age</div>
                        <div class="value is-ok" id="who-waz">—</div>
                        <div class="label">WAZ</div>
                    </div>
                    <div class="zcard">
                        <div class="axis">Height-for-Age</div>
                        <div class="value is-ok" id="who-haz">—</div>
                        <div class="label">HAZ</div>
                    </div>
                    <div class="zcard">
                        <div class="axis">Weight-for-Height</div>
                        <div class="value is-ok" id="who-whz">—</div>
                        <div class="label">WHZ</div>
                    </div>
                </div>
                <div class="who-summary" id="who-summary">
                    <span>Awaiting values…</span>
                </div>
                <div class="who-flags" id="who-flags"></div>
            </div>

            <div class="flag-banner" id="flag-banner"></div>

            <div id="override-panel" style="display:none;padding:12px 14px;border-radius:8px;background:rgba(220,38,38,.06);border:1px solid rgba(220,38,38,.28);margin-bottom:14px;">
                <div style="font-weight:800;font-size:13px;color:#991b1b;margin-bottom:6px;">Not scheduled — record as override?</div>
                <p id="override-hint" style="margin:0 0 10px;font-size:12px;color:#7f1d1d;"></p>
                <label class="admin-field" style="margin-bottom:10px;">
                    <span>Reason for override *</span>
                    <textarea id="override-reason" maxlength="255" rows="2" placeholder="e.g. Doctor requested an urgent re-weigh after illness"></textarea>
                    <span class="admin-field-message"></span>
                </label>
                <div class="action-row" style="margin-top:0;">
                    <button class="admin-btn" type="button" id="override-save-btn">Save as override</button>
                    <button class="admin-btn-secondary" type="button" id="override-cancel-btn">Cancel</button>
                </div>
                <div class="form-message" id="override-message" aria-live="polite"></div>
            </div>

            <div id="recheck-panel" style="display:none;padding:12px 14px;border-radius:8px;background:rgba(11,110,79,.06);border:1px solid rgba(11,110,79,.30);margin-bottom:14px;">
                <div style="font-weight:800;font-size:13px;color:#0b6e4f;margin-bottom:6px;">Double-check — save as recheck?</div>
                <p style="margin:0 0 10px;font-size:12px;color:#14532d;">Rechecks must be within the same month as the measurement you are verifying (before moving to the next month). They never move the due schedule. The previous reading stays in history; this one is marked as the verified value.</p>
                <label class="admin-field" style="margin-bottom:10px;">
                    <span>Reason for recheck *</span>
                    <textarea id="recheck-reason" maxlength="255" rows="2" placeholder="e.g. Child moved during scan, unstable reading — verifying"></textarea>
                    <span class="admin-field-message"></span>
                </label>
                <div class="action-row" style="margin-top:0;">
                    <button class="admin-btn" type="button" id="recheck-save-btn">Save as recheck</button>
                    <button class="admin-btn-secondary" type="button" id="recheck-cancel-btn">Cancel</button>
                </div>
                <div class="form-message" id="recheck-message" aria-live="polite"></div>
            </div>

            <div class="action-row">
                <button class="admin-btn" type="button" id="save-btn" disabled>
                    <?php echo admin_action_icon('save'); ?> Save measurement
                </button>
                <button class="admin-btn-secondary" type="button" id="recheck-open-btn">Measure again (double-check)</button>
                <button class="admin-btn-secondary" type="button" id="reset-btn">Clear values</button>
                <a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/children.php')); ?>" id="cancel-link">Cancel</a>
            </div>
        </article>
</section>

<script id="children-data" type="application/json"><?php echo $childrenJson; ?></script>
<script>
(function () {
    var CHILDREN = [];
    try { CHILDREN = JSON.parse(document.getElementById('children-data').textContent || '[]'); }
    catch (err) { CHILDREN = []; }

    var PREVIEW_URL = '<?php echo nutritionist_e(app_url("/api/nutritionist/who_preview.php")); ?>';
    var OVERRIDE_URL = '<?php echo nutritionist_e(app_url("/api/nutritionist/measurements_override.php")); ?>';
    var RECHECK_URL = '<?php echo nutritionist_e(app_url("/api/nutritionist/measurements_recheck.php")); ?>';

    var $ = function (id) { return document.getElementById(id); };

    var childSearch = $('child-search');
    var childResults = $('child-results');
    var childIdInput = $('child-id');
    var childSummary = $('child-summary');
    var selectedChild = null;

    var weightInput = $('weight-input');
    var heightInput = $('height-input');
    var dateInput = $('date-input');

    var whoResult = $('who-result');
    var whoAge = $('who-age');
    var whoWaz = $('who-waz');
    var whoHaz = $('who-haz');
    var whoWhz = $('who-whz');
    var whoSummary = $('who-summary');
    var whoFlags = $('who-flags');
    var flagBanner = $('flag-banner');
    var saveBtn = $('save-btn');
    var resetBtn = $('reset-btn');
    var overridePanel = $('override-panel');
    var overrideHint = $('override-hint');
    var overrideReason = $('override-reason');
    var overrideSaveBtn = $('override-save-btn');
    var overrideCancelBtn = $('override-cancel-btn');
    var overrideMessage = $('override-message');
    var recheckPanel = $('recheck-panel');
    var recheckReason = $('recheck-reason');
    var recheckSaveBtn = $('recheck-save-btn');
    var recheckCancelBtn = $('recheck-cancel-btn');
    var recheckMessage = $('recheck-message');
    var recheckOpenBtn = $('recheck-open-btn');

    function hideOverridePanel() {
        if (overridePanel) overridePanel.style.display = 'none';
        if (overrideReason) overrideReason.value = '';
        if (overrideMessage) overrideMessage.textContent = '';
    }

    function showOverridePanel(nextDue) {
        if (!overridePanel) return;
        if (overrideHint) {
            overrideHint.textContent = nextDue
                ? 'Next scheduled measurement: ' + nextDue + '. Saving now records an exceptional OVERRIDE measurement instead.'
                : 'Saving now records an exceptional OVERRIDE measurement instead of waiting for the schedule.';
        }
        overridePanel.style.display = '';
        if (overrideReason) overrideReason.focus();
    }

    var previewTimer = null;
    var previewController = null;

    /*
     * ---- Child picker ----
     */
    function renderResults(query) {
        var q = (query || '').trim().toLowerCase();
        var matches = CHILDREN.filter(function (c) {
            if (!q) return true;
            return (c.name + ' ' + c.code + ' ' + c.barangay).toLowerCase().indexOf(q) !== -1;
        }).slice(0, 8);
        if (matches.length === 0) {
            childResults.innerHTML = '<div class="child-picker-item" style="cursor:default;color:var(--admin-muted);">No matching children.</div>';
            childResults.classList.add('is-open');
            return;
        }
        childResults.innerHTML = matches.map(function (c) {
            var initials = (c.first_name.charAt(0) + c.last_name.charAt(0)).toUpperCase();
            var sexLetter = String(c.sex || '').toLowerCase().charAt(0);
            var avatarBg = sexLetter === 'm' ? '#0B6E4F' : (sexLetter === 'f' ? '#52B788' : '#94a3b8');
            return ''
                + '<div class="child-picker-item" data-id="' + c.id + '">'
                + '<span class="avatar" style="background:' + avatarBg + ';">' + escapeHtml(initials) + '</span>'
                + '<div class="meta">'
                + '<div class="name">' + escapeHtml(c.name) + '</div>'
                + '<div class="sub">' + escapeHtml(c.code) + ' · ' + escapeHtml(c.barangay || '—') + '</div>'
                + '</div></div>';
        }).join('');
        childResults.classList.add('is-open');
    }

    childSearch.addEventListener('focus', function () { renderResults(childSearch.value); });
    childSearch.addEventListener('input', function () {
        if (childIdInput.value) {
            childIdInput.value = '';
            selectedChild = null;
            childSummary.classList.remove('is-visible');
        }
        renderResults(childSearch.value);
    });
    childResults.addEventListener('mousedown', function (e) {
        var item = e.target.closest('.child-picker-item');
        if (!item || !item.dataset.id) return;
        selectChild(parseInt(item.dataset.id, 10));
    });
    document.addEventListener('click', function (e) {
        if (!childResults.contains(e.target) && e.target !== childSearch) {
            childResults.classList.remove('is-open');
        }
    });

    function selectChild(id) {
        var match = null;
        for (var i = 0; i < CHILDREN.length; i++) {
            if (CHILDREN[i].id === id) { match = CHILDREN[i]; break; }
        }
        if (!match) return;
        selectedChild = match;
        childIdInput.value = String(match.id);
        childSearch.value = match.name + ' (' + match.code + ')';
        childResults.classList.remove('is-open');

        $('cs-name').textContent = match.name;
        $('cs-code').textContent = match.code;
        $('cs-sex').textContent = match.sex;
        $('cs-birthdate').textContent = match.birthdate;
        $('cs-barangay').textContent = match.barangay || '—';

        updateChildAgeDisplay();

        var pills = [];
        if (match.sex) pills.push('<span class="admin-pill is-muted">' + escapeHtml(match.sex) + '</span>');
        if (match.barangay) pills.push('<span class="admin-pill is-info">' + escapeHtml(match.barangay) + '</span>');
        $('cs-pills').innerHTML = pills.join('');

        childSummary.classList.add('is-visible');
        recomputeWho();
    }

    /*
     * ---- Live WHO preview (canonical server values) ----
     * Every keystroke debounces into who_preview.php, which runs the same
     * calculate_who_metrics() the save endpoint uses — so the numbers shown
     * while typing always match what save will record.
     */
    function computeAgeMonths(birthdate, onDate) {
        if (!birthdate) return null;
        var b = new Date(birthdate + 'T00:00:00');
        var d = onDate ? new Date(onDate + 'T00:00:00') : new Date();
        if (isNaN(b.getTime()) || isNaN(d.getTime())) return null;
        var months = (d.getFullYear() - b.getFullYear()) * 12 + (d.getMonth() - b.getMonth());
        if (d.getDate() < b.getDate()) months -= 1;
        return months < 0 ? 0 : months;
    }

    function computeAgeDays(birthdate, onDate) {
        if (!birthdate) return null;
        var b = new Date(birthdate + 'T00:00:00');
        var d = onDate ? new Date(onDate + 'T00:00:00') : new Date();
        if (isNaN(b.getTime()) || isNaN(d.getTime())) return null;
        var diff = Math.floor((d - b) / (1000 * 60 * 60 * 24));
        return diff < 0 ? 0 : diff;
    }

    function formatDisplayDate(iso) {
        if (!iso) return '—';
        var d = new Date(String(iso) + 'T00:00:00');
        if (isNaN(d.getTime())) return '—';
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
    }

    /*
     * Single source of truth for the Step 1 card's dynamic rows.
     * Recomputes Age at measurement / Age in days / Measurement date
     * from the selected child's birthdate + current date input, so
     * changing the date in Step 2 updates Step 1 instantly.
     * Invalid dates (empty / unparsable / before birthdate) show '—';
     * the server (who_preview.php) remains the authority for errors.
     */
    function updateChildAgeDisplay() {
        if (!selectedChild) return;
        var ageMonths = computeAgeMonths(selectedChild.birthdate, dateInput.value);
        var ageDays = computeAgeDays(selectedChild.birthdate, dateInput.value);
        var birth = selectedChild.birthdate ? new Date(selectedChild.birthdate + 'T00:00:00') : null;
        var current = dateInput.value ? new Date(dateInput.value + 'T00:00:00') : null;
        var valid = ageMonths !== null && ageDays !== null && birth && !isNaN(birth.getTime())
            && current && !isNaN(current.getTime()) && current >= birth;
        $('cs-age').textContent = valid ? (ageMonths + ' months') : '—';
        $('cs-age-days').textContent = valid ? (ageDays + ' days') : '—';
        var dateEl = $('cs-measurement-date');
        if (dateEl) dateEl.textContent = valid ? formatDisplayDate(dateInput.value) : '—';
    }

    function formatZ(v) {
        if (v === null || v === undefined || isNaN(v)) return '—';
        return (v > 0 ? '+' : '') + v.toFixed(2);
    }

    function wfhDisplayShort(code) {
        var x = String(code || '').toLowerCase().trim();
        if (x === 'sw' || x === 'sw/sam' || x === 'sw(sam)' || x === 'sam') return 'SW/SAM';
        if (x === 'mw' || x === 'mw/mam' || x === 'mw(mam)' || x === 'mam') return 'MW/MAM';
        return String(code || '');
    }

    function statusPillClass(status) {
        if (status === 'Normal') return 'is-success';
        if (status.indexOf('Refer') !== -1) return 'is-info';
        if (status.indexOf('Tall') !== -1 || status.indexOf('OW') !== -1 || status.indexOf('Ob') !== -1) return 'is-orange';
        if (status.indexOf('MUW') !== -1 || status.indexOf('MSt') !== -1 || status.indexOf('MW') !== -1 || status.indexOf('MAM') !== -1) return 'is-warn';
        if (status.indexOf('SAM') !== -1) return 'is-danger';
        if (!status || status === '—') return 'is-muted';
        return 'is-danger';
    }

    function recomputeWho() {
        if (!selectedChild) {
            whoResult.style.display = 'none';
            saveBtn.disabled = true;
            return;
        }
        var w = parseFloat(weightInput.value);
        var h = parseFloat(heightInput.value);
        if (isNaN(w) || isNaN(h) || w <= 0 || h <= 0) {
            whoResult.style.display = 'none';
            saveBtn.disabled = true;
            return;
        }

        var ageMonths = computeAgeMonths(selectedChild.birthdate, dateInput.value);
        if (ageMonths === null || ageMonths < 0) {
            whoResult.style.display = 'none';
            saveBtn.disabled = true;
            return;
        }

        // Show the panel immediately with a loading state, then fill in
        // the canonical values when the preview responds.
        whoResult.style.display = 'block';
        whoAge.textContent = ageMonths + ' months · ' + selectedChild.sex;
        whoWaz.textContent = '…';
        whoHaz.textContent = '…';
        whoWhz.textContent = '…';
        whoSummary.innerHTML = '<span>Calculating…</span>';
        saveBtn.disabled = true;

        if (previewTimer) clearTimeout(previewTimer);
        if (previewController) previewController.abort();

        previewTimer = setTimeout(function() {
            previewController = new AbortController();

            var url = PREVIEW_URL
                + '?child_id=' + selectedChild.id
                + '&weight_kg=' + encodeURIComponent(w)
                + '&height_cm=' + encodeURIComponent(h)
                + '&measurement_date=' + encodeURIComponent(dateInput.value || '');

            fetch(url, { credentials: 'same-origin', signal: previewController.signal })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    if (!json.success) throw new Error(json.message || 'Could not preview the assessment.');
                    renderSavedResult(json.data);
                    saveBtn.disabled = false;
                })
                .catch(function (err) {
                    if (err && err.name === 'AbortError') return;
                    whoWaz.textContent = '—';
                    whoHaz.textContent = '—';
                    whoWhz.textContent = '—';
                    whoSummary.innerHTML = '<span>' + escapeHtml(err.message || 'Could not preview the assessment.') + '</span>';
                    whoFlags.innerHTML = '';
                    flagBanner.classList.remove('is-visible');
                    saveBtn.disabled = true;
                });
        }, 300);
    }

    weightInput.addEventListener('input', recomputeWho);
    heightInput.addEventListener('input', recomputeWho);
    function handleDateChange() {
        updateChildAgeDisplay();
        recomputeWho();
    }
    dateInput.addEventListener('change', handleDateChange);
    dateInput.addEventListener('input', handleDateChange);

    /*
     * If a child is preselected via ?child=ID, populate the summary.
     */
    if (childIdInput.value) {
        var preselectId = parseInt(childIdInput.value, 10);
        if (preselectId) selectChild(preselectId);
    }

    /*
     * ---- Save ----
     */
    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    function renderSavedResult(data) {
        // Refresh with the server's authoritative WHO values.
        var waz = parseFloat(data.waz);
        var haz = parseFloat(data.haz);
        var whz = parseFloat(data.whz);
        whoWaz.textContent = formatZ(waz);
        whoHaz.textContent = formatZ(haz);
        whoWhz.textContent = formatZ(whz);
        whoWaz.className = 'value ' + (Math.abs(waz) > 2 ? 'is-warn' : 'is-ok');
        whoHaz.className = 'value ' + (Math.abs(haz) > 2 ? 'is-warn' : 'is-ok');
        whoWhz.className = 'value ' + (Math.abs(whz) > 2 ? 'is-warn' : 'is-ok');

        // Derive combined display status from the three DOH axes (abbreviations).
        // Exclude Normal; show all abnormal axes together. WFH uses the
        // SAM/MAM-annotated display labels (stored values stay SW/MW).
        var wfa = String(data.wfa_status || 'Normal');
        var hfa = String(data.hfa_status || 'Normal');
        var wfh = wfhDisplayShort(data.wfh_status || 'Normal');
        var abnParts = [];
        if (wfa !== 'Normal') abnParts.push(wfa);
        if (hfa !== 'Normal' && hfa !== 'Tall') abnParts.push(hfa);
        if (hfa === 'Tall') abnParts.push('Tall');
        if (wfh !== 'Normal') abnParts.push(wfh);
        var status = abnParts.length > 0 ? abnParts.join(' + ') : 'Normal';

        whoSummary.innerHTML = ''
            + '<span><strong>Status:</strong> <span class="admin-pill ' + statusPillClass(status) + '">' + escapeHtml(status) + '</span></span>'
            + '<span>WFA: ' + escapeHtml(wfa) + ' · HFA: ' + escapeHtml(hfa) + ' · WFH: ' + escapeHtml(wfh) + '</span>';

        whoFlags.innerHTML = '';
        if (data.is_flagged) {
            flagBanner.textContent = '⚠ Flagged for review: ' + (data.flag_reason || 'implausible values');
            flagBanner.classList.add('is-visible');
        } else {
            flagBanner.classList.remove('is-visible');
        }
    }

    function toastError(message) {
        if (window.AdminToast) AdminToast.error(message);
    }

    saveBtn.addEventListener('click', function () {
        if (!selectedChild) { toastError('Please select a child first.'); return; }
        var w = parseFloat(weightInput.value);
        var h = parseFloat(heightInput.value);
        if (isNaN(w) || isNaN(h) || w <= 0 || h <= 0) {
            toastError('Please enter a valid weight and height.');
            return;
        }
        saveBtn.disabled = true;
        var originalLabel = saveBtn.innerHTML;
        saveBtn.innerHTML = 'Saving…';

        var form = $('new-measurement-form');
        fetch(form.getAttribute('data-endpoint'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
                child_id: selectedChild.id,
                measurement_date: dateInput.value,
                weight_kg: w,
                height_cm: h
            })
        })
        .then(function (r) { return r.json().catch(function () { throw new Error('Unexpected server response.'); }); })
        .then(function (json) {
            if (!json.success) {
                var saveErr = new Error(json.message || 'Could not save the measurement.');
                saveErr.notDue = json.not_due === true;
                saveErr.nextDue = json.next_due || null;
                throw saveErr;
            }
            // The panel already shows these exact values from the live
            // preview; re-render with the authoritative save response.
            renderSavedResult(json.data);
            if (window.AdminToast) AdminToast.success('Measurement saved for ' + json.data.child_name + ' (' + json.data.child_code + ').');
            saveBtn.innerHTML = 'Saved!';
            setTimeout(function () {
                saveBtn.disabled = false;
                saveBtn.innerHTML = originalLabel;
            }, 2000);
            return;
        })
        .catch(function (err) {
            toastError(err.message || 'Could not save the measurement.');
            if (err && err.notDue) showOverridePanel(err.nextDue);
        })
        .finally(function () {
            if (saveBtn.innerHTML !== 'Saved!') {
                saveBtn.disabled = false;
                saveBtn.innerHTML = originalLabel;
            }
        });
    });

    function hideRecheckPanel() {
        if (recheckPanel) recheckPanel.style.display = 'none';
        if (recheckReason) recheckReason.value = '';
        if (recheckMessage) recheckMessage.textContent = '';
    }

    function showRecheckPanel() {
        if (!recheckPanel) return;
        hideOverridePanel();
        recheckPanel.style.display = '';
        if (recheckReason) recheckReason.focus();
    }

    overrideCancelBtn.addEventListener('click', hideOverridePanel);
    if (recheckCancelBtn) recheckCancelBtn.addEventListener('click', hideRecheckPanel);
    if (recheckOpenBtn) recheckOpenBtn.addEventListener('click', function () {
        if (!selectedChild) { toastError('Please select a child first.'); return; }
        var w = parseFloat(weightInput.value);
        var h = parseFloat(heightInput.value);
        if (isNaN(w) || isNaN(h) || w <= 0 || h <= 0) {
            toastError('Enter the new weight and height first, then Measure again.');
            return;
        }
        showRecheckPanel();
    });

    overrideSaveBtn.addEventListener('click', function () {
        if (!selectedChild) { toastError('Please select a child first.'); return; }
        var w = parseFloat(weightInput.value);
        var h = parseFloat(heightInput.value);
        if (isNaN(w) || isNaN(h) || w <= 0 || h <= 0) {
            overrideMessage.textContent = 'Enter a valid weight and height first.';
            return;
        }
        var reason = (overrideReason.value || '').trim();
        if (reason === '') {
            overrideMessage.textContent = 'A reason for the override is required.';
            overrideReason.focus();
            return;
        }
        overrideSaveBtn.disabled = true;
        overrideMessage.textContent = '';
        var saveOriginalLabel = saveBtn.innerHTML;
        overrideSaveBtn.textContent = 'Saving override…';

        fetch(OVERRIDE_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
                child_id: selectedChild.id,
                measurement_date: dateInput.value,
                weight_kg: w,
                height_cm: h,
                override_reason: reason
            })
        })
        .then(function (r) { return r.json().catch(function () { throw new Error('Unexpected server response.'); }); })
        .then(function (json) {
            if (!json.success) throw new Error(json.message || 'Could not save the override measurement.');
            renderSavedResult(json.data);
            if (window.AdminToast) AdminToast.success('Override measurement saved for ' + json.data.child_name + ' (' + json.data.child_code + ').');
            hideOverridePanel();
            saveBtn.innerHTML = 'Saved!';
            setTimeout(function () {
                saveBtn.disabled = false;
                saveBtn.innerHTML = saveOriginalLabel;
            }, 2000);
            return;
        })
        .catch(function (err) {
            overrideMessage.textContent = err.message || 'Could not save the override measurement.';
        })
        .finally(function () {
            overrideSaveBtn.disabled = false;
            overrideSaveBtn.textContent = 'Save as override';
        });
    });

    if (recheckSaveBtn) recheckSaveBtn.addEventListener('click', function () {
        if (!selectedChild) { toastError('Please select a child first.'); return; }
        var w = parseFloat(weightInput.value);
        var h = parseFloat(heightInput.value);
        if (isNaN(w) || isNaN(h) || w <= 0 || h <= 0) {
            recheckMessage.textContent = 'Enter a valid weight and height first.';
            return;
        }
        var reason = (recheckReason.value || '').trim();
        if (reason === '') {
            recheckMessage.textContent = 'A reason for the recheck is required.';
            recheckReason.focus();
            return;
        }
        recheckSaveBtn.disabled = true;
        recheckMessage.textContent = '';
        var saveOriginalLabel = saveBtn.innerHTML;
        recheckSaveBtn.textContent = 'Saving recheck…';

        fetch(RECHECK_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
                child_id: selectedChild.id,
                measurement_date: dateInput.value,
                weight_kg: w,
                height_cm: h,
                recheck_reason: reason
            })
        })
        .then(function (r) { return r.json().catch(function () { throw new Error('Unexpected server response.'); }); })
        .then(function (json) {
            if (!json.success) throw new Error(json.message || 'Could not save the recheck measurement.');
            renderSavedResult(json.data);
            if (window.AdminToast) AdminToast.success('Recheck saved for ' + json.data.child_name + ' (' + json.data.child_code + '). Due schedule unchanged.');
            hideRecheckPanel();
            saveBtn.innerHTML = 'Saved!';
            setTimeout(function () {
                saveBtn.disabled = false;
                saveBtn.innerHTML = saveOriginalLabel;
            }, 2000);
            return;
        })
        .catch(function (err) {
            recheckMessage.textContent = err.message || 'Could not save the recheck measurement.';
        })
        .finally(function () {
            recheckSaveBtn.disabled = false;
            recheckSaveBtn.textContent = 'Save as recheck';
        });
    });

    resetBtn.addEventListener('click', function () {
        weightInput.value = '';
        heightInput.value = '';
        dateInput.value = new Date().toISOString().slice(0, 10);
        updateChildAgeDisplay();
        whoResult.style.display = 'none';
        saveBtn.disabled = true;
        flagBanner.classList.remove('is-visible');
        hideOverridePanel();
        hideRecheckPanel();
    });

})();
</script>

<?php endif; ?>

<?php
nutritionist_layout_end();

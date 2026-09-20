<?php

require_once __DIR__ . '/../includes/admin_helpers.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/firebase_sync.php';

start_secure_session();
require_permission('dashboard.view');

/*
|--------------------------------------------------------------------------
| Summary Statistics
|--------------------------------------------------------------------------
*/

// Total staff users (admins + nutritionists)
$totalUsers = admin_scalar('SELECT COUNT(*) FROM users');

$adminCount = admin_scalar(
    "SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE r.name = 'admin'"
);

$nutritionistCount = admin_scalar(
    "SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE r.name = 'nutritionist'"
);

// Kiosk devices
$devicesTotal = admin_scalar('SELECT COUNT(*) FROM devices');

$devices = admin_fetch_all(
    'SELECT d.id, d.device_code, d.location, d.status, d.barangay_id, bg.name AS barangay,
            d.last_seen_at, d.last_calibration_at, d.hx711_calibration_factor, d.mounting_height_cm,
            d.calibration_offset_height, d.calibration_offset_weight, d.updated_at,
            TIMESTAMPDIFF(SECOND, d.last_seen_at, NOW()) AS seconds_since_last_seen
     FROM devices d
     LEFT JOIN barangays bg ON bg.id = d.barangay_id
     ORDER BY d.device_code ASC'
);

$devicesOnlineCount = 0;
foreach ($devices as &$device) {
    $device = api_sync_stale_device_status($device);
    if (api_device_is_online($device)) {
        $devicesOnlineCount++;
    }
}
unset($device);

// Security events: danger + warning level audit logs
$securityEvents = admin_scalar(
    "SELECT COUNT(*) FROM audit_logs WHERE level IN ('danger', 'warning')"
);

$dangerEvents = admin_scalar(
    "SELECT COUNT(*) FROM audit_logs WHERE level = 'danger'"
);

$warningEvents = admin_scalar(
    "SELECT COUNT(*) FROM audit_logs WHERE level = 'warning'"
);

// Failed login attempts
$failedLogins = admin_scalar(
    "SELECT COUNT(*) FROM login_attempts WHERE success = 0"
);

// Sensor status computation
$sensorHealthy = 0;
$sensorNeedsRepair = 0;
$sensorOffline = 0;

foreach ($devices as $d) {
    $isOnline = api_device_is_online($d);
    $status = (string)($d['status'] ?? 'offline');

    if ($status === 'maintenance') {
        $sensorNeedsRepair++;
    } elseif (!$isOnline) {
        $sensorOffline++;
    } else {
        $sensorHealthy++;
    }
}

/*
|--------------------------------------------------------------------------
| Users Across the City (per-barangay)
|--------------------------------------------------------------------------
*/

$barangayUserStats = admin_fetch_all(
    "SELECT
        b.id,
        b.name,
        COUNT(DISTINCT p.id) AS parent_count,
        COUNT(DISTINCT c.id) AS child_count,
        (COUNT(DISTINCT p.id) + COUNT(DISTINCT c.id)) AS total_users
     FROM barangays b
     LEFT JOIN parents p ON p.barangay_id = b.id AND p.status = 'active'
     LEFT JOIN children c ON c.barangay_id = b.id
     WHERE b.status = 'active'
     GROUP BY b.id, b.name
     ORDER BY total_users DESC, b.name ASC"
);

$totalParents = 0;
$totalChildren = 0;
$barangayMaxUsers = 0;

foreach ($barangayUserStats as &$bs) {
    $totalParents += (int)$bs['parent_count'];
    $totalChildren += (int)$bs['child_count'];
    if ((int)$bs['total_users'] > $barangayMaxUsers) {
        $barangayMaxUsers = (int)$bs['total_users'];
    }
}
unset($bs);

// Normalize barangay names for GeoJSON matching
function dashboard_normalize_name(string $name): string
{
    $name = preg_replace('/\s*\((?:pob\.?|poblacion)\)\s*/i', '', $name) ?? $name;
    $name = preg_replace('/\s+/', ' ', trim($name)) ?? trim($name);
    return mb_strtolower($name);
}

$byNormalizedName = [];
foreach ($barangayUserStats as $row) {
    $byNormalizedName[dashboard_normalize_name((string)$row['name'])] = [
        'name' => $row['name'],
        'parents' => (int)$row['parent_count'],
        'children' => (int)$row['child_count'],
        'total' => (int)$row['total_users'],
    ];
}

/*
|--------------------------------------------------------------------------
| Fleet + family rollups (for link cards)
|--------------------------------------------------------------------------
*/

$familyTotal = (int)$totalParents + (int)$totalChildren;

$fleetOfflineCount = (int)$devicesTotal - (int)$devicesOnlineCount;
$fleetLabel = 'All Operational';
$fleetLabelClass = 'admin-card-value--success';
if ((int)$devicesTotal === 0) {
    $fleetLabel = 'No Devices';
    $fleetLabelClass = '';
} elseif ($sensorNeedsRepair > 0 || $sensorOffline > 0) {
    $fleetLabel = ((int)$devicesOnlineCount === 0) ? 'All Offline' : 'Degraded';
    $fleetLabelClass = $sensorNeedsRepair > 0 ? 'admin-card-value--warn' : '';
}

$worstOfflineCode = '';
$worstOfflineSecs = -1;
foreach ($devices as $d) {
    if (api_device_is_online($d)) {
        continue;
    }
    $secs = isset($d['seconds_since_last_seen']) && $d['seconds_since_last_seen'] !== null
        ? (int)$d['seconds_since_last_seen'] : -1;
    if ($secs > $worstOfflineSecs) {
        $worstOfflineSecs = $secs;
        $worstOfflineCode = (string)($d['device_code'] ?? '');
    }
}

$actions = '';

admin_layout_start('Admin Dashboard', 'City families, kiosk fleet, and activity at a glance.', 'dashboard', $actions);
?>

<?php
/* ─── TOP SUMMARY CARDS ───────────────────────────────────────────── */
?>
<section class="admin-grid-cards admin-grid-cards--compact sk-stagger">
    <article class="admin-card admin-card--dashboard">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Families &amp; Children</div>
                <div class="admin-card-value" data-count-up><?php echo (int)$familyTotal; ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-up"><?php echo (int)$totalParents; ?> parents</span>
                    <span class="admin-card-sep">&middot;</span>
                    <span class="admin-card-trend is-up"><?php echo (int)$totalChildren; ?> children</span>
                </div>
            </div>
        </div>
    </article>

    <article class="admin-card admin-card--dashboard">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.429 9.75 2.25 12l4.179 2.25m0-4.5 5.571 3 5.571-3m-11.142 0L2.25 7.5 12 2.25l9.75 5.25-4.179 2.25m0 0L12 12.75 6.429 9.75m11.142 0 4.179 2.25-9.75 5.25-9.75-5.25 4.179-2.25"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Kiosk Fleet &middot; <?php echo (int)$devicesOnlineCount; ?>/<?php echo (int)$devicesTotal; ?> online</div>
                <div class="admin-card-value admin-card-value--text <?php echo $fleetLabelClass; ?>"><?php echo admin_e($fleetLabel); ?></div>
                <div class="admin-card-meta">
                    <?php if ((int)$devicesTotal === 0): ?>
                        <span class="admin-card-trend">No devices registered</span>
                    <?php else: ?>
                        <span class="admin-card-trend is-up"><?php echo (int)$devicesOnlineCount; ?> online</span>
                        <?php if ($sensorNeedsRepair > 0): ?>
                            <span class="admin-card-sep">&middot;</span>
                            <span class="admin-card-trend is-danger"><?php echo (int)$sensorNeedsRepair; ?> maintenance</span>
                        <?php endif; ?>
                        <?php if ($sensorOffline > 0): ?>
                            <span class="admin-card-sep">&middot;</span>
                            <span class="admin-card-trend is-danger"><?php echo (int)$sensorOffline; ?> offline<?php echo $worstOfflineCode !== '' ? ' (' . admin_e($worstOfflineCode) . ')' : ''; ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </article>

    <article class="admin-card admin-card--dashboard">
        <div class="admin-card-row">
            <div class="admin-card-icon is-danger">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Security &amp; Activity</div>
                <div class="admin-card-value" data-count-up><?php echo (int)$securityEvents; ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-danger"><?php echo (int)$dangerEvents; ?> critical &middot; <?php echo (int)$warningEvents; ?> warnings</span>
                </div>
            </div>
        </div>
    </article>

    <article class="admin-card admin-card--dashboard">
        <div class="admin-card-row">
            <div class="admin-card-icon is-success">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Staff Users</div>
                <div class="admin-card-value" data-count-up><?php echo (int)$totalUsers; ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-up"><?php echo (int)$adminCount; ?> admins</span>
                    <span class="admin-card-sep">&middot;</span>
                    <span class="admin-card-trend is-up"><?php echo (int)$nutritionistCount; ?> nutritionists</span>
                </div>
            </div>
        </div>
    </article>
</section>

<?php
/* ─── FAMILIES MAP + AI INSIGHTS + KIOSK ─────────────────────────── */
?>
<section class="admin-dashboard-maprow admin-dashboard-maprow--compact">
    <article class="admin-section admin-dashboard-mapsection">
        <div class="admin-section-head">
            <div>
                <h2 class="admin-section-title">Families Across the Entire City</h2>
                <p class="admin-section-subtitle">Registered families per barangay — City of San Fernando, Pampanga.</p>
            </div>
        </div>

        <div class="admin-riskmap-layout admin-riskmap-layout--compact">
            <div class="admin-riskmap-mapwrap">
                <div id="user-map" class="admin-riskmap-canvas-v2 admin-riskmap-canvas--compact"></div>
            </div>

            <aside class="admin-riskmap-sidebar">
                <div class="admin-riskmap-card">
                    <h3 class="admin-riskmap-card-title">Family Density</h3>
                    <ul id="user-map-legend" class="admin-riskmap-legend-v2">
                        <li data-level="high">
                            <span class="admin-riskmap-swatch" style="background:#0b6e4f"></span>
                            <span class="admin-riskmap-legend-text">High density</span>
                            <span class="admin-riskmap-legend-count" id="legend-high">0</span>
                        </li>
                        <li data-level="medium">
                            <span class="admin-riskmap-swatch" style="background:#2ec57a"></span>
                            <span class="admin-riskmap-legend-text">Medium density</span>
                            <span class="admin-riskmap-legend-count" id="legend-medium">0</span>
                        </li>
                        <li data-level="low">
                            <span class="admin-riskmap-swatch" style="background:#a8e6c3"></span>
                            <span class="admin-riskmap-legend-text">Low density</span>
                            <span class="admin-riskmap-legend-count" id="legend-low">0</span>
                        </li>
                        <li data-level="none">
                            <span class="admin-riskmap-swatch" style="background:#c7ccd1"></span>
                            <span class="admin-riskmap-legend-text">No families yet</span>
                            <span class="admin-riskmap-legend-count" id="legend-none">0</span>
                        </li>
                    </ul>
                </div>

                <div class="admin-riskmap-card">
                    <h3 class="admin-riskmap-card-title">Top Barangays</h3>
                    <div id="top-barangays-list" class="admin-dashboard-toplist"></div>
                </div>
            </aside>
        </div>

        <div id="user-map-status" class="admin-mini" style="margin-top:8px;"></div>
    </article>

    <div class="admin-dashboard-rightcol">
        <article class="admin-section admin-dashboard-insights">
            <div class="admin-section-head">
                <div>
                    <h2 class="admin-section-title">Quick AI Insights — City Families</h2>
                    <p class="admin-section-subtitle">Coverage gaps and nutrition signals.</p>
                </div>
                <div class="admin-dashboard-insights-actions">
                    <button id="family-insights-refresh" class="admin-icon-btn" type="button" title="Refresh insights"><?php echo admin_action_icon('sync'); ?></button>
                </div>
            </div>
            <ul id="family-insights-list" class="admin-dashboard-insights-list">
                <li class="admin-dashboard-insights-item is-loading">Loading city insights…</li>
            </ul>
            <div id="family-insights-meta" class="admin-mini"></div>
        </article>

        <article class="admin-section admin-dashboard-kiosksection admin-dashboard-kiosksection--compact">
            <div class="admin-section-head">
                <div>
                    <h2 class="admin-section-title">Live Kiosk &amp; Sensor Status</h2>
                    <p class="admin-section-subtitle">Deployed devices at a glance.</p>
                </div>
                <a class="admin-btn-secondary admin-btn-secondary--sm" href="<?php echo admin_e(app_url('/admin/sensors.php')); ?>">Manage</a>
            </div>
            <div id="kiosk-tiles-wrap" class="admin-dashboard-kiosk-grid"></div>
        </article>
    </div>
</section>

<?php
/* ─── HORIZONTAL QUICK NAV ─────────────────────────────────────────── */
$quickNavs = [
    ['href' => app_url('/admin/children.php'), 'icon' => 'children', 'label' => 'Children', 'sub' => 'Records & growth'],
    ['href' => app_url('/admin/sensors.php'), 'icon' => 'sensors', 'label' => 'Sensors', 'sub' => 'Kiosk fleet'],
    ['href' => app_url('/admin/audit_logs.php'), 'icon' => 'audit_logs', 'label' => 'Audit Logs', 'sub' => 'Activity & security'],
    ['href' => app_url('/admin/users.php'), 'icon' => 'users', 'label' => 'Users', 'sub' => 'Staff accounts'],
];
?>
<nav class="admin-quicknav" aria-label="Quick navigation">
    <?php foreach ($quickNavs as $qn): ?>
        <a class="admin-quicknav-item" href="<?php echo admin_e($qn['href']); ?>">
            <span class="admin-quicknav-icon"><?php echo admin_sidebar_icon($qn['icon']); ?></span>
            <span class="admin-quicknav-text">
                <strong><?php echo admin_e($qn['label']); ?></strong>
                <small><?php echo admin_e($qn['sub']); ?></small>
            </span>
            <span class="admin-quicknav-arrow" aria-hidden="true">&#8250;</span>
        </a>
    <?php endforeach; ?>
</nav>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function () {
    "use strict";

    var STATS_BY_NAME = <?php echo json_encode($byNormalizedName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    var GEOJSON_URL = <?php echo json_encode(app_url('/assets/data/sanfernando_barangays.geojson')); ?>;
    var MAX_USERS = <?php echo (int)$barangayMaxUsers; ?>;
    var KIOSK_DEVICES = <?php echo json_encode(array_map(function ($d) {
        $online = api_device_is_online($d);
        $status = (string)($d['status'] ?? 'offline');
        return [
            'code' => $d['device_code'],
            'barangay' => $d['barangay'] ?? 'Unassigned',
            'online' => $online,
            'status' => $status,
            'color' => $online ? '#0b6e4f' : ($status === 'maintenance' ? '#d97706' : '#c93b3b'),
            'label' => $online ? 'Online' : ($status === 'maintenance' ? 'Maintenance' : 'Offline'),
            'pill' => $online ? 'is-success' : ($status === 'maintenance' ? 'is-warn' : 'is-danger'),
        ];
    }, $devices), JSON_UNESCAPED_SLASHES); ?>;

    var DIST_COLORS = {
        high: '#0b6e4f',
        medium: '#2ec57a',
        low: '#a8e6c3',
        none: '#c7ccd1'
    };

    function getLevel(total) {
        if (total === 0) return 'none';
        if (total >= 20) return 'high';
        if (total >= 10) return 'medium';
        return 'low';
    }

    function normalizeName(name) {
        return String(name || '')
            .replace(/\s*\((?:pob\.?|poblacion)\)\s*/i, '')
            .trim()
            .replace(/\s+/g, ' ')
            .toLowerCase();
    }

    /* ─── Top Barangays List (compact: top 3 for above-the-fold) ─────── */
    function renderTopBarangays() {
        var container = document.getElementById('top-barangays-list');
        if (!container) return;

        var sorted = Object.keys(STATS_BY_NAME).map(function (k) { return STATS_BY_NAME[k]; })
            .filter(function (s) { return s.total > 0; })
            .sort(function (a, b) { return b.total - a.total; })
            .slice(0, 3);

        if (sorted.length === 0) {
            container.innerHTML = '<div class="admin-empty"><p>No families registered yet.</p></div>';
            return;
        }

        var html = '';
        sorted.forEach(function (s, i) {
            var barWidth = MAX_USERS > 0 ? Math.round((s.total / MAX_USERS) * 100) : 0;
            html += '<div class="admin-dashboard-toplist-item">';
            html += '<div class="admin-dashboard-toplist-header">';
            html += '<span class="admin-dashboard-toplist-rank">' + (i + 1) + '</span>';
            html += '<span class="admin-dashboard-toplist-name">' + s.name + '</span>';
            html += '<span class="admin-dashboard-toplist-count">' + s.total + '</span>';
            html += '</div>';
            html += '<div class="admin-dashboard-toplist-bar"><div class="admin-dashboard-toplist-fill" style="width:' + barWidth + '%"></div></div>';
            html += '<div class="admin-dashboard-toplist-meta">' + s.parents + ' parents &middot; ' + s.children + ' children</div>';
            html += '</div>';
        });
        container.innerHTML = html;
    }

    /* ─── Leaflet Map ──────────────────────────────────────────────── */
    function initMap() {
        var mapEl = document.getElementById('user-map');
        if (!mapEl || typeof L === 'undefined') return;

        var map = L.map(mapEl, { scrollWheelZoom: true, zoomControl: false }).setView([15.034, 120.686], 12);
        L.control.zoom({ position: 'bottomright' }).addTo(map);

        var baseLayers = {
            street: L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors'
            }),
            satellite: L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
                maxZoom: 19,
                attribution: 'Tiles &copy; Esri, Maxar, Earthstar Geographics'
            }),
            terrain: L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {
                maxZoom: 17,
                attribution: '&copy; OpenStreetMap contributors, SRTM &mdash; &copy; OpenTopoMap (CC-BY-SA)'
            })
        };

        var activeBase = baseLayers.street.addTo(map);

        // Basemap gallery
        var BasemapGallery = L.Control.extend({
            options: { position: 'topright' },
            onAdd: function (map) {
                var container = L.DomUtil.create('div', 'admin-riskmap-basemaps');
                var options = [
                    { key: 'street', label: 'Street' },
                    { key: 'satellite', label: 'Satellite' },
                    { key: 'terrain', label: 'Terrain' }
                ];
                options.forEach(function (opt) {
                    var btn = L.DomUtil.create('button', 'admin-riskmap-basemap-btn', container);
                    btn.type = 'button';
                    btn.textContent = opt.label;
                    btn.dataset.basemap = opt.key;
                    if (opt.key === 'street') btn.classList.add('is-active');
                    L.DomEvent.on(btn, 'click', function (e) {
                        L.DomEvent.stopPropagation(e);
                        this._select(opt.key, container);
                    }, this);
                }, this);
                L.DomEvent.disableClickPropagation(container);
                L.DomEvent.disableScrollPropagation(container);
                this._container = container;
                return container;
            },
            _select: function (key, container) {
                if (this._onSelect) this._onSelect(key);
                container.querySelectorAll('.admin-riskmap-basemap-btn').forEach(function (b) {
                    b.classList.toggle('is-active', b.dataset.basemap === key);
                });
            },
            onSelect: function (fn) { this._onSelect = fn; return this; }
        });

        // Search
        var BarangaySearch = L.Control.extend({
            options: { position: 'topleft' },
            onAdd: function () {
                var container = L.DomUtil.create('div', 'admin-riskmap-search');
                var input = L.DomUtil.create('input', 'admin-riskmap-search-input', container);
                input.type = 'text';
                input.placeholder = 'Find a barangay\u2026';
                var dropdown = L.DomUtil.create('div', 'admin-riskmap-search-dropdown', container);
                dropdown.style.display = 'none';
                var names = Object.keys(STATS_BY_NAME).map(function (k) { return STATS_BY_NAME[k].name; });

                function showDropdown(query) {
                    dropdown.innerHTML = '';
                    if (!query) { dropdown.style.display = 'none'; return; }
                    var lower = query.toLowerCase();
                    var matches = names.filter(function (n) { return n.toLowerCase().indexOf(lower) !== -1; }).slice(0, 8);
                    if (matches.length === 0) { dropdown.style.display = 'none'; return; }
                    matches.forEach(function (name) {
                        var item = document.createElement('div');
                        item.className = 'admin-riskmap-search-item';
                        item.textContent = name;
                        item.addEventListener('mousedown', function (e) {
                            e.preventDefault();
                            input.value = name;
                            dropdown.style.display = 'none';
                            if (this._onSearch) this._onSearch(name);
                        }.bind(this));
                        dropdown.appendChild(item);
                    }.bind(this));
                    dropdown.style.display = 'block';
                }

                L.DomEvent.on(input, 'input', function () { showDropdown.call(this, input.value); }, this);
                L.DomEvent.on(input, 'keydown', function (e) {
                    if (e.key === 'Enter') { dropdown.style.display = 'none'; if (this._onSearch) this._onSearch(input.value); }
                    if (e.key === 'Escape') dropdown.style.display = 'none';
                }, this);
                L.DomEvent.on(input, 'blur', function () { setTimeout(function () { dropdown.style.display = 'none'; }, 150); });
                L.DomEvent.disableClickPropagation(container);
                L.DomEvent.disableScrollPropagation(container);
                this._dropdown = dropdown;
                this._input = input;
                return container;
            },
            onSearch: function (fn) { this._onSearch = fn; return this; }
        });

        var geoLayer = null;

        new BarangaySearch().onSearch(function (query) {
            if (!geoLayer || !query) return;
            var target = null;
            geoLayer.eachLayer(function (fl) {
                if (fl.feature.properties.name.toLowerCase() === query.toLowerCase()) target = fl;
            });
            if (target) {
                map.fitBounds(target.getBounds(), { padding: [40, 40], maxZoom: 15 });
                target.openPopup();
            }
        }).addTo(map);

        new BasemapGallery().onSelect(function (key) {
            if (baseLayers[key] === activeBase) return;
            map.removeLayer(activeBase);
            activeBase = baseLayers[key].addTo(map);
        }).addTo(map);

        // Legend counters
        var levelCounts = { high: 0, medium: 0, low: 0, none: 0 };

        fetch(GEOJSON_URL)
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (geojson) {
                var matched = 0;

                geoLayer = L.geoJSON(geojson, {
                    style: function (feature) {
                        var key = normalizeName(feature.properties.name);
                        var stat = STATS_BY_NAME[key];
                        var total = stat ? stat.total : 0;
                        var level = getLevel(total);
                        levelCounts[level]++;
                        return {
                            color: '#ffffff',
                            weight: 1,
                            fillColor: DIST_COLORS[level],
                            fillOpacity: 0.75
                        };
                    },
                    onEachFeature: function (feature, featureLayer) {
                        var key = normalizeName(feature.properties.name);
                        var stat = STATS_BY_NAME[key];
                        if (stat) matched++;

                        var label = stat ? stat.name : feature.properties.name;
                        var body = stat && stat.total > 0
                            ? '<strong>' + label + '</strong><br>' +
                              stat.parents + ' parents &middot; ' + stat.children + ' children'
                            : '<strong>' + label + '</strong><br>No families registered yet.';

                        featureLayer.bindPopup(body);
                        featureLayer.on('mouseover', function () { featureLayer.setStyle({ weight: 2.5, color: '#2f3d3a' }); });
                        featureLayer.on('mouseout', function () { geoLayer.resetStyle(featureLayer); });
                    }
                }).addTo(map);

                // Container stretches to the row height — re-check size now
                // that CSS layout has settled, then fit the boundaries.
                map.invalidateSize();
                map.fitBounds(geoLayer.getBounds(), { padding: [16, 16] });

                // Update legend counts
                document.getElementById('legend-high').textContent = levelCounts.high;
                document.getElementById('legend-medium').textContent = levelCounts.medium;
                document.getElementById('legend-low').textContent = levelCounts.low;
                document.getElementById('legend-none').textContent = levelCounts.none;

                var missing = Object.keys(STATS_BY_NAME).length - matched;
                var statusEl = document.getElementById('user-map-status');
                if (statusEl) {
                    statusEl.textContent = missing > 0
                        ? 'Boundary data isn\'t available for ' + missing + ' barangay(s) in the master list.'
                        : '';
                }
            })
            .catch(function () {
                mapEl.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--admin-muted);font-size:0.9rem;">Could not load map boundaries. Data is available in the table above.</div>';
            });
    }

    /* ─── Live Kiosk Tiles (compact: first 4, no pagination) ─────────── */
    var KIOSK_MAX = 4;

    function renderKioskTiles() {
        var wrap = document.getElementById('kiosk-tiles-wrap');
        if (!wrap) return;

        if (KIOSK_DEVICES.length === 0) {
            wrap.innerHTML = '<div class="admin-empty" style="padding:12px;"><p>No devices registered yet.</p></div>';
            return;
        }

        var slice = KIOSK_DEVICES.slice(0, KIOSK_MAX);

        var html = '';
        slice.forEach(function (d) {
            html += '<div class="admin-dashboard-kiosk-tile">';
            html += '<span class="admin-dashboard-kiosk-dot" style="background:' + d.color + ';"></span>';
            html += '<span class="admin-dashboard-kiosk-code">' + escHtml(d.code) + '</span>';
            html += '<span class="admin-dashboard-kiosk-location">' + escHtml(d.barangay) + '</span>';
            html += '<span class="admin-pill ' + d.pill + '" style="font-size:0.65rem;padding:2px 8px;">' + d.label + '</span>';
            html += '</div>';
        });
        if (KIOSK_DEVICES.length > KIOSK_MAX) {
            html += '<div class="admin-dashboard-kiosk-more">+' + (KIOSK_DEVICES.length - KIOSK_MAX) + ' more in Sensors</div>';
        }
        wrap.innerHTML = html;
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(String(s == null ? '' : s)));
        return d.innerHTML;
    }

    /* ─── Family AI Insights ───────────────────────────────────────── */
    var FAMILY_INSIGHTS_URL = <?php echo json_encode(app_url('/api/admin/family_insights.php')); ?>;

    function renderFamilyInsights(insights, generatedAt) {
        var list = document.getElementById('family-insights-list');
        var meta = document.getElementById('family-insights-meta');
        if (!list) return;
        if (!insights || insights.length === 0) {
            list.innerHTML = '<li class="admin-dashboard-insights-item">No city patterns yet — register families to unlock insights.</li>';
        } else {
            list.innerHTML = insights.map(function (t) {
                return '<li class="admin-dashboard-insights-item">' + escHtml(t) + '</li>';
            }).join('');
        }
        if (meta) {
            meta.textContent = generatedAt ? ('Updated ' + generatedAt) : '';
        }
    }

    function loadFamilyInsights(force) {
        var list = document.getElementById('family-insights-list');
        var btn = document.getElementById('family-insights-refresh');
        if (list && !force) list.innerHTML = '<li class="admin-dashboard-insights-item is-loading">Loading city insights…</li>';
        if (btn) btn.disabled = true;
        var url = FAMILY_INSIGHTS_URL + (force ? '?force=1' : '');
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.success) {
                    renderFamilyInsights(res.insights, res.generated_at);
                } else {
                    renderFamilyInsights([], '');
                    var m = document.getElementById('family-insights-meta');
                    if (m) m.textContent = 'Could not load insights.';
                }
            })
            .catch(function () {
                renderFamilyInsights([], '');
                var m2 = document.getElementById('family-insights-meta');
                if (m2) m2.textContent = 'Could not load insights. Retrying…';
            })
            .finally(function () { if (btn) btn.disabled = false; });
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('#family-insights-refresh')) {
            loadFamilyInsights(true);
        }
    });

    /* ─── Initialize ───────────────────────────────────────────────── */
    document.addEventListener('DOMContentLoaded', function () {
        renderTopBarangays();
        renderKioskTiles();
        initMap();
        loadFamilyInsights(false);
    });
})();
</script>
<?php
admin_layout_end();

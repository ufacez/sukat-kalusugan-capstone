<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

$user = nutritionist_require_access();

/**
 * Per-barangay nutrition snapshot.
 *
 * "At risk" mirrors the same definition already used on
 * nutritionist/who_analysis.php: the child's LATEST measurement is
 * anything other than Normal (i.e. Moderately Underweight, Severely Underweight,
 * Moderately Stunted, Severely Stunted, Moderately Wasted, Severely Wasted,
 * Overweight, or Obese).
 *
 * Non-admin nutritionists are scoped to their assigned barangay, same as
 * every other nutritionist page (see nutritionist_scope_fragment()).
 * Admins see every active barangay.
 */
$params = [];
$scope = nutritionist_scope_fragment($user, 'b.id', $params);

$barangayStats = admin_fetch_all(
    "SELECT
        b.id,
        b.name,
        b.status,
        COUNT(c.id) AS children_count,
        SUM(CASE WHEN lm.measurement_date IS NOT NULL THEN 1 ELSE 0 END) AS measured_count,
        SUM(CASE WHEN lm.nutritional_status IS NOT NULL AND lm.nutritional_status NOT IN ('Normal') THEN 1 ELSE 0 END) AS at_risk_count
     FROM barangays b
     LEFT JOIN children c ON c.barangay_id = b.id
     LEFT JOIN measurements lm ON lm.id = (
        SELECT m.id
        FROM measurements m
        WHERE m.child_id = c.id
        ORDER BY m.measurement_date DESC, m.id DESC
        LIMIT 1
     )
     WHERE b.status = 'active' AND {$scope}
     GROUP BY b.id, b.name, b.status
     ORDER BY b.name ASC",
    str_repeat('i', count($params)),
    $params
);

/**
 * Normalize a barangay name so DB spelling ("Santo Rosario (Pob.)") lines
 * up with the boundary file's spelling ("Santo Rosario"). Matched purely
 * by lowercase text, no accent-stripping, since both sources already use
 * the same NAME_3 spelling for shared letters like ñ.
 */
function risk_map_normalize_name(string $name): string
{
    $name = preg_replace('/\s*\((?:pob\.?|poblacion)\)\s*/i', '', $name) ?? $name;
    $name = preg_replace('/\s+/', ' ', trim($name)) ?? trim($name);

    return mb_strtolower($name);
}

$thresholdHigh = 25.0;
$thresholdModerate = 10.0;

$byNormalizedName = [];
$highRiskCount = 0;
$totalChildren = 0;
$totalMeasured = 0;
$totalAtRisk = 0;
$levelCounts = ['high' => 0, 'moderate' => 0, 'low' => 0, 'no_data' => 0];

foreach ($barangayStats as $row) {
    $children = (int)$row['children_count'];
    $measured = (int)$row['measured_count'];
    $atRisk = (int)$row['at_risk_count'];
    $prevalence = $measured > 0 ? round(($atRisk / $measured) * 100, 1) : null;

    if ($prevalence === null) {
        $level = 'no_data';
    } elseif ($prevalence >= $thresholdHigh) {
        $level = 'high';
    } elseif ($prevalence >= $thresholdModerate) {
        $level = 'moderate';
    } else {
        $level = 'low';
    }

    if ($level === 'high') {
        $highRiskCount++;
    }

    $levelCounts[$level] = ($levelCounts[$level] ?? 0) + 1;

    $totalChildren += $children;
    $totalMeasured += $measured;
    $totalAtRisk += $atRisk;

    $byNormalizedName[risk_map_normalize_name((string)$row['name'])] = [
        'name' => $row['name'],
        'children' => $children,
        'measured' => $measured,
        'at_risk' => $atRisk,
        'prevalence' => $prevalence,
        'level' => $level,
    ];
}

$overallPrevalence = $totalMeasured > 0 ? round(($totalAtRisk / $totalMeasured) * 100, 1) : null;

$isScoped = ($user['role'] ?? '') !== 'admin' && !empty($user['barangay_id']);
$subtitle = $isScoped
    ? 'Malnutrition prevalence for your assigned barangay, City of San Fernando, Pampanga.'
    : 'Malnutrition prevalence by barangay across City of San Fernando, Pampanga.';

nutritionist_layout_start('Barangay Risk Map', $subtitle, 'risk_map');
?>
<section class="admin-grid-cards">
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Barangays mapped</div>
                <div class="admin-card-value"><?php echo count($barangayStats); ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-up">All active barangays</span>
                </div>
            </div>
        </div>
    </article>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Children measured</div>
                <div class="admin-card-value"><?php echo $totalMeasured; ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-up">All-time measurements</span>
                </div>
            </div>
        </div>
    </article>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon is-danger">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">High-risk barangays</div>
                <div class="admin-card-value"><?php echo $highRiskCount; ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-danger">Needs priority intervention</span>
                </div>
            </div>
        </div>
    </article>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Prevalence</div>
                <div class="admin-card-value"><?php echo $overallPrevalence !== null ? admin_e((string)$overallPrevalence) . '%' : 'n/a'; ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-up">Malnutrition rate across barangays</span>
                </div>
            </div>
        </div>
    </article>
</section>

<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">GeoMapper &mdash; Nutritional Risk Exposure</h2>
            <p class="admin-section-subtitle">Colored by the share of measured children currently classified with a nutritional concern. Click a barangay for details.</p>
        </div>
    </div>

    <div class="admin-riskmap-layout">
        <div class="admin-riskmap-mapwrap">
            <div id="risk-map" class="admin-riskmap-canvas-v2"></div>
        </div>

        <aside class="admin-riskmap-sidebar">
            <div class="admin-riskmap-card">
                <h3 class="admin-riskmap-card-title">Risk Levels</h3>
                <ul id="risk-map-legend" class="admin-riskmap-legend-v2">
                    <li data-level="high">
                        <span class="admin-riskmap-swatch" style="background:#c93b3b"></span>
                        <span class="admin-riskmap-legend-text">High <em>(&ge;<?php echo admin_e((string)$thresholdHigh); ?>%)</em></span>
                        <span class="admin-riskmap-legend-count"><?php echo (int)$levelCounts['high']; ?></span>
                    </li>
                    <li data-level="moderate">
                        <span class="admin-riskmap-swatch" style="background:#f2a93b"></span>
                        <span class="admin-riskmap-legend-text">Moderate <em>(<?php echo admin_e((string)$thresholdModerate); ?>&ndash;<?php echo admin_e((string)$thresholdHigh); ?>%)</em></span>
                        <span class="admin-riskmap-legend-count"><?php echo (int)$levelCounts['moderate']; ?></span>
                    </li>
                    <li data-level="low">
                        <span class="admin-riskmap-swatch" style="background:#2f9e5c"></span>
                        <span class="admin-riskmap-legend-text">Low <em>(&lt;<?php echo admin_e((string)$thresholdModerate); ?>%)</em></span>
                        <span class="admin-riskmap-legend-count"><?php echo (int)$levelCounts['low']; ?></span>
                    </li>
                    <li data-level="no_data">
                        <span class="admin-riskmap-swatch" style="background:#c7ccd1"></span>
                        <span class="admin-riskmap-legend-text">No measurements yet</span>
                        <span class="admin-riskmap-legend-count"><?php echo (int)$levelCounts['no_data']; ?></span>
                    </li>
                </ul>
            </div>

            <div class="admin-riskmap-card">
                <h3 class="admin-riskmap-card-title">Risk Level Distribution</h3>
                <div class="admin-riskmap-chartwrap">
                    <canvas id="risk-map-donut" width="220" height="220"></canvas>
                </div>
                <p class="admin-mini admin-riskmap-chartcaption">
                    <?php echo $isScoped
                        ? 'Risk category for your assigned barangay.'
                        : 'Share of active barangays in each risk category, City of San Fernando.'; ?>
                </p>
            </div>
        </aside>
    </div>

    <div id="risk-map-status" class="admin-mini" style="margin-top:8px;"></div>
</section>

<section class="admin-section" style="margin-top:16px;">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">Barangay Breakdown</h2>
            <p class="admin-section-subtitle">Same data as the map, in table form.</p>
        </div>
    </div>

    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Barangay</th>
                    <th>Children</th>
                    <th>Measured</th>
                    <th>At risk</th>
                    <th>Prevalence</th>
                    <th>Risk level</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($byNormalizedName as $stat): ?>
                    <?php
                    $pillClass = [
                        'high' => 'is-danger',
                        'moderate' => 'is-warn',
                        'low' => 'is-success',
                        'no_data' => 'is-muted',
                    ][$stat['level']];
                    $pillLabel = [
                        'high' => 'High',
                        'moderate' => 'Moderate',
                        'low' => 'Low',
                        'no_data' => 'No data',
                    ][$stat['level']];
                    ?>
                    <tr>
                        <td><?php echo admin_e($stat['name']); ?></td>
                        <td><?php echo (int)$stat['children']; ?></td>
                        <td><?php echo (int)$stat['measured']; ?></td>
                        <td><?php echo (int)$stat['at_risk']; ?></td>
                        <td><?php echo $stat['prevalence'] !== null ? admin_e((string)$stat['prevalence']) . '%' : 'n/a'; ?></td>
                        <td><span class="admin-pill <?php echo $pillClass; ?>"><?php echo $pillLabel; ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>


<?php
// ── Local Area Prevalence ──────────────────────────────────────────────
$localAreaStats = admin_fetch_all(
    "SELECT
        la.id,
        la.barangay_id,
        la.area_name,
        la.area_type,
        b.name AS barangay_name,
        COUNT(c.id) AS children_count,
        SUM(CASE WHEN lm.measurement_date IS NOT NULL THEN 1 ELSE 0 END) AS measured_count,
        SUM(CASE WHEN lm.nutritional_status IS NOT NULL AND lm.nutritional_status NOT IN ('Normal') THEN 1 ELSE 0 END) AS at_risk_count
     FROM local_areas la
     INNER JOIN barangays b ON b.id = la.barangay_id
     LEFT JOIN children c ON c.local_area_id = la.id
     LEFT JOIN measurements lm ON lm.id = (
        SELECT m.id
        FROM measurements m
        WHERE m.child_id = c.id
        ORDER BY m.measurement_date DESC, m.id DESC
        LIMIT 1
     )
     WHERE la.is_active = 1 AND b.status = 'active' AND {$scope}
     GROUP BY la.id, la.barangay_id, la.area_name, la.area_type, b.name
     HAVING measured_count > 0
     ORDER BY (SUM(CASE WHEN lm.nutritional_status IS NOT NULL AND lm.nutritional_status NOT IN ('Normal') THEN 1 ELSE 0 END) / SUM(CASE WHEN lm.measurement_date IS NOT NULL THEN 1 ELSE 0 END)) DESC, la.area_name ASC",
    str_repeat('i', count($params)),
    $params
);

foreach ($localAreaStats as &$la) {
    $la['prevalence'] = $la['measured_count'] > 0
        ? round(($la['at_risk_count'] / $la['measured_count']) * 100, 1)
        : null;
}
unset($la);
?>

<section class="admin-section" style="margin-top:16px;">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">Local Area Prevalence</h2>
            <p class="admin-section-subtitle">Purok, sitio, subdivision, and other local-area breakdown. Ranked by highest prevalence.</p>
        </div>
    </div>

    <?php if (empty($localAreaStats)): ?>
        <div class="admin-empty">
            <p>No local areas with measurements found. Assign children to local areas to see prevalence data here.</p>
        </div>
    <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table" id="local-area-prevalence-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Barangay</th>
                        <th>Local Area</th>
                        <th>Type</th>
                        <th>Children</th>
                        <th>Measured</th>
                        <th>At risk</th>
                        <th>Prevalence</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $rank = 0; foreach ($localAreaStats as $la): ?>
                        <?php
                        $rank++;
                        $prev = $la['prevalence'];
                        if ($prev === null) {
                            $prevColor = 'var(--admin-muted)';
                        } elseif ($prev >= $thresholdHigh) {
                            $prevColor = '#E03131';
                        } elseif ($prev >= $thresholdModerate) {
                            $prevColor = '#F08C00';
                        } else {
                            $prevColor = '#2F9E44';
                        }
                        ?>
                        <tr>
                            <td style="color:var(--admin-muted);"><?php echo $rank; ?></td>
                            <td style="font-weight:700;"><?php echo admin_e($la['barangay_name']); ?></td>
                            <td><?php echo admin_e($la['area_name']); ?></td>
                            <td>
                                <span class="admin-pill is-info">
                                    <?php echo admin_e(ucfirst($la['area_type'])); ?>
                                </span>
                            </td>
                            <td><?php echo (int)$la['children_count']; ?></td>
                            <td><?php echo (int)$la['measured_count']; ?></td>
                            <td><?php echo (int)$la['at_risk_count']; ?></td>
                            <td style="font-weight:700;color:<?php echo $prevColor; ?>;">
                                <?php echo $prev !== null ? admin_e((string)$prev) . '%' : 'n/a'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>


<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script>
<script>
(function () {
    "use strict";

    var STATS_BY_NAME = <?php echo json_encode($byNormalizedName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    var LEVEL_COUNTS = <?php echo json_encode($levelCounts, JSON_UNESCAPED_UNICODE); ?>;
    var GEOJSON_URL = <?php echo json_encode(app_url('/assets/data/sanfernando_barangays.geojson')); ?>;

    var LEVEL_COLORS = {
        high: '#c93b3b',
        moderate: '#f2a93b',
        low: '#2f9e5c',
        no_data: '#c7ccd1'
    };

    var LEVEL_LABELS = {
        high: 'High',
        moderate: 'Moderate',
        low: 'Low',
        no_data: 'No data'
    };

    function normalizeName(name) {
        return String(name || '')
            .replace(/\s*\((?:pob\.?|poblacion)\)\s*/i, '')
            .trim()
            .replace(/\s+/g, ' ')
            .toLowerCase();
    }

    function statusEl() {
        return document.getElementById('risk-map-status');
    }

    function renderDonut() {
        var canvas = document.getElementById('risk-map-donut');
        if (!canvas || typeof Chart === 'undefined') {
            return;
        }

        var order = ['high', 'moderate', 'low', 'no_data'];
        var data = order.map(function (k) { return LEVEL_COUNTS[k] || 0; });
        var colors = order.map(function (k) { return LEVEL_COLORS[k]; });
        var labels = order.map(function (k) { return LEVEL_LABELS[k]; });

        new Chart(canvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: colors,
                    borderColor: '#ffffff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: false,
                cutout: '62%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return ctx.label + ': ' + ctx.parsed + ' barangay(s)';
                            }
                        }
                    }
                }
            }
        });
    }

    // Custom Leaflet control: basemap gallery (street / satellite / terrain).
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
                if (opt.key === 'street') {
                    btn.classList.add('is-active');
                }
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
            if (this._onSelect) {
                this._onSelect(key);
            }
            var btns = container.querySelectorAll('.admin-riskmap-basemap-btn');
            btns.forEach(function (b) {
                b.classList.toggle('is-active', b.dataset.basemap === key);
            });
        },
        onSelect: function (fn) {
            this._onSelect = fn;
            return this;
        }
    });

    // Custom Leaflet control: type-ahead search that flies to a barangay.
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
                        if (this._onSearch) { this._onSearch(name); }
                    }.bind(this));
                    dropdown.appendChild(item);
                }.bind(this));
                dropdown.style.display = 'block';
            }

            L.DomEvent.on(input, 'input', function () {
                showDropdown.call(this, input.value);
            }, this);

            L.DomEvent.on(input, 'keydown', function (e) {
                if (e.key === 'Enter') {
                    dropdown.style.display = 'none';
                    if (this._onSearch) { this._onSearch(input.value); }
                }
                if (e.key === 'Escape') { dropdown.style.display = 'none'; }
            }, this);

            L.DomEvent.on(input, 'blur', function () {
                setTimeout(function () { dropdown.style.display = 'none'; }, 150);
            });

            L.DomEvent.disableClickPropagation(container);
            L.DomEvent.disableScrollPropagation(container);

            this._dropdown = dropdown;
            this._input = input;
            return container;
        },
        onSearch: function (fn) {
            this._onSearch = fn;
            return this;
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        renderDonut();

        var mapEl = document.getElementById('risk-map');
        if (!mapEl || typeof L === 'undefined') {
            return;
        }

        var map = L.map(mapEl, { scrollWheelZoom: false, zoomControl: false }).setView([15.034, 120.686], 12);

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

        new BasemapGallery().onSelect(function (key) {
            if (baseLayers[key] === activeBase) {
                return;
            }
            map.removeLayer(activeBase);
            activeBase = baseLayers[key].addTo(map);
        }).addTo(map);

        var geoLayer = null;

        new BarangaySearch().onSearch(function (query) {
            if (!geoLayer || !query) {
                return;
            }
            var target = null;
            geoLayer.eachLayer(function (fl) {
                if (fl.feature.properties.name.toLowerCase() === query.toLowerCase()) {
                    target = fl;
                }
            });
            if (target) {
                map.fitBounds(target.getBounds(), { padding: [40, 40], maxZoom: 15 });
                target.openPopup();
            }
        }).addTo(map);

        fetch(GEOJSON_URL)
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(function (geojson) {
                var matched = 0;

                geoLayer = L.geoJSON(geojson, {
                    style: function (feature) {
                        var key = normalizeName(feature.properties.name);
                        var stat = STATS_BY_NAME[key];
                        var level = stat ? stat.level : 'no_data';

                        return {
                            color: '#ffffff',
                            weight: 1,
                            fillColor: LEVEL_COLORS[level] || LEVEL_COLORS.no_data,
                            fillOpacity: 0.75
                        };
                    },
                    onEachFeature: function (feature, featureLayer) {
                        var key = normalizeName(feature.properties.name);
                        var stat = STATS_BY_NAME[key];

                        if (stat) {
                            matched++;
                        }

                        var label = stat ? stat.name : feature.properties.name;
                        var body = stat
                            ? (
                                '<strong>' + label + '</strong><br>' +
                                stat.children + ' children &middot; ' + stat.measured + ' measured<br>' +
                                (stat.prevalence !== null ? stat.prevalence + '% at risk (' + stat.at_risk + ' children)' : 'No measurements yet')
                            )
                            : ('<strong>' + label + '</strong><br>No data on record for this barangay yet.');

                        featureLayer.bindPopup(body);

                        featureLayer.on('mouseover', function () {
                            featureLayer.setStyle({ weight: 2.5, color: '#2f3d3a' });
                        });

                        featureLayer.on('mouseout', function () {
                            geoLayer.resetStyle(featureLayer);
                        });
                    }
                }).addTo(map);

                map.fitBounds(geoLayer.getBounds(), { padding: [16, 16] });

                var missing = Object.keys(STATS_BY_NAME).length - matched;
                if (statusEl()) {
                    statusEl().textContent = missing > 0
                        ? 'Boundary data isn\'t available yet for ' + missing + ' barangay(s) in the master list \u2014 see the table below for the complete set.'
                        : '';
                }
            })
            .catch(function () {
                mapEl.innerHTML = '';
                if (statusEl()) {
                    statusEl().textContent = 'Could not load the map boundaries right now. The table below still has the full breakdown.';
                }
            });
    });
})();
</script>
<?php
nutritionist_layout_end();

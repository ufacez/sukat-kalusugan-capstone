<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

$user = nutritionist_require_access();

/**
 * Per-barangay nutrition snapshot.
 *
 * "At risk" mirrors the same definition already used on
 * nutritionist/who_analysis.php: the child's LATEST measurement is
 * anything other than Normal or Overweight (i.e. Underweight, Severely
 * Underweight, Stunted, or Wasted).
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
        SUM(CASE WHEN lm.nutritional_status IS NOT NULL AND lm.nutritional_status NOT IN ('Normal', 'Overweight') THEN 1 ELSE 0 END) AS at_risk_count
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
        <div class="admin-stat-label">Barangays mapped</div>
        <div class="admin-stat-value"><?php echo count($barangayStats); ?></div>
    </article>
    <article class="admin-card">
        <div class="admin-stat-label">Children measured</div>
        <div class="admin-stat-value"><?php echo $totalMeasured; ?></div>
    </article>
    <article class="admin-card">
        <div class="admin-stat-label">High-risk barangays</div>
        <div class="admin-stat-value"><?php echo $highRiskCount; ?></div>
    </article>
    <article class="admin-card">
        <div class="admin-stat-label">Prevalence</div>
        <div class="admin-stat-value"><?php echo $overallPrevalence !== null ? admin_e((string)$overallPrevalence) . '%' : 'n/a'; ?></div>
    </article>
</section>

<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">GeoMapper &mdash; Nutritional Risk Exposure</h2>
            <p class="admin-section-subtitle">Colored by the share of measured children currently classified Underweight, Stunted, or Wasted. Click a barangay for details.</p>
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
            input.setAttribute('list', 'risk-map-barangay-list');

            var datalist = document.getElementById('risk-map-barangay-list');
            if (!datalist) {
                datalist = document.createElement('datalist');
                datalist.id = 'risk-map-barangay-list';
                document.body.appendChild(datalist);
            }
            Object.keys(STATS_BY_NAME).forEach(function (key) {
                var opt = document.createElement('option');
                opt.value = STATS_BY_NAME[key].name;
                datalist.appendChild(opt);
            });

            L.DomEvent.on(input, 'keydown', function (e) {
                if (e.key === 'Enter' && this._onSearch) {
                    this._onSearch(input.value);
                }
            }, this);

            L.DomEvent.disableClickPropagation(container);
            L.DomEvent.disableScrollPropagation(container);

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

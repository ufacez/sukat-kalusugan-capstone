<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

$user = nutritionist_require_access();

$scopeParams = [];
$scope = nutritionist_scope_fragment($user, 'h.barangay_id', $scopeParams);

$barangayParams = [];
$barangayScope = nutritionist_scope_fragment($user, 'b.id', $barangayParams);

$barangayCountRow = admin_fetch_one(
    "SELECT COUNT(*) AS cnt FROM barangays b WHERE b.status = 'active' AND {$barangayScope}",
    str_repeat('i', count($barangayParams)),
    $barangayParams
);
$totalBarangays = (int)($barangayCountRow['cnt'] ?? 0);

$allBarangays = admin_fetch_all(
    "SELECT id, name FROM barangays WHERE status = 'active' ORDER BY name ASC"
);
$isBarangayAdmin = ($user['role'] ?? '') === 'admin';
$lockedBarangay = !$isBarangayAdmin && !empty($barangayParams);
$lockedBarangayId = null;
$lockedBarangayName = '';
if ($lockedBarangay) {
    $lockedBarangayId = (int)$barangayParams[0];
    foreach ($allBarangays as $b) {
        if ((int)$b['id'] === $lockedBarangayId) {
            $lockedBarangayName = $b['name'];
            break;
        }
    }
}

$spotRows = admin_fetch_all(
    "SELECT
        h.id,
        h.household_code,
        h.lat,
        h.lng,
        h.local_area_id,
        h.status AS hh_status,
        b.name AS barangay_name,
        COALESCE(la.area_name, '') AS purok_name,
        COUNT(c.id) AS child_count,
        GROUP_CONCAT(c.id ORDER BY c.id) AS child_ids
     FROM households h
     INNER JOIN barangays b ON b.id = h.barangay_id
     LEFT JOIN local_areas la ON la.id = h.local_area_id
     LEFT JOIN children c ON c.household_id = h.id AND c.status = 'active'
     WHERE h.status = 'active' AND {$scope}
     GROUP BY h.id, h.household_code, h.lat, h.lng, h.local_area_id, h.status, b.name, la.area_name
     ORDER BY h.created_at ASC, h.id ASC",
    str_repeat('i', count($scopeParams)),
    $scopeParams
);

$householdIds = [];
foreach ($spotRows as $sr) {
    $cid = $sr['child_ids'] ?? '';
    if ($cid !== '') {
        foreach (explode(',', $cid) as $c) {
            $householdIds[(int)$c] = (int)$sr['id'];
        }
    }
}

$measurementMap = [];
if (!empty($householdIds)) {
    $childIds = array_keys($householdIds);
    $placeholders = implode(',', array_fill(0, count($childIds), '?'));
    $types = str_repeat('i', count($childIds));

    $mRows = admin_fetch_all(
        "SELECT m.id, m.child_id, m.measurement_date, m.waz, m.haz, m.whz, m.nutritional_status
         FROM measurements m
         INNER JOIN (
            SELECT child_id, MAX(measurement_date) AS max_date
            FROM measurements
            WHERE child_id IN ({$placeholders})
            GROUP BY child_id
         ) latest ON latest.child_id = m.child_id AND latest.max_date = m.measurement_date
         WHERE m.child_id IN ({$placeholders})",
        $types . $types,
        array_merge($childIds, $childIds)
    );

    foreach ($mRows as $mr) {
        $childId = (int)$mr['child_id'];
        $measurementMap[$childId] = [
            'waz' => $mr['waz'] !== null ? (float)$mr['waz'] : null,
            'haz' => $mr['haz'] !== null ? (float)$mr['haz'] : null,
            'whz' => $mr['whz'] !== null ? (float)$mr['whz'] : null,
            'status' => $mr['nutritional_status'] ?? 'Normal',
        ];
    }
}

$childDetailMap = [];
if (!empty($householdIds)) {
    $childIds = array_keys($householdIds);
    $placeholders = implode(',', array_fill(0, count($childIds), '?'));
    $types = str_repeat('i', count($childIds));

    $cRows = admin_fetch_all(
        "SELECT c.id, c.child_code, c.first_name, c.last_name, c.sex, c.birthdate,
                c.household_id,
                TIMESTAMPDIFF(MONTH, c.birthdate, CURDATE()) AS age_months
         FROM children c
         WHERE c.id IN ({$placeholders})",
        $types,
        $childIds
    );

    foreach ($cRows as $cr) {
        $childDetailMap[(int)$cr['id']] = $cr;
    }
}

$spots = [];
$totalChildren = 0;
$totalHighRisk = 0;
$totalMeasured = 0;
$countByLevel = ['normal' => 0, 'moderate' => 0, 'severe' => 0, 'overweight' => 0];
$indicatorOptions = ['wfa' => 'Weight-for-Age', 'hfa' => 'Height-for-Age', 'wfhl' => 'Weight-for-Length/Height'];

foreach ($spotRows as $sr) {
    $spotId = (int)$sr['id'];
    $childCount = (int)$sr['child_count'];
    $totalChildren += $childCount;

    $purok = $sr['purok_name'] ?: 'Unassigned';

    $spotChildren = [];
    $childIds = !empty($sr['child_ids']) ? explode(',', (string)$sr['child_ids']) : [];
    $normalCount = 0;
    $moderateCount = 0;
    $severeCount = 0;
    $overweightCount = 0;
    $worstLevel = 'normal';
    $worstColor = '#22c55e';
    $worstStatus = 'Normal';

    foreach ($childIds as $cid) {
        $cid = (int)$cid;
        $detail = $childDetailMap[$cid] ?? null;
        $meas = $measurementMap[$cid] ?? null;
        if ($detail && $meas) {
            $totalMeasured++;
            $status = $meas['status'] ?? 'Normal';
            $childEntry = [
                'id' => $cid,
                'code' => $detail['child_code'] ?? '',
                'name' => trim(($detail['first_name'] ?? '') . ' ' . ($detail['last_name'] ?? '')),
                'sex' => $detail['sex'] ?? '',
                'age_months' => (int)($detail['age_months'] ?? 0),
                'waz' => $meas['waz'],
                'haz' => $meas['haz'],
                'whz' => $meas['whz'],
                'status' => $status,
            ];
            $spotChildren[] = $childEntry;

            $levelForSpot = 'normal';
            if (in_array($status, ['Severely Underweight','Severely Stunted','Severely Wasted'], true)) {
                $levelForSpot = 'severe';
            } elseif (in_array($status, ['Moderately Underweight','Moderately Stunted','Moderately Wasted'], true)) {
                $levelForSpot = 'moderate';
            } elseif (in_array($status, ['Overweight','Obese'], true)) {
                $levelForSpot = 'overweight';
            }

            if ($levelForSpot === 'severe') {
                $severeCount++;
            } elseif ($levelForSpot === 'moderate') {
                $moderateCount++;
            } elseif ($levelForSpot === 'overweight') {
                $overweightCount++;
            } else {
                $normalCount++;
            }

            if ($levelForSpot === 'severe') {
                $worstLevel = 'severe';
                $worstColor = '#ef4444';
                $worstStatus = $status;
            } elseif ($levelForSpot === 'moderate' && $worstLevel !== 'severe') {
                $worstLevel = 'moderate';
                $worstColor = '#eab308';
                $worstStatus = $status;
            } elseif ($levelForSpot === 'overweight' && $worstLevel === 'normal') {
                $worstLevel = 'overweight';
                $worstColor = '#f97316';
                $worstStatus = $status;
            }
        } elseif ($detail) {
            $spotChildren[] = [
                'id' => $cid,
                'code' => $detail['child_code'] ?? '',
                'name' => trim(($detail['first_name'] ?? '') . ' ' . ($detail['last_name'] ?? '')),
                'sex' => $detail['sex'] ?? '',
                'age_months' => (int)($detail['age_months'] ?? 0),
                'waz' => null, 'haz' => null, 'whz' => null,
                'status' => 'Unmeasured',
            ];
        }
    }

    $hasRisk = $severeCount > 0 || $moderateCount > 0 || $overweightCount > 0;
    $spotLevel = 'low';
    $spotLevelLabel = 'Low';
    if ($severeCount > 0) {
        $spotLevel = 'high';
        $spotLevelLabel = 'High';
        $totalHighRisk++;
    } elseif ($moderateCount > 0 || $overweightCount > 0) {
        $spotLevel = 'moderate';
        $spotLevelLabel = 'Moderate';
    }
    $countByLevel[$worstLevel === 'normal' ? 'normal' : ($worstLevel === 'severe' ? 'severe' : ($worstLevel === 'overweight' ? 'overweight' : 'moderate'))]++;

    $spots[] = [
        'id' => $spotId,
        'code' => 'HH-' . str_pad((string)$spotId, 4, '0', STR_PAD_LEFT),
        'lat' => $sr['lat'] !== null ? (float)$sr['lat'] : null,
        'lng' => $sr['lng'] !== null ? (float)$sr['lng'] : null,
        'barangay' => $sr['barangay_name'],
        'purok' => $purok,
        'child_count' => $childCount,
        'children' => $spotChildren,
        'normal' => $normalCount,
        'moderate' => $moderateCount,
        'severe' => $severeCount,
        'overweight' => $overweightCount,
        'level' => $spotLevel,
        'level_label' => $spotLevelLabel,
        'worst_color' => $worstColor,
    ];
}

$totalSpots = count($spots);

$spotsJson = json_encode($spots, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$geoJsonUrl = app_url('/assets/data/sanfernando_barangays.geojson');

nutritionist_layout_start('Barangay Risk Map', 'View the distribution of children and nutritional risk status per household in your assigned barangay.', 'risk_map');
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

<section class="admin-grid-cards">
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Barangays Mapped</div>
                <div class="admin-card-value"><?php echo $totalBarangays; ?></div>
                <div class="admin-card-meta"><span class="admin-card-trend is-up">Assigned Barangay<?php echo $totalBarangays !== 1 ? 's' : ''; ?></span></div>
            </div>
        </div>
    </article>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Children Measured</div>
                <div class="admin-card-value"><?php echo $totalMeasured; ?></div>
                <div class="admin-card-meta"><span class="admin-card-trend is-up">This Barangay</span></div>
            </div>
        </div>
    </article>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Households / Spots Mapped</div>
                <div class="admin-card-value"><?php echo $totalSpots; ?></div>
                <div class="admin-card-meta"><span class="admin-card-trend is-up">Total Households</span></div>
            </div>
        </div>
    </article>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon is-danger">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">High-Risk Spots</div>
                <div class="admin-card-value"><?php echo $totalHighRisk; ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-danger">
                        <?php echo $totalSpots > 0 ? admin_e((string)round($totalHighRisk / $totalSpots * 100, 1)) . '% of total spots' : 'No spots yet'; ?>
                    </span>
                </div>
            </div>
        </div>
    </article>
</section>

<section class="admin-section" style="margin-top:12px;">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:20px;height:20px;vertical-align:-3px;margin-right:6px;opacity:.6"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498c.914.27 1.87.411 2.854.414a4.5 4.5 0 004.412-5.577 4.5 4.5 0 00-4.412-5.577 4.5 4.5 0 00-4.412 5.577c0 .914.141 1.87.414 2.854"/></svg>Barangay Risk Map</h2>
            <p class="admin-section-subtitle">View the distribution of children and nutritional risk status per household in your assigned barangay.</p>
        </div>
        <div class="admin-section-actions">
            <button class="admin-btn admin-btn-sm" id="spotmap-add-btn" style="background:var(--admin-valid);color:#fff;border:none;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:15px;height:15px;vertical-align:-2px;margin-right:4px"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Add Spot
            </button>
            <button class="admin-btn admin-btn-sm" id="spotmap-import-btn" style="background:var(--admin-surface);color:var(--admin-text);border:1px solid var(--admin-border);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:15px;height:15px;vertical-align:-2px;margin-right:4px"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
                Import Spot Map
            </button>
        </div>
    </div>

    <p class="admin-mini" style="margin:-4px 0 12px;color:var(--admin-muted);">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:13px;height:13px;vertical-align:-2px;margin-right:3px;opacity:.5"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
        Digital map from registered data (not a manual sketch)
    </p>

    <div class="admin-spotmap-filters">
        <div class="admin-spotmap-filter">
            <label class="admin-spotmap-filter-label">Barangay</label>
            <?php if ($lockedBarangay): ?>
                <input type="hidden" id="filter-barangay" value="<?php echo $lockedBarangayId; ?>">
                <select class="admin-spotmap-filter-select" disabled>
                    <option value="<?php echo $lockedBarangayId; ?>" selected><?php echo admin_e($lockedBarangayName); ?></option>
                </select>
            <?php else: ?>
                <select id="filter-barangay" class="admin-spotmap-filter-select">
                    <option value="">All Barangays</option>
                    <?php foreach ($allBarangays as $b): ?>
                        <option value="<?php echo (int)$b['id']; ?>"><?php echo admin_e($b['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>
        <div class="admin-spotmap-filter">
            <label class="admin-spotmap-filter-label">Risk Level</label>
            <select id="filter-risk" class="admin-spotmap-filter-select">
                <option value="">All Risk Levels</option>
                <option value="high">High</option>
                <option value="moderate">Moderate</option>
                <option value="low">Low</option>
            </select>
        </div>
        <div class="admin-spotmap-filter">
            <label class="admin-spotmap-filter-label">Indicator</label>
            <select id="filter-indicator" class="admin-spotmap-filter-select">
                <option value="">All Indicators</option>
                <option value="wfa">Weight-for-Age</option>
                <option value="hfa">Height-for-Age</option>
                <option value="wfhl">Weight-for-Length/Height</option>
            </select>
        </div>
        <div class="admin-spotmap-filter admin-spotmap-filter-date">
            <label class="admin-spotmap-filter-label">Date Range</label>
            <input type="date" id="filter-date-from" class="admin-spotmap-filter-input" value="">
            <span class="admin-spotmap-filter-sep">&ndash;</span>
            <input type="date" id="filter-date-to" class="admin-spotmap-filter-input" value="">
        </div>
        <button class="admin-spotmap-clear-btn" id="filter-clear">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:14px;height:14px;vertical-align:-2px;margin-right:3px"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182"/></svg>
            Clear Filters
        </button>
    </div>

    <div class="admin-riskmap-layout">
        <div class="admin-riskmap-mapwrap" style="position:relative;">
            <div id="spot-map" class="admin-riskmap-canvas-v2" style="min-height:460px;"></div>
            <div id="spot-coord-bar" class="admin-spotmap-coord-bar">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:13px;height:13px;vertical-align:-2px;opacity:.6"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                <span id="spot-coord-text">Click anywhere on the map to preview coordinates</span>
            </div>

            <div id="spot-boundary-warn" class="admin-spotmap-boundary-warn" style="display:none;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:14px;height:14px;vertical-align:-2px"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
                <span id="spot-boundary-warn-text">Selected location is outside the barangay boundary.</span>
            </div>
        </div>

        <aside class="admin-spotmap-sidebar">
            <div id="spot-panel" class="admin-spotmap-panel">
                <div class="admin-spotmap-panel-header">
                    <h3 class="admin-spotmap-panel-title">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:16px;height:16px;vertical-align:-2px;margin-right:4px;color:var(--admin-valid)"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                        Selected Spot
                    </h3>
                    <button id="spot-panel-close" class="admin-spotmap-panel-close" title="Close" style="display:none;">&times;</button>
                </div>
                <div id="spot-panel-body" class="admin-spotmap-panel-body">
                    <div class="admin-spotmap-empty" style="margin-top:40px;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:32px;height:32px;opacity:.3;display:block;margin:0 auto 8px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                        Click a household marker on the map to view details.
                    </div>
                </div>
            </div>
        </aside>
    </div>
</section>

<section class="admin-section" style="margin-top:16px;">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">Spot Map Summary</h2>
        </div>
        <div class="admin-spotmap-search">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="admin-spotmap-search-icon"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>
            <input type="search" id="spot-summary-search" class="admin-spotmap-search-input" placeholder="Search by code or purok…" autocomplete="off">
            <button type="button" id="spot-summary-search-clear" class="admin-spotmap-search-clear" title="Clear search" style="display:none;">&times;</button>
        </div>
    </div>

    <div class="admin-table-wrap">
        <table class="admin-table" id="spot-summary-table" data-no-paginate>
            <thead>
                <tr>
                    <th>Spot / Household</th>
                    <th>Purok</th>
                    <th>Children</th>
                    <th>Normal</th>
                    <th>Moderate Risk</th>
                    <th>Severe Risk</th>
                    <th>Risk Level</th>
                    <th>View</th>
                </tr>
            </thead>
            <tbody id="spot-summary-body">
            </tbody>
        </table>
        <div class="admin-table-pagination" id="spot-summary-pagination">
            <span class="admin-table-page-info" id="spot-summary-count"></span>
        </div>
    </div>
</section>

<div id="spot-add-modal" class="admin-spotmap-modal" style="display:none;">
    <div class="admin-spotmap-modal-backdrop" data-close-modal="spot-add-modal"></div>
    <div class="admin-spotmap-modal-content">
        <div class="admin-spotmap-modal-header">
            <h3 id="spot-modal-title">Add New Spot</h3>
            <button class="admin-spotmap-panel-close" data-close-modal="spot-add-modal">&times;</button>
        </div>
        <form id="spot-add-form" class="admin-spotmap-modal-body">
            <input type="hidden" name="id" id="spot-form-id" value="">
            <div class="admin-spotmap-form-row">
                <div class="admin-spotmap-form-group">
                    <label>Household Code</label>
                    <input type="text" id="spot-form-code-preview" value="" readonly style="background:var(--admin-surface-alt);color:var(--admin-muted);font-weight:600;">
                    <small style="display:block;color:var(--admin-muted);font-size:11px;margin-top:4px;">Auto-generated on save (format: HH-0001)</small>
                </div>
                <div class="admin-spotmap-form-group">
                    <label>Address</label>
                    <input type="text" name="address" placeholder="Street / landmark">
                </div>
            </div>
            <div class="admin-spotmap-form-row">
                <div class="admin-spotmap-form-group">
                    <label>Latitude</label>
                    <input type="number" step="0.0000001" name="lat" placeholder="15.0340">
                </div>
                <div class="admin-spotmap-form-group">
                    <label>Longitude</label>
                    <input type="number" step="0.0000001" name="lng" placeholder="120.6860">
                </div>
            </div>
            <div class="admin-spotmap-form-row">
                <div class="admin-spotmap-form-group">
                    <label>Purok / Local Area</label>
                    <select name="local_area_id">
                        <option value="">None</option>
                        <?php
                        $laRows = admin_fetch_all(
                            "SELECT la.id, la.area_name FROM local_areas la INNER JOIN barangays b ON b.id = la.barangay_id WHERE la.is_active = 1 AND b.status = 'active' AND {$barangayScope} ORDER BY la.area_name",
                            str_repeat('i', count($barangayParams)),
                            $barangayParams
                        );
                        foreach ($laRows as $la): ?>
                            <option value="<?php echo (int)$la['id']; ?>"><?php echo admin_e($la['area_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <p class="admin-mini" style="color:var(--admin-muted);margin:8px 0 12px;">Or click on the map to auto-fill coordinates.</p>
            <div class="admin-spotmap-modal-footer">
                <button type="button" class="admin-btn admin-btn-sm" data-close-modal="spot-add-modal" style="background:var(--admin-surface);color:var(--admin-text);border:1px solid var(--admin-border);">Cancel</button>
                <button type="submit" class="admin-btn admin-btn-sm" style="background:var(--admin-valid);color:#fff;border:none;">Save Spot</button>
            </div>
        </form>
    </div>
</div>

<div id="spot-import-modal" class="admin-spotmap-modal" style="display:none;">
    <div class="admin-spotmap-modal-backdrop" data-close-modal="spot-import-modal"></div>
    <div class="admin-spotmap-modal-content">
        <div class="admin-spotmap-modal-header">
            <h3>Import Spot Map</h3>
            <button class="admin-spotmap-panel-close" data-close-modal="spot-import-modal">&times;</button>
        </div>
        <form id="spot-import-form" class="admin-spotmap-modal-body" enctype="multipart/form-data">
            <p class="admin-mini" style="color:var(--admin-muted);margin:0 0 12px;">Upload a CSV with columns: <code>address, lat, lng, local_area_name</code> — household codes are auto-generated.</p>
            <div class="admin-spotmap-form-group">
                <label>CSV File</label>
                <input type="file" name="csv_file" accept=".csv" required>
            </div>
            <div class="admin-spotmap-modal-footer">
                <button type="button" class="admin-btn admin-btn-sm" data-close-modal="spot-import-modal" style="background:var(--admin-surface);color:var(--admin-text);border:1px solid var(--admin-border);">Cancel</button>
                <button type="submit" class="admin-btn admin-btn-sm" style="background:var(--admin-valid);color:#fff;border:none;">Import</button>
            </div>
        </form>
    </div>
</div>

<div id="assign-children-modal" class="admin-spotmap-modal" style="display:none;">
    <div class="admin-spotmap-modal-backdrop" data-close-modal="assign-children-modal"></div>
    <div class="admin-spotmap-modal-content" style="max-width:480px;">
        <div class="admin-spotmap-modal-header">
            <h3>Add Children to Household</h3>
            <button class="admin-spotmap-panel-close" data-close-modal="assign-children-modal">&times;</button>
        </div>
        <form id="assign-children-form" class="admin-spotmap-modal-body">
            <input type="hidden" name="household_id" id="assign-children-hh" value="">
            <p class="admin-mini" style="color:var(--admin-muted);margin:0 0 8px;">Select children in this barangay who are not yet assigned to a household. Their barangay and local area will be synced to the household's.</p>
            <div id="assign-children-list" class="admin-spotmap-pick-list">
                <p class="admin-mini" style="color:var(--admin-muted);text-align:center;padding:20px;">Loading…</p>
            </div>
            <div class="admin-spotmap-modal-footer">
                <button type="button" class="admin-btn admin-btn-sm" data-close-modal="assign-children-modal" style="background:var(--admin-surface);color:var(--admin-text);border:1px solid var(--admin-border);">Cancel</button>
                <button type="submit" class="admin-btn admin-btn-sm" style="background:var(--admin-valid);color:#fff;border:none;">Assign Selected</button>
            </div>
        </form>
    </div>
</div>

<div id="assign-parents-modal" class="admin-spotmap-modal" style="display:none;">
    <div class="admin-spotmap-modal-backdrop" data-close-modal="assign-parents-modal"></div>
    <div class="admin-spotmap-modal-content" style="max-width:480px;">
        <div class="admin-spotmap-modal-header">
            <h3>Add Parents to Household</h3>
            <button class="admin-spotmap-panel-close" data-close-modal="assign-parents-modal">&times;</button>
        </div>
        <form id="assign-parents-form" class="admin-spotmap-modal-body">
            <input type="hidden" name="household_id" id="assign-parents-hh" value="">
            <p class="admin-mini" style="color:var(--admin-muted);margin:0 0 8px;">Select parents in this barangay who are not yet assigned to a household. Their barangay and local area will be synced to the household's.</p>
            <div id="assign-parents-list" class="admin-spotmap-pick-list">
                <p class="admin-mini" style="color:var(--admin-muted);text-align:center;padding:20px;">Loading…</p>
            </div>
            <div class="admin-spotmap-modal-footer">
                <button type="button" class="admin-btn admin-btn-sm" data-close-modal="assign-parents-modal" style="background:var(--admin-surface);color:var(--admin-text);border:1px solid var(--admin-border);">Cancel</button>
                <button type="submit" class="admin-btn admin-btn-sm" style="background:var(--admin-valid);color:#fff;border:none;">Assign Selected</button>
            </div>
        </form>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function () {
    "use strict";

    var SPOTS = <?php echo $spotsJson; ?>;
    var GEOJSON_URL = <?php echo json_encode($geoJsonUrl); ?>;
    var BASE_URL = <?php echo json_encode(app_url('/')); ?>;
    var LOCKED_BARANGAY_NAME = <?php echo json_encode($lockedBarangayName); ?>;
    var IS_LOCKED = <?php echo $lockedBarangay ? 'true' : 'false'; ?>;
    var ITEMS_PER_PAGE = 5;
    var currentPage = 1;
    var filteredSpots = SPOTS.slice();
    var activeMarker = null;

    var STATUS_DOT_COLORS = {
        'Normal': '#22c55e',
        'Moderately Underweight': '#eab308',
        'Severely Underweight': '#ef4444',
        'Moderately Stunted': '#eab308',
        'Severely Stunted': '#ef4444',
        'Moderately Wasted': '#eab308',
        'Severely Wasted': '#ef4444',
        'Overweight': '#f97316',
        'Obese': '#f97316',
        'Unmeasured': '#94a3b8'
    };

    var INDICATOR_STATUS_MAP = {
        wfa: {
            'Normal': 'Normal',
            'Moderately Underweight': 'MUW',
            'Severely Underweight': 'SUW',
            'Overweight': 'OW',
            'Obese': 'Ob'
        },
        hfa: {
            'Normal': 'Normal',
            'Moderately Stunted': 'MSt',
            'Severely Stunted': 'SSt',
            'Tall': 'Tall'
        },
        wfhl: {
            'Normal': 'Normal',
            'Moderately Wasted': 'MW',
            'Severely Wasted': 'SW',
            'Overweight': 'OW',
            'Obese': 'Ob'
        }
    };

    function getSpotColor(spot) {
        if (spot.severe > 0) return '#ef4444';
        if (spot.moderate > 0) return '#eab308';
        if (spot.overweight > 0) return '#f97316';
        return '#22c55e';
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    function normalizeBarangayName(name) {
        return (name || '').toLowerCase().replace(/\s*\(.*?\)\s*/g, '').replace(/[^a-z0-9]/g, '').trim();
    }

    var barangayLayers = {};
    var activeLockedLayer = null;

    function findBarangayLayer(name) {
        if (!name) return null;
        var norm = normalizeBarangayName(name);
        if (barangayLayers[norm]) return barangayLayers[norm];
        for (var key in barangayLayers) {
            if (key.indexOf(norm) >= 0 || norm.indexOf(key) >= 0) return barangayLayers[key];
        }
        return null;
    }

    function formatSpotCode(code, hhId) {
        var num = String(hhId).padStart(3, '0');
        var purok = '000';
        return 'SP-' + purok + '-' + num;
    }

    function applyFilters() {
        var barangayVal = document.getElementById('filter-barangay').value;
        var riskVal = document.getElementById('filter-risk').value;
        var indicatorVal = document.getElementById('filter-indicator').value;
        var searchInput = document.getElementById('spot-summary-search');
        var searchVal = searchInput ? searchInput.value.trim().toLowerCase() : '';

        filteredSpots = SPOTS.filter(function (s) {
            if (riskVal && s.level !== riskVal) return false;
            if (searchVal) {
                var haystack = ((s.code || '') + ' ' + (s.purok || '') + ' ' + (s.barangay || '')).toLowerCase();
                if (haystack.indexOf(searchVal) === -1) return false;
            }
            return true;
        });

        currentPage = 1;
        renderSummaryTable();
        renderMapMarkers();
    }

    function renderSummaryTable() {
        var tbody = document.getElementById('spot-summary-body');
        var countEl = document.getElementById('spot-summary-count');
        var paginationEl = document.getElementById('spot-summary-pagination');
        if (!tbody) return;

        var total = filteredSpots.length;
        var totalPages = Math.max(1, Math.ceil(total / ITEMS_PER_PAGE));
        if (currentPage > totalPages) currentPage = totalPages;

        var start = (currentPage - 1) * ITEMS_PER_PAGE;
        var end = Math.min(start + ITEMS_PER_PAGE, total);
        var pageSpots = filteredSpots.slice(start, end);

        tbody.innerHTML = '';
        if (pageSpots.length === 0) {
            return;
        } else {
            pageSpots.forEach(function (s) {
                var pillClass = s.level === 'high' ? 'is-danger' : (s.level === 'moderate' ? 'is-warn' : 'is-success');
                var tr = document.createElement('tr');
                tr.innerHTML =
                    '<td style="font-weight:600;">' + escapeHtml(s.code) + '</td>' +
                    '<td>' + escapeHtml(s.purok) + '</td>' +
                    '<td>' + s.child_count + '</td>' +
                    '<td>' + s.normal + '</td>' +
                    '<td>' + s.moderate + '</td>' +
                    '<td>' + s.severe + '</td>' +
                    '<td><span class="admin-pill ' + pillClass + '">' + escapeHtml(s.level_label) + '</span></td>' +
                    '<td><button class="admin-spotmap-view-btn" data-spot-id="' + s.id + '" title="View"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:14px;height:14px;vertical-align:-2px"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg> View</button></td>';
                tbody.appendChild(tr);
            });
        }

        tbody.querySelectorAll('.admin-spotmap-view-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var spotId = parseInt(this.dataset.spotId, 10);
                var spot = SPOTS.find(function (s) { return s.id === spotId; });
                if (spot && spot.lat && spot.lng) {
                    map.setView([spot.lat, spot.lng], 16);
                    openSpotPanel(spot);
                    openEditModal(spot);
                }
            });
        });

        if (paginationEl) {
            paginationEl.innerHTML = '';
            var infoSpan = document.createElement('span');
            infoSpan.className = 'admin-table-page-info';
            infoSpan.id = 'spot-summary-count';
            if (total > 0) {
                infoSpan.innerHTML = 'Showing ' + (start + 1) + '&ndash;' + end + ' of ' + total + ' spots';
                paginationEl.appendChild(infoSpan);
            }

            if (totalPages <= 1) return;

            var pagesWrap = document.createElement('div');
            pagesWrap.className = 'admin-table-pages';

            var prevBtn = document.createElement('button');
            prevBtn.className = 'admin-table-page-btn';
            prevBtn.innerHTML = '&lsaquo;';
            prevBtn.disabled = currentPage <= 1;
            prevBtn.addEventListener('click', function () { currentPage--; renderSummaryTable(); });
            pagesWrap.appendChild(prevBtn);

            for (var i = 1; i <= totalPages; i++) {
                if (totalPages > 7 && i > 3 && i < totalPages - 1 && Math.abs(i - currentPage) > 1) {
                    if (i === 4 || i === totalPages - 2) {
                        var dots = document.createElement('span');
                        dots.className = 'admin-table-page-dots';
                        dots.textContent = '...';
                        pagesWrap.appendChild(dots);
                    }
                    continue;
                }
                var pageBtn = document.createElement('button');
                pageBtn.className = 'admin-table-page-btn' + (i === currentPage ? ' is-active' : '');
                pageBtn.textContent = i;
                pageBtn.dataset.page = i;
                pageBtn.addEventListener('click', function () {
                    currentPage = parseInt(this.dataset.page, 10);
                    renderSummaryTable();
                });
                pagesWrap.appendChild(pageBtn);
            }

            var nextBtn = document.createElement('button');
            nextBtn.className = 'admin-table-page-btn';
            nextBtn.innerHTML = '&rsaquo;';
            nextBtn.disabled = currentPage >= totalPages;
            nextBtn.addEventListener('click', function () { currentPage++; renderSummaryTable(); });
            pagesWrap.appendChild(nextBtn);

            paginationEl.appendChild(pagesWrap);
        }
    }

    function formatAge(months) {
        if (!months && months !== 0) return '';
        if (months < 12) return months + ' mo';
        var years = Math.floor(months / 12);
        var rem = months % 12;
        return rem === 0 ? years + ' yr' : years + ' yr ' + rem + ' mo';
    }

    function openSpotPanel(spot) {
        var panel = document.getElementById('spot-panel');
        var body = document.getElementById('spot-panel-body');
        var closeBtn = document.getElementById('spot-panel-close');
        if (!panel || !body) return;

        panel.classList.add('is-active');
        if (closeBtn) closeBtn.style.display = 'inline-flex';

        var pillClass = spot.level === 'high' ? 'is-danger' : (spot.level === 'moderate' ? 'is-warn' : 'is-success');

        var spotCode = spot.code || ('HH-' + String(spot.id).padStart(4, '0'));

        var html = '<div class="admin-spotmap-panel-spot-header">';
        html += '<span class="admin-spotmap-panel-spot-code">' + escapeHtml(spotCode) + '</span>';
        html += '<span class="admin-pill ' + pillClass + '" style="margin-left:auto;">' + (spot.level === 'high' ? 'AT RISK' : escapeHtml(spot.level_label)) + '</span>';
        html += '</div>';
        html += '<div class="admin-spotmap-panel-meta">';
        html += '<div class="admin-spotmap-panel-meta-row"><span>Purok</span><strong>' + escapeHtml(spot.purok) + '</strong></div>';
        html += '<div class="admin-spotmap-panel-meta-row"><span>Household Code</span><strong>' + escapeHtml(spotCode) + '</strong></div>';
        html += '<div class="admin-spotmap-panel-meta-row"><span>Barangay</span><strong>' + escapeHtml(spot.barangay) + '</strong></div>';
        html += '</div>';

        html += '<div id="spot-panel-loading" style="text-align:center;padding:16px;color:var(--admin-muted);font-size:11px;">';
        html += 'Loading household members…';
        html += '</div>';
        html += '<div id="spot-panel-detail" style="display:none;"></div>';

        body.innerHTML = html;

        loadSpotDetails(spot.id, spot);
    }

    function loadSpotDetails(householdId, fallbackSpot) {
        var detailEl = document.getElementById('spot-panel-detail');
        var loadingEl = document.getElementById('spot-panel-loading');
        if (!detailEl) return;

        fetch(BASE_URL + 'api/households/get_details.php?id=' + householdId, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) {
                    if (loadingEl) loadingEl.textContent = res.message || 'Failed to load.';
                    return;
                }
                if (loadingEl) loadingEl.style.display = 'none';
                detailEl.style.display = 'block';
                detailEl.innerHTML = renderSpotDetail(res);
                wireSpotDetailEvents(householdId);
            })
            .catch(function () {
                if (loadingEl) loadingEl.textContent = 'Network error loading household details.';
            });
    }

    function renderSpotDetail(res) {
        var h = res.household;
        var children = res.children || [];
        var parents = res.parents || [];
        var summary = res.summary || {};

        var html = '';

        html += '<h4 class="admin-spotmap-panel-section-title">';
        html += '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:12px;height:12px;vertical-align:-2px;margin-right:3px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>';
        html += 'Parents / Guardians <span style="color:var(--admin-muted);font-weight:500;">(' + parents.length + ')</span>';
        html += '</h4>';

        if (parents.length > 0) {
            html += '<div class="admin-spotmap-person-list">';
            parents.forEach(function (p) {
                var meta = p.parent_type || 'Guardian';
                if (p.phone) meta += ' · ' + p.phone;
                html += '<div class="admin-spotmap-person" data-parent-id="' + p.id + '">';
                html += '<div class="admin-spotmap-person-info">';
                html += '<div class="admin-spotmap-person-name">' + escapeHtml(p.name) + '</div>';
                html += '<div class="admin-spotmap-person-meta">' + escapeHtml(meta) + '</div>';
                html += '</div>';
                html += '<button class="admin-spotmap-person-remove" data-action="unassign-parent" data-id="' + p.id + '" title="Remove from household">&times;</button>';
                html += '</div>';
            });
            html += '</div>';
        } else {
            html += '<div class="admin-spotmap-empty">No parents assigned yet.</div>';
        }
        html += '<button class="admin-spotmap-add-person" data-action="open-assign-parents" data-hh="' + h.id + '">+ Add Parent</button>';

        html += '<h4 class="admin-spotmap-panel-section-title">';
        html += '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:12px;height:12px;vertical-align:-2px;margin-right:3px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/></svg>';
        html += 'Children <span style="color:var(--admin-muted);font-weight:500;">(' + children.length + ')</span>';
        html += '</h4>';

        if (children.length > 0) {
            html += '<div class="admin-spotmap-person-list">';
            children.forEach(function (ch) {
                var dotColor = STATUS_DOT_COLORS[ch.status] || '#94a3b8';
                var meta = (ch.sex || '') + (ch.age_months ? ' · ' + formatAge(ch.age_months) : '');
                if (ch.code) meta += ' · ' + ch.code;
                html += '<div class="admin-spotmap-person" data-child-id="' + ch.id + '">';
                html += '<div class="admin-spotmap-person-info">';
                html += '<div class="admin-spotmap-person-name"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' + dotColor + ';margin-right:6px;vertical-align:middle;"></span>' + escapeHtml(ch.name) + '</div>';
                html += '<div class="admin-spotmap-person-meta">' + escapeHtml(meta) + ' · ' + escapeHtml(ch.status) + '</div>';
                html += '</div>';
                html += '<a href="' + BASE_URL + 'nutritionist/children.php?id=' + ch.id + '" class="admin-spotmap-person-remove" title="View child" style="text-decoration:none;color:inherit;">→</a>';
                html += '</div>';
            });
            html += '</div>';
        } else {
            html += '<div class="admin-spotmap-empty">No children assigned yet.</div>';
        }
        html += '<button class="admin-spotmap-add-person" data-action="open-assign-children" data-hh="' + h.id + '">+ Add Child</button>';

        if (children.length > 0 || parents.length > 0) {
            html += '<div class="admin-spotmap-panel-actions">';
            html += '<a href="' + BASE_URL + 'nutritionist/children.php?household_id=' + h.id + '" class="admin-btn admin-btn-sm" style="flex:1;text-align:center;background:var(--admin-surface);color:var(--admin-text);border:1px solid var(--admin-border);text-decoration:none;">View Full Record</a>';
            html += '</div>';
        }

        return html;
    }

    function wireSpotDetailEvents(householdId) {
        document.querySelectorAll('[data-action="unassign-parent"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!confirm('Remove this parent from the household?')) return;
                var parentId = parseInt(this.dataset.id, 10);
                var fd = new FormData();
                fd.append('parent_id', parentId);
                fetch(BASE_URL + 'api/households/unassign_parent.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res.success) loadSpotDetails(householdId);
                        else alert(res.message || 'Failed to unassign.');
                    })
                    .catch(function () { alert('Network error.'); });
            });
        });

        document.querySelectorAll('[data-action="open-assign-children"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openAssignPersonsModal('children', this.dataset.hh);
            });
        });
        document.querySelectorAll('[data-action="open-assign-parents"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openAssignPersonsModal('parents', this.dataset.hh);
            });
        });
    }

    function openAssignPersonsModal(type, householdId) {
        var modalId = type === 'children' ? 'assign-children-modal' : 'assign-parents-modal';
        var modal = document.getElementById(modalId);
        var list = document.getElementById(type === 'children' ? 'assign-children-list' : 'assign-parents-list');
        var hhField = document.getElementById(type === 'children' ? 'assign-children-hh' : 'assign-parents-hh');
        if (!modal || !list) return;
        hhField.value = householdId;
        list.innerHTML = '<p class="admin-mini" style="color:var(--admin-muted);text-align:center;padding:20px;">Loading…</p>';
        modal.style.display = 'flex';

        fetch(BASE_URL + 'api/households/available_persons.php?type=' + type + '&household_id=' + householdId, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) {
                    list.innerHTML = '<p class="admin-mini" style="color:var(--admin-muted);text-align:center;padding:20px;">' + escapeHtml(res.message || 'Failed to load.') + '</p>';
                    return;
                }
                if (res.data.length === 0) {
                    list.innerHTML = '<p class="admin-mini" style="color:var(--admin-muted);text-align:center;padding:20px;">No available ' + type + ' to assign.</p>';
                    return;
                }
                var html = '';
                res.data.forEach(function (p) {
                    var meta = '';
                    if (type === 'children') {
                        meta = (p.sex || '') + (p.age_months ? ' · ' + formatAge(p.age_months) : '') + (p.parent_name ? ' · Guardian: ' + p.parent_name : '');
                    } else {
                        meta = p.parent_type || 'Guardian';
                        if (p.phone) meta += ' · ' + p.phone;
                    }
                    var fieldName = type === 'children' ? 'child_ids[]' : 'parent_ids[]';
                    html += '<label class="admin-spotmap-pick-item">';
                    html += '<input type="checkbox" name="' + fieldName + '" value="' + p.id + '">';
                    html += '<div class="admin-spotmap-pick-info">';
                    html += '<div class="admin-spotmap-pick-name">' + escapeHtml(p.name) + '</div>';
                    html += '<div class="admin-spotmap-pick-meta">' + escapeHtml(meta) + '</div>';
                    html += '</div>';
                    html += '</label>';
                });
                list.innerHTML = html;
            })
            .catch(function () {
                list.innerHTML = '<p class="admin-mini" style="color:#ef4444;text-align:center;padding:20px;">Network error.</p>';
            });
    }

    function submitAssignForm(type) {
        var form = document.getElementById(type === 'children' ? 'assign-children-form' : 'assign-parents-form');
        var modalId = type === 'children' ? 'assign-children-modal' : 'assign-parents-modal';
        if (!form) return;

        var householdId = form.querySelector('[name="household_id"]').value;
        var fieldName = type === 'children' ? 'child_ids[]' : 'parent_ids[]';
        var checked = form.querySelectorAll('[name="' + fieldName + '"]:checked');
        if (checked.length === 0) {
            alert('Please select at least one ' + (type === 'children' ? 'child' : 'parent') + '.');
            return;
        }

        var fd = new FormData();
        fd.append('household_id', householdId);
        checked.forEach(function (cb) { fd.append(fieldName, cb.value); });

        var apiUrl = BASE_URL + 'api/households/assign_' + type + '.php';
        fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    document.getElementById(modalId).style.display = 'none';
                    form.reset();
                    loadSpotDetails(parseInt(householdId, 10));
                } else {
                    alert(res.message || 'Failed to assign.');
                }
            })
            .catch(function () { alert('Network error.'); });
    }

    function openEditModal(spot) {
        var modal = document.getElementById('spot-add-modal');
        var title = document.getElementById('spot-modal-title');
        var form = document.getElementById('spot-add-form');
        var idField = document.getElementById('spot-form-id');

        title.textContent = 'Edit Spot';
        idField.value = spot.id;

        var codePreview = document.getElementById('spot-form-code-preview');
        if (codePreview) codePreview.value = spot.code || '';

        form.querySelector('[name="address"]').value = '';
        form.querySelector('[name="lat"]').value = spot.lat !== null ? spot.lat : '';
        form.querySelector('[name="lng"]').value = spot.lng !== null ? spot.lng : '';

        var localSelect = form.querySelector('[name="local_area_id"]');
        if (localSelect) localSelect.value = '';

        modal.style.display = 'flex';
    }

    function resetAddModal() {
        var title = document.getElementById('spot-modal-title');
        var form = document.getElementById('spot-add-form');
        var idField = document.getElementById('spot-form-id');

        title.textContent = 'Add New Spot';
        idField.value = '';

        form.reset();

        var codePreview = document.getElementById('spot-form-code-preview');
        if (codePreview) codePreview.value = 'Auto-generated on save';
    }

    var map;
    var spotMarkerGroup;

    function renderMapMarkers() {
        if (!spotMarkerGroup) return;
        spotMarkerGroup.clearLayers();

        filteredSpots.forEach(function (spot) {
            if (spot.lat === null || spot.lng === null) return;

            var color = getSpotColor(spot);

            var marker = L.circleMarker([spot.lat, spot.lng], {
                radius: 7,
                fillColor: color,
                color: '#fff',
                weight: 2,
                opacity: 1,
                fillOpacity: 0.9
            });

            marker.on('click', function () {
                openSpotPanel(spot);
                openEditModal(spot);
                if (activeMarker && activeMarker !== marker) {
                    activeMarker.setStyle({ radius: 7, weight: 2, color: '#fff' });
                }
                marker.setStyle({ radius: 10, weight: 3, color: '#111' });
                activeMarker = marker;
            });

            marker.on('mouseover', function () {
                if (marker === activeMarker) return;
                marker.setStyle({ radius: 10, weight: 3, color: '#111', fillOpacity: 1 });
                marker.openTooltip();
            });

            marker.on('mouseout', function () {
                if (marker === activeMarker) return;
                marker.setStyle({ radius: 7, weight: 2, color: '#fff', fillOpacity: 0.9 });
                marker.closeTooltip();
            });

            var tooltip = '<strong>' + escapeHtml(spot.code) + '</strong>';
            marker.bindTooltip(tooltip, { direction: 'top', offset: [0, -8] });

            spotMarkerGroup.addLayer(marker);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var mapEl = document.getElementById('spot-map');
        if (!mapEl || typeof L === 'undefined') return;

        map = L.map(mapEl, {
            scrollWheelZoom: true,
            wheelDebounceTime: 60,
            wheelPxPerZoomLevel: 180,
            zoomDelta: 0.5,
            zoomSnap: 0.5,
            inertia: true,
            zoomControl: false
        }).setView([15.034, 120.686], 13);
        L.control.zoom({ position: 'bottomright' }).addTo(map);

        var satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            maxZoom: 19,
            attribution: 'Tiles &copy; Esri, Maxar, Earthstar Geographics'
        });
        var street = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        });
        var terrain = L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {
            maxZoom: 17,
            attribution: '&copy; OpenStreetMap contributors, SRTM &mdash; &copy; OpenTopoMap (CC-BY-SA)'
        });

        var activeBase = satellite.addTo(map);

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
                    if (opt.key === 'satellite') btn.classList.add('is-active');
                    L.DomEvent.on(btn, 'click', function (e) {
                        L.DomEvent.stopPropagation(e);
                        if (baseLayers[opt.key] === activeBase) return;
                        map.removeLayer(activeBase);
                        activeBase = baseLayers[opt.key].addTo(map);
                        container.querySelectorAll('.admin-riskmap-basemap-btn').forEach(function (b) {
                            b.classList.toggle('is-active', b.dataset.basemap === opt.key);
                        });
                    });
                });
                L.DomEvent.disableClickPropagation(container);
                L.DomEvent.disableScrollPropagation(container);
                return container;
            }
        });

        var baseLayers = { street: street, satellite: satellite, terrain: terrain };
        new BasemapGallery().addTo(map);

        spotMarkerGroup = L.layerGroup().addTo(map);

        renderMapMarkers();

        fetch(GEOJSON_URL)
            .then(function (r) { return r.json(); })
            .then(function (geojson) {
                var geoLayer = L.geoJSON(geojson, {
                    style: function () {
                        return { color: '#22c55e', weight: 2, dashArray: '6 4', fillOpacity: 0, fillColor: 'transparent' };
                    },
                    onEachFeature: function (feature, layer) {
                        var name = feature.properties.name || 'Unknown';
                        var norm = normalizeBarangayName(name);
                        barangayLayers[norm] = layer;

                        layer.bindTooltip('<strong>' + escapeHtml(name) + '</strong>', { sticky: true, className: 'admin-spotmap-brgy-tooltip' });

                        layer.on('mouseover', function () {
                            layer.setStyle({ weight: 3.5, color: '#4ade80', fillOpacity: 0.08, fillColor: '#22c55e' });
                        });
                        layer.on('mouseout', function () {
                            geoLayer.resetStyle(layer);
                        });
                    }
                }).addTo(map);

                map.fitBounds(geoLayer.getBounds(), { padding: [16, 16] });

                if (IS_LOCKED && LOCKED_BARANGAY_NAME) {
                    activeLockedLayer = findBarangayLayer(LOCKED_BARANGAY_NAME);
                }
            })
            .catch(function () {});

        document.getElementById('filter-barangay').addEventListener('change', applyFilters);
        document.getElementById('filter-risk').addEventListener('change', applyFilters);
        document.getElementById('filter-indicator').addEventListener('change', applyFilters);

        var searchInput = document.getElementById('spot-summary-search');
        var searchClear = document.getElementById('spot-summary-search-clear');
        if (searchInput) {
            var searchTimer;
            searchInput.addEventListener('input', function () {
                if (searchClear) searchClear.style.display = searchInput.value ? 'inline-flex' : 'none';
                clearTimeout(searchTimer);
                searchTimer = setTimeout(applyFilters, 120);
            });
            searchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { searchInput.value = ''; if (searchClear) searchClear.style.display = 'none'; applyFilters(); }
            });
        }
        if (searchClear) {
            searchClear.addEventListener('click', function () {
                if (searchInput) { searchInput.value = ''; searchInput.focus(); }
                searchClear.style.display = 'none';
                applyFilters();
            });
        }

        document.getElementById('filter-clear').addEventListener('click', function () {
            var brgyEl = document.getElementById('filter-barangay');
            if (!brgyEl.disabled) brgyEl.value = '';
            document.getElementById('filter-risk').value = '';
            document.getElementById('filter-indicator').value = '';
            document.getElementById('filter-date-from').value = '';
            document.getElementById('filter-date-to').value = '';
            if (searchInput) {
                searchInput.value = '';
                if (searchClear) searchClear.style.display = 'none';
            }
            applyFilters();
        });

        document.getElementById('spot-panel-close').addEventListener('click', function () {
            var panel = document.getElementById('spot-panel');
            var body = document.getElementById('spot-panel-body');
            if (panel) {
                panel.classList.remove('is-active');
                this.style.display = 'none';
            }
            if (body) {
                body.innerHTML = '<div class="admin-spotmap-empty" style="margin-top:40px;">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:32px;height:32px;opacity:.3;display:block;margin:0 auto 8px;">' +
                    '<path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>' +
                    '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>' +
                    'Click a household marker on the map to view details.</div>';
            }
            if (activeMarker) {
                activeMarker.setStyle({ radius: 7, weight: 2, color: '#fff', fillOpacity: 0.9 });
                activeMarker = null;
            }
        });

        document.getElementById('assign-children-form').addEventListener('submit', function (e) {
            e.preventDefault();
            submitAssignForm('children');
        });
        document.getElementById('assign-parents-form').addEventListener('submit', function (e) {
            e.preventDefault();
            submitAssignForm('parents');
        });

        document.querySelectorAll('[data-close-modal]').forEach(function (el) {
            el.addEventListener('click', function () {
                var modalId = this.dataset.closeModal;
                var modal = document.getElementById(modalId);
                if (modal) modal.style.display = 'none';
                if (modalId === 'spot-add-modal') {
                    resetAddModal();
                    if (tempPin) { map.removeLayer(tempPin); tempPin = null; }
                }
            });
        });

        document.getElementById('spotmap-add-btn').addEventListener('click', function () {
            resetAddModal();
            document.getElementById('spot-add-modal').style.display = 'flex';
        });

        document.getElementById('spotmap-import-btn').addEventListener('click', function () {
            document.getElementById('spot-import-modal').style.display = 'flex';
        });

        var tempPin = null;
        var boundaryWarnTimer = null;

        function showBoundaryWarn(message) {
            var warn = document.getElementById('spot-boundary-warn');
            var text = document.getElementById('spot-boundary-warn-text');
            var coordText = document.getElementById('spot-coord-text');
            if (!warn || !text) return;

            text.textContent = message;
            warn.style.display = 'flex';
            warn.style.animation = 'none';
            void warn.offsetWidth;
            warn.style.animation = '';

            if (coordText) {
                coordText.style.color = '#b91c1c';
            }

            if (boundaryWarnTimer) clearTimeout(boundaryWarnTimer);
            boundaryWarnTimer = setTimeout(function () {
                warn.style.display = 'none';
                if (coordText) coordText.style.color = '';
            }, 3500);
        }

        map.on('click', function (e) {
            var lat = e.latlng.lat.toFixed(7);
            var lng = e.latlng.lng.toFixed(7);

            var coordText = document.getElementById('spot-coord-text');
            if (coordText) {
                coordText.textContent = lat + ', ' + lng;
                coordText.style.color = '';
            }

            if (IS_LOCKED && activeLockedLayer) {
                var inside = false;
                try {
                    if (typeof activeLockedLayer.contains === 'function') {
                        inside = activeLockedLayer.contains(e.latlng);
                    } else if (typeof activeLockedLayer.getBounds === 'function') {
                        inside = activeLockedLayer.getBounds().contains(e.latlng);
                    }
                } catch (err) {
                    inside = true;
                }

                if (!inside) {
                    showBoundaryWarn('Selected location is outside ' + LOCKED_BARANGAY_NAME + ' boundary. Spots can only be added within the assigned barangay.');
                    if (tempPin) { map.removeLayer(tempPin); tempPin = null; }
                    return;
                }
            }

            if (tempPin) {
                map.removeLayer(tempPin);
            }
            tempPin = L.circleMarker([e.latlng.lat, e.latlng.lng], {
                radius: 8,
                fillColor: '#ef4444',
                color: '#fff',
                weight: 3,
                opacity: 1,
                fillOpacity: 0.9
            }).addTo(map);
            tempPin.bindTooltip(lat + ', ' + lng, {direction: 'top', offset: [0, -10]}).openTooltip();

            resetAddModal();
            var form = document.getElementById('spot-add-form');
            form.querySelector('[name="lat"]').value = lat;
            form.querySelector('[name="lng"]').value = lng;
            document.getElementById('spot-add-modal').style.display = 'flex';
        });

        document.getElementById('spot-add-form').addEventListener('submit', function (e) {
            e.preventDefault();

            if (IS_LOCKED && activeLockedLayer) {
                var latInput = this.querySelector('[name="lat"]').value;
                var lngInput = this.querySelector('[name="lng"]').value;
                if (latInput && lngInput) {
                    var lat = parseFloat(latInput);
                    var lng = parseFloat(lngInput);
                    var inside = false;
                    try {
                        if (typeof activeLockedLayer.contains === 'function') {
                            inside = activeLockedLayer.contains(L.latLng(lat, lng));
                        } else if (typeof activeLockedLayer.getBounds === 'function') {
                            inside = activeLockedLayer.getBounds().contains(L.latLng(lat, lng));
                        }
                    } catch (err) {
                        inside = true;
                    }
                    if (!inside) {
                        showBoundaryWarn('Coordinates are outside ' + LOCKED_BARANGAY_NAME + ' boundary. Please use coordinates within the assigned barangay.');
                        return;
                    }
                }
            }

            var formData = new FormData(this);
            var editId = document.getElementById('spot-form-id').value;
            formData.append('barangay_id', <?php echo json_encode($user['barangay_id'] ?? 0); ?>);

            var apiUrl = editId
                ? BASE_URL + 'api/households/update.php'
                : BASE_URL + 'api/households/create.php';

            fetch(apiUrl, {
                method: 'POST',
                body: formData
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    document.getElementById('spot-add-modal').style.display = 'none';
                    resetAddModal();
                    if (tempPin) { map.removeLayer(tempPin); tempPin = null; }
                    location.reload();
                } else {
                    alert(res.message || 'Failed to add spot.');
                }
            })
            .catch(function () { alert('Network error. Please try again.'); });
        });

        document.getElementById('spot-import-form').addEventListener('submit', function (e) {
            e.preventDefault();
            var formData = new FormData(this);
            formData.append('barangay_id', <?php echo json_encode($user['barangay_id'] ?? 0); ?>);

            fetch(BASE_URL + 'api/households/import.php', {
                method: 'POST',
                body: formData
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    document.getElementById('spot-import-modal').style.display = 'none';
                    location.reload();
                } else {
                    alert(res.message || 'Failed to import spots.');
                }
            })
            .catch(function () { alert('Network error. Please try again.'); });
        });

        renderSummaryTable();
    });
})();
</script>
<?php
nutritionist_layout_end();

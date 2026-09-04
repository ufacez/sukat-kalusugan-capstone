<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth_middleware.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
$user = current_user();

if (($user['type'] ?? '') !== 'staff' || !in_array($user['role'] ?? '', ['admin', 'nutritionist'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Household ID is required.']);
    exit;
}

$conn = get_db_connection();

$hh = admin_fetch_one(
    'SELECT h.id, h.household_code, h.address, h.lat, h.lng, h.barangay_id, h.local_area_id,
            b.name AS barangay_name, la.area_name AS purok_name
       FROM households h
       INNER JOIN barangays b ON b.id = h.barangay_id
       LEFT JOIN local_areas la ON la.id = h.local_area_id
      WHERE h.id = ? AND h.status = "active"
      LIMIT 1',
    'i',
    [$id]
);

if (!$hh) {
    echo json_encode(['success' => false, 'message' => 'Household not found.']);
    exit;
}

$isBarangayAdmin = ($user['role'] ?? '') === 'admin';
$userBarangayId = $user['barangay_id'] ?? null;
if (!$isBarangayAdmin && $userBarangayId !== null && (int)$hh['barangay_id'] !== (int)$userBarangayId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have access to this household.']);
    exit;
}

$children = admin_fetch_all(
    "SELECT c.id, c.child_code, c.first_name, c.middle_name, c.last_name, c.sex, c.birthdate,
            TIMESTAMPDIFF(MONTH, c.birthdate, CURDATE()) AS age_months
       FROM children c
      WHERE c.household_id = ? AND c.status = 'active'
      ORDER BY c.first_name, c.last_name",
    'i',
    [$id]
);

$childIds = array_map(static fn($c) => (int)$c['id'], $children);

$measurementMap = [];
if (!empty($childIds)) {
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
        $cid = (int)$mr['child_id'];
        $measurementMap[$cid] = [
            'measurement_date' => $mr['measurement_date'],
            'waz' => $mr['waz'] !== null ? (float)$mr['waz'] : null,
            'haz' => $mr['haz'] !== null ? (float)$mr['haz'] : null,
            'whz' => $mr['whz'] !== null ? (float)$mr['whz'] : null,
            'status' => $mr['nutritional_status'] ?? 'Normal',
        ];
    }
}

$childList = [];
$normalCount = 0;
$moderateCount = 0;
$severeCount = 0;
$overweightCount = 0;
$worstLevel = 'normal';

foreach ($children as $c) {
    $cid = (int)$c['id'];
    $m = $measurementMap[$cid] ?? null;
    $status = $m['status'] ?? 'Unmeasured';
    $level = 'normal';
    if (in_array($status, ['Severely Underweight', 'Severely Stunted', 'Severely Wasted'], true)) {
        $level = 'severe';
        $severeCount++;
        if ($worstLevel !== 'severe') $worstLevel = 'severe';
    } elseif (in_array($status, ['Moderately Underweight', 'Moderately Stunted', 'Moderately Wasted'], true)) {
        $level = 'moderate';
        $moderateCount++;
        if ($worstLevel === 'normal') $worstLevel = 'moderate';
    } elseif (in_array($status, ['Overweight', 'Obese'], true)) {
        $level = 'overweight';
        $overweightCount++;
        if ($worstLevel === 'normal') $worstLevel = 'overweight';
    } else {
        $normalCount++;
    }

    $childList[] = [
        'id' => $cid,
        'code' => $c['child_code'] ?? '',
        'name' => trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')),
        'middle_name' => $c['middle_name'] ?? '',
        'sex' => $c['sex'] ?? '',
        'birthdate' => $c['birthdate'] ?? '',
        'age_months' => (int)($c['age_months'] ?? 0),
        'status' => $status,
        'level' => $level,
        'waz' => $m['waz'] ?? null,
        'haz' => $m['haz'] ?? null,
        'whz' => $m['whz'] ?? null,
    ];
}

$parents = admin_fetch_all(
    'SELECT p.id, p.name, p.email, p.phone, p.parent_type, p.address
       FROM parents p
      WHERE p.household_id = ? AND p.status = "active"
      ORDER BY p.name',
    'i',
    [$id]
);

$parentList = [];
foreach ($parents as $p) {
    $parentList[] = [
        'id' => (int)$p['id'],
        'name' => $p['name'] ?? '',
        'email' => $p['email'] ?? '',
        'phone' => $p['phone'] ?? '',
        'parent_type' => $p['parent_type'] ?? '',
        'address' => $p['address'] ?? '',
    ];
}

$riskLevel = 'low';
$riskLabel = 'Low';
if ($severeCount > 0) {
    $riskLevel = 'high';
    $riskLabel = 'High';
} elseif ($moderateCount > 0 || $overweightCount > 0) {
    $riskLevel = 'moderate';
    $riskLabel = 'Moderate';
}

echo json_encode([
    'success' => true,
    'household' => [
        'id' => (int)$hh['id'],
        'code' => $hh['household_code'],
        'address' => $hh['address'] ?? '',
        'barangay' => $hh['barangay_name'],
        'purok' => $hh['purok_name'] ?? 'Unassigned',
        'lat' => $hh['lat'] !== null ? (float)$hh['lat'] : null,
        'lng' => $hh['lng'] !== null ? (float)$hh['lng'] : null,
    ],
    'children' => $childList,
    'parents' => $parentList,
    'summary' => [
        'child_count' => count($childList),
        'parent_count' => count($parentList),
        'normal' => $normalCount,
        'moderate' => $moderateCount,
        'severe' => $severeCount,
        'overweight' => $overweightCount,
        'risk_level' => $riskLevel,
        'risk_label' => $riskLabel,
    ],
]);

<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/kiosk_helpers.php';

api_require_method(['GET']);

$deviceCode = api_string(
    $_GET['device']
        ?? $_GET['device_id']
        ?? $_GET['deviceCode']
        ?? 'ESP32-KIOSK-01',
    'ESP32-KIOSK-01'
);

if (!preg_match('/^[A-Za-z0-9_-]{3,50}$/', $deviceCode)) {
    api_error('Invalid device ID.', 400);
}

$conn = get_db_connection();

$kioskBarangay = kiosk_resolve_device_barangay($deviceCode);

if ($kioskBarangay !== null) {
    $childrenScopeSql = ' WHERE c.barangay_id = ? AND c.status = ? AND TIMESTAMPDIFF(MONTH, c.birthdate, CURDATE()) <= 59';
    $childrenScopeParams = [$kioskBarangay['id'], 'active'];
    $childrenScopeTypes = 'is';
} else {
    $childrenScopeSql = ' WHERE c.status = ? AND TIMESTAMPDIFF(MONTH, c.birthdate, CURDATE()) <= 59';
    $childrenScopeParams = ['active'];
    $childrenScopeTypes = 's';
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

api_success(
    ['children' => $childrenPayload],
    'Children list loaded.'
);
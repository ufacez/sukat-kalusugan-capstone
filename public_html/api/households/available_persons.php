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

$type = $_GET['type'] ?? '';
$householdId = isset($_GET['household_id']) ? (int)$_GET['household_id'] : 0;

if (!in_array($type, ['children', 'parents'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid type.']);
    exit;
}
if ($householdId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Household ID is required.']);
    exit;
}

$conn = get_db_connection();

$hh = admin_fetch_one(
    'SELECT barangay_id FROM households WHERE id = ? AND status = "active" LIMIT 1',
    'i',
    [$householdId]
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

if ($type === 'children') {
    $rows = admin_fetch_all(
        "SELECT c.id, c.child_code, c.first_name, c.middle_name, c.last_name, c.sex, c.birthdate,
                TIMESTAMPDIFF(MONTH, c.birthdate, CURDATE()) AS age_months,
                p.name AS parent_name
           FROM children c
           LEFT JOIN parents p ON p.id = c.parent_id
          WHERE c.barangay_id = ?
            AND c.status = 'active'
            AND (c.household_id IS NULL OR c.household_id = 0 OR c.household_id <> ?)
          ORDER BY c.first_name, c.last_name",
        'ii',
        [(int)$hh['barangay_id'], $householdId]
    );

    $list = array_map(static function ($r) {
        return [
            'id' => (int)$r['id'],
            'code' => $r['child_code'] ?? '',
            'name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
            'sex' => $r['sex'] ?? '',
            'age_months' => (int)($r['age_months'] ?? 0),
            'parent_name' => $r['parent_name'] ?? '',
        ];
    }, $rows);

    echo json_encode(['success' => true, 'data' => $list]);
    exit;
}

$rows = admin_fetch_all(
    "SELECT id, name, email, phone, parent_type
       FROM parents
      WHERE barangay_id = ?
        AND status = 'active'
        AND (household_id IS NULL OR household_id = 0 OR household_id <> ?)
      ORDER BY name",
    'ii',
    [(int)$hh['barangay_id'], $householdId]
);

$list = array_map(static function ($r) {
    return [
        'id' => (int)$r['id'],
        'name' => $r['name'] ?? '',
        'email' => $r['email'] ?? '',
        'phone' => $r['phone'] ?? '',
        'parent_type' => $r['parent_type'] ?? '',
    ];
}, $rows);

echo json_encode(['success' => true, 'data' => $list]);

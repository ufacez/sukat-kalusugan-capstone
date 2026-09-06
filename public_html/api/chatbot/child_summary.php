<?php
/**
 * api/chatbot/child_summary.php
 *
 * Returns child details + latest measurement summary for the AI assistant
 * sidebar panel. Lightweight endpoint — just enough data for the UI.
 *
 * GET ?child_id=123
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/auth_middleware.php';
require_once __DIR__ . '/../../includes/who_calculator.php';

api_require_method(['GET']);

start_secure_session();
$user = current_user();

if ($user === null) {
    api_error('Please sign in to continue.', 401);
}

$userType = (string)($user['type'] ?? '');

if ($userType === 'parent') {
    $user = api_require_parent_session();
} elseif ($userType === 'staff') {
    $user = api_require_staff_session(['admin', 'nutritionist']);
} else {
    api_error('Unauthorized.', 403);
}

$childId = api_int($_GET['child_id'] ?? null);

if ($childId === null || $childId <= 0) {
    api_error('Child ID is required.');
}

$db = get_db_connection();


/* -----------------------------------------------------------------------
 * Load child
 * ----------------------------------------------------------------------- */
$sql = 'SELECT c.id, c.child_code, c.first_name, c.last_name, c.sex,
               c.birthdate, c.barangay_id, c.parent_id,
               b.name AS barangay_name
        FROM children c
        LEFT JOIN barangays b ON b.id = c.barangay_id
        WHERE c.id = ?';
$stmt = mysqli_prepare($db, $sql);
mysqli_stmt_bind_param($stmt, 'i', $childId);
mysqli_stmt_execute($stmt);
$child = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if ($child === null) {
    api_error('Child not found.', 404);
}


/* -----------------------------------------------------------------------
 * Authorization: parents can only see their own children
 * ----------------------------------------------------------------------- */
if ($userType === 'parent') {
    $parentId = (int)($user['id'] ?? 0);
    if ((int)$child['parent_id'] !== $parentId && !empty($child['parent_id'])) {
        api_error('Access denied.', 403);
    }
}


/* -----------------------------------------------------------------------
 * Compute age
 * ----------------------------------------------------------------------- */
$age = doh_age($child['birthdate'] ?? null);
$ageText = 'unknown';
if ($age !== null) {
    $ageText = $age['days'] . ' days (~' . $age['months'] . ' months)';
}


/* -----------------------------------------------------------------------
 * Load latest measurement
 * ----------------------------------------------------------------------- */
$sql = 'SELECT measurement_date, height_cm, weight_kg,
               waz, haz, whz,
               nutritional_status, wfa_status, hfa_status, wfh_status,
               is_flagged, flag_reason
        FROM measurements
        WHERE child_id = ?
        ORDER BY measurement_date DESC, id DESC
        LIMIT 1';
$stmt = mysqli_prepare($db, $sql);
mysqli_stmt_bind_param($stmt, 'i', $childId);
mysqli_stmt_execute($stmt);
$measurement = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);


/* -----------------------------------------------------------------------
 * Build response
 * ----------------------------------------------------------------------- */
$fullName = trim($child['first_name'] . ' ' . $child['last_name']);

$response = [
    'child' => [
        'id'           => (int)$child['id'],
        'child_code'   => $child['child_code'],
        'name'         => $fullName !== '' ? $fullName : 'Unknown',
        'sex'          => $child['sex'],
        'age'          => $ageText,
        'age_months'   => $age !== null ? (int)$age['months'] : null,
        'barangay'     => $child['barangay_name'] ?? null,
    ],
    'measurement' => null,
];

if ($measurement !== null) {
    $response['measurement'] = [
        'date'               => $measurement['measurement_date'],
        'height_cm'          => $measurement['height_cm'],
        'weight_kg'          => $measurement['weight_kg'],
        'waz'                => $measurement['waz'] !== null ? (float)$measurement['waz'] : null,
        'haz'                => $measurement['haz'] !== null ? (float)$measurement['haz'] : null,
        'whz'                => $measurement['whz'] !== null ? (float)$measurement['whz'] : null,
        'nutritional_status' => $measurement['nutritional_status'],
        'wfa_status'         => $measurement['wfa_status'],
        'hfa_status'         => $measurement['hfa_status'],
        'wfh_status'         => $measurement['wfh_status'],
        'is_flagged'         => !empty($measurement['is_flagged']),
        'flag_reason'        => $measurement['flag_reason'] ?? null,
    ];
}


api_success($response);

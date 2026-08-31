<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';
require_once __DIR__ . '/../../includes/who_calculator.php';

start_secure_session();
require_permission('children.create');

function admin_next_child_code_api(): string
{
    $row = admin_fetch_one('SELECT child_code FROM children ORDER BY id DESC LIMIT 1');
    $lastCode = (string)($row['child_code'] ?? 'CHD-0000');

    if (preg_match('/(\d+)$/', $lastCode, $matches) !== 1) {
        return 'CHD-0001';
    }

    return 'CHD-' . str_pad((string)(((int)$matches[1]) + 1), 4, '0', STR_PAD_LEFT);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    admin_redirect('/admin/child_form.php', ['notice' => 'Method not allowed.', 'type' => 'error']);
}

$firstName = trim((string)($_POST['first_name'] ?? ''));
$middleName = trim((string)($_POST['middle_name'] ?? ''));
$lastName = trim((string)($_POST['last_name'] ?? ''));
$birthdate = trim((string)($_POST['birthdate'] ?? ''));
$sex = trim((string)($_POST['sex'] ?? 'Male'));
$isIp = isset($_POST['is_ip']) ? 1 : 0;
$hasDisability = isset($_POST['has_disability']) ? 1 : 0;
$parentId = (int)($_POST['parent_id'] ?? 0);
$localAreaId = (int)($_POST['local_area_id'] ?? 0);

if (
    !admin_is_valid_name_part($firstName, true)
    || !admin_is_valid_name_part($middleName, false)
    || !admin_is_valid_name_part($lastName, true)
    || $birthdate === ''
    || $parentId <= 0
) {
    admin_redirect('/admin/child_form.php', ['notice' => 'First name, last name, birthdate, and parent are required.', 'type' => 'error']);
}

$parent = admin_fetch_one('SELECT id, barangay_id FROM parents WHERE id = ? LIMIT 1', 'i', [$parentId]);

if (!$parent) {
    admin_redirect('/admin/child_form.php', ['notice' => 'Selected parent/guardian could not be found.', 'type' => 'error']);
}

$barangayId = !empty($parent['barangay_id']) ? (int)$parent['barangay_id'] : null;

if ($barangayId === null) {
    admin_redirect('/admin/child_form.php', ['notice' => 'The selected parent/guardian does not have a Barangay assigned.', 'type' => 'error']);
}

$validatedLocalAreaId = null;
if ($localAreaId > 0) {
    $localArea = admin_fetch_one(
        'SELECT id FROM local_areas WHERE id = ? AND barangay_id = ? AND is_active = 1 LIMIT 1',
        'ii',
        [$localAreaId, $barangayId]
    );
    if ($localArea) {
        $validatedLocalAreaId = (int)$localArea['id'];
    }
}

// Day-based age check -- 5 years is roughly 1825 days, which is the
// upper bound of the eOPT Plus scope.
$registrationAgeDays = doh_age_in_days($birthdate);

if ($registrationAgeDays === null || $registrationAgeDays > 1825) {
    admin_redirect('/admin/child_form.php', ['notice' => 'Birthdate must be valid and the child must be 5 years (~1825 days) old or younger.', 'type' => 'error']);
}

if (!in_array($sex, ['Male', 'Female'], true)) {
    $sex = 'Male';
}

$childCode = admin_next_child_code_api();

$ok = admin_execute(
    'INSERT INTO children (child_code, first_name, middle_name, last_name, birthdate, sex, barangay_id, local_area_id, is_ip, has_disability, parent_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    'ssssssssiii',
    [$childCode, $firstName, $middleName, $lastName, $birthdate, $sex, $barangayId, $validatedLocalAreaId ?? 0, $isIp, $hasDisability, $parentId]
);

if ($ok) {
    $actor = current_user();
    log_action($actor['id'] ?? null, 'CREATE_CHILD', 'info', 'Created child ' . $childCode);
}

admin_redirect('/admin/children.php', $ok ? ['notice' => 'Child added successfully.'] : ['notice' => 'Child could not be added.', 'type' => 'error']);

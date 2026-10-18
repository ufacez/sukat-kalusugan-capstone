<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';
require_once __DIR__ . '/../../includes/who_calculator.php';

start_secure_session();
require_permission('children.update');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    admin_redirect('/admin/children.php', ['notice' => 'Method not allowed.', 'type' => 'error']);
}

$id = (int)($_POST['id'] ?? 0);
$firstName = trim((string)($_POST['first_name'] ?? ''));
$middleName = trim((string)($_POST['middle_name'] ?? ''));
$lastName = trim((string)($_POST['last_name'] ?? ''));
$birthdate = trim((string)($_POST['birthdate'] ?? ''));
$sex = trim((string)($_POST['sex'] ?? 'Male'));
$isIp = isset($_POST['is_ip']) ? 1 : 0;
$hasDisability = isset($_POST['has_disability']) ? 1 : 0;
$parentId = (int)($_POST['parent_id'] ?? 0);
$localAreaId = (int)($_POST['local_area_id'] ?? 0);

// Every failure flashes the submitted values (via admin_flash_form_state)
// and bounces to the standalone edit page, mirroring users_update.php, so
// the form is never wiped and the offending field is highlighted.
$errorBackUrl = '/admin/child_form.php?id=' . $id;

if ($id <= 0) {
    admin_redirect('/admin/children.php', ['notice' => 'Invalid child id.', 'type' => 'error']);
}

if (
    !admin_is_valid_name_part($firstName, true)
    || !admin_is_valid_name_part($middleName, false)
    || !admin_is_valid_name_part($lastName, true)
) {
    admin_flash_form_state($_POST, 'first_name');
    admin_redirect($errorBackUrl, ['notice' => 'Enter a valid first name and surname (letters only). Middle name is optional.', 'type' => 'error']);
}

if ($birthdate === '') {
    admin_flash_form_state($_POST, 'birthdate');
    admin_redirect($errorBackUrl, ['notice' => 'Birthdate is required.', 'type' => 'error']);
}

if ($parentId <= 0) {
    admin_flash_form_state($_POST, 'parent_id');
    admin_redirect($errorBackUrl, ['notice' => 'Select the parent/guardian for this child.', 'type' => 'error']);
}

$parent = admin_fetch_one('SELECT id, barangay_id FROM parents WHERE id = ? LIMIT 1', 'i', [$parentId]);

if (!$parent) {
    admin_flash_form_state($_POST, 'parent_id');
    admin_redirect($errorBackUrl, ['notice' => 'Selected parent/guardian could not be found.', 'type' => 'error']);
}

$barangayId = !empty($parent['barangay_id']) ? (int)$parent['barangay_id'] : null;

if ($barangayId === null) {
    admin_flash_form_state($_POST, 'parent_id');
    admin_redirect($errorBackUrl, ['notice' => 'The selected parent/guardian does not have a Barangay assigned.', 'type' => 'error']);
}

$currentChild = admin_fetch_one('SELECT id, child_code, parent_id, local_area_id FROM children WHERE id = ? LIMIT 1', 'i', [$id]);
if (!$currentChild) {
    admin_redirect('/admin/children.php', ['notice' => 'Invalid child id.', 'type' => 'error']);
}

$validatedLocalAreaId = null;
if ($localAreaId > 0) {
    $localArea = admin_fetch_one(
        'SELECT id FROM local_areas WHERE id = ? AND barangay_id = ? AND is_active = 1 LIMIT 1',
        'ii',
        [$localAreaId, $barangayId]
    );
    $keepsExistingInactiveArea = $localAreaId === (int)($currentChild['local_area_id'] ?? 0)
        && $parentId === (int)($currentChild['parent_id'] ?? 0);

    if ($localArea) {
        $validatedLocalAreaId = (int)$localArea['id'];
    } elseif (!$keepsExistingInactiveArea) {
        admin_flash_form_state($_POST, 'local_area_id');
        admin_redirect($errorBackUrl, ['notice' => 'Selected Local Area is inactive or does not belong to the selected Barangay.', 'type' => 'error']);
    }
}

$registrationAgeDays = doh_age_in_days($birthdate);

if ($registrationAgeDays === null || $registrationAgeDays > 1825) {
    admin_flash_form_state($_POST, 'birthdate');
    admin_redirect($errorBackUrl, ['notice' => 'Birthdate must be valid and the child must be 5 years (~1825 days) old or younger.', 'type' => 'error']);
}

if (!in_array($sex, ['Male', 'Female'], true)) {
    $sex = 'Male';
}

$childCode = (string)($currentChild['child_code'] ?? '');

$ok = admin_execute(
    'UPDATE children SET first_name = ?, middle_name = ?, last_name = ?, birthdate = ?, sex = ?, barangay_id = ?, local_area_id = ?, is_ip = ?, has_disability = ?, parent_id = ? WHERE id = ?',
    'ssssssiiiii',
    [$firstName, $middleName, $lastName, $birthdate, $sex, $barangayId, $validatedLocalAreaId ?? null, $isIp, $hasDisability, $parentId, $id]
);

if ($ok) {
    $actor = current_user();
    log_action($actor['id'] ?? null, 'UPDATE_CHILD', 'info', 'Updated child ' . ($childCode !== '' ? $childCode : '#' . $id) . ' (' . $id . ')');
}

admin_clear_form_state();
admin_redirect('/admin/children.php', $ok
    ? ['notice' => 'Child updated successfully.', 'type' => 'success']
    : ['notice' => 'Child could not be updated. Please try again.', 'type' => 'error']);

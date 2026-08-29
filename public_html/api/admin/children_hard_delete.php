<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_permission('children.delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    admin_redirect('/admin/children_archived.php', ['notice' => 'Method not allowed.', 'type' => 'error']);
}

$id = (int)($_POST['id'] ?? 0);
$confirm = trim((string)($_POST['confirm_delete'] ?? ''));

if ($id <= 0) {
    admin_redirect('/admin/children_archived.php', ['notice' => 'Invalid child id.', 'type' => 'error']);
}

if ($confirm !== 'DELETE') {
    admin_redirect('/admin/children_archived.php?id=' . $id, ['notice' => 'Type DELETE to confirm permanent deletion.', 'type' => 'error']);
}

$target = admin_fetch_one('SELECT id, child_code, first_name FROM children WHERE id = ? LIMIT 1', 'i', [$id]);

if ($target === null) {
    admin_redirect('/admin/children_archived.php', ['notice' => 'Child not found.', 'type' => 'error']);
}

// Check for linked measurements
$linkedMeasurements = admin_fetch_one('SELECT COUNT(*) AS cnt FROM measurements WHERE child_id = ?', 'i', [$id]);
if ((int)($linkedMeasurements['cnt'] ?? 0) > 0) {
    admin_redirect('/admin/children_archived.php', ['notice' => 'Cannot permanently delete — ' . $linkedMeasurements['cnt'] . ' measurement record(s) are linked. Remove measurements first.', 'type' => 'error']);
}

// Check for linked appointments
$linkedAppts = admin_fetch_one('SELECT COUNT(*) AS cnt FROM appointments WHERE child_id = ?', 'i', [$id]);
if ((int)($linkedAppts['cnt'] ?? 0) > 0) {
    admin_redirect('/admin/children_archived.php', ['notice' => 'Cannot permanently delete — ' . $linkedAppts['cnt'] . ' appointment(s) are still linked.', 'type' => 'error']);
}

$ok = admin_execute('DELETE FROM children WHERE id = ?', 'i', [$id]);

if ($ok) {
    $actor = current_user();
    log_action($actor['id'] ?? null, 'DELETE_CHILD', 'danger', 'Permanently deleted child ' . $target['child_code'] . ' (' . $id . ')');
}

admin_redirect('/admin/children_archived.php', ['notice' => $ok ? 'Child permanently deleted.' : 'Child could not be deleted.', 'type' => $ok ? 'success' : 'error']);

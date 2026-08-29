<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_permission('parents.delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    admin_redirect('/admin/parents_archived.php', ['notice' => 'Method not allowed.', 'type' => 'error']);
}

$id = (int)($_POST['id'] ?? 0);
$confirm = trim((string)($_POST['confirm_delete'] ?? ''));

if ($id <= 0) {
    admin_redirect('/admin/parents_archived.php', ['notice' => 'Invalid parent id.', 'type' => 'error']);
}

if ($confirm !== 'DELETE') {
    admin_redirect('/admin/parents_archived.php?id=' . $id, ['notice' => 'Type DELETE to confirm permanent deletion.', 'type' => 'error']);
}

$target = admin_fetch_one('SELECT id, name, email FROM parents WHERE id = ? LIMIT 1', 'i', [$id]);

if ($target === null) {
    admin_redirect('/admin/parents_archived.php', ['notice' => 'Parent not found.', 'type' => 'error']);
}

// Check for linked children
$linkedChildren = admin_fetch_one('SELECT COUNT(*) AS cnt FROM children WHERE parent_id = ?', 'i', [$id]);
if ((int)($linkedChildren['cnt'] ?? 0) > 0) {
    admin_redirect('/admin/parents_archived.php', ['notice' => 'Cannot permanently delete — ' . $linkedChildren['cnt'] . ' child record(s) are still linked. Reassign or remove those children first.', 'type' => 'error']);
}

// Check for linked appointments
$linkedAppts = admin_fetch_one('SELECT COUNT(*) AS cnt FROM appointments WHERE parent_id = ?', 'i', [$id]);
if ((int)($linkedAppts['cnt'] ?? 0) > 0) {
    admin_redirect('/admin/parents_archived.php', ['notice' => 'Cannot permanently delete — ' . $linkedAppts['cnt'] . ' appointment(s) are still linked.', 'type' => 'error']);
}

$ok = admin_execute('DELETE FROM parents WHERE id = ?', 'i', [$id]);

if ($ok) {
    $actor = current_user();
    log_action($actor['id'] ?? null, 'DELETE_PARENT', 'danger', 'Permanently deleted parent ' . $target['email'] . ' (' . $id . ')');
}

admin_redirect('/admin/parents_archived.php', ['notice' => $ok ? 'Parent permanently deleted.' : 'Parent could not be deleted.', 'type' => $ok ? 'success' : 'error']);

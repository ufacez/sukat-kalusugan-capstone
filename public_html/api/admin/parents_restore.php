<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_permission('parents.delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    admin_redirect('/admin/parents_archived.php', ['notice' => 'Method not allowed.', 'type' => 'error']);
}

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    admin_redirect('/admin/parents_archived.php', ['notice' => 'Invalid parent id.', 'type' => 'error']);
}

$target = admin_fetch_one('SELECT id, name, email, status FROM parents WHERE id = ? LIMIT 1', 'i', [$id]);

if ($target === null) {
    admin_redirect('/admin/parents_archived.php', ['notice' => 'Parent not found.', 'type' => 'error']);
}

if ($target['status'] !== 'inactive') {
    admin_redirect('/admin/parents_archived.php', ['notice' => 'Parent is not archived.', 'type' => 'error']);
}

$ok = admin_execute('UPDATE parents SET status = ? WHERE id = ?', 'si', ['active', $id]);

if ($ok) {
    $actor = current_user();
    log_action($actor['id'] ?? null, 'UPDATE_PARENT', 'info', 'Restored parent ' . $target['email'] . ' (' . $id . ')');
}

admin_redirect('/admin/parents_archived.php', ['notice' => $ok ? 'Parent restored successfully.' : 'Parent could not be restored.', 'type' => $ok ? 'success' : 'error']);

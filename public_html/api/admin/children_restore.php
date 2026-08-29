<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_permission('children.delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    admin_redirect('/admin/children_archived.php', ['notice' => 'Method not allowed.', 'type' => 'error']);
}

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    admin_redirect('/admin/children_archived.php', ['notice' => 'Invalid child id.', 'type' => 'error']);
}

$target = admin_fetch_one('SELECT id, child_code, first_name, status FROM children WHERE id = ? LIMIT 1', 'i', [$id]);

if ($target === null) {
    admin_redirect('/admin/children_archived.php', ['notice' => 'Child not found.', 'type' => 'error']);
}

if ($target['status'] !== 'inactive') {
    admin_redirect('/admin/children_archived.php', ['notice' => 'Child is not archived.', 'type' => 'error']);
}

$ok = admin_execute('UPDATE children SET status = ? WHERE id = ?', 'si', ['active', $id]);

if ($ok) {
    $actor = current_user();
    log_action($actor['id'] ?? null, 'UPDATE_CHILD', 'info', 'Restored child ' . $target['child_code'] . ' (' . $id . ')');
}

admin_redirect('/admin/children_archived.php', ['notice' => $ok ? 'Child restored successfully.' : 'Child could not be restored.', 'type' => $ok ? 'success' : 'error']);

<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_permission('parents.delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    admin_redirect('/admin/parents.php', ['notice' => 'Method not allowed.', 'type' => 'error']);
}

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    admin_redirect('/admin/parents.php', ['notice' => 'Invalid parent id.', 'type' => 'error']);
}

$target = admin_fetch_one('SELECT id, email FROM parents WHERE id = ? LIMIT 1', 'i', [$id]);

if ($target === null) {
    admin_redirect('/admin/parents.php', ['notice' => 'Parent not found.', 'type' => 'error']);
}

// children.parent_id and appointments.parent_id are RESTRICT (not CASCADE),
// so a raw DELETE would just fail with an FK constraint error. Check first
// so the admin gets a clear, actionable message instead of a DB error.
$linkedChildren = admin_scalar('SELECT COUNT(*) FROM children WHERE parent_id = ?', 'i', [$id]);

if ($linkedChildren > 0) {
    admin_redirect('/admin/parents.php', [
        'notice' => 'Cannot delete this parent — ' . $linkedChildren . ' child record(s) are still linked to it. Reassign or remove those children first.',
        'type' => 'error',
    ]);
}

if (!admin_execute('DELETE FROM parents WHERE id = ?', 'i', [$id])) {
    admin_redirect('/admin/parents.php', ['notice' => 'Parent could not be deleted.', 'type' => 'error']);
}

$actor = current_user();
log_action($actor['id'] ?? null, 'DELETE_PARENT', 'warning', 'Deleted parent account ' . $target['email'] . ' (' . $id . ')');

admin_redirect('/admin/parents.php', ['notice' => 'Parent deleted successfully.', 'type' => 'success']);

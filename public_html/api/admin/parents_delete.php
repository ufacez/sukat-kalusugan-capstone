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

$target = admin_fetch_one('SELECT id, name, email, status FROM parents WHERE id = ? LIMIT 1', 'i', [$id]);

if ($target === null) {
    admin_redirect('/admin/parents.php', ['notice' => 'Parent not found.', 'type' => 'error']);
}

if ($target['status'] !== 'active') {
    admin_redirect('/admin/parents.php', ['notice' => 'Parent is already archived.', 'type' => 'error']);
}

$ok = admin_execute('UPDATE parents SET status = ? WHERE id = ?', 'si', ['inactive', $id]);

if ($ok) {
    $actor = current_user();
    log_action($actor['id'] ?? null, 'UPDATE_PARENT', 'warning', 'Archived parent ' . $target['email'] . ' (' . $id . ')');
}

admin_redirect('/admin/parents.php', ['notice' => $ok ? 'Parent archived successfully.' : 'Parent could not be archived.', 'type' => $ok ? 'success' : 'error']);

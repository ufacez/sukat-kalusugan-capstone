<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_permission('children.delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    admin_redirect('/admin/children.php', ['notice' => 'Method not allowed.', 'type' => 'error']);
}

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    admin_redirect('/admin/children.php', ['notice' => 'Invalid child id.', 'type' => 'error']);
}

$target = admin_fetch_one('SELECT id, child_code, first_name, status FROM children WHERE id = ? LIMIT 1', 'i', [$id]);

if ($target === null) {
    admin_redirect('/admin/children.php', ['notice' => 'Child not found.', 'type' => 'error']);
}

if ($target['status'] !== 'active') {
    admin_redirect('/admin/children.php', ['notice' => 'Child is already archived.', 'type' => 'error']);
}

$ok = admin_execute('UPDATE children SET status = ? WHERE id = ?', 'si', ['inactive', $id]);

if ($ok) {
    $actor = current_user();
    log_action($actor['id'] ?? null, 'UPDATE_CHILD', 'warning', 'Archived child ' . $target['child_code'] . ' (' . $id . ')');
}

admin_redirect('/admin/children.php', ['notice' => $ok ? 'Child archived successfully.' : 'Child could not be archived.', 'type' => $ok ? 'success' : 'error']);

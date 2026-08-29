<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_permission('users.delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    admin_redirect('/admin/users_archived.php', ['notice' => 'Method not allowed.', 'type' => 'error']);
}

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    admin_redirect('/admin/users_archived.php', ['notice' => 'Invalid user id.', 'type' => 'error']);
}

$target = admin_fetch_one('SELECT id, email, status FROM users WHERE id = ? LIMIT 1', 'i', [$id]);

if ($target === null) {
    admin_redirect('/admin/users_archived.php', ['notice' => 'User not found.', 'type' => 'error']);
}

if ($target['status'] !== 'inactive') {
    admin_redirect('/admin/users_archived.php', ['notice' => 'User is not archived.', 'type' => 'error']);
}

$ok = admin_execute('UPDATE users SET status = ? WHERE id = ?', 'si', ['active', $id]);

if ($ok) {
    $actor = current_user();
    log_action($actor['id'] ?? null, 'UPDATE_USER', 'info', 'Restored user ' . $target['email'] . ' (' . $id . ')');
}

admin_redirect('/admin/users_archived.php', ['notice' => $ok ? 'User restored successfully.' : 'User could not be restored.', 'type' => $ok ? 'success' : 'error']);

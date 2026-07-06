<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_permission('users.delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    admin_redirect('/admin/users.php', ['notice' => 'Method not allowed.', 'type' => 'error']);
}

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    admin_redirect('/admin/users.php', ['notice' => 'Invalid user id.', 'type' => 'error']);
}

$target = admin_fetch_one('SELECT id, email FROM users WHERE id = ? LIMIT 1', 'i', [$id]);

if ($target === null) {
    admin_redirect('/admin/users.php', ['notice' => 'User not found.', 'type' => 'error']);
}

if (!admin_execute('DELETE FROM users WHERE id = ?', 'i', [$id])) {
    admin_redirect('/admin/users.php', ['notice' => 'User could not be deleted.', 'type' => 'error']);
}

$actor = current_user();
log_action($actor['id'] ?? null, 'DELETE_USER', 'warning', 'Deleted user ' . $target['email'] . ' (' . $id . ')');

admin_redirect('/admin/users.php', ['notice' => 'User deleted successfully.', 'type' => 'success']);


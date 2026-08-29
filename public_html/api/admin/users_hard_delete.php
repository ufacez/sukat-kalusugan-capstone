<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_permission('users.delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    admin_redirect('/admin/users_archived.php', ['notice' => 'Method not allowed.', 'type' => 'error']);
}

$id = (int)($_POST['id'] ?? 0);
$confirm = trim((string)($_POST['confirm_delete'] ?? ''));

if ($id <= 0) {
    admin_redirect('/admin/users_archived.php', ['notice' => 'Invalid user id.', 'type' => 'error']);
}

if ($confirm !== 'DELETE') {
    admin_redirect('/admin/users_archived.php?id=' . $id, ['notice' => 'Type DELETE to confirm permanent deletion.', 'type' => 'error']);
}

$target = admin_fetch_one('SELECT id, email, name FROM users WHERE id = ? LIMIT 1', 'i', [$id]);

if ($target === null) {
    admin_redirect('/admin/users_archived.php', ['notice' => 'User not found.', 'type' => 'error']);
}

$ok = admin_execute('DELETE FROM users WHERE id = ?', 'i', [$id]);

if ($ok) {
    $actor = current_user();
    log_action($actor['id'] ?? null, 'DELETE_USER', 'danger', 'Permanently deleted user ' . $target['email'] . ' (' . $id . ')');
}

admin_redirect('/admin/users_archived.php', ['notice' => $ok ? 'User permanently deleted.' : 'User could not be deleted.', 'type' => $ok ? 'success' : 'error']);

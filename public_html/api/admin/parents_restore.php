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

// Cascade: restoring a parent restores its archived children too, so the
// whole family comes back together.
$restoredKids = 0;
if ($ok) {
    $restoredKids = admin_scalar('SELECT COUNT(*) FROM children WHERE parent_id = ? AND status = "inactive"', 'i', [$id]);
    if ($restoredKids > 0) {
        $kidsOk = admin_execute('UPDATE children SET status = "active" WHERE parent_id = ? AND status = "inactive"', 'i', [$id]);
        if (!$kidsOk) {
            error_log('[SukatKalusugan] parents_restore.php: parent ' . $id . ' restored but children cascade failed.');
        }
    }
}

if ($ok) {
    $actor = current_user();
    log_action($actor['id'] ?? null, 'UPDATE_PARENT', 'info', 'Restored parent ' . $target['email'] . ' (' . $id . ') with ' . $restoredKids . ' child(ren)');
}

admin_redirect('/admin/parents_archived.php', ['notice' => $ok ? 'Parent restored successfully' . ($restoredKids > 0 ? ' with ' . $restoredKids . ' child(ren).' : '.') : 'Parent could not be restored.', 'type' => $ok ? 'success' : 'error']);

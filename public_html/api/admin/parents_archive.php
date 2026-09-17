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

// Cascade: archiving a parent archives all of its active children too,
// so no orphaned active children linger on the kiosk or Children list.
$archivedKids = 0;
if ($ok) {
    $archivedKids = admin_scalar('SELECT COUNT(*) FROM children WHERE parent_id = ? AND status = "active"', 'i', [$id]);
    if ($archivedKids > 0) {
        $kidsOk = admin_execute('UPDATE children SET status = "inactive" WHERE parent_id = ? AND status = "active"', 'i', [$id]);
        if (!$kidsOk) {
            error_log('[SukatKalusugan] parents_archive.php: parent ' . $id . ' archived but children cascade failed.');
        }
    }
}

if ($ok) {
    $actor = current_user();
    log_action($actor['id'] ?? null, 'UPDATE_PARENT', 'warning', 'Archived parent ' . $target['email'] . ' (' . $id . ') with ' . $archivedKids . ' child(ren)');
}

admin_redirect('/admin/parents.php', ['notice' => $ok ? 'Parent archived successfully' . ($archivedKids > 0 ? ' with ' . $archivedKids . ' child(ren).' : '.') : 'Parent could not be archived.', 'type' => $ok ? 'success' : 'error']);

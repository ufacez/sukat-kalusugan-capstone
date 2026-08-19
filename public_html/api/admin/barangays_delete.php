<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_permission('barangays.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    admin_redirect('/admin/barangays.php', ['notice' => 'Method not allowed.', 'type' => 'error']);
}

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    admin_redirect('/admin/barangays.php', ['notice' => 'Invalid barangay id.', 'type' => 'error']);
}

$target = admin_fetch_one('SELECT id, name FROM barangays WHERE id = ? LIMIT 1', 'i', [$id]);

if ($target === null) {
    admin_redirect('/admin/barangays.php', ['notice' => 'Barangay not found.', 'type' => 'error']);
}

// children.barangay_id, parents.barangay_id, users.barangay_id, devices.barangay_id,
// and nutritionist_events.barangay_id all use ON DELETE SET NULL, so removing a
// barangay here unassigns it from every linked record instead of failing or
// cascading deletes.
if (!admin_execute('DELETE FROM barangays WHERE id = ?', 'i', [$id])) {
    admin_redirect('/admin/barangays.php', ['notice' => 'Barangay could not be deleted.', 'type' => 'error']);
}

$actor = current_user();
log_action($actor['id'] ?? null, 'DELETE_BARANGAY', 'warning', 'Deleted barangay ' . $target['name'] . ' (' . $id . ')');

admin_redirect('/admin/barangays.php', ['notice' => 'Barangay deleted. Linked records were unassigned, not removed.', 'type' => 'success']);

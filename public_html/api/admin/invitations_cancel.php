<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_permission('users.create');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    admin_redirect('/admin/invitations.php', ['notice' => 'Method not allowed.', 'type' => 'error']);
}

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    admin_redirect('/admin/invitations.php', ['notice' => 'Invalid invitation.', 'type' => 'error']);
}

$invitation = admin_fetch_one(
    "SELECT id, invitee_name, code, status FROM invitations WHERE id = ? LIMIT 1",
    'i',
    [$id]
);

if ($invitation === null) {
    admin_redirect('/admin/invitations.php', ['notice' => 'Invitation not found.', 'type' => 'error']);
}

if ($invitation['status'] !== 'pending') {
    admin_redirect('/admin/invitations.php', ['notice' => 'This invitation is no longer pending.', 'type' => 'error']);
}

$ok = admin_execute("UPDATE invitations SET status = 'cancelled' WHERE id = ? AND status = 'pending'", 'i', [$id]);

if ($ok) {
    $actor = current_user();
    log_action($actor['id'] ?? null, 'DELETE_INVITATION', 'info', sprintf(
        'Cancelled invitation for %s (code: %s)',
        $invitation['invitee_name'],
        $invitation['code']
    ));
}

admin_redirect('/admin/invitations.php', ['notice' => $ok ? 'Invitation cancelled.' : 'Could not cancel invitation.', 'type' => $ok ? 'success' : 'error']);

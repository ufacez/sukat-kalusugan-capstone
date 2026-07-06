<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_permission('users.view');

header('Content-Type: application/json; charset=utf-8');

$rows = admin_fetch_all(
    'SELECT u.id, u.name, u.email, u.username, u.phone, u.barangay, u.status, u.last_login, u.created_at, r.name AS role_name
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     ORDER BY u.created_at DESC, u.id DESC'
);

echo json_encode([
    'success' => true,
    'data' => $rows,
]);


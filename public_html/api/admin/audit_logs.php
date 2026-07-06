<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_permission('audit_logs.view');

header('Content-Type: application/json; charset=utf-8');

$limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));

$logs = admin_fetch_all(
    'SELECT a.id, a.action, a.level, a.description, a.ip_address, a.created_at, COALESCE(u.email, "System") AS actor
     FROM audit_logs a
     LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.created_at DESC, a.id DESC
     LIMIT ' . (int)$limit
);

echo json_encode([
    'success' => true,
    'data' => $logs,
]);


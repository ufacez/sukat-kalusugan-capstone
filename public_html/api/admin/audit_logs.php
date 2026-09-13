<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_permission('audit_logs.view');

header('Content-Type: application/json; charset=utf-8');

$limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));

$logs = admin_fetch_all(
    'SELECT a.id, a.action, a.level, a.description, a.ip_address, a.created_at, a.user_type,
            COALESCE(u.email, p.email, "System") AS actor,
            COALESCE(u.name, p.name, "System") AS actor_name
     FROM audit_logs a
     LEFT JOIN users u ON u.id = a.user_id AND (a.user_type IS NULL OR a.user_type != "parent")
     LEFT JOIN parents p ON p.id = a.user_id AND (a.user_type = "parent" OR (a.user_type IS NULL AND u.id IS NULL))
     ORDER BY a.created_at DESC, a.id DESC
     LIMIT ' . (int)$limit
);

echo json_encode([
    'success' => true,
    'data' => $logs,
]);


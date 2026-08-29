<?php

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth_middleware.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/audit_logger.php';

start_secure_session();
require_permission('roles_permissions.update');

api_require_method(['POST']);
$payload = api_payload();

$userId = (int)($payload['user_id'] ?? 0);
$accessLevel = $payload['access_level'] ?? '';

if ($userId <= 0) {
    api_error('Invalid user ID.', 422);
}

$validLevels = ['full', 'standard', 'readonly'];
if (!in_array($accessLevel, $validLevels)) {
    api_error('Invalid access level. Must be: ' . implode(', ', $validLevels), 422);
}

$conn = get_db_connection();

$stmt = mysqli_prepare($conn, 'UPDATE users SET access_level = ? WHERE id = ? LIMIT 1');
if ($stmt === false) {
    api_error('Failed to update access level.', 500);
}

mysqli_stmt_bind_param($stmt, 'si', $accessLevel, $userId);
$ok = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

if (!$ok) {
    api_error('Failed to update access level.', 500);
}

$user = current_user();
log_action(
    $user['id'] ?? null,
    'UPDATE_ROLE_PERMISSIONS',
    'info',
    'Updated user #' . $userId . ' access level to ' . $accessLevel
);

api_success(['user_id' => $userId, 'access_level' => $accessLevel], 'Access level updated to ' . ucfirst($accessLevel) . '.');

<?php

/**
 * api/auth/session_check.php
 * Returns the current authenticated session, if any.
 */

require_once __DIR__ . '/../../includes/auth_middleware.php';

start_secure_session();
header('Content-Type: application/json; charset=utf-8');

$user = current_user();

echo json_encode([
    'authenticated' => $user !== null,
    'user' => $user,
    'redirect_url' => $user !== null ? redirect_for_current_user($user) : app_url('/auth/login.php'),
]);

<?php

require_once __DIR__ . '/includes/auth_middleware.php';

start_secure_session();

$user = current_user();

if ($user !== null) {
    header('Location: ' . redirect_for_current_user($user));
    exit;
}

header('Location: ' . app_url('/auth/login.php'));
exit;

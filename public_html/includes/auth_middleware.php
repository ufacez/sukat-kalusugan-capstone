<?php
/**
 * auth_middleware.php
 * Session/role checking functions used at the top of every protected API/page.
 *
 * Functions to implement:
 *   start_secure_session(): void
 *   current_user(): array|null
 *   require_login(): void                 -- redirects/exits if not logged in
 *   require_permission(string $code): void -- checks role_permissions table
 *   is_parent_session(): bool             -- distinguishes staff vs parent session
 */

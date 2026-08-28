<?php

require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/audit_logger.php';

function admin_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function admin_nav_items(): array
{
    return [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => app_url('/admin/dashboard.php')],
        ['key' => 'users', 'label' => 'Users', 'href' => app_url('/admin/users.php')],
        ['key' => 'barangays', 'label' => 'Barangays', 'href' => app_url('/admin/barangays.php')],
        ['key' => 'audit_logs', 'label' => 'Audit Logs', 'href' => app_url('/admin/audit_logs.php')],
        ['key' => 'roles_permissions', 'label' => 'Roles & Permissions', 'href' => app_url('/admin/roles_permissions.php')],
        ['key' => 'sensors', 'label' => 'Sensors', 'href' => app_url('/admin/sensors.php')],
        ['key' => 'settings', 'label' => 'Settings', 'href' => app_url('/admin/settings.php')],
    ];
}

function admin_sidebar_icon(string $key): string
{
    $icons = [
        'dashboard' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955a1.126 1.126 0 0 1 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg>',
        'users' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>',
        'barangays' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>',
        'audit_logs' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z"/></svg>',
        'roles_permissions' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/></svg>',
        'sensors' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.288 15.038a5.25 5.25 0 0 1 7.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 0 1 1.06 0Z"/></svg>',
        'settings' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 0 1 1.45.12l.773.774c.39.389.44 1.002.12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.93.78l.893.15c.543.09.94.56.94 1.109v1.094c0 .55-.397 1.02-.94 1.11l-.893.149c-.425.07-.765.383-.93.78-.165.398-.143.854.107 1.204l.527.738c.32.447.269 1.06-.12 1.45l-.774.773a1.125 1.125 0 0 1-1.449.12l-.738-.527c-.35-.25-.806-.272-1.203-.107-.397.165-.71.505-.781.929l-.149.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527c-.447.32-1.06.269-1.45-.12l-.773-.774a1.125 1.125 0 0 1-.12-1.45l.527-.737c.25-.35.273-.806.108-1.204-.165-.397-.505-.71-.93-.78l-.894-.15c-.542-.09-.94-.56-.94-1.109v-1.094c0-.55.398-1.02.94-1.11l.894-.149c.424-.07.765-.383.93-.78.165-.398.143-.854-.108-1.204l-.526-.738a1.125 1.125 0 0 1 .12-1.45l.773-.773a1.125 1.125 0 0 1 1.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>',
        'children' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/></svg>',
        'clipboard' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z"/></svg>',
        'chart' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/></svg>',
        'book' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25"/></svg>',
        'map' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 2.499 4.012-3.749a1.125 1.125 0 0 1 1.538-.028l3.499 3.25a1.125 1.125 0 0 1-.05 1.664l-3.499 2a1.125 1.125 0 0 1-1.588-.5V6.75a1.125 1.125 0 0 1 .503-.999Z"/></svg>',
        'calendar' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>',
        'document' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>',
        'linechart' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/></svg>',
    ];

    return $icons[$key] ?? '';
}

function admin_action_icon(string $key): string
{
    $icons = [
        'view'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>',
        'edit'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10"/></svg>',
        'delete' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>',
        'measure' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9m6.75-5.25v4.5m0-4.5h4.5m-4.5 0L15 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15m6.75 5.25v-4.5m0 4.5h4.5m-4.5 0L15 15"/></svg>',
        'add'    => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>',
        'export' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>',
        'back'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>',
        'open'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>',
        'print'  => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z"/></svg>',
        'verify' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>',
        'sync'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>',
        'save'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>',
        'cancel' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>',
        'settings' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>',
        'logout' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"/></svg>',
        'chevron_left'  => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>',
        'chevron_right' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>',
    ];

    return $icons[$key] ?? '';
}

function admin_initials(string $name): string
{
    $parts = array_filter(explode(' ', trim($name)));
    if (count($parts) >= 2) {
        return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
    }
    return mb_strtoupper(mb_substr($name, 0, 2));
}

function admin_avatar_color(string $name): string
{
    $colors = ['#0b6e4f','#1a6b5a','#2e8b6e','#3a7d5c','#4e9a6f','#2d8f6f','#347a5c','#408c6a'];
    $hash = 0;
    for ($i = 0; $i < mb_strlen($name); $i++) {
        $hash = ($hash * 31 + mb_ord(mb_substr($name, $i, 1))) % count($colors);
    }
    return $colors[$hash];
}

function admin_grouped_nav_items(): array
{
    return [
        [
            'label' => 'Overview',
            'items' => [
                ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => app_url('/admin/dashboard.php'), 'icon' => 'dashboard'],
            ],
        ],
        [
            'label' => 'Management',
            'items' => [
                ['key' => 'users', 'label' => 'Users', 'href' => app_url('/admin/users.php'), 'icon' => 'users'],
                ['key' => 'barangays', 'label' => 'Barangays', 'href' => app_url('/admin/barangays.php'), 'icon' => 'barangays'],
            ],
        ],
        [
            'label' => 'Monitoring',
            'items' => [
                ['key' => 'audit_logs', 'label' => 'Audit Logs', 'href' => app_url('/admin/audit_logs.php'), 'icon' => 'audit_logs'],
                ['key' => 'sensors', 'label' => 'Sensors', 'href' => app_url('/admin/sensors.php'), 'icon' => 'sensors'],
            ],
        ],
        [
            'label' => 'Configuration',
            'items' => [
                ['key' => 'roles_permissions', 'label' => 'Roles & Permissions', 'href' => app_url('/admin/roles_permissions.php'), 'icon' => 'roles_permissions'],
            ],
        ],
    ];
}

function admin_bind_params(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '' || $params === []) {
        return;
    }

    $bindArgs = [$stmt, $types];

    foreach ($params as $index => &$value) {
        $bindArgs[] = &$value;
    }

    call_user_func_array('mysqli_stmt_bind_param', $bindArgs);
}

function admin_fetch_all(string $sql, string $types = '', array $params = []): array
{
    $conn = get_db_connection();
    $stmt = mysqli_prepare($conn, $sql);

    if ($stmt === false) {
        error_log('[SukatKalusugan] admin_fetch_all prepare failed: ' . mysqli_error($conn) . ' | SQL: ' . $sql);

        return [];
    }

    admin_bind_params($stmt, $types, $params);

    if (!mysqli_stmt_execute($stmt)) {
        error_log('[SukatKalusugan] admin_fetch_all execute failed: ' . mysqli_stmt_error($stmt) . ' | SQL: ' . $sql);

        mysqli_stmt_close($stmt);

        return [];
    }

    $result = mysqli_stmt_get_result($stmt);
    $rows = [];

    if ($result instanceof mysqli_result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }

    mysqli_stmt_close($stmt);

    return $rows;
}

function admin_fetch_one(string $sql, string $types = '', array $params = []): ?array
{
    $rows = admin_fetch_all($sql, $types, $params);

    return $rows[0] ?? null;
}

function admin_execute(string $sql, string $types = '', array $params = []): bool
{
    $conn = get_db_connection();
    $stmt = mysqli_prepare($conn, $sql);

    if ($stmt === false) {
        error_log('[SukatKalusugan] admin_execute prepare failed: ' . mysqli_error($conn) . ' | SQL: ' . $sql);

        return false;
    }

    admin_bind_params($stmt, $types, $params);
    $ok = mysqli_stmt_execute($stmt);

    if (!$ok) {
        error_log('[SukatKalusugan] admin_execute failed: ' . mysqli_stmt_error($stmt) . ' | SQL: ' . $sql);
    }

    mysqli_stmt_close($stmt);

    return $ok;
}

function admin_scalar(string $sql, string $types = '', array $params = [], int $default = 0): int
{
    $row = admin_fetch_one($sql, $types, $params);

    if ($row === null) {
        return $default;
    }

    $value = array_values($row)[0] ?? $default;

    return (int)$value;
}

function admin_find_role_id(string $roleName): int
{
    return admin_scalar('SELECT id FROM roles WHERE name = ? LIMIT 1', 's', [$roleName]);
}

/**
 * Best-effort split of a single "name" column into first / middle / last
 * name parts, used to pre-fill the Add/Edit forms when editing a record
 * that only ever stored one combined name string.
 */
function admin_split_full_name(?string $fullName): array
{
    $fullName = trim(preg_replace('/\s+/', ' ', (string)$fullName));

    if ($fullName === '') {
        return ['first' => '', 'middle' => '', 'last' => ''];
    }

    $parts = explode(' ', $fullName);

    if (count($parts) === 1) {
        return ['first' => $parts[0], 'middle' => '', 'last' => ''];
    }

    $first = array_shift($parts);
    $last = array_pop($parts);
    $middle = implode(' ', $parts);

    return ['first' => $first, 'middle' => $middle, 'last' => $last];
}

/**
 * Recombine first / middle / last name inputs into the single "name"
 * column used everywhere else in the app (dashboards, reports, audit
 * logs, session display, etc.), so no other file needs to change.
 */
function admin_combine_name(string $first, string $middle, string $last): string
{
    $parts = array_filter(
        [trim($first), trim($middle), trim($last)],
        static fn(string $part): bool => $part !== ''
    );

    return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));
}

/**
 * A name part (first/middle/last) may contain letters, spaces, hyphens,
 * apostrophes, and periods (e.g. "Jr.", "Sto. Niño", "D'Angelo").
 */
function admin_is_valid_name_part(string $value, bool $required = true): bool
{
    $value = trim($value);

    if ($value === '') {
        return !$required;
    }

    return (bool)preg_match("/^[A-Za-zÀ-ÖØ-öø-ÿ.'\\-\\s]{2,60}$/u", $value);
}

/**
 * Philippine mobile numbers: 11 digits, always starting with 09
 * (e.g. 0917xxxxxxx). Spaces/dashes are stripped before checking.
 */
function admin_is_valid_ph_mobile(string $phone): bool
{
    $digitsOnly = preg_replace('/[^0-9]/', '', $phone);

    return (bool)preg_match('/^09\d{9}$/', (string)$digitsOnly);
}

/**
 * Strong password: at least 8 characters with an uppercase letter,
 * a lowercase letter, a number, and a special character.
 */
function admin_is_strong_password(string $password): bool
{
    if (strlen($password) < 8) {
        return false;
    }

    return preg_match('/[a-z]/', $password) === 1
        && preg_match('/[A-Z]/', $password) === 1
        && preg_match('/[0-9]/', $password) === 1
        && preg_match('/[^A-Za-z0-9]/', $password) === 1;
}

/**
 * Shared dropdown source for every "assign a barangay" form across the
 * admin, nutritionist, and settings pages. Active barangays only, sorted
 * by name so the <select> stays predictable.
 */
function admin_barangay_options(): array
{
    return admin_fetch_all(
        "SELECT id, name, city_municipality, status
         FROM barangays
         WHERE status = 'active'
         ORDER BY name ASC"
    );
}

function admin_redirect(string $path, array $query = []): void
{
    $url = $path;

    if ($query !== []) {
        $url .= (str_contains($path, '?') ? '&' : '?') . http_build_query($query);
    }

    header('Location: ' . app_url($url), true, 303);
    exit;
}

function admin_flash_message(): ?array
{
    $message = trim((string)($_GET['notice'] ?? ''));

    if ($message === '') {
        return null;
    }

    return [
        'message' => $message,
        'type' => trim((string)($_GET['type'] ?? 'success')),
    ];
}

function admin_layout_start(string $title, string $subtitle, string $activeSection, string $actionsHtml = ''): void
{
    $currentUser = current_user();
    $userName = $currentUser['name'] ?? 'Administrator';
    $userRole = $currentUser['role'] ?? 'admin';
    $flash = admin_flash_message();
    $logoutUrl = app_url('/api/auth/logout.php');

    echo '<!doctype html>';
    echo '<html lang="en">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . admin_e($title) . ' | Sukat Kalusugan Admin</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@700;800;900&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="' . admin_e(app_url('/assets/css/app.css')) . '">';
    echo '<link rel="stylesheet" href="' . admin_e(app_url('/assets/css/admin.css')) . '">';
    echo '<link rel="icon" type="image/jpeg" href="' . admin_e(app_url('/assets/img/logo/variant01.jpg')) . '">';

    echo '<script>';
    echo '(function(){';
    echo 'var t=localStorage.getItem("theme");';
    echo 'if(t==="dark"||(!t&&window.matchMedia("(prefers-color-scheme:dark)").matches)){';
    echo 'document.documentElement.setAttribute("data-theme","dark");';
    echo '}';
    echo '})();';
    echo '</script>';
    echo '</head>';
    echo '<body class="admin-page">';
    echo '<div class="admin-shell">';
    echo '<aside class="admin-sidebar" data-admin-sidebar>';

    echo '<div class="admin-brand">';
    echo '<div class="admin-brand-mark">';
    echo '<img src="' . admin_e(app_url('/assets/img/logo/4k_light01.png')) . '" alt="Sukat Kalusugan" class="admin-brand-img logo-light">';
    echo '<img src="' . admin_e(app_url('/assets/img/logo/4k_dark01.png')) . '" alt="Sukat Kalusugan" class="admin-brand-img logo-dark">';
    echo '</div>';
    echo '<div class="admin-brand-text">';
    echo '<div class="admin-brand-name">Sukat Kalusugan</div>';
    echo '<div class="admin-brand-sub">Admin console</div>';
    echo '</div>';
    echo '</div>';
    echo '<button type="button" class="admin-sidebar-collapse" data-admin-sidebar-collapse title="Toggle sidebar">';
    echo '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>';
    echo '</button>';

    echo '<nav class="admin-nav">';
    foreach (admin_grouped_nav_items() as $groupIndex => $group) {
        echo '<div class="admin-nav-group">';
        echo '<div class="admin-nav-section"><span class="admin-nav-label">' . admin_e($group['label']) . '</span></div>';
        foreach ($group['items'] as $item) {
            $isActive = $item['key'] === $activeSection ? ' is-active' : '';
            $iconHtml = admin_sidebar_icon($item['icon']);
            echo '<a class="admin-nav-link' . $isActive . '" href="' . admin_e($item['href']) . '">';
            if ($iconHtml !== '') {
                echo $iconHtml;
            }
            echo '<span>' . admin_e($item['label']) . '</span>';
            echo '</a>';
        }
        echo '</div>';
    }
    echo '</nav>';

    echo '<div class="admin-sidebar-footer">';
    echo '<div class="admin-nav-group sidebar-theme-toggle">';
    echo '<button type="button" data-theme-toggle>';
    echo '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z"/></svg>';
    echo '<span class="theme-toggle-label">Dark Mode</span>';
    echo '</button>';
    echo '</div>';
    echo '<div class="admin-session-card">';
    echo '<div class="admin-session-role">' . admin_e(ucfirst($userRole)) . '</div>';
    echo '<div class="admin-session-name">' . admin_e($userName) . '</div>';
    echo '</div>';
    echo '<a class="admin-logout" href="' . admin_e($logoutUrl) . '">' . admin_action_icon('logout') . ' Sign out</a>';
    echo '</div>';

    echo '</aside>';
    echo '<div class="admin-sidebar-overlay" data-admin-sidebar-overlay></div>';
    echo '<div class="admin-main">';
    echo '<header class="admin-topbar">';
    echo '<div class="admin-topbar-left">';
    echo '<button class="admin-sidebar-toggle" type="button" data-admin-sidebar-toggle aria-label="Toggle navigation" aria-expanded="false">';
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>';
    echo '</button>';
    echo '<div class="admin-pagehead">';
    echo '<p class="admin-kicker">Administration</p>';
    echo '<h1>' . admin_e($title) . '</h1>';
    echo '<p>' . admin_e($subtitle) . '</p>';
    echo '</div>';
    echo '</div>';
    echo '<div class="admin-topbar-right">';
    echo '<div class="admin-topbar-actions">' . $actionsHtml . '</div>';
    echo '<a href="' . admin_e(app_url('/admin/settings.php')) . '" class="admin-topbar-settings" title="Settings">' . admin_action_icon('settings') . '</a>';
    echo '<div class="admin-topbar-profile">';
    echo '<span class="admin-avatar" style="background:' . admin_avatar_color($userName) . '">' . admin_initials($userName) . '</span>';
    echo '</div>';
    echo '</div>';
    echo '</header>';

    if ($flash !== null) {
        $flashClass = $flash['type'] === 'error' ? 'admin-flash is-error' : 'admin-flash';
        echo '<div class="' . $flashClass . '">' . admin_e($flash['message']) . '</div>';
    }

    echo '<main class="admin-content">';
}

function admin_layout_end(): void
{
    echo '</main>';
    echo '</div>';
    echo '</div>';
    echo '<script src="' . admin_e(app_url('/assets/js/admin.js')) . '"></script>';
    echo '<script src="' . admin_e(app_url('/assets/js/admin-form-validate.js')) . '"></script>';
    echo '</body>';
    echo '</html>';
}
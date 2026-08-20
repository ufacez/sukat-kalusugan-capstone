<?php

/**
 * api/chatbot/children.php
 *
 * Returns children that the currently authenticated user
 * is allowed to access.
 *
 * Parent:
 *   - Only their own children.
 *
 * Staff:
 *   - Admin / nutritionist can search all children.
 */

require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';

api_require_method(['GET']);

$user = current_user();

if ($user === null) {
    api_error('Please sign in to continue.', 401);
}

$userType = (string)($user['type'] ?? '');

if ($userType === 'parent') {

    /*
     * Parent session / authorization.
     */
    $user = api_require_parent_session();

    $parentId = (int)($user['id'] ?? 0);

    if ($parentId <= 0) {
        api_error('Invalid parent session.', 401);
    }

    $children = admin_fetch_all(
        'SELECT
            id,
            child_code,
            first_name,
            last_name
         FROM children
         WHERE parent_id = ?
         ORDER BY first_name ASC, last_name ASC',
        'i',
        [$parentId]
    );

} elseif ($userType === 'staff') {

    /*
     * Only authorized staff may access the complete child list.
     */
    $user = api_require_staff_session(['admin', 'nutritionist']);

    $query = trim((string)($_GET['q'] ?? ''));

    if ($query !== '') {

        /*
         * Search by:
         *   - first name
         *   - last name
         *   - child code
         */
        $search = '%' . $query . '%';

        $children = admin_fetch_all(
            'SELECT
                id,
                child_code,
                first_name,
                last_name
             FROM children
             WHERE first_name LIKE ?
                OR last_name LIKE ?
                OR child_code LIKE ?
             ORDER BY first_name ASC, last_name ASC
             LIMIT 50',
            'sss',
            [$search, $search, $search]
        );

    } else {

        /*
         * Don't load an unlimited number of children.
         */
        $children = admin_fetch_all(
            'SELECT
                id,
                child_code,
                first_name,
                last_name
             FROM children
             ORDER BY first_name ASC, last_name ASC
             LIMIT 50'
        );
    }

} else {

    api_error(
        'You do not have permission to access children.',
        403
    );
}


/*
|--------------------------------------------------------------------------
| Format response
|--------------------------------------------------------------------------
*/

$result = [];

foreach ($children as $child) {

    $firstName = trim(
        (string)($child['first_name'] ?? '')
    );

    $lastName = trim(
        (string)($child['last_name'] ?? '')
    );

    $name = trim(
        $firstName . ' ' . $lastName
    );

    $result[] = [
        'id' => (int)($child['id'] ?? 0),
        'child_code' => (string)($child['child_code'] ?? ''),
        'name' => $name !== '' ? $name : 'Unnamed child',
    ];
}


/*
|--------------------------------------------------------------------------
| JSON response
|--------------------------------------------------------------------------
*/

api_success(
    [
        'children' => $result,
        'count' => count($result),
    ],
    'Children loaded.'
);
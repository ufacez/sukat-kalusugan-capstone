<?php

declare(strict_types=1);

/**
 * api/nutritionist/parents_lookup.php
 *
 * Searchable, paginated parent list for the child-form parent picker modal.
 * Same barangay scoping as child_form.php: non-admin nutritionists only see
 * parents in their assigned barangay.
 *
 * GET ?q=name&page=1&page_size=5 → {parents, page, pages, total}
 * GET ?id=123                  → {parent} (edit-mode preselect)
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/nutritionist_helpers.php';

api_require_method(['GET']);

$user = api_require_staff_session(['admin', 'nutritionist']);

$singleId = api_int($_GET['id'] ?? null, 0);
$query = api_string($_GET['q'] ?? '', '');
$page = max(1, api_int($_GET['page'] ?? null, 1));
$pageSize = min(20, max(1, api_int($_GET['page_size'] ?? null, 5)));

$scopeParams = [];
$scope = nutritionist_scope_fragment($user, 'p.barangay_id', $scopeParams);
$scopeTypes = str_repeat('i', count($scopeParams));

$columns = 'p.id, p.name, p.parent_type, p.barangay_id, p.local_area_id, bg.name AS barangay';
$from = 'FROM parents p LEFT JOIN barangays bg ON bg.id = p.barangay_id WHERE ' . $scope;

if ($singleId > 0) {
    $row = admin_fetch_one(
        'SELECT ' . $columns . ' ' . $from . ' AND p.id = ? LIMIT 1',
        $scopeTypes . 'i',
        [...$scopeParams, $singleId]
    );

    if ($row === null) {
        api_error('Parent not found.', 404);
    }

    api_success(['parent' => parent_lookup_format($row)]);
}

$like = '%' . $query . '%';
$total = admin_scalar(
    'SELECT COUNT(*) ' . $from . ' AND p.name LIKE ?',
    $scopeTypes . 's',
    [...$scopeParams, $like]
);

$pages = max(1, (int)ceil($total / $pageSize));
$page = min($page, $pages);
$offset = ($page - 1) * $pageSize;

$rows = admin_fetch_all(
    'SELECT ' . $columns . ' ' . $from . ' AND p.name LIKE ? ORDER BY p.name ASC LIMIT ? OFFSET ?',
    $scopeTypes . 'sii',
    [...$scopeParams, $like, $pageSize, $offset]
);

api_success([
    'parents' => array_map('parent_lookup_format', $rows),
    'page' => $page,
    'pages' => $pages,
    'total' => (int)$total,
]);

function parent_lookup_format(array $row): array
{
    $name = trim((string)($row['name'] ?? ''));

    return [
        'id' => (int)($row['id'] ?? 0),
        'name' => $name !== '' ? $name : 'Unnamed parent',
        'parent_type' => (string)($row['parent_type'] ?? ''),
        'barangay' => (string)($row['barangay'] ?? 'Not assigned'),
        'barangay_id' => (int)($row['barangay_id'] ?? 0),
        'local_area_id' => (int)($row['local_area_id'] ?? 0),
    ];
}

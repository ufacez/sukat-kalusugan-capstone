<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_permission('barangays.view');

/**
 * Official 35 barangays of the City of San Fernando, Pampanga.
 * Keep spelling in sync with db/20260825_seed_sanfernando_barangays.sql
 * (and the sanfernando_barangays.geojson map) — these strings become
 * barangays.name rows when an admin adds a missing barangay.
 */
function csfp_official_barangays(): array
{
    return [
        'Alasas',
        'Baliti',
        'Bulaon',
        'Calulut',
        'Del Carmen',
        'Del Pilar',
        'Del Rosario',
        'Dela Paz Norte',
        'Dela Paz Sur',
        'Dolores',
        'Juliana',
        'Lara',
        'Lourdes',
        'Magliman',
        'Maimpis',
        'Malino',
        'Malpitic',
        'Pandaras',
        'Panipuan',
        'Pulung Bulu',
        'Quebiauan',
        'Saguin',
        'San Agustin',
        'San Felipe',
        'San Isidro',
        'San Jose',
        'San Juan',
        'San Nicolas',
        'San Pedro',
        'Santa Lucia',
        'Santa Teresita',
        'Santo Niño',
        'Santo Rosario (Pob.)',
        'Sindalan',
        'Telabastagan',
    ];
}

$mode = strtolower(trim((string)($_GET['mode'] ?? '')));

if ($mode === 'missing') {
    // Add Barangay form: official names NOT yet in the directory (any
    // status). The picker used to list existing active rows only, which made
    // a hard-deleted barangay (e.g. Alasas) impossible to re-add — it was
    // gone from the dropdown, so the form could never submit its name.
    $existing = admin_fetch_all('SELECT name FROM barangays');
    $taken = [];
    foreach ($existing as $row) {
        $taken[strtolower(trim((string)($row['name'] ?? '')))] = true;
    }

    $missing = array_values(array_filter(
        csfp_official_barangays(),
        static fn(string $name): bool => !isset($taken[strtolower($name)])
    ));

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'city_municipality' => 'City of San Fernando, Pampanga',
        'barangays' => $missing,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$barangays = admin_fetch_all(
    "SELECT name FROM barangays WHERE status = 'active' ORDER BY name ASC"
);

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'city_municipality' => 'City of San Fernando, Pampanga',
    'barangays' => array_map(fn($b) => $b['name'], $barangays),
], JSON_UNESCAPED_UNICODE);

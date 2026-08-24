<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_permission('barangays.view');

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'city_municipality' => 'City of San Fernando, Pampanga',
    'barangays' => [
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
    ],
], JSON_UNESCAPED_UNICODE);

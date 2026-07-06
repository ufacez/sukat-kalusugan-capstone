<?php
/**
 * test_connection.php
 * Throwaway script — confirms PHP can reach MySQL via db.php.
 * Delete this file once you've confirmed it works.
 */
require_once __DIR__ . '/public_html/includes/db.php';

$conn = get_db_connection();

$result = mysqli_query($conn, 'SELECT 1 AS ok');
$row = mysqli_fetch_assoc($result);

if ($row['ok'] == 1) {
    echo "Connection successful. DB: " . DB_NAME . "\n";
} else {
    echo "Connected, but query didn't return expected result.\n";
}

$tables_result = mysqli_query($conn, 'SHOW TABLES');
$tables = [];
while ($row = mysqli_fetch_row($tables_result)) {
    $tables[] = $row[0];
}
echo "Tables found: " . count($tables) . "\n";
echo implode(', ', $tables) . "\n";
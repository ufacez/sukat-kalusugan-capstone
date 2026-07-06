<?php
/**
 * db.php
 * Single procedural function that returns a shared mysqli connection.
 * Every API file includes this instead of opening its own connection.
 */
require_once __DIR__ . '/config.php';

/**
 * Returns a shared mysqli connection. Reuses the same connection across
 * multiple calls in the same request (static variable = simple "singleton"
 * without needing a class).
 *
 * @return mysqli
 */
function get_db_connection(): mysqli
{
    static $conn = null;

    if ($conn === null) {
        $connection = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

        if ($connection === false) {
            die('Connection failed: ' . mysqli_connect_error());
        }

        $conn = $connection;
        mysqli_set_charset($conn, 'utf8mb4');
    }

    return $conn;
}
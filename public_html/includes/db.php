<?php
/**
 * db.php
 * Single procedural function that returns a shared PDO connection.
 * Every API file includes this instead of opening its own connection.
 *
 * Functions to implement:
 *   get_db_connection(): PDO
 */
require_once __DIR__ . '/config.php';

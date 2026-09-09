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
            // Log the real reason server-side, but never echo DB host/user/error
            // details to the browser — that's the kind of thing bootstrap_errors.php
            // is trying to keep off the page in production.
            error_log('Database connection failed: ' . mysqli_connect_error());
            http_response_code(500);

            if (defined('APP_ENV') && APP_ENV === 'production') {
                die('Something went wrong. Please try again later.');
            }

            die('Connection failed: ' . mysqli_connect_error());
        }

        $conn = $connection;
        mysqli_set_charset($conn, 'utf8mb4');

        // Sync MySQL session timezone with PHP's so that NOW(),
        // DATE_ADD(), and other time functions return consistent
        // values across both layers. Without this, a timezone
        // mismatch (e.g. PHP UTC+2 vs MySQL UTC+8) corrupts
        // expires_at and other computed datetimes.
        $offset = (new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get())))->getOffset();
        $sign   = $offset >= 0 ? '+' : '-';
        $hours  = abs(intdiv($offset, 3600));
        $mins   = abs(intdiv($offset % 3600, 60));
        mysqli_query($conn, "SET time_zone = '{$sign}{$hours}:{$mins}'");
    }

    return $conn;
}
<?php
/**
 * bootstrap_errors.php
 * Central place that decides whether PHP errors are shown on-screen or
 * hidden and logged, based on APP_ENV (set in config.php).
 *
 * Included from config.php, right after APP_ENV is defined, so this runs
 * on every request before any other code executes.
 */

$appEnv = defined('APP_ENV') ? APP_ENV : 'development';

if ($appEnv === 'production') {
    // Never show raw errors/warnings/stack traces to visitors in production —
    // they can leak file paths, SQL, and other internals.
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/../../logs/php_errors.log');
} else {
    // Development: surface everything to make debugging easier.
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
}

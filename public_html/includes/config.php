<?php
/**
 * config.php
 * Central configuration constants: DB credentials, app settings.
 * NEVER commit real credentials to Git.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'sukat_kalusugan');
define('DB_USER', 'root');
define('DB_PASS', '');   // XAMPP default root password is usually empty

define('APP_ENV', 'development'); // 'development' | 'production'
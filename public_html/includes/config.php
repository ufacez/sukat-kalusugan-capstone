<?php
/**
 * config.php
 * Central configuration constants: DB credentials, Firebase config, app settings.
 * NEVER commit real credentials to Git — use environment-specific values here,
 * and add this file to .gitignore once you set up version control.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'nutrition_system');
define('DB_USER', 'root');
define('DB_PASS', '');

define('FIREBASE_DB_URL', 'https://your-project.firebaseio.com');
define('FIREBASE_API_KEY', '');

define('APP_ENV', 'development'); // 'development' | 'production'

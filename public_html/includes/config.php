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

// Firebase Realtime Database setup (leave empty until you create the project)
define('FIREBASE_DATABASE_URL', 'https://sukatkalusugan-default-rtdb.firebaseio.com/');
// Example: define('FIREBASE_DATABASE_URL', 'https://your-project-default-rtdb.firebaseio.com');

// Optional: Firebase Realtime Database auth token for server-side REST writes.
// Leave empty if you are using test-mode rules during development.
define('FIREBASE_AUTH_TOKEN', '');